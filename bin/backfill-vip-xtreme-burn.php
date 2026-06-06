<?php declare(strict_types=1);

/**
 * Reconciliador: asegura que todo miembro de los espacios VIP existentes tenga
 * también el espacio "-INFINITY VIP- XTREME BURN" (lt3hvpzqHzJS).
 *
 * Fuente EN VIVO desde Bettermode (no la tabla espejo, que puede estar vieja):
 *   - SOURCE_SPACES: espacios VIP que definen "ser VIP" (ALL ACCESS + Retos y Más).
 *   - Se concede TARGET_SPACE a quien esté en algún SOURCE y NO esté ya en TARGET.
 *
 * Idempotente. Pensado para correr en cron (domingo 6AM CdMx) y/o a mano.
 * Uso:  php bin/backfill-vip-xtreme-burn.php [--dry-run]
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

use App\Bettermode\BettermodeClient;

const TARGET_SPACE  = 'lt3hvpzqHzJS';                       // -INFINITY VIP- XTREME BURN
const SOURCE_SPACES = ['OnOLt4PLDpGe', 'Ym4TTsZsttrx'];     // VIP ALL ACCESS + Retos y Más
const PAGE = 100;
const MAX_RETRY = 5;
$dryRun = in_array('--dry-run', $argv, true);
$bm = new BettermodeClient(fn(...$a) => null);

function gqlRetry(BettermodeClient $bm, string $q): array {
    $last = null;
    for ($i = 1; $i <= MAX_RETRY; $i++) {
        try { return $bm->query($q); } catch (\Throwable $e) { $last = $e; usleep(600000 * $i); }
    }
    throw $last;
}
/** @return array<string,string> member_id => email, para todos los miembros de un espacio */
function membersOf(BettermodeClient $bm, string $spaceId): array {
    $out = []; $cursor = null;
    do {
        $after = $cursor ? ', after: ' . json_encode($cursor) : '';
        $q = 'query { spaceMembers(spaceId: ' . json_encode($spaceId) . ', limit:' . PAGE . $after . '){ pageInfo{ endCursor hasNextPage } nodes{ member{ id email } } } }';
        $d = gqlRetry($bm, $q)['spaceMembers'] ?? [];
        foreach (($d['nodes'] ?? []) as $n) {
            $m = $n['member'] ?? null;
            if ($m && !empty($m['id'])) $out[$m['id']] = $m['email'] ?? '';
        }
        $cursor = ($d['pageInfo']['hasNextPage'] ?? false) ? ($d['pageInfo']['endCursor'] ?? null) : null;
    } while ($cursor !== null);
    return $out;
}

echo "Reconciliador VIP XTREME BURN (target " . TARGET_SPACE . ")\n";
echo "Modo: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";

$source = [];
foreach (SOURCE_SPACES as $s) { $source += membersOf($bm, $s); }
$already = membersOf($bm, TARGET_SPACE);
$todo = array_diff_key($source, $already);

echo "VIP (union de espacios fuente): " . count($source) . " | ya tienen XTREME BURN VIP: " . count($already) . " | faltan: " . count($todo) . "\n";
if ($dryRun) { echo "DRY-RUN.\n"; exit(0); }

$LOG = $root . '/scripts/.backfill-vip-xtreme-burn.log';
$fp = fopen($LOG, 'a');
$ok = 0; $err = 0; $i = 0;
foreach ($todo as $mid => $email) {
    $i++;
    try { $bm->grantSpaceAccess((string)$mid, TARGET_SPACE); $ok++; $st='OK'; $msg=''; }
    catch (\Throwable $e) { $err++; $st='ERR'; $msg=substr($e->getMessage(),0,120); }
    fwrite($fp, json_encode(['ts'=>true,'mid'=>$mid,'email'=>$email,'status'=>$st,'msg'=>$msg], JSON_UNESCAPED_UNICODE)."\n");
    if ($i % 25 === 0) echo "  $i/" . count($todo) . " (OK=$ok Err=$err)\n";
}
fclose($fp);
echo "\nFinalizado. Concedidos OK=$ok  ERROR=$err  (de " . count($todo) . " faltantes).\n";
