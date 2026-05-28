<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Repositories\VervipRepo;
use App\View;

/**
 * Controlador del módulo "Dashboard VIP".
 *
 * Reúne las páginas portadas del repo 5T4D10_InfinityVIP_Verificador:
 *   - Listado de corridas del job diario.
 *   - Detalle de una corrida (header + movimientos).
 *   - Historial filtrable de movimientos (+ export CSV).
 *   - Estado canónico por alumno (+ export CSV).
 *
 * Patrón: cada acción hace Auth::requireLogin() y luego renderiza una view
 * bajo src/Views/vip/* con $active = 'vip'.
 */
final class VipController
{
    /**
     * Home del módulo VIP. Redirige al listado de corridas.
     */
    public function index(): void
    {
        Auth::requireLogin();
        header('Location: /vip/corridas', true, 302);
        exit;
    }

    /**
     * Listado de las últimas corridas del job.
     */
    public function corridas(): void
    {
        Auth::requireLogin();

        $repo = new VervipRepo();
        $rows = $repo->listCorridas(50);

        // Stats simples para el hero (no consultamos COUNT separado, basta con la lista).
        $totalListadas = count($rows);
        $ultimaFecha   = $rows !== [] ? (string) ($rows[0]['started_at'] ?? '') : '';

        View::render('vip/corridas', [
            'title'         => 'Dashboard VIP · Corridas',
            'active'        => 'vip',
            'rows'          => $rows,
            'totalListadas' => $totalListadas,
            'ultimaFecha'   => $ultimaFecha,
        ]);
    }

    /**
     * Detalle de una corrida específica + sus movimientos.
     *
     * @param string $id Llega como string desde el router.
     */
    public function corridaDetail(string $id): void
    {
        Auth::requireLogin();

        if (!ctype_digit($id) || (int) $id <= 0) {
            http_response_code(404);
            View::render('vip/corridas', [
                'title'         => 'Dashboard VIP · Corridas',
                'active'        => 'vip',
                'rows'          => [],
                'totalListadas' => 0,
                'ultimaFecha'   => '',
                'errorMsg'      => 'ID de corrida inválido.',
            ]);
            return;
        }

        $corridaId = (int) $id;
        $repo      = new VervipRepo();
        $corrida   = $repo->getCorrida($corridaId);

        if ($corrida === null) {
            http_response_code(404);
            View::render('vip/corridas', [
                'title'         => 'Dashboard VIP · Corridas',
                'active'        => 'vip',
                'rows'          => [],
                'totalListadas' => 0,
                'ultimaFecha'   => '',
                'errorMsg'      => 'Corrida #' . $corridaId . ' no encontrada.',
            ]);
            return;
        }

        $movimientos = $repo->listMovimientosDeCorrida($corridaId);

        View::render('vip/corrida_detail', [
            'title'       => 'Dashboard VIP · Corrida #' . $corridaId,
            'active'      => 'vip',
            'corrida'     => $corrida,
            'movimientos' => $movimientos,
        ]);
    }

    /**
     * Historial de movimientos con filtros vía GET.
     */
    public function movimientos(): void
    {
        Auth::requireLogin();

        $filtros = $this->collectMovimientosFilters();
        $rows    = (new VervipRepo())->listMovimientos($filtros);

        View::render('vip/movimientos', [
            'title'   => 'Dashboard VIP · Movimientos',
            'active'  => 'vip',
            'rows'    => $rows,
            'filtros' => $filtros,
        ]);
    }

    /**
     * Export CSV del historial de movimientos con el mismo filtro de la vista.
     */
    public function movimientosCsv(): void
    {
        Auth::requireLogin();

        $filtros = $this->collectMovimientosFilters();
        $rows    = (new VervipRepo())->listMovimientos($filtros);

        $filename = 'vervip_movimientos_' . date('Y-m-d_H-i') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel.
        $headers = [
            'id', 'corrida_id', 'created_at', 'email', 'nombre', 'plan_name', 'product_id',
            'accion', 'status_hotmart', 'bettermode_member_id', 'resultado', 'intentos', 'error_msg',
        ];
        echo $this->csvLine($headers);

        foreach ($rows as $r) {
            $fecha = !empty($r['created_at'])
                ? date('Y-m-d H:i:s', strtotime((string) $r['created_at']))
                : '';
            echo $this->csvLine([
                (string) ($r['id']                   ?? ''),
                (string) ($r['corrida_id']           ?? ''),
                $fecha,
                (string) ($r['email']                ?? ''),
                (string) ($r['nombre']               ?? ''),
                (string) ($r['plan_name']            ?? ''),
                (string) ($r['product_id']           ?? ''),
                (string) ($r['accion']               ?? ''),
                (string) ($r['status_hotmart']       ?? ''),
                (string) ($r['bettermode_member_id'] ?? ''),
                (string) ($r['resultado']            ?? ''),
                (string) ($r['intentos']             ?? ''),
                (string) ($r['error_msg']            ?? ''),
            ]);
        }
    }

