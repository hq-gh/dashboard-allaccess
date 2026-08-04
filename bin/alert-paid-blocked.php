<?php declare(strict_types=1);
/**
 * ALERTA: detecta suscriptores AL CORRIENTE (pago vigente) que siguen BLOQUEADOS
 * en PLAY. Se corre al final de cada reconcile del motor (permission-sync.php).
 * Post-reconcile, un "pagado pero suspendido" = el motor debió activarlo y no lo
 * hizo (dato viejo / bug) => se avisa a hq@ por correo para escalarlo de inmediato.
 * Nació del incidente yennis_2891 (pago tardío que el sync incremental se saltó, 3-ago-2026).
 * No aborta nunca la corrida; los fallos de la alerta solo se loguean.
 */
$root = dirname(__DIR__);
// Carga .env para runs locales/standalone; en prod (cron/passthru) el env ya viene de Railway.
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\"'");
    if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require $root . '/vendor/autoload.php';
use App\Config;
use App\Database;

try {
    $pdo = Database::get();

    $pids = array_column(
        $pdo->query("SELECT hotmart_product_id FROM hotmart_product_mapping WHERE product_key IN ('infinity','infinity_full') AND is_active = true")->fetchAll(\PDO::FETCH_ASSOC),
        'hotmart_product_id'
    );
    if (!$pids) { fwrite(STDERR, "[alert] sin pids infinity\n"); exit(0); }
    $pl = '{' . implode(',', $pids) . '}';

    // Personas AL CORRIENTE (misma lógica de vigencia del motor) que sigan suspendidas en PLAY.
    $sql = "
      WITH txr AS (SELECT subscription_id, recurrency_number,
                     bool_or(recurrency_status='PAID' OR purchase_status IN ('COMPLETE','APPROVED')) pagada,
                     MAX(recurrency_start_datetime) rstart
                   FROM subscription_transactions GROUP BY 1,2),
           ult AS (SELECT DISTINCT ON (subscription_id) subscription_id, pagada, rstart
                   FROM txr ORDER BY subscription_id, recurrency_number DESC),
           sub1 AS (SELECT DISTINCT ON (subscriber_code) subscription_id,
                     LOWER(TRIM(subscriber_email)) email, status, product_id
                   FROM subscriptions ORDER BY subscriber_code, synced_at DESC NULLS LAST),
           paid AS (SELECT DISTINCT s.email FROM sub1 s LEFT JOIN ult u ON u.subscription_id = s.subscription_id
                    WHERE s.product_id = ANY(:p::text[]) AND s.status IN ('ACTIVE','DELAYED') AND s.email <> ''
                      AND (u.subscription_id IS NULL OR u.pagada
                           OR (u.rstart IS NOT NULL AND (CURRENT_DATE - to_timestamp(u.rstart/1000.0)::date) <= 8)))
      SELECT ps.email, ps.nombre, to_char(ps.updated_at,'YYYY-MM-DD HH24:MI') suspendido_desde
        FROM tyris_play_state ps JOIN paid pe ON pe.email = ps.email
       WHERE ps.suspended = true
       ORDER BY ps.updated_at";
    $st = $pdo->prepare($sql);
    $st->execute([':p' => $pl]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

    $n = count($rows);
    if ($n === 0) { fwrite(STDOUT, "[alert] OK: 0 al-corriente-pero-bloqueados\n"); exit(0); }

    fwrite(STDOUT, "[alert] ⚠ $n al-corriente-pero-bloqueados -> avisando a hq@\n");

    $apiKey = (string) Config::get('MAILGUN_API_KEY', '');
    $domain = (string) Config::get('MAILGUN_DOMAIN', 'mail.5t4d10.com');
    $from   = (string) Config::get('MAILGUN_FROM', 'reportes@mail.5t4d10.com');
    $to     = (string) Config::get('ALERT_EMAIL', 'hq@5t4d10.com');
    if ($apiKey === '') { fwrite(STDERR, "[alert] sin MAILGUN_API_KEY -> no se envía correo (pero HAY casos)\n"); exit(0); }

    $lines = '';
    foreach ($rows as $r) $lines .= "- {$r['email']}  ({$r['nombre']})  suspendido desde {$r['suspendido_desde']}\n";
    $text = "ALERTA acceso 5T4D10\n\n$n suscriptor(es) AL CORRIENTE pero BLOQUEADOS en PLAY (el motor debió activarlos y no lo hizo):\n\n$lines\n"
          . "Revisar: sync de pagos (SUBTX_LOOKBACK_DAYS) + ultima corrida del motor. Reactivar y escalar si persiste.\n";
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1a1a1a"><p><b>ALERTA acceso 5T4D10</b></p>'
          . "<p><b>$n</b> suscriptor(es) <b>al corriente pero BLOQUEADOS en PLAY</b> (el motor debió activarlos y no lo hizo):</p><pre>"
          . htmlspecialchars($lines) . '</pre><p>Revisar sync de pagos + última corrida del motor. Reactivar y escalar si persiste.</p></div>';

    $post = ['from' => "Alertas 5T4D10 <$from>", 'to' => $to,
             'subject' => "[ALERTA] $n al corriente pero bloqueados en PLAY",
             'text' => $text, 'html' => $html, 'o:tag' => 'alerta-acceso'];
    $ch = curl_init("https://api.mailgun.net/v3/$domain/messages");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "api:$apiKey", CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    fwrite(STDOUT, "[alert] Mailgun HTTP $code\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "[alert] ERROR (no aborta): " . substr($e->getMessage(), 0, 200) . "\n");
}
exit(0);
