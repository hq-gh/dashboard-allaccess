<?php declare(strict_types=1);

/**
 * Onboarding manual de compradores Infinity VIP legacy (compraron antes
 * del webhook nuevo del 28-may-2026; los Apps Scripts viejos no procesaron
 * su alta en Bettermode).
 *
 * Para cada email:
 *   0. Validar que NO sea PECADOR (VIP activo + Infinity inactivo). Si lo es, SKIP.
 *   1. createMember (joinNetwork con password default)
 *   2. verifyMember
 *   3. grantSpaceAccess para los 22 spaces (20 de infinity + 2 de infinity_vip)
 *
 * Throttle 1s entre calls. Resume-safe via log JSON.
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

const TEMP_PASSWORD = 'Password!54321';
const SLEEP_SEC     = 1;
$LOG_PATH = $root . '/scripts/.onboard-vip-legacy.log';
$dryRun = in_array('--dry-run', $argv, true);

const EMAILS = [
    'alejitabe@gmail.com',
    'amgaona@gmail.com',
    'dyhana910@hotmail.com',
    'flarroa@gmail.com',
    'hibarrola@yahoo.com.mx',
    'luchyringuis@hotmail.com',
    'maru.monroy17@gmail.com',
    'netorosales@gmail.com',
    'storresalicealaw@gmail.com',
    'tambo.andres@gmail.com',
    'vergelindira@hotmail.com',
];

function generateUsername(string $name, string $email): string {
    $base = strtolower($name);
    $base = strtr($base, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n']);
    $base = preg_replace('/[^a-z0-9]/', '', $base) ?? '';
    $base = substr($base, 0, 12);
    if (strlen($base) < 3) {
        $local = strstr($email, '@', true) ?: $email;
        $base = substr(preg_replace('/[^a-z0-9]/', '', strtolower($local)) ?? '', 0, 12);
    }
    return $base . (string)random_int(1000, 9999);
}

$pdo = Database::get();

// === 0. PECADOR CHECK ===
// Pecador = VIP activo AND Infinity inactivo. NO se onboardea.
$pecadorCheck = $pdo->prepare(
    "SELECT BOOL_OR(product_id IN ('6587403','7005612','7005981')
                    AND status IN ('ACTIVE','DELAYED','STARTED','OVERDUE')) AS vip,
            BOOL_OR(product_id IN ('6454766','7065704','6952229')
                    AND status IN ('ACTIVE','DELAYED','STARTED','OVERDUE')) AS infinity
     FROM subscriptions WHERE LOWER(subscriber_email) = :e"
);
$validEmails = [];
$pecadores   = [];
foreach (EMAILS as $em) {
    $pecadorCheck->execute([':e' => $em]);
    $r = $pecadorCheck->fetch();
    if ($r && $r['vip'] && !$r['infinity']) $pecadores[] = $em;
    else                                     $validEmails[] = $em;
}

// === Spaces y nombres ===
$spaces = $pdo->query(
    "SELECT space_id FROM public.bettermode_spaces
     WHERE product_key IN ('infinity','infinity_vip') AND is_active=TRUE
     ORDER BY product_key, sort_order"
)->fetchAll(PDO::FETCH_COLUMN);

$names = [];
if ($validEmails) {
    $place = implode(',', array_fill(0, count($validEmails), '?'));
    $st = $pdo->prepare("SELECT LOWER(email_comprador) e, MAX(comprador) n FROM patito_ventas WHERE LOWER(email_comprador) IN ($place) GROUP BY LOWER(email_comprador)");
    $st->execute($validEmails);
    foreach ($st->fetchAll() as $r) $names[$r['e']] = (string)($r['n'] ?? '');
}

echo "Onboarding Infinity VIP legacy\n";
echo "==============================\n";
echo "Modo     : " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo "Input    : " . count(EMAILS) . " emails | Pecadores filtrados: " . count($pecadores) . " | Válidos: " . count($validEmails) . "\n";
echo "Spaces   : " . count($spaces) . " por email\n";
echo "Password : " . TEMP_PASSWORD . "\n";
echo "Throttle : " . SLEEP_SEC . "s entre calls\n\n";

if ($pecadores) {
    echo "⚠️  Pecadores detectados (NO se procesarán — sus cuentas serían inválidas):\n";
    foreach ($pecadores as $em) echo "  - $em\n";
    echo "\n";
}

$done = [];
if (is_file($LOG_PATH)) {
    foreach (file($LOG_PATH, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $ln) {
        $j = json_decode($ln, true);
        if (($j['status'] ?? '') === 'OK') $done[$j['email']] = true;
    }
    echo "Resume   : " . count($done) . " ya OK\n\n";
}

if ($dryRun) {
    echo "=== Sample 5 emails válidos con username generado ===\n";
    foreach (array_slice($validEmails, 0, 5) as $em) {
        $n = $names[$em] ?? '';
        if ($n === '' || strlen($n) < 2) $n = explode('@', $em)[0];
        printf("  %-32s | name=%-25s | username=%s\n", $em, substr($n,0,25), generateUsername($n, $em));
    }
    echo "\nDRY-RUN.\n"; exit(0);
}

$client = new BettermodeClient(fn($l,$e,$c) => null);
$logFp = fopen($LOG_PATH, 'a');
$log = function(array $e) use ($logFp) { $e['ts']=time(); fwrite($logFp, json_encode($e, JSON_UNESCAPED_UNICODE)."\n"); fflush($logFp); };

$total = count($validEmails);
$start = time(); $ok=0; $failed=0; $spacesOk=0; $spacesFail=0;
foreach ($validEmails as $i => $email) {
    if (isset($done[$email])) continue;
    $idx = $i+1;
    $name = $names[$email] ?? '';
    if ($name === '' || strlen($name) < 2) $name = explode('@', $email)[0];
    $username = generateUsername($name, $email);

    try {
        $member = $client->findMemberByEmail($email);
        sleep(SLEEP_SEC);

        if (!$member) {
            $new = $client->createMember($email, $name, TEMP_PASSWORD, $username);
            sleep(SLEEP_SEC);
            $memberId = (string)$new['id'];
            try { $client->verifyMember($memberId); } catch (\Throwable $vErr) { /* ya verificado */ }
            sleep(SLEEP_SEC);
        } else {
            $memberId = (string)$member['id'];
        }

        $sOk=0; $sFail=0;
        foreach ($spaces as $sid) {
            try { $client->grantSpaceAccess($memberId, $sid); $sOk++; }
            catch (\Throwable $ge) { $sFail++; }
            sleep(SLEEP_SEC);
        }
        $spacesOk += $sOk; $spacesFail += $sFail;
        $ok++;
        $log(['email'=>$email,'status'=>'OK','memberId'=>$memberId,'username'=>$username,'spacesOk'=>$sOk,'spacesFail'=>$sFail]);
        printf("[%s] %d/%d %s | memberId=%s | spaces OK=%d Fail=%d\n", date('H:i:s'), $idx, $total, $email, $memberId, $sOk, $sFail);
    } catch (\Throwable $e) {
        $failed++;
        $log(['email'=>$email,'status'=>'FAILED','msg'=>substr($e->getMessage(),0,200)]);
        printf("[%s] %d/%d %s | FAILED: %s\n", date('H:i:s'), $idx, $total, $email, substr($e->getMessage(),0,80));
        sleep(5);
    }
}
fclose($logFp);
$el = time()-$start;
echo "\nFinalizado en ".round($el/60,1)." min.\n  OK=$ok | FAILED=$failed | spaces OK=$spacesOk Fail=$spacesFail\n";
