<?php declare(strict_types=1);

namespace App;

use PDO;

/**
 * Auth multi-user contra public.users (tabla compartida con dashboard principal).
 *
 * Validaciones:
 *  - password_verify constant-time
 *  - sesion regenerate_id post-login
 *  - rate limit por sesion (5 intentos / 15 min)
 *  - last_login_at updated en login OK
 *
 * Roles soportados: 'administrador', 'usuario'.
 */
final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        Security::startSession();
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') return false;

        // rate limit por sesion
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
        $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            return false;
        }

        $stmt = Database::get()->prepare(
            "SELECT id, name, email, role, password_hash
               FROM public.users
              WHERE LOWER(email) = :email
              LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            $attempts++;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= Security::LOGIN_MAX_ATTEMPTS) {
                $_SESSION['login_locked_until'] = time() + Security::LOGIN_LOCKOUT_SECONDS;
                error_log('[auth] lockout sesion tras ' . $attempts . ' intentos');
            }
            return false;
        }

        // login OK
        session_regenerate_id(true);
        $_SESSION['user_id']     = (int) $row['id'];
        $_SESSION['user_name']   = (string) $row['name'];
        $_SESSION['user_email']  = (string) $row['email'];
        $_SESSION['user_role']   = (string) $row['role'];
        $_SESSION['logged_in_at'] = time();
        unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);

        Database::get()->prepare("UPDATE public.users SET last_login_at = NOW() WHERE id = :id")
            ->execute([':id' => $row['id']]);

        error_log('[auth] login OK email=' . $row['email'] . ' role=' . $row['role']);
        return true;
    }

    public static function check(): bool
    {
        Security::startSession();
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) return null;
        return [
            'id'    => (int) $_SESSION['user_id'],
            'name'  => (string) ($_SESSION['user_name']  ?? ''),
            'email' => (string) ($_SESSION['user_email'] ?? ''),
            'role'  => (string) ($_SESSION['user_role']  ?? 'usuario'),
        ];
    }

    public static function isAdmin(): bool
    {
        return self::check() && ($_SESSION['user_role'] ?? '') === 'administrador';
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login', true, 302);
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            echo '<h1>403 — Acceso restringido a administradores</h1>';
            exit;
        }
    }

    public static function logout(): void
    {
        Security::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
