<?php
use App\Security;

/** @var array<int, array<string, mixed>> $rows */

$e = fn($v) => Security::e((string) ($v ?? ''));

$toBool = static function (mixed $v): bool {
    if (is_bool($v))   return $v;
    if (is_int($v))    return $v === 1;
    if (is_string($v)) return in_array(strtolower($v), ['1','t','true','yes','y'], true);
    return false;
};

$total       = count($rows);
$conAcceso   = 0;
$sinAcceso   = 0;
foreach ($rows as $r) {
    if ($toBool($r['tiene_acceso_vip'] ?? false)) {
        $conAcceso++;
    } else {
        $sinAcceso++;
    }
}
?>
<h1 class="page-title">Dashboard VIP · Estado actual</h1>
<p class="subtitle">Estado canónico por alumno: una fila por email. Base de la idempotencia del job.<br>
<span style="color:#5C5C66;font-size:.85rem"><?= $e((string) $total) ?> alumnos · <?= $e(date('Y-m-d H:i:s')) ?></span></p>

<div class="stats-row">
    <div class="stat">
        <div class="label">Total alumnos</div>
        <div class="value"><?= $e((string) $total) ?></div>
    </div>
    <div class="stat">
        <div class="label">Con acceso VIP</div>
        <div class="value accent-lime"><?= $e((string) $conAcceso) ?></div>
    </div>
    <div class="stat">
        <div class="label">Sin acceso VIP</div>
        <div class="value" style="color:var(--text-3)"><?= $e((string) $sinAcceso) ?></div>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Email</th>
                <th>Nombre</th>
                <th>Plan</th>
                <th>Producto</th>
                <th>Status Hotmart</th>
                <th>Acceso VIP</th>
                <th>Member ID</th>
                <th>Última verificación</th>
                <th>Último cambio</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" style="text-align:center;padding:30px;color:#A8A8B3">
                    No hay registros en el estado canónico.
                </td></tr>
            <?php else: foreach ($rows as $r):
                $email    = (string) ($r['email']                  ?? '');
                $nombre   = (string) ($r['nombre']                 ?? '');
                $plan     = (string) ($r['plan_name']              ?? '');
                $product  = (string) ($r['product_id']             ?? '');
                $statusH  = (string) ($r['status_hotmart']         ?? '');
                $acceso   = $toBool($r['tiene_acceso_vip'] ?? false);
                $member   = (string) ($r['bettermode_member_id']   ?? '');
                $ultV     = (string) ($r['ultima_verificacion_at'] ?? '');
                $ultC     = (string) ($r['ultimo_cambio_at']       ?? '');
            ?>
                <tr>
                    <td><?= $e($email) ?></td>
                    <td><?= $e($nombre) ?></td>
                    <td><?= $e($plan) ?></td>
                    <td><?= $e($product) ?></td>
                    <td><?= $e($statusH) ?></td>
                    <td>
                        <?php if ($acceso): ?>
                            <span style="color:var(--lime);font-weight:600">Sí</span>
                        <?php else: ?>
                            <span style="color:var(--text-3)">No</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.82rem;color:var(--text-2)"><?= $e($member) ?></td>
                    <td><?= $e($ultV !== '' ? date('Y-m-d H:i', strtotime($ultV)) : '') ?></td>
                    <td><?= $e($ultC !== '' ? date('Y-m-d H:i', strtotime($ultC)) : '') ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="actions-bottom">
    <a class="btn secondary" href="/vip/estado.csv">Exportar CSV</a>
    <a class="btn secondary" href="/vip/corridas">Ver corridas</a>
    <a class="btn secondary" href="/vip/movimientos">Ver movimientos</a>
</div>
