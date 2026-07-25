<?php declare(strict_types=1);
/**
 * Cron del Dashboard de Correos (dashmail.5t4d10.com).
 * 1) Ingesta eventos de Mailgun -> Neon (dashmail_events), dedup por event_id.
 * 2) Publica agregados por campaña a R2 (cdn.5t4d10.com/dashmail/campaign_stats.json),
 *    que es lo que lee el web app (que no tiene pgsql).
 * Corre 2x/dia en el proyecto dashboard-allaccess (Nixpacks con pgsql funcionando).
 * Env extra que necesita: MAILGUN_API_KEY, MAILGUN_DOMAIN, R2_* (DB_* ya estan).
 */

function load_env(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k); $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}
load_env(__DIR__ . '/../.env');
require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\R2Client;

$KEY = getenv('MAILGUN_API_KEY'); $DOM = getenv('MAILGUN_DOMAIN');
if (!$KEY || !$DOM) { fwrite(STDERR, "faltan MAILGUN_*\n"); exit(1); }
$pdo = Database::get();

// ---- asegurar tablas (idempotente) ----
$pdo->exec("CREATE TABLE IF NOT EXISTS dashmail_events (event_id text PRIMARY KEY, ts double precision, event text, email_type text, email_type_id text, recipient text, subject text)");
$pdo->exec("CREATE INDEX IF NOT EXISTS ix_dme_typeid ON dashmail_events(email_type_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS ix_dme_ts ON dashmail_events(ts)");
$pdo->exec("CREATE TABLE IF NOT EXISTS dashmail_ingest_state (k text PRIMARY KEY, v text)");

function state_get(PDO $p, string $k): ?string { $s=$p->prepare("SELECT v FROM dashmail_ingest_state WHERE k=?"); $s->execute([$k]); $v=$s->fetchColumn(); return $v===false?null:(string)$v; }
function state_set(PDO $p, string $k, string $v): void { $p->prepare("INSERT INTO dashmail_ingest_state (k,v) VALUES (?,?) ON CONFLICT (k) DO UPDATE SET v=EXCLUDED.v")->execute([$k,$v]); }
function mg_get(string $url, string $KEY): array { $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>"api:$KEY",CURLOPT_TIMEOUT=>40]); $r=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$c, json_decode((string)$r,true)]; }

// ---- 1) INGESTA ----
$cursor = state_get($pdo,'mg_cursor');
$begin = $cursor !== null ? (float)$cursor : (time() - 3*86400);
$maxPages = (int)(getenv('INGEST_MAX_PAGES') ?: 80);
$url = "https://api.mailgun.net/v3/$DOM/events?".http_build_query(['begin'=>$begin,'ascending'=>'yes','limit'=>300]);
$ins = $pdo->prepare("INSERT INTO dashmail_events (event_id,ts,event,email_type,email_type_id,recipient,subject) VALUES (:id,:ts,:ev,:et,:eid,:rc,:su) ON CONFLICT (event_id) DO NOTHING");
$inserted=0; $seen=0; $maxts=$begin; $pages=0;
while ($url && $pages < $maxPages) {
    [$code,$j] = mg_get($url,$KEY);
    if ($code !== 200) { fwrite(STDERR,"Mailgun HTTP $code\n"); break; }
    $items = $j['items'] ?? []; if (!$items) break;
    foreach ($items as $it) {
        $seen++;
        $uv = $it['user-variables'] ?? [];
        $ts = (float)($it['timestamp'] ?? 0); if ($ts > $maxts) $maxts = $ts;
        $eid = (string)($uv['email_type_id'] ?? ''); if ($eid === '') continue;
        $ins->execute([':id'=>(string)($it['id'] ?? ''), ':ts'=>$ts, ':ev'=>(string)($it['event'] ?? ''), ':et'=>(string)($uv['email_type'] ?? ''), ':eid'=>$eid, ':rc'=>(string)($it['recipient'] ?? ''), ':su'=>(string)($it['message']['headers']['subject'] ?? '')]);
        $inserted += $ins->rowCount();
    }
    $pages++;
    $next = $j['paging']['next'] ?? null; if (!$next || $next === $url) break; $url = $next;
}
if ($maxts > $begin) state_set($pdo,'mg_cursor',(string)$maxts);
echo "ingest: paginas=$pages vistos=$seen nuevos=$inserted cursor=".gmdate('Y-m-d H:i',(int)$maxts)."\n";

// ---- 2) PUBLISH ----
$camps = [];
foreach ($pdo->query("SELECT email_type_id eid, event, COUNT(*) total, COUNT(DISTINCT recipient) uniq, MIN(ts) mn, MAX(ts) mx FROM dashmail_events GROUP BY 1,2") as $r) {
    $eid = $r['eid'];
    if (!isset($camps[$eid])) $camps[$eid] = ['engagement'=>[], 'events'=>0, 'first_ts'=>null, 'last_ts'=>null];
    $camps[$eid]['engagement'][$r['event']] = ['total'=>(int)$r['total'], 'uniq'=>(int)$r['uniq']];
    $camps[$eid]['events'] += (int)$r['total'];
    $camps[$eid]['first_ts'] = $camps[$eid]['first_ts'] === null ? $r['mn'] : min($camps[$eid]['first_ts'], $r['mn']);
    $camps[$eid]['last_ts']  = $camps[$eid]['last_ts']  === null ? $r['mx'] : max($camps[$eid]['last_ts'], $r['mx']);
}
$json = json_encode(['generated'=>time(), 'campaigns'=>$camps], JSON_UNESCAPED_SLASHES);
$r2 = new R2Client();
$r2->put('dashmail/campaign_stats.json', $json, 'application/json');
echo "publish: ".count($camps)." campañas -> ".$r2->publicUrl('dashmail/campaign_stats.json')."\n";
