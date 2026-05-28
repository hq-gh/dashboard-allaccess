<?php declare(strict_types=1);

namespace App;

use PDO;

/**
 * Singleton PDO a Postgres (Neon).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Config::require('DB_HOST');
        $name = Config::get('DB_NAME') ?? Config::get('DB_DATABASE') ?? 'neondb';
        $user = Config::get('DB_USER') ?? Config::get('DB_USERNAME');
        $pass = Config::get('DB_PASS') ?? Config::get('DB_PASSWORD');

        if (!$user || !$pass) {
            throw new \RuntimeException('DB_USER / DB_PASS no definidos');
        }

        $dsn = "pgsql:host={$host};dbname={$name};sslmode=require";

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);

        return self::$pdo;
    }
}
