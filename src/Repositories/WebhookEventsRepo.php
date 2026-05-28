<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class WebhookEventsRepo
{
    /**
     * Inserta evento (con UNIQUE dedup_key). Si el dedup_key ya existe, no inserta y devuelve 0.
     * @return int id insertado (0 si dedup hit).
     */
    public function insert(array $row): int
    {
        $stmt = Database::get()->prepare(
            "INSERT INTO public.infinity_webhook_events
                (event_type, hotmart_product_id, product_key, email, member_id, transaction_id,
                 action_taken, spaces_ok, spaces_failed, status, message, payload_json, dedup_key)
             VALUES
                (:event_type, :hpid, :pk, :email, :member_id, :tx,
                 :action, :ok, :failed, :status, :message, :payload, :dedup)
             ON CONFLICT (dedup_key) DO NOTHING
             RETURNING id"
        );
        $stmt->execute([
            ':event_type' => $row['event_type'] ?? '',
            ':hpid'       => $row['hotmart_product_id'] ?? null,
            ':pk'         => $row['product_key'] ?? null,
            ':email'      => $row['email'] ?? null,
            ':member_id'  => $row['member_id'] ?? null,
            ':tx'         => $row['transaction_id'] ?? null,
            ':action'     => $row['action_taken'] ?? 'ignored',
            ':ok'         => $row['spaces_ok'] ?? 0,
            ':failed'     => $row['spaces_failed'] ?? 0,
            ':status'     => $row['status'] ?? 'failed',
            ':message'    => $row['message'] ?? null,
            ':payload'    => isset($row['payload_json']) ? json_encode($row['payload_json'], JSON_UNESCAPED_UNICODE) : null,
            ':dedup'      => $row['dedup_key'] ?? null,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : 0;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listRecent(array $filters = [], int $limit = 500): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['email'])) {
            $where[] = 'email ILIKE :email';
            $params[':email'] = '%' . trim((string) $filters['email']) . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = :evt';
            $params[':evt'] = (string) $filters['event_type'];
        }
        if (!empty($filters['product_key'])) {
            $where[] = 'product_key = :pk';
            $params[':pk'] = (string) $filters['product_key'];
        }
        if (!empty($filters['desde'])) {
            $where[] = 'received_at >= :desde';
            $params[':desde'] = (string) $filters['desde'];
        }
        if (!empty($filters['hasta'])) {
            $where[] = 'received_at < ((:hasta)::date + INTERVAL \'1 day\')';
            $params[':hasta'] = (string) $filters['hasta'];
        }
        $sql = "SELECT id, received_at, event_type, hotmart_product_id, product_key,
                       email, member_id, transaction_id, action_taken,
                       spaces_ok, spaces_failed, status, message
                  FROM public.infinity_webhook_events"
             . (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where))
             . ' ORDER BY received_at DESC LIMIT ' . (int) $limit;
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
