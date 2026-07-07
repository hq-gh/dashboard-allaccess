<?php declare(strict_types=1);

namespace App\Repositories;

use App\Database;

/**
 * Consumo de video en PLAY (Tyris/Galgo). Tabla `play_consumo` — 1 fila por sesión de
 * visualización. Se alimenta por carga MANUAL semanal del CSV "Consumo por usuario"
 * (upsert idempotente por clave natural, así exports traslapados no duplican).
 */
final class PlayRepo
{
    /** Crea la tabla si no existe (idempotente). */
    public function ensureTable(): void
    {
        $db = Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS public.play_consumo (
            id BIGSERIAL PRIMARY KEY,
            email TEXT NOT NULL, title TEXT NOT NULL,
            programa TEXT, semana INT, dia TEXT,
            session_start TIMESTAMPTZ, session_end TIMESTAMPTZ,
            duration_sec INT, platform TEXT, city TEXT, country TEXT,
            imported_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE (email, session_start, title))");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_play_email ON public.play_consumo (email)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_play_prog_sem ON public.play_consumo (programa, semana)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_play_start ON public.play_consumo (session_start)");
    }

    /**
     * Importa el CSV de Tyris (upsert). Devuelve [leidas, nuevas].
     * @throws \RuntimeException si el archivo no abre o el header no coincide.
     */
    public function importCsv(string $path): array
    {
        $this->ensureTable();
        $f = @fopen($path, 'r');
        if ($f === false) throw new \RuntimeException('No se pudo abrir el archivo.');
        $bom = fread($f, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($f);
        $header = fgetcsv($f);
        if (!$header || count($header) < 9) { fclose($f); throw new \RuntimeException('CSV inesperado (se esperaban 9 columnas: Email, Título, Sesión, Plataforma, Ciudad, País, Comienzo, Fin, Duración).'); }

        $db = Database::get();
        $cols = ['email','title','programa','semana','dia','session_start','session_end','duration_sec','platform','city','country'];
        $batch = []; $BN = 500; $leidas = 0; $nuevas = 0;
        $flush = function () use (&$batch, &$nuevas, $db, $cols) {
            if (!$batch) return;
            $ph = []; $vals = [];
            foreach ($batch as $row) { $ph[] = '(' . implode(',', array_fill(0, count($cols), '?')) . ')'; foreach ($cols as $c) $vals[] = $row[$c]; }
            $sql = "INSERT INTO public.play_consumo (" . implode(',', $cols) . ") VALUES " . implode(',', $ph)
                 . " ON CONFLICT (email, session_start, title) DO NOTHING";
            $st = $db->prepare($sql); $st->execute($vals); $nuevas += $st->rowCount(); $batch = [];
        };
        while (($r = fgetcsv($f)) !== false) {
            if (count($r) < 9) continue;
            $title = trim($r[1]); $prog = $title; $sem = null; $dia = null;
            if (preg_match('/^(.*?)\s+Semana\s+(\d+)\s+(\S+)/iu', $title, $m)) { $prog = trim($m[1]); $sem = (int) $m[2]; $dia = $m[3]; }
            $start = $this->pdt($r[6]);
            if ($start === null) continue; // sin inicio no hay clave natural
            $batch[] = ['email' => mb_strtolower(trim($r[0])), 'title' => $title, 'programa' => $prog, 'semana' => $sem, 'dia' => $dia,
                'session_start' => $start, 'session_end' => $this->pdt($r[7]), 'duration_sec' => $this->secs($r[8]),
                'platform' => trim($r[3]), 'city' => trim($r[4]), 'country' => trim($r[5])];
            $leidas++;
            if (count($batch) >= $BN) $flush();
        }
        $flush(); fclose($f);
        return ['leidas' => $leidas, 'nuevas' => $nuevas];
    }

    /** Fecha máxima de sesión en la tabla (referencia "hoy" para actividad). */
    public function maxDate(): ?string
    {
        return Database::get()->query("SELECT MAX(session_start) FROM play_consumo")->fetchColumn() ?: null;
    }

    /** KPIs de consumo. */
    public function kpis(): array
    {
        $db = Database::get();
        $mx = $this->maxDate();
        if ($mx === null) return ['sin_datos' => true];
        $q = fn(string $s) => (int) $db->query($s)->fetchColumn();
        $ref = "(TIMESTAMPTZ " . $db->quote($mx) . ")";
        return [
            'sin_datos'      => false,
            'sesiones'       => $q("SELECT COUNT(*) FROM play_consumo"),
            'usuarios'       => $q("SELECT COUNT(DISTINCT email) FROM play_consumo"),
            'horas'          => round(((int) $db->query("SELECT COALESCE(SUM(duration_sec),0) FROM play_consumo")->fetchColumn()) / 3600),
            'min_sesion'     => round(((float) $db->query("SELECT COALESCE(AVG(duration_sec),0) FROM play_consumo")->fetchColumn()) / 60, 1),
            'activos_7d'     => $q("SELECT COUNT(DISTINCT email) FROM play_consumo WHERE session_start >= {$ref} - INTERVAL '7 days'"),
            'activos_14d'    => $q("SELECT COUNT(DISTINCT email) FROM play_consumo WHERE session_start >= {$ref} - INTERVAL '14 days'"),
            'activos_30d'    => $q("SELECT COUNT(DISTINCT email) FROM play_consumo WHERE session_start >= {$ref} - INTERVAL '30 days'"),
            'en_riesgo_14d'  => $q("SELECT COUNT(DISTINCT email) FROM play_consumo WHERE email NOT IN (SELECT email FROM play_consumo WHERE session_start >= {$ref} - INTERVAL '14 days')"),
            'ultima_fecha'   => substr((string) $mx, 0, 10),
            'ultimo_import'  => $db->query("SELECT to_char(MAX(imported_at),'YYYY-MM-DD HH24:MI') FROM play_consumo")->fetchColumn(),
        ];
    }

    /** Embudo de retención: alumnos únicos que alcanzaron cada Semana. */
    public function funnel(): array
    {
        $out = [];
        foreach (Database::get()->query("SELECT semana, COUNT(DISTINCT email) n FROM play_consumo WHERE semana IS NOT NULL GROUP BY semana ORDER BY semana LIMIT 16") as $r)
            $out[] = ['semana' => (int) $r['semana'], 'usuarios' => (int) $r['n']];
        return $out;
    }

    /** Distribución por plataforma y país (top). */
    public function distrib(): array
    {
        $db = Database::get();
        $plat = []; foreach ($db->query("SELECT platform, COUNT(*) n FROM play_consumo GROUP BY platform ORDER BY n DESC") as $r) $plat[] = ['k' => $r['platform'], 'n' => (int) $r['n']];
        $pais = []; foreach ($db->query("SELECT country, COUNT(*) n FROM play_consumo GROUP BY country ORDER BY n DESC LIMIT 8") as $r) $pais[] = ['k' => $r['country'], 'n' => (int) $r['n']];
        return ['plataforma' => $plat, 'pais' => $pais];
    }

    /** Alumnos en riesgo: sin ver hace >= $dias. Devuelve email, ultima, sesiones, horas, programa. */
    public function enRiesgo(int $dias = 14, int $limit = 500): array
    {
        $mx = $this->maxDate(); if ($mx === null) return [];
        $st = Database::get()->prepare("
            SELECT email,
                   to_char(MAX(session_start),'YYYY-MM-DD') ultima,
                   (CURRENT_DATE - MAX(session_start)::date) dias_sin_ver,
                   COUNT(*) sesiones,
                   round(SUM(duration_sec)/3600.0,1) horas,
                   MAX(semana) max_semana,
                   (ARRAY_AGG(programa ORDER BY session_start DESC))[1] ultimo_programa
              FROM play_consumo
             GROUP BY email
            HAVING MAX(session_start) < :mx::timestamptz - make_interval(days => :dias)
             ORDER BY MAX(session_start) DESC
             LIMIT :lim");
        $st->bindValue(':mx', $mx);
        $st->bindValue(':dias', $dias, \PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Consumo de un alumno (para su ficha). */
    public function byEmail(string $email): ?array
    {
        $st = Database::get()->prepare("
            SELECT COUNT(*) sesiones, round(COALESCE(SUM(duration_sec),0)/3600.0,1) horas,
                   to_char(MIN(session_start),'YYYY-MM-DD') primera, to_char(MAX(session_start),'YYYY-MM-DD') ultima,
                   MAX(semana) max_semana, (ARRAY_AGG(programa ORDER BY session_start DESC))[1] ultimo_programa
              FROM play_consumo WHERE email = :e");
        $st->execute([':e' => mb_strtolower(trim($email))]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return ($r && (int) $r['sesiones'] > 0) ? $r : null;
    }
}
