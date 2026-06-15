<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Database;
use App\Mailer;
use App\Repositories\UsersRepo;
use App\Security;
use App\View;
use PDO;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            header('Location: /', true, 302);
            return;
        }
        Security::startSession();
        $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
        $locked = $lockedUntil > time();
        $remainingMin = $locked ? (int) ceil(($lockedUntil - time()) / 60) : 0;

        View::render('login', [
            'title'        => 'Iniciar sesión',
            'csrf'         => Security::csrfToken(),
            'error'        => $_SESSION['login_flash_error'] ?? null,
            'success'      => $_SESSION['login_flash_success'] ?? null,
            'locked'       => $locked,
            'remainingMin' => $remainingMin,
        ], false);
        unset($_SESSION['login_flash_error'], $_SESSION['login_flash_success']);
    }

    public function doLogin(): void
    {
        Security::startSession();
        if (!Security::csrfValidate($_POST['csrf_token'] ?? null)) {
            $_SESSION['login_flash_error'] = 'Token de seguridad inválido. Recarga la página.';
            header('Location: /login', true, 302);
            return;
        }

        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::attempt($email, $password)) {
            header('Location: /', true, 302);
            return;
        }

        $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
        $remaining = Security::LOGIN_MAX_ATTEMPTS - $attempts;
        if ($remaining <= 0) {
            $_SESSION['login_flash_error'] = 'Demasiados intentos fallidos. Espera 15 minutos.';
        } else {
            $_SESSION['login_flash_error'] = "Email o contraseña incorrectos. Te quedan {$remaining} intento(s).";
        }
        header('Location: /login', true, 302);
    }

    public function doLogout(): void
    {
        Security::startSession();
        if (!Security::csrfValidate($_POST['csrf_token'] ?? null)) {
            header('Location: /', true, 302);
            return;
        }
        Auth::logout();
        header('Location: /login', true, 302);
    }

    // --- Recuperación de contraseña ---

    public function showForgot(): void
    {
        if (Auth::check()) { header('Location: /', true, 302); return; }
        Security::startSession();
        View::render('forgot', [
            'title' => 'Recuperar contraseña',
            'csrf'  => Security::csrfToken(),
            'flash' => $_SESSION['forgot_flash'] ?? null,
        ], false);
        unset($_SESSION['forgot_flash']);
    }

    public function doForgot(): void
    {
        Security::startSession();
        if (!Security::csrfValidate($_POST['csrf_token'] ?? null)) {
            $_SESSION['forgot_flash'] = ['type' => 'error', 'msg' => 'Token de seguridad inválido. Recarga la página.'];
            header('Location: /forgot', true, 302);
            return;
        }
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        // Respuesta SIEMPRE genérica (evita enumeración de correos).
        $generic = 'Si el correo existe, te enviaremos un link para restablecer tu contraseña.';

        if ($email !== '') {
            $user = (new UsersRepo())->findByEmail($email);
            if ($user) {
                $token = bin2hex(random_bytes(32));
                $db = Database::get();
                $db->prepare("UPDATE public.success_password_resets SET used = TRUE WHERE user_id = :u AND used = FALSE")
                   ->execute([':u' => (int) $user['id']]);
                $db->prepare("INSERT INTO public.success_password_resets (user_id, token, expires_at) VALUES (:u, :t, NOW() + INTERVAL '1 hour')")
                   ->execute([':u' => (int) $user['id'], ':t' => $token]);
                $base = rtrim((string) Config::get('APP_URL', 'https://rw2.5t4d10.com'), '/');
                Mailer::sendPasswordReset((string) $user['email'], (string) $user['name'], $base . '/reset?token=' . $token);
            }
        }
        $_SESSION['forgot_flash'] = ['type' => 'ok', 'msg' => $generic];
        header('Location: /forgot', true, 302);
    }

    public function showReset(): void
    {
        if (Auth::check()) { header('Location: /', true, 302); return; }
        Security::startSession();
        $token = (string) ($_GET['token'] ?? '');
        $valid = $token !== '' && $this->resetUserId($token) !== null;
        View::render('reset', [
            'title' => 'Nueva contraseña',
            'csrf'  => Security::csrfToken(),
            'token' => $token,
            'valid' => $valid,
            'flash' => $_SESSION['reset_flash'] ?? null,
        ], false);
        unset($_SESSION['reset_flash']);
    }

    public function doReset(): void
    {
        Security::startSession();
        $token = (string) ($_POST['token'] ?? '');
        if (!Security::csrfValidate($_POST['csrf_token'] ?? null)) {
            $_SESSION['reset_flash'] = ['type' => 'error', 'msg' => 'Token de seguridad inválido. Recarga la página.'];
            header('Location: /reset?token=' . urlencode($token), true, 302);
            return;
        }
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm'] ?? '');
        $err = null;
        if ($password === '' || $confirm === '') $err = 'Completa ambos campos.';
        elseif ($password !== $confirm)          $err = 'Las contraseñas no coinciden.';
        elseif (strlen($password) < 8)           $err = 'La contraseña debe tener al menos 8 caracteres.';

        $userId = $this->resetUserId($token);
        if ($userId === null) $err = 'El link es inválido o ya expiró. Solicita uno nuevo.';

        if ($err !== null) {
            $_SESSION['reset_flash'] = ['type' => 'error', 'msg' => $err];
            header('Location: /reset?token=' . urlencode($token), true, 302);
            return;
        }

        (new UsersRepo())->updatePassword($userId, password_hash($password, PASSWORD_BCRYPT));
        Database::get()->prepare("UPDATE public.success_password_resets SET used = TRUE WHERE token = :t")
            ->execute([':t' => $token]);

        $_SESSION['login_flash_success'] = 'Contraseña actualizada. Ya puedes iniciar sesión.';
        header('Location: /login', true, 302);
    }

    /** @return int|null user_id si el token es válido (existe, no usado, no expirado). */
    private function resetUserId(string $token): ?int
    {
        if ($token === '') return null;
        $st = Database::get()->prepare(
            "SELECT user_id FROM public.success_password_resets
              WHERE token = :t AND used = FALSE AND expires_at > NOW() LIMIT 1"
        );
        $st->execute([':t' => $token]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
