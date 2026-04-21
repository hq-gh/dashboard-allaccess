<?php
session_start();

echo "<h1>DEBUG AUTENTICACIÓN</h1>";
echo "<pre>";

echo "=== ESTADO DE SESIÓN ===\n";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . "\n";
echo "Session Name: " . session_name() . "\n";

echo "\n=== VARIABLES DE SESIÓN ===\n";
if (isset($_SESSION)) {
    print_r($_SESSION);
} else {
    echo "No hay variables de sesión\n";
}

echo "\n=== CONTENIDO DE CONFIG.PHP ===\n";
if (file_exists('config.php')) {
    echo "Leyendo config.php...\n";
    $config_content = file_get_contents('config.php');
    // Buscar la función isAuthenticated
    if (strpos($config_content, 'isAuthenticated') !== false) {
        echo "✅ Función isAuthenticated encontrada\n";
        // Extraer la función
        $lines = explode("\n", $config_content);
        $in_function = false;
        echo "\nFUNCIÓN isAuthenticated:\n";
        foreach ($lines as $line) {
            if (strpos($line, 'function isAuthenticated') !== false) {
                $in_function = true;
            }
            if ($in_function) {
                echo $line . "\n";
                if (strpos($line, '}') !== false && $in_function) {
                    break;
                }
            }
        }
    } else {
        echo "❌ Función isAuthenticated NO encontrada\n";
        echo "\nPrimeras 20 líneas de config.php:\n";
        $lines = file('config.php');
        for ($i = 0; $i < min(20, count($lines)); $i++) {
            echo ($i + 1) . ": " . $lines[$i];
        }
    }
} else {
    echo "❌ config.php no encontrado\n";
}

echo "\n=== TEST MANUAL DE AUTENTICACIÓN ===\n";
// Simular lo que debería hacer isAuthenticated
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    echo "✅ $_SESSION['authenticated'] = true\n";
} else {
    echo "❌ $_SESSION['authenticated'] no es true\n";
    echo "Valor actual: " . (isset($_SESSION['authenticated']) ? var_export($_SESSION['authenticated'], true) : 'NO EXISTE') . "\n";
}

echo "</pre>";
?>
