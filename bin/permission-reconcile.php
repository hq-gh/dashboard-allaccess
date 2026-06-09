<?php declare(strict_types=1);

/**
 * Concilia la última corrida del motor de permisos (user_program_validity) contra
 * las fuentes directas (subscriptions/sales) con la MISMA lógica del motor —incluida
 * la dependencia infinity_vip -> infinity vigente—. Corrido inmediatamente DESPUÉS
 * de un sync, el diff por programa debe ser 0 (motor y fuente miden lo mismo, al
 * mismo instante). Guarda el resultado en permission_reconcile_runs para poder
 * leerlo sin depender de logs.
 *
 * Uso: php bin/permission-reconcile.php
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

$pdo = Database::get();
$now = (int) (microtime(true) * 1000);

$pdo->exec("CREATE TABLE IF NOT EXISTS permission_reconcile_runs (
    id          BIGSERIAL PRIMARY KEY,
    sync_run_id BIGINT,
    computed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    total_diff  INT NOT NULL,
    detail      JSONB NOT NULL
)");

// Último run CON datos de validez (evita runs zombie 'running' que murieron sin computar).
$runId = (int) $pdo->query("SELECT COALESCE(MAX(run_id),0) FROM user_program_validity")->fetchColumn();

// Vigentes por programa según la FUENTE, con la misma lógica del motor.
$subIn = "'6454766','7065704','6952229'";
$vipIn = "'6587403','7005612','7005981'";
$activeStatuses = "'ACTIVE','DELAYED','STARTED','OVERDUE'";

// infinity (suscripción)
$srcInfinity = (int) $pdo->query("SELECT COUNT(DISTINCT LOWER(TRIM(subscriber_email))) FROM subscriptions
    WHERE product_id IN ($subIn) AND status IN ($activeStatuses) AND subscriber_email <> ''")->fetchColumn();
// infinity_vip (suscripción) PERO solo quienes ADEMÁS tienen infinity vigente (dependencia del motor)
$srcVip = (int) $pdo->query("SELECT COUNT(*) FROM (
        SELECT DISTINCT LOWER(TRIM(subscriber_email)) e FROM subscriptions
         WHERE product_id IN ($vipIn) AND status IN ($activeStatuses) AND subscriber_email <> ''
        INTERSECT
        SELECT DISTINCT LOWER(TRIM(subscriber_email)) e FROM subscriptions
         WHERE product_id IN ($subIn) AND status IN ($activeStatuses) AND subscriber_email <> ''
    ) t")->fetchColumn();
// fixed_days
$fixed = function (string $pid, int $days) use ($pdo, $now): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM (
        SELECT LOWER(TRIM(buyer_email)) e FROM sales s
         WHERE product_id = '$pid' AND status IN ('COMPLETE','APPROVED') AND buyer_email <> ''
           AND NOT EXISTS (SELECT 1 FROM sales r WHERE r.transaction_id = s.transaction_id AND r.status IN ('REFUNDED','CHARGEBACK'))
         GROUP BY 1 HAVING MAX(approved_date) + " . ($days * 86400000) . " > $now) t")->fetchColumn();
};
$source = [
    'infinity'       => $srcInfinity,
    'infinity_vip'   => $srcVip,
    'mommy_comeback' => $fixed('7455277', 80),
    'xtreme_burn'    => $fixed('7815025', 24),
];

// Vigentes por programa según el MOTOR (última corrida)
$engine = [];
foreach (array_keys($source) as $pk) {
    $engine[$pk] = (int) $pdo->query("SELECT COUNT(DISTINCT email) FROM user_program_validity
        WHERE run_id = $runId AND product_key = '$pk' AND is_valid IS TRUE")->fetchColumn();
}

$detail = []; $totalDiff = 0;
echo "=== Conciliación motor vs fuente (sync_run=$runId) ===\n";
printf("  %-15s %8s %8s %6s\n", 'programa', 'fuente', 'motor', 'diff');
foreach ($source as $pk => $src) {
    $eng = $engine[$pk]; $diff = $src - $eng; $totalDiff += abs($diff);
    $detail[$pk] = ['source' => $src, 'engine' => $eng, 'diff' => $diff];
    printf("  %-15s %8d %8d %6d %s\n", $pk, $src, $eng, $diff, $diff === 0 ? 'OK' : '<--');
}
echo "  ----\n  TOTAL diff absoluto: $totalDiff " . ($totalDiff === 0 ? '(PERFECTO, cuadra)' : '(revisar / drift por ventas entre sync y reconcile)') . "\n";

$ins = $pdo->prepare("INSERT INTO permission_reconcile_runs (sync_run_id, total_diff, detail) VALUES (:r, :t, :d)");
$ins->execute([':r' => $runId, ':t' => $totalDiff, ':d' => json_encode($detail, JSON_UNESCAPED_UNICODE)]);
echo "Guardado en permission_reconcile_runs.\n";
