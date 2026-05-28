<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));
?>
<h1 class="page-title">Admin · Usuarios</h1>
<p class="subtitle">Usuarios con acceso al portal. Roles: <code>administrador</code> (acceso total) · <code>usuario</code> (sin sección Admin).</p>

<?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type']==='ok' ? 'alert-ok' : 'alert-error' ?>"><?= $e($flash['msg']) ?></div>
<?php endif; ?>

<h2 style="margin-top:18px;margin-bottom:10px;font-size:1.05rem">Agregar nuevo usuario</h2>
<form method="POST" action="/admin/usuarios/create" style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="form-group" style="margin:0"><label>Nombre</label><input name="name" required></div>
    <div class="form-group" style="margin:0"><label>Email</label><input type="email" name="email" required></div>
    <div class="form-group" style="margin:0"><label>Rol</label>
        <select name="role" required style="width:100%;padding:11px 12px;background:var(--bg-card-2);border:1px solid var(--border);border-radius:6px;color:var(--text-1);font-size:.95rem">
            <option value="usuario">usuario</option>
            <option value="administrador">administrador</option>
        </select>
    </div>
    <div class="form-group" style="margin:0"><label>Contraseña (mín 8)</label><input type="password" name="password" minlength="8" required></div>
    <div style="display:flex;align-items:end"><button type="submit" class="btn">Crear usuario</button></div>
</form>

<div class="table-wrap">
<table>
    <thead><tr>
        <th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Último login</th><th>Creado</th><th style="min-width:280px">Acciones</th>
    </tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:#A8A8B3">Sin usuarios.</td></tr>
    <?php else: foreach ($rows as $r): $isMe = (int)$r['id'] === (int)$current_id; ?>
        <tr style="vertical-align:middle">
            <td><?= (int)$r['id'] ?><?= $isMe ? ' <span style="color:#FF6687;font-size:.7rem">(tú)</span>' : '' ?></td>
            <td>
                <form method="POST" action="/admin/usuarios/update" style="display:flex;gap:6px;align-items:center;margin:0">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input name="name" value="<?= $e($r['name']) ?>" required style="background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 8px;border-radius:4px;width:160px">
            </td>
            <td style="font-family:monospace;font-size:.85rem"><?= $e($r['email']) ?></td>
            <td>
                    <select name="role" style="background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 8px;border-radius:4px">
                        <option value="usuario"       <?= $r['role']==='usuario'?'selected':'' ?>>usuario</option>
                        <option value="administrador" <?= $r['role']==='administrador'?'selected':'' ?>>administrador</option>
                    </select>
            </td>
            <td style="color:var(--text-3);font-size:.82rem"><?= $e($r['last_login_at'] ? date('Y-m-d H:i', strtotime((string)$r['last_login_at'])) : 'nunca') ?></td>
            <td style="color:var(--text-3);font-size:.82rem"><?= $e(date('Y-m-d', strtotime((string)$r['created_at']))) ?></td>
            <td>
                    <button type="submit" class="btn" style="padding:6px 10px;font-size:.78rem">Guardar</button>
                </form>
                <form method="POST" action="/admin/usuarios/reset-password" style="display:inline-flex;gap:6px;align-items:center;margin-left:6px">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="password" name="password" placeholder="Nueva contraseña" minlength="8" required style="background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 8px;border-radius:4px;width:140px">
                    <button type="submit" class="btn secondary" style="padding:6px 10px;font-size:.78rem">Reset</button>
                </form>
                <?php if (!$isMe): ?>
                <form method="POST" action="/admin/usuarios/delete" style="display:inline" onsubmit="return confirm('¿Eliminar a <?= $e($r['email']) ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn secondary" style="padding:6px 10px;font-size:.78rem;border-color:var(--red);color:var(--red);margin-left:6px">Eliminar</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
