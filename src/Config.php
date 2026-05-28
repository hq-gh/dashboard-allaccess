<?php declare(strict_types=1);

namespace App;

/**
 * Acceso a variables de entorno (Railway inyecta directo; en local pueden
 * venir del shell o de un .env cargado al inicio).
 */
final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') {
            return $default;
        }
        return (string) $v;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null) {
            throw new \RuntimeException("Variable de entorno requerida no definida: {$key}");
        }
        return $v;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) return $default;
        return in_array(strtolower($v), ['true','1','yes','on'], true);
    }
}
