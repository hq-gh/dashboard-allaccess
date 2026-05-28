<?php declare(strict_types=1);

namespace App\Webhook;

use App\Bettermode\BettermodeClient;
use App\Config;
use App\Repositories\ProductMappingRepo;
use App\Repositories\SpacesRepo;
use App\Repositories\WebhookEventsRepo;

/**
 * Recibe webhooks de Hotmart y aplica grant/revoke en Bettermode.
 *
 * Flujo:
 *  1. Lee body JSON crudo.
 *  2. Valida hottok (env HOTMART_WEBHOOK_HOTTOK).
 *  3. Extrae event_type, status, email, transaction_id, product_id, name.
 *  4. Si product_id no esta en hotmart_product_mapping (o inactivo) -> ignora.
 *  5. event_type + status determinan accion:
 *     - PURCHASE_APPROVED|COMPLETE + status valido -> grant
 *     - PURCHASE_REFUNDED|CHARGEBACK|PROTEST       -> revoke
 *     - SUBSCRIPTION_CANCELLATION                  -> log informativo (no revoke; lo maneja el cron diario)
 *     - otros                                       -> ignored
 *  6. Para grant: busca miembro por email; si no existe lo crea con joinNetwork.
 *     Verifica email. Set field "Infinity"=true. Add a TODOS los spaces del product_key.
 *  7. Para revoke: busca miembro; si existe, remove de TODOS los spaces del product_key.
 *  8. Persiste resultado en infinity_webhook_events con dedup_key UNIQUE para idempotencia.
 *  9. Responde JSON {ok, message, ...}.
 */
final class HotmartHandler
{
    private const VALID_STATUSES = ['APPROVED', 'COMPLETE'];
    private const GRANT_EVENTS   = ['PURCHASE_APPROVED', 'PURCHASE_COMPLETE'];
    private const REVOKE_EVENTS  = ['PURCHASE_REFUNDED', 'PURCHASE_CHARGEBACK', 'PURCHASE_PROTEST'];
    private const LOG_ONLY_EVENTS = ['SUBSCRIPTION_CANCELLATION'];

    private const TEMP_PASSWORD = 'Password!54321'; // paridad con el Apps Script existente

