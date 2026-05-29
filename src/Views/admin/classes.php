<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));
$missing = 0; $named = 0;
foreach ($rows as $r) { if ($r['class_name'] === null || $r['class_name'] === '') $missing++; else $named++; }
?>
<h1 class="page-title">Admin · Classes (Teams) Hotmart Club</h1>
<p class="subtitle">Mapeo <code>(subdomain, class_id)</code> → nombre humano (ej. "Team 39 -MCB-"). La API de Hotmart no expone el nombre; se llena aquí. Ordenado por # de alumnos, prioriza los teams más grandes.</p>

<?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type']==='ok' ? 'alert-ok' : 'alert-error' ?>"><?= $e($flash['msg']) ?></div>
<?php endif; ?>

<div class="stats-row">
    <div class="stat"><div class="label">Con nombre</div><div class="value accent-lime"><?= $named ?></div></div>
    <div class="stat"><div class="label">Sin nombre</div><div class="value accent-rose"><?= $missing ?></div></div>
    <div class="stat"><div class="label">Total</div><div class="value"><?= $named + $missing ?></div></div>
</div>

<form method="GET" action="/admin/classes" style="display:flex;gap:10px;align-items:center;margin-bottom:18px;flex-wrap:wrap">
    <select name="subdomain" style="padding:9px 12px;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;color:var(--text-1)">
        <option value="">Todos los subdomains</option>
        <?php foreach ($subdomains as $sd): ?>
            <option value="<?= $e($sd) ?>" <?= $subdomain === $sd ? 'selected':'' ?>><?= $e($sd) ?></option>
        <?php endforeach; ?>
    </select>
    <label style="color:var(--text-2);display:flex;align-items:center;gap:6px;font-size:.9rem">
        <input type="checkbox" name="only_missing" value="1" <?= !empty($only_missing) ? 'checked':'' ?>> Solo sin nombre
    </label>
    <button type="submit" class="btn">Filtrar</button>
    <a class="btn secondary" href="/admin/classes">Limpiar</a>
</form>

<div class="table-wrap">
<table>
    <thead><tr>
        <th>Subdomain</th>
        <th>class_id</th>
        <th>Alumnos</th>
        <th>Nombre (editable)</th>
        <th>Activo</th>
        <th>Actualizado</th>
        <th>Acción</th>
    </tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-2)">Sin classes.</td></tr>
    <?php else: foreach ($rows as $r): ?>
        <tr style="vertical-align:middle">
            <form method="POST" action="/admin/classes/update" style="display:contents">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="return_subdomain" value="<?= $e($subdomain) ?>">
                <td style="font-family:monospace;font-size:.82rem;color:var(--text-2)"><?= $e($r['subdomain']) ?></td>
                <td style="font-family:monospace;font-size:.82rem"><?= $e($r['class_id']) ?></td>
                <td><?= number_format((int)$r['alumnos']) ?></td>
                <td>
                    <input name="class_name" value="<?= $e($r['class_name']) ?>"
                           placeholder="ej. Team 39 -MCB-"
                           style="width:100%;background:transparent;border:1px solid var(--border);color:var(--text-1);padding:6px 10px;border-radius:4px">
                </td>
                <td><input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked':'' ?>></td>
                <td style="color:var(--text-3);font-size:.78rem"><?= $e(date('Y-m-d H:i', strtotime((string)$r['updated_at']))) ?></td>
                <td><button type="submit" class="btn" style="padding:6px 12px;font-size:.82rem">Guardar</button></td>
            </form>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
