<?php declare(strict_types=1);

namespace App\Sync;

use App\Bettermode\BettermodeClient;
use App\Tyris\StadioClient;
use PDO;

/**
 * Motor de sincronización de permisos Bettermode (data-driven, idempotente).
 *
 * Seguro para Neon serverless (BD primero, fase API después, reconexión al cierre).
 * Lock por fila (run 'running' < 30 min => aborta).
 *
 * BLINDAJE: nunca revoca a admins/staff (rol Bettermode != Member o con
 * staffReasons) ni a emails en protected_members (lista blanca). A esos solo se
 * les puede AGREGAR acceso, jamás quitar.
 *
 * Modos: dry_run (no toca Bettermode) | apply. Opción grantsOnly: ejecuta solo
 * grants; los revokes se CALCULAN y reportan pero NO se ejecutan.
 */
final class PermissionSyncEngine
{
    /** Días de gracia de pago para programas 'subscription': si la recurrencia más
     *  reciente NOT_PAID lleva MÁS de estos días, el alumno pierde acceso a Diez Y PLAY
     *  (regla unificada). <= 8 días = sigue con acceso (gracia). Decisión de Rub 2026-07. */
    private const OVERDUE_DAYS = 8;
    private const TRIAL_DAYS = 7;   // ventana de acceso de un trial (STARTED) sin convertir

    private array $programs = [];
    private array $keyToPids = [];
    private array $spacesByKey = [];
    private array $managedSet = [];
    private array $protectedEmails = []; // email => true (lista blanca DB)
    private array $emailUcode = [];      // email => ucode (hotmart_identity)
    private array $ucodeEmails = [];     // ucode => [email,...]
    private PDO $pdo;
    /** @var callable */ private $pdoFactory;
    /** @var callable */ private $log;

    public function __construct(callable $pdoFactory, private BettermodeClient $bm, ?callable $logger = null)
    {
        $this->pdoFactory = $pdoFactory;
        $this->pdo = ($pdoFactory)();
        $this->log = $logger ?? function (string $m): void { fwrite(STDOUT, $m . "\n"); };
    }

    private function db(): PDO
    {
        try { $this->pdo->query('SELECT 1'); }
        catch (\Throwable $e) { $this->pdo = ($this->pdoFactory)(); }
        return $this->pdo;
    }
    private function say(string $m): void { ($this->log)($m); }
    private static function norm(?string $e): string { return mb_strtolower(trim((string) $e)); }
    private function gqlRetry(string $q, int $max = 5): array
    {
        $last = null;
        for ($i = 1; $i <= $max; $i++) {
            try { return $this->bm->query($q); }
            catch (\Throwable $e) { $last = $e; usleep(600000 * $i); }
        }
        throw $last;
    }

