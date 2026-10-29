<?php
declare(strict_types=1);

final class Database
{
    public static function connect(?string $databasePath = null): PDO
    {
        $databasePath ??= dirname(__DIR__) . '/storage/gestock.sqlite';
        $directory = dirname($databasePath);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The private database directory could not be created.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('The private database directory permissions could not be restricted.');
        }

        $pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        self::initialize($pdo);
        if (!chmod($databasePath, 0600)) {
            throw new RuntimeException('The private database file permissions could not be restricted.');
        }

        return $pdo;
    }

    private static function initialize(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                sku TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(sku) BETWEEN 1 AND 40),
                description TEXT NULL CHECK (description IS NULL OR length(description) <= 1000),
                price_cents INTEGER NOT NULL CHECK (price_cents >= 0),
                quantity INTEGER NOT NULL CHECK (quantity BETWEEN 0 AND 2147483647),
                min_stock_level INTEGER NOT NULL CHECK (min_stock_level BETWEEN 0 AND 2147483647),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }
}
