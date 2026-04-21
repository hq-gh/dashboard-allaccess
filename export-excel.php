<?php
// ========================================
// EXPORT EXCEL CSV - Descarga datos
// ========================================

session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo "No autorizado";
    exit;
}

require_once 'config.php';

// Headers para descarga CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="usuarios_allaccess_sin_infinity_' . date('Y-m-d_H-i') . '.csv"');

try {
    $pdo = getDBConnection();
    
    // Query principal
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
    
    // BOM para UTF-8 en Excel
    echo "\xEF\xBB\xBF";
    
    // Headers CSV
    echo "Nombre,Email,País,Teléfono,Código Transacción,Fecha/Hora,Plan,Precio,Moneda\n";
    
    // Datos
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = str_replace(['"', ','], ['""', ';'], $row['name'] ?? '');
        $email = $row['email'] ?? '';
        $country = $row['country'] ?? '';
        $phone = $row['phone'] ?? '';
        $codigo = $row['codigo_transaccion'] ?? '';
        $fecha = $row['fecha_hora'] ? date('Y-m-d H:i:s', strtotime($row['fecha_hora'])) : '';
        $plan = str_replace(['"', ','], ['""', ';'], $row['plan'] ?? '');
        $precio = $row['precio'] ?? '';
        $moneda = $row['moneda'] ?? '';
        
        echo "\"{$name}\",\"{$email}\",\"{$country}\",\"{$phone}\",\"{$codigo}\",\"{$fecha}\",\"{$plan}\",\"{$precio}\",\"{$moneda}\"\n";
    }
    
} catch (Exception $e) {
    echo "Error," . $e->getMessage() . "\n";
}
?>
