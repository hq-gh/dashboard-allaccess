<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class UsersRepo
{
    /** @return array<int, array<string,mixed>> */
    public function listAll(): array
    {
        return Database::get()->query(
            "SELECT id, name, email, role, created_at, updated_at, last_login_at
               FROM public.success_users ORDER BY role DESC, name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $st = Database::get()->prepare(
            "SELECT id, name, email, role, created_at, updated_at, last_login_at
               FROM public.success_users WHERE id = :id LIMIT 1"
        );
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** @return array{id:int,name:string,email:string}|null */
    public function findByEmail(string $email): ?array
    {
        $st = Database::get()->prepare(
            "SELECT id, name, email FROM public.success_users WHERE LOWER(email) = LOWER(:e) LIMIT 1"
        );
        $st->execute([':e' => $email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function existsByEmail(string $email): bool
    {
        $st = Database::get()->prepare("SELECT 1 FROM public.success_users WHERE LOWER(email) = LOWER(:e) LIMIT 1");
        $st->execute([':e' => $email]);
        return (bool) $st->fetchColumn();
    }

    /** @return int id del nuevo usuario */
    public function create(string $name, string $email, string $role, string $passwordHash): int
    {
        $st = Database::get()->prepare(
            "INSERT INTO public.success_users (name, email, role, password_hash)
             VALUES (:n, LOWER(:e), :r, :ph)
             RETURNING id"
        );
        $st->execute([':n' => $name, ':e' => $email, ':r' => $role, ':ph' => $passwordHash]);
        return (int) $st->fetchColumn();
    }

    public function update(int $id, string $name, string $role): void
    {
        $st = Database::get()->prepare("UPDATE public.success_users SET name = :n, role = :r WHERE id = :id");
        $st->execute([':n' => $name, ':r' => $role, ':id' => $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $st = Database::get()->prepare("UPDATE public.success_users SET password_hash = :ph WHERE id = :id");
        $st->execute([':ph' => $passwordHash, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $st = Database::get()->prepare("DELETE FROM public.success_users WHERE id = :id");
        $st->execute([':id' => $id]);
    }

    public function countAdmins(): int
    {
        return (int) Database::get()->query("SELECT COUNT(*) FROM public.success_users WHERE role = 'administrador'")->fetchColumn();
    }
}
