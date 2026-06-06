<?php declare(strict_types=1);

/**
 * Resuelve los suscriptores Infinity ACTIVOS que no tienen cuenta en Bettermode
 * (los NOT_FOUND del backfill 8EIGHT MAX.). Replica el grant del webhook:
 *   1. findMemberByEmail (re-verifica; evita duplicar si ya existe).
 *   2. Si no existe -> createMember (joinNetwork, password temporal) + verifyMember.
 *   3. updateMemberField('Infinity','true')  (best-effort).
 *   4. grantSpaceAccess a los 20 spaces activos del product_key 'infinity'.
 *
 * Fuente de emails: el .log del backfill (entradas NOT_FOUND).
 * Throttle: lo hace el propio BettermodeClient (BETTERMODE_SLEEP_MS_BETWEEN_CALLS).
 * Resume-safe: relee su propio .log y salta los ya resueltos (CREATED/EXISTED sin fallos).
 * Uso:  php scripts/fix-infinity-missing-accounts.php [--dry-run]
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

const PRODUCT_KEY   = 'infinity';
const FIELD_KEY     = 'Infinity';
const TEMP_PASSWORD = 'Password!54321';        // paridad con el webhook
const REPORT_EVERY  = 10;
$BACKFILL_LOG = $root . '/scripts/.backfill-infinity-8eight-max.log';
$LOG_PATH     = $root . '/scripts/.fix-infinity-missing-accounts.log';
$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::get();

// 1) Spaces activos de infinity (20)
$spaces = $pdo->query(
    "SELECT space_id, space_name FROM bettermode_spaces
      WHERE product_key = '" . PRODUCT_KEY . "' AND is_active = TRUE ORDER BY sort_order"
)->fetchAll(PDO::FETCH_ASSOC);

// 2) Emails NOT_FOUND del backfill
$notFound = [];
foreach (file($BACKFILL_LOG, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $ln) {
    $j = json_decode($ln, true);
    if (($j['status'] ?? '') === 'NOT_FOUND' && !empty($j['email'])) $notFound[$j['email']] = true;
}
$emails = array_keys($notFound);

// 3) Nombres desde subscriptions
$names = [];
if ($emails) {
    $in = implode(',', array_fill(0, count($emails), '?'));
    $st = $pdo->prepare(
        "SELECT LOWER(subscriber_email) email, MAX(NULLIF(TRIM(subscriber_name),'')) name
           FROM subscriptions WHERE LOWER(subscriber_email) IN ($in) GROUP BY 1"
    );
    $st->execute($emails);
    foreach ($st as $r) $names[$r['email']] = (string) ($r['name'] ?? '');
}

echo "Fix Infinity: crear cuenta + asignar 20 spaces a los NOT_FOUND\n";
echo "=============================================================\n";
echo "Product  : " . PRODUCT_KEY . " | field=" . FIELD_KEY . " | spaces=" . count($spaces) . "\n";
echo "Modo     : " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo "Targets  : " . count($emails) . " emails (NOT_FOUND del backfill)\n";

// resume
$done = [];
if (is_file($LOG_PATH)) {
    foreach (file($LOG_PATH, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $ln) {
        $j = json_decode($ln, true);
        if (!empty($j['email'])) {
            $okFinal = in_array($j['status'] ?? '', ['CREATED','EXISTED'], true) && (int)($j['spaces_failed'] ?? 1) === 0;
            if ($okFinal) $done[$j['email']] = true;
        }
    }
    echo "Resume   : " . count($done) . " ya resueltos\n";
}
$pending = array_values(array_filter($emails, fn($e) => !isset($done[$e])));
echo "Pendientes: " . count($pending) . "\n\n";

if ($dryRun) {
    echo "DRY-RUN. Muestra de 5 targets con nombre:\n";
    foreach (array_slice($pending, 0, 5) as $e) echo "  $e  ->  name=" . ($names[$e] ?? '(sin nombre)') . "\n";
    exit(0);
}

$client = new BettermodeClient(fn($l, $e, $c) => null);
$logFp = fopen($LOG_PATH, 'a');
$log = function(array $e) use ($logFp) { $e['ts'] = time(); fwrite($logFp, json_encode($e, JSON_UNESCAPED_UNICODE)."\n"); fflush($logFp); };

$genUser = function(string $name, string $email): string {
    $base = strtolower($name);
    $base = strtr($base, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n']);
    $base = preg_replace('/[^a-z0-9]/', '', $base) ?? '';
    $base = substr($base, 0, 12);
    if (strlen($base) < 3) { $local = strstr($email, '@', true) ?: $email; $base = substr(preg_replace('/[^a-z0-9]/', '', strtolower($local)) ?? '', 0, 12); }
    return $base . (string) random_int(1000, 9999);
};

$start = time(); $created = 0; $existed = 0; $err = 0; $spacesFailTotal = 0;
foreach ($pending as $i => $email) {
    $idx = $i + 1;
    $name = ($names[$email] ?? '') !== '' ? $names[$email] : (strstr($email, '@', true) ?: $email);
    try {
        $member = $client->findMemberByEmail($email);
        $wasCreated = false;
        if ($member === null) {
            $username = $genUser($name, $email);
            $member = $client->createMember($email, $name, TEMP_PASSWORD, $username);
            $wasCreated = true;
        }
        $memberId = (string) $member['id'];
        // verificar (la mayoría son cuentas UNVERIFIED) + setear field — best-effort
        try { $client->verifyMember($memberId); } catch (\Throwable $e) {}
        try { $client->updateMemberField($memberId, FIELD_KEY, 'true'); } catch (\Throwable $e) {}

        $sOk = 0; $sFail = 0; $fails = [];
        foreach ($spaces as $sp) {
            try { $client->grantSpaceAccess($memberId, (string)$sp['space_id']); $sOk++; }
            catch (\Throwable $e) { $sFail++; $fails[] = $sp['space_name'].': '.substr($e->getMessage(),0,80); }
        }
        $spacesFailTotal += $sFail;
        if ($wasCreated) $created++; else $existed++;
        $log(['email'=>$email,'status'=>$wasCreated?'CREATED':'EXISTED','memberId'=>$memberId,'spaces_ok'=>$sOk,'spaces_failed'=>$sFail,'fails'=>array_slice($fails,0,3)]);
    } catch (\Throwable $e) {
        $err++;
        $log(['email'=>$email,'status'=>'ERROR','msg'=>substr($e->getMessage(),0,200)]);
    }
    if ($idx % REPORT_EVERY === 0 || $idx === count($pending)) {
        $el = time() - $start; $rate = $el>0 ? $idx/$el : 0; $remain = $rate>0 ? (count($pending)-$idx)/$rate : 0;
        printf("[%s] %d/%d (Created=%d Existed=%d Err=%d SpaceFails=%d) | %.2f/s | ETA=%dm\n",
            date('H:i:s'), $idx, count($pending), $created, $existed, $err, $spacesFailTotal, $rate, (int)($remain/60));
    }
}
fclose($logFp);
echo "\nFinalizado en " . round((time()-$start)/60) . " min.\n";
echo "  CREATED      : $created\n  EXISTED      : $existed\n  ERROR        : $err\n  Space fails  : $spacesFailTotal\n";
