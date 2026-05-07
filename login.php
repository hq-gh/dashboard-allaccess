<?php
// ========================================
// LOGIN - Dashboard 5T4D10
// ========================================
require_once 'config.php';

startSecureSession();
applySecurityHeaders();

$error = '';

// ---------- Rate limiting por sesión ----------
$attempts = $_SESSION['login_attempts'] ?? 0;
$lockedUntil = $_SESSION['login_locked_until'] ?? 0;

if ($lockedUntil > time()) {
    $remaining = (int) ceil(($lockedUntil - time()) / 60);
    $error = "Demasiados intentos. Intenta de nuevo en {$remaining} minutos.";
}

// ---------- Procesar POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    // Validar CSRF antes que nada
    $token = $_POST['csrf_token'] ?? '';
    if (!csrfValidate($token)) {
        error_log('[login] CSRF token inválido desde IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $error = 'Token de seguridad inválido. Recarga la página.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $validUsername = getenv('DASHBOARD_USER');
        $validPassword = getenv('DASHBOARD_PASS');

        if (!$validUsername || !$validPassword) {
            error_log('[login] DASHBOARD_USER o DASHBOARD_PASS no configurados en entorno');
            $error = 'Sistema no configurado. Contacta al administrador.';
        } else {
            // Comparación constant-time para ambos campos
            $userOk = hash_equals($validUsername, $username);
            $passOk = hash_equals($validPassword, $password);

            if ($userOk && $passOk) {
                // Login exitoso: regenerar ID, limpiar contadores
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                $_SESSION['username'] = $username;
                $_SESSION['login_time'] = time();
                unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);

                error_log('[login] Login exitoso: ' . $username);
                header('Location: index.php');
                exit;
            } else {
                $attempts++;
                $_SESSION['login_attempts'] = $attempts;

                if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                    $_SESSION['login_locked_until'] = time() + LOGIN_LOCKOUT_SECONDS;
                    error_log("[login] Bloqueo por {$attempts} intentos fallidos. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                    $error = 'Demasiados intentos fallidos. Espera 15 minutos.';
                } else {
                    $remaining = LOGIN_MAX_ATTEMPTS - $attempts;
                    $error = "Usuario o contraseña incorrectos. Te quedan {$remaining} intento(s).";
                    error_log("[login] Intento fallido #{$attempts} para usuario: {$username}");
                }
            }
        }
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Dashboard 5T4D10</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 3rem;
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .logo { margin-bottom: 1rem; }
        .subtitle { color: #999; margin-bottom: 2rem; }

        .form-group { margin-bottom: 1.5rem; text-align: left; }

        label {
            display: block;
            color: #cccccc;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            color: #ffffff;
            font-size: 16px;
        }

        input[type="text"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #FF6687;
            box-shadow: 0 0 0 2px rgba(255, 102, 135, 0.3);
        }

        .login-btn {
            width: 100%;
            background: linear-gradient(45deg, #FF6687, #FF4567);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }

        .login-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 102, 135, 0.4);
        }

        .login-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .error {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid #dc3545;
            border-radius: 6px;
            padding: 0.75rem;
            color: #ff6b6b;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="https://github.com/hq-gh/dashboard-allaccess/blob/main/5T4D10%20logo.png?raw=true"
                 alt="5T4D10 Logo"
                 style="width: 150px; height: auto; filter: brightness(1.1);"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <div style="display:none; color: #FF6687; font-size: 2.5rem; font-weight: 700;">5T4D10</div>
        </div>
        <p class="subtitle">Dashboard Infinity VIP → INFINITY</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="username">Usuario:</label>
                <input type="text" id="username" name="username" required autocomplete="username"
                       <?= $lockedUntil > time() ? 'disabled' : '' ?>>
            </div>

            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required autocomplete="current-password"
                       <?= $lockedUntil > time() ? 'disabled' : '' ?>>
            </div>

            <button type="submit" class="login-btn" <?= $lockedUntil > time() ? 'disabled' : '' ?>>
                Iniciar Sesión
            </button>
        </form>
    </div>
</body>
</html>
