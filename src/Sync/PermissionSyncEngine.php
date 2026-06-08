<?php declare(strict_types=1);

namespace App\Sync;

use App\Bettermode\BettermodeClient;
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
            [$desiredByEmail, $validityRows, $vipInfinityExpired] = $this->computeDesired();
            $this->persistValidity($runId, $validityRows);
            $this->say("Emails con producto vigente: " . count(array_filter($desiredByEmail, fn($s) => !empty($s))));

            // ===== FASE API =====
            [$currentByMember, $emailToMember, $protectedMembers] = $this->fetchCurrentManagedMembership();
            $this->say("Miembros con espacio administrado: " . count($currentByMember) . " | admins/staff: " . count($protectedMembers));
            $report = $this->reconcile($mode, $grantsOnly, $currentByMember, $emailToMember, $protectedMembers, $desiredByEmail, $vipInfinityExpired);

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

    private function computeDesired(): array
    {
        $vig = []; $nowMs = (int) (microtime(true) * 1000);
        foreach ($this->programs as $pk => $cfg) {
            $pids = $this->keyToPids[$pk] ?? []; if (!$pids) continue;
            $pl = '{' . implode(',', $pids) . '}'; $sl = '{' . implode(',', $cfg['valid_statuses']) . '}';
            if ($cfg['access_type'] === 'subscription') {
                $st = $this->db()->prepare("SELECT DISTINCT LOWER(TRIM(subscriber_email)) email, status FROM subscriptions
                    WHERE product_id = ANY(:p::text[]) AND status = ANY(:s::text[]) AND subscriber_email IS NOT NULL AND subscriber_email <> ''");
                $st->execute([':p' => $pl, ':s' => $sl]);
                foreach ($st as $r) $vig[$r['email']][$pk] = ['valid_until' => null, 'status' => $r['status']];
            } else {
                $days = (int) $cfg['valid_days'];
                $st = $this->db()->prepare("SELECT LOWER(TRIM(buyer_email)) email, MAX(approved_date) latest FROM sales s
                    WHERE product_id = ANY(:p::text[]) AND status = ANY(:s::text[]) AND buyer_email IS NOT NULL AND buyer_email <> ''
                    AND NOT EXISTS (SELECT 1 FROM sales r WHERE r.transaction_id = s.transaction_id AND r.status IN ('REFUNDED','CHARGEBACK')) GROUP BY 1");
                $st->execute([':p' => $pl, ':s' => $sl]);
                foreach ($st as $r) {
                    if ($r['latest'] === null) continue;
                    $until = (int) $r['latest'] + $days * 86400000;
                    if ($nowMs < $until) $vig[$r['email']][$pk] = ['valid_until' => $until, 'status' => 'within_' . $days . 'd'];
                }
            }
        }
        $desired = []; $rows = []; $vipExp = [];
        foreach ($vig as $email => $progs) {
            $eff = [];
            foreach ($progs as $pk => $info) {
                $dep = $this->programs[$pk]['requires_program_key'] ?? null;
                $ok = !($dep && !isset($progs[$dep]));
                $rows[] = [$email, $pk, $ok, $ok ? $info['status'] : ('dependency_unmet:' . $dep), $info['valid_until'], $info['status']];
                if ($ok) $eff[$pk] = true; elseif ($pk === 'infinity_vip') $vipExp[$email] = true;
            }
            $sp = [];
            foreach (array_keys($eff) as $pk) foreach (array_keys($this->spacesByKey[$pk] ?? []) as $sid) $sp[$sid] = true;
            $desired[$email] = $sp;
        }
        // Expandir el deseo a TODOS los emails del mismo ucode (compra + acceso),
        // para que un miembro de Bettermode matchee por cualquiera de sus emails.
        if ($this->ucodeEmails) {
            $byUcode = [];
            foreach ($desired as $email => $sp) {
                if (!$sp) continue;
                $u = $this->emailUcode[$email] ?? null;
                if ($u === null) continue;
                foreach (array_keys($sp) as $sid) $byUcode[$u][$sid] = true;
            }
            foreach ($byUcode as $u => $sp) {
                foreach ($this->ucodeEmails[$u] ?? [] as $em) {
                    if (!isset($desired[$em])) $desired[$em] = [];
                    foreach (array_keys($sp) as $sid) $desired[$em][$sid] = true;
                }
            }
        }
        return [$desired, $rows, $vipExp];
    }

    private function persistValidity(int $runId, array $rows): void
    {
        if (!$rows) return;
        $db = $this->db();
        $ins = $db->prepare("INSERT INTO user_program_validity (run_id,email,product_key,is_valid,reason,valid_until,source_status) VALUES (:r,:e,:pk,:v,:rs,:vu,:ss)");
        $db->beginTransaction();
        foreach ($rows as [$email, $pk, $ok, $reason, $until, $status])
            $ins->execute([':r' => $runId, ':e' => $email, ':pk' => $pk, ':v' => $ok ? 1 : 0, ':rs' => $reason, ':vu' => $until ? date('c', (int) ($until / 1000)) : null, ':ss' => $status]);
        $db->commit();
    }

    /** @return array{0:array,1:array,2:array} currentByMember, emailToMember, protectedMembers(member_id=>true) */
    private function fetchCurrentManagedMembership(): array
    {
        $currentByMember = []; $emailToMember = []; $protectedMembers = [];
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
                    if ($em !== '') $emailToMember[$em] = $m['id'];
                    $roleName = $m['role']['name'] ?? '';
                    $isStaff = (!empty($m['staffReasons'])) || ($roleName !== '' && $roleName !== 'Member');
                    if ($isStaff) $protectedMembers[$m['id']] = true;
                }
                $cursor = ($d['pageInfo']['hasNextPage'] ?? false) ? ($d['pageInfo']['endCursor'] ?? null) : null;
            } while ($cursor !== null);
        }
        return [$currentByMember, $emailToMember, $protectedMembers];
    }

    private function reconcile(string $mode, bool $grantsOnly, array $currentByMember, array $emailToMember, array $protectedMembers, array $desiredByEmail, array $vipInfinityExpired): array
    {
        $universe = [];
        foreach (array_keys($desiredByEmail) as $e) $universe[$e] = true;
        foreach ($emailToMember as $e => $_) $universe[$e] = true;

        $rep = ['status' => 'success', 'mode' => $mode, 'grants_only' => $grantsOnly, 'users_processed' => 0, 'users_changed' => 0,
            'grants_ok' => 0, 'grants_failed' => 0, 'revokes_ok' => 0, 'revokes_failed' => 0, 'revokes_pending' => 0, 'protected_skipped' => 0,
            'accounts_created' => 0, 'accounts_missing' => 0, 'accounts_dup_skipped' => 0, 'grants_by_space' => [], 'revokes_by_space' => [],
            'losing_all' => [], 'missing_accounts' => [], 'vip_infinity_expired' => array_keys($vipInfinityExpired), 'errors' => [], 'csv' => []];
        foreach ($this->programs as $pk => $_) if (empty($this->spacesByKey[$pk])) $rep['errors'][] = "Programa '$pk' sin espacios activos";

        $isDry = ($mode === 'dry_run');
        foreach (array_keys($universe) as $email) {
            $rep['users_processed']++;
            $desired = $desiredByEmail[$email] ?? [];
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
                else { try { $this->bm->grantSpaceAccess($memberId, $sid); $rep['grants_ok']++; } catch (\Throwable $e) { $rep['grants_failed']++; $rep['errors'][] = "grant $email: " . substr($e->getMessage(), 0, 45); } }
            }
            foreach (array_keys($revokes) as $sid) {
                $rep['revokes_by_space'][$sid] = ($rep['revokes_by_space'][$sid] ?? 0) + 1;
                if ($isDry || $grantsOnly) { $rep['revokes_pending']++; }   // calculado, NO ejecutado
                else { try { $this->bm->revokeSpaceAccess($memberId, $sid); $rep['revokes_ok']++; } catch (\Throwable $e) { $rep['revokes_failed']++; $rep['errors'][] = "revoke $email: " . substr($e->getMessage(), 0, 45); } }
            }
            $rep['csv'][] = [$email, $memberId, count($desired), count($current), count($grants), count($revokes), (empty($desired) && !empty($current) && !$protected) ? 'SI' : '', $protected ? 'PROT' : ''];
        }
        if ($rep['grants_failed'] > 0 || $rep['revokes_failed'] > 0) $rep['status'] = 'partial';
        $rep['managed_spaces'] = $this->managedSet;
        return $rep;
    }
}
