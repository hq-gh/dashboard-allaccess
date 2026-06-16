#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Top comentaristas en Bettermode (diez.5t4d10.com).
 *
 * Consulta la API GraphQL Analytics de Bettermode y arma un ranking de miembros
 * ordenados por número de COMENTARIOS (en Bettermode un "comentario" = reply) en
 * un rango de fechas. Salida: CSV en ./output/ + resumen en consola.
 *
 * DSL validado contra la API real (2026-06): `count(reply) as comentarios` funciona;
 * `entities` viene como OBJETO (entities.person), no como arreglo.
 *
 * Uso:
 *   php bettermode-top-comentaristas.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--limit=N]
 * Defaults: from = hace 30 días, to = hoy, limit = 100. Fechas en America/Mexico_City.
 *
 * Variables de entorno (de .env o getenv; NUNCA hardcodear — regla #7):
 *   BETTERMODE_API_URL       (default https://api.bettermode.com)
 *   BETTERMODE_NETWORK_ID    (requerida)
 *   BETTERMODE_APP_TOKEN     (token Bearer; si no está, se hace login con las de abajo)
 *   -- fallback de auth (login admin, como el resto del proyecto) --
 *   BETTERMODE_NETWORK_DOMAIN, BETTERMODE_ADMIN_EMAIL, BETTERMODE_ADMIN_PASSWORD
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Solo CLI.\n"); exit(1); }

const TZ = 'America/Mexico_City';

// ---------------------------------------------------------------------------
// 1) Cargar .env (carga manual: busca .env junto al script o en la raíz del repo)
// ---------------------------------------------------------------------------
foreach ([__DIR__ . '/.env', __DIR__ . '/../.env', getcwd() . '/.env'] as $envFile) {
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $k = trim($k); $v = trim($v, " \t\"'");
            if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); }
        }
        break;
    }
}

// ---------------------------------------------------------------------------
// 2) Leer config y validar (abortar con mensaje claro si falta algo)
// ---------------------------------------------------------------------------
$apiUrl    = rtrim((string) (getenv('BETTERMODE_API_URL') ?: 'https://api.bettermode.com'), '/');
$networkId = (string) (getenv('BETTERMODE_NETWORK_ID') ?: '');
$appToken  = (string) (getenv('BETTERMODE_APP_TOKEN') ?: '');

if ($networkId === '') {
    fwrite(STDERR, "ERROR: falta BETTERMODE_NETWORK_ID en el entorno.\n");
    exit(1);
}
// Confirmar región (US vs EU) por la URL.
$region = stripos($apiUrl, 'eu-central') !== false || stripos($apiUrl, 'eu.') !== false ? 'EU' : 'US';
fwrite(STDERR, "[info] API={$apiUrl} ({$region}) network={$networkId}\n");

// ---------------------------------------------------------------------------
// 3) Argumentos CLI
// ---------------------------------------------------------------------------
$args = getopt('', ['from::', 'to::', 'limit::']);
$tz   = new DateTimeZone(TZ);
$from = isset($args['from']) && $args['from'] !== '' ? $args['from'] : (new DateTime('now', $tz))->modify('-30 days')->format('Y-m-d');
$to   = isset($args['to'])   && $args['to']   !== '' ? $args['to']   : (new DateTime('now', $tz))->format('Y-m-d');
$limit = isset($args['limit']) && (int) $args['limit'] > 0 ? (int) $args['limit'] : 100;

// Validar formato de fecha.
foreach (['from' => $from, 'to' => $to] as $label => $val) {
    $d = DateTime::createFromFormat('Y-m-d', $val, $tz);
    if (!$d || $d->format('Y-m-d') !== $val) {
        fwrite(STDERR, "ERROR: fecha --{$label} inválida ('{$val}'). Formato esperado YYYY-MM-DD.\n");
        exit(1);
    }
}
// Epoch ms: inicio del día 'from' y fin del día 'to' (23:59:59), en CdMx.
$fromMs = (new DateTime($from . ' 00:00:00', $tz))->getTimestamp() * 1000;
$toMs   = (new DateTime($to   . ' 23:59:59', $tz))->getTimestamp() * 1000;
if ($fromMs > $toMs) { fwrite(STDERR, "ERROR: --from es posterior a --to.\n"); exit(1); }

// ---------------------------------------------------------------------------
// 4) Helper GraphQL (cURL nativo, sin dependencias)
// ---------------------------------------------------------------------------
function gql(string $apiUrl, string $query, ?string $token, ?array $variables = null): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null && $token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    $payload = ['query' => $query];
    if ($variables !== null) $payload['variables'] = $variables;

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw    = curl_exec($ch);
    $errNo  = curl_errno($ch);
    $errMsg = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0 || $raw === false) {
        throw new RuntimeException("cURL error #{$errNo}: {$errMsg}");
    }
    if ($status >= 400) {
        throw new RuntimeException("HTTP {$status}: " . substr((string) $raw, 0, 400));
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Respuesta no es JSON válido (HTTP {$status}).");
    }
    if (!empty($decoded['errors'])) {
        throw new RuntimeException('GraphQL errors: ' . json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE));
    }
    if (!isset($decoded['data']) || !is_array($decoded['data'])) {
        throw new RuntimeException('Respuesta sin clave "data".');
    }
    return $decoded['data'];
}

/**
 * Obtiene el token Bearer. Si BETTERMODE_APP_TOKEN está seteado, lo usa tal cual.
 * Si no, hace el flujo del proyecto: guest token (tokens) -> loginNetwork (admin).
 */
