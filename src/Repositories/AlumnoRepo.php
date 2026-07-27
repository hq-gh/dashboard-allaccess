<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;

/**
 * Consulta de suscripciones por alumno para el módulo "Suscripciones" del portal
 * de Success (lo usan Cami e Iza para revisar el estado de un alumno).
 *
 * Entrada: el CORREO DE COMPRA (subscriptions.subscriber_email).
 * Devuelve, por cada suscripción del alumno:
 *   - la ficha tipo "Detalles de la Suscripción" de Hotmart (encabezado),
 *   - la tabla completa de Pagos Recurrentes (1 fila por recurrencia),
 *   - el histórico de pagos tardíos (recurrencias que se pagaron con retraso).
 * Además intenta detectar un SEGUNDO correo de acceso cruzando fuentes:
 *   - confirmado: hotmart_identity (por ucode),
 *   - probable:   bettermode_members (por nombre) — se marca "verificar".
 *
 * Toda la data vive en la misma Neon que alimenta el Dashboard PTT (suscripciones
 * y transacciones se sincronizan desde Hotmart por el cron-resync de ese repo).
 */
final class AlumnoRepo
{
    private const TZ = 'America/Mexico_City';

    /** Días de tolerancia: por debajo de esto NO se considera pago tardío. */
    private const LATE_GRACE_DAYS = 2;

