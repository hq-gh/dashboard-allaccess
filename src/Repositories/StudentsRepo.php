<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

/**
 * Estadísticas de alumnos a partir de public.club_students.
 *
 * El alumno se identifica por email (un alumno puede aparecer en N filas
 * de club_students, una por producto en el que está inscrito). Todas las
 * consultas agrupan por LOWER(email).
 *
 * Mapeo de enums a etiquetas humanas (lo hace la View):
 *   engagement: HIGH->Alto, MEDIUM->Mediano, LOW->Bajo, NONE->Ninguno
 *   status:     ACTIVE->Activo, BLOCKED->Bloqueado, BLOCKED_BY_OWNER->Bloqueado por dueño
 *   type:       BUYER->Comprado, IMPORTED->Importado, GUEST->Invitado
 *   role:       STUDENT->Estudiante, ADMIN->Admin, MODERATOR->Moderador
 */
final class StudentsRepo
{
    /**
     * Stats globales (KPI cards) con filtros opcionales.
     *
     * @param array{search?:string, product?:string} $filters
     * @return array{total_alumnos:int, avg_progress:float, engagement_label:string}
     */
    public function stats(array $filters = []): array
    {
        [$where, $params] = $this->whereClause($filters);

        $sql = "SELECT COUNT(DISTINCT LOWER(email)) AS total_alumnos,
                       COALESCE(AVG(progress_pct), 0)::numeric(6,1) AS avg_progress
                  FROM public.club_students
                  $where";
        $st = Database::get()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // Engagement representativo: el de mayor frecuencia entre los rows filtrados.
        $sqlEng = "SELECT engagement, COUNT(*) c
                     FROM public.club_students
                     $where
                    GROUP BY engagement
                    ORDER BY c DESC
                    LIMIT 1";
        $st2 = Database::get()->prepare($sqlEng);
        $st2->execute($params);
        $eng = (string) ($st2->fetchColumn() ?: '');
        $engLabel = ['HIGH' => 'Alto', 'MEDIUM' => 'Mediano', 'LOW' => 'Bajo', 'NONE' => 'Ninguno'][$eng] ?? '—';

        return [
            'total_alumnos'    => (int) ($r['total_alumnos'] ?? 0),
            'avg_progress'     => (float) ($r['avg_progress'] ?? 0),
            'engagement_label' => $engLabel,
        ];
    }

    /**
     * Listado paginado agrupado por email (1 fila por alumno).
     *
     * @param array{search?:string, product?:string, page?:int, per_page?:int} $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function listStudents(array $filters = []): array
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 15)));
        $offset  = ($page - 1) * $perPage;

        [$where, $params] = $this->whereClause($filters);

        // Total de alumnos únicos (para paginación).
        $sqlCount = "SELECT COUNT(*) FROM (SELECT 1 FROM public.club_students $where GROUP BY LOWER(email)) sub";
        $st = Database::get()->prepare($sqlCount);
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $sql = "SELECT
                    LOWER(email)                                      AS email_key,
                    MIN(email)                                        AS email,
                    MIN(name)                                         AS name,
                    MAX(last_access_date)                             AS last_access,
                    BOOL_OR(status = 'ACTIVE')                        AS is_active,
                    MAX(CASE engagement
                          WHEN 'HIGH'   THEN 3
                          WHEN 'MEDIUM' THEN 2
                          WHEN 'LOW'    THEN 1
                          ELSE 0
                        END)                                          AS engagement_score,
                    COUNT(*)                                          AS num_productos,
                    ARRAY_AGG(DISTINCT product_name)                  AS productos,
                    ROUND(AVG(progress_pct)::numeric, 1)              AS avg_progress
                FROM public.club_students
                $where
                GROUP BY LOWER(email)
                ORDER BY MAX(last_access_date) DESC NULLS LAST
                LIMIT $perPage OFFSET $offset";
        $st = Database::get()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Postgres devuelve los arrays como "{a,b,c}"; convertir a PHP array.
        foreach ($rows as &$r) {
            if (is_string($r['productos']) && $r['productos'] !== '') {
                $inner = trim($r['productos'], '{}');
                $r['productos'] = $inner === '' ? [] : array_map(
                    fn($s) => trim($s, '"'),
                    str_getcsv($inner)
                );
            } elseif (!is_array($r['productos'])) {
                $r['productos'] = [];
            }
        }
        unset($r);

        return [
            'rows'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Detalle por email: agregados + lista de productos del alumno.
     *
     * @return array{summary:array<string,mixed>|null, products:array<int,array<string,mixed>>}
     */
    public function getByEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') return ['summary' => null, 'products' => []];

        $sqlSum = "SELECT
                    MIN(email)                              AS email,
                    MIN(name)                               AS name,
                    MIN(first_access_date)                  AS first_access,
                    MAX(last_access_date)                   AS last_access,
                    SUM(access_count)                       AS total_access_count,
                    BOOL_OR(status = 'ACTIVE')              AS is_active,
                    MIN(type)                               AS type,
                    ROUND(AVG(progress_pct)::numeric, 1)    AS avg_progress,
                    MAX(CASE engagement
                          WHEN 'HIGH'   THEN 3
                          WHEN 'MEDIUM' THEN 2
                          WHEN 'LOW'    THEN 1
                          ELSE 0
                        END)                                AS engagement_score
                FROM public.club_students
               WHERE LOWER(email) = :email";
        $st = Database::get()->prepare($sqlSum);
        $st->execute([':email' => $email]);
        $summary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($summary === null || ($summary['email'] ?? null) === null) {
            return ['summary' => null, 'products' => []];
        }

        $sqlProd = "SELECT product_name, subdomain, progress_pct, class_id, role, type, status,
                           first_access_date, last_access_date, access_count
                      FROM public.club_students
                     WHERE LOWER(email) = :email
                     ORDER BY progress_pct DESC, product_name";
        $st = Database::get()->prepare($sqlProd);
        $st->execute([':email' => $email]);
        $products = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['summary' => $summary, 'products' => $products];
    }

    /** @return array<int, string> */
    public function listProductNames(): array
    {
        $rows = Database::get()->query(
            "SELECT DISTINCT product_name FROM public.club_students
              WHERE product_name IS NOT NULL AND product_name <> ''
              ORDER BY product_name"
        )->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    /**
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function whereClause(array $filters): array
    {
        $where  = [];
        $params = [];
        if (!empty($filters['search'])) {
            $where[] = "(name ILIKE :search OR email ILIKE :search)";
            $params[':search'] = '%' . trim((string) $filters['search']) . '%';
        }
        if (!empty($filters['product'])) {
            $where[] = "product_name = :product";
            $params[':product'] = (string) $filters['product'];
        }
        $sql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
        return [$sql, $params];
    }
}
