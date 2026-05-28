<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));
?>
<h1 class="page-title">Admin · Spaces Bettermode</h1>
<p class="subtitle">Mapeo <code>product_key</code> → spaces. Estos son los espacios a los que el webhook agrega/quita miembros. Solo administradores pueden editar.</p>

<?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type']==='ok' ? 'alert-ok' : 'alert-error' ?>"><?= $e($flash['msg']) ?></div>
<?php endif; ?>

<h2 style="margin-top:18px;margin-bottom:10px;font-size:1.05rem">Agregar / actualizar space</h2>
<form method="POST" action="/admin/spaces/create" style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="form-group" style="margin:0"><label>product_key</label>
        <input list="pkList" name="product_key" required>
        <datalist id="pkList"><option value="infinity"><option value="infinity_vip"></datalist>
    </div>
    <div class="form-group" style="margin:0"><label>space_id</label><input name="space_id" required></div>
    <div class="form-group" style="margin:0"><label>space_name</label><input name="space_name" required></div>
    <div class="form-group" style="margin:0"><label>sort_order</label><input type="number" name="sort_order" value="0"></div>
    <div class="form-group" style="margin:0;display:flex;align-items:flex-end;gap:10px">
        <label style="display:flex;align-items:center;gap:6px;color:var(--text-2)"><input type="checkbox" name="is_active" value="1" checked> Activo</label>
        <button type="submit" class="btn">Guardar</button>
    </div>
</form>

<div class="table-wrap">
<table>
    <thead><tr>
        <th>ID</th><th>product_key</th><th>space_id</th><th>Nombre</th><th>Orden</th><th>Activo</th><th>Actualizado</th><th>Acciones</th>
    </tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:#A8A8B3">Sin spaces.</td></tr>
    <?php else: foreach ($rows as $r): ?>
        <tr>
            <form method="POST" action="/admin/spaces/update">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <td><?= (int)$r['id'] ?></td>
                <td><?= $e($r['product_key']) ?></td>
                <td style="font-family:monospace;font-size:.85rem"><?= $e($r['space_id']) ?></td>
                <td><input name="space_name" value="<?= $e($r['space_name']) ?>" required style="width:100%;background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 8px;border-radius:4px"></td>
                <td><input type="number" name="sort_order" value="<?= (int)$r['sort_order'] ?>" style="width:70px;background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 6px;border-radius:4px"></td>
                <td><input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></td>
                <td style="color:var(--text-3);font-size:.82rem"><?= $e(date('Y-m-d H:i', strtotime((string)$r['updated_at']))) ?></td>
                <td><button type="submit" class="btn" style="padding:6px 12px;font-size:.82rem">Guardar</button></td>
            </form>
            <form method="POST" action="/admin/spaces/delete" style="display:inline" onsubmit="return confirm('¿Eliminar este space?')">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <td><button type="submit" class="btn secondary" style="padding:6px 12px;font-size:.82rem;border-color:var(--red);color:var(--red)">Eliminar</button></td>
            </form>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
