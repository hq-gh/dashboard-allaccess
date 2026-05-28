<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

/**
 * Pecadores = suscriptores ACTIVOS a Infinity VIP (product 6587403) que NO
 * tienen ningún INFINITY (products 6454766 / 7065704 / 6952229) ACTIVO.
 *
 * Stats: total Infinity VIP activos, convertidos (con INFINITY), pecadores,
 * tasa de conversión.
 */
final class PecadoresRepo
{
    private const PRODUCT_VIP      = '6587403';
    private const PRODUCT_INFINITY = ['6454766', '7065704', '6952229'];

    /** Columnas permitidas para sort (alias del SELECT) y su SQL real. */
    private const SORT_MAP = [
        'name'    => 's.subscriber_name',
        'email'   => 's.subscriber_email',
        'country' => 'sp.buyer_country',
        'fecha'   => 's.request_date',
        'plan'    => 's.plan_name',
    ];

    /**
     * @return array{list:array<int,array<string,mixed>>, sort:string, dir:string}
     */
    public function list(string $sort = 'name', string $dir = 'asc'): array
    {
        $sortKey = array_key_exists($sort, self::SORT_MAP) ? $sort : 'name';
        $dirNorm = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $sortCol = self::SORT_MAP[$sortKey];

        $placeholders = implode(',', array_fill(0, count(self::PRODUCT_INFINITY), '?'));
        $sql = "
            SELECT DISTINCT
                s.subscriber_name  AS name,
                s.subscriber_email AS email,
                sp.buyer_country   AS country,
                sp.buyer_phone     AS phone,
                s.transaction_id   AS codigo_transaccion,
                s.request_date     AS request_date_ms,
                TO_TIMESTAMP(s.request_date / 1000) AS fecha_hora,
                s.plan_name        AS plan,
                s.price_value      AS precio,
                s.price_currency   AS moneda
            FROM public.subscriptions s
            INNER JOIN public.sales_participants sp ON s.transaction_id = sp.transaction_id
            WHERE s.product_id = ?
              AND s.status = 'ACTIVE'
              AND s.subscriber_ucode NOT IN (
                  SELECT DISTINCT s2.subscriber_ucode
                    FROM public.subscriptions s2
                   WHERE s2.product_id IN ($placeholders)
                     AND s2.status = 'ACTIVE'
                     AND s2.subscriber_ucode IS NOT NULL
              )
            ORDER BY $sortCol $dirNorm NULLS LAST
        ";
        $stmt = Database::get()->prepare($sql);
        $params = array_merge([self::PRODUCT_VIP], self::PRODUCT_INFINITY);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['list' => $rows, 'sort' => $sortKey, 'dir' => $dirNorm === 'DESC' ? 'desc' : 'asc'];
    }

    /**
     * @return array{total_vip:int, convertidos:int, pecadores:int, tasa:float}
     */
    public function stats(): array
    {
        $pdo = Database::get();

        $totalQ = $pdo->prepare(
            "SELECT COUNT(DISTINCT s.subscriber_ucode) c
               FROM public.subscriptions s
               INNER JOIN public.sales_participants sp ON s.transaction_id = sp.transaction_id
              WHERE s.product_id = ? AND s.status = 'ACTIVE'"
        );
        $totalQ->execute([self::PRODUCT_VIP]);
        $total = (int) $totalQ->fetchColumn();

        $placeholders = implode(',', array_fill(0, count(self::PRODUCT_INFINITY), '?'));
        $convQ = $pdo->prepare(
            "SELECT COUNT(DISTINCT s1.subscriber_ucode) c
               FROM public.subscriptions s1
               INNER JOIN public.sales_participants sp1 ON s1.transaction_id = sp1.transaction_id
              WHERE s1.product_id = ?
                AND s1.status = 'ACTIVE'
                AND s1.subscriber_ucode IN (
                    SELECT DISTINCT s2.subscriber_ucode
                      FROM public.subscriptions s2
                     WHERE s2.product_id IN ($placeholders)
                       AND s2.status = 'ACTIVE'
                       AND s2.subscriber_ucode IS NOT NULL
                )"
        );
        $convQ->execute(array_merge([self::PRODUCT_VIP], self::PRODUCT_INFINITY));
        $converted = (int) $convQ->fetchColumn();

        $pecadores = max(0, $total - $converted);
        $tasa = $total > 0 ? round(($converted / $total) * 100, 1) : 0.0;

        return [
            'total_vip'   => $total,
            'convertidos' => $converted,
            'pecadores'   => $pecadores,
            'tasa'        => $tasa,
        ];
    }
}
