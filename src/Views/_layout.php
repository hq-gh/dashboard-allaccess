<?php
/**
 * Layout base. Variables esperadas:
 *   $title (string)     — title del <title> y <h1>
 *   $active (string)    — slug del menú activo: 'home','pecadores','vip','estadisticas'
 *   $content (string)   — HTML del cuerpo (ya escapado donde corresponde)
 *
 * Las views renderizan $content como string y lo pasan a este layout.
 */
use App\Auth;
use App\Security;
$user = Auth::user();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Security::e($title ?? 'Portal 5T4D10') ?></title>
<link rel="stylesheet" href="/css/app.css">
</head>
<body>
<header class="topbar">
    <a href="/" class="brand" style="text-decoration:none">
        <span class="digit">5</span><span class="letter">T</span><span class="digit">4</span><span class="letter">D</span><span class="digit">10</span>
    </a>
    <nav class="menu">
        <a href="/"           class="<?= ($active ?? '')==='home' ? 'active':'' ?>">Inicio</a>
        <a href="/pecadores"  class="<?= ($active ?? '')==='pecadores' ? 'active':'' ?>">Pecadores</a>
        <a href="/vip"        class="<?= ($active ?? '')==='vip' ? 'active':'' ?>">Dashboard VIP</a>
        <a href="/estadisticas" class="<?= ($active ?? '')==='estadisticas' ? 'active':'' ?>">Estadísticas</a>
        <?php if ($user): ?>
            <span class="user-info"><?= Security::e($user['name']) ?> · <span class="role"><?= Security::e($user['role']) ?></span></span>
            <form method="POST" action="/logout" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= Security::e(Security::csrfToken()) ?>">
                <button type="submit" class="logout">Salir</button>
            </form>
        <?php endif; ?>
    </nav>
</header>
<main>
<?= $content ?>
</main>
</body>
</html>
