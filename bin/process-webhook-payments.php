<?php declare(strict_types=1);
/**
 * PROCESADOR de eventos de pago del webhook de Hotmart (reactivacion instantanea).
 * Lee hotmart_webhook_events sin procesar (PURCHASE_APPROVED / PURCHASE_COMPLETE),
 * y para pagos de Infinity: (1) marca el pago en subscription_transactions al
 * instante (durabilidad: el motor lo ve pagado y no lo re-bloquea), (2) reactiva
 * dirigido -> Diez (regrant de sus espacios) + PLAY (activar). Marca processed_at.
 *
 * Corre off-web (cron cada pocos min) para NO bloquear el dashboard (web de 1 worker).
 * Nace del incidente yennis_2891 (pago tardio que el sync incremental se saltaba, 3-ago-2026).
 *
 * Uso:  php bin/process-webhook-payments.php [--dry]
 *   --dry : calcula y reporta, NO toca subscription_transactions ni Bettermode/PLAY.
 */
$root = dirname(__DIR__);
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\"'");
    if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require $root . '/vendor/autoload.php';

use App\Database;
use App\Bettermode\BettermodeClient;
use App\Tyris\PlayClient;

$DRY = in_array('--dry', $argv, true);
$log = fn(string $m) => fwrite(STDOUT, $m . "\n");
$log('[webhook-proc] inicio ' . gmdate('c') . ($DRY ? ' (DRY)' : ''));

