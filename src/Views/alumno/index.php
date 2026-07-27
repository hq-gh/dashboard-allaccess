<?php
use App\Security;
$e = fn($v) => Security::e((string)($v ?? ''));

/** Clase de color para el badge de estatus de suscripción. */
$subBadge = function (string $raw): string {
    if ($raw === 'ACTIVE')  return 'ok';
    if ($raw === 'STARTED') return 'info';
    if (in_array($raw, ['DELAYED', 'OVERDUE'], true)) return 'warn';
    return 'muted'; // cancelaciones / inactivo
};
/** Clase de color para el estatus de un pago. */
$payBadge = function (string $raw): string {
    if ($raw === 'PAID')     return 'ok';
    if ($raw === 'NOT_PAID') return 'warn';
    return 'muted'; // reembolso / chargeback / reclamado
};
?>
<h1 class="page-title">Suscripciones de alumno</h1>
<p class="subtitle">Teclea el <strong>correo de compra</strong> del alumno para ver sus suscripciones, todos sus pagos y su historial de pagos tardíos.</p>

<form method="GET" action="/suscripciones" style="display:flex;gap:10px;margin-bottom:22px;max-width:560px">
    <input type="email" name="compra" value="<?= $e($compra) ?>" placeholder="correo-de-compra@dominio.com" autofocus
           style="flex:1;padding:11px 12px;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;color:var(--text-1)">
    <button type="submit" class="btn">Buscar</button>
</form>

<?php if (!empty($error)): ?>
    <div class="sub-note warn"><?= $e($error) ?></div>
<?php endif; ?>

