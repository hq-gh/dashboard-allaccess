<?php
// ========================================
// DEBUG STATUS CORREGIDO - Cast de tipos
// ========================================

require_once 'config.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICACIÓN CORREGIDA CON CAST ===\n\n";
    
    // 1. Verificar tipo de datos de product_id
    echo "1. INFORMACIÓN DE COLUMNA product_id:\n";
    $stmt = $pdo->query("SELECT data_type FROM information_schema.columns WHERE table_name = 'subscriptions' AND column_name = 'product_id'");
    $data_type = $stmt->fetchColumn();
    echo "   Tipo de datos product_id: {$data_type}\n\n";
    
    // 2. Status para ALL ACCESS (con CAST)
    echo "2. STATUS PARA ALL ACCESS (Product ID 6587403) - CON CAST:\n";
    $stmt = $pdo->prepare("SELECT DISTINCT status, COUNT(*) as count FROM subscriptions WHERE product_id = '6587403' GROUP BY status ORDER BY count DESC");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   \"{$row['status']}\": {$row['count']} registros\n";
    }
    echo "\n";
    
    // 3. Probar query corregido (string en lugar de int)
    echo "3. USUARIOS ALL ACCESS SIN INFINITY (query corregido):\n";
    $query_corrected = "
        SELECT COUNT(DISTINCT sp.participant_id) as count
        FROM subscriptions s
        INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
        WHERE s.product_id = '6587403'
        AND s.status IN ('ACTIVE','APPROVED','COMPLETE')
        AND sp.participant_id NOT IN (
            SELECT DISTINCT sp2.participant_id
            FROM subscriptions s2
            INNER JOIN sales_participants sp2 ON s2.transaction_id = sp2.transaction_id
            WHERE s2.product_id IN ('6454766', '7065704', '6952229')
            AND s2.status IN ('ACTIVE','APPROVED','COMPLETE')
        )
    ";
    $stmt = $pdo->prepare($query_corrected);
    $stmt->execute();
    $corrected_count = $stmt->fetchColumn();
    echo "   Encontrados: {$corrected_count} usuarios\n\n";
    
    // 4. Verificar si existen los product_ids de INFINITY
    echo "4. VERIFICAR PRODUCT IDS DE INFINITY:\n";
    $infinity_products = ['6454766', '7065704', '6952229'];
    foreach ($infinity_products as $product_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $count = $stmt->fetchColumn();
        echo "   Product ID {$product_id}: {$count} registros\n";
    }
    echo "\n";
    
    // 5. Usuarios de muestra con detalles
    echo "5. MUESTRA DE USUARIOS ALL ACCESS SIN INFINITY (primeros 15):\n";
    $sample_query = "
        SELECT DISTINCT
            sp.participant_name as name,
            sp.participant_email as email,
            sp.participant_country as country,
            sp.participant_phone as phone
        FROM subscriptions s
        INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
        WHERE s.product_id = '6587403'
        AND s.status = 'ACTIVE'
        AND sp.participant_id NOT IN (
            SELECT DISTINCT sp2.participant_id
            FROM subscriptions s2
            INNER JOIN sales_participants sp2 ON s2.transaction_id = sp2.transaction_id
            WHERE s2.product_id IN ('6454766', '7065704', '6952229')
            AND s2.status = 'ACTIVE'
        )
        ORDER BY sp.participant_name
        LIMIT 15
    ";
    $stmt = $pdo->prepare($sample_query);
    $stmt->execute();
    $sample_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($sample_users as $user) {
        $name = $user['name'] ?: 'N/A';
        $email = $user['email'] ?: 'N/A';
        $country = $user['country'] ?: '';
        $phone = $user['phone'] ?: '';
        echo "   {$name} | {$email} | {$country} | {$phone}\n";
    }
    echo "\n";
    
    // 6. Conteo total con diferentes status
    echo "6. CONTEOS POR STATUS (solo ALL ACCESS):\n";
    $statuses = ['ACTIVE', 'INACTIVE', 'CANCELLED_BY_CUSTOMER', 'DELAYED'];
    foreach ($statuses as $status) {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT sp.participant_id) as count
            FROM subscriptions s
            INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
            WHERE s.product_id = '6587403'
            AND s.status = ?
            AND sp.participant_id NOT IN (
                SELECT DISTINCT sp2.participant_id
                FROM subscriptions s2
                INNER JOIN sales_participants sp2 ON s2.transaction_id = sp2.transaction_id
                WHERE s2.product_id IN ('6454766', '7065704', '6952229')
                AND s2.status IN ('ACTIVE','APPROVED','COMPLETE')
            )
        ");
        $stmt->execute([$status]);
        $count = $stmt->fetchColumn();
        echo "   Status '{$status}': {$count} usuarios\n";
    }
    echo "\n";
    
    echo "=== CONCLUSIONES ===\n";
    echo "Query corregido (strings): {$corrected_count} usuarios\n";
    echo "Expected: 29 usuarios\n";
    echo "Muestra encontrada: " . count($sample_users) . " usuarios\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
