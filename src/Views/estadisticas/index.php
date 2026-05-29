<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));

$engLabel = function(int $score): string {
    return [0 => '—', 1 => 'Bajo', 2 => 'Mediano', 3 => 'Alto'][$score] ?? '—';
};
$engColor = function(int $score): string {
    return [0 => 'var(--text-3)', 1 => 'var(--orange)', 2 => 'var(--cyan)', 3 => 'var(--lime)'][$score] ?? 'var(--text-3)';
};
$initials = function(?string $name, ?string $email): string {
    $n = trim((string)($name ?: $email));
    if ($n === '') return '?';
    $parts = preg_split('/\s+/', $n) ?: [$n];
    $first = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
    $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : '';
    return strtoupper($first . $second) ?: '?';
};
$bgFromEmail = function(string $email): string {
    $palette = ['#FF6687','#2D7FF9','#4DDDFF','#D4FF4D','#FF9F4D','#a78bfa','#34d399','#f472b6'];
    return $palette[abs(crc32($email)) % count($palette)];
};
?>
<h1 class="page-title">Estadísticas alumnos</h1>
<p class="subtitle">Vista de miembros del Hotmart Club. Click en un alumno para ver detalle.</p>

<form method="GET" action="/estadisticas" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;margin-bottom:18px">
    <input type="text" name="q" value="<?= $e($filters['search']) ?>" placeholder="Buscar por nombre o email"
           style="padding:11px 12px;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;color:var(--text-1)">
    <select name="producto" style="padding:11px 12px;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;color:var(--text-1)">
        <option value="">Miembro de todos los productos</option>
        <?php foreach ($productos as $p): ?>
            <option value="<?= $e($p) ?>" <?= $filters['product']===$p ? 'selected':'' ?>><?= $e($p) ?></option>
        <?php endforeach; ?>
    </select>
    <div style="display:flex;gap:8px">
        <button type="submit" class="btn">Filtrar</button>
        <a class="btn secondary" href="/estadisticas">Limpiar</a>
    </div>
</form>

<div class="stats-row">
    <div class="stat">
        <div class="label">Total de alumnos</div>
        <div class="value"><?= number_format($stats['total_alumnos']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Progreso promedio</div>
        <div class="value accent-rose"><?= number_format($stats['avg_progress'], 1) ?>%</div>
        <div style="height:6px;background:var(--bg-card-2);border-radius:3px;margin-top:8px;overflow:hidden">
            <div style="height:100%;background:var(--rose);width:<?= max(0,min(100,(float)$stats['avg_progress'])) ?>%"></div>
        </div>
    </div>
    <div class="stat">
        <div class="label">Engagement predominante</div>
        <div class="value"><?= $e($stats['engagement_label']) ?></div>
    </div>
</div>

<div class="table-wrap">
<table>
    <thead><tr>
        <th>Nombre y email</th>
        <th>Último acceso</th>
        <th>Estatus</th>
        <th>Engagement</th>
        <th>Productos</th>
    </tr></thead>
    <tbody>
    <?php if (empty($list['rows'])): ?>
        <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-2)">Sin resultados.</td></tr>
    <?php else: foreach ($list['rows'] as $r):
        $eScore = (int) ($r['engagement_score'] ?? 0);
        $isActive = !empty($r['is_active']);
        $email = (string) ($r['email'] ?? '');
        $url = '/estadisticas/alumnos/' . rawurlencode($email);
        $productos = is_array($r['productos']) ? $r['productos'] : [];
    ?>
        <tr style="cursor:pointer" onclick="window.location='<?= $e($url) ?>'">
            <td>
                <a href="<?= $e($url) ?>" style="display:flex;align-items:center;gap:10px;color:var(--text-1);text-decoration:none">
                    <span style="width:34px;height:34px;border-radius:50%;background:<?= $bgFromEmail($email) ?>;color:#0A0A0B;font-weight:700;font-size:.78rem;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><?= $e($initials($r['name'] ?? null, $email)) ?></span>
                    <span>
                        <div style="font-weight:600"><?= $e($r['name'] ?: '(sin nombre)') ?></div>
                        <div style="color:var(--text-3);font-size:.78rem"><?= $e($email) ?></div>
                    </span>
                </a>
            </td>
            <td><?= $r['last_access'] ? $e(date('d/m/Y', (int)$r['last_access']/1000)) : '—' ?></td>
            <td>
                <span style="display:inline-block;padding:3px 10px;border-radius:11px;font-size:.72rem;font-weight:600;<?= $isActive ? 'background:rgba(212,255,77,.12);color:var(--lime);border:1px solid rgba(212,255,77,.5)' : 'background:rgba(255,77,77,.1);color:var(--red);border:1px solid var(--red)' ?>">
                    <?= $isActive ? 'Activo' : 'Bloqueado' ?>
                </span>
            </td>
            <td style="color:<?= $engColor($eScore) ?>"><?= $e($engLabel($eScore)) ?></td>
            <td>
                <?php
                    $first = $productos[0] ?? null;
                    $extras = count($productos) - 1;
                    if ($first !== null) {
                        echo '<span style="background:var(--bg-card-2);padding:3px 8px;border-radius:4px;font-size:.78rem">' . $e($first) . '</span>';
                        if ($extras > 0) echo ' <span style="color:var(--text-3);font-size:.78rem">+' . (int)$extras . '</span>';
                    } else {
                        echo '<span style="color:var(--text-3)">—</span>';
                    }
                ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<?php if ($list['total_pages'] > 1):
    $q = $filters['search'] !== '' ? '&q=' . urlencode($filters['search']) : '';
    $p = $filters['product'] !== '' ? '&producto=' . urlencode($filters['product']) : '';
    $pp = '&per_page=' . (int) $list['per_page'];
    $page = (int) $list['page'];
    $total = (int) $list['total_pages'];
?>
<div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:20px">
    <?php if ($page > 1): ?>
        <a class="btn secondary" href="?page=1<?= $q.$p.$pp ?>" style="padding:6px 12px;font-size:.85rem">«</a>
        <a class="btn secondary" href="?page=<?= $page-1 ?><?= $q.$p.$pp ?>" style="padding:6px 12px;font-size:.85rem">‹</a>
    <?php endif; ?>
    <span style="color:var(--text-2);font-size:.9rem">Página <?= $page ?> de <?= $total ?> (<?= number_format($list['total']) ?> alumnos)</span>
    <?php if ($page < $total): ?>
        <a class="btn secondary" href="?page=<?= $page+1 ?><?= $q.$p.$pp ?>" style="padding:6px 12px;font-size:.85rem">›</a>
        <a class="btn secondary" href="?page=<?= $total ?><?= $q.$p.$pp ?>" style="padding:6px 12px;font-size:.85rem">»</a>
    <?php endif; ?>
</div>
<?php endif; ?>
