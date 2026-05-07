<?php
// ========================================
// EXPORT CSV - Descarga datos para Excel
// ========================================
require_once 'config.php';

requireAuthApi();

/**
 * Escapa un campo según RFC 4180.
 * Si contiene comas, comillas, saltos o CR, se envuelve en comillas
 * y las comillas internas se duplican.
 */
function csvEscape($value) {
    $s = (string) ($value ?? '');
    if ($s === '') {
        return '""';
    }
    // Siempre envolvemos en comillas para máxima compatibilidad con Excel
    return '"' . str_replace('"', '""', $s) . '"';
}

try {
    $pdo = getDBConnection();

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

    // Headers para descarga
    $filename = 'usuarios_infinity_vip_sin_infinity_' . date('Y-m-d_H-i') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    // BOM para que Excel detecte UTF-8 correctamente
    echo "\xEF\xBB\xBF";

    // Headers CSV
    $headers = ['Nombre', 'Email', 'País', 'Teléfono', 'Código Transacción', 'Fecha/Hora', 'Plan', 'Precio', 'Moneda'];
    echo implode(',', array_map('csvEscape', $headers)) . "\r\n";

    // Filas
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fecha = !empty($row['fecha_hora'])
            ? date('Y-m-d H:i:s', strtotime($row['fecha_hora']))
            : '';

        $fields = [
            $row['name'] ?? '',
            $row['email'] ?? '',
            $row['country'] ?? '',
            $row['phone'] ?? '',
            $row['codigo_transaccion'] ?? '',
            $fecha,
            $row['plan'] ?? '',
            $row['precio'] ?? '',
            $row['moneda'] ?? '',
        ];

        echo implode(',', array_map('csvEscape', $fields)) . "\r\n";
    }

} catch (Exception $e) {
    error_log('[export-excel] Error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error generando exportación. Revisa los logs.';
}
