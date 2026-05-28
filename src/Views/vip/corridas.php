<?php
use App\Security;

/** @var array<int, array<string, mixed>> $rows */
/** @var int    $totalListadas */
/** @var string $ultimaFecha   */
/** @var string|null $errorMsg */

$e = fn($v) => Security::e((string) ($v ?? ''));

$toBool = static function (mixed $v): bool {
    if (is_bool($v))   return $v;
    if (is_int($v))    return $v === 1;
    if (is_string($v)) return in_array(strtolower($v), ['1','t','true','yes','y'], true);
    return false;
};

/**
 * Devuelve color (CSS var) según el status de una corrida.
 */
$statusColor = static function (string $status): string {
    return match ($status) {
        'success' => 'var(--lime)',
        'partial' => 'var(--orange)',
        'failed'  => 'var(--red)',
        'running' => 'var(--cyan)',
        default   => 'var(--text-2)',
    };
};
?>
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <h1 class="page-title" style="margin:0">Dashboard VIP · Corridas</h1>
    <form method="GET" action="/vip/altas-bajas.csv" style="display:flex;gap:8px;align-items:center;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:8px 12px">
        <label style="color:var(--text-3);font-size:.78rem">Desde</label>
        <input type="date" name="desde" value="<?= $e(date('Y-m-d', strtotime('-30 days'))) ?>"
               style="background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 6px;border-radius:4px;font-size:.85rem">
        <label style="color:var(--text-3);font-size:.78rem">Hasta</label>
        <input type="date" name="hasta" value="<?= $e(date('Y-m-d')) ?>"
               style="background:transparent;border:1px solid var(--border);color:var(--text-1);padding:4px 6px;border-radius:4px;font-size:.85rem">
        <button type="submit" class="btn" style="padding:6px 14px;font-size:.85rem" title="Descarga CSV con altas/bajas (nombre, email, teléfono, programa, fecha) para gestión manual de WhatsApp">Exportar altas y bajas</button>
    </form>
</div>
<p class="subtitle">Historial de ejecuciones del job diario del Verificador InfinityVIP.<br>
<span style="color:#5C5C66;font-size:.85rem">Mostrando las últimas <?= $e((string) $totalListadas) ?> corridas · <?= $e(date('Y-m-d H:i:s')) ?></span></p>

<?php if (!empty($errorMsg)): ?>
    <div class="alert alert-error"><?= $e($errorMsg) ?></div>
<?php endif; ?>

<div class="stats-row">
    <div class="stat">
        <div class="label">Corridas listadas</div>
        <div class="value"><?= $e((string) $totalListadas) ?></div>
    </div>
    <div class="stat">
        <div class="label">Última corrida</div>
        <div class="value" style="font-size:1.05rem">
            <?= $e($ultimaFecha !== '' ? date('Y-m-d H:i', strtotime($ultimaFecha)) : '—') ?>
        </div>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Inicio</th>
                <th>Fin</th>
                <th>Status</th>
                <th>Grants</th>
                <th>Revokes</th>
                <th>Errores</th>
                <th>Mail</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" style="text-align:center;padding:30px;color:#A8A8B3">
                    Aún no hay corridas registradas.
                </td></tr>
            <?php else: foreach ($rows as $r):
                $id          = (string) ($r['id']            ?? '');
                $startedAt   = (string) ($r['started_at']    ?? '');
                $finishedAt  = (string) ($r['finished_at']   ?? '');
                $status      = (string) ($r['status']        ?? '');
                $grants      = (string) ($r['total_grants']  ?? '0');
                $revokes     = (string) ($r['total_revokes'] ?? '0');
                $errores     = (string) ($r['total_errores'] ?? '0');
                $mailEnviado = $toBool($r['mail_enviado'] ?? false);
                $color       = $statusColor($status);
            ?>
                <tr>
                    <td><?= $e($id) ?></td>
                    <td><?= $e($startedAt !== '' ? date('Y-m-d H:i', strtotime($startedAt)) : '') ?></td>
                    <td><?= $e($finishedAt !== '' ? date('Y-m-d H:i', strtotime($finishedAt)) : '') ?></td>
                    <td>
                        <span style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:.78rem;font-weight:600;background:rgba(255,255,255,.04);color:<?= $color ?>;border:1px solid <?= $color ?>">
                            <?= $e($status) ?>
                        </span>
                    </td>
                    <td><?= $e($grants) ?></td>
                    <td><?= $e($revokes) ?></td>
                    <td style="color:<?= ((int) $errores) > 0 ? 'var(--red)' : 'inherit' ?>"><?= $e($errores) ?></td>
                    <td>
                        <?php if ($mailEnviado): ?>
                            <span style="color:var(--lime)">Sí</span>
                        <?php else: ?>
                            <span style="color:var(--text-3)">No</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn secondary" style="padding:5px 10px;font-size:.82rem" href="/vip/corridas/<?= $e($id) ?>">Ver detalle</a></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="actions-bottom">
    <a class="btn secondary" href="/vip/movimientos">Ver movimientos</a>
    <a class="btn secondary" href="/vip/estado">Ver estado actual</a>
</div>
