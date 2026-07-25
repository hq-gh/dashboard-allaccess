<?php

declare(strict_types=1);

namespace App;

/**
 * Cliente mínimo para Cloudflare R2 (S3-compatible) — solo PutObject, firmado
 * con AWS Signature V4. Sin dependencias externas (cURL + hash nativos).
 *
 * Env: R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET,
 *      R2_ENDPOINT (https://<acct>.r2.cloudflarestorage.com), R2_PUBLIC_BASE_URL.
 * Lectura pública: por el R2_PUBLIC_BASE_URL (r2.dev o dominio propio), sin firma.
 */
final class R2Client
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $endpoint;   // https://<acct>.r2.cloudflarestorage.com
    private string $publicBase;  // https://pub-xxxx.r2.dev
    private string $region = 'auto';

    public function __construct()
    {
        $this->accessKey  = (string) Config::require('R2_ACCESS_KEY_ID');
        $this->secretKey  = (string) Config::require('R2_SECRET_ACCESS_KEY');
        $this->bucket     = (string) Config::require('R2_BUCKET');
        $this->endpoint   = rtrim((string) Config::require('R2_ENDPOINT'), '/');
        $this->publicBase = rtrim((string) Config::get('R2_PUBLIC_BASE_URL', ''), '/');
    }

    /** ¿Están configuradas las credenciales? (para degradar sin romper el sync). */
    public static function isConfigured(): bool
    {
        foreach (['R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_BUCKET', 'R2_ENDPOINT'] as $k) {
            if ((string) Config::get($k, '') === '') return false;
        }
        return true;
    }

    /**
     * Sube bytes a R2 en {bucket}/{key}. Devuelve la URL pública.
     * @throws \RuntimeException si el PUT no responde 2xx.
     */
    public function put(string $key, string $body, string $contentType): string
    {
        $key   = ltrim($key, '/');
        $host  = parse_url($this->endpoint, PHP_URL_HOST);
        $path  = '/' . $this->bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $now   = gmdate('Ymd\THis\Z');
        $date  = substr($now, 0, 8);
        $payloadHash = hash('sha256', $body);

        $canonicalHeaders = "content-type:{$contentType}\n"
            . "host:{$host}\n"
            . "x-amz-content-sha256:{$payloadHash}\n"
            . "x-amz-date:{$now}\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "PUT\n{$path}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init($this->endpoint . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: {$contentType}",
                "x-amz-content-sha256: {$payloadHash}",
                "x-amz-date: {$now}",
                "Authorization: {$authorization}",
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("R2 PUT {$key} falló: HTTP {$code} " . ($err ?: substr((string) $resp, 0, 200)));
        }
        return $this->publicUrl($key);
    }

    public function publicUrl(string $key): string
    {
        return $this->publicBase . '/' . ltrim($key, '/');
    }

    /** Borra un objeto de R2 (SigV4 DELETE, cuerpo vacío). @throws \RuntimeException si no 2xx/404. */
    public function delete(string $key): void
    {
        $key   = ltrim($key, '/');
        $host  = parse_url($this->endpoint, PHP_URL_HOST);
        $path  = '/' . $this->bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $now   = gmdate('Ymd\THis\Z');
        $date  = substr($now, 0, 8);
        $payloadHash = hash('sha256', '');

        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$now}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "DELETE\n{$path}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init($this->endpoint . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                "x-amz-content-sha256: {$payloadHash}",
                "x-amz-date: {$now}",
                "Authorization: {$authorization}",
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (($code < 200 || $code >= 300) && $code !== 404) {
            throw new \RuntimeException("R2 DELETE {$key} falló: HTTP {$code} " . ($err ?: substr((string) $resp, 0, 200)));
        }
    }
}
