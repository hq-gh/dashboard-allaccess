<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));

/** Helper para el link del header de sort. */
$sortLink = function(string $col, string $label) use ($sort, $dir, $e): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow  = '';
    if ($sort === $col) {
        $arrow = $dir === 'asc' ? ' <span class="sort-arrow">▲</span>' : ' <span class="sort-arrow">▼</span>';
    }
    $active = $sort === $col ? ' active' : '';
    return '<a class="' . $active . '" href="/pecadores?sort=' . urlencode($col) . '&dir=' . urlencode($newDir) . '">' . $e($label) . $arrow . '</a>';
};
?>
<h1 class="page-title">Verificador Pecadores</h1>
<p class="subtitle">Suscriptores activos a <b>Infinity VIP</b> que aún no tienen <b>INFINITY</b> — oportunidades de conversión.<br>
<span style="color:#5C5C66;font-size:.85rem">Sincronizado al cargar · <?= $e(date('Y-m-d H:i:s')) ?></span></p>

<div class="stats-row">
    <div class="stat">
        <div class="label">Pecadores</div>
        <div class="value accent-rose"><?= number_format($stats['pecadores']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Total Infinity VIP</div>
        <div class="value"><?= number_format($stats['total_vip']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Ya convertidos</div>
        <div class="value accent-lime"><?= number_format($stats['convertidos']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Tasa de conversión</div>
        <div class="value"><?= number_format($stats['tasa'], 1) ?>%</div>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th><?= $sortLink('name',    'Nombre') ?></th>
                <th><?= $sortLink('email',   'Email') ?></th>
                <th><?= $sortLink('country', 'País') ?></th>
                <th>Teléfono</th>
                <th><?= $sortLink('fecha',   'Fecha/Hora') ?></th>
                <th><?= $sortLink('plan',    'Plan') ?></th>
                <th>Precio</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#A8A8B3">No hay pecadores. Todos ya tienen INFINITY.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= $e($r['name']) ?></td>
                    <td><?= $e($r['email']) ?></td>
                    <td><?= $e($r['country']) ?></td>
                    <td><?= $e($r['phone']) ?></td>
                    <td><?= $e($r['fecha_hora'] ? date('Y-m-d H:i', strtotime((string)$r['fecha_hora'])) : '') ?></td>
                    <td><?= $e($r['plan']) ?></td>
                    <td><?= $e($r['precio']) ?> <?= $e($r['moneda']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="actions-bottom">
    <a class="btn" href="/pecadores?sort=<?= $e($sort) ?>&dir=<?= $e($dir) ?>">Sincronizar</a>
    <a class="btn secondary" href="/pecadores/export.csv?sort=<?= $e($sort) ?>&dir=<?= $e($dir) ?>">Exportar CSV</a>
</div>
