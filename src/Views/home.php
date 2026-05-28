<?php use App\Security; ?>
<h1 class="page-title">Bienvenido, <?= Security::e($user['name']) ?></h1>
<p class="subtitle">Selecciona una sección:</p>

<div class="cards-grid">
    <a href="/pecadores" class="card">
        <div class="icon">◆</div>
        <h2>Verificador Pecadores</h2>
        <p>Suscriptores activos a Infinity VIP que aún no tienen INFINITY. Oportunidades de conversión.</p>
        <span class="badge">Activo</span>
    </a>

    <a href="/vip" class="card">
        <div class="icon">◆</div>
        <h2>Dashboard VIP</h2>
        <p>Estado del proceso de reconciliación entre Hotmart Infinity VIP y el espacio VIP de Bettermode.</p>
        <span class="badge">Activo</span>
    </a>

    <a href="/estadisticas" class="card disabled">
        <div class="icon">◆</div>
        <h2>Estadísticas alumnos</h2>
        <p>Próximamente. Métricas agregadas de progreso, engagement y conversión por producto.</p>
        <span class="badge soon">Próximamente</span>
    </a>
</div>