    /**
     * Busca al alumno por su correo de compra y arma el detalle completo.
     *
     * @return array{found:bool, buyer?:array, second_emails?:array, subscriptions?:array}
     */
    public function findByPurchaseEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return ['found' => false];
        }

        $db = Database::get();

        $stmt = $db->prepare(
            "SELECT subscriber_code, subscription_id, subscriber_name, subscriber_email,
                    subscriber_ucode, status, plan_name, product_name, product_id,
                    price_value, price_currency, plan_recurrency_period,
                    accession_date, date_next_charge, trial
               FROM subscriptions
              WHERE lower(subscriber_email) = :email
              ORDER BY accession_date DESC NULLS LAST"
        );
        $stmt->execute([':email' => $email]);
        $subs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$subs) {
            return ['found' => false];
        }

        // Datos base del alumno (nombre + correos/ucodes conocidos).
        $name   = '';
        $ucodes = [];
        $known  = [$email];
        foreach ($subs as $s) {
            if ($name === '' && !empty($s['subscriber_name'])) {
                $name = (string) $s['subscriber_name'];
            }
            if (!empty($s['subscriber_ucode'])) {
                $ucodes[] = (string) $s['subscriber_ucode'];
            }
            if (!empty($s['subscriber_email'])) {
                $known[] = strtolower((string) $s['subscriber_email']);
            }
        }
        $ucodes = array_values(array_unique($ucodes));
        $known  = array_values(array_unique($known));

        $subscriptions = [];
        foreach ($subs as $s) {
            $subscriptions[] = $this->buildSubscription($db, $s);
        }

        return [
            'found'         => true,
            'buyer'         => [
                'name'           => $name,
                'purchase_email' => $email,
                'ucodes'         => $ucodes,
            ],
            'second_emails' => $this->findSecondEmails($db, $ucodes, $name, $known),
            'subscriptions' => $subscriptions,
        ];
    }

    /** Arma una suscripción: encabezado + pagos + retrasos. */
    private function buildSubscription(\PDO $db, array $s): array
    {
        $code = (string) $s['subscriber_code'];

        $stmt = $db->prepare(
            "SELECT recurrency_number, recurrency_status, purchase_transaction,
                    purchase_approved_date, purchase_order_date, recurrency_start_datetime,
                    purchase_price_value, purchase_price_total_value, purchase_price_currency,
                    purchase_payment_type, purchase_credit_card_flag,
                    has_retry, recurrency_payment_delays, trial_period, trial_end
               FROM subscription_transactions
              WHERE subscriber_code = :code
              ORDER BY recurrency_number ASC"
        );
        $stmt->execute([':code' => $code]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $pagos       = [];
        $retrasos    = [];
        $trialPeriod = $s['trial'] ? null : null; // se llena desde tx
        $trialEnd    = null;
        $ultimoPago  = null;

        foreach ($rows as $r) {
            $approved = $this->toInt($r['purchase_approved_date']);
            $recStart = $this->toInt($r['recurrency_start_datetime']);
            $status   = (string) ($r['recurrency_status'] ?? '');
            $value    = $r['purchase_price_value'];
            if ($value === null) {
                $value = $r['purchase_price_total_value'];
            }

            if ($r['trial_period'] !== null && $trialPeriod === null) {
                $trialPeriod = (int) $r['trial_period'];
            }
            if ($r['trial_end'] !== null && $trialEnd === null) {
                $trialEnd = $this->toInt($r['trial_end']);
            }
            if ($status === 'PAID' && $approved !== null) {
                if ($ultimoPago === null || $approved > $ultimoPago) {
                    $ultimoPago = $approved;
                }
            }

            $delays   = $r['recurrency_payment_delays'] !== null ? (int) $r['recurrency_payment_delays'] : 0;
            $hasRetry = (bool) $r['has_retry'];
            $daysLate = ($status === 'PAID' && $approved !== null && $recStart !== null)
                ? (int) floor(($approved - $recStart) / 86400000)
                : 0;
            $isLate = $hasRetry || $delays > 0 || ($status === 'PAID' && $daysLate >= self::LATE_GRACE_DAYS);

            $pago = [
                'num'          => (int) $r['recurrency_number'],
                'transaccion'  => (string) ($r['purchase_transaction'] ?? '—'),
                'fecha'        => $this->fmt($approved ?? $recStart),
                'valor'        => $this->money($value, (string) ($r['purchase_price_currency'] ?? '')),
                'forma'        => $this->payLabel((string) ($r['purchase_payment_type'] ?? ''), $r['purchase_credit_card_flag']),
                'estatus'      => $this->recLabel($status),
                'estatus_raw'  => $status,
                'tarde'        => $isLate,
            ];
            $pagos[] = $pago;

            if ($isLate) {
                $retrasos[] = [
                    'num'         => (int) $r['recurrency_number'],
                    'programada'  => $this->fmt($recStart),
                    'pagada'      => $this->fmt($approved),
                    'dias_tarde'  => $daysLate > 0 ? $daysLate : null,
                    'reintento'   => $hasRetry,
                    'delays'      => $delays,
                    'estatus'     => $this->recLabel($status),
                ];
            }
        }

        $accession    = $this->toInt($s['accession_date']);
        $nextCharge   = $this->toInt($s['date_next_charge']);
        $totalPagos   = count($pagos);
        $totalTardios = count($retrasos);

        return [
            'codigo'          => (string) $s['subscriber_code'],
            'estatus'         => $this->subStatusLabel((string) $s['status']),
            'estatus_raw'     => (string) $s['status'],
            'plan'            => (string) ($s['plan_name'] ?? '—'),
            'producto'        => (string) ($s['product_name'] ?? '—'),
            'valor_plan'      => $this->planValue($s['price_value'], (string) ($s['price_currency'] ?? ''), $this->toInt($s['plan_recurrency_period'])),
            'activacion'      => $this->fmt($accession),
            'dias_prueba'     => $s['trial'] ? ($trialPeriod ?? null) : null,
            'fin_prueba'      => $trialEnd ? $this->fmtDate($trialEnd) : null,
            'ultimo_pago'     => $ultimoPago ? $this->fmt($ultimoPago) : '—',
            'dia_vencimiento' => $nextCharge ? $this->fmt($nextCharge, 'j') : '—',
            'proximo_cargo'   => $nextCharge ? $this->fmt($nextCharge) : '—',
            'pagos'           => $pagos,
            'retrasos'        => $retrasos,
            'total_pagos'     => $totalPagos,
            'total_tardios'   => $totalTardios,
        ];
    }

    /**
     * Detecta un segundo correo de acceso distinto al de compra.
     * Nivel 1 (confirmado): hotmart_identity por ucode.
     * Nivel 2 (probable):   bettermode_members por nombre (marcar "verificar").
     */
    private function findSecondEmails(\PDO $db, array $ucodes, string $name, array $known): array
    {
        $confirmados = [];
        $probables   = [];

        if ($ucodes) {
            $ph = implode(',', array_fill(0, count($ucodes), '?'));
            $st = $db->prepare(
                "SELECT DISTINCT lower(email) AS email, email_type
                   FROM hotmart_identity
                  WHERE ucode IN ($ph)"
            );
            $st->execute($ucodes);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $em = (string) $r['email'];
                if ($em !== '' && !in_array($em, $known, true)) {
                    $confirmados[$em] = ['email' => $em, 'fuente' => 'Hotmart (identidad)', 'tipo' => (string) $r['email_type']];
                    $known[] = $em;
                }
            }
        }

        $nm = strtolower(trim($name));
        if ($nm !== '') {
            $st = $db->prepare(
                "SELECT DISTINCT lower(email) AS email
                   FROM bettermode_members
                  WHERE lower(trim(name)) = :nm
                    AND email IS NOT NULL
                    AND email NOT ILIKE '%deleted%'"
            );
            $st->execute([':nm' => $nm]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $em = (string) $r['email'];
                if ($em !== '' && !in_array($em, $known, true) && !isset($confirmados[$em])) {
                    $probables[$em] = ['email' => $em, 'fuente' => 'DIEZ / Bettermode (por nombre)'];
                    $known[] = $em;
                }
            }
        }

        return [
            'confirmados' => array_values($confirmados),
            'probables'   => array_values($probables),
        ];
    }

    // ---------- helpers de formato ----------

    private function toInt($v): ?int
    {
        if ($v === null || $v === '') return null;
        return (int) $v;
    }

    /** Epoch en milisegundos → string en horario CDMX. */
    private function fmt(?int $ms, string $format = 'd/m/Y H:i:s'): string
    {
        if ($ms === null || $ms <= 0) return '—';
        $dt = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone(self::TZ));
        return $dt->format($format);
    }

    /**
     * Epoch → solo fecha, SIN convertir zona. Hotmart guarda algunos campos de
     * fecha (fin de prueba) como fecha a medianoche UTC; convertir a CDMX los
     * recorría un día. Se muestra la fecha tal cual (dd/mm/aaaa).
     */
    private function fmtDate(?int $ms): string
    {
        if ($ms === null || $ms <= 0) return '—';
        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->format('d/m/Y');
    }

    private function money($value, string $currency): string
    {
        $n = $value === null ? 0.0 : (float) $value;
        $cur = $currency !== '' ? $currency . '$ ' : '$';
        return $cur . number_format($n, 2, '.', ',');
    }

    private function planValue($value, string $currency, ?int $period): string
    {
        $base = $this->money($value, $currency);
        if ($period) {
            $base .= " (cada {$period} días)";
        }
        return $base;
    }

    private function subStatusLabel(string $s): string
    {
        return [
            'ACTIVE'                => 'Activo',
            'INACTIVE'              => 'Inactivo',
            'DELAYED'               => 'Atrasado',
            'STARTED'               => 'Iniciada',
            'OVERDUE'               => 'Vencida',
            'CANCELLED_BY_CUSTOMER' => 'Cancelada (cliente)',
            'CANCELLED_BY_ADMIN'    => 'Cancelada (admin)',
            'CANCELLED_BY_SELLER'   => 'Cancelada (vendedor)',
            'TRIAL'                 => 'En prueba',
        ][$s] ?? $s;
    }

    private function recLabel(string $s): string
    {
        return [
            'PAID'      => 'Pagado',
            'NOT_PAID'  => 'No pagado',
            'REFUNDED'  => 'Reembolsado',
            'CHARGEBACK'=> 'Chargeback',
            'CLAIMED'   => 'Reclamado',
        ][$s] ?? ($s !== '' ? $s : '—');
    }

    private function payLabel(string $t, $ccFlag): string
    {
        $label = [
            'CREDIT_CARD'           => 'Tarjeta',
            'PAYPAL'                => 'PayPal',
            'APPLE_PAY'             => 'Apple Pay',
            'CASH_PAYMENT'          => 'Efectivo',
            'DIRECT_BANK_TRANSFER'  => 'Transferencia',
        ][$t] ?? ($t !== '' ? $t : '—');
        if ($t === 'CREDIT_CARD' && !empty($ccFlag)) {
            $label .= ' (' . ucfirst(strtolower((string) $ccFlag)) . ')';
        }
        return $label;
    }
}
