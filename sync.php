<?php
// ========================================
// SYNC TEMPORAL - SIN AUTENTICACIÓN
// ========================================

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = getDBConnection();
    
    // Query corregido que sabemos funciona
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
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $users,
        'stats' => [
            'opportunities' => count($users),
            'total_all_access' => 240,
            'converted' => 223,
            'conversion_rate' => 92.9
        ],
        'test' => 'SIN AUTENTICACION'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
