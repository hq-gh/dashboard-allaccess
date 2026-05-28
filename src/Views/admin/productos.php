<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));
?>
<h1 class="page-title">Admin · Productos Hotmart</h1>
<p class="subtitle">Mapeo <code>hotmart_product_id</code> → <code>product_key</code>. Webhooks de productos NO mapeados se ignoran (seguridad por defecto).</p>

<?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type']==='ok' ? 'alert-ok' : 'alert-error' ?>"><?= $e($flash['msg']) ?></div>
<?php endif; ?>

<h2 style="margin-top:18px;margin-bottom:10px;font-size:1.05rem">Agregar / actualizar producto</h2>
<form method="POST" action="/admin/productos/upsert" style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="form-group" style="margin:0"><label>hotmart_product_id</label><input name="hotmart_product_id" required></div>
    <div class="form-group" style="margin:0"><label>product_key</label>
        <input list="pkList" name="product_key" required>
        <datalist id="pkList"><option value="infinity"><option value="infinity_vip"></datalist>
    </div>
    <div class="form-group" style="margin:0"><label>product_name</label><input name="product_name"></div>
    <div class="form-group" style="margin:0;display:flex;align-items:flex-end;gap:10px">
        <label style="display:flex;align-items:center;gap:6px;color:var(--text-2)"><input type="checkbox" name="is_active" value="1" checked> Activo</label>
        <button type="submit" class="btn">Guardar</button>
    </div>
</form>

<div class="table-wrap">
<table>
    <thead><tr>
        <th>Hotmart product_id</th><th>product_key</th><th>Nombre</th><th>Activo</th><th>Acciones</th>
    </tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:#A8A8B3">Sin productos.</td></tr>
    <?php else: foreach ($rows as $r): ?>
        <tr>
            <form method="POST" action="/admin/productos/upsert">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="hotmart_product_id" value="<?= $e($r['hotmart_product_id']) ?>">
                <td style="font-family:monospace"><?= $e($r['hotmart_product_id']) ?></td>
                <td>
                    <input list="pkList" name="product_key" value="<?= $e($r['product_key']) ?>" required
                           style="width:140px;background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 8px;border-radius:4px">
                </td>
                <td><input name="product_name" value="<?= $e($r['product_name']) ?>" style="width:100%;background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 8px;border-radius:4px"></td>
                <td><input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></td>
                <td><button type="submit" class="btn" style="padding:6px 12px;font-size:.82rem">Guardar</button></td>
            </form>
            <form method="POST" action="/admin/productos/delete" style="display:inline" onsubmit="return confirm('¿Eliminar este mapping?')">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="hotmart_product_id" value="<?= $e($r['hotmart_product_id']) ?>">
                <td><button type="submit" class="btn secondary" style="padding:6px 12px;font-size:.82rem;border-color:var(--red);color:var(--red)">Eliminar</button></td>
            </form>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
