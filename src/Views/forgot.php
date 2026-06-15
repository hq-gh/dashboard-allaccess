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
        <p class="subtitle">Recuperar contraseña</p>

        <?php if (!empty($flash) && ($flash['type'] ?? '') === 'ok'): ?>
            <div class="alert" style="margin-top:20px;background:#163a1f;color:#9ff5b0;border:1px solid #2c6b3a;padding:10px 12px;border-radius:6px"><?= Security::e($flash['msg']) ?></div>
        <?php elseif (!empty($flash)): ?>
            <div class="alert alert-error" style="margin-top:20px"><?= Security::e($flash['msg']) ?></div>
        <?php endif; ?>

        <form method="POST" action="/forgot" autocomplete="on" style="margin-top:14px">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
            <div class="form-group">
                <label for="email">Tu correo</label>
                <input id="email" type="email" name="email" required autocomplete="username">
            </div>
            <button type="submit" class="btn" style="width:100%; margin-top:18px">Enviarme el link</button>
        </form>
        <p style="text-align:center;margin-top:16px;font-size:13px">
            <a href="/login" style="color:#A8A8B3">← Volver a iniciar sesión</a>
        </p>
    </div>
</div>
</body>
</html>
