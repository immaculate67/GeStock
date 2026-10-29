<?php
declare(strict_types=1);

final class Database
{
    public static function connect(?string $databasePath = null): PDO
    {
        $databasePath ??= (getenv('GESTOCK_DB_PATH') ?: dirname(__DIR__) . '/storage/gestock.sqlite');
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
            'CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 100),
                description TEXT NULL CHECK (description IS NULL OR length(description) <= 500),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS suppliers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 160),
                contact_person TEXT NULL CHECK (contact_person IS NULL OR length(contact_person) <= 120),
                email TEXT NULL CHECK (email IS NULL OR length(email) <= 254),
                phone TEXT NULL CHECK (phone IS NULL OR length(phone) <= 40),
                address TEXT NULL CHECK (address IS NULL OR length(address) <= 1000),
                tax_number TEXT NULL CHECK (tax_number IS NULL OR length(tax_number) <= 50),
                notes TEXT NULL CHECK (notes IS NULL OR length(notes) <= 2000),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 160),
                email TEXT NULL CHECK (email IS NULL OR length(email) <= 254),
                phone TEXT NULL CHECK (phone IS NULL OR length(phone) <= 40),
                address TEXT NULL CHECK (address IS NULL OR length(address) <= 1000),
                tax_number TEXT NULL CHECK (tax_number IS NULL OR length(tax_number) <= 50),
                notes TEXT NULL CHECK (notes IS NULL OR length(notes) <= 2000),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                email TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(email) BETWEEN 3 AND 254),
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN (\'administrator\', \'employee\')),
                active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
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
        self::addColumn($pdo, 'products', 'cost_price_cents', 'INTEGER NOT NULL DEFAULT 0 CHECK (cost_price_cents >= 0)');
        self::addColumn($pdo, 'products', 'category_id', 'INTEGER NULL REFERENCES categories(id) ON DELETE SET NULL');
        self::addColumn($pdo, 'products', 'supplier_id', 'INTEGER NULL REFERENCES suppliers(id) ON DELETE SET NULL');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sales (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sale_number TEXT NOT NULL UNIQUE,
                client_id INTEGER NULL REFERENCES clients(id) ON DELETE SET NULL,
                client_name_snapshot TEXT NOT NULL,
                total_cents INTEGER NOT NULL CHECK (total_cents >= 0),
                status TEXT NOT NULL DEFAULT \'completed\' CHECK (status IN (\'completed\', \'cancelled\')),
                created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                cancelled_at TEXT NULL,
                cancelled_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sale_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sale_id INTEGER NOT NULL REFERENCES sales(id) ON DELETE RESTRICT,
                product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                product_name_snapshot TEXT NOT NULL,
                sku_snapshot TEXT NOT NULL,
                quantity INTEGER NOT NULL CHECK (quantity > 0),
                unit_price_cents INTEGER NOT NULL CHECK (unit_price_cents >= 0),
                line_total_cents INTEGER NOT NULL CHECK (line_total_cents >= 0)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS stock_movements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                movement_type TEXT NOT NULL CHECK (movement_type IN (\'in\', \'out\', \'correction\', \'sale\', \'sale_return\')),
                quantity INTEGER NOT NULL CHECK (quantity > 0),
                stock_before INTEGER NOT NULL CHECK (stock_before >= 0),
                stock_after INTEGER NOT NULL CHECK (stock_after >= 0),
                reason TEXT NOT NULL CHECK (length(reason) BETWEEN 1 AND 500),
                sale_id INTEGER NULL REFERENCES sales(id) ON DELETE SET NULL,
                created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_supplier ON products(supplier_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sales_client ON sales(client_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sale_items_product ON sale_items(product_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_movements_product_date ON stock_movements(product_id, created_at)');
    }

    private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($statement->fetchAll() as $existingColumn) {
            if ($existingColumn['name'] === $column) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
