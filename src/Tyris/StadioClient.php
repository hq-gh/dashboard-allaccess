<?php declare(strict_types=1);

namespace App\Tyris;

/**
 * Cliente del CMS de Tyris (Stadio / Galgo) para activar/suspender acceso a PLAY.
 * Portado de DASHBOARD_5T4D10_PTT/bin/report-tyris-play.php::enviarStadio.
 *
 * Flujo: login (usuario/clave) -> access_token; luego POST del CSV (multipart campo
 * `file`) a bulk-status-csv. Respuesta {activated, suspended, notFound[], errors[]}.
 * Credenciales por entorno: TYRIS_CMS_USER / TYRIS_CMS_PASS (nunca hardcodear).
 */
final class StadioClient
{
    private const LOGIN = 'https://cms-galgo-54d.galgo.tv/auth/login';
    private const BULK  = 'https://cms-galgo-54d.galgo.tv/userFront/bulk-status-csv';

    private string $user;
    private string $pass;

    public function __construct(?string $user = null, ?string $pass = null)
    {
        $this->user = $user ?? ((string) (getenv('TYRIS_CMS_USER') ?: ''));
        $this->pass = $pass ?? ((string) (getenv('TYRIS_CMS_PASS') ?: ''));
    }

    /**
     * Envía el CSV de acciones a Stadio (login + bulk).
     * @return array{ok:bool,activated?:int,suspended?:int,notFound?:array,errors?:array,error?:string,raw?:mixed}
     */
    public function bulkStatusCsv(string $csv): array
    {
        if ($this->user === '' || $this->pass === '') {
            return ['ok' => false, 'error' => 'TYRIS_CMS_USER/PASS no configurados'];
        }
        // 1) login -> access_token
        try {
            [$lc, $lb] = $this->retry(fn() => $this->post(self::LOGIN,
                json_encode(['username' => $this->user, 'password' => $this->pass]),
                ['Content-Type: application/json', 'Accept: application/json']));
        } catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if ($lc >= 300) return ['ok' => false, 'error' => 'login HTTP ' . $lc];
        $token = json_decode($lb, true)['access_token'] ?? null;
        if (!$token) return ['ok' => false, 'error' => 'login sin access_token'];

        // 2) bulk-status-csv (multipart campo `file`)
        try {
            [$bc, $bb] = $this->retry(fn() => $this->postCsv(self::BULK, $csv, (string) $token));
        } catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        $j = json_decode($bb, true);
        if ($bc >= 300 || !is_array($j)) {
            return ['ok' => false, 'error' => 'bulk HTTP ' . $bc, 'raw' => substr($bb, 0, 500)];
        }
        return [
            'ok'        => true,
            'activated' => (int) ($j['activated'] ?? 0),
            'suspended' => (int) ($j['suspended'] ?? 0),
            'notFound'  => is_array($j['notFound'] ?? null) ? $j['notFound'] : [],
            'errors'    => is_array($j['errors'] ?? null) ? $j['errors'] : [],
            'raw'       => $j,
        ];
    }

    /** Reintenta ante fallos transitorios (red / 5xx). No reintenta 4xx. @return array{0:int,1:string} */
    private function retry(callable $fn): array
    {
        $delays = [1, 3, 8]; $last = null;
        for ($i = 0; $i <= count($delays); $i++) {
            try {
                [$code, $body] = $fn();
                if ($code < 500) return [$code, $body];
                $last = 'HTTP ' . $code;
            } catch (\Throwable $e) { $last = $e->getMessage(); }
            if ($i < count($delays)) sleep($delays[$i]);
        }
        throw new \RuntimeException('Stadio no responde tras reintentos: ' . $last);
    }

    /** @return array{0:int,1:string} [httpCode, body] */
    private function post(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
        $r = curl_exec($ch); $err = curl_error($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($r === false) throw new \RuntimeException($err !== '' ? $err : 'curl error');
        return [$c, (string) $r];
    }

    /** POST multipart con el CSV en campo `file`. @return array{0:int,1:string} */
    private function postCsv(string $url, string $csv, string $token): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stadio');
        file_put_contents($tmp, $csv);
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
                CURLOPT_POSTFIELDS => ['file' => new \CURLFile($tmp, 'text/csv', 'acciones.csv')],
            ]);
            $r = curl_exec($ch); $err = curl_error($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        } finally { @unlink($tmp); }
        if ($r === false) throw new \RuntimeException($err !== '' ? $err : 'curl error');
        return [$c, (string) $r];
    }

    /** CSV con BOM UTF-8: nombre, correo_electronico, accion. $rows = [[nombre,email,accion],...] */
    public static function buildCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['nombre', 'correo_electronico', 'accion']);
        foreach ($rows as $r) fputcsv($fh, $r);
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /** Extrae correos (lowercase) de una lista de items (strings con '@' o arrays con email/correo). */
    public static function emailsFrom(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_string($item) && strpos($item, '@') !== false) {
                $out[] = strtolower(trim($item));
            } elseif (is_array($item)) {
                foreach (['email', 'correo_electronico', 'correo'] as $f) {
                    if (!empty($item[$f]) && is_string($item[$f])) { $out[] = strtolower(trim($item[$f])); break; }
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Correos de notFound (cuenta inexistente = TERMINAL, no reintentar). */
    public static function notFoundEmails(array $res): array { return self::emailsFrom($res['notFound'] ?? []); }

    /** Correos de errors (fallo TRANSITORIO = reintentar la próxima corrida). */
    public static function errorEmails(array $res): array { return self::emailsFrom($res['errors'] ?? []); }
}
