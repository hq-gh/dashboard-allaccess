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
    $first  = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
    $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : '';
    return strtoupper($first . $second) ?: '?';
};
$bgFromEmail = function(string $email): string {
    $palette = ['#FF6687','#2D7FF9','#4DDDFF','#D4FF4D','#FF9F4D','#a78bfa','#34d399','#f472b6'];
    return $palette[abs(crc32($email)) % count($palette)];
};

$typeLabel = ['BUYER' => 'Comprado', 'IMPORTED' => 'Importado', 'GUEST' => 'Invitado'];
$roleLabel = ['STUDENT' => 'Estudiante', 'ADMIN' => 'Admin', 'MODERATOR' => 'Moderador'];

$email = (string) ($summary['email'] ?? '');
$name  = (string) ($summary['name']  ?? '');
$engScore = (int) ($summary['engagement_score'] ?? 0);
$isActive = !empty($summary['is_active']);
$avgProgress = (float) ($summary['avg_progress'] ?? 0);
?>
<p style="color:var(--text-3);font-size:.85rem;margin-bottom:10px">
    <a href="/estadisticas" style="color:var(--text-3);text-decoration:none">Alumnos</a>
    &nbsp;/&nbsp;
    <span style="color:var(--text-2)"><?= $e($name ?: $email) ?></span>
</p>

<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
    <span style="width:46px;height:46px;border-radius:50%;background:<?= $bgFromEmail($email) ?>;color:#0A0A0B;font-weight:700;font-size:1rem;display:inline-flex;align-items:center;justify-content:center"><?= $e($initials($name, $email)) ?></span>
    <h1 class="page-title" style="margin:0"><?= $e($name ?: $email) ?></h1>
</div>

<div class="stats-row" style="grid-template-columns:2fr 1fr">
    <div class="stat">
        <div class="label">Progreso promedio</div>
        <div class="value accent-rose"><?= number_format($avgProgress, 1) ?>%</div>
        <div style="height:8px;background:var(--bg-card-2);border-radius:4px;margin-top:10px;overflow:hidden">
            <div style="height:100%;background:var(--rose);width:<?= max(0,min(100,$avgProgress)) ?>%"></div>
        </div>
    </div>
    <div class="stat">
        <div class="label">Engagement</div>
        <div class="value" style="color:<?= $engColor($engScore) ?>"><?= $e($engLabel($engScore)) ?></div>
    </div>
</div>

<h2 style="margin-top:22px;margin-bottom:14px;font-size:1.05rem;color:var(--text-2);font-weight:600">Información y datos personales</h2>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:18px;margin-bottom:24px;display:grid;grid-template-columns:1fr 1fr;gap:18px 30px">
    <div>
        <div style="color:var(--text-3);font-size:.78rem;margin-bottom:4px">Nombre completo</div>
        <div><?= $e($name ?: '—') ?></div>
    </div>
    <div>
        <div style="color:var(--text-3);font-size:.78rem;margin-bottom:4px">Primer acceso</div>
        <div><?= $summary['first_access'] ? $e(date('d/m/Y H:i', (int)$summary['first_access']/1000)) . ' GMT-6' : '—' ?></div>
    </div>
    <div>
        <div style="color:var(--text-3);font-size:.78rem;margin-bottom:4px">Email</div>
        <div style="font-family:monospace;font-size:.85rem"><?= $e($email) ?></div>
    </div>
    <div>
        <div style="color:var(--text-3);font-size:.78rem;margin-bottom:4px">Último acceso</div>
        <div><?= $summary['last_access'] ? $e(date('d/m/Y H:i', (int)$summary['last_access']/1000)) . ' GMT-6' : '—' ?></div>
    </div>
    <div>
        <div style="color:var(--text-3);font-size:.78rem;margin-bottom:4px">Categoría</div>
        <div><?= $e($typeLabel[(string)($summary['type'] ?? '')] ?? ($summary['type'] ?? '—')) ?></div>
    </div>
    <div>
        <div style="color:var(--text-3);font-size:.78rem;margin-bottom:4px">Cantidad de accesos</div>
        <div><?= number_format((int)($summary['total_access_count'] ?? 0)) ?></div>
    </div>
    <div>
        <div style="color:var(--text-3);font-size:.78rem;margin-bottom:4px">Acceso (visión general)</div>
        <div>
            <span style="display:inline-block;padding:3px 10px;border-radius:11px;font-size:.74rem;font-weight:600;<?= $isActive ? 'background:rgba(212,255,77,.12);color:var(--lime);border:1px solid rgba(212,255,77,.5)' : 'background:rgba(255,77,77,.1);color:var(--red);border:1px solid var(--red)' ?>">
                <?= $isActive ? 'Activo' : 'Bloqueado' ?>
            </span>
        </div>
    </div>
</div>

<h2 style="margin-top:8px;margin-bottom:14px;font-size:1.05rem;color:var(--text-2);font-weight:600">Productos</h2>
<div class="table-wrap">
<table>
    <thead><tr>
        <th>Nombre del producto</th>
        <th>Progreso</th>
        <th>Grupo</th>
        <th>Función</th>
        <th>Categoría</th>
        <th>Acceso</th>
    </tr></thead>
    <tbody>
    <?php foreach ($products as $p):
        $pStatus = (string)($p['status'] ?? '');
        $pActive = $pStatus === 'ACTIVE';
        $prog = (float) ($p['progress_pct'] ?? 0);
    ?>
        <tr>
            <td style="font-weight:600"><?= $e($p['product_name']) ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="min-width:48px"><?= number_format($prog, 0) ?>%</span>
                    <div style="flex:1;height:6px;background:var(--bg-card-2);border-radius:3px;overflow:hidden;min-width:80px">
                        <div style="height:100%;background:var(--rose);width:<?= max(0,min(100,$prog)) ?>%"></div>
                    </div>
                </div>
            </td>
            <td style="font-family:monospace;font-size:.82rem;color:var(--text-2)"><?= $e($p['class_id'] ?: '—') ?></td>
            <td><?= $e($roleLabel[(string)($p['role'] ?? '')] ?? ($p['role'] ?? '—')) ?></td>
            <td><?= $e($typeLabel[(string)($p['type'] ?? '')] ?? ($p['type'] ?? '—')) ?></td>
            <td>
                <span style="display:inline-block;padding:3px 10px;border-radius:11px;font-size:.72rem;font-weight:600;<?= $pActive ? 'background:rgba(212,255,77,.12);color:var(--lime);border:1px solid rgba(212,255,77,.5)' : 'background:rgba(255,77,77,.1);color:var(--red);border:1px solid var(--red)' ?>">
                    <?= $pActive ? 'Activo' : $e($pStatus) ?>
                </span>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
