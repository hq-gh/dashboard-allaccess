<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));
$k = $kpis;
$maxF = 0; foreach (($funnel ?? []) as $f) $maxF = max($maxF, $f['usuarios']);
?>
<h1 class="page-title">Consumo PLAY</h1>
<p class="subtitle">Analítica de consumo de video en PLAY (Tyris). Carga manual semanal del CSV "Consumo por usuario".</p>

<?php if (!empty($flash)): ?>
  <div style="padding:10px 14px;border-radius:6px;margin-bottom:16px;<?= $flash['type']==='ok' ? 'background:rgba(212,255,77,.12);color:var(--lime);border:1px solid rgba(212,255,77,.4)' : 'background:rgba(255,77,77,.12);color:var(--red);border:1px solid var(--red)' ?>"><?= $e($flash['msg']) ?></div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:18px">
  <form method="POST" action="/play/upload" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label style="font-weight:600">Subir CSV de PLAY:</label>
    <input type="file" name="csv" accept=".csv,text/csv" required style="color:var(--text-2)">
    <button type="submit" class="btn">Cargar</button>
  </form>
  <div style="color:var(--text-3);font-size:.82rem">
    <?php if (empty($k['sin_datos'])): ?>Datos hasta <b style="color:var(--text-2)"><?= $e($k['ultima_fecha']) ?></b> · última carga <?= $e($k['ultimo_import']) ?><?php else: ?>Sin datos aún — sube el CSV.<?php endif; ?>
  </div>
</div>

<?php if (!empty($k['sin_datos'])): ?>
  <p class="subtitle">No hay consumo cargado todavía. Exporta de Tyris "Consumo por usuario" y súbelo arriba.</p>
<?php else: ?>

<div class="stats-row">
  <div class="stat"><div class="label">Alumnos (con historial)</div><div class="value"><?= number_format($k['usuarios']) ?></div></div>
  <div class="stat"><div class="label">Activos últimos 7 días</div><div class="value accent-rose"><?= number_format($k['activos_7d']) ?></div></div>
  <div class="stat"><div class="label">Activos últimos 30 días</div><div class="value"><?= number_format($k['activos_30d']) ?></div></div>
  <div class="stat"><div class="label">En riesgo (14+ días sin ver)</div><div class="value" style="color:var(--red)"><?= number_format($k['en_riesgo_14d']) ?></div></div>
</div>
<div class="stats-row" style="margin-top:12px">
  <div class="stat"><div class="label">Horas vistas (total)</div><div class="value"><?= number_format($k['horas']) ?></div></div>
  <div class="stat"><div class="label">Sesiones</div><div class="value"><?= number_format($k['sesiones']) ?></div></div>
  <div class="stat"><div class="label">Minutos por sesión</div><div class="value"><?= $e($k['min_sesion']) ?></div></div>
  <div class="stat"><div class="label">Activos 14 días</div><div class="value"><?= number_format($k['activos_14d']) ?></div></div>
</div>

<h2 style="margin:26px 0 10px;font-size:1.05rem">Embudo de retención por Semana</h2>
<p class="subtitle" style="margin-top:-4px">Alumnos únicos que alcanzaron cada semana del programa. La caída = dónde abandonan.</p>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:16px">
  <?php foreach (($funnel ?? []) as $f): $pct = $maxF ? round(100*$f['usuarios']/$maxF) : 0; ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:7px">
      <div style="width:80px;color:var(--text-2);font-size:.85rem">Semana <?= (int)$f['semana'] ?></div>
      <div style="flex:1;height:16px;background:var(--bg-card-2);border-radius:4px;overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:var(--rose)"></div></div>
      <div style="width:120px;text-align:right;font-variant-numeric:tabular-nums;font-size:.85rem"><?= number_format($f['usuarios']) ?> (<?= $pct ?>%)</div>
    </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:22px">
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:16px">
    <h3 style="margin:0 0 10px;font-size:.95rem">Plataforma</h3>
    <?php foreach ($distrib['plataforma'] as $p): ?><div style="display:flex;justify-content:space-between;font-size:.85rem;padding:3px 0"><span style="color:var(--text-2)"><?= $e($p['k']) ?></span><span style="font-variant-numeric:tabular-nums"><?= number_format($p['n']) ?></span></div><?php endforeach; ?>
  </div>
  <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:16px">
    <h3 style="margin:0 0 10px;font-size:.95rem">País (top)</h3>
    <?php foreach ($distrib['pais'] as $p): ?><div style="display:flex;justify-content:space-between;font-size:.85rem;padding:3px 0"><span style="color:var(--text-2)"><?= $e($p['k']) ?></span><span style="font-variant-numeric:tabular-nums"><?= number_format($p['n']) ?></span></div><?php endforeach; ?>
  </div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin:26px 0 10px">
  <h2 style="font-size:1.05rem;margin:0">Alumnos en riesgo (<?= (int)$dias ?>+ días sin ver)</h2>
  <div style="display:flex;gap:8px;align-items:center">
    <form method="GET" action="/play" style="display:flex;gap:6px;align-items:center">
      <label style="color:var(--text-3);font-size:.82rem">días</label>
      <input type="number" name="dias" value="<?= (int)$dias ?>" min="1" style="width:70px;padding:7px 8px;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;color:var(--text-1)">
      <button class="btn secondary" type="submit">Aplicar</button>
    </form>
    <a class="btn" href="/play/en-riesgo.csv?dias=<?= (int)$dias ?>">Exportar CSV</a>
  </div>
</div>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;overflow:auto">
  <table style="width:100%;border-collapse:collapse;font-size:.85rem">
    <thead><tr style="text-align:left;color:var(--text-3)">
      <th style="padding:10px 12px">Correo</th><th style="padding:10px 12px">Última vez</th><th style="padding:10px 12px">Días sin ver</th>
      <th style="padding:10px 12px">Sesiones</th><th style="padding:10px 12px">Horas</th><th style="padding:10px 12px">Últ. programa</th>
    </tr></thead>
    <tbody>
      <?php foreach ($enRiesgo as $r): ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:9px 12px"><a href="/estadisticas/alumnos/<?= $e(rawurlencode($r['email'])) ?>" style="color:var(--cyan)"><?= $e($r['email']) ?></a></td>
          <td style="padding:9px 12px"><?= $e($r['ultima']) ?></td>
          <td style="padding:9px 12px;color:var(--red)"><?= (int)$r['dias_sin_ver'] ?></td>
          <td style="padding:9px 12px;font-variant-numeric:tabular-nums"><?= number_format((int)$r['sesiones']) ?></td>
          <td style="padding:9px 12px;font-variant-numeric:tabular-nums"><?= $e($r['horas']) ?></td>
          <td style="padding:9px 12px;color:var(--text-2)"><?= $e($r['ultimo_programa']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$enRiesgo): ?><tr><td colspan="6" style="padding:14px 12px;color:var(--text-3)">Nadie en riesgo con ese umbral.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="subtitle" style="margin-top:8px">Mostrando hasta 500 en pantalla. El CSV trae hasta 5,000. "Días sin ver" es respecto a hoy.</p>

<?php endif; ?>
