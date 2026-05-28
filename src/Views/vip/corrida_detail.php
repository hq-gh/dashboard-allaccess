<?php
use App\Security;

/** @var array<string, mixed>             $corrida */
/** @var array<int, array<string, mixed>> $movimientos */

$e = fn($v) => Security::e((string) ($v ?? ''));

$toBool = static function (mixed $v): bool {
    if (is_bool($v))   return $v;
    if (is_int($v))    return $v === 1;
    if (is_string($v)) return in_array(strtolower($v), ['1','t','true','yes','y'], true);
    return false;
};

$statusColor = static function (string $status): string {
    return match ($status) {
        'success' => 'var(--lime)',
        'partial' => 'var(--orange)',
        'failed'  => 'var(--red)',
        'running' => 'var(--cyan)',
        default   => 'var(--text-2)',
    };
};

$resultadoColor = static function (string $r): string {
    return match ($r) {
        'success' => 'var(--lime)',
        'failed'  => 'var(--red)',
        'skipped' => 'var(--text-3)',
        default   => 'var(--text-2)',
    };
};

$accionColor = static function (string $a): string {
    return match ($a) {
        'grant'  => 'var(--lime)',
        'revoke' => 'var(--orange)',
        default  => 'var(--text-2)',
    };
};

$id          = (string) ($corrida['id']              ?? '');
$startedAt   = (string) ($corrida['started_at']      ?? '');
$finishedAt  = (string) ($corrida['finished_at']     ?? '');
$status      = (string) ($corrida['status']          ?? '');
$resyncInts  = (string) ($corrida['resync_intentos'] ?? '0');
$grants      = (string) ($corrida['total_grants']    ?? '0');
$revokes     = (string) ($corrida['total_revokes']   ?? '0');
$errores     = (string) ($corrida['total_errores']   ?? '0');
$mailEnviado = $toBool($corrida['mail_enviado'] ?? false);
$errorMsgC   = (string) ($corrida['error_msg']       ?? '');
$color       = $statusColor($status);
?>
<h1 class="page-title">Corrida #<?= $e($id) ?></h1>
<p class="subtitle">
    <a href="/vip/corridas" style="font-size:.9rem">&laquo; Volver al listado de corridas</a>
</p>

<div class="stats-row">
    <div class="stat">
        <div class="label">Status</div>
        <div class="value" style="font-size:1.1rem">
            <span style="display:inline-block;padding:4px 12px;border-radius:4px;font-size:.85rem;font-weight:600;background:rgba(255,255,255,.04);color:<?= $color ?>;border:1px solid <?= $color ?>">
                <?= $e($status) ?>
            </span>
        </div>
    </div>
    <div class="stat">
        <div class="label">Inicio</div>
        <div class="value" style="font-size:1.05rem"><?= $e($startedAt !== '' ? date('Y-m-d H:i', strtotime($startedAt)) : '—') ?></div>
    </div>
    <div class="stat">
        <div class="label">Fin</div>
        <div class="value" style="font-size:1.05rem"><?= $e($finishedAt !== '' ? date('Y-m-d H:i', strtotime($finishedAt)) : '—') ?></div>
    </div>
    <div class="stat">
        <div class="label">Resync intentos</div>
        <div class="value"><?= $e($resyncInts) ?></div>
    </div>
    <div class="stat">
        <div class="label">Grants</div>
        <div class="value accent-lime"><?= $e($grants) ?></div>
    </div>
    <div class="stat">
        <div class="label">Revokes</div>
        <div class="value" style="color:var(--orange)"><?= $e($revokes) ?></div>
    </div>
    <div class="stat">
        <div class="label">Errores</div>
        <div class="value" style="color:<?= ((int) $errores) > 0 ? 'var(--red)' : 'inherit' ?>"><?= $e($errores) ?></div>
    </div>
    <div class="stat">
        <div class="label">Mail enviado</div>
        <div class="value" style="font-size:1.1rem">
            <?php if ($mailEnviado): ?>
                <span style="color:var(--lime)">Sí</span>
            <?php else: ?>
                <span style="color:var(--text-3)">No</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($errorMsgC !== ''): ?>
    <div class="alert alert-error"><strong>Error:</strong> <?= $e($errorMsgC) ?></div>
<?php endif; ?>

<h2 style="font-size:1.2rem;margin:24px 0 12px 0">Movimientos de la corrida</h2>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Email</th>
                <th>Nombre</th>
                <th>Plan</th>
                <th>Producto</th>
                <th>Acción</th>
                <th>Status Hotmart</th>
                <th>Resultado</th>
                <th>Intentos</th>
                <th>Error</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($movimientos)): ?>
                <tr><td colspan="10" style="text-align:center;padding:30px;color:#A8A8B3">
                    Esta corrida no registró movimientos.
                </td></tr>
            <?php else: foreach ($movimientos as $m):
                $fecha     = (string) ($m['created_at']     ?? '');
                $email     = (string) ($m['email']          ?? '');
                $nombre    = (string) ($m['nombre']         ?? '');
                $plan      = (string) ($m['plan_name']      ?? '');
                $product   = (string) ($m['product_id']     ?? '');
                $accion    = (string) ($m['accion']         ?? '');
                $statusH   = (string) ($m['status_hotmart'] ?? '');
                $resultado = (string) ($m['resultado']      ?? '');
                $intentos  = (string) ($m['intentos']       ?? '');
                $errorMsg  = (string) ($m['error_msg']      ?? '');
                $cAcc      = $accionColor($accion);
                $cRes      = $resultadoColor($resultado);
            ?>
                <tr>
                    <td><?= $e($fecha !== '' ? date('Y-m-d H:i', strtotime($fecha)) : '') ?></td>
                    <td><?= $e($email) ?></td>
                    <td><?= $e($nombre) ?></td>
                    <td><?= $e($plan) ?></td>
                    <td><?= $e($product) ?></td>
                    <td><span style="color:<?= $cAcc ?>;font-weight:600"><?= $e($accion) ?></span></td>
                    <td><?= $e($statusH) ?></td>
                    <td><span style="color:<?= $cRes ?>;font-weight:600"><?= $e($resultado) ?></span></td>
                    <td><?= $e($intentos) ?></td>
                    <td style="color:<?= $errorMsg !== '' ? 'var(--red)' : 'inherit' ?>;font-size:.85rem"><?= $e($errorMsg) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="actions-bottom">
    <a class="btn secondary" href="/vip/corridas">&laquo; Volver a corridas</a>
    <a class="btn secondary" href="/vip/movimientos?desde=<?= $e($startedAt !== '' ? date('Y-m-d', strtotime($startedAt)) : '') ?>">Ver movimientos del día</a>
</div>
