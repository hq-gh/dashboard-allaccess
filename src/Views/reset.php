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
        <p class="subtitle">Nueva contraseña</p>

        <?php if (!empty($flash)): ?>
            <div class="alert alert-error" style="margin-top:20px"><?= Security::e($flash['msg']) ?></div>
        <?php endif; ?>

        <?php if (empty($valid)): ?>
            <div class="alert alert-error" style="margin-top:20px">El link es inválido o ya expiró.</div>
            <p style="text-align:center;margin-top:16px;font-size:13px">
                <a href="/forgot" style="color:#A8A8B3">Solicitar uno nuevo</a>
            </p>
        <?php else: ?>
            <form method="POST" action="/reset" autocomplete="off" style="margin-top:14px">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrf) ?>">
                <input type="hidden" name="token" value="<?= Security::e($token) ?>">
                <div class="form-group">
                    <label for="password">Nueva contraseña</label>
                    <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="confirm">Confirmar contraseña</label>
                    <input id="confirm" type="password" name="confirm" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn" style="width:100%; margin-top:18px">Guardar contraseña</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
