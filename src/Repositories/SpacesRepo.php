<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class SpacesRepo
{
    /**
     * @return array<int, array{space_id:string, space_name:string}>
     */
    public function listActiveForProductKey(string $productKey): array
    {
        $stmt = Database::get()->prepare(
            "SELECT space_id, space_name
               FROM public.bettermode_spaces
              WHERE product_key = :pk AND is_active = TRUE
              ORDER BY sort_order, space_name"
        );
        $stmt->execute([':pk' => $productKey]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string,mixed>> */
    public function listAll(): array
    {
        $stmt = Database::get()->query(
            "SELECT id, product_key, space_id, space_name, is_active, sort_order, updated_at
               FROM public.bettermode_spaces
              ORDER BY product_key, sort_order, space_name"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(string $productKey, string $spaceId, string $spaceName, int $sortOrder = 0, bool $isActive = true): void
    {
        $stmt = Database::get()->prepare(
            "INSERT INTO public.bettermode_spaces (product_key, space_id, space_name, sort_order, is_active)
             VALUES (:pk, :sid, :sn, :so, :ia)
             ON CONFLICT (product_key, space_id) DO UPDATE SET
                space_name = EXCLUDED.space_name,
                sort_order = EXCLUDED.sort_order,
                is_active  = EXCLUDED.is_active"
        );
        $stmt->execute([':pk' => $productKey, ':sid' => $spaceId, ':sn' => $spaceName, ':so' => $sortOrder, ':ia' => $isActive]);
    }

    public function update(int $id, string $spaceName, int $sortOrder, bool $isActive): void
    {
        $stmt = Database::get()->prepare(
            "UPDATE public.bettermode_spaces SET space_name=:sn, sort_order=:so, is_active=:ia WHERE id=:id"
        );
        $stmt->execute([':sn' => $spaceName, ':so' => $sortOrder, ':ia' => $isActive, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::get()->prepare("DELETE FROM public.bettermode_spaces WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}
