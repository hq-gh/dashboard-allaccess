<?php
// ========================================
// CONFIGURACIÓN GLOBAL - Dashboard ALL ACCESS → INFINITY
// Variables de entorno y constantes del sistema
// ========================================

// Configuración de autenticación (desde variables de entorno)
$dashboard_user = getenv('DASHBOARD_USER');
$dashboard_pass = getenv('DASHBOARD_PASS');

if (!$dashboard_user || !$dashboard_pass) {
    die('Error: Variables de entorno DASHBOARD_USER y DASHBOARD_PASS requeridas');
}

define('DASHBOARD_USER', $dashboard_user);
define('DASHBOARD_PASS', $dashboard_pass);
define('SESSION_TIMEOUT', 3600); // 1 hora

// Configuración de base de datos (desde variables de entorno)
$db_host = getenv('DB_HOST');
$db_name = getenv('DB_NAME');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');

if (!$db_host || !$db_name || !$db_user || !$db_pass) {
    die('Error: Variables de entorno de base de datos requeridas (DB_HOST, DB_NAME, DB_USER, DB_PASS)');
}

define('DB_HOST', $db_host);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);

// Configuración de zona horaria y encoding
define('TIMEZONE', 'America/Merida');
date_default_timezone_set(TIMEZONE);

// Configuración de productos y estados (business logic)
define('ALL_ACCESS_PRODUCT_ID', '6587403');
define('INFINITY_PRODUCT_IDS', ['6454766', '7065704', '6952229']);
define('ACTIVE_STATUSES', ['ACTIVE', 'APPROVED', 'COMPLETE']);

// ========================================
// FUNCIÓN: Conexión a Base de Datos
// ========================================
function getDbConnection() {
    try {
        // DSN corregido para PostgreSQL con SSL
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            DB_HOST,
            5432, // Puerto PostgreSQL estándar
            DB_NAME
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 30,
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        // Configurar encoding UTF-8
        $pdo->exec("SET NAMES 'utf8'");
        
        return $pdo;
        
    } catch (PDOException $e) {
        error_log("Error de conexión DB: " . $e->getMessage());
        throw new Exception("Error de conexión a la base de datos");
    }
}

// ========================================
// FUNCIÓN: Validar Autenticación
// ========================================
function validateAuth($username, $password) {
    return ($username === DASHBOARD_USER && $password === DASHBOARD_PASS);
}

// ========================================
// FUNCIÓN: Iniciar Sesión
// ========================================
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        
        // Configurar timeout de sesión
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            return false;
        }
        
        $_SESSION['last_activity'] = time();
    }
    
    return true;
}

// ========================================
// FUNCIÓN: Verificar Si Está Autenticado
// ========================================
function isAuthenticated() {
    startSession();
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

// ========================================
// FUNCIÓN: Procesar Login
// ========================================
function processLogin($username, $password) {
    if (validateAuth($username, $password)) {
        startSession();
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['login_time'] = time();
        return true;
    }
    return false;
}

// ========================================
// FUNCIÓN: Logout
// ========================================
function logout() {
    startSession();
    session_unset();
    session_destroy();
}

// ========================================
// CONFIGURACIÓN DE HEADERS GLOBALES
// ========================================
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

?>
