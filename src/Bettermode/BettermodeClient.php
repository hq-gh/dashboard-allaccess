<?php declare(strict_types=1);

namespace App\Bettermode;

use App\Config;

/**
 * Cliente GraphQL para Bettermode. Portado del repo 5T4D10_InfinityVIP_Verificador
 * adaptado al portal: usa cURL nativo (sin Guzzle) y opera sobre cualquier spaceId
 * pasado por parametro (no hay vipSpaceId hardcoded).
 *
 * Schemas confirmados vía introspección (2026-05):
 *  - members(query, limit) -> nodes { id email name }
 *  - joinNetwork(input: { email, name, password, username }) -> accessToken, member { id email name }
 *  - verifyMember(input: { memberId }) -> accessToken
 *  - updateMember(id, input: { fields: { key, value } }) -> id
 *  - addSpaceMembers(spaceId, input: [{ memberId }]) -> [SpaceMember]
 *  - removeSpaceMembers(spaceId, memberIds) -> [Action]
 *
 * Auth: 2 pasos (guest token -> admin token via loginNetwork). Refresh-on-401/403.
 * Throttle: sleep configurable entre llamadas (default 300ms).
 */
final class BettermodeClient
{
    /** @var callable|null logger(string $level, string $event, array $context): void */
    private $logger;

    private string $apiUrl;
    private string $networkDomain;
    private string $adminEmail;
    private string $adminPassword;
    private int    $sleepMs;
    private int    $maxRetries;
    private int    $timeout;

    private ?string $adminToken = null;
    private int $lastCallAtMs = 0;

    public function __construct(?callable $logger = null)
    {
        $this->logger        = $logger;
        $this->apiUrl        = rtrim((string) Config::get('BETTERMODE_API_URL', 'https://api.bettermode.com/'), '/') . '/';
        $this->networkDomain = (string) Config::require('BETTERMODE_NETWORK_DOMAIN');
        $this->adminEmail    = (string) Config::require('BETTERMODE_ADMIN_EMAIL');
        $this->adminPassword = (string) Config::require('BETTERMODE_ADMIN_PASSWORD');
        $this->sleepMs       = (int) Config::getInt('BETTERMODE_SLEEP_MS_BETWEEN_CALLS', 300);
        $this->maxRetries    = (int) Config::getInt('BETTERMODE_MAX_RETRIES_PER_MEMBER', 2);
        $this->timeout       = (int) Config::getInt('BETTERMODE_HTTP_TIMEOUT_SEC', 60);
    }

