<?php
declare(strict_types=1);

final class DatabaseMigrations
{
    public static function apply(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 1) {
            self::applyIndustryTemplates($pdo);
            self::applyTenantIntegrityAndCategories($pdo);
            self::applyCatalogArchitecture($pdo);
            return;
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $pdo->beginTransaction();
            try {
            $pdo->exec(
                'CREATE TABLE businesses (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                    industry_key TEXT NOT NULL DEFAULT \'custom\' CHECK (length(industry_key) BETWEEN 1 AND 80),
                    timezone TEXT NOT NULL DEFAULT \'Europe/Lisbon\' CHECK (length(timezone) BETWEEN 1 AND 80),
                    owner_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            $pdo->exec(
                'CREATE TABLE business_memberships (
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    role TEXT NOT NULL CHECK (role IN (\'owner\', \'administrator\', \'employee\', \'customer\')),
                    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (business_id, user_id)
                )'
            );
            $pdo->exec(
                'CREATE TABLE business_settings (
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
                    setting_key TEXT NOT NULL,
                    setting_value TEXT NOT NULL,
                    PRIMARY KEY (business_id, setting_key)
                )'
            );
            $pdo->exec(
                'CREATE TABLE business_modules (
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
                    module_key TEXT NOT NULL,
                    enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
                    configuration TEXT NOT NULL DEFAULT \'{}\',
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (business_id, module_key)
                )'
            );

            foreach ([
                'categories',
                'suppliers',
                'clients',
                'products',
                'sales',
                'sale_items',
                'stock_movements',
            ] as $table) {
                self::addColumn($pdo, $table, 'business_id', 'INTEGER NULL REFERENCES businesses(id) ON DELETE RESTRICT');
            }

            $hasLegacyData = false;
            foreach (['users', 'categories', 'suppliers', 'clients', 'products', 'sales', 'stock_movements', 'settings'] as $table) {
                if ((int) $pdo->query('SELECT EXISTS(SELECT 1 FROM ' . $table . ' LIMIT 1)')->fetchColumn() === 1) {
                    $hasLegacyData = true;
                    break;
                }
            }

            if ($hasLegacyData) {
                $ownerId = $pdo->query(
                    "SELECT id FROM users WHERE role = 'administrator' AND active = 1 ORDER BY id LIMIT 1"
                )->fetchColumn();
                $configuredName = $pdo->query(
                    "SELECT setting_value FROM settings WHERE setting_key = 'business_name' LIMIT 1"
                )->fetchColumn();
                $businessName = is_string($configuredName) && trim($configuredName) !== ''
                    ? trim($configuredName)
                    : 'O meu negócio';
                $createBusiness = $pdo->prepare(
                    'INSERT INTO businesses (name, industry_key, owner_user_id) VALUES (:name, \'custom\', :owner)'
                );
                $createBusiness->execute([
                    'name' => mb_substr($businessName, 0, 120),
                    'owner' => $ownerId === false ? null : (int) $ownerId,
                ]);
                $businessId = (int) $pdo->lastInsertId();

                foreach ([
                    'categories',
                    'suppliers',
                    'clients',
                    'products',
                    'sales',
                    'sale_items',
                    'stock_movements',
                ] as $table) {
                    $pdo->exec('UPDATE ' . $table . ' SET business_id = ' . $businessId . ' WHERE business_id IS NULL');
                }
                $pdo->prepare(
                    'INSERT INTO business_settings (business_id, setting_key, setting_value)
                     SELECT :business_id, setting_key, setting_value FROM settings'
                )->execute(['business_id' => $businessId]);
                $pdo->prepare(
                    'INSERT INTO business_memberships (business_id, user_id, role, active)
                     SELECT :business_id, id,
                         CASE WHEN id = :owner_id THEN \'owner\' ELSE role END,
                         active
                     FROM users'
                )->execute([
                    'business_id' => $businessId,
                    'owner_id' => $ownerId === false ? -1 : (int) $ownerId,
                ]);
            }

            self::rebuildCategories($pdo);
            self::rebuildProducts($pdo);
            foreach (['categories', 'suppliers', 'clients', 'products', 'sales', 'sale_items', 'stock_movements'] as $table) {
                $pdo->exec(
                    'CREATE TRIGGER require_business_' . $table . '_insert
                     BEFORE INSERT ON ' . $table . '
                     WHEN NEW.business_id IS NULL
                     BEGIN SELECT RAISE(ABORT, \'A empresa é obrigatória.\'); END'
                );
                $pdo->exec(
                    'CREATE TRIGGER require_business_' . $table . '_update
                     BEFORE UPDATE OF business_id ON ' . $table . '
                     WHEN NEW.business_id IS NULL
                     BEGIN SELECT RAISE(ABORT, \'A empresa é obrigatória.\'); END'
                );
            }
            foreach ([
                'CREATE INDEX idx_business_memberships_user ON business_memberships(user_id, active)',
                'CREATE INDEX idx_products_business ON products(business_id)',
                'CREATE INDEX idx_categories_business ON categories(business_id)',
                'CREATE INDEX idx_suppliers_business ON suppliers(business_id)',
                'CREATE INDEX idx_clients_business ON clients(business_id)',
                'CREATE INDEX idx_sales_business_date ON sales(business_id, created_at)',
                'CREATE INDEX idx_sale_items_business_sale ON sale_items(business_id, sale_id)',
                'CREATE INDEX idx_movements_business_date ON stock_movements(business_id, created_at)',
            ] as $statement) {
                $pdo->exec($statement);
            }
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (1)');
            $pdo->exec('PRAGMA user_version = 1');
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('The V6 tenant migration caused a foreign key violation.');
            }
            $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        self::applyIndustryTemplates($pdo);
        self::applyTenantIntegrityAndCategories($pdo);
        self::applyCatalogArchitecture($pdo);
    }

    private static function applyIndustryTemplates(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 2) {
            return;
        }
        require_once __DIR__ . '/IndustryTemplateService.php';
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'CREATE TABLE industry_templates (
                    template_key TEXT PRIMARY KEY,
                    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
                    description TEXT NOT NULL CHECK (length(description) BETWEEN 1 AND 500),
                    configuration TEXT NOT NULL,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            $insert = $pdo->prepare(
                'INSERT INTO industry_templates (template_key, name, description, configuration)
                 VALUES (:key, :name, :description, :configuration)'
            );
            foreach (IndustryTemplateService::definitions() as $key => $template) {
                $insert->execute([
                    'key' => $key,
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'configuration' => json_encode($template['configuration'], JSON_THROW_ON_ERROR),
                ]);
            }

            $businesses = $pdo->query('SELECT id, industry_key FROM businesses ORDER BY id')->fetchAll();
            foreach ($businesses as $business) {
                IndustryTemplateService::apply($pdo, (int) $business['id'], (string) $business['industry_key']);
            }
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (2)');
            $pdo->exec('PRAGMA user_version = 2');
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function applyTenantIntegrityAndCategories(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 3) {
            return;
        }
        $pdo->beginTransaction();
        try {
            self::addColumn(
                $pdo,
                'categories',
                'parent_id',
                'INTEGER NULL REFERENCES categories(id) ON DELETE RESTRICT'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_category_parent_insert
                 BEFORE INSERT ON categories
                 WHEN NEW.parent_id IS NOT NULL
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM categories
                         WHERE id = NEW.parent_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A categoria superior tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_category_parent_update
                 BEFORE UPDATE ON categories
                 BEGIN
                     SELECT CASE WHEN NEW.parent_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM categories
                         WHERE id = NEW.parent_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A categoria superior tem de pertencer ao mesmo negócio.\') END;
                     SELECT CASE WHEN NEW.parent_id = NEW.id
                         THEN RAISE(ABORT, \'Uma categoria não pode ser superior a si própria.\') END;
                     SELECT CASE WHEN NEW.parent_id IS NOT NULL AND EXISTS (
                         WITH RECURSIVE descendants(id) AS (
                             SELECT id FROM categories
                             WHERE parent_id = NEW.id AND business_id = NEW.business_id
                             UNION ALL
                             SELECT child.id FROM categories child
                             JOIN descendants parent ON child.parent_id = parent.id
                             WHERE child.business_id = NEW.business_id
                         )
                         SELECT 1 FROM descendants WHERE id = NEW.parent_id
                     ) THEN RAISE(ABORT, \'A hierarquia de categorias não pode conter ciclos.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_product_references_insert
                 BEFORE INSERT ON products
                 BEGIN
                     SELECT CASE WHEN NEW.category_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM categories WHERE id = NEW.category_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A categoria tem de pertencer ao mesmo negócio.\') END;
                     SELECT CASE WHEN NEW.supplier_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM suppliers WHERE id = NEW.supplier_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O fornecedor tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_product_references_update
                 BEFORE UPDATE ON products
                 BEGIN
                     SELECT CASE WHEN NEW.category_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM categories WHERE id = NEW.category_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A categoria tem de pertencer ao mesmo negócio.\') END;
                     SELECT CASE WHEN NEW.supplier_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM suppliers WHERE id = NEW.supplier_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O fornecedor tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_sale_client_insert
                 BEFORE INSERT ON sales
                 WHEN NEW.client_id IS NOT NULL AND NOT EXISTS (
                     SELECT 1 FROM clients WHERE id = NEW.client_id AND business_id = NEW.business_id
                 )
                 BEGIN SELECT RAISE(ABORT, \'O cliente tem de pertencer ao mesmo negócio.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_sale_client_update
                 BEFORE UPDATE ON sales
                 WHEN NEW.client_id IS NOT NULL AND NOT EXISTS (
                     SELECT 1 FROM clients WHERE id = NEW.client_id AND business_id = NEW.business_id
                 )
                 BEGIN SELECT RAISE(ABORT, \'O cliente tem de pertencer ao mesmo negócio.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_sale_item_references_insert
                 BEFORE INSERT ON sale_items
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM sales WHERE id = NEW.sale_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A venda tem de pertencer ao mesmo negócio.\') END;
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM products WHERE id = NEW.product_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O produto tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_sale_item_references_update
                 BEFORE UPDATE ON sale_items
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM sales WHERE id = NEW.sale_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A venda tem de pertencer ao mesmo negócio.\') END;
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM products WHERE id = NEW.product_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O produto tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_movement_references_insert
                 BEFORE INSERT ON stock_movements
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM products WHERE id = NEW.product_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O produto tem de pertencer ao mesmo negócio.\') END;
                     SELECT CASE WHEN NEW.sale_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM sales WHERE id = NEW.sale_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A venda tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_movement_references_update
                 BEFORE UPDATE ON stock_movements
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM products WHERE id = NEW.product_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O produto tem de pertencer ao mesmo negócio.\') END;
                     SELECT CASE WHEN NEW.sale_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM sales WHERE id = NEW.sale_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A venda tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (3)');
            $pdo->exec('PRAGMA user_version = 3');
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('The V6 tenant integrity migration caused a foreign key violation.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function applyCatalogArchitecture(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 4) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'CREATE TABLE business_branches (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                    code TEXT NOT NULL COLLATE NOCASE CHECK (length(code) BETWEEN 1 AND 40),
                    address TEXT NULL CHECK (address IS NULL OR length(address) <= 500),
                    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (business_id, name), UNIQUE (business_id, code)
                )'
            );
            $pdo->exec(
                'CREATE TABLE item_types (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    type_key TEXT NOT NULL COLLATE NOCASE CHECK (length(type_key) BETWEEN 1 AND 40),
                    label TEXT NOT NULL CHECK (length(label) BETWEEN 1 AND 100),
                    stock_tracked INTEGER NOT NULL DEFAULT 1 CHECK (stock_tracked IN (0, 1)),
                    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (business_id, type_key)
                )'
            );
            self::addColumn($pdo, 'products', 'item_type_id', 'INTEGER NULL REFERENCES item_types(id) ON DELETE RESTRICT');
            $insertType = $pdo->prepare(
                'INSERT INTO item_types (business_id, type_key, label, stock_tracked)
                 VALUES (:business_id, :type_key, :label, :stock_tracked)'
            );
            $insertBranch = $pdo->prepare(
                'INSERT INTO business_branches (business_id, name, code) VALUES (:business_id, :name, :code)'
            );
            $typeQuery = $pdo->prepare(
                "SELECT id FROM item_types WHERE business_id = :business_id AND type_key = 'product'"
            );
            $updateProducts = $pdo->prepare(
                'UPDATE products SET item_type_id = :item_type_id
                 WHERE business_id = :business_id AND item_type_id IS NULL'
            );
            foreach ($pdo->query('SELECT id FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $businessId) {
                $businessId = (int) $businessId;
                foreach ([['product', 'Produto', 1], ['service', 'Serviço', 0], ['resource', 'Recurso', 0]] as $type) {
                    $insertType->execute([
                        'business_id' => $businessId,
                        'type_key' => $type[0],
                        'label' => $type[1],
                        'stock_tracked' => $type[2],
                    ]);
                }
                $insertBranch->execute([
                    'business_id' => $businessId,
                    'name' => 'Local principal',
                    'code' => 'MAIN',
                ]);
                $typeQuery->execute(['business_id' => $businessId]);
                $updateProducts->execute([
                    'item_type_id' => (int) $typeQuery->fetchColumn(),
                    'business_id' => $businessId,
                ]);
            }
            $pdo->exec(
                'CREATE TRIGGER validate_product_item_type_insert
                 BEFORE INSERT ON products
                 WHEN NEW.item_type_id IS NOT NULL AND NOT EXISTS (
                     SELECT 1 FROM item_types
                     WHERE id = NEW.item_type_id AND business_id = NEW.business_id AND enabled = 1
                 )
                 BEGIN SELECT RAISE(ABORT, \'O tipo de artigo tem de pertencer ao mesmo negócio e estar ativo.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_product_item_type_update
                 BEFORE UPDATE OF item_type_id, business_id ON products
                 WHEN NEW.item_type_id IS NOT NULL AND NOT EXISTS (
                     SELECT 1 FROM item_types
                     WHERE id = NEW.item_type_id AND business_id = NEW.business_id AND enabled = 1
                 )
                 BEGIN SELECT RAISE(ABORT, \'O tipo de artigo tem de pertencer ao mesmo negócio e estar ativo.\'); END'
            );
            $pdo->exec('CREATE INDEX idx_branches_business ON business_branches(business_id, active)');
            $pdo->exec('CREATE INDEX idx_item_types_business ON item_types(business_id, enabled)');
            $pdo->exec('CREATE INDEX idx_products_item_type ON products(business_id, item_type_id)');
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (4)');
            $pdo->exec('PRAGMA user_version = 4');
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('The V6 catalog architecture migration caused a foreign key violation.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($columns as $existingColumn) {
            if ($existingColumn['name'] === $column) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private static function rebuildCategories(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE categories_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL COLLATE NOCASE CHECK (length(name) BETWEEN 1 AND 100),
                description TEXT NULL CHECK (description IS NULL OR length(description) <= 500),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                UNIQUE (business_id, name)
            )'
        );
        $pdo->exec(
            'INSERT INTO categories_new (id, name, description, created_at, business_id)
             SELECT id, name, description, created_at, business_id FROM categories'
        );
        $pdo->exec('DROP TABLE categories');
        $pdo->exec('ALTER TABLE categories_new RENAME TO categories');
    }

    private static function rebuildProducts(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE products_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                sku TEXT NOT NULL COLLATE NOCASE CHECK (length(sku) BETWEEN 1 AND 40),
                description TEXT NULL CHECK (description IS NULL OR length(description) <= 1000),
                price_cents INTEGER NOT NULL CHECK (price_cents >= 0),
                quantity INTEGER NOT NULL CHECK (quantity BETWEEN 0 AND 2147483647),
                min_stock_level INTEGER NOT NULL CHECK (min_stock_level BETWEEN 0 AND 2147483647),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                cost_price_cents INTEGER NOT NULL DEFAULT 0 CHECK (cost_price_cents >= 0),
                category_id INTEGER NULL REFERENCES categories(id) ON DELETE SET NULL,
                supplier_id INTEGER NULL REFERENCES suppliers(id) ON DELETE SET NULL,
                business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                UNIQUE (business_id, sku)
            )'
        );
        $pdo->exec(
            'INSERT INTO products_new (
                id, name, sku, description, price_cents, quantity, min_stock_level,
                created_at, updated_at, cost_price_cents, category_id, supplier_id, business_id
             )
             SELECT
                id, name, sku, description, price_cents, quantity, min_stock_level,
                created_at, updated_at, cost_price_cents, category_id, supplier_id, business_id
             FROM products'
        );
        $pdo->exec('DROP TABLE products');
        $pdo->exec('ALTER TABLE products_new RENAME TO products');
    }
}
