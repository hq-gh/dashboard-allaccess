<?php
// ========================================
// SYNC ENDPOINT - Dashboard ALL ACCESS → INFINITY
// Retorna usuarios con ALL ACCESS que NO tienen INFINITY
// ========================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

try {
    // Conectar a la base de datos
    $pdo = getDbConnection();
    
    // QUERY PRINCIPAL: Usuarios con ALL ACCESS activo que NO tienen INFINITY activo
    $sql = "
    WITH all_access_users AS (
        -- Usuarios con ALL ACCESS activo
        SELECT DISTINCT 
            s.subscriber_name,
            s.subscriber_email,
            s.transaction_id
        FROM subscriptions s
        WHERE s.product_id = :all_access_product_id
          AND s.status = ANY(:active_statuses)
    ),
    infinity_users AS (
        -- Usuarios que YA tienen INFINITY activo (cualquier variante)
        SELECT DISTINCT s.subscriber_email
        FROM subscriptions s
        WHERE s.product_id = ANY(:infinity_product_ids)
          AND s.status = ANY(:active_statuses2)
    ),
    target_users AS (
        -- ALL ACCESS sin INFINITY (targets de conversión)
        SELECT 
            aa.subscriber_name,
            aa.subscriber_email,
            aa.transaction_id
        FROM all_access_users aa
        LEFT JOIN infinity_users iu ON aa.subscriber_email = iu.subscriber_email
        WHERE iu.subscriber_email IS NULL  -- NO tienen INFINITY
    )
    -- Resultado final con teléfono de sales_participants
    SELECT 
        TRIM(tu.subscriber_name) AS name,
        tu.subscriber_email AS email,
        sp.buyer_phone AS phone,
        sp.buyer_country AS country,
        COUNT(*) OVER() AS total_records
    FROM target_users tu
    LEFT JOIN sales_participants sp ON tu.transaction_id = sp.transaction_id
    ORDER BY tu.subscriber_name ASC
    ";
    
    // Preparar parámetros
    $params = [
        ':all_access_product_id' => ALL_ACCESS_PRODUCT_ID,
        ':active_statuses' => '{' . implode(',', ACTIVE_STATUSES) . '}',
        ':infinity_product_ids' => '{' . implode(',', INFINITY_PRODUCT_IDS) . '}',
        ':active_statuses2' => '{' . implode(',', ACTIVE_STATUSES) . '}',
    ];
    
    // Ejecutar query principal
    $start_time = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    $query_time = round((microtime(true) - $start_time) * 1000, 2);
    
    // Estadísticas adicionales CORREGIDAS
    $stats_sql = "
    -- Total ALL ACCESS activos
    SELECT 
        'all_access_total' as metric,
        COUNT(DISTINCT subscriber_email) as value
    FROM subscriptions 
    WHERE product_id = :all_access_product_id 
      AND status = ANY(:active_statuses)
    
    UNION ALL
    
    -- Total INFINITY activos
    SELECT 
        'infinity_total' as metric,
        COUNT(DISTINCT subscriber_email) as value
    FROM subscriptions 
    WHERE product_id = ANY(:infinity_product_ids) 
      AND status = ANY(:active_statuses2)
    
    UNION ALL
    
    -- ALL ACCESS que también tienen INFINITY (convertidos)
    SELECT 
        'all_access_converted' as metric,
        COUNT(DISTINCT aa.subscriber_email) as value
    FROM subscriptions aa
    WHERE aa.product_id = :all_access_product_id 
      AND aa.status = ANY(:active_statuses)
      AND aa.subscriber_email IN (
          SELECT DISTINCT subscriber_email 
          FROM subscriptions 
          WHERE product_id = ANY(:infinity_product_ids) 
            AND status = ANY(:active_statuses2)
      )
    ";
    
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute($params);
    $stats_raw = $stats_stmt->fetchAll();
    
    // Procesar estadísticas
    $stats = [];
    foreach ($stats_raw as $stat) {
        $stats[$stat['metric']] = (int)$stat['value'];
    }
    
    // Calcular métricas CORRECTAS
    $opportunities = count($results); // ALL ACCESS sin INFINITY
    $total_all_access = $stats['all_access_total'] ?? 0;
    $converted = $stats['all_access_converted'] ?? 0; // ALL ACCESS que SÍ tienen INFINITY
    $conversion_rate = $total_all_access > 0 ? round(($converted / $total_all_access) * 100, 1) : 0;
    
    // Respuesta JSON
    $response = [
        'success' => true,
        'timestamp' => date('c'), // ISO 8601
        'stats' => [
            'opportunities' => $opportunities, // ALL ACCESS sin INFINITY (targets)
            'total_all_access' => $total_all_access, // Total ALL ACCESS activos
            'converted' => $converted, // ALL ACCESS que SÍ tienen INFINITY
            'conversion_rate' => $conversion_rate // % de ALL ACCESS convertidos a INFINITY
        ],
        'data' => $results,
        'meta' => [
            'all_access_product_id' => ALL_ACCESS_PRODUCT_ID,
            'infinity_product_ids' => INFINITY_PRODUCT_IDS,
            'active_statuses' => ACTIVE_STATUSES,
            'timezone' => TIMEZONE
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Error handling
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Log del error (para debug)
    error_log("Dashboard ALL ACCESS → INFINITY ERROR: " . $e->getMessage());
}
?>
