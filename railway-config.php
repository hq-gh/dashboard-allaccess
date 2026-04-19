<?php
// ========================================
// CONFIGURACIÓN - Dashboard ALL ACCESS → INFINITY
// 5T4D10 - Rub (CTO)
// Railway Version with Environment Variables
// ========================================

// Configuración de Base de Datos (desde variables de entorno o fallback)
define('DB_HOST', $_ENV['DB_HOST'] ?? 'ep-noisy-frog-ajf0ynrl-pooler.c-3.us-east-2.aws.neon.tech');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'neondb');
define('DB_USER', $_ENV['DB_USER'] ?? 'neondb_owner');
define('DB_PASS', $_ENV['DB_PASS'] ?? 'npg_cAlUZJgkh5f0');
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
define('DASHBOARD_USER', $_ENV['DASHBOARD_USER'] ?? '5t4d10admin');
define('DASHBOARD_PASS', $_ENV['DASHBOARD_PASS'] ?? 'DefaultPass123!');
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