try {
    $pdo = Database::get();

    // Product ids que confieren acceso Infinity (Diez + PLAY).
    $infPids = array_map('strval', array_column(
        $pdo->query("SELECT hotmart_product_id FROM hotmart_product_mapping WHERE product_key IN ('infinity','infinity_full') AND is_active = true")->fetchAll(\PDO::FETCH_ASSOC),
        'hotmart_product_id'
    ));

    // Eventos de pago sin procesar.
    $st = $pdo->prepare("
        SELECT id, event_type, payload FROM hotmart_webhook_events
         WHERE status = 'received' AND processed_at IS NULL
           AND event_type IN ('PURCHASE_APPROVED','PURCHASE_COMPLETE')
         ORDER BY id ASC LIMIT 200");
    $st->execute();
    $events = $st->fetchAll(\PDO::FETCH_ASSOC);
    $log('[webhook-proc] eventos de pago sin procesar: ' . count($events));
    if (!$events) { $log('[webhook-proc] nada que hacer'); exit(0); }

    $bm = $DRY ? null : new BettermodeClient(fn($l, $e, $c) => null);
    $markProcessed = $pdo->prepare("UPDATE hotmart_webhook_events SET status = :s, processed_at = NOW(), error_message = :e WHERE id = :id");
    $toActivatePlay = []; // email => nombre (batch PLAY al final)

    foreach ($events as $ev) {
        $p = json_decode($ev['payload'], true);
        $d = $p['data'] ?? [];
        $email = strtolower(trim((string) ($d['buyer']['email'] ?? '')));
        $pid   = (string) ($d['product']['id'] ?? '');
        $subcode = (string) ($d['subscription']['subscriber']['code'] ?? '');
        $recn  = (int) ($d['purchase']['recurrence_number'] ?? $d['purchase']['recurrency_number'] ?? 0);
        $tx    = (string) ($d['purchase']['transaction'] ?? '');
        $status = 'processed'; $note = '';

        if ($email === '' || $pid === '') { $note = 'sin email/product'; }
        elseif (!in_array($pid, $infPids, true)) { $note = "product $pid no es Infinity -> ignorado"; }
        else {
            $log("[webhook-proc] evento {$ev['id']}: PAGO Infinity de $email (sub $subcode rec $recn tx $tx)");
            // 1) Durabilidad: marcar la recurrencia como PAGADA en subscription_transactions.
            if ($subcode !== '') {
                $sid = $pdo->prepare("SELECT subscription_id FROM subscriptions WHERE subscriber_code = ? LIMIT 1");
                $sid->execute([$subcode]); $subscriptionId = $sid->fetchColumn();
                if ($subscriptionId && $recn > 0) {
                    if ($DRY) { $log("   [dry] marcaria sub_transactions sub=$subscriptionId rec=$recn -> PAID/APPROVED"); }
                    else {
                        $upd = $pdo->prepare("UPDATE subscription_transactions SET recurrency_status='PAID', purchase_status='APPROVED' WHERE subscription_id = ? AND recurrency_number = ?");
                        $upd->execute([$subscriptionId, $recn]);
                        $log("   sub_transactions sub=$subscriptionId rec=$recn -> PAID/APPROVED (filas {$upd->rowCount()})");
                    }
                } else { $note .= " (sub/rec no mapeada en BD; el sync la traera)"; }
            }
            // 2) Diez INMEDIATO: regrant de sus espacios historicos (espejo).
            $sp = $pdo->prepare("SELECT space_id FROM bettermode_member_spaces WHERE lower(email) = ?");
            $sp->execute([$email]); $spaceIds = $sp->fetchAll(\PDO::FETCH_COLUMN);
            if ($DRY) { $log("   [dry] Diez: regrandearia " . count($spaceIds) . " espacios a $email"); }
            elseif ($spaceIds) {
                $m = $bm->findMemberByEmail($email);
                if ($m && !empty($m['id'])) {
                    $ok = 0; foreach ($spaceIds as $s) { try { $bm->grantSpaceAccess((string) $m['id'], (string) $s); $ok++; } catch (\Throwable $e) {} usleep(300000); }
                    $log("   Diez: $ok/" . count($spaceIds) . " espacios regrandeados");
                } else { $note .= ' (sin cuenta Bettermode)'; }
            }
            // 3) PLAY: acumular para activar en batch.
            $nombre = (string) ($pdo->query("SELECT nombre FROM tyris_play_state WHERE lower(email)=" . $pdo->quote($email) . " LIMIT 1")->fetchColumn() ?: ($d['buyer']['name'] ?? ''));
            $toActivatePlay[$email] = $nombre;
        }

        if (!$DRY) $markProcessed->execute([':s' => 'processed', ':e' => $note ?: null, ':id' => $ev['id']]);
        $log("   -> evento {$ev['id']} procesado" . ($note ? " ($note)" : ''));
    }

    // PLAY en batch (una sola llamada al CMS).
    if ($toActivatePlay) {
        if ($DRY) { $log('[webhook-proc] [dry] PLAY: activaria ' . count($toActivatePlay) . ' -> ' . implode(', ', array_keys($toActivatePlay))); }
        else {
            try {
                $rows = [];
                foreach ($toActivatePlay as $em => $nom) $rows[] = [trim((string) $nom), $em, 'ACTIVAR'];
                $res = (new PlayClient())->bulkStatusCsv(PlayClient::buildCsv($rows));
                if (!empty($res['ok'])) {
                    $emails = array_keys($toActivatePlay);
                    $in = implode(',', array_fill(0, count($emails), '?'));
                    $pdo->prepare("UPDATE tyris_play_state SET suspended=false, updated_at=NOW() WHERE lower(email) IN ($in)")->execute($emails);
                    $log('[webhook-proc] PLAY activados=' . ($res['activated'] ?? 0) . ' notFound=' . count($res['notFound'] ?? []));
                } else { $log('[webhook-proc] PLAY FALLO: ' . ($res['error'] ?? '?')); }
            } catch (\Throwable $e) { $log('[webhook-proc] PLAY ERROR: ' . substr($e->getMessage(), 0, 160)); }
        }
    }
    $log('[webhook-proc] fin');
} catch (\Throwable $e) {
    fwrite(STDERR, '[webhook-proc] ERROR: ' . substr($e->getMessage(), 0, 300) . "\n");
    exit(1);
}
exit(0);
