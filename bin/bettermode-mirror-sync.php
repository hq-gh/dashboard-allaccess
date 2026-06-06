<?php declare(strict_types=1);

/**
 * Sincroniza el espejo de Bettermode (diez.5t4d10.com) a Neon:
 *   - bettermode_members        : perfil de cada miembro de la red (upsert).
 *   - bettermode_member_spaces  : 1 fila por (miembro x espacio) (truncate + reload).
 *
 * La API de Bettermode tira 500 intermitentes (code 10): cada página se reintenta.
 * Uso:  php bin/bettermode-mirror-sync.php [--dry-run]
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
$root = dirname(__DIR__);
load_env($root . '/.env');
require $root . '/vendor/autoload.php';

use App\Database;
use App\Bettermode\BettermodeClient;

const PAGE = 100;
const MAX_RETRY = 5;
$dryRun = in_array('--dry-run', $argv, true);
$pdo = Database::get();
$bm  = new BettermodeClient(fn($l,$e,$c)=>null);

/** Llama a la API reintentando ante 500 intermitentes. */
function gqlRetry(BettermodeClient $bm, string $q): array {
    $last = null;
    for ($i = 1; $i <= MAX_RETRY; $i++) {
        try { return $bm->query($q); }
        catch (\Throwable $e) { $last = $e; usleep(600000 * $i); }
    }
    throw $last;
}
function ts(?string $s): ?string { return ($s && trim($s) !== '') ? $s : null; }

echo "Sync espejo Bettermode  | modo=" . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo "============================================\n";

// -------------------------------------------------------------------
// 1) MIEMBROS (paginado por cursor) -> upsert bettermode_members
// -------------------------------------------------------------------
$FIELDS = 'id email name username createdAt updatedAt status emailStatus verifiedAt roleId locale timeZone lastSeenAt externalId relativeUrl';
$total = (int) (gqlRetry($bm, 'query { members(limit:1){ totalCount } }')['members']['totalCount'] ?? 0);
echo "Miembros en la red: $total\n";

$ins = $pdo->prepare(
    "INSERT INTO bettermode_members
       (member_id,email,name,username,status,email_status,role_id,locale,time_zone,external_id,relative_url,bm_created_at,bm_updated_at,last_seen_at,verified_at,raw,synced_at)
     VALUES (:id,:email,:name,:username,:status,:estatus,:role,:locale,:tz,:ext,:rel,:cre,:upd,:seen,:ver,:raw,NOW())
     ON CONFLICT (member_id) DO UPDATE SET
       email=EXCLUDED.email,name=EXCLUDED.name,username=EXCLUDED.username,status=EXCLUDED.status,
       email_status=EXCLUDED.email_status,role_id=EXCLUDED.role_id,locale=EXCLUDED.locale,time_zone=EXCLUDED.time_zone,
       external_id=EXCLUDED.external_id,relative_url=EXCLUDED.relative_url,bm_created_at=EXCLUDED.bm_created_at,
       bm_updated_at=EXCLUDED.bm_updated_at,last_seen_at=EXCLUDED.last_seen_at,verified_at=EXCLUDED.verified_at,
       raw=EXCLUDED.raw,synced_at=NOW()");

$cursor = null; $seen = 0; $page = 0;
do {
    $after = $cursor ? ', after: ' . json_encode($cursor) : '';
    $q = 'query { members(limit:' . PAGE . $after . '){ pageInfo{ endCursor hasNextPage } nodes{ ' . $FIELDS . ' } } }';
    $d = gqlRetry($bm, $q)['members'] ?? [];
    $nodes = $d['nodes'] ?? [];
    if (!$dryRun) {
        $pdo->beginTransaction();
        foreach ($nodes as $n) {
            $ins->execute([
                ':id'=>$n['id'], ':email'=>$n['email']??null, ':name'=>$n['name']??null, ':username'=>$n['username']??null,
                ':status'=>$n['status']??null, ':estatus'=>$n['emailStatus']??null, ':role'=>$n['roleId']??null,
                ':locale'=>$n['locale']??null, ':tz'=>$n['timeZone']??null, ':ext'=>$n['externalId']??null,
                ':rel'=>$n['relativeUrl']??null, ':cre'=>ts($n['createdAt']??null), ':upd'=>ts($n['updatedAt']??null),
                ':seen'=>ts($n['lastSeenAt']??null), ':ver'=>ts($n['verifiedAt']??null),
                ':raw'=>json_encode($n, JSON_UNESCAPED_UNICODE),
            ]);
        }
        $pdo->commit();
    }
    $seen += count($nodes); $page++;
    $cursor = ($d['pageInfo']['hasNextPage'] ?? false) ? ($d['pageInfo']['endCursor'] ?? null) : null;
    if ($page % 10 === 0) echo "  miembros: $seen/$total\n";
} while ($cursor !== null && count($nodes) > 0);
echo "Miembros sincronizados: $seen\n\n";

// -------------------------------------------------------------------
// 2) MEMBRESÍA por espacio -> truncate + reload bettermode_member_spaces
// -------------------------------------------------------------------
$spaces = gqlRetry($bm, 'query { spaces(limit:100){ nodes{ id name membersCount } } }')['spaces']['nodes'] ?? [];
echo "Espacios: " . count($spaces) . "\n";
if (!$dryRun) $pdo->exec("TRUNCATE bettermode_member_spaces");

$totalMs = 0;
foreach ($spaces as $sp) {
    $sid = $sp['id']; $sname = $sp['name'] ?? ''; $cnt = 0; $cursor = null;
    do {
        $after = $cursor ? ', after: ' . json_encode($cursor) : '';
        $q = 'query { spaceMembers(spaceId: ' . json_encode($sid) . ', limit:' . PAGE . $after . '){ pageInfo{ endCursor hasNextPage } nodes{ member{ id email } } } }';
        $d = gqlRetry($bm, $q)['spaceMembers'] ?? [];
        $nodes = $d['nodes'] ?? [];
        if (!$dryRun && $nodes) {
            $vals = []; $args = [];
            foreach ($nodes as $i => $n) {
                $m = $n['member'] ?? null; if (!$m || empty($m['id'])) continue;
                $vals[] = "(:m$i,:s$i,:sn$i,:e$i,NOW())";
                $args[":m$i"]=$m['id']; $args[":s$i"]=$sid; $args[":sn$i"]=$sname; $args[":e$i"]=$m['email']??null;
            }
            if ($vals) {
                $st = $pdo->prepare("INSERT INTO bettermode_member_spaces (member_id,space_id,space_name,email,synced_at) VALUES "
                    . implode(',', $vals) . " ON CONFLICT (member_id,space_id) DO UPDATE SET space_name=EXCLUDED.space_name,email=EXCLUDED.email,synced_at=NOW()");
                $st->execute($args);
            }
        }
        $cnt += count($nodes);
        $cursor = ($d['pageInfo']['hasNextPage'] ?? false) ? ($d['pageInfo']['endCursor'] ?? null) : null;
    } while ($cursor !== null && count($nodes) > 0);
    $totalMs += $cnt;
    printf("  %-32s %6d miembros\n", substr($sname,0,32), $cnt);
}
echo "\nMembresías insertadas: $totalMs\n";
echo "LISTO.\n";
