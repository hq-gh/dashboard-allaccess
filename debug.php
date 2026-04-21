<?php
// ========================================
// DIAGNÓSTICO COMPLETO - debug.php
// ========================================

echo "<h1>Diagnóstico del Sistema</h1>";
echo "<pre>";

echo "=== INFORMACIÓN BÁSICA ===\n";
echo "Fecha/Hora: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";
echo "Current Directory: " . getcwd() . "\n";

echo "\n=== ARCHIVOS PRESENTES ===\n";
$files = ['config.php', 'index.php', 'sync.php', 'login.php', 'logout.php'];
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "✅ $file - " . filesize($file) . " bytes\n";
    } else {
        echo "❌ $file - NO ENCONTRADO\n";
    }
}

echo "\n=== VARIABLES DE ENTORNO ===\n";
$env_vars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
foreach ($env_vars as $var) {
    $value = getenv($var);
    if ($value) {
        $masked = $var === 'DB_PASSWORD' ? str_repeat('*', strlen($value)) : $value;
        echo "✅ $var = $masked\n";
    } else {
        echo "❌ $var = NO CONFIGURADA\n";
    }
}

echo "\n=== PRUEBA DE CONEXIÓN BASE DE DATOS ===\n";
try {
    $host = getenv('DB_HOST');
    $dbname = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASSWORD');
    
    if ($host && $dbname && $user && $pass) {
        $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
        echo "✅ Conexión a base de datos: EXITOSA\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM subscriptions");
        $count = $stmt->fetchColumn();
        echo "✅ Tabla subscriptions: $count registros\n";
        
    } else {
        echo "❌ Variables de entorno incompletas\n";
    }
} catch (Exception $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
}

echo "\n=== PERMISOS DE ARCHIVOS ===\n";
foreach ($files as $file) {
    if (file_exists($file)) {
        $perms = fileperms($file);
        echo "$file: " . decoct($perms & 0777) . "\n";
    }
}

echo "\n=== CONTENIDO CONFIG.PHP (primeras 10 líneas) ===\n";
if (file_exists('config.php')) {
    $lines = file('config.php');
    for ($i = 0; $i < min(10, count($lines)); $i++) {
        $line = trim($lines[$i]);
        if (!empty($line) && !strpos($line, 'password')) {
            echo ($i + 1) . ": $line\n";
        }
    }
} else {
    echo "❌ config.php no encontrado\n";
}

echo "\n=== HEADERS HTTP ===\n";
foreach (getallheaders() as $name => $value) {
    echo "$name: $value\n";
}

echo "\n=== PHP ERRORS/WARNINGS ===\n";
if (function_exists('error_get_last')) {
    $error = error_get_last();
    if ($error) {
        echo "Último error: " . print_r($error, true) . "\n";
    } else {
        echo "No hay errores registrados\n";
    }
}

echo "</pre>";
?>
