<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));
$badge = function(string $status): string {
    $color = match ($status) {
        'success' => '#D4FF4D', 'partial' => '#FF9F4D', 'failed' => '#FF4D4D',
        'ignored' => '#5C5C66', 'invalid' => '#FF4D4D', default => '#A8A8B3'
    };
    return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:600;background:rgba(255,255,255,.04);color:' . $color . ';border:1px solid ' . $color . '">' . htmlspecialchars($status, ENT_QUOTES) . '</span>';
};
?>
<h1 class="page-title">Webhook · Eventos</h1>
<p class="subtitle">Bitácora de eventos entrantes de Hotmart procesados por el webhook. Últimos 500.</p>

<form method="GET" action="/webhook/eventos" style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
    <div class="form-group" style="margin:0"><label>Email</label><input name="email" value="<?= $e($filters['email']) ?>"></div>
    <div class="form-group" style="margin:0"><label>Status</label>
        <input list="statusList" name="status" value="<?= $e($filters['status']) ?>">
        <datalist id="statusList"><option value="success"><option value="partial"><option value="failed"><option value="ignored"><option value="invalid"></datalist>
    </div>
    <div class="form-group" style="margin:0"><label>Evento</label><input name="event_type" value="<?= $e($filters['event_type']) ?>" placeholder="PURCHASE_APPROVED..."></div>
    <div class="form-group" style="margin:0"><label>Producto</label>
        <input list="pkList" name="product_key" value="<?= $e($filters['product_key']) ?>">
        <datalist id="pkList"><option value="infinity"><option value="infinity_vip"></datalist>
    </div>
    <div class="form-group" style="margin:0"><label>Desde</label><input type="date" name="desde" value="<?= $e($filters['desde']) ?>"></div>
    <div class="form-group" style="margin:0"><label>Hasta</label><input type="date" name="hasta" value="<?= $e($filters['hasta']) ?>"></div>
    <div style="display:flex;align-items:end;gap:8px"><button type="submit" class="btn">Filtrar</button><a class="btn secondary" href="/webhook/eventos">Limpiar</a></div>
</form>

<div class="table-wrap">
<table>
    <thead><tr>
        <th>Recibido</th><th>Evento</th><th>Producto</th><th>Email</th><th>Acción</th>
        <th>Spaces OK</th><th>Status</th><th>Mensaje</th>
    </tr></thead>
    <tbody>
    <?php if (empty($events)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:#A8A8B3">No hay eventos con esos filtros.</td></tr>
    <?php else: foreach ($events as $r): ?>
        <tr>
            <td><?= $e(date('Y-m-d H:i:s', strtotime((string)$r['received_at']))) ?></td>
            <td style="font-family:monospace;font-size:.85rem"><?= $e($r['event_type']) ?></td>
            <td><?= $e($r['product_key']) ?></td>
            <td><?= $e($r['email']) ?></td>
            <td><?= $e($r['action_taken']) ?></td>
            <td><?= (int)$r['spaces_ok'] ?>/<?= (int)$r['spaces_ok'] + (int)$r['spaces_failed'] ?></td>
            <td><?= $badge((string)$r['status']) ?></td>
            <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis"><?= $e($r['message']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