function obtenerToken(string $apiUrl, string $appToken): string
{
    if ($appToken !== '') return $appToken;

    $domain = (string) (getenv('BETTERMODE_NETWORK_DOMAIN') ?: '');
    $email  = (string) (getenv('BETTERMODE_ADMIN_EMAIL') ?: '');
    $pass   = (string) (getenv('BETTERMODE_ADMIN_PASSWORD') ?: '');
    if ($domain === '' || $email === '' || $pass === '') {
        throw new RuntimeException('Falta BETTERMODE_APP_TOKEN y tampoco hay credenciales de login (BETTERMODE_NETWORK_DOMAIN/ADMIN_EMAIL/ADMIN_PASSWORD).');
    }
    // 1) guest token
    $domLit = json_encode($domain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $g = gql($apiUrl, 'query { tokens(networkDomain: ' . $domLit . ') { accessToken } }', null);
    $guest = $g['tokens']['accessToken'] ?? '';
    if ($guest === '') throw new RuntimeException('No se obtuvo guest token.');
    // 2) loginNetwork -> admin token
    $eLit = json_encode($email, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pLit = json_encode($pass,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $l = gql($apiUrl, 'mutation { loginNetwork(input: { usernameOrEmail: ' . $eLit . ', password: ' . $pLit . ' }) { accessToken } }', $guest);
    $admin = $l['loginNetwork']['accessToken'] ?? '';
    if ($admin === '') throw new RuntimeException('loginNetwork no devolvió accessToken.');
    return $admin;
}

// ---------------------------------------------------------------------------
// 5) Ejecutar
// ---------------------------------------------------------------------------
try {
    $token = obtenerToken($apiUrl, $appToken);

    // Prueba mínima de auth antes de la query pesada.
    $me = gql($apiUrl, 'query { __typename }', $token);

    // DSL de analytics: cuenta SOLO comentarios (reply) por persona.
    $dsl = "select person as person, count(reply) as comentarios\n"
         . "timeFrame from {$fromMs} to {$toMs}\n"
         . "where network = '{$networkId}' and space_type in ('GROUP','BROADCAST') and person != null\n"
         . "group by person\n"
         . "having comentarios > 0\n"
         . "order by comentarios desc\n"
         . "limit {$limit}";

    $gqlQuery = 'query Analytics($queries: [String!]!) {
        analytics(queries: $queries) {
            query
            records {
                payload { key value }
                entities { __typename person { id name displayName username email relativeUrl } }
            }
        }
    }';

    $data = gql($apiUrl, $gqlQuery, $token, ['queries' => [$dsl]]);
    if (!isset($data['analytics'][0]['records']) || !is_array($data['analytics'][0]['records'])) {
        throw new RuntimeException('Respuesta sin analytics[].records. Crudo: ' . substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 400));
    }
    $records = $data['analytics'][0]['records'];

    // Procesar: cruzar payload (comentarios) con entities.person (datos del miembro).
    $rows = [];
    foreach ($records as $rec) {
        $payload = [];
        foreach (($rec['payload'] ?? []) as $p) {
            if (isset($p['key'])) $payload[$p['key']] = $p['value'] ?? null;
        }
        $comentarios = (int) ($payload['comentarios'] ?? 0);

        // entities es OBJETO con .person (validado). Defensivo por si llegara como lista.
        $person = $rec['entities']['person'] ?? null;
        if ($person === null && isset($rec['entities'][0])) {
            foreach ($rec['entities'] as $e) { if (isset($e['person'])) { $person = $e['person']; break; } }
        }
        $memberId = $person['id'] ?? trim((string) ($payload['person'] ?? ''), '"');
        $rows[] = [
            'member_id'   => (string) $memberId,
            'nombre'      => (string) ($person['name'] ?? $person['displayName'] ?? ''),
            'username'    => (string) ($person['username'] ?? ''),
            'email'       => (string) ($person['email'] ?? ''),
            'comentarios' => $comentarios,
        ];
    }

    // El motor ya ordena desc; reforzamos por si acaso.
    usort($rows, static fn($a, $b) => $b['comentarios'] <=> $a['comentarios']);

    // ----- CSV en ./output/ con timestamp -----
    $outDir = __DIR__ . '/output';
    if (!is_dir($outDir)) { mkdir($outDir, 0775, true); }
    $stamp = (new DateTime('now', $tz))->format('Ymd-His');
    $csvPath = "{$outDir}/top-comentaristas_{$from}_a_{$to}_{$stamp}.csv";
    $fh = fopen($csvPath, 'w');
    fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8 (acentos en Excel)
    fputcsv($fh, ['posicion', 'member_id', 'nombre', 'username', 'email', 'comentarios']);
    $pos = 0;
    foreach ($rows as $r) {
        $pos++;
        fputcsv($fh, [$pos, $r['member_id'], $r['nombre'], $r['username'], $r['email'], $r['comentarios']]);
    }
    fclose($fh);

    // ----- Resumen en consola -----
    echo "\n=== Top comentaristas — diez.5t4d10.com ===\n";
    echo "Rango: {$from} a {$to} (CdMx)  |  miembros con comentarios: " . count($rows) . "  |  límite: {$limit}\n\n";
    printf("  %-4s %-32s %-28s %s\n", '#', 'Nombre', 'Email', 'Comentarios');
    echo '  ' . str_repeat('-', 74) . "\n";
    foreach (array_slice($rows, 0, 10) as $i => $r) {
        printf("  %-4d %-32s %-28s %d\n", $i + 1, mb_substr($r['nombre'] ?: '(sin nombre)', 0, 32), mb_substr($r['email'], 0, 28), $r['comentarios']);
    }
    if (count($rows) === $limit) {
        echo "\n[aviso] Se alcanzó el límite ({$limit}); puede haber más miembros. Sube --limit si necesitas el universo completo.\n";
    }
    echo "\nCSV: {$csvPath}\n";
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
