<?php
// ========================================
// CONFIGURACIÓN - Dashboard ALL ACCESS → INFINITY
// 5T4D10 - Rub (CTO)
// Railway Version with Environment Variables
// ========================================

// Configuración de Base de Datos (desde variables de entorno o fallback)
define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('DB_OPTIONS', '?sslmode=require');

// IDs de Productos
define('ALL_ACCESS_PRODUCT_ID', '6587403');
define('INFINITY_PRODUCT_IDS', ['6454766', '7065704', '6952229']); // Principal, Totalplay M, Totalplay A

// Estados válidos para considerar "activo"
define('ACTIVE_STATUSES', ['ACTIVE']);

// Configuración de aplicación
define('APP_NAME', 'Dashboard ALL ACCESS → INFINITY');
define('APP_VERSION', '1.1.0');
define('TIMEZONE', 'America/Merida'); // México - Yucatán

// Configuración de CORS (ajustar según necesidad)
define('ALLOWED_ORIGINS', ['*']); // Para desarrollo. En producción: especificar dominios

// Configuración de autenticación (desde variables de entorno)
$dashboard_user = getenv('DASHBOARD_USER');
$dashboard_pass = getenv('DASHBOARD_PASS');

if (!$dashboard_user || !$dashboard_pass) {
    die('Error: Variables de entorno DASHBOARD_USER y DASHBOARD_PASS requeridas');
}

define('DASHBOARD_USER', $dashboard_user);
define('DASHBOARD_PASS', $dashboard_pass);
define('SESSION_TIMEOUT', 3600); // 1 hora

// Función para conectar a la base de datos
function getDbConnection() {
    $dsn = "pgsql:host=" . DB_HOST . ";dbname=" . DB_NAME . DB_OPTIONS;
    
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30, // 30 segundos timeout
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Error de conexión DB: " . $e->getMessage());
        throw new Exception("Error de conexión a la base de datos");
    }
}

// Configurar timezone
date_default_timezone_set(TIMEZONE);
?>
