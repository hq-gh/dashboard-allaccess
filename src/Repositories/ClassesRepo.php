<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

/**
 * Mapeo (subdomain, class_id) -> class_name humano.
 * El nombre se llena manualmente desde /admin/classes (la API de Hotmart
 * no lo expone vía endpoint público).
 */
final class ClassesRepo
{
    /**
     * Lista todas las classes con el conteo de alumnos. Ordenado por
     * cuántos alumnos tiene (DESC) para que se llenen primero los importantes.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listAllWithCounts(?string $filterSubdomain = null, bool $onlyMissing = false): array
    {
        $where = [];
        $params = [];
        if ($filterSubdomain !== null && $filterSubdomain !== '') {
            $where[] = 'c.subdomain = :sd';
            $params[':sd'] = $filterSubdomain;
        }
        if ($onlyMissing) {
            $where[] = '(c.class_name IS NULL OR c.class_name = \'\')';
        }
        $sql = "SELECT c.id, c.subdomain, c.class_id, c.class_name, c.is_active, c.updated_at,
                       COUNT(s.user_id) AS alumnos
                  FROM public.bettermode_classes c
             LEFT JOIN public.club_students s
                    ON s.subdomain = c.subdomain AND s.class_id = c.class_id"
             . (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where))
             . " GROUP BY c.id, c.subdomain, c.class_id, c.class_name, c.is_active, c.updated_at"
             . " ORDER BY alumnos DESC, c.subdomain, c.class_id";
        $st = Database::get()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function update(int $id, ?string $className, bool $isActive): void
    {
        $st = Database::get()->prepare(
            "UPDATE public.bettermode_classes SET class_name = :n, is_active = :a WHERE id = :id"
        );
        $st->execute([':n' => ($className === '' ? null : $className), ':a' => $isActive, ':id' => $id]);
    }

    /** @return array<int, string> */
    public function listSubdomains(): array
    {
        return Database::get()->query(
            "SELECT DISTINCT subdomain FROM public.bettermode_classes ORDER BY subdomain"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Resuelve (subdomain, class_id) -> class_name. Devuelve null si no hay nombre.
     */
    public function resolve(string $subdomain, string $classId): ?string
    {
        $st = Database::get()->prepare(
            "SELECT class_name FROM public.bettermode_classes
              WHERE subdomain = :sd AND class_id = :cid AND is_active = TRUE LIMIT 1"
        );
        $st->execute([':sd' => $subdomain, ':cid' => $classId]);
        $name = $st->fetchColumn();
        return ($name !== false && $name !== null && $name !== '') ? (string) $name : null;
    }
}
