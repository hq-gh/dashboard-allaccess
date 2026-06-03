<?php declare(strict_types=1);

/**
 * Backfill: agregar space Ym4TTsZsttrx (-INFINITY VIP- Retos y Más) a TODOS
 * los suscriptores activos de Infinity VIP (product_ids 6587403/7005612/7005981).
 *
 * Throttle 1s entre calls. Resume-safe.
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

const SPACE_ID    = 'Ym4TTsZsttrx';
const SLEEP_SEC   = 1;
const REPORT_EVERY = 25;
$LOG_PATH = $root . '/scripts/.backfill-vip-new-space.log';
$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::get();
$emails = $pdo->query(
    "SELECT DISTINCT LOWER(subscriber_email) AS email
       FROM subscriptions
      WHERE product_id IN ('6587403','7005612','7005981')
        AND status IN ('ACTIVE','DELAYED','STARTED','OVERDUE')
        AND subscriber_email IS NOT NULL AND subscriber_email <> ''
      ORDER BY email"
)->fetchAll(PDO::FETCH_COLUMN);
$total = count($emails);

echo "Backfill nuevo space VIP (Ym4TTsZsttrx) a Infinity VIP activos\n";
echo "==============================================================\n";
echo "Space    : Ym4TTsZsttrx (-INFINITY VIP- Retos y Más)\n";
echo "Modo     : " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo "Targets  : $total emails\n";
echo "Throttle : " . SLEEP_SEC . "s entre calls (2 calls por email)\n";

$done = [];
if (is_file($LOG_PATH)) {
    foreach (file($LOG_PATH, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $ln) {
        $j = json_decode($ln, true);
        if (isset($j['email'])) $done[$j['email']] = $j['status'];
    }
    echo "Resume   : " . count($done) . " ya procesados\n";
}
$pending = array_values(array_filter($emails, fn($e) => !isset($done[$e])));
$eta = count($pending) * 2 * SLEEP_SEC;
echo "Pendientes: " . count($pending) . " | ETA: " . round($eta / 60, 1) . " min\n\n";

if ($dryRun) { echo "DRY-RUN.\n"; exit(0); }

$client = new BettermodeClient(fn($l, $e, $c) => null);
$logFp = fopen($LOG_PATH, 'a');
$log = function(array $e) use ($logFp) {
    $e['ts'] = time();
    fwrite($logFp, json_encode($e, JSON_UNESCAPED_UNICODE) . "\n");
    fflush($logFp);
};

$start = time(); $ok = 0; $notFound = 0; $err = 0;
foreach ($pending as $i => $email) {
    $idx = $i + 1;
    try {
        $member = $client->findMemberByEmail($email);
        sleep(SLEEP_SEC);
        if (!$member) {
            $notFound++;
            $log(['email' => $email, 'status' => 'NOT_FOUND']);
        } else {
            $memberId = (string)$member['id'];
            $client->grantSpaceAccess($memberId, SPACE_ID);
            sleep(SLEEP_SEC);
            $ok++;
            $log(['email' => $email, 'status' => 'OK', 'memberId' => $memberId]);
        }
    } catch (\Throwable $e) {
        $err++;
        $log(['email' => $email, 'status' => 'ERROR', 'msg' => substr($e->getMessage(), 0, 200)]);
        sleep(5);
    }
    if ($idx % REPORT_EVERY === 0 || $idx === count($pending)) {
        $el = time() - $start;
        $rate = $el > 0 ? $idx / $el : 0;
        $remain = $rate > 0 ? (count($pending) - $idx) / $rate : 0;
        printf("[%s] %d/%d (OK=%d NotFound=%d Err=%d) | %.2f/s | ETA=%dm\n",
            date('H:i:s'), $idx, count($pending), $ok, $notFound, $err, $rate, (int)($remain / 60));
    }
}
fclose($logFp);
$el = time() - $start;
echo "\nFinalizado en " . round($el / 60) . " min.\n";
echo "  OK        : $ok\n  NOT_FOUND : $notFound\n  ERROR     : $err\n";
