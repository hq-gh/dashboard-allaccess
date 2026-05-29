<?php declare(strict_types=1);

/**
 * Reconcilia public.club_students.class_id usando el CSV de Hotmart como
 * fuente de verdad. Para cada (email, subdomain) del CSV, busca el class_id
 * que corresponde al nombre del Grupo en public.hotmart_club_classes y, si
 * difiere del que tiene Neon, lo actualiza.
 *
 * Requisito: hotmart_club_classes ya debe estar poblada para el subdomain
 * (corre primero scripts/import-classes-from-csv.php).
 *
 * Uso:
 *   php scripts/reconcile-classids-from-csv.php <ruta_csv> <subdomain> [--apply]
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
load_env(dirname($root) . '/DASHBOARD_5T4D10_PTT/.env');
require $root . '/vendor/autoload.php';
use App\Database;

[$_, $csvPath, $subdomain] = array_pad($argv, 3, null);
$apply = in_array('--apply', $argv, true);

if (!$csvPath || !$subdomain) {
    fwrite(STDERR, "Uso: php reconcile-classids-from-csv.php <ruta_csv> <subdomain> [--apply]\n");
    exit(2);
}
if (!is_file($csvPath)) { fwrite(STDERR, "CSV no encontrado: $csvPath\n"); exit(2); }

echo "Modo : ".($apply ? "APPLY" : "DRY-RUN")."\nCSV  : $csvPath\nSub  : $subdomain\n\n";

$pdo = Database::get();

// name → class_id en hotmart_club_classes (para este subdomain)
$nameToCid = [];
$st = $pdo->prepare("SELECT class_id, class_name FROM public.hotmart_club_classes WHERE subdomain=:sd AND class_name IS NOT NULL AND class_name <> ''");
$st->execute([':sd' => $subdomain]);
foreach ($st->fetchAll() as $r) $nameToCid[$r['class_name']] = $r['class_id'];

if (!$nameToCid) {
    fwrite(STDERR, "hotmart_club_classes vacío para subdomain $subdomain. Corre primero import-classes-from-csv.php\n");
    exit(2);
}

$stCur = $pdo->prepare("SELECT class_id FROM public.club_students WHERE LOWER(email)=:e AND subdomain=:sd");
$stUpd = $pdo->prepare("UPDATE public.club_students SET class_id=:cid WHERE LOWER(email)=:e AND subdomain=:sd");

$f = fopen($csvPath, 'r');
$h = fgetcsv($f, 0, ';');
$iE = array_search('Email', $h, true);
$iG = array_search('Grupo', $h, true);
if ($iE === false || $iG === false) { fwrite(STDERR, "CSV sin Email/Grupo\n"); exit(2); }

$total = 0; $ok = 0; $toUpdate = []; $unknownGrupo = [];
while (($r = fgetcsv($f, 0, ';')) !== false) {
    $em = strtolower(trim($r[$iE] ?? ''));
    $g  = trim($r[$iG] ?? '');
    if ($em === '' || $g === '') continue;
    $total++;

    $expectedCid = $nameToCid[$g] ?? null;
    if ($expectedCid === null) { $unknownGrupo[$g] = ($unknownGrupo[$g] ?? 0) + 1; continue; }

    $stCur->execute([':e'=>$em, ':sd'=>$subdomain]);
    $curCid = $stCur->fetchColumn();
    if ($curCid === false) continue;

    if ($curCid === $expectedCid) { $ok++; continue; }
    $toUpdate[] = [$em, $curCid, $expectedCid, $g];
}
fclose($f);

echo "Filas CSV totales        : $total\n";
echo "Coinciden (sin cambio)   : $ok\n";
echo "A actualizar             : ".count($toUpdate)."\n";
echo "Grupo del CSV no mapeado : ".count($unknownGrupo)." (sumando: ".array_sum($unknownGrupo).")\n\n";

if ($unknownGrupo) {
    echo "Grupos del CSV sin entry en hotmart_club_classes:\n";
    foreach ($unknownGrupo as $g => $c) echo "  - \"$g\" ($c alumnos)\n";
    echo "\n";
}

if ($toUpdate) {
    echo "Cambios a aplicar (email | class_id viejo → nuevo | Grupo CSV):\n";
    foreach ($toUpdate as [$em, $old, $new, $g]) {
        printf("  %s | %s -> %s | %s\n", $em, $old, $new, $g);
    }
    echo "\n";
}

if (!$apply) { echo "DRY-RUN. Nada escrito. Vuelve a correr con --apply\n"; exit(0); }
if (!$toUpdate) { echo "Nada que actualizar.\n"; exit(0); }

$pdo->beginTransaction();
$done = 0;
foreach ($toUpdate as [$em, $old, $new, $g]) {
    $stUpd->execute([':cid'=>$new, ':e'=>$em, ':sd'=>$subdomain]);
    $done++;
}
$pdo->commit();
echo "UPDATEs aplicados: $done\n";
