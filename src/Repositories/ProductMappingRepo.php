<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class ProductMappingRepo
{
    /**
     * Devuelve el mapping activo de un product_id de Hotmart, o null si no está mapeado / inactivo.
     * @return array{product_key:string, product_name:?string}|null
     */
    public function findByHotmartProductId(string $hotmartProductId): ?array
    {
        $stmt = Database::get()->prepare(
            "SELECT product_key, product_name
               FROM public.hotmart_product_mapping
              WHERE hotmart_product_id = :pid AND is_active = TRUE
              LIMIT 1"
        );
        $stmt->execute([':pid' => $hotmartProductId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int, array{hotmart_product_id:string, product_key:string, product_name:?string, is_active:bool}> */
    public function listAll(): array
    {
        $stmt = Database::get()->query(
            "SELECT hotmart_product_id, product_key, product_name, is_active
               FROM public.hotmart_product_mapping
              ORDER BY product_key, hotmart_product_id"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function upsert(string $hotmartProductId, string $productKey, ?string $productName, bool $isActive = true): void
    {
        $stmt = Database::get()->prepare(
            "INSERT INTO public.hotmart_product_mapping (hotmart_product_id, product_key, product_name, is_active)
             VALUES (:pid, :pk, :pn, :ia)
             ON CONFLICT (hotmart_product_id) DO UPDATE SET
                product_key  = EXCLUDED.product_key,
                product_name = EXCLUDED.product_name,
                is_active    = EXCLUDED.is_active"
        );
        $stmt->execute([':pid' => $hotmartProductId, ':pk' => $productKey, ':pn' => $productName, ':ia' => $isActive]);
    }

    public function delete(string $hotmartProductId): void
    {
        $stmt = Database::get()->prepare("DELETE FROM public.hotmart_product_mapping WHERE hotmart_product_id = :pid");
        $stmt->execute([':pid' => $hotmartProductId]);
    }
}
