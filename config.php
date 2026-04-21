<?php
// ========================================
// CONFIGURACIÓN DATABASE Y AUTENTICACIÓN
// ========================================

error_reporting(E_ALL);
ini_set('display_errors', 0);

function getDBConnection() {
    $host = getenv('DB_HOST');
    $dbname = getenv('DB_NAME') ?: getenv('DB_DATABASE');
    $user = getenv('DB_USER') ?: getenv('DB_USERNAME');
    $pass = getenv('DB_PASS') ?: getenv('DB_PASSWORD');
    
    if (!$host || !$dbname || !$user || !$pass) {
        throw new Exception('Variables de entorno de base de datos no configuradas');
    }
    
    $dsn = "pgsql:host=$host;dbname=$dbname";
    
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception('Error de conexión: ' . $e->getMessage());
    }
}

function isAuthenticated() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}
?>
