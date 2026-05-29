<?php declare(strict_types=1);

/**
 * Importa mapeo class_id → class_name desde un CSV exportado de Hotmart Club.
 *
 * CSV esperado (separador ';'): columnas Email + Grupo (mínimo).
 * La columna Email se cruza con public.club_students para obtener el class_id
 * (en el subdomain pasado por arg), y se UPSERT en public.hotmart_club_classes.
 *
 * Uso:
 *   php scripts/import-classes-from-csv.php <ruta_csv> <subdomain> [--apply]
 *
 * Sin --apply hace dry-run (no toca la BD).
 */

// Mini loader de .env (sin phpdotenv) — soporta DB_PASS y DB_PASSWORD.
function load_env(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k); $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v"); $_ENV[$k] = $v;
        }
    }
}

// Carga primero el .env local de dashboard-allaccess (si existe), luego el del proyecto principal
// como fallback (DB Neon compartida).
$root = dirname(__DIR__);
load_env($root . '/.env');
load_env(dirname($root) . '/DASHBOARD_5T4D10_PTT/.env');

require $root . '/vendor/autoload.php';

use App\Database;

[$_, $csvPath, $subdomain] = array_pad($argv, 3, null);
$apply = in_array('--apply', $argv, true);

if (!$csvPath || !$subdomain) {
    fwrite(STDERR, "Uso: php import-classes-from-csv.php <ruta_csv> <subdomain> [--apply]\n");
    exit(2);
}
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV no encontrado: $csvPath\n");
    exit(2);
}

$mode = $apply ? 'APPLY (escribe en BD)' : 'DRY-RUN (no escribe)';
echo "Modo: $mode\nCSV : $csvPath\nSub : $subdomain\n\n";

$f = fopen($csvPath, 'r');
$header = fgetcsv($f, 0, ';');
$idxEmail = array_search('Email', $header, true);
$idxGrupo = array_search('Grupo', $header, true);
if ($idxEmail === false || $idxGrupo === false) {
    fwrite(STDERR, "CSV sin columnas 'Email' y/o 'Grupo'. Header: ".implode('|', $header)."\n");
    exit(2);
}

$pdo = Database::get();
$stLookup = $pdo->prepare(
    "SELECT class_id FROM public.club_students
      WHERE LOWER(email) = :email AND subdomain = :sd
        AND class_id IS NOT NULL AND class_id <> ''"
);

$rowsCsv = 0; $rowsCsvWithGrupo = 0; $matched = 0; $missing = [];
$mapping = []; // class_id => [grupo => count]

while (($r = fgetcsv($f, 0, ';')) !== false) {
    $rowsCsv++;
    $email = strtolower(trim($r[$idxEmail] ?? ''));
    $grupo = trim($r[$idxGrupo] ?? '');
    if ($email === '' || $grupo === '' || $grupo === '-' || $grupo === '—') continue;
    $rowsCsvWithGrupo++;

    $stLookup->execute([':email' => $email, ':sd' => $subdomain]);
    $cid = $stLookup->fetchColumn();
    if (!$cid) { $missing[] = $email; continue; }
    $matched++;
    $mapping[(string)$cid][$grupo] = ($mapping[(string)$cid][$grupo] ?? 0) + 1;
}
fclose($f);

echo "Filas CSV total           : $rowsCsv\n";
echo "Filas CSV con Grupo       : $rowsCsvWithGrupo\n";
echo "Match (email→class_id)    : $matched\n";
echo "Sin match (email en DB)   : ".count($missing)."\n";
echo "class_ids únicos mapeados : ".count($mapping)."\n\n";

if ($missing && count($missing) <= 10) {
    echo "Primeros emails sin match en DB:\n";
    foreach (array_slice($missing, 0, 10) as $m) echo "  - $m\n";
    echo "\n";
}

// Resolver: por cada class_id, elegir el grupo más frecuente; reportar conflictos.
$resolved = []; $conflicts = 0;
foreach ($mapping as $cid => $groups) {
    arsort($groups);
    $top = (string) array_key_first($groups);
    $resolved[$cid] = $top;
    if (count($groups) > 1) {
        $conflicts++;
        echo "WARN $cid: múltiples grupos -> ".json_encode($groups, JSON_UNESCAPED_UNICODE)." | tomo: $top\n";
    }
}
if ($conflicts) echo "\nConflictos detectados: $conflicts (resueltos por mayoría)\n\n";

echo "Mapeos finales (class_id => class_name):\n";
ksort($resolved);
foreach ($resolved as $cid => $g) echo sprintf("  %-12s => %s\n", $cid, $g);
echo "\n";

if (!$apply) {
    echo "DRY-RUN. Nada escrito. Para aplicar, vuelve a correr con --apply\n";
    exit(0);
}

$ups = $pdo->prepare("
    INSERT INTO public.hotmart_club_classes (subdomain, class_id, class_name)
    VALUES (:sd, :cid, :name)
    ON CONFLICT (subdomain, class_id)
    DO UPDATE SET class_name = EXCLUDED.class_name, updated_at = NOW()
");

$pdo->beginTransaction();
$ok = 0;
foreach ($resolved as $cid => $g) {
    $ups->execute([':sd' => $subdomain, ':cid' => $cid, ':name' => $g]);
    $ok++;
}
$pdo->commit();
echo "UPSERTs aplicados: $ok\n";
