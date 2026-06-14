<?php declare(strict_types=1);
/**
 * Crea cuentas Bettermode + otorga espacios a los usuarios VIGENTES que no tienen
 * cuenta (los "vigentes sin cuenta" que el motor cuenta pero no crea en apply).
 *
 * Fuente de vigencia: la última corrida de user_program_validity (is_valid).
 * Espacios deseados = union de bettermode_spaces de sus product_keys válidos.
 * Agrupa por ucode (hotmart_identity) → UNA cuenta por persona; matchea por
 * cualquiera de sus correos. Confirma en vivo (findMemberByEmail) antes de crear
 * para NO duplicar. Resume-safe (log .create-missing.log).
 *
 * Uso: php scripts/create-missing-accounts.php [--apply] [--limit=N]
 *      (sin --apply = dry-run: cuenta candidatos, no toca Bettermode)
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

$APPLY = in_array('--apply', $argv, true);
$LIMIT = 0; foreach ($argv as $a) if (str_starts_with($a, '--limit=')) $LIMIT = (int) substr($a, 8);
$TEMP_PASSWORD = 'Password!54321';
$LOG = $root . '/scripts/.create-missing.log';

$pdo = new PDO("pgsql:host=" . getenv('DB_HOST') . ";dbname=" . (getenv('DB_NAME') ?: 'neondb') . ";sslmode=require",
    getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$run = (int) $pdo->query("SELECT MAX(run_id) FROM user_program_validity")->fetchColumn();
fwrite(STDOUT, "Run de vigencia: $run | modo=" . ($APPLY ? 'APPLY' : 'dry-run') . "\n");

// 1) desired por email (válidos) + spaces
$desired = [];
$st = $pdo->query("SELECT LOWER(v.email) email, bs.space_id FROM user_program_validity v
    JOIN bettermode_spaces bs ON bs.product_key=v.product_key AND bs.is_active
    WHERE v.run_id=$run AND v.is_valid");
foreach ($st as $r) $desired[$r['email']][$r['space_id']] = true;

// 2) ucode map
$emailUcode = []; $ucodeEmails = [];
foreach ($pdo->query("SELECT ucode, LOWER(email) e FROM hotmart_identity") as $r) {
    $emailUcode[$r['e']] = $r['ucode']; $ucodeEmails[$r['ucode']][] = $r['e'];
}
// 3) agrupar desired por identidad (ucode o el propio email) + union de spaces y correos
$idEmails = []; $idSpaces = [];
foreach ($desired as $email => $sp) {
    $key = $emailUcode[$email] ?? ('email:' . $email);
    $idEmails[$key][$email] = true;
    foreach (array_keys($sp) as $sid) $idSpaces[$key][$sid] = true;
}
// añadir correos hermanos (mismo ucode) aunque no estén en desired
foreach (array_keys($idEmails) as $key) {
    if (str_starts_with($key, 'email:')) continue;
    foreach ($ucodeEmails[$key] ?? [] as $e) $idEmails[$key][$e] = true;
}

// 4) set de correos que YA tienen cuenta (espejo: members + member_spaces) — para narrow
$hasAccount = [];
foreach ($pdo->query("SELECT LOWER(email) e FROM bettermode_members WHERE email IS NOT NULL") as $r) $hasAccount[$r['e']] = true;
foreach ($pdo->query("SELECT DISTINCT LOWER(email) e FROM bettermode_member_spaces WHERE email IS NOT NULL") as $r) $hasAccount[$r['e']] = true;

// 5) candidatos = identidades donde NINGÚN correo aparece en el espejo
$cands = [];
foreach ($idEmails as $key => $emails) {
    $any = false; foreach (array_keys($emails) as $e) if (isset($hasAccount[$e])) { $any = true; break; }
    if (!$any) $cands[$key] = array_keys($emails);
}
fwrite(STDOUT, "Identidades vigentes: " . count($idEmails) . " | candidatas SIN cuenta (espejo): " . count($cands) . "\n");

if (!$APPLY) {
    $i = 0;
    foreach ($cands as $key => $emails) { if ($i++ >= 20) break; fwrite(STDOUT, "  " . $emails[0] . " (" . count($idSpaces[$key]) . " spaces)\n"); }
    fwrite(STDOUT, "(dry-run: nada ejecutado. Corre con --apply)\n");
    exit(0);
}

// 6) APPLY: confirmar en vivo y crear/otorgar
$done = [];
if (is_file($LOG)) foreach (file($LOG, FILE_IGNORE_NEW_LINES) as $l) { $p = explode("\t", $l); if (($p[1] ?? '') === 'OK') $done[$p[0]] = true; }
$bm = new BettermodeClient(fn($a, $b, $c) => null);
$lf = fopen($LOG, 'a');
$slug = function (string $name, string $email): string {
    $b = strtolower($name);
    $b = strtr($b, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
    $b = preg_replace('/[^a-z0-9]/', '', $b) ?? '';
    if (strlen($b) < 3) { $b = preg_replace('/[^a-z0-9]/', '', strtolower(strstr($email, '@', true) ?: $email)) ?? ''; }
    return substr($b, 0, 12) . random_int(1000, 9999);
};
$created = 0; $granted = 0; $skipExist = 0; $errs = 0; $n = 0;
foreach ($cands as $key => $emails) {
    if ($LIMIT && $n >= $LIMIT) break;
    $n++;
    $primary = $emails[0];
    if (isset($done[$key])) { continue; }
    try {
        // confirmar en vivo: ¿algún correo ya tiene cuenta?
        $memberId = null;
        foreach ($emails as $e) { $m = $bm->findMemberByEmail($e); if ($m !== null) { $memberId = $m['id']; break; } usleep(300000); }
        $isNew = false;
        if ($memberId === null) {
            $name = strstr($primary, '@', true) ?: $primary;
            $nm = $bm->createMember($primary, $name, $TEMP_PASSWORD, $slug($name, $primary));
            $memberId = $nm['id']; $isNew = true; $created++;
            try { $bm->verifyMember($memberId); } catch (\Throwable $e) {}
        } else { $skipExist++; }
        foreach (array_keys($idSpaces[$key]) as $sid) {
            try { $bm->grantSpaceAccess($memberId, $sid); $granted++; } catch (\Throwable $e) { /* ya miembro u otro */ }
            usleep(250000);
        }
        fwrite($lf, $primary . "\tOK\t" . ($isNew ? 'created' : 'existing') . "\t$memberId\n");
        if ($n % 20 === 0) fwrite(STDOUT, "  ... $n procesadas (created=$created, granted=$granted)\n");
    } catch (\Throwable $e) {
        $errs++; fwrite($lf, $primary . "\tERR\t" . substr($e->getMessage(), 0, 80) . "\n");
    }
}
fclose($lf);
fwrite(STDOUT, "\nLISTO. identidades procesadas=$n | cuentas creadas=$created | ya existían=$skipExist | grants=$granted | errores=$errs\n");
fwrite(STDOUT, "Log: $LOG\n");
