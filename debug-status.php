<?php
// ========================================
// DEBUG STATUS - Verificar status reales
// ========================================

require_once 'config.php';

try {
    $pdo = getDBConnection();
    
    echo "=== VERIFICACIÓN DE STATUS EN BASE DE DATOS ===\n\n";
    
    // 1. Status únicos en subscriptions
    echo "1. TODOS LOS STATUS ÚNICOS:\n";
    $stmt = $pdo->query("SELECT DISTINCT status, COUNT(*) as count FROM subscriptions GROUP BY status ORDER BY count DESC LIMIT 15");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   \"{$row['status']}\": {$row['count']} registros\n";
    }
    echo "\n";
    
    // 2. Status para ALL ACCESS (6587403)
    echo "2. STATUS PARA ALL ACCESS (Product ID 6587403):\n";
    $stmt = $pdo->prepare("SELECT DISTINCT status, COUNT(*) as count FROM subscriptions WHERE product_id = 6587403 GROUP BY status ORDER BY count DESC");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   \"{$row['status']}\": {$row['count']} registros\n";
    }
    echo "\n";
    
    // 3. Probar query actual (inglés)
    echo "3. USUARIOS CON STATUS INGLÉS (query actual):\n";
    $query_english = "
        SELECT COUNT(DISTINCT sp.participant_id) as count
        FROM subscriptions s
        INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
        WHERE s.product_id = 6587403
        AND s.status IN ('ACTIVE','APPROVED','COMPLETE')
        AND sp.participant_id NOT IN (
            SELECT DISTINCT sp2.participant_id
            FROM subscriptions s2
            INNER JOIN sales_participants sp2 ON s2.transaction_id = sp2.transaction_id
            WHERE s2.product_id IN (6454766, 7065704, 6952229)
            AND s2.status IN ('ACTIVE','APPROVED','COMPLETE')
        )
    ";
    $stmt = $pdo->prepare($query_english);
    $stmt->execute();
    $english_count = $stmt->fetchColumn();
    echo "   Encontrados: {$english_count} usuarios\n\n";
    
    // 4. Probar con status español
    echo "4. USUARIOS CON STATUS ESPAÑOL:\n";
    $query_spanish = "
        SELECT COUNT(DISTINCT sp.participant_id) as count
        FROM subscriptions s
        INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
        WHERE s.product_id = 6587403
        AND s.status IN ('Activo','Aprovado','Completo')
        AND sp.participant_id NOT IN (
            SELECT DISTINCT sp2.participant_id
            FROM subscriptions s2
            INNER JOIN sales_participants sp2 ON s2.transaction_id = sp2.transaction_id
            WHERE s2.product_id IN (6454766, 7065704, 6952229)
            AND s2.status IN ('Activo','Aprovado','Completo')
        )
    ";
    $stmt = $pdo->prepare($query_spanish);
    $stmt->execute();
    $spanish_count = $stmt->fetchColumn();
    echo "   Encontrados: {$spanish_count} usuarios\n\n";
    
    // 5. Muestra de usuarios reales
    echo "5. MUESTRA DE USUARIOS (primeros 10):\n";
    $sample_query = "
        SELECT DISTINCT
            sp.participant_name as name,
            sp.participant_email as email,
            s.status
        FROM subscriptions s
        INNER JOIN sales_participants sp ON s.transaction_id = sp.transaction_id
        WHERE s.product_id = 6587403
        AND sp.participant_id NOT IN (
            SELECT DISTINCT sp2.participant_id
            FROM subscriptions s2
            INNER JOIN sales_participants sp2 ON s2.transaction_id = sp2.transaction_id
            WHERE s2.product_id IN (6454766, 7065704, 6952229)
        )
        ORDER BY sp.participant_name
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sample_query);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   {$row['name']} | {$row['email']} | Status: \"{$row['status']}\"\n";
    }
    echo "\n";
    
    echo "=== RESUMEN ===\n";
    echo "Query inglés (actual): {$english_count} usuarios\n";
    echo "Query español: {$spanish_count} usuarios\n";
    echo "Total esperado: 29 usuarios\n\n";
    
    if ($english_count == 20 && $spanish_count == 9) {
        echo "✅ HIPÓTESIS CONFIRMADA: Status en inglés (20) + español (9) = 29 total\n";
    } else {
        echo "❌ HIPÓTESIS INCORRECTA: Necesitamos investigar más\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
