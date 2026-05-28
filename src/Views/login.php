<?php use App\Security; ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Security::e($title) ?> · Portal 5T4D10</title>
<link rel="stylesheet" href="/css/app.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-box">
        <div style="text-align:center;margin-bottom:8px">
            <img src="/img/5t4d10-logo.png" alt="5T4D10" style="max-width:160px;height:auto">
        </div>
        <p class="subtitle">Portal interno</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="margin-top:20px"><?= Security::e($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($locked)): ?>
            <div class="alert alert-error" style="margin-top:20px">Bloqueado por intentos. Espera <?= (int)$remainingMin ?> min.</div>
        <?php endif; ?>

        <form method="POST" action="/login" autocomplete="on" style="margin-top:14px">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" required autocomplete="username" <?= !empty($locked)?'disabled':'' ?>>
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" required autocomplete="current-password" <?= !empty($locked)?'disabled':'' ?>>
            </div>
            <button type="submit" class="btn" style="width:100%; margin-top:18px" <?= !empty($locked)?'disabled':'' ?>>Iniciar sesión</button>
        </form>
    </div>
</div>
</body>
</html>
