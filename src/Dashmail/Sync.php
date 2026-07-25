<?php declare(strict_types=1);

namespace App\Dashmail;

use App\Database;
use App\R2Client;
use PDO;

/**
 * Ingesta de eventos de Mailgun -> Neon (dashmail_events) + publica agregados
 * por-campaña a R2 (cdn.5t4d10.com/dashmail/campaign_stats.json).
 *
 * Lo usan DOS entradas:
 *  - bin/dashmail-cron.php (cron 2x/dia en el servicio 5t4d10_DASHMAIL_CRON).
 *  - la ruta HTTP /internal/dashmail-sync (boton "Sincronizar ahora" del dashboard).
 * Idempotente (dedup por event_id + upsert del JSON), seguro correrlo cuando sea.
 */
final class Sync
{
    /** @return array{ingest:array,publish:array} */
    public static function run(?int $maxPages = null): array
    {
        $KEY = getenv('MAILGUN_API_KEY'); $DOM = getenv('MAILGUN_DOMAIN');
        if (!$KEY || !$DOM) throw new \RuntimeException('faltan MAILGUN_API_KEY / MAILGUN_DOMAIN');
        $pdo = Database::get();

        // ---- asegurar tablas (idempotente) ----
        $pdo->exec("CREATE TABLE IF NOT EXISTS dashmail_events (event_id text PRIMARY KEY, ts double precision, event text, email_type text, email_type_id text, recipient text, subject text)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_dme_typeid ON dashmail_events(email_type_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_dme_ts ON dashmail_events(ts)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dashmail_ingest_state (k text PRIMARY KEY, v text)");

        $ingest = self::ingest($pdo, $KEY, $DOM, $maxPages ?? (int)(getenv('INGEST_MAX_PAGES') ?: 80));
        $publish = self::publish($pdo);
        self::stateSet($pdo, 'sync_lock', '0'); // liberar lock del botón "Sincronizar ahora"
        return ['ingest' => $ingest, 'publish' => $publish];
    }

    private static function stateGet(PDO $p, string $k): ?string
    { $s = $p->prepare("SELECT v FROM dashmail_ingest_state WHERE k=?"); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? null : (string)$v; }

    private static function stateSet(PDO $p, string $k, string $v): void
    { $p->prepare("INSERT INTO dashmail_ingest_state (k,v) VALUES (?,?) ON CONFLICT (k) DO UPDATE SET v=EXCLUDED.v")->execute([$k, $v]); }

    private static function mgGet(string $url, string $KEY): array
    { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "api:$KEY", CURLOPT_TIMEOUT => 40]); $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$c, json_decode((string)$r, true)]; }

    private static function ingest(PDO $pdo, string $KEY, string $DOM, int $maxPages): array
    {
        $cursor = self::stateGet($pdo, 'mg_cursor');
        $begin = $cursor !== null ? (float)$cursor : (time() - 3 * 86400);
        $url = "https://api.mailgun.net/v3/$DOM/events?" . http_build_query(['begin' => $begin, 'ascending' => 'yes', 'limit' => 300]);
        $inserted = 0; $seen = 0; $maxts = $begin; $pages = 0;
        while ($url && $pages < $maxPages) {
            [$code, $j] = self::mgGet($url, $KEY);
            if ($code !== 200) { error_log("[dashmail sync] Mailgun HTTP $code"); break; }
            $items = $j['items'] ?? []; if (!$items) break;
            // Acumular la página y hacer UN solo INSERT multi-fila (evita miles de round-trips a Neon).
            $batch = []; // keyed por event_id para no duplicar dentro del mismo INSERT (rompe ON CONFLICT)
            foreach ($items as $it) {
                $seen++;
                $uv = $it['user-variables'] ?? [];
                $ts = (float)($it['timestamp'] ?? 0); if ($ts > $maxts) $maxts = $ts;
                $eid = (string)($uv['email_type_id'] ?? '');
                $etype = (string)($uv['email_type'] ?? '');
                if ($eid === '') {
                    foreach (($it['tags'] ?? []) as $tg) {
                        if (!preg_match('/^(loc_|com_|et_)/', (string)$tg)) { $eid = 'tag:' . $tg; $etype = 'direct'; break; }
                    }
                }
                if ($eid === '') continue;
                $evid = (string)($it['id'] ?? '');
                if ($evid === '') continue; // sin id no se puede deduplicar
                $batch[$evid] = [$evid, $ts, (string)($it['event'] ?? ''), $etype, $eid, (string)($it['recipient'] ?? ''), (string)($it['message']['headers']['subject'] ?? '')];
            }
            if ($batch) {
                $ph = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?)'));
                $st = $pdo->prepare("INSERT INTO dashmail_events (event_id,ts,event,email_type,email_type_id,recipient,subject) VALUES $ph ON CONFLICT (event_id) DO NOTHING");
                $flat = []; foreach ($batch as $row) foreach ($row as $v) $flat[] = $v;
                $st->execute($flat);
                $inserted += $st->rowCount();
            }
            $pages++;
            $next = $j['paging']['next'] ?? null; if (!$next || $next === $url) break; $url = $next;
        }
        if ($maxts > $begin) self::stateSet($pdo, 'mg_cursor', (string)$maxts);
        return ['pages' => $pages, 'seen' => $seen, 'inserted' => $inserted, 'cursor' => gmdate('Y-m-d H:i', (int)$maxts)];
    }

    private static function publish(PDO $pdo): array
    {
        $camps = [];
        foreach ($pdo->query("SELECT email_type_id eid, event, COUNT(*) total, COUNT(DISTINCT recipient) uniq, MIN(ts) mn, MAX(ts) mx, MAX(subject) subj, MAX(email_type) et FROM dashmail_events GROUP BY 1,2") as $r) {
            $eid = $r['eid'];
            if (!isset($camps[$eid])) $camps[$eid] = ['engagement' => [], 'events' => 0, 'first_ts' => null, 'last_ts' => null, 'subject' => '', 'kind' => 'ghl'];
            $camps[$eid]['engagement'][$r['event']] = ['total' => (int)$r['total'], 'uniq' => (int)$r['uniq']];
            $camps[$eid]['events'] += (int)$r['total'];
            $camps[$eid]['first_ts'] = $camps[$eid]['first_ts'] === null ? $r['mn'] : min($camps[$eid]['first_ts'], $r['mn']);
            $camps[$eid]['last_ts']  = $camps[$eid]['last_ts']  === null ? $r['mx'] : max($camps[$eid]['last_ts'], $r['mx']);
            if (($r['subj'] ?? '') !== '') $camps[$eid]['subject'] = $r['subj'];
            if (($r['et'] ?? '') === 'direct' || strpos($eid, 'tag:') === 0) $camps[$eid]['kind'] = 'direct';
        }
        $generated = time();
        $json = json_encode(['generated' => $generated, 'campaigns' => $camps], JSON_UNESCAPED_SLASHES);
        $r2 = new R2Client();
        $r2->put('dashmail/campaign_stats.json', $json, 'application/json');
        return ['campaigns' => count($camps), 'generated' => $generated, 'url' => $r2->publicUrl('dashmail/campaign_stats.json')];
    }
}