    public function run(string $mode = 'dry_run', bool $grantsOnly = false, string $trigger = 'cli'): array
    {
        if (!in_array($mode, ['dry_run', 'apply'], true)) throw new \InvalidArgumentException('mode inválido');
        $db = $this->db();
        // TTL/reclamo de lock zombie: una corrida que muere (ej. corte de conexión Neon pooled)
        // deja su fila 'running' sin cerrar y bloquearía las siguientes. Una corrida real tarda
        // ~10 min; cualquier 'running' > 30 min es zombie -> se marca 'failed' y se libera.
        // (Antes: ventana de 180 min sin limpieza -> zombies acumulados; ver memoria.)
        $db->exec("UPDATE permission_sync_runs SET status='failed', finished_at=NOW(),
                     message='lock zombie reclamado automáticamente (>30 min sin terminar)'
                   WHERE status='running' AND started_at < NOW() - INTERVAL '30 minutes'");
        $busy = (int) $db->query("SELECT COUNT(*) FROM permission_sync_runs WHERE status='running' AND started_at > NOW() - INTERVAL '30 minutes'")->fetchColumn();
        if ($busy > 0) {
            $db->prepare("INSERT INTO permission_sync_runs (mode,status,trigger_source,finished_at,message) VALUES (:m,'aborted_locked',:t,NOW(),'Otra corrida activa (<30min)')")
                ->execute([':m' => $mode, ':t' => $trigger]);
            return ['status' => 'aborted_locked'];
        }
        $db->prepare("INSERT INTO permission_sync_runs (mode,status,trigger_source) VALUES (:m,'running',:t)")->execute([':m' => $mode, ':t' => $trigger]);
        $runId = (int) $db->lastInsertId('permission_sync_runs_id_seq');
        try {
            // ===== FASE BD =====
            $this->loadConfig();
            $this->loadProtected();
            $this->loadIdentity();
            $this->say("Programas: " . implode(', ', array_keys($this->programs)) . " | spaces administrados: " . count($this->managedSet) . " | protegidos(lista blanca): " . count($this->protectedEmails));
            // FASE 1: calcular vigencia (3 modelos) y persistir user_program_validity.
            [$validityRows, $vipInfinityExpired] = $this->computeValidity();
            $this->persistValidity($runId, $validityRows);
            // FASE 2: construir espacios deseados leyendo SOLO user_program_validity.
            $desiredByEmail = $this->buildDesiredFromValidity($runId);
            $this->say("Emails con producto vigente: " . count(array_filter($desiredByEmail, fn($s) => !empty($s))));

            // ===== FASE API =====
            [$currentByMember, $emailToMember, $protectedMembers, $membersByEmail] = $this->fetchCurrentManagedMembership();
            $this->say("Miembros con espacio administrado: " . count($currentByMember) . " | admins/staff: " . count($protectedMembers));
            $report = $this->reconcile($mode, $grantsOnly, $currentByMember, $emailToMember, $protectedMembers, $desiredByEmail, $vipInfinityExpired, $runId, $membersByEmail);
            if (!empty($report['dup_accounts'])) $this->say("CUENTAS DUPLICADAS (mismo correo, >1 cuenta Bettermode): " . count($report['dup_accounts']) . " -> se otorgó a TODAS; revisar consolidación (hq@).");

            // ===== ACTUADOR PLAY (Tyris/Stadio) — misma validez, mismo run =====
            // Un fallo de PLAY NO aborta la corrida de Bettermode (que ya se ejecutó).
            try { $this->reconcilePlay($mode, $grantsOnly, $runId); }
            catch (\Throwable $e) { $this->say("PLAY reconcile FALLO (no aborta Bettermode): " . $e->getMessage()); }

            // ===== CIERRE =====
            $this->db()->prepare("UPDATE permission_sync_runs SET finished_at=NOW(), status=:s,
                users_processed=:up, users_changed=:uc, grants_ok=:go, grants_failed=:gf,
                revokes_ok=:ro, revokes_failed=:rf, accounts_created=:ac, accounts_missing=:am, message=:msg WHERE id=:id")
                ->execute([':s' => $report['status'], ':up' => $report['users_processed'], ':uc' => $report['users_changed'],
                    ':go' => $report['grants_ok'], ':gf' => $report['grants_failed'], ':ro' => $report['revokes_ok'],
                    ':rf' => $report['revokes_failed'], ':ac' => $report['accounts_created'], ':am' => $report['accounts_missing'],
                    ':msg' => 'mode=' . $mode . ($grantsOnly ? ' grants_only' : '') . ' revokes_pending=' . $report['revokes_pending'] . ' protected_skipped=' . $report['protected_skipped'], ':id' => $runId]);
            $report['run_id'] = $runId;
            return $report;
        } catch (\Throwable $e) {
            try { $this->db()->prepare("UPDATE permission_sync_runs SET finished_at=NOW(), status='failed', message=:m WHERE id=:id")
                ->execute([':m' => substr($e->getMessage(), 0, 500), ':id' => $runId]); } catch (\Throwable $_) {}
            throw $e;
        }
    }

    private function loadConfig(): void
    {
        foreach ($this->db()->query("SELECT * FROM program_config WHERE is_active ORDER BY sort_order") as $r) {
            $r['valid_statuses'] = $this->pgArray($r['valid_statuses']);
            $this->programs[$r['product_key']] = $r;
        }
        foreach ($this->db()->query("SELECT hotmart_product_id, product_key FROM hotmart_product_mapping WHERE is_active") as $r) {
            if (isset($this->programs[$r['product_key']])) $this->keyToPids[$r['product_key']][] = $r['hotmart_product_id'];
        }
        foreach ($this->db()->query("SELECT product_key, space_id, space_name FROM bettermode_spaces WHERE is_active") as $r) {
            if (!isset($this->programs[$r['product_key']])) continue;
            $this->spacesByKey[$r['product_key']][$r['space_id']] = $r['space_name'];
            $this->managedSet[$r['space_id']] = $r['space_name'];
        }
    }
    private function loadProtected(): void
    {
        foreach ($this->db()->query("SELECT LOWER(email) e FROM protected_members") as $r) $this->protectedEmails[$r['e']] = true;
    }
    private function loadIdentity(): void
    {
        foreach ($this->db()->query("SELECT ucode, LOWER(email) e FROM hotmart_identity") as $r) {
            $this->emailUcode[$r['e']] = $r['ucode'];
            $this->ucodeEmails[$r['ucode']][] = $r['e'];
        }
    }
    private function pgArray(string $s): array
    {
        $s = trim($s, '{}');
        return $s === '' ? [] : array_map(fn($x) => trim($x, '"'), explode(',', $s));
    }

    // ===================== ACTUADOR PLAY (Tyris/Stadio) =====================
    // Suspende/activa PLAY con la MISMA validez que Bettermode (user_program_validity de
    // esta corrida), para infinity + infinity_vip, matcheando por CUALQUIER correo del
    // ucode (hotmart_identity). Estado propio en tyris_play_state; auditoría en
    // tyris_play_runs. Sin correo. En dry_run/grantsOnly: solo ACTIVAR (nunca suspender).

    private function reconcilePlay(string $mode, bool $grantsOnly, int $runId): void
    {
        $db = $this->db();
        $this->ensurePlayTables();

        // NOTA: sin advisory lock. Era para coordinar con el cron viejo cron-tyris-play (ya
        // retirado). La concurrencia motor-vs-grants-only ya la evita el chequeo 'running' de
        // run(). Además los pg_advisory_lock de SESIÓN son poco confiables en el endpoint
        // POOLED de Neon (PgBouncer): se quedaban pegados y provocaban 'locked' falsos que
        // saltaban PLAY (incl. suspensiones). Quitado 2026-07-11.
        {
            // 1) validez PLAY = "al corriente en INFINITY" (infinity + infinity_full).
            // CAMBIO 28-jul-2026 (regla de Rub): PLAY requiere una suscripción INFINITY vigente.
            // Los privilegios VIP (infinity_vip / "ALL ACCESS") solo aplican si ADEMÁS tienes
            // Infinity; por eso se EXCLUYE del entitlement toda key con requires_program_key
            // (= infinity_vip, que depende de 'infinity'). infinity_full (8119954, Infinity+VIP)
            // SÍ confiere PLAY porque incluye Infinity. Los VIP-sin-Infinity ("pecadores") se
            // suspenden en el paso 1b. (Morosidad NULL-safe por subscriber_code, gracia
            // OVERDUE_DAYS, por PERSONA; trial vigente = entitled.)
            $infPids = [];
            foreach ($this->programs as $pk => $cfg) {
                if (($cfg['access_type'] ?? '') === 'subscription' && empty($cfg['requires_program_key'])) {
                    $infPids = array_merge($infPids, $this->keyToPids[$pk] ?? []);
                }
            }
            $valid = []; $invalid = [];
            if ($infPids) {
                foreach ($this->subscriptionStatusByEmail($infPids, ['ACTIVE', 'DELAYED']) as $e => $hc) {
                    if ($hc) $valid[$e] = true; else $invalid[$e] = true;
                }
                foreach (array_keys($this->trialActiveByEmail($infPids, self::TRIAL_DAYS)) as $e) { $valid[$e] = true; unset($invalid[$e]); }
            }

            // 2) expandir por ucode (compra + acceso). entitled gana sobre delinq.
            $entitled = $this->expandByUcode($valid);
            $delinq   = array_diff_key($this->expandByUcode($invalid), $entitled);

            // 1b) PECADORES: VIP (infinity_vip / ALL ACCESS) activo SIN Infinity vigente ->
            // NO deben tener PLAY. Se suman a delinq para suspenderlos. Los que además tienen
            // Infinity ya cayeron en $entitled (vía 'infinity'/'infinity_full') y se respetan.
            $vipPids = [];
            foreach ($this->programs as $pk => $cfg) {
                if (($cfg['access_type'] ?? '') === 'subscription' && !empty($cfg['requires_program_key'])) {
                    $vipPids = array_merge($vipPids, $this->keyToPids[$pk] ?? []);
                }
            }
            if ($vipPids) {
                $vipActive = $this->expandByUcode($this->subscriptionStatusByEmail($vipPids, ['ACTIVE', 'DELAYED']));
                foreach (array_keys($vipActive) as $e) {
                    if (!isset($entitled[$e])) { $delinq[$e] = true; }
                }
            }

            // PISO DE CORDURA: si NADIE resultó vigente pero SÍ hay morosos, algo está roto
            // (config/validez). Nunca suspendemos masivamente contra un 'valid' vacío.
            if (empty($entitled) && !empty($delinq)) {
                $this->say("PLAY: vigentes=0 con delinq=" . count($delinq) . " -> SANITY ABORT (no se suspende)");
                $this->registrarPlay($runId, 'real', 'sanity_empty_valid', 0, 0);
                return;
            }

            // 3) estado actual
            $suspNow = [];
            foreach ($db->query("SELECT LOWER(email) e FROM tyris_play_state WHERE suspended") as $r) $suspNow[$r['e']] = true;
            // 'seeded' = la tabla YA tiene estado (cualquier fila), NO "nadie suspendido ahora".
            $seeded = ((int) $db->query("SELECT COUNT(*) FROM tyris_play_state")->fetchColumn()) > 0;

            // 4) SEED inicial (estado vacío): marca los morosos como suspendidos SIN enviar a
            // Stadio (asume que Stadio ya refleja realidad, p.ej. lo dejó el cron viejo). Evita
            // una suspensión masiva sin tope en la primera corrida. Solo en apply completo.
            if (!$seeded) {
                if ($mode === 'apply' && !$grantsOnly) {
                    $this->aplicarEstadoPlay(array_keys($delinq), [], $this->playNames(array_keys($delinq)));
                    $this->say("PLAY: SEED inicial -> " . count($delinq) . " morosos marcados suspendidos (SIN enviar a Stadio)");
                    $this->registrarPlay($runId, 'real', 'seed', count($delinq), 0);
                } else {
                    $this->say("PLAY: estado sin sembrar; se siembra en la próxima corrida apply completa");
                    $this->registrarPlay($runId, $mode === 'dry_run' ? 'dry' : 'real', 'seed_pending', count($delinq), 0);
                }
                return;
            }

            // 5) delta. En dry_run/grantsOnly NUNCA se suspende (solo activar).
            $suspender = array_keys(array_diff_key($delinq, $suspNow));
            $activar   = array_keys(array_intersect_key($suspNow, $entitled));
            $soloActivar = ($mode === 'dry_run' || $grantsOnly);
            $suspToSend = $soloActivar ? [] : $suspender;
            $total = count($suspToSend) + count($activar);
            $maxDelta = (int) (getenv('TYRIS_PLAY_MAX_DELTA') ?: 40);

            if ($mode === 'dry_run') {
                $this->say("PLAY (dry): suspender=" . count($suspender) . " activar=" . count($activar) . " (no se envía)");
                $this->registrarPlay($runId, 'dry', 'dry_run', count($suspender), count($activar));
                return;
            }
            if ($total === 0) {
                $this->say("PLAY: sin cambios" . ($soloActivar ? " (grants-only)" : ""));
                $this->registrarPlay($runId, 'real', 'sin_cambios', 0, 0);
                return;
            }
            // Tope de acción masiva SIEMPRE (el bootstrap ya lo cubre el SEED). Bloquea la corrida
            // completa si el delta es anómalo; queda auditado para revisión manual.
            if ($total > $maxDelta) {
                $this->say("PLAY: delta $total > MAX_DELTA $maxDelta -> BLOQUEADO (no se envía, estado sin cambios)");
                $this->registrarPlay($runId, 'real', 'delta_blocked', count($suspToSend), count($activar));
                return;
            }

            // 6) CSV + enviar a Stadio
            $nombres = $this->playNames(array_merge($suspToSend, $activar));
            $rows = [];
            foreach ($suspToSend as $e) $rows[] = [trim((string) ($nombres[$e] ?? '')), $e, 'SUSPENDER'];
            foreach ($activar as $e)    $rows[] = [trim((string) ($nombres[$e] ?? '')), $e, 'ACTIVAR'];
            $res = (new StadioClient())->bulkStatusCsv(StadioClient::buildCsv($rows));
            if (empty($res['ok'])) {
                $this->say("PLAY: Stadio FALLO: " . ($res['error'] ?? '?') . " -> estado SIN cambios");
                $this->registrarPlay($runId, 'real', 'stadio_error', count($suspToSend), count($activar), $res, $res['error'] ?? null);
                return;
            }

            // 7) reconciliar estado. notFound = cuenta inexistente = TERMINAL (se persiste para
            // que no reingrese al delta y consuma el presupuesto). errors = TRANSITORIO (no se
            // marca, se reintenta la próxima). Así: suspender/activar procesados = enviados − errors.
            $err = StadioClient::errorEmails($res);
            $suspMark = array_values(array_diff($suspToSend, $err)); // incluye notFound -> queda suspended
            $actMark  = array_values(array_diff($activar,    $err)); // incluye notFound -> deja de reintentarse
            $this->aplicarEstadoPlay($suspMark, $actMark, $nombres);
            $this->say("PLAY: Stadio OK activated=" . ($res['activated'] ?? 0) . " suspended=" . ($res['suspended'] ?? 0) . " notFound=" . count($res['notFound'] ?? []) . " errors=" . count($res['errors'] ?? []));
            $this->registrarPlay($runId, 'real', 'sent_ok', count($suspToSend), count($activar), $res);
        }
    }

    /** Expande un set de correos a TODOS los correos del mismo ucode (hotmart_identity). */
    private function expandByUcode(array $emails): array
    {
        $out = [];
        foreach (array_keys($emails) as $e) {
            $out[$e] = true;
            $u = $this->emailUcode[$e] ?? null;
            if ($u !== null) foreach ($this->ucodeEmails[$u] ?? [] as $sib) $out[$sib] = true;
        }
        return $out;
    }

    /** Nombres por correo (subscriber_name) para poblar el CSV. @param string[] $emails */
    private function playNames(array $emails): array
    {
        $emails = array_values(array_unique($emails));
        if (!$emails) return [];
        $ph = implode(',', array_fill(0, count($emails), '?'));
        $st = $this->db()->prepare("SELECT LOWER(subscriber_email) e, MAX(subscriber_name) n FROM subscriptions WHERE LOWER(subscriber_email) IN ($ph) GROUP BY 1");
        $st->execute($emails);
        $out = []; foreach ($st as $r) $out[$r['e']] = (string) ($r['n'] ?? '');
        return $out;
    }

    /** Marca suspended=TRUE los $susp y suspended=FALSE los $act en tyris_play_state. */
    private function aplicarEstadoPlay(array $susp, array $act, array $nombres): void
    {
        $db = $this->db();
        $db->beginTransaction();
        $up = $db->prepare("INSERT INTO tyris_play_state (email,nombre,suspended,updated_at) VALUES (:e,:n,TRUE,NOW())
            ON CONFLICT (email) DO UPDATE SET nombre=EXCLUDED.nombre, suspended=TRUE, updated_at=NOW()");
        foreach ($susp as $e) $up->execute([':e' => $e, ':n' => $nombres[$e] ?? null]);
        $off = $db->prepare("UPDATE tyris_play_state SET suspended=FALSE, updated_at=NOW() WHERE email=:e");
        foreach ($act as $e) $off->execute([':e' => $e]);
        $db->commit();
    }

    /** Auditoría en tyris_play_runs (una fila por corrida del motor). Nunca aborta. */
    private function registrarPlay(int $runId, string $mode, string $status, int $nSusp, int $nAct, ?array $res = null, ?string $err = null): void
    {
        try {
            $st = $this->db()->prepare("INSERT INTO tyris_play_runs
                (run_id, mode, status, suspender_count, activar_count, notfound_count, errors_count, stadio_response, error_message)
                VALUES (:rid,:mode,:st,:ns,:na,:nf,:ne,:resp,:err) ON CONFLICT (run_id) DO NOTHING");
            $st->execute([
                ':rid'  => 'eng-' . $runId, ':mode' => $mode, ':st' => $status,
                ':ns'   => $nSusp, ':na' => $nAct,
                ':nf'   => count($res['notFound'] ?? []), ':ne' => count($res['errors'] ?? []),
                ':resp' => $res !== null ? json_encode($res, JSON_UNESCAPED_UNICODE) : null, ':err' => $err,
            ]);
        } catch (\Throwable $e) { $this->say("PLAY: no se pudo registrar corrida: " . $e->getMessage()); }
    }

    private function ensurePlayTables(): void
    {
        $this->db()->exec("CREATE TABLE IF NOT EXISTS tyris_play_state (
            email TEXT PRIMARY KEY, nombre TEXT, suspended BOOLEAN NOT NULL DEFAULT TRUE, updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
        $this->db()->exec("CREATE TABLE IF NOT EXISTS tyris_play_runs (
            id BIGSERIAL PRIMARY KEY, run_id TEXT NOT NULL UNIQUE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            mode TEXT NOT NULL, status TEXT NOT NULL, suspender_count INT NOT NULL DEFAULT 0, activar_count INT NOT NULL DEFAULT 0,
            notfound_count INT NOT NULL DEFAULT 0, errors_count INT NOT NULL DEFAULT 0, stadio_response JSONB, csv_payload TEXT, error_message TEXT)");
    }

    /**
     * Emails con una suscripción STARTED cuyo TRIAL sigue vigente: accession_date
     * (bigint ms) + $trialDays días >= ahora. Da acceso durante la prueba; al vencer,
     * si no convirtió a ACTIVE/al corriente, deja de aparecer aquí => pierde acceso.
     * @param array<int,string|int> $pids
     * @return array<string,bool>
     */
    private function trialActiveByEmail(array $pids, int $trialDays): array
    {
        if (!$pids) return [];
        $pl = '{' . implode(',', $pids) . '}';
        $st = $this->db()->prepare(
            "SELECT DISTINCT LOWER(TRIM(subscriber_email)) AS email
               FROM subscriptions
              WHERE product_id = ANY(:p::text[]) AND status = 'STARTED'
                AND subscriber_email <> '' AND accession_date IS NOT NULL
                AND to_timestamp(accession_date / 1000.0) + make_interval(days => :d) >= NOW()"
        );
        $st->execute([':p' => $pl, ':d' => $trialDays]);
        $out = [];
        foreach ($st as $r) $out[$r['email']] = true;
        return $out;
    }

    /**
     * Clasifica por PERSONA su vigencia de suscripción en los product_ids dados.
     * Devuelve [email => hasCurrent], donde hasCurrent = true si la persona tiene AL
     * MENOS UNA suscripción con status ∈ $statuses que esté AL CORRIENTE (sin recurrencia
     * NOT_PAID, o la más antigua impaga <= OVERDUE_DAYS días). Solo entran emails con
     * alguna suscripción en esos status; los que quedan en false están vencidos
     * >OVERDUE_DAYS días en TODAS sus suscripciones vigentes (esos pierden acceso).
     * dedup de subscription_transactions por (subscription_id, recurrency_number) reciente.
     *
     * @param array<int,string|int> $pids
     * @param array<int,string> $statuses
     * @return array<string,bool>
     */
    private function subscriptionStatusByEmail(array $pids, array $statuses): array
    {
        $pl = '{' . implode(',', $pids) . '}';
        $sl = '{' . implode(',', $statuses) . '}';
        // AL CORRIENTE = la recurrencia MÁS RECIENTE de la sub está pagada (o dentro de gracia).
        // 'pagada' por recurrencia = CUALQUIER tx PAID/COMPLETE/APPROVED. Antes se usaba la
        // NOT_PAID MÁS ANTIGUA (bug: marcaba moroso a quien saltó un mes viejo pero paga al día);
        // corregido 2026-07-03, mismo criterio que report-tyris-play.
        $st = $this->db()->prepare("
            WITH txr AS (
                SELECT subscription_id, recurrency_number,
                       bool_or(recurrency_status = 'PAID' OR purchase_status IN ('COMPLETE','APPROVED')) AS pagada,
                       MAX(recurrency_start_datetime) AS rstart
                FROM subscription_transactions
                GROUP BY subscription_id, recurrency_number
            ),
            ult AS (
                SELECT DISTINCT ON (subscription_id) subscription_id, pagada, rstart
                FROM txr ORDER BY subscription_id, recurrency_number DESC
            ),
            sub1 AS (
                -- Dedup por subscriber_code (llave UNIQUE real de la tabla), NO por subscription_id.
                -- Hotmart devuelve subscription_id NULL para ciertas subs; dedupar por subscription_id
                -- colapsaba TODAS las filas NULL en una sola (Postgres agrupa los NULL como un único
                -- grupo en DISTINCT ON) => ~117 suscriptores ACTIVE quedaban invisibles al motor y se
                -- les revocaba el acceso (bug detectado 2026-07-07, caso victor.ayala.gorra). Con NULL
                -- no hay match en 'ult' => has_current=TRUE (correcto: ACTIVE sin recurrencia impaga).
                SELECT DISTINCT ON (subscriber_code) subscription_id, LOWER(TRIM(subscriber_email)) email, status, product_id
                FROM subscriptions ORDER BY subscriber_code, synced_at DESC NULLS LAST
            )
            SELECT s.email,
                   BOOL_OR(u.subscription_id IS NULL
                           OR u.pagada
                           OR (u.rstart IS NOT NULL AND (CURRENT_DATE - to_timestamp(u.rstart / 1000.0)::date) <= :d)) AS has_current
            FROM sub1 s LEFT JOIN ult u ON u.subscription_id = s.subscription_id
            WHERE s.product_id = ANY(:p::text[]) AND s.status = ANY(:s::text[]) AND s.email <> ''
            GROUP BY s.email");
        $st->execute([':p' => $pl, ':s' => $sl, ':d' => self::OVERDUE_DAYS]);
        $out = [];
        foreach ($st as $r) $out[$r['email']] = ($r['has_current'] === true || $r['has_current'] === 't' || $r['has_current'] === '1');
        return $out;
    }

    /**
     * FASE 1 — Cálculo de vigencia por (email, product_key) para los 3 modelos.
     * Devuelve filas para user_program_validity (con access_type/start/end) y el
     * set de emails con infinity_vip caído por dependencia. NO toca Bettermode ni
     * arma espacios (eso es Fase 2, que lee de la tabla persistida).
     *
     * @return array{0:array,1:array} [validityRows, vipInfinityExpired]
     */
    private function computeValidity(): array
    {
        $vig = []; $nowMs = (int) (microtime(true) * 1000);
        foreach ($this->programs as $pk => $cfg) {
            $at = $cfg['access_type'];

            if ($at === 'subscription') {
                $pids = $this->keyToPids[$pk] ?? []; if (!$pids) continue;
                // Vigencia por PERSONA, no por suscripción suelta: válido si tiene AL MENOS UNA
                // suscripción en valid_statuses que esté AL CORRIENTE (recurrencia NOT_PAID más
                // antigua <= OVERDUE_DAYS días, o sin impagos). Solo pierde acceso si TODAS sus
                // suscripciones vigentes están vencidas >OVERDUE_DAYS días. (Evita revocar a quien
                // tiene una sub vieja/cancelada impaga pero otra activa y pagando.)
                // TRIAL: una suscripción STARTED da acceso SOLO durante su periodo de prueba
                // (accession_date + TRIAL_DAYS >= hoy). Fuera del trial, si NO convirtió a
                // ACTIVE/al corriente, pierde acceso. Decisión de Rub (2026-07-03): trial 7 días
                // con acceso a Bettermode + PLAY; vencido sin convertir => se le quita.
                $statusByEmail = $this->subscriptionStatusByEmail($pids, $cfg['valid_statuses']);
                $trialByEmail  = $this->trialActiveByEmail($pids, self::TRIAL_DAYS);
                $emails = array_unique(array_merge(array_keys($statusByEmail), array_keys($trialByEmail)));
                foreach ($emails as $email) {
                    $active = $statusByEmail[$email] ?? false;
                    $trial  = $trialByEmail[$email]  ?? false;
                    $valid  = $active || $trial;
                    $vig[$email][$pk] = ['valid' => $valid, 'type' => 'subscription', 'start' => null, 'end' => null,
                        'status' => $active ? 'active' : ($trial ? 'trial_active' : ('payment_overdue_' . self::OVERDUE_DAYS . 'd'))];
                }

            } elseif ($at === 'team_based') {
                $sd = $cfg['subdomain'] ?? null; if ($sd === null || $sd === '') continue;
                // Vigencia por el Team del alumno: club_students(subdomain) -> class_id ->
                // hotmart_club_classes.class_name -> "Team N" -> teams.fecha_inicio/fecha_fin.
                // Teams 1-39 (legacy) NO están en `teams` => el JOIN los descarta (sin acceso).
                // ACCESO INMEDIATO + PROTEGIDO (decisión de Rub): el alumno es vigente desde
                // que está inscrito y HASTA fecha_fin (NO se exige que el Team ya haya
                // iniciado). Así el cron NO le quita lo que el webhook otorgó al comprar
                // aunque su Team empiece después; solo expira al pasar fecha_fin.
                // Todo evaluado en zona horaria America/Mexico_City.
                $st = $this->db()->prepare("
                    WITH enroll AS (
                      SELECT DISTINCT LOWER(TRIM(cs.email)) email,
                             (regexp_match(hcc.class_name, '^Team [0-9]+'))[1] AS team_label
                        FROM club_students cs
                        JOIN hotmart_club_classes hcc
                          ON hcc.subdomain = cs.subdomain AND hcc.class_id = cs.class_id
                       WHERE cs.subdomain = :sd AND cs.email IS NOT NULL AND cs.email <> ''
                         AND hcc.class_name ~ '^Team [0-9]+')
                    SELECT e.email, t.fecha_inicio::text fi, t.fecha_fin::text ff,
                           ((NOW() AT TIME ZONE 'America/Mexico_City')::date <= t.fecha_fin) AS is_valid,
                           ((NOW() AT TIME ZONE 'America/Mexico_City')::date >= t.fecha_inicio) AS started
                      FROM enroll e JOIN teams t ON t.team = e.team_label
                     WHERE t.fecha_inicio IS NOT NULL AND t.fecha_fin IS NOT NULL");
                $st->execute([':sd' => $sd]);
                foreach ($st as $r) {
                    // Vigente = hoy <= fecha_fin. 'team_window' si el Team ya inició,
                    // 'team_pending_prestart' si compró/se inscribió antes del inicio
                    // (protegido). Vencido (fecha_fin<hoy) => sin acceso. Si un email cae
                    // en varios teams del mismo subdominio, gana cualquiera vigente.
                    if (!$r['is_valid']) { if (!isset($vig[$r['email']][$pk])) $vig[$r['email']][$pk] = ['valid' => false, 'type' => 'team_based', 'start' => $r['fi'], 'end' => $r['ff'], 'status' => 'team_window_expired']; continue; }
                    $status = ($r['started'] === true || $r['started'] === 't' || $r['started'] === '1') ? 'team_window' : 'team_pending_prestart';
                    $vig[$r['email']][$pk] = ['valid' => true, 'type' => 'team_based', 'start' => $r['fi'], 'end' => $r['ff'], 'status' => $status];
                }

            } else { // fixed_days
                $pids = $this->keyToPids[$pk] ?? []; if (!$pids) continue;
                $pl = '{' . implode(',', $pids) . '}'; $sl = '{' . implode(',', $cfg['valid_statuses']) . '}';
                $days = (int) $cfg['valid_days'];
                $st = $this->db()->prepare("SELECT LOWER(TRIM(buyer_email)) email, MAX(approved_date) latest FROM sales s
                    WHERE product_id = ANY(:p::text[]) AND status = ANY(:s::text[]) AND buyer_email IS NOT NULL AND buyer_email <> ''
                    AND NOT EXISTS (SELECT 1 FROM sales r WHERE r.transaction_id = s.transaction_id AND r.status IN ('REFUNDED','CHARGEBACK')) GROUP BY 1");
                $st->execute([':p' => $pl, ':s' => $sl]);
                foreach ($st as $r) {
                    if ($r['latest'] === null) continue;
                    $until = (int) $r['latest'] + $days * 86400000;
                    if ($nowMs < $until) $vig[$r['email']][$pk] = ['valid' => true, 'type' => 'fixed_days',
                        'start' => date('c', (int) ((int) $r['latest'] / 1000)), 'end' => date('c', (int) ($until / 1000)), 'status' => 'within_' . $days . 'd'];
                }
            }
        }

        // Dependencias (ej. infinity_vip requiere infinity vigente).
        $rows = []; $vipExp = [];
        foreach ($vig as $email => $progs) {
            foreach ($progs as $pk => $info) {
                $dep = $this->programs[$pk]['requires_program_key'] ?? null;
                $depOk = !$dep || (isset($progs[$dep]) && $progs[$dep]['valid']);
                $ok = $info['valid'] && $depOk;
                $reason = $ok ? $info['status'] : (!$depOk ? ('dependency_unmet:' . $dep) : $info['status']);
                $rows[] = [$email, $pk, $ok, $reason, $info['type'], $info['start'], $info['end'], $info['status']];
                if (!$ok && $pk === 'infinity_vip' && $info['valid'] && !$depOk) $vipExp[$email] = true;
            }
        }
        return [$rows, $vipExp];
    }

    private function persistValidity(int $runId, array $rows): void
    {
        if (!$rows) return;
        $db = $this->db();
        $ins = $db->prepare("INSERT INTO user_program_validity
            (run_id,email,product_key,is_valid,reason,access_type,access_start,access_end,valid_until,source_status)
            VALUES (:r,:e,:pk,:v,:rs,:at,:as,:ae,:vu,:ss)");
        $db->beginTransaction();
        foreach ($rows as [$email, $pk, $ok, $reason, $type, $start, $end, $status])
            $ins->execute([':r' => $runId, ':e' => $email, ':pk' => $pk, ':v' => $ok ? 1 : 0, ':rs' => $reason,
                ':at' => $type, ':as' => $start, ':ae' => $end, ':vu' => $end, ':ss' => $status]);
        $db->commit();
    }

    /**
     * FASE 2 (parte 1) — Construye los espacios deseados leyendo ÚNICAMENTE
     * user_program_validity (la corrida actual, is_valid) + bettermode_spaces.
     * No re-evalúa los modelos. Expande por ucode (un miembro matchea por
     * cualquiera de sus correos).
     */
    private function buildDesiredFromValidity(int $runId): array
    {
        $desired = [];
        $st = $this->db()->prepare("SELECT LOWER(email) email, product_key FROM user_program_validity WHERE run_id = :r AND is_valid");
        $st->execute([':r' => $runId]);
        foreach ($st as $r) {
            foreach (array_keys($this->spacesByKey[$r['product_key']] ?? []) as $sid) $desired[$r['email']][$sid] = true;
        }
        // Expandir a TODOS los emails del mismo ucode (compra + acceso).
        if ($this->ucodeEmails) {
            $byUcode = [];
            foreach ($desired as $email => $sp) {
                if (!$sp) continue;
                $u = $this->emailUcode[$email] ?? null; if ($u === null) continue;
                foreach (array_keys($sp) as $sid) $byUcode[$u][$sid] = true;
            }
            foreach ($byUcode as $u => $sp) {
                foreach ($this->ucodeEmails[$u] ?? [] as $em) {
                    if (!isset($desired[$em])) $desired[$em] = [];
                    foreach (array_keys($sp) as $sid) $desired[$em][$sid] = true;
                }
            }
        }
        return $desired;
    }

    /** @return array{0:array,1:array,2:array,3:array} currentByMember, emailToMember, protectedMembers, membersByEmail(email=>[member_id=>true]) */
    private function fetchCurrentManagedMembership(): array
    {
        $currentByMember = []; $emailToMember = []; $protectedMembers = []; $membersByEmail = [];
        foreach (array_keys($this->managedSet) as $spaceId) {
            $cursor = null;
            do {
                $after = $cursor ? ', after: ' . json_encode($cursor) : '';
                $q = 'query { spaceMembers(spaceId: ' . json_encode($spaceId) . ', limit:100' . $after . '){ pageInfo{ endCursor hasNextPage } nodes{ member{ id email role{ name } staffReasons } } } }';
                $d = $this->gqlRetry($q)['spaceMembers'] ?? [];
                foreach (($d['nodes'] ?? []) as $n) {
                    $m = $n['member'] ?? null;
                    if (!$m || empty($m['id'])) continue;
                    $currentByMember[$m['id']][$spaceId] = true;
                    $em = self::norm($m['email'] ?? '');
                    if ($em !== '') { $emailToMember[$em] = $m['id']; $membersByEmail[$em][$m['id']] = true; }
                    $roleName = $m['role']['name'] ?? '';
                    $isStaff = (!empty($m['staffReasons'])) || ($roleName !== '' && $roleName !== 'Member');
                    if ($isStaff) $protectedMembers[$m['id']] = true;
                }
                $cursor = ($d['pageInfo']['hasNextPage'] ?? false) ? ($d['pageInfo']['endCursor'] ?? null) : null;
            } while ($cursor !== null);
        }
        return [$currentByMember, $emailToMember, $protectedMembers, $membersByEmail];
    }

    private function reconcile(string $mode, bool $grantsOnly, array $currentByMember, array $emailToMember, array $protectedMembers, array $desiredByEmail, array $vipInfinityExpired, int $runId = 0, array $membersByEmail = []): array
    {
        $universe = [];
        foreach (array_keys($desiredByEmail) as $e) $universe[$e] = true;
        foreach ($emailToMember as $e => $_) $universe[$e] = true;

        $rep = ['status' => 'success', 'mode' => $mode, 'grants_only' => $grantsOnly, 'users_processed' => 0, 'users_changed' => 0,
            'grants_ok' => 0, 'grants_failed' => 0, 'revokes_ok' => 0, 'revokes_failed' => 0, 'revokes_pending' => 0, 'protected_skipped' => 0,
            'accounts_created' => 0, 'accounts_missing' => 0, 'accounts_dup_skipped' => 0, 'grants_by_space' => [], 'revokes_by_space' => [],
            'losing_all' => [], 'missing_accounts' => [], 'dup_accounts' => [], 'vip_infinity_expired' => array_keys($vipInfinityExpired), 'errors' => [], 'csv' => []];
        foreach ($this->programs as $pk => $_) if (empty($this->spacesByKey[$pk])) $rep['errors'][] = "Programa '$pk' sin espacios activos";

        $isDry = ($mode === 'dry_run');
        // Log por miembro: una fila por cada grant/revoke EJECUTADO (insert inmediato, así se
        // ve el avance en vivo y queda rastro). En dry_run no se inserta (no se ejecuta nada).
        $movIns = (!$isDry) ? $this->db()->prepare(
            "INSERT INTO permission_member_movements (run_id,email,member_id,space_id,action,ok,error,mode)
             VALUES (:r,:e,:m,:s,:a,:ok,:err,:mode)") : null;
        $logMov = function (string $email, ?string $memberId, string $sid, string $action, bool $ok, ?string $err) use ($movIns, $runId, $mode): void {
            if ($movIns === null) return;
            try { $movIns->execute([':r' => $runId ?: null, ':e' => $email, ':m' => $memberId, ':s' => $sid,
                ':a' => $action, ':ok' => $ok ? 'true' : 'false', ':err' => $err, ':mode' => $mode]); }
            catch (\Throwable $_) { /* el log de auditoría nunca debe tumbar la reconciliación */ }
        };
        foreach (array_keys($universe) as $email) {
            $rep['users_processed']++;
            if ($rep['users_processed'] % 1000 === 0)
                $this->say("reconcile: " . $rep['users_processed'] . " procesados | grants=" . $rep['grants_ok'] . " revokes=" . $rep['revokes_ok']);
            $desired = $desiredByEmail[$email] ?? [];

            // DUPLICADO: el mismo correo tiene MÁS DE UNA cuenta Bettermode (recreación/re-registro
            // tras borrado, verificación rebotada, etc.). Antes el motor se quedaba con UNA cuenta
            // (la última vista) y podía medir/otorgar contra la equivocada, dejando la cuenta real
            // del alumno vacía (caso daniela.monserrat 2026-07). Ahora: otorgar los espacios deseados
            // a TODAS las cuentas del correo (así la que use el alumno SÍ tiene acceso), NO revocar de
            // ninguna, y reportar para consolidación manual.
            $dupIds = array_keys($membersByEmail[$email] ?? []);
            if (count($dupIds) > 1) {
                $rep['dup_accounts'][] = ['email' => $email, 'members' => $dupIds, 'desired' => count($desired)];
                if (!empty($desired)) {
                    $changed = false;
                    foreach ($dupIds as $mid) {
                        foreach (array_keys(array_diff_key($desired, $currentByMember[$mid] ?? [])) as $sid) {
                            $changed = true;
                            $rep['grants_by_space'][$sid] = ($rep['grants_by_space'][$sid] ?? 0) + 1;
                            if ($isDry) { $rep['grants_ok']++; }
                            else { try { $this->bm->grantSpaceAccess($mid, $sid); $rep['grants_ok']++; $logMov($email, $mid, $sid, 'grant', true, null); }
                                   catch (\Throwable $e) { $msg = substr($e->getMessage(), 0, 120); $rep['grants_failed']++; $rep['errors'][] = "grant(dup) $email: " . substr($msg, 0, 40); $logMov($email, $mid, $sid, 'grant', false, $msg); } }
                        }
                    }
                    if ($changed) $rep['users_changed']++;
                    $rep['csv'][] = [$email, implode('|', $dupIds), count($desired), 0, 0, 0, '', 'DUP'];
                }
                continue; // NO seguir a la ruta de cuenta única (evita revocar de la cuenta buena)
            }

            $memberId = $emailToMember[$email] ?? null;
            if ($memberId === null && !empty($desired)) {
                try { $m = $this->bm->findMemberByEmail($email); $memberId = $m['id'] ?? null; }
                catch (\Throwable $e) { $rep['errors'][] = "find $email: " . substr($e->getMessage(), 0, 60); }
            }
            $current = $memberId !== null ? ($currentByMember[$memberId] ?? []) : [];
            $protected = ($memberId !== null && isset($protectedMembers[$memberId])) || isset($this->protectedEmails[$email]);

            $grants = array_diff_key($desired, $current);
            $revokes = $protected ? [] : array_diff_key($current, $desired); // protegidos: jamás revoke

            if ($memberId === null) {
                if (!empty($desired)) {
                    // ¿el ucode ya tiene cuenta bajo un correo hermano (compra/acceso)? -> NO crear duplicado;
                    // esa persona ya conserva acceso por el hermano (el deseo se expandió a ambos correos).
                    $u = $this->emailUcode[$email] ?? null; $dup = false;
                    if ($u !== null) foreach ($this->ucodeEmails[$u] ?? [] as $sib) if ($sib !== $email && isset($emailToMember[$sib])) { $dup = true; break; }
                    if ($dup) $rep['accounts_dup_skipped']++;
                    else { $rep['accounts_missing']++; $rep['missing_accounts'][] = $email; }
                }
                continue;
            }
            if ($protected && !empty(array_diff_key($current, $desired))) $rep['protected_skipped']++;
            if (empty($grants) && empty($revokes)) continue;
            $rep['users_changed']++;
            if (empty($desired) && !empty($current) && !$protected) $rep['losing_all'][] = ['email' => $email, 'spaces' => array_keys($current)];

            foreach (array_keys($grants) as $sid) {
                $rep['grants_by_space'][$sid] = ($rep['grants_by_space'][$sid] ?? 0) + 1;
                if ($isDry) $rep['grants_ok']++;
                else { try { $this->bm->grantSpaceAccess($memberId, $sid); $rep['grants_ok']++; $logMov($email, $memberId, $sid, 'grant', true, null); } catch (\Throwable $e) { $msg = substr($e->getMessage(), 0, 120); $rep['grants_failed']++; $rep['errors'][] = "grant $email: " . substr($msg, 0, 45); $logMov($email, $memberId, $sid, 'grant', false, $msg); } }
            }
            foreach (array_keys($revokes) as $sid) {
                $rep['revokes_by_space'][$sid] = ($rep['revokes_by_space'][$sid] ?? 0) + 1;
                if ($isDry || $grantsOnly) { $rep['revokes_pending']++; }   // calculado, NO ejecutado
                else { try { $this->bm->revokeSpaceAccess($memberId, $sid); $rep['revokes_ok']++; $logMov($email, $memberId, $sid, 'revoke', true, null); } catch (\Throwable $e) { $msg = substr($e->getMessage(), 0, 120); $rep['revokes_failed']++; $rep['errors'][] = "revoke $email: " . substr($msg, 0, 45); $logMov($email, $memberId, $sid, 'revoke', false, $msg); } }
            }
            $rep['csv'][] = [$email, $memberId, count($desired), count($current), count($grants), count($revokes), (empty($desired) && !empty($current) && !$protected) ? 'SI' : '', $protected ? 'PROT' : ''];
        }
        if ($rep['grants_failed'] > 0 || $rep['revokes_failed'] > 0) $rep['status'] = 'partial';
        $rep['managed_spaces'] = $this->managedSet;
        return $rep;
    }
}