    /**
     * Export CSV unificado de altas y bajas (grant/revoke) para WhatsApp groups
     * y operación manual. Junta movimientos del cron + eventos del webhook.
     * Enriquece con nombre y teléfono desde sales_participants.
     *
     * Query params:
     *   desde (YYYY-MM-DD) — default: hoy - 30 días.
     *   hasta (YYYY-MM-DD) — default: hoy.
     */
    public function altasBajasCsv(): void
    {
        Auth::requireLogin();

        $desde = (string) ($_GET['desde'] ?? date('Y-m-d', strtotime('-30 days')));
        $hasta = (string) ($_GET['hasta'] ?? date('Y-m-d'));

        // Validación básica del formato YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

        $rows = (new VervipRepo())->exportAltasBajas($desde, $hasta);

        $filename = 'altas_bajas_' . $desde . '_a_' . $hasta . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel.
        echo $this->csvLine(['Acción', 'Fecha', 'Nombre', 'Email', 'Teléfono', 'Programa', 'Origen']);

        $programaMap = [
            'infinity'       => 'Infinity',
            'infinity_vip'   => 'Infinity VIP',
            'mommy_comeback' => 'Mommy Comeback',
        ];
        $accionMap = ['grant' => 'ALTA', 'revoke' => 'BAJA'];

        foreach ($rows as $r) {
            $fecha = !empty($r['fecha']) ? date('Y-m-d H:i', strtotime((string) $r['fecha'])) : '';
            $prog  = (string) ($r['programa'] ?? '');
            $prog  = $programaMap[$prog] ?? $prog;
            $accion = $accionMap[(string) ($r['accion'] ?? '')] ?? (string) ($r['accion'] ?? '');
            echo $this->csvLine([
                $accion,
                $fecha,
                (string) ($r['nombre']   ?? ''),
                (string) ($r['email']    ?? ''),
                (string) ($r['telefono'] ?? ''),
                $prog,
                (string) ($r['origen']   ?? ''),
            ]);
        }
    }

    /**
     * Estado canónico por alumno.
     */
    public function estado(): void
    {
        Auth::requireLogin();

        $rows = (new VervipRepo())->listEstado();

        View::render('vip/estado', [
            'title'  => 'Dashboard VIP · Estado actual',
            'active' => 'vip',
            'rows'   => $rows,
        ]);
    }

    /**
     * Export CSV del estado canónico completo.
     */
    public function estadoCsv(): void
    {
        Auth::requireLogin();

        $rows = (new VervipRepo())->listEstado();

        $filename = 'vervip_estado_' . date('Y-m-d_H-i') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo "\xEF\xBB\xBF";
        $headers = [
            'email', 'nombre', 'plan_name', 'product_id', 'status_hotmart',
            'tiene_acceso_vip', 'bettermode_member_id',
            'ultima_verificacion_at', 'ultimo_cambio_at',
        ];
        echo $this->csvLine($headers);

        foreach ($rows as $r) {
            $acceso  = $this->boolToCsv($r['tiene_acceso_vip'] ?? null);
            $ultV    = !empty($r['ultima_verificacion_at'])
                ? date('Y-m-d H:i:s', strtotime((string) $r['ultima_verificacion_at']))
                : '';
            $ultC    = !empty($r['ultimo_cambio_at'])
                ? date('Y-m-d H:i:s', strtotime((string) $r['ultimo_cambio_at']))
                : '';
            echo $this->csvLine([
                (string) ($r['email']                ?? ''),
                (string) ($r['nombre']               ?? ''),
                (string) ($r['plan_name']            ?? ''),
                (string) ($r['product_id']           ?? ''),
                (string) ($r['status_hotmart']       ?? ''),
                $acceso,
                (string) ($r['bettermode_member_id'] ?? ''),
                $ultV,
                $ultC,
            ]);
        }
    }

    /**
     * Lee y normaliza los filtros del historial de movimientos.
     *
     * @return array<string, string>
     */
    private function collectMovimientosFilters(): array
    {
        $filtros = [];
        foreach (['accion', 'resultado', 'email', 'desde', 'hasta'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $v = trim($_GET[$key]);
                if ($v !== '') {
                    $filtros[$key] = $v;
                }
            }
        }

        // Whitelist de enums.
        if (isset($filtros['accion']) && !in_array($filtros['accion'], ['grant', 'revoke'], true)) {
            unset($filtros['accion']);
        }
        if (isset($filtros['resultado']) && !in_array($filtros['resultado'], ['success', 'failed', 'skipped'], true)) {
            unset($filtros['resultado']);
        }

        return $filtros;
    }

    /**
     * Convierte un valor booleano "crudo" de Postgres (bool/string/int) a '1' o '0' para CSV.
     */
    private function boolToCsv(mixed $v): string
    {
        if (is_bool($v))   return $v ? '1' : '0';
        if (is_int($v))    return $v === 1 ? '1' : '0';
        if (is_string($v)) return in_array(strtolower($v), ['1','t','true','yes','y'], true) ? '1' : '0';
        return '0';
    }

    /**
     * Una línea CSV RFC 4180: cada campo envuelto en "..." y comillas internas duplicadas.
     *
     * @param array<int, mixed> $fields
     */
    private function csvLine(array $fields): string
    {
        $esc = static function ($v): string {
            $s = (string) ($v ?? '');
            return '"' . str_replace('"', '""', $s) . '"';
        };
        return implode(',', array_map($esc, $fields)) . "\r\n";
    }
}

// Rutas a registrar:
//   GET  /vip                      -> index
//   GET  /vip/corridas             -> corridas
//   GET  /vip/corridas/{id}        -> corridaDetail
//   GET  /vip/movimientos          -> movimientos
//   GET  /vip/movimientos.csv      -> movimientosCsv
//   GET  /vip/estado               -> estado
//   GET  /vip/estado.csv           -> estadoCsv
