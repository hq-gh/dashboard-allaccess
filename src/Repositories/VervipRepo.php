<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use PDOStatement;

/**
 * Repositorio consolidado del módulo VIP (Verificador InfinityVIP).
 *
 * Lee de las tablas VERVIP_* en Neon:
 *   - VERVIP_corridas        : una fila por ejecución del job diario.
 *   - VERVIP_movimientos     : una fila por acción (grant/revoke) sobre un alumno.
 *   - VERVIP_estado_actual   : estado canónico por email.
 *
 * Solo lectura: este portal no escribe en las tablas del Verificador.
 */
final class VervipRepo
{
    /**
     * Lista las corridas más recientes (limit por defecto 50).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCorridas(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        $sql = 'SELECT id, started_at, finished_at, status, resync_intentos, '
             . '       total_grants, total_revokes, total_errores, mail_enviado, error_msg '
             . 'FROM VERVIP_corridas '
             . 'ORDER BY started_at DESC '
             . 'LIMIT :limit';

        $stmt = Database::get()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    /**
     * Devuelve una corrida por id o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function getCorrida(int $id): ?array
    {
        $sql = 'SELECT id, started_at, finished_at, status, resync_intentos, '
             . '       total_grants, total_revokes, total_errores, mail_enviado, error_msg '
             . 'FROM VERVIP_corridas '
             . 'WHERE id = :id';

        $stmt = Database::get()->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Todos los movimientos de una corrida (sin paginación).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMovimientosDeCorrida(int $id): array
    {
        $sql = 'SELECT id, corrida_id, email, nombre, plan_name, product_id, accion, '
             . '       status_hotmart, bettermode_member_id, resultado, intentos, error_msg, created_at '
             . 'FROM VERVIP_movimientos '
             . 'WHERE corrida_id = :cid '
             . 'ORDER BY created_at ASC, id ASC';

        $stmt = Database::get()->prepare($sql);
        $stmt->bindValue(':cid', $id, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    /**
     * Lista movimientos con filtros.
     *
     * Filtros aceptados: accion ('grant'|'revoke'), resultado ('success'|'failed'|'skipped'),
     * email (LIKE), desde (string fecha), hasta (string fecha).
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public function listMovimientos(array $filtros = []): array
    {
        [$where, $params] = $this->buildMovimientosWhere($filtros);

        $sql = 'SELECT id, corrida_id, email, nombre, plan_name, product_id, accion, '
             . '       status_hotmart, bettermode_member_id, resultado, intentos, error_msg, created_at '
             . 'FROM VERVIP_movimientos '
             . $where
             . ' ORDER BY created_at DESC, id DESC '
             . 'LIMIT 2000';

        $stmt = Database::get()->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    /**
     * Lista completa del estado canónico por alumno, ordenada por
     * última verificación descendente y email ascendente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEstado(): array
    {
        $sql = 'SELECT email, nombre, plan_name, product_id, status_hotmart, '
             . '       tiene_acceso_vip, bettermode_member_id, '
             . '       ultima_verificacion_at, ultimo_cambio_at '
             . 'FROM VERVIP_estado_actual '
             . 'ORDER BY ultima_verificacion_at DESC NULLS LAST, email ASC';

        $stmt = Database::get()->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0:string, 1:array<string, array{value:mixed, type:int}>}
     */
    private function buildMovimientosWhere(array $filtros): array
    {
        $conditions = [];
        /** @var array<string, array{value:mixed, type:int}> $params */
        $params = [];

        if (!empty($filtros['accion']) && in_array($filtros['accion'], ['grant', 'revoke'], true)) {
            $conditions[] = 'accion = :accion';
            $params[':accion'] = ['value' => (string) $filtros['accion'], 'type' => PDO::PARAM_STR];
        }
        if (!empty($filtros['resultado']) && in_array($filtros['resultado'], ['success', 'failed', 'skipped'], true)) {
            $conditions[] = 'resultado = :resultado';
            $params[':resultado'] = ['value' => (string) $filtros['resultado'], 'type' => PDO::PARAM_STR];
        }
        if (!empty($filtros['email'])) {
            $conditions[] = 'email ILIKE :email';
            $params[':email'] = ['value' => '%' . (string) $filtros['email'] . '%', 'type' => PDO::PARAM_STR];
        }
        if (!empty($filtros['desde'])) {
            $conditions[] = 'created_at >= :desde';
            $params[':desde'] = ['value' => (string) $filtros['desde'], 'type' => PDO::PARAM_STR];
        }
        if (!empty($filtros['hasta'])) {
            // Sumar un día para que el filtro "hasta YYYY-MM-DD" incluya todo ese día.
            $hasta = (string) $filtros['hasta'];
            $conditions[] = 'created_at < (:hasta::date + INTERVAL \'1 day\')';
            $params[':hasta'] = ['value' => $hasta, 'type' => PDO::PARAM_STR];
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }

    /**
     * @param array<string, array{value:mixed, type:int}> $params
     */
    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $info) {
            $stmt->bindValue($name, $info['value'], $info['type']);
        }
    }
}
