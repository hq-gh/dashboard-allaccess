<?php use App\Security; ?>
<p style="color:var(--text-3);font-size:.85rem;margin-bottom:10px">
    <a href="/estadisticas" style="color:var(--text-3);text-decoration:none">Alumnos</a>
</p>
<div class="empty-state" style="margin-top:24px">
    <div class="icon">⌬</div>
    <h2>Alumno no encontrado</h2>
    <p>No hay registros para <code><?= Security::e($email) ?></code> en <code>club_students</code>.</p>
    <a class="btn" href="/estadisticas" style="margin-top:16px">Volver al listado</a>
</div>
