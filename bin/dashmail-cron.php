<?php declare(strict_types=1);
/**
 * Cron del Dashboard de Correos (dashmail.5t4d10.com).
 * Ingesta eventos de Mailgun -> Neon (dashmail_events) y publica agregados por
 * campaña a R2 (cdn.5t4d10.com/dashmail/campaign_stats.json).
 * La lógica vive en App\Dashmail\Sync (reutilizada por la ruta HTTP /internal/dashmail-sync).
 * Corre 2x/dia en el servicio 5t4d10_DASHMAIL_CRON (Nixpacks con pgsql).
 * Env: MAILGUN_API_KEY, MAILGUN_DOMAIN, R2_*, DB_* .
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
load_env(__DIR__ . '/../.env');
require __DIR__ . '/../vendor/autoload.php';

$r = App\Dashmail\Sync::run();
$i = $r['ingest']; $p = $r['publish'];
echo "ingest: paginas={$i['pages']} vistos={$i['seen']} nuevos={$i['inserted']} cursor={$i['cursor']}\n";
echo "publish: {$p['campaigns']} campañas -> {$p['url']}\n";
