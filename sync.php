<?php
// ========================================
// SYNC API - Datos Infinity VIP → INFINITY
// ========================================
require_once 'config.php';

requireAuthApi();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $pdo = getDBConnection();

    // Query principal: Pecadores (Infinity VIP sin INFINITY)
    $query = "
        SELECT DISTINCT
            s.subscriber_name AS name,
            s.subscriber_email AS email,
            sp.buyer_country AS country,
            sp.buyer_phone AS phone,
            s.transaction_id AS codigo_transaccion,
            TO_TIMESTAMP(s.request_date / 1000) AS fecha_hora,
            s.plan_name AS plan,
            s.price_value AS precio,
            s.price_currency AS moneda
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

    // Total Infinity VIP activos
    $totalQuery = "
        SELECT COUNT(DISTINCT s.subscriber_ucode) AS total
        FROM subscriptions s
        INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
        WHERE s.product_id = '6587403'
          AND s.status = 'ACTIVE'
    ";
    $totalStmt = $pdo->prepare($totalQuery);
    $totalStmt->execute();
    $totalInfinityVip = (int) ($totalStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    // Convertidos (Infinity VIP + INFINITY activos)
    $convertedQuery = "
        SELECT COUNT(DISTINCT s1.subscriber_ucode) AS converted
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
    $convertedStmt = $pdo->prepare($convertedQuery);
    $convertedStmt->execute();
    $converted = (int) ($convertedStmt->fetch(PDO::FETCH_ASSOC)['converted'] ?? 0);

    $pecadores = count($users);
    $conversionRate = $totalInfinityVip > 0
        ? round(($converted / $totalInfinityVip) * 100, 1)
        : 0;

    echo json_encode([
        'success' => true,
        'data'    => $users,
        'stats'   => [
            'pecadores'          => $pecadores,
            'total_infinity_vip' => $totalInfinityVip,
            'no_pecadores'       => $converted,
            'conversion_rate'    => $conversionRate,
        ],
        'generated_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[sync] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Error procesando solicitud',
    ]);
}
