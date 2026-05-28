<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Security;
use App\View;

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
            'locked'       => $locked,
            'remainingMin' => $remainingMin,
        ], false);
        unset($_SESSION['login_flash_error']);
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
}