    private function log(string $level, string $event, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $event, $context);
        }
    }

    /**
     * Throttle + cURL POST + parse + GraphQL error inspection.
     *
     * @throws \RuntimeException con $code = HTTP status (o status interno de error GraphQL si aplica).
     */
    private function gql(string $query, ?string $token, ?array $variables = null): array
    {
        // Reintento ante rate limit ("You've made too many requests"): backoff
        // exponencial 2s,4s,8s,16s,32s. Cubre TODAS las llamadas (create/verify/grant)
        // en webhook, cron y scripts, sin duplicar lógica por método.
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->gqlExec($query, $token, $variables);
            } catch (\Throwable $e) {
                if ($attempt < 5 && $this->isRateLimit($e)) { sleep(2 << $attempt); continue; }
                throw $e;
            }
        }
    }

    private function isRateLimit(\Throwable $e): bool
    {
        if ($e->getCode() === 429) return true;
        $m = $e->getMessage();
        return stripos($m, 'too many requests') !== false
            || stripos($m, 'rate limit') !== false
            || strpos($m, '"status":429') !== false;
    }

    private function gqlExec(string $query, ?string $token, ?array $variables = null): array
    {
        // throttle
        $nowMs = (int) floor(microtime(true) * 1000);
        if ($this->lastCallAtMs > 0) {
            $diffMs = $nowMs - $this->lastCallAtMs;
            if ($diffMs < $this->sleepMs) {
                usleep(($this->sleepMs - $diffMs) * 1000);
            }
        }
        $this->lastCallAtMs = (int) floor(microtime(true) * 1000);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $payload = ['query' => $query];
        if ($variables !== null) {
            $payload['variables'] = $variables;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $raw    = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $raw === false) {
            throw new \RuntimeException("Bettermode: cURL error #{$errNo}: {$errMsg}");
        }
        if ($status >= 400) {
            throw new \RuntimeException("Bettermode: HTTP {$status}: " . substr((string) $raw, 0, 500), $status);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Bettermode: respuesta no es JSON valido (status={$status})");
        }
        if (isset($decoded['errors']) && is_array($decoded['errors']) && count($decoded['errors']) > 0) {
            $first = $decoded['errors'][0] ?? null;
            $innerStatus = 0;
            if (is_array($first)) {
                if (isset($first['status']) && is_numeric($first['status'])) {
                    $innerStatus = (int) $first['status'];
                } elseif (isset($first['extensions']['status']) && is_numeric($first['extensions']['status'])) {
                    $innerStatus = (int) $first['extensions']['status'];
                }
            }
            throw new \RuntimeException(
                'Bettermode: GraphQL errors: ' . json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE),
                $innerStatus
            );
        }
        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new \RuntimeException('Bettermode: respuesta sin clave "data"');
        }
        return $decoded['data'];
    }

    private function isAuthError(\Throwable $e): bool
    {
        $code = $e->getCode();
        if ($code === 401 || $code === 403) return true;
        $msg = $e->getMessage();
        if (stripos($msg, 'Forbidden resource') !== false) return true;
        if (strpos($msg, '"code":"102"') !== false || strpos($msg, '"status":403') !== false || strpos($msg, '"status":401') !== false) return true;
        return false;
    }

    /**
     * gql con refresh-on-auth-error (un retry tras invalidar admin token).
     */
    private function gqlWithAuth(string $query, ?array $variables = null): array
    {
        $token = $this->ensureAdminToken();
        try {
            return $this->gql($query, $token, $variables);
        } catch (\RuntimeException $e) {
            if (!$this->isAuthError($e)) throw $e;
            $this->log('warn', 'bettermode.auth.token_expired_retry', ['error' => $e->getMessage()]);
            $this->adminToken = null;
            $fresh = $this->ensureAdminToken();
            return $this->gql($query, $fresh, $variables);
        }
    }

    public function ensureAdminToken(): string
    {
        if ($this->adminToken !== null && $this->adminToken !== '') {
            return $this->adminToken;
        }
        // 1) guest token
        $domainLit = json_encode($this->networkDomain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $guestQ    = 'query { tokens(networkDomain: ' . $domainLit . ') { accessToken } }';
        $g         = $this->gql($guestQ, null);
        $guestTok  = $g['tokens']['accessToken'] ?? null;
        if (!is_string($guestTok) || $guestTok === '') {
            throw new \RuntimeException('Bettermode: no se obtuvo guest token');
        }
        // 2) loginNetwork
        $emailLit = json_encode($this->adminEmail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $passLit  = json_encode($this->adminPassword, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $loginMut = 'mutation { loginNetwork(input: { usernameOrEmail: ' . $emailLit . ', password: ' . $passLit . ' }) { accessToken } }';
        $l        = $this->gql($loginMut, $guestTok);
        $adminTok = $l['loginNetwork']['accessToken'] ?? null;
        if (!is_string($adminTok) || $adminTok === '') {
            throw new \RuntimeException('Bettermode: loginNetwork no devolvio accessToken');
        }
        $this->adminToken = $adminTok;
        $this->log('info', 'bettermode.auth.ok', ['network_domain' => $this->networkDomain]);
        return $adminTok;
    }

    /**
     * Busca un miembro por email en 3 pasadas (paridad con el script Apps Script
     * original): normal, status=SUSPENDED, status=BLOCKED. Bettermode oculta
     * miembros suspendidos/bloqueados de la query por defecto; sin esto, un
     * alumno previamente bloqueado que vuelve a comprar no se encuentra y se
     * intenta crear un duplicado (joinNetwork falla por email duplicado).
     *
     * @return array{id:string, email:string, name:?string}|null
     */
    /**
     * Ejecuta un query/mutation GraphQL arbitrario con auth admin (retry-on-auth).
     * Pensado para syncs/reportes que necesitan campos no cubiertos por los
     * métodos específicos. @return array El nodo `data` de la respuesta.
     */
    public function query(string $gql, array $variables = []): array
    {
        // Sin variables: omitir el arg (un `variables: []` se serializa como [] y
        // Bettermode exige objeto). Con variables: pasarlas.
        return $variables === [] ? $this->gqlWithAuth($gql) : $this->gqlWithAuth($gql, $variables);
    }

    public function findMemberByEmail(string $email): ?array
    {
        $needle = mb_strtolower(trim($email));

        // Primario: filtro EXACTO por email. Encuentra al miembro en cualquier
        // estado (incl. UNVERIFIED), a diferencia de la búsqueda free-text, que
        // es difusa y omite cuentas sin verificar. El value debe ser un json
        // string (operator enum: equals). Doble json_encode -> "\"correo\"".
        $valueLit = json_encode(json_encode($email, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), JSON_UNESCAPED_UNICODE);
        $byEmail  = 'query { members(limit: 10, filterBy: [{ key: "email", operator: equals, value: ' . $valueLit . ' }]) { nodes { id email name status } } }';
        try {
            $hit = $this->matchEmailNode($this->gqlWithAuth($byEmail)['members']['nodes'] ?? null, $needle);
            if ($hit !== null) return $hit;
        } catch (\Throwable $_) { /* cae al siguiente intento */ }

        // 2ª pasada: status UNVERIFIED. El scope por defecto del query `members`
        // OMITE las cuentas sin verificar (ej. cuando el correo de verificación
        // rebotó: emailStatus=notDelivered). Sin esta pasada, un alumno con cuenta
        // UNVERIFIED no se encuentra y joinNetwork falla con "Email is already taken",
        // dejándolo sin verificar ni espacios. Hay que pasar status: UNVERIFIED explícito.
        $byEmailUnv = 'query { members(limit: 10, status: UNVERIFIED, filterBy: [{ key: "email", operator: equals, value: ' . $valueLit . ' }]) { nodes { id email name status } } }';
        try {
            $hit = $this->matchEmailNode($this->gqlWithAuth($byEmailUnv)['members']['nodes'] ?? null, $needle);
            if ($hit !== null) return $hit;
        } catch (\Throwable $_) { /* cae al fallback */ }

        // Fallback: búsqueda free-text (legacy).
        $emailLit = json_encode($email, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $hit = $this->matchEmailNode($this->gqlWithAuth('query { members(query: ' . $emailLit . ', limit: 10) { nodes { id email name status } } }')['members']['nodes'] ?? null, $needle);
            if ($hit !== null) return $hit;
        } catch (\Throwable $_) {}

        return null;
    }

    /**
     * Busca en los nodes el que tenga el email exacto (case-insensitive).
     * @param mixed $nodes
     * @return array{id:string, email:string, name:?string, status:?string}|null
     */
    private function matchEmailNode($nodes, string $needle): ?array
    {
        if (!is_array($nodes)) return null;
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $ne = isset($node['email']) && is_string($node['email']) ? $node['email'] : '';
            if ($ne === '' || mb_strtolower(trim($ne)) !== $needle) continue;
            $id = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
            if ($id === '') continue;
            return ['id' => $id, 'email' => $ne, 'name' => $node['name'] ?? null, 'status' => $node['status'] ?? null];
        }
        return null;
    }

    /** ¿El error es el bloqueo de verificación de Bettermode? (NO reintentar en el mismo run). */
    public static function isVerifyLocked(\Throwable $e): bool
    {
        return stripos($e->getMessage(), 'too many wrong attempts to verify') !== false;
    }

    /**
     * Crea un miembro (joinNetwork).
     * @return array{id:string, email:string, name:?string}
     */
    public function createMember(string $email, string $name, string $password, string $username): array
    {
        $mut = 'mutation Join($i: JoinNetworkInput!) { joinNetwork(input: $i) { accessToken member { id email name } } }';
        $tries = 3;
        for ($attempt = 1; ; $attempt++) {
            try {
                $d = $this->gqlWithAuth($mut, [
                    'i' => ['email' => $email, 'name' => $name, 'password' => $password, 'username' => $username],
                ]);
                $member = $d['joinNetwork']['member'] ?? null;
                if (!is_array($member) || empty($member['id'])) {
                    throw new \RuntimeException('Bettermode: joinNetwork sin member.id');
                }
                return ['id' => (string) $member['id'], 'email' => (string) ($member['email'] ?? $email), 'name' => $member['name'] ?? $name];
            } catch (\Throwable $e) {
                // "Validation Params Failed" suele ser COLISIÓN DE USERNAME (debe ser único
                // en la red). Reintentamos con un username nuevo (otro sufijo aleatorio).
                // NO reintentar si el email ya está tomado (eso lo resuelve findMemberByEmail
                // antes de llegar aquí) ni otros errores.
                $msg = $e->getMessage();
                $usernameClash = stripos($msg, 'Validation Params Failed') !== false && stripos($msg, 'already taken') === false;
                if ($attempt < $tries && $usernameClash) {
                    $base = preg_replace('/[0-9]+$/', '', $username) ?: 'user';
                    $username = substr($base, 0, 11) . random_int(10000, 99999);
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Verifica email del miembro. Si ya estaba verificado, Bettermode devuelve error;
     * el caller decide si tratarlo como info o error.
     */
    public function verifyMember(string $memberId): void
    {
        $memLit = json_encode($memberId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mut    = 'mutation { verifyMember(input: { memberId: ' . $memLit . ' }) { accessToken } }';
        $this->gqlWithAuth($mut);
    }

    /**
     * Actualiza un custom field del miembro. value como string (Bettermode acepta string para boolean fields).
     */
    public function updateMemberField(string $memberId, string $fieldKey, string $value): void
    {
        $idLit  = json_encode($memberId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $keyLit = json_encode($fieldKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $valLit = json_encode($value,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mut    = 'mutation { updateMember(id: ' . $idLit . ', input: { fields: { key: ' . $keyLit . ', value: ' . $valLit . ' } }) { id } }';
        $this->gqlWithAuth($mut);
    }

    /**
     * Agrega un miembro a un space.
     */
    public function grantSpaceAccess(string $memberId, string $spaceId): void
    {
        $spaceLit = json_encode($spaceId,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $memLit   = json_encode($memberId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mut      = 'mutation { addSpaceMembers(spaceId: ' . $spaceLit . ', input: [{ memberId: ' . $memLit . ' }]) { member { id } } }';
        $this->runWithRetries($mut, 'grant', $memberId, $spaceId);
    }

    /**
     * Remueve un miembro de un space.
     */
    public function revokeSpaceAccess(string $memberId, string $spaceId): void
    {
        $spaceLit = json_encode($spaceId,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $memLit   = json_encode($memberId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mut      = 'mutation { removeSpaceMembers(spaceId: ' . $spaceLit . ', memberIds: [' . $memLit . ']) { status } }';
        $this->runWithRetries($mut, 'revoke', $memberId, $spaceId);
    }

    private function runWithRetries(string $mutation, string $action, string $memberId, string $spaceId): void
    {
        $tries = 1 + max(0, $this->maxRetries);
        $last  = null;
        for ($i = 1; $i <= $tries; $i++) {
            try {
                $this->gqlWithAuth($mutation);
                if ($i > 1) {
                    $this->log('info', "bettermode.{$action}.ok.retry", ['member' => $memberId, 'space' => $spaceId, 'intento' => $i]);
                }
                return;
            } catch (\RuntimeException $e) {
                $last = $e;
                $this->log('warn', "bettermode.{$action}.fallo.intento", [
                    'member' => $memberId, 'space' => $spaceId, 'intento' => $i, 'error' => $e->getMessage(),
                ]);
                if ($i < $tries) usleep(1_000_000);
            }
        }
        throw new \RuntimeException(
            "Bettermode: {$action} fallo para member={$memberId} space={$spaceId} tras {$tries} intentos: " .
            ($last !== null ? $last->getMessage() : 'desconocido'),
            0,
            $last
        );
    }
}
