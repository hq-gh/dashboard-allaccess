<?php
// ========================================
// INVESTIGAR ESTRUCTURA DE TABLAS
// ========================================

require_once 'config.php';

try {
    $pdo = getDBConnection();
    
    echo "=== INVESTIGACIÓN DE ESTRUCTURA DE TABLAS ===\n\n";
    
    // 1. Estructura de tabla subscriptions
    echo "1. COLUMNAS DE TABLA 'subscriptions':\n";
    $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'subscriptions' ORDER BY ordinal_position");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   {$row['column_name']} ({$row['data_type']})\n";
    }
    echo "\n";
    
    // 2. Estructura de tabla sales_participants  
    echo "2. COLUMNAS DE TABLA 'sales_participants':\n";
    $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'sales_participants' ORDER BY ordinal_position");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   {$row['column_name']} ({$row['data_type']})\n";
    }
    echo "\n";
    
    // 3. Muestra de datos de subscriptions
    echo "3. MUESTRA DE DATOS 'subscriptions' (5 registros):\n";
    $stmt = $pdo->query("SELECT * FROM subscriptions WHERE product_id = '6587403' AND status = 'ACTIVE' LIMIT 5");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (empty($columns)) {
            $columns = array_keys($row);
            echo "   Columnas: " . implode(' | ', $columns) . "\n";
        }
        echo "   " . implode(' | ', array_values($row)) . "\n";
    }
    echo "\n";
    
    // 4. Muestra de datos de sales_participants
    echo "4. MUESTRA DE DATOS 'sales_participants' (5 registros):\n";
    $stmt = $pdo->query("SELECT * FROM sales_participants LIMIT 5");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (empty($columns)) {
            $columns = array_keys($row);
            echo "   Columnas: " . implode(' | ', $columns) . "\n";
        }
        echo "   " . implode(' | ', array_values($row)) . "\n";
    }
    echo "\n";
    
    // 5. Ver si hay relación entre tablas
    echo "5. VERIFICAR RELACIÓN ENTRE TABLAS:\n";
    echo "   ¿Qué columna de subscriptions se relaciona con sales_participants?\n";
    
    // Buscar columnas con transaction en el nombre
    echo "   Columnas con 'transaction' en subscriptions:\n";
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'subscriptions' AND column_name LIKE '%transaction%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "     - {$row['column_name']}\n";
    }
    
    echo "   Columnas con 'transaction' en sales_participants:\n";
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'sales_participants' AND column_name LIKE '%transaction%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "     - {$row['column_name']}\n";
    }
    echo "\n";
    
    // 6. Contar registros en cada tabla
    echo "6. CONTEO DE REGISTROS:\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM subscriptions");
    $sub_count = $stmt->fetchColumn();
    echo "   subscriptions: {$sub_count} registros\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM sales_participants");
    $part_count = $stmt->fetchColumn();
    echo "   sales_participants: {$part_count} registros\n";
    echo "\n";
    
    echo "=== CONCLUSIÓN ===\n";
    echo "Con esta información podremos crear el query correcto.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
