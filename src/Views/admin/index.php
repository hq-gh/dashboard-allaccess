<h1 class="page-title">Administración</h1>
<p class="subtitle">Configuración del portal y del webhook. Solo administradores.</p>

<div class="cards-grid">
    <a href="/admin/usuarios" class="card">
        <div class="icon">◆</div>
        <h2>Usuarios</h2>
        <p>Alta, edición de rol, reset de contraseña y baja de usuarios del portal.</p>
        <span class="badge">Activo</span>
    </a>
    <a href="/admin/productos" class="card">
        <div class="icon">◆</div>
        <h2>Productos Hotmart</h2>
        <p>Mapeo de <code>product_id</code> de Hotmart → grupo (<code>infinity</code>, <code>mommy_comeback</code>, etc.). Webhooks de productos no mapeados se ignoran.</p>
        <span class="badge">Activo</span>
    </a>
    <a href="/admin/spaces" class="card">
        <div class="icon">◆</div>
        <h2>Spaces Bettermode</h2>
        <p>Espacios de Bettermode asignados por grupo. Editar esta lista cambia los spaces que el webhook agrega/quita sin redeploy.</p>
        <span class="badge">Activo</span>
    </a>
</div>