<?php if ($result !== null && empty($error)): ?>
    <?php if (empty($result['found'])): ?>
        <div class="sub-note muted">
            No encontramos ninguna suscripción con el correo de compra <strong><?= $e($compra) ?></strong>.
            Verifica que sea el correo con el que se hizo la compra en Hotmart (no el de acceso a la plataforma).
        </div>
    <?php else:
        $buyer = $result['buyer'];
        $se    = $result['second_emails'];
    ?>
        <!-- Encabezado del alumno -->
        <div class="sub-buyer">
            <div class="sub-buyer-head">
                <div>
                    <div class="sub-buyer-name"><?= $e($buyer['name'] ?: '(sin nombre)') ?></div>
                    <div class="sub-buyer-mail">Correo de compra: <strong><?= $e($buyer['purchase_email']) ?></strong></div>
                </div>
                <div class="sub-buyer-count"><?= count($result['subscriptions']) ?> suscripción(es)</div>
            </div>

            <!-- Segundo correo de acceso -->
            <div class="sub-second">
                <?php if (!empty($se['confirmados'])): ?>
                    <?php foreach ($se['confirmados'] as $c): ?>
                        <div class="sub-chip ok" title="<?= $e($c['fuente']) ?>">
                            Otro correo de acceso: <strong><?= $e($c['email']) ?></strong>
                            <span class="sub-chip-tag">confirmado</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($se['probables'])): ?>
                    <?php foreach ($se['probables'] as $pmail): ?>
                        <div class="sub-chip warn" title="<?= $e($pmail['fuente']) ?>">
                            Posible correo de acceso: <strong><?= $e($pmail['email']) ?></strong>
                            <span class="sub-chip-tag">por nombre — verificar</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (empty($se['confirmados']) && empty($se['probables'])): ?>
                    <div class="sub-chip muted">No encontramos un segundo correo de acceso registrado.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Una tarjeta por suscripción -->
        <?php foreach ($result['subscriptions'] as $i => $s): ?>
            <div class="sub-card" data-sub="<?= (int)$i ?>">
                <div class="sub-card-title">
                    <span><?= $e($s['producto']) ?></span>
                    <span class="sub-badge <?= $subBadge($s['estatus_raw']) ?>"><?= $e($s['estatus']) ?></span>
                </div>

                <!-- Ficha tipo "Detalles de la Suscripción" de Hotmart -->
                <div class="sub-grid">
                    <div><span class="k">Código</span><span class="v"><?= $e($s['codigo']) ?></span></div>
                    <div><span class="k">Nombre del Plan</span><span class="v"><?= $e($s['plan']) ?></span></div>
                    <div><span class="k">Valor del plan</span><span class="v"><?= $e($s['valor_plan']) ?></span></div>
                    <div><span class="k">Fecha de Activación</span><span class="v"><?= $e($s['activacion']) ?></span></div>
                    <div><span class="k">Fecha del último pago</span><span class="v"><?= $e($s['ultimo_pago']) ?></span></div>
                    <div><span class="k">Día del Vencimiento</span><span class="v"><?= $e($s['dia_vencimiento']) ?></span></div>
                    <?php if ($s['dias_prueba'] !== null): ?>
                        <div><span class="k">Días de prueba</span><span class="v"><?= $e((string)$s['dias_prueba']) ?> días</span></div>
                    <?php endif; ?>
                    <?php if (!empty($s['fin_prueba'])): ?>
                        <div><span class="k">Fin del Período de Prueba</span><span class="v"><?= $e($s['fin_prueba']) ?></span></div>
                    <?php endif; ?>
                    <div><span class="k">Próximo cargo</span><span class="v"><?= $e($s['proximo_cargo']) ?></span></div>
                </div>

                <!-- Pestañas -->
                <div class="sub-tabs" role="tablist">
                    <button type="button" class="sub-tab active" data-tab="pagos">Pagos recurrentes (<?= (int)$s['total_pagos'] ?>)</button>
                    <button type="button" class="sub-tab" data-tab="retrasos">Retrasos<?= $s['total_tardios'] > 0 ? ' (' . (int)$s['total_tardios'] . ')' : '' ?></button>
                </div>

                <!-- Pestaña: Pagos -->
                <div class="sub-panel" data-panel="pagos">
                    <?php if (empty($s['pagos'])): ?>
                        <div class="sub-note muted">Sin pagos registrados.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr>
                                    <th>#</th><th>Transacción</th><th>Fecha</th>
                                    <th>Valor</th><th>Forma</th><th>Estatus</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($s['pagos'] as $p): ?>
                                    <tr<?= $p['tarde'] ? ' class="is-late"' : '' ?>>
                                        <td><?= (int)$p['num'] ?></td>
                                        <td class="mono"><?= $e($p['transaccion']) ?></td>
                                        <td><?= $e($p['fecha']) ?></td>
                                        <td class="num"><?= $e($p['valor']) ?></td>
                                        <td><?= $e($p['forma']) ?></td>
                                        <td><span class="sub-badge <?= $payBadge($p['estatus_raw']) ?>"><?= $e($p['estatus']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pestaña: Retrasos (histórico de pagos tardíos) -->
                <div class="sub-panel" data-panel="retrasos" hidden>
                    <?php if (empty($s['retrasos'])): ?>
                        <div class="sub-note ok">Sin pagos tardíos: este alumno ha pagado siempre a tiempo (<?= (int)$s['total_pagos'] ?> de <?= (int)$s['total_pagos'] ?>).</div>
                    <?php else: ?>
                        <div class="sub-note warn">Pagó tarde <strong><?= (int)$s['total_tardios'] ?></strong> de <?= (int)$s['total_pagos'] ?> recurrencias.</div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr>
                                    <th>#</th><th>Programada</th><th>Pagada</th>
                                    <th>Días tarde</th><th>Reintento</th><th>Retrasos (Hotmart)</th><th>Estatus</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($s['retrasos'] as $r): ?>
                                    <tr>
                                        <td><?= (int)$r['num'] ?></td>
                                        <td><?= $e($r['programada']) ?></td>
                                        <td><?= $e($r['pagada']) ?></td>
                                        <td class="num"><?= $r['dias_tarde'] !== null ? (int)$r['dias_tarde'] : '—' ?></td>
                                        <td><?= $r['reintento'] ? 'Sí' : 'No' ?></td>
                                        <td class="num"><?= (int)$r['delays'] ?></td>
                                        <td><?= $e($r['estatus']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('.sub-card').forEach(function (card) {
        var tabs   = card.querySelectorAll('.sub-tab');
        var panels = card.querySelectorAll('.sub-panel');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = tab.getAttribute('data-tab');
                tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
                panels.forEach(function (p) { p.hidden = (p.getAttribute('data-panel') !== target); });
            });
        });
    });
})();
</script>