    public function __construct(
        private ProductMappingRepo $mappingRepo,
        private SpacesRepo $spacesRepo,
        private WebhookEventsRepo $eventsRepo,
        private ?BettermodeClient $bettermode = null
    ) {}

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        // 1) leer body crudo
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->respond(400, ['ok' => false, 'message' => 'Body JSON invalido']);
            return;
        }

        // 2) validar hottok
        $expectedHottok = (string) (Config::get('HOTMART_WEBHOOK_HOTTOK') ?? '');
        if ($expectedHottok === '') {
            // Endpoint deshabilitado: la var no esta seteada en el entorno todavia.
            $this->respond(503, ['ok' => false, 'message' => 'Endpoint no configurado (HOTMART_WEBHOOK_HOTTOK vacio)']);
            return;
        }
        $receivedHottok = $this->extractHottok($payload);
        if ($receivedHottok === null || !hash_equals($expectedHottok, $receivedHottok)) {
            $this->eventsRepo->insert([
                'event_type'   => (string) ($payload['event'] ?? 'UNKNOWN'),
                'action_taken' => 'failed',
                'status'       => 'invalid',
                'message'      => 'Hottok invalido o ausente',
                'payload_json' => $payload,
            ]);
            $this->respond(401, ['ok' => false, 'message' => 'Hottok invalido']);
            return;
        }

        // 3) extraer datos
        $event = $this->extractEventData($payload);

        // 4) mapear product_id
        $mapping = $event['hotmart_product_id'] !== ''
            ? $this->mappingRepo->findByHotmartProductId($event['hotmart_product_id'])
            : null;
        if ($mapping === null) {
            $this->eventsRepo->insert([
                'event_type'         => $event['event_type'],
                'hotmart_product_id' => $event['hotmart_product_id'] ?: null,
                'email'              => $event['email'] ?: null,
                'transaction_id'     => $event['transaction_id'] ?: null,
                'action_taken'       => 'ignored',
                'status'             => 'ignored',
                'message'            => 'product_id no mapeado o inactivo',
                'payload_json'       => $payload,
                'dedup_key'          => $this->dedupKey($event, 'ignored'),
            ]);
            $this->respond(200, ['ok' => true, 'message' => 'Evento ignorado: product no mapeado']);
            return;
        }
        $productKey = (string) $mapping['product_key'];

        // 5) determinar accion
        $action = $this->decideAction($event);
        if ($action === 'ignored') {
            $this->eventsRepo->insert([
                'event_type'         => $event['event_type'],
                'hotmart_product_id' => $event['hotmart_product_id'] ?: null,
                'product_key'        => $productKey,
                'email'              => $event['email'] ?: null,
                'transaction_id'     => $event['transaction_id'] ?: null,
                'action_taken'       => 'ignored',
                'status'             => 'ignored',
                'message'            => 'Evento no requiere accion (status='. $event['status'] .', event='. $event['event_type'] .')',
                'payload_json'       => $payload,
                'dedup_key'          => $this->dedupKey($event, 'ignored'),
            ]);
            $this->respond(200, ['ok' => true, 'message' => 'Evento ignorado']);
            return;
        }
        if ($action === 'log_only') {
            $this->eventsRepo->insert([
                'event_type'         => $event['event_type'],
                'hotmart_product_id' => $event['hotmart_product_id'] ?: null,
                'product_key'        => $productKey,
                'email'              => $event['email'] ?: null,
                'transaction_id'     => $event['transaction_id'] ?: null,
                'action_taken'       => 'ignored',
                'status'             => 'ignored',
                'message'            => 'Cancelacion registrada: el revoke lo maneja el cron diario al expirar el periodo pagado',
                'payload_json'       => $payload,
                'dedup_key'          => $this->dedupKey($event, 'log_only'),
            ]);
            $this->respond(200, ['ok' => true, 'message' => 'Cancelacion registrada (sin revoke inmediato)']);
            return;
        }

        if ($event['email'] === '') {
            $this->eventsRepo->insert([
                'event_type'         => $event['event_type'],
                'hotmart_product_id' => $event['hotmart_product_id'] ?: null,
                'product_key'        => $productKey,
                'transaction_id'     => $event['transaction_id'] ?: null,
                'action_taken'       => 'failed',
                'status'             => 'failed',
                'message'            => 'Payload sin email',
                'payload_json'       => $payload,
                'dedup_key'          => $this->dedupKey($event, 'no_email'),
            ]);
            $this->respond(200, ['ok' => false, 'message' => 'Payload sin email']);
            return;
        }

        // 6/7) ejecutar accion
        if ($action === 'grant') {
            $result = $this->doGrant($productKey, $event);
        } else { // revoke
            $result = $this->doRevoke($productKey, $event);
        }

        $this->eventsRepo->insert([
            'event_type'         => $event['event_type'],
            'hotmart_product_id' => $event['hotmart_product_id'] ?: null,
            'product_key'        => $productKey,
            'email'              => $event['email'],
            'member_id'          => $result['member_id'] ?? null,
            'transaction_id'     => $event['transaction_id'] ?: null,
            'action_taken'       => $action,
            'spaces_ok'          => $result['spaces_ok'] ?? 0,
            'spaces_failed'      => $result['spaces_failed'] ?? 0,
            'status'             => $result['status'],
            'message'            => $result['message'],
            'payload_json'       => $payload,
            'dedup_key'          => $this->dedupKey($event, $action),
        ]);

        $this->respond($result['status'] === 'failed' ? 500 : 200, [
            'ok'        => $result['status'] !== 'failed',
            'message'   => $result['message'],
            'member_id' => $result['member_id'] ?? null,
            'spaces'    => ['ok' => $result['spaces_ok'] ?? 0, 'failed' => $result['spaces_failed'] ?? 0],
        ]);
    }

    /**
     * @return array{member_id:?string, spaces_ok:int, spaces_failed:int, status:string, message:string}
     */
    private function doGrant(string $productKey, array $event): array
    {
        $spaces = $this->spacesRepo->listActiveForProductKey($productKey);
        if (empty($spaces)) {
            return ['member_id' => null, 'spaces_ok' => 0, 'spaces_failed' => 0, 'status' => 'failed', 'message' => 'No hay spaces activos para product_key=' . $productKey];
        }

        $bm = $this->getBettermode();

        // 1) miembro existente o crear
        $memberId = null;
        $created  = false;
        try {
            $found = $bm->findMemberByEmail($event['email']);
            if ($found !== null) {
                $memberId = $found['id'];
            } else {
                $name = $event['name'] !== '' ? $event['name'] : strstr($event['email'], '@', true);
                $username = $this->generateUsername($name ?: $event['email'], $event['email']);
                $newMember = $bm->createMember($event['email'], $name ?: $event['email'], self::TEMP_PASSWORD, $username);
                $memberId = $newMember['id'];
                $created  = true;
            }
        } catch (\Throwable $e) {
            return ['member_id' => null, 'spaces_ok' => 0, 'spaces_failed' => 0, 'status' => 'failed', 'message' => 'Error find/create member: ' . $e->getMessage()];
        }

        // 2) verificar email + set field Infinity = true (best effort, no aborta el grant)
        try { $bm->verifyMember($memberId); }
        catch (\Throwable $e) { /* puede estar ya verificado o fallar; no bloquea */ }
        try { $bm->updateMemberField($memberId, 'Infinity', 'true'); }
        catch (\Throwable $e) { /* idem; no bloquea */ }

        // 3) agregar a todos los spaces
        $ok = 0; $failed = 0; $fails = [];
        foreach ($spaces as $sp) {
            try {
                $bm->grantSpaceAccess($memberId, (string) $sp['space_id']);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $fails[] = $sp['space_name'] . ': ' . $e->getMessage();
            }
        }

        $total  = count($spaces);
        $status = $failed === 0 ? 'success' : ($ok > 0 ? 'partial' : 'failed');
        $msg    = ($created ? '[NUEVA CUENTA] ' : '[EXISTENTE] ') . $event['email']
                . ' grant ' . $ok . '/' . $total . ' spaces (' . $productKey . ')';
        if ($failed > 0) $msg .= ' | fallos: ' . implode(' | ', array_slice($fails, 0, 3));

        return ['member_id' => $memberId, 'spaces_ok' => $ok, 'spaces_failed' => $failed, 'status' => $status, 'message' => $msg];
    }

    /**
     * @return array{member_id:?string, spaces_ok:int, spaces_failed:int, status:string, message:string}
     */
    private function doRevoke(string $productKey, array $event): array
    {
        $spaces = $this->spacesRepo->listActiveForProductKey($productKey);
        if (empty($spaces)) {
            return ['member_id' => null, 'spaces_ok' => 0, 'spaces_failed' => 0, 'status' => 'failed', 'message' => 'No hay spaces activos para product_key=' . $productKey];
        }
        $bm = $this->getBettermode();

        try {
            $found = $bm->findMemberByEmail($event['email']);
        } catch (\Throwable $e) {
            return ['member_id' => null, 'spaces_ok' => 0, 'spaces_failed' => 0, 'status' => 'failed', 'message' => 'Error buscando miembro: ' . $e->getMessage()];
        }
        if ($found === null) {
            return ['member_id' => null, 'spaces_ok' => 0, 'spaces_failed' => 0, 'status' => 'ignored', 'message' => 'Email no encontrado en Bettermode: ' . $event['email']];
        }
        $memberId = $found['id'];

        $ok = 0; $failed = 0; $fails = [];
        foreach ($spaces as $sp) {
            try {
                $bm->revokeSpaceAccess($memberId, (string) $sp['space_id']);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $fails[] = $sp['space_name'] . ': ' . $e->getMessage();
            }
        }
        $total  = count($spaces);
        $status = $failed === 0 ? 'success' : ($ok > 0 ? 'partial' : 'failed');
        $msg    = $event['email'] . ' revoke ' . $ok . '/' . $total . ' spaces (' . $productKey . ')';
        if ($failed > 0) $msg .= ' | fallos: ' . implode(' | ', array_slice($fails, 0, 3));

        return ['member_id' => $memberId, 'spaces_ok' => $ok, 'spaces_failed' => $failed, 'status' => $status, 'message' => $msg];
    }

    private function getBettermode(): BettermodeClient
    {
        if ($this->bettermode === null) {
            $this->bettermode = new BettermodeClient(function (string $level, string $event, array $ctx): void {
                error_log('[webhook/bettermode] ' . $level . ' ' . $event . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE));
            });
        }
        return $this->bettermode;
    }

    /**
     * @return array{event_type:string, status:string, email:string, name:string, transaction_id:string, hotmart_product_id:string}
     */
    private function extractEventData(array $payload): array
    {
        $event = strtoupper((string) ($payload['event'] ?? $payload['tipo'] ?? ''));
        $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $purchase   = is_array($data['purchase']   ?? null) ? $data['purchase']   : [];
        $buyer      = is_array($data['buyer']      ?? null) ? $data['buyer']      : [];
        $subscriber = is_array($data['subscriber'] ?? null) ? $data['subscriber'] : [];
        $product    = is_array($data['product']    ?? null) ? $data['product']    : [];

        $status        = strtoupper((string) ($purchase['status'] ?? $data['status'] ?? $payload['status'] ?? $payload['purchase_status'] ?? ''));
        $transactionId = (string) ($purchase['transaction'] ?? $data['transaction'] ?? $payload['transaction'] ?? '');
        $email         = strtolower(trim((string) ($buyer['email'] ?? $subscriber['email'] ?? $payload['buyer_email'] ?? $payload['email'] ?? '')));
        $name          = trim((string) ($buyer['name'] ?? $subscriber['name'] ?? $payload['buyer_name'] ?? $payload['name'] ?? ''));
        $hpid          = (string) ($product['id'] ?? $data['product_id'] ?? $payload['product_id'] ?? '');

        return [
            'event_type'         => $event,
            'status'             => $status,
            'email'              => $email,
            'name'               => $name,
            'transaction_id'     => $transactionId,
            'hotmart_product_id' => $hpid,
        ];
    }

    private function decideAction(array $event): string
    {
        if (in_array($event['event_type'], self::GRANT_EVENTS, true)
            && ($event['status'] === '' || in_array($event['status'], self::VALID_STATUSES, true))) {
            return 'grant';
        }
        if (in_array($event['event_type'], self::REVOKE_EVENTS, true)) {
            return 'revoke';
        }
        if (in_array($event['event_type'], self::LOG_ONLY_EVENTS, true)) {
            return 'log_only';
        }
        return 'ignored';
    }

    private function extractHottok(array $payload): ?string
    {
        // En v2 viene como header X-Hottok o en el body como hottok / data.hottok.
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, 'X-Hotmart-Hottok') === 0) return (string) $v;
            if (strcasecmp((string) $k, 'X-Hottok')         === 0) return (string) $v;
        }
        if (!empty($payload['hottok'])) return (string) $payload['hottok'];
        if (isset($payload['data']['hottok'])) return (string) $payload['data']['hottok'];
        return null;
    }

    private function dedupKey(array $event, string $suffix): string
    {
        $tx = $event['transaction_id'] !== '' ? $event['transaction_id'] : ($event['email'] ?? '');
        return $event['event_type'] . '|' . $tx . '|' . $suffix;
    }

    private function generateUsername(string $name, string $email): string
    {
        $base = strtolower($name);
        $base = strtr($base, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n']);
        $base = preg_replace('/[^a-z0-9]/', '', $base) ?? '';
        $base = substr($base, 0, 12);
        if (strlen($base) < 3) {
            $local = strstr($email, '@', true) ?: $email;
            $base = substr(preg_replace('/[^a-z0-9]/', '', strtolower($local)) ?? '', 0, 12);
        }
        return $base . (string) random_int(1000, 9999);
    }

    private function respond(int $code, array $body): void
    {
        http_response_code($code);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
