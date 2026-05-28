<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class ProductKeysConfigRepo
{
    /**
     * @return array{product_key:string, member_field_key:?string, description:?string, is_active:bool}|null
     */
    public function findByKey(string $productKey): ?array
    {
        $stmt = Database::get()->prepare(
            "SELECT product_key, member_field_key, description, is_active
               FROM public.product_keys_config
              WHERE product_key = :pk AND is_active = TRUE
              LIMIT 1"
        );
        $stmt->execute([':pk' => $productKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
