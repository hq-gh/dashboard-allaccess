<?php
// ========================================
// SYNC FINAL CON AUTENTICACIÓN SIMPLE
// ========================================

// Verificación de sesión simple
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

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
    
    // Stats queries
    $statsQuery = "SELECT COUNT(DISTINCT s.subscriber_ucode) as total_all_access FROM subscriptions s INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id WHERE s.product_id = '6587403' AND s.status = 'ACTIVE'";
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute();
    $totalStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    $convertedQuery = "SELECT COUNT(DISTINCT s1.subscriber_ucode) as converted FROM subscriptions s1 INNER JOIN sales_participants sp1 ON s1.transaction_id = sp1.transaction_id WHERE s1.product_id = '6587403' AND s1.status = 'ACTIVE' AND s1.subscriber_ucode IN (SELECT DISTINCT s2.subscriber_ucode FROM subscriptions s2 WHERE s2.product_id IN ('6454766', '7065704', '6952229') AND s2.status = 'ACTIVE' AND s2.subscriber_ucode IS NOT NULL)";
    $convertedStmt = $pdo->prepare($convertedQuery);
    $convertedStmt->execute();
    $convertedStats = $convertedStmt->fetch(PDO::FETCH_ASSOC);
    
    $opportunities = count($users);
    $total_all_access = (int) $totalStats['total_all_access'];
    $converted = (int) $convertedStats['converted'];
    $conversion_rate = $total_all_access > 0 ? round(($converted / $total_all_access) * 100, 1) : 0;
    
    echo json_encode([
        'success' => true,
        'data' => $users,
        'stats' => [
            'pecadores' => $opportunities,
            'total_all_access' => $total_all_access,
            'no_pecadores' => $converted
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
