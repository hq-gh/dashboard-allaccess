<?php declare(strict_types=1);

/**
 * Sync de permisos Bettermode (cron 12h). Por DEFECTO corre en dry-run (no toca
 * Bettermode); requiere --apply explícito para otorgar/revocar.
 *
 * Uso:
 *   php bin/permission-sync.php            # dry-run (reporte)
 *   php bin/permission-sync.php --apply    # aplica grants/revokes en producción
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
use App\Sync\PermissionSyncEngine;

$mode       = in_array('--apply', $argv, true) ? 'apply' : 'dry_run';
$grantsOnly = in_array('--grants-only', $argv, true);
$trigger    = in_array('--cron', $argv, true) ? 'cron' : 'cli';

// Factory de PDO: crea conexión NUEVA cada llamada (Neon cierra conexiones idle;
// el motor reconecta tras la fase larga de API).
$pdoFactory = function (): PDO {
    $host = getenv('DB_HOST');
    $name = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'neondb');
    $user = getenv('DB_USER') ?: getenv('DB_USERNAME');
    $pass = getenv('DB_PASS') ?: getenv('DB_PASSWORD');
    return new PDO("pgsql:host={$host};dbname={$name};sslmode=require", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};
$bm  = new BettermodeClient(fn($l, $e, $c) => null);
$engine = new PermissionSyncEngine($pdoFactory, $bm);

fwrite(STDOUT, "=== Permission Sync · modo=$mode" . ($grantsOnly ? " (grants-only)" : "") . " ===\n");
$t0 = microtime(true);
$r = $engine->run($mode, $grantsOnly, $trigger);

if (($r['status'] ?? '') === 'aborted_locked') {
    fwrite(STDOUT, "Abortado: otra corrida está activa (lock).\n");
    exit(0);
}

$mins = round((microtime(true) - $t0) / 60, 1);
$bspace = function (array $byId, array $names) {
    arsort($byId);
    $out = [];
    foreach ($byId as $sid => $n) $out[] = "    " . ($names[$sid] ?? $sid) . " ($sid): $n";
    return implode("\n", $out) ?: "    (ninguno)";
};

fwrite(STDOUT, "\n================ REPORTE (run {$r['run_id']}, {$mins} min) ================\n");
fwrite(STDOUT, "Modo: {$r['mode']}  | status: {$r['status']}\n");
fwrite(STDOUT, "Usuarios procesados: {$r['users_processed']}  | con cambios: {$r['users_changed']}\n\n");

$grantsExec = $r['mode'] === 'apply' ? "{$r['grants_ok']} EJECUTADOS" . ($r['grants_failed'] ? " (fallidos {$r['grants_failed']})" : "") : "{$r['grants_ok']} (dry-run)";
fwrite(STDOUT, "1) ACCESOS A AGREGAR (grants): $grantsExec\n");
fwrite(STDOUT, $bspace($r['grants_by_space'], $r['managed_spaces']) . "\n\n");

$revInfo = ($r['mode'] === 'apply' && !$r['grants_only'])
    ? "{$r['revokes_ok']} EJECUTADOS" . ($r['revokes_failed'] ? " (fallidos {$r['revokes_failed']})" : "")
    : "{$r['revokes_pending']} CALCULADOS (NO ejecutados" . ($r['grants_only'] ? ", grants-only" : ", dry-run") . ")";
fwrite(STDOUT, "2) ACCESOS A REMOVER (revokes): $revInfo  | protegidos saltados: {$r['protected_skipped']}\n");
fwrite(STDOUT, $bspace($r['revokes_by_space'], $r['managed_spaces']) . "\n\n");

fwrite(STDOUT, "3) USUARIOS QUE PERDERÍAN TODOS SUS ACCESOS: " . count($r['losing_all']) . "\n");
foreach (array_slice($r['losing_all'], 0, 25) as $u) fwrite(STDOUT, "    {$u['email']} (" . count($u['spaces']) . " spaces)\n");
if (count($r['losing_all']) > 25) fwrite(STDOUT, "    ... +" . (count($r['losing_all']) - 25) . " más (ver CSV)\n");

fwrite(STDOUT, "\n4) USUARIOS VIGENTES SIN CUENTA BETTERMODE (se crearían en apply): {$r['accounts_missing']}\n");
foreach (array_slice($r['missing_accounts'], 0, 25) as $e) fwrite(STDOUT, "    $e\n");
if ($r['accounts_missing'] > 25) fwrite(STDOUT, "    ... +" . ($r['accounts_missing'] - 25) . " más (ver CSV)\n");

fwrite(STDOUT, "\n5) CASOS VIP CON INFINITY VENCIDO (perderían los 5 spaces VIP): " . count($r['vip_infinity_expired']) . "\n");
foreach (array_slice($r['vip_infinity_expired'], 0, 25) as $e) fwrite(STDOUT, "    $e\n");
if (count($r['vip_infinity_expired']) > 25) fwrite(STDOUT, "    ... +" . (count($r['vip_infinity_expired']) - 25) . " más\n");

fwrite(STDOUT, "\n6) ERRORES / INCONSISTENCIAS: " . count($r['errors']) . "\n");
foreach (array_slice($r['errors'], 0, 30) as $e) fwrite(STDOUT, "    $e\n");
if (count($r['errors']) > 30) fwrite(STDOUT, "    ... +" . (count($r['errors']) - 30) . " más\n");

// CSV con el detalle por usuario con cambios
$csvPath = $root . '/scripts/.permission-sync-' . $r['mode'] . '.csv';
$fp = fopen($csvPath, 'w');
fputcsv($fp, ['email', 'member_id', 'desired_spaces', 'current_spaces', 'grants', 'revokes', 'pierde_todo']);
foreach ($r['csv'] as $row) fputcsv($fp, $row);
fclose($fp);
fwrite(STDOUT, "\nDetalle por usuario (con cambios): $csvPath\n");
fwrite(STDOUT, "Listas completas (losing_all / missing / vip): tabla user_program_validity + permission_sync_runs (run {$r['run_id']}).\n");
