<?php
// ========================================
// CONFIGURACIÓN DATABASE Y AUTENTICACIÓN
// Dashboard Infinity VIP → INFINITY | 5T4D10
// ========================================

// Errores: nunca se muestran al usuario, siempre se loggean a stderr (Railway logs)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php://stderr');

// Timezone para timestamps consistentes
date_default_timezone_set('America/Merida');

// Configuración de sesión segura (debe ir antes de session_start())
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// Timeout de sesión: 8 horas
define('SESSION_TIMEOUT', 8 * 60 * 60);

// Rate limiting login: 5 intentos / 15 min
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 15 * 60);

/**
 * Conexión PDO a PostgreSQL (Neon).
 * Lanza Exception si faltan variables de entorno o falla la conexión.
 */
function getDBConnection() {
    $host = getenv('DB_HOST');
    $dbname = getenv('DB_NAME') ?: getenv('DB_DATABASE');
    $user = getenv('DB_USER') ?: getenv('DB_USERNAME');
    $pass = getenv('DB_PASS') ?: getenv('DB_PASSWORD');

    if (!$host || !$dbname || !$user || !$pass) {
        error_log('[config] Variables de entorno de BD no configuradas');
        throw new Exception('Variables de entorno de base de datos no configuradas');
    }

    $dsn = "pgsql:host={$host};dbname={$dbname};sslmode=require";

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    } catch (PDOException $e) {
        error_log('[config] PDO error: ' . $e->getMessage());
        throw new Exception('Error de conexión a base de datos');
    }
}

/**
 * Inicia la sesión con los flags seguros aplicados.
 * Idempotente: seguro de llamar múltiples veces.
 */
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('STADIO_SESSID');
        session_start();
    }
}

/**
 * Devuelve true si la sesión es válida y no ha expirado.
 * Si expiró, la destruye automáticamente.
 */
function isAuthenticated() {
    startSecureSession();

    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }

    // Validar timeout
    $loginTime = $_SESSION['login_time'] ?? 0;
    if ((time() - $loginTime) > SESSION_TIMEOUT) {
        error_log('[auth] Sesión expirada para usuario: ' . ($_SESSION['username'] ?? 'unknown'));
        destroySession();
        return false;
    }

    return true;
}

/**
 * Redirige a login.php si no hay sesión válida (uso para páginas).
 */
function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Devuelve JSON 401 si no hay sesión válida (uso para endpoints API).
 */
function requireAuthApi() {
    if (!isAuthenticated()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
}

/**
 * Destruye sesión completa, cookies y datos.
 */
function destroySession() {
    startSecureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Genera token CSRF para la sesión actual.
 */
function csrfToken() {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida token CSRF en constant-time.
 */
function csrfValidate($token) {
    startSecureSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !is_string($token)) {
        return false;
    }
    return hash_equals($expected, $token);
}

/**
 * Headers de seguridad estándar para responses HTML.
 * Llamar al inicio de cada página HTML.
 */
function applySecurityHeaders() {
    // Evita clickjacking
    header('X-Frame-Options: DENY');
    // Evita MIME sniffing
    header('X-Content-Type-Options: nosniff');
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // HSTS (Railway sirve HTTPS)
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    // CSP: permite el logo externo de GitHub raw y estilos inline
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "img-src 'self' data: https://github.com https://*.githubusercontent.com; " .
        "style-src 'self' 'unsafe-inline'; " .
        "script-src 'self' 'unsafe-inline'; " .
        "connect-src 'self'; " .
        "frame-ancestors 'none'; " .
        "base-uri 'self';"
    );
    // Permissions
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
