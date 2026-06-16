<?php
use App\Security;
$e = fn($v) => Security::e((string) ($v ?? ''));
$nf = fn($n) => number_format((int) $n);

$tz = new DateTimeZone('America/Mexico_City');
$hoy = (new DateTime('now', $tz));
$preset = function (string $label, string $f, string $t) use ($from, $to, $e) {
    $act = ($from === $f && $to === $t) ? 'active' : '';
    return '<a class="chip ' . $act . '" href="/comunidad?from=' . $e($f) . '&to=' . $e($t) . '">' . $e($label) . '</a>';
};
$d7  = (clone $hoy)->modify('-7 days')->format('Y-m-d');
$d30 = (clone $hoy)->modify('-29 days')->format('Y-m-d');
$mes = $hoy->format('Y-m-01');
$hoyS = $hoy->format('Y-m-d');
?>
<style>
  .chip{display:inline-block;padding:7px 13px;border-radius:999px;border:1px solid var(--border);
        color:var(--text-2);text-decoration:none;font-size:13px;margin-right:6px}
  .chip.active{background:var(--rose);border-color:var(--rose);color:#fff;font-weight:600}
  .rank-num{display:inline-flex;width:24px;height:24px;align-items:center;justify-content:center;
        border-radius:6px;background:var(--bg-card-2);color:var(--text-2);font-size:12px;font-weight:700}
  .rank-num.top{background:var(--rose);color:#fff}
</style>

<h1 class="page-title">Comunidad</h1>
<p class="subtitle">Engagement de diez.5t4d10.com — comentarios y publicaciones por miembro. Datos en vivo de Bettermode.</p>

<!-- Filtro de fechas -->
<form method="GET" action="/comunidad" style="display:flex;flex-wrap:wrap;align-items:end;gap:12px;margin-bottom:10px">
    <div>
        <label style="display:block;font-size:12px;color:var(--text-3);margin-bottom:4px">Desde</label>
        <input type="date" name="from" value="<?= $e($from) ?>"
               style="padding:9px 11px;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;color:var(--text-1)">
    </div>
    <div>
        <label style="display:block;font-size:12px;color:var(--text-3);margin-bottom:4px">Hasta</label>
        <input type="date" name="to" value="<?= $e($to) ?>"
               style="padding:9px 11px;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;color:var(--text-1)">
    </div>
    <button type="submit" class="btn">Aplicar</button>
    <a class="btn secondary" href="/comunidad/export.csv?from=<?= $e($from) ?>&to=<?= $e($to) ?>">Exportar CSV</a>
</form>
<div style="margin-bottom:22px">
    <?= $preset('Últimos 7 días', $d7, $hoyS) ?>
    <?= $preset('Últimos 30 días', $d30, $hoyS) ?>
    <?= $preset('Este mes', $mes, $hoyS) ?>
</div>

<?php if ($error): ?>
    <div class="card" style="border-color:var(--red);color:var(--red);margin-bottom:20px">
        No se pudo cargar el engagement: <?= $e($error) ?>
    </div>
<?php else: ?>

<!-- KPIs ejecutivos -->
<div class="stats-row">
    <div class="stat">
        <div class="label">Comentarios</div>
        <div class="value accent-rose"><?= $nf($kpis['comentarios']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Publicaciones</div>
        <div class="value"><?= $nf($kpis['posts']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Miembros activos</div>
        <div class="value"><?= $nf($kpis['activos']) ?></div>
    </div>
</div>
<p class="subtitle" style="margin-top:-6px">Periodo: <?= $e($from) ?> a <?= $e($to) ?> (hora CdMx)</p>

<!-- Rankings -->
<div class="cards-grid" style="margin-top:18px">
    <div class="card">
        <h3 style="margin:0 0 12px">Top comentaristas</h3>
        <div class="table-wrap">
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                <thead><tr style="text-align:left;color:var(--text-3)">
                    <th style="padding:6px 8px">#</th><th style="padding:6px 8px">Miembro</th><th style="padding:6px 8px;text-align:right">Comentarios</th>
                </tr></thead>
                <tbody>
                <?php if (!$topCom): ?>
                    <tr><td colspan="3" style="padding:12px 8px;color:var(--text-3)">Sin comentarios en el periodo.</td></tr>
                <?php else: foreach ($topCom as $i => $r): ?>
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:8px"><span class="rank-num <?= $i < 3 ? 'top' : '' ?>"><?= $i + 1 ?></span></td>
                        <td style="padding:8px">
                            <div style="color:var(--text-1)"><?= $e($r['nombre'] ?: '(sin nombre)') ?></div>
                            <div style="font-size:12px;color:var(--text-3)"><?= $e($r['email']) ?></div>
                        </td>
                        <td style="padding:8px;text-align:right;font-weight:700;color:var(--rose)"><?= $nf($r['comentarios']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 style="margin:0 0 12px">Top autores (publicaciones)</h3>
        <div class="table-wrap">
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                <thead><tr style="text-align:left;color:var(--text-3)">
                    <th style="padding:6px 8px">#</th><th style="padding:6px 8px">Miembro</th><th style="padding:6px 8px;text-align:right">Posts</th>
                </tr></thead>
                <tbody>
                <?php if (!$topPost): ?>
                    <tr><td colspan="3" style="padding:12px 8px;color:var(--text-3)">Sin publicaciones en el periodo.</td></tr>
                <?php else: foreach ($topPost as $i => $r): ?>
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:8px"><span class="rank-num <?= $i < 3 ? 'top' : '' ?>"><?= $i + 1 ?></span></td>
                        <td style="padding:8px">
                            <div style="color:var(--text-1)"><?= $e($r['nombre'] ?: '(sin nombre)') ?></div>
                            <div style="font-size:12px;color:var(--text-3)"><?= $e($r['email']) ?></div>
                        </td>
                        <td style="padding:8px;text-align:right;font-weight:700"><?= $nf($r['posts']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>
