<?php declare(strict_types=1);

namespace App;

/**
 * Helpers transversales de seguridad: sesión, CSRF, rate limit, headers.
 */
final class Security
{
    private const SESSION_LIFETIME      = 60 * 60 * 8;    // 8h
    public  const LOGIN_MAX_ATTEMPTS    = 5;
    public  const LOGIN_LOCKOUT_SECONDS = 15 * 60;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = self::isHttps();
        session_set_cookie_params([
            'lifetime' => self::SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string) self::SESSION_LIFETIME);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('PORTAL_RW2_SID');
        session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfValidate(?string $token): bool
    {
        self::startSession();
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!$expected || !is_string($token)) {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public static function applyHtmlHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "img-src 'self' data: https://github.com https://*.githubusercontent.com; " .
            "style-src 'self' 'unsafe-inline'; " .
            "script-src 'self'; " .
            "connect-src 'self'; " .
            "frame-ancestors 'none'; " .
            "base-uri 'self';"
        );
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }

    /**
     * Escapar para HTML (uso obligatorio en views).
     */
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
