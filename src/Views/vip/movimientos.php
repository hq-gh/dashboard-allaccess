<?php
use App\Security;

/** @var array<int, array<string, mixed>> $rows */
/** @var array<string, string>            $filtros */

$e = fn($v) => Security::e((string) ($v ?? ''));

$fAccion    = (string) ($filtros['accion']    ?? '');
$fResultado = (string) ($filtros['resultado'] ?? '');
$fEmail     = (string) ($filtros['email']     ?? '');
$fDesde     = (string) ($filtros['desde']     ?? '');
$fHasta     = (string) ($filtros['hasta']     ?? '');

$total = count($rows);

// Reusar query string para el link de export CSV.
$qs = http_build_query(array_filter($filtros, static fn($v) => $v !== '' && $v !== null));
$csvHref = '/vip/movimientos.csv' . ($qs !== '' ? ('?' . $qs) : '');

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
?>
<h1 class="page-title">Dashboard VIP · Movimientos</h1>
<p class="subtitle">Historial filtrable de grants y revokes registrados por el job.
Muestra hasta 2000 resultados; afina los filtros si necesitas más detalle.<br>
<span style="color:#5C5C66;font-size:.85rem"><?= $e((string) $total) ?> resultados · <?= $e(date('Y-m-d H:i:s')) ?></span></p>

<form method="get" action="/vip/movimientos" style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:18px;margin-bottom:20px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
        <div>
            <label style="display:block;color:var(--text-2);font-size:.82rem;margin-bottom:6px">Desde</label>
            <input type="date" name="desde" value="<?= $e($fDesde) ?>"
                   style="width:100%;padding:9px 11px;background:var(--bg-card-2);border:1px solid var(--border);border-radius:6px;color:var(--text-1);font-size:.92rem">
        </div>
        <div>
            <label style="display:block;color:var(--text-2);font-size:.82rem;margin-bottom:6px">Hasta</label>
            <input type="date" name="hasta" value="<?= $e($fHasta) ?>"
                   style="width:100%;padding:9px 11px;background:var(--bg-card-2);border:1px solid var(--border);border-radius:6px;color:var(--text-1);font-size:.92rem">
        </div>
        <div>
            <label style="display:block;color:var(--text-2);font-size:.82rem;margin-bottom:6px">Acción</label>
            <select name="accion" style="width:100%;padding:9px 11px;background:var(--bg-card-2);border:1px solid var(--border);border-radius:6px;color:var(--text-1);font-size:.92rem">
                <option value=""       <?= $fAccion === ''       ? 'selected' : '' ?>>Todas</option>
                <option value="grant"  <?= $fAccion === 'grant'  ? 'selected' : '' ?>>Grant</option>
                <option value="revoke" <?= $fAccion === 'revoke' ? 'selected' : '' ?>>Revoke</option>
            </select>
        </div>
        <div>
            <label style="display:block;color:var(--text-2);font-size:.82rem;margin-bottom:6px">Resultado</label>
            <select name="resultado" style="width:100%;padding:9px 11px;background:var(--bg-card-2);border:1px solid var(--border);border-radius:6px;color:var(--text-1);font-size:.92rem">
                <option value=""        <?= $fResultado === ''        ? 'selected' : '' ?>>Todos</option>
                <option value="success" <?= $fResultado === 'success' ? 'selected' : '' ?>>Success</option>
                <option value="failed"  <?= $fResultado === 'failed'  ? 'selected' : '' ?>>Failed</option>
                <option value="skipped" <?= $fResultado === 'skipped' ? 'selected' : '' ?>>Skipped</option>
            </select>
        </div>
        <div style="grid-column:span 2">
            <label style="display:block;color:var(--text-2);font-size:.82rem;margin-bottom:6px">Email contiene</label>
            <input type="text" name="email" value="<?= $e($fEmail) ?>" placeholder="ej: alguien@dominio.com"
                   style="width:100%;padding:9px 11px;background:var(--bg-card-2);border:1px solid var(--border);border-radius:6px;color:var(--text-1);font-size:.92rem">
        </div>
    </div>
    <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
        <button type="submit" class="btn">Filtrar</button>
        <a class="btn secondary" href="/vip/movimientos">Limpiar</a>
    </div>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Corrida</th>
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
            <?php if (empty($rows)): ?>
                <tr><td colspan="11" style="text-align:center;padding:30px;color:#A8A8B3">
                    Sin movimientos para los filtros aplicados.
                </td></tr>
            <?php else: foreach ($rows as $m):
                $fecha     = (string) ($m['created_at']     ?? '');
                $corridaId = (string) ($m['corrida_id']     ?? '');
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
                    <td><a href="/vip/corridas/<?= $e($corridaId) ?>">#<?= $e($corridaId) ?></a></td>
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
    <a class="btn secondary" href="<?= $e($csvHref) ?>">Exportar CSV</a>
    <a class="btn secondary" href="/vip/corridas">Ver corridas</a>
    <a class="btn secondary" href="/vip/estado">Ver estado actual</a>
</div>
