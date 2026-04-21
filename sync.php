<?php
// ========================================
// SYNC ENDPOINT DEFINITIVO - Query corregido
// API para sincronización de datos
// ========================================

require_once 'config.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // QUERY FINAL CORREGIDO - Usa subscriber_ucode correctamente
    $query = "
        SELECT DISTINCT
            s.subscriber_name as name,
            s.subscriber_email as email,
            sp.buyer_country as country,
            sp.buyer_phone as phone,
            s.transaction_id as codigo_transaccion,
            TO_TIMESTAMP(s.request_date / 1000) as fecha_hora,
            s.plan_name as plan,
            s.price_value as precio,
            s.price_currency as moneda
        FROM subscriptions s
        INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
        WHERE s.product_id = '6587403'
        AND s.status = 'ACTIVE'
        AND s.subscriber_ucode NOT IN (
            SELECT DISTINCT s2.subscriber_ucode
            FROM subscriptions s2
            WHERE s2.product_id IN ('6454766', '7065704', '6952229')
            AND s2.status = 'ACTIVE'
            AND s2.subscriber_ucode IS NOT NULL
        )
        ORDER BY s.subscriber_name
    ";
    
    // Query para estadísticas total ALL ACCESS
    $statsQuery = "
        SELECT COUNT(DISTINCT s.subscriber_ucode) as total_all_access
        FROM subscriptions s
        INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
        WHERE s.product_id = '6587403'
        AND s.status = 'ACTIVE'
    ";
    
    // Query para convertidos (ALL ACCESS + INFINITY)
    $convertedQuery = "
        SELECT COUNT(DISTINCT s1.subscriber_ucode) as converted
        FROM subscriptions s1
        INNER JOIN sales_participants sp1 ON s1.transaction_id = sp1.transaction_id
        WHERE s1.product_id = '6587403'
        AND s1.status = 'ACTIVE'
        AND s1.subscriber_ucode IN (
            SELECT DISTINCT s2.subscriber_ucode
            FROM subscriptions s2
            WHERE s2.product_id IN ('6454766', '7065704', '6952229')
            AND s2.status = 'ACTIVE'
            AND s2.subscriber_ucode IS NOT NULL
        )
    ";
    
    // Ejecutar query principal
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ejecutar estadísticas
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute();
    $totalStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    $convertedStmt = $pdo->prepare($convertedQuery);
    $convertedStmt->execute();
    $convertedStats = $convertedStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calcular métricas
    $opportunities = count($users);
    $total_all_access = (int) $totalStats['total_all_access'];
    $converted = (int) $convertedStats['converted'];
    $conversion_rate = $total_all_access > 0 ? round(($converted / $total_all_access) * 100, 1) : 0;
    
    // Respuesta
    $response = [
        'success' => true,
        'data' => $users,
        'stats' => [
            'opportunities' => $opportunities,
            'total_all_access' => $total_all_access,
            'converted' => $converted,
            'conversion_rate' => $conversion_rate
        ],
        'meta' => [
            'all_access_product_id' => '6587403',
            'infinity_product_ids' => ['6454766', '7065704', '6952229'],
            'active_status' => 'ACTIVE',
            'query_fix' => 'Usa subscriber_ucode con IS NOT NULL'
        ],
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>
