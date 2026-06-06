<?php declare(strict_types=1);

/**
 * Backfill: asigna el espacio "-INFINITY VIP- XTREME BURN" (lt3hvpzqHzJS) a todos
 * los miembros VIP activos. Toma los bettermode_member_id desde vervip_estado_actual
 * (tiene_acceso_vip = true) — no requiere buscar por email.
 *
 * Idempotente (re-grant no rompe). Resume-safe vía su .log.
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

use App\Database;
use App\Bettermode\BettermodeClient;

const SPACE_ID = 'lt3hvpzqHzJS';   // -INFINITY VIP- XTREME BURN
$LOG = $root . '/scripts/.backfill-vip-xtreme-burn.log';
$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::get();
$rows = $pdo->query(
    "SELECT bettermode_member_id AS mid, LOWER(email) AS email
       FROM vervip_estado_actual
      WHERE tiene_acceso_vip = TRUE
        AND bettermode_member_id IS NOT NULL AND bettermode_member_id <> ''"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Backfill VIP XTREME BURN (space lt3hvpzqHzJS)\n";
echo "Modo: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . " | VIP activos con member_id: " . count($rows) . "\n";

$done = [];
if (is_file($LOG)) foreach (file($LOG, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l) {
    $j = json_decode($l, true); if (($j['status'] ?? '') === 'OK' && !empty($j['mid'])) $done[$j['mid']] = true;
}
$pending = array_values(array_filter($rows, fn($r) => !isset($done[$r['mid']])));
echo "Pendientes: " . count($pending) . " (ya OK: " . count($done) . ")\n";
if ($dryRun) { echo "DRY-RUN.\n"; exit(0); }

$bm = new BettermodeClient(fn(...$a) => null);
$fp = fopen($LOG, 'a');
$ok = 0; $err = 0;
foreach ($pending as $i => $r) {
    try { $bm->grantSpaceAccess((string)$r['mid'], SPACE_ID); $ok++; $st = 'OK'; $msg = ''; }
    catch (\Throwable $e) { $err++; $st = 'ERR'; $msg = substr($e->getMessage(), 0, 120); }
    fwrite($fp, json_encode(['mid'=>$r['mid'],'email'=>$r['email'],'status'=>$st,'msg'=>$msg], JSON_UNESCAPED_UNICODE)."\n");
    if (($i+1) % 25 === 0) echo "  " . ($i+1) . "/" . count($pending) . " (OK=$ok Err=$err)\n";
}
fclose($fp);
echo "\nFinalizado. OK=$ok  ERROR=$err  de " . count($pending) . " pendientes.\n";
