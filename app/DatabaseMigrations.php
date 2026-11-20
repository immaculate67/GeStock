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
            self::applyVariantsAndAttributes($pdo);
            self::applyVariantSales($pdo);
            self::applyPermissionsAuditPurchasing($pdo);
            self::applyPurchaseCompletion($pdo);
            self::applyStockTransfers($pdo);
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
        self::applyVariantsAndAttributes($pdo);
        self::applyVariantSales($pdo);
        self::applyPermissionsAuditPurchasing($pdo);
        self::applyPurchaseCompletion($pdo);
        self::applyStockTransfers($pdo);
    }

    private static function applyStockTransfers(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 9) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS stock_balances (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    branch_id INTEGER NOT NULL REFERENCES business_branches(id) ON DELETE RESTRICT,
                    location_id INTEGER NOT NULL REFERENCES stock_locations(id) ON DELETE RESTRICT,
                    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                    variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
                    quantity INTEGER NOT NULL DEFAULT 0 CHECK (quantity >= 0),
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS stock_transfers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    source_branch_id INTEGER NOT NULL REFERENCES business_branches(id) ON DELETE RESTRICT,
                    source_location_id INTEGER NOT NULL REFERENCES stock_locations(id) ON DELETE RESTRICT,
                    destination_branch_id INTEGER NOT NULL REFERENCES business_branches(id) ON DELETE RESTRICT,
                    destination_location_id INTEGER NOT NULL REFERENCES stock_locations(id) ON DELETE RESTRICT,
                    status TEXT NOT NULL DEFAULT \'draft\' CHECK (status IN (\'draft\',\'completed\',\'cancelled\')),
                    reference TEXT NOT NULL CHECK (length(reference) BETWEEN 1 AND 80),
                    notes TEXT NULL CHECK (notes IS NULL OR length(notes) <= 2000),
                    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    completed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT NULL,
                    UNIQUE (business_id, reference)
                )'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS stock_transfer_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    transfer_id INTEGER NOT NULL REFERENCES stock_transfers(id) ON DELETE RESTRICT,
                    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                    variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
                    quantity INTEGER NOT NULL CHECK (quantity > 0)
                )'
            );
            $pdo->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS idx_stock_balances_location_item
                 ON stock_balances(business_id, location_id, product_id, COALESCE(variant_id, 0))'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_balances_business_location ON stock_balances(business_id, location_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_transfers_business_status ON stock_transfers(business_id, status, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_transfer_items_transfer ON stock_transfer_items(transfer_id)');
            self::addColumn($pdo, 'stock_movements', 'transfer_id', 'INTEGER NULL REFERENCES stock_transfers(id) ON DELETE SET NULL');
            self::addColumn($pdo, 'stock_movements', 'source_location_id', 'INTEGER NULL REFERENCES stock_locations(id) ON DELETE SET NULL');
            self::addColumn($pdo, 'stock_movements', 'destination_location_id', 'INTEGER NULL REFERENCES stock_locations(id) ON DELETE SET NULL');

            $branches = $pdo->query('SELECT id, business_id, code FROM business_branches WHERE active = 1 ORDER BY id')->fetchAll();
            $insertLocation = $pdo->prepare(
                'INSERT OR IGNORE INTO stock_locations (business_id, branch_id, name, code)
                 VALUES (:business_id, :branch_id, :name, :code)'
            );
            $findLocation = $pdo->prepare(
                'SELECT id FROM stock_locations
                 WHERE business_id = :business_id AND branch_id = :branch_id AND active = 1
                 ORDER BY id LIMIT 1'
            );
            $principalLocationByBusiness = [];
            foreach ($branches as $branch) {
                $businessId = (int) $branch['business_id'];
                $branchId = (int) $branch['id'];
                $insertLocation->execute([
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'name' => 'Stock principal',
                    'code' => 'PRINCIPAL',
                ]);
                $findLocation->execute(['business_id' => $businessId, 'branch_id' => $branchId]);
                $locationId = (int) $findLocation->fetchColumn();
                if (!isset($principalLocationByBusiness[$businessId])) {
                    $principalLocationByBusiness[$businessId] = $locationId;
                }
            }

            $insertBalance = $pdo->prepare(
                'INSERT INTO stock_balances (business_id, branch_id, location_id, product_id, variant_id, quantity)
                 VALUES (:business_id, :branch_id, :location_id, :product_id, :variant_id, :quantity)'
            );
            foreach ($principalLocationByBusiness as $businessId => $locationId) {
                $branchLocation = $pdo->prepare(
                    'SELECT branch_id FROM stock_locations WHERE id = :id AND business_id = :business_id'
                );
                $branchLocation->execute(['id' => $locationId, 'business_id' => $businessId]);
                $branchId = (int) $branchLocation->fetchColumn();
                $products = $pdo->prepare('SELECT id, quantity FROM products WHERE business_id = :business_id');
                $products->execute(['business_id' => $businessId]);
                foreach ($products->fetchAll() as $product) {
                    $insertBalance->execute([
                        'business_id' => $businessId,
                        'branch_id' => $branchId,
                        'location_id' => $locationId,
                        'product_id' => (int) $product['id'],
                        'variant_id' => null,
                        'quantity' => (int) $product['quantity'],
                    ]);
                }
                $variants = $pdo->prepare('SELECT id, product_id, quantity FROM product_variants WHERE business_id = :business_id');
                $variants->execute(['business_id' => $businessId]);
                foreach ($variants->fetchAll() as $variant) {
                    $insertBalance->execute([
                        'business_id' => $businessId,
                        'branch_id' => $branchId,
                        'location_id' => $locationId,
                        'product_id' => (int) $variant['product_id'],
                        'variant_id' => (int) $variant['id'],
                        'quantity' => (int) $variant['quantity'],
                    ]);
                }
            }

            $pdo->exec(
                'CREATE TRIGGER IF NOT EXISTS validate_stock_balance_insert BEFORE INSERT ON stock_balances
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM stock_locations l
                         WHERE l.id = NEW.location_id AND l.business_id = NEW.business_id
                           AND l.branch_id = NEW.branch_id
                     ) THEN RAISE(ABORT, \'O local de stock não pertence à filial indicada.\') END;
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM products p WHERE p.id = NEW.product_id AND p.business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O produto do stock não pertence ao negócio indicado.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER IF NOT EXISTS validate_stock_transfer_insert BEFORE INSERT ON stock_transfers
                 BEGIN
                     SELECT CASE WHEN NEW.source_branch_id = NEW.destination_branch_id
                         THEN RAISE(ABORT, \'A transferência tem de usar filiais diferentes.\') END;
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM stock_locations s JOIN stock_locations d
                         WHERE s.id = NEW.source_location_id AND d.id = NEW.destination_location_id
                           AND s.business_id = NEW.business_id AND d.business_id = NEW.business_id
                           AND s.branch_id = NEW.source_branch_id AND d.branch_id = NEW.destination_branch_id
                     ) THEN RAISE(ABORT, \'Os locais da transferência têm de pertencer ao mesmo negócio e às filiais indicadas.\') END;
                 END'
            );
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (9)');
            $pdo->exec('PRAGMA user_version = 9');
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('A migração de transferências causou uma violação de chave estrangeira.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function applyPurchaseCompletion(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 8) {
            return;
        }
        $pdo->beginTransaction();
        try {
            self::addColumn($pdo, 'purchase_order_items', 'received_quantity', 'INTEGER NOT NULL DEFAULT 0');
            self::addColumn($pdo, 'purchase_order_items', 'returned_quantity', 'INTEGER NOT NULL DEFAULT 0');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS purchase_receipts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    purchase_order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE RESTRICT,
                    purchase_order_item_id INTEGER NOT NULL REFERENCES purchase_order_items(id) ON DELETE RESTRICT,
                    quantity INTEGER NOT NULL CHECK (quantity > 0),
                    unit_cost_cents INTEGER NOT NULL CHECK (unit_cost_cents >= 0),
                    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS purchase_returns (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    purchase_order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE RESTRICT,
                    purchase_order_item_id INTEGER NOT NULL REFERENCES purchase_order_items(id) ON DELETE RESTRICT,
                    quantity INTEGER NOT NULL CHECK (quantity > 0),
                    reason TEXT NULL CHECK (reason IS NULL OR length(reason) <= 2000),
                    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_purchase_receipts_order ON purchase_receipts(business_id, purchase_order_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_purchase_returns_order ON purchase_returns(business_id, purchase_order_id)');
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (8)');
            $pdo->exec('PRAGMA user_version = 8');
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function applyPermissionsAuditPurchasing(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 7) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'CREATE TABLE audit_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    actor_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    action TEXT NOT NULL CHECK (length(action) BETWEEN 1 AND 80),
                    entity_type TEXT NOT NULL CHECK (length(entity_type) BETWEEN 1 AND 80),
                    entity_id INTEGER NULL,
                    before_json TEXT NULL,
                    after_json TEXT NULL,
                    ip_address TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            $pdo->exec(
                'CREATE TABLE membership_permissions (
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    permission_key TEXT NOT NULL CHECK (length(permission_key) BETWEEN 1 AND 80),
                    granted INTEGER NOT NULL DEFAULT 1 CHECK (granted IN (0, 1)),
                    updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (business_id, user_id, permission_key)
                )'
            );
            $pdo->exec(
                'CREATE TABLE membership_branches (
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    branch_id INTEGER NOT NULL REFERENCES business_branches(id) ON DELETE CASCADE,
                    PRIMARY KEY (business_id, user_id, branch_id)
                )'
            );
            $pdo->exec(
                'CREATE TABLE purchase_orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    branch_id INTEGER NULL REFERENCES business_branches(id) ON DELETE RESTRICT,
                    supplier_id INTEGER NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
                    order_number TEXT NOT NULL CHECK (length(order_number) BETWEEN 1 AND 80),
                    status TEXT NOT NULL DEFAULT \'draft\' CHECK (status IN (\'draft\',\'ordered\',\'received\',\'partially_received\',\'cancelled\',\'closed\')),
                    subtotal_cents INTEGER NOT NULL DEFAULT 0 CHECK (subtotal_cents >= 0),
                    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    notes TEXT NULL CHECK (notes IS NULL OR length(notes) <= 2000),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    received_at TEXT NULL,
                    cancelled_at TEXT NULL,
                    UNIQUE (business_id, order_number)
                )'
            );
            $pdo->exec(
                'CREATE TABLE purchase_order_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    purchase_order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE RESTRICT,
                    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                    variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
                    quantity INTEGER NOT NULL CHECK (quantity > 0),
                    unit_cost_cents INTEGER NOT NULL CHECK (unit_cost_cents >= 0),
                    line_total_cents INTEGER NOT NULL CHECK (line_total_cents >= 0)
                )'
            );
            $pdo->exec(
                'CREATE TABLE operational_records (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    branch_id INTEGER NULL REFERENCES business_branches(id) ON DELETE RESTRICT,
                    record_type TEXT NOT NULL CHECK (record_type IN (\'reservation\',\'appointment\',\'rental\',\'membership\',\'subscription\',\'ticket\',\'production\',\'other\')),
                    reference TEXT NOT NULL CHECK (length(reference) BETWEEN 1 AND 80),
                    title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 160),
                    status TEXT NOT NULL DEFAULT \'draft\' CHECK (length(status) BETWEEN 1 AND 40),
                    customer_id INTEGER NULL REFERENCES clients(id) ON DELETE SET NULL,
                    resource_id INTEGER NULL REFERENCES products(id) ON DELETE SET NULL,
                    quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity > 0),
                    starts_at TEXT NULL,
                    ends_at TEXT NULL,
                    deposit_cents INTEGER NOT NULL DEFAULT 0 CHECK (deposit_cents >= 0),
                    total_cents INTEGER NOT NULL DEFAULT 0 CHECK (total_cents >= 0),
                    metadata_json TEXT NOT NULL DEFAULT "{}",
                    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (business_id, record_type, reference)
                )'
            );
            $pdo->exec(
                'CREATE TABLE hotel_rooms (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    branch_id INTEGER NULL REFERENCES business_branches(id) ON DELETE RESTRICT,
                    room_number TEXT NOT NULL CHECK (length(room_number) BETWEEN 1 AND 40),
                    room_type TEXT NOT NULL CHECK (length(room_type) BETWEEN 1 AND 80),
                    floor TEXT NULL,
                    status TEXT NOT NULL DEFAULT \'available\' CHECK (status IN (\'available\',\'occupied\',\'cleaning\',\'maintenance\',\'inactive\')),
                    base_price_cents INTEGER NOT NULL DEFAULT 0 CHECK (base_price_cents >= 0),
                    metadata_json TEXT NOT NULL DEFAULT \'{}\' ,
                    UNIQUE (business_id, branch_id, room_number)
                )'
            );
            $pdo->exec(
                'CREATE TABLE hotel_stays (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    room_id INTEGER NOT NULL REFERENCES hotel_rooms(id) ON DELETE RESTRICT,
                    customer_id INTEGER NULL REFERENCES clients(id) ON DELETE SET NULL,
                    reference TEXT NOT NULL CHECK (length(reference) BETWEEN 1 AND 80),
                    status TEXT NOT NULL DEFAULT \'reserved\' CHECK (status IN (\'reserved\',\'checked_in\',\'checked_out\',\'cancelled\',\'no_show\')),
                    check_in_at TEXT NOT NULL,
                    check_out_at TEXT NOT NULL,
                    adults INTEGER NOT NULL DEFAULT 1 CHECK (adults >= 1),
                    children INTEGER NOT NULL DEFAULT 0 CHECK (children >= 0),
                    total_cents INTEGER NOT NULL DEFAULT 0 CHECK (total_cents >= 0),
                    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (business_id, reference)
                )'
            );
            $pdo->exec(
                'CREATE TABLE event_passes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    event_id INTEGER NOT NULL REFERENCES operational_records(id) ON DELETE RESTRICT,
                    customer_id INTEGER NULL REFERENCES clients(id) ON DELETE SET NULL,
                    pass_code TEXT NOT NULL COLLATE NOCASE CHECK (length(pass_code) BETWEEN 16 AND 128),
                    pass_type TEXT NOT NULL CHECK (length(pass_type) BETWEEN 1 AND 80),
                    status TEXT NOT NULL DEFAULT \'issued\' CHECK (status IN (\'issued\',\'used\',\'cancelled\',\'expired\')),
                    issued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    redeemed_at TEXT NULL,
                    redeemed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                    UNIQUE (business_id, pass_code)
                )'
            );
            $pdo->exec(
                'CREATE TABLE stock_locations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    branch_id INTEGER NULL REFERENCES business_branches(id) ON DELETE RESTRICT,
                    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                    code TEXT NOT NULL COLLATE NOCASE CHECK (length(code) BETWEEN 1 AND 40),
                    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
                    UNIQUE (business_id, branch_id, code)
                )'
            );
            $pdo->exec(
                'CREATE TRIGGER prevent_audit_update BEFORE UPDATE ON audit_events
                 BEGIN SELECT RAISE(ABORT, \'O histórico de auditoria não pode ser alterado.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER prevent_audit_delete BEFORE DELETE ON audit_events
                 BEGIN SELECT RAISE(ABORT, \'O histórico de auditoria não pode ser eliminado.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_purchase_supplier_insert BEFORE INSERT ON purchase_orders
                 WHEN NOT EXISTS (SELECT 1 FROM suppliers WHERE id = NEW.supplier_id AND business_id = NEW.business_id)
                 BEGIN SELECT RAISE(ABORT, \'O fornecedor da compra tem de pertencer ao mesmo negócio.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_purchase_item_insert BEFORE INSERT ON purchase_order_items
                 WHEN NOT EXISTS (
                     SELECT 1 FROM purchase_orders po JOIN products p ON p.id = NEW.product_id
                     WHERE po.id = NEW.purchase_order_id AND po.business_id = NEW.business_id AND p.business_id = NEW.business_id
                 )
                 BEGIN SELECT RAISE(ABORT, \'Os dados da compra têm de pertencer ao mesmo negócio.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_hotel_room_insert BEFORE INSERT ON hotel_rooms
                 WHEN NEW.branch_id IS NOT NULL AND NOT EXISTS (
                     SELECT 1 FROM business_branches WHERE id = NEW.branch_id AND business_id = NEW.business_id
                 )
                 BEGIN SELECT RAISE(ABORT, \'O local do quarto tem de pertencer ao mesmo negócio.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_hotel_stay_insert BEFORE INSERT ON hotel_stays
                 WHEN NOT EXISTS (SELECT 1 FROM hotel_rooms WHERE id = NEW.room_id AND business_id = NEW.business_id)
                 BEGIN SELECT RAISE(ABORT, \'A estadia tem de usar um quarto do mesmo negócio.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_event_pass_insert BEFORE INSERT ON event_passes
                 WHEN NOT EXISTS (SELECT 1 FROM operational_records WHERE id = NEW.event_id AND business_id = NEW.business_id)
                 BEGIN SELECT RAISE(ABORT, \'O passe tem de pertencer a um evento do mesmo negócio.\'); END'
            );
            $pdo->exec('CREATE INDEX idx_audit_business_created ON audit_events(business_id, created_at DESC)');
            $pdo->exec('CREATE INDEX idx_audit_entity ON audit_events(business_id, entity_type, entity_id)');
            $pdo->exec('CREATE INDEX idx_permissions_member ON membership_permissions(business_id, user_id)');
            $pdo->exec('CREATE INDEX idx_purchase_business_status ON purchase_orders(business_id, status, created_at)');
            $pdo->exec('CREATE INDEX idx_operational_business_type ON operational_records(business_id, record_type, status)');
            $pdo->exec('CREATE INDEX idx_hotel_stay_room_dates ON hotel_stays(room_id, status, check_in_at, check_out_at)');
            $pdo->exec('CREATE INDEX idx_event_pass_code ON event_passes(business_id, pass_code)');
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (7)');
            $pdo->exec('PRAGMA user_version = 7');
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('A migração de auditoria e operações causou uma violação de chave estrangeira.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function applyVariantsAndAttributes(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 5) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'CREATE TABLE product_variants (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                    branch_id INTEGER NULL REFERENCES business_branches(id) ON DELETE RESTRICT,
                    identifier TEXT NOT NULL CHECK (length(identifier) BETWEEN 1 AND 120),
                    sku TEXT NOT NULL COLLATE NOCASE CHECK (length(sku) BETWEEN 1 AND 40),
                    barcode TEXT NULL COLLATE NOCASE CHECK (barcode IS NULL OR length(barcode) BETWEEN 1 AND 80),
                    price_cents INTEGER NULL CHECK (price_cents IS NULL OR price_cents >= 0),
                    cost_price_cents INTEGER NULL CHECK (cost_price_cents IS NULL OR cost_price_cents >= 0),
                    quantity INTEGER NOT NULL DEFAULT 0 CHECK (quantity BETWEEN 0 AND 2147483647),
                    min_stock_level INTEGER NOT NULL DEFAULT 0 CHECK (min_stock_level BETWEEN 0 AND 2147483647),
                    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (business_id, sku),
                    UNIQUE (business_id, barcode)
                )'
            );
            $pdo->exec(
                'CREATE TABLE custom_attribute_definitions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    attribute_key TEXT NOT NULL COLLATE NOCASE CHECK (length(attribute_key) BETWEEN 1 AND 40),
                    label TEXT NOT NULL CHECK (length(label) BETWEEN 1 AND 120),
                    field_type TEXT NOT NULL CHECK (field_type IN (\'text\', \'integer\', \'decimal\', \'boolean\', \'date\', \'select\')),
                    options_json TEXT NOT NULL DEFAULT \'[]\',
                    required INTEGER NOT NULL DEFAULT 0 CHECK (required IN (0, 1)),
                    default_value TEXT NULL,
                    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (business_id, attribute_key)
                )'
            );
            $pdo->exec(
                'CREATE TABLE product_attribute_values (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
                    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                    attribute_id INTEGER NOT NULL REFERENCES custom_attribute_definitions(id) ON DELETE RESTRICT,
                    value TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (product_id, attribute_id)
                )'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_variant_references_insert
                 BEFORE INSERT ON product_variants
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM products WHERE id = NEW.product_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A variante tem de pertencer ao mesmo negócio do produto.\') END;
                     SELECT CASE WHEN NEW.branch_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM business_branches WHERE id = NEW.branch_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O local da variante tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_variant_references_update
                 BEFORE UPDATE OF business_id, product_id, branch_id ON product_variants
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM products WHERE id = NEW.product_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A variante tem de pertencer ao mesmo negócio do produto.\') END;
                     SELECT CASE WHEN NEW.branch_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM business_branches WHERE id = NEW.branch_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O local da variante tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_attribute_value_references_insert
                 BEFORE INSERT ON product_attribute_values
                 BEGIN
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM products WHERE id = NEW.product_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'O atributo tem de pertencer ao mesmo negócio do produto.\') END;
                     SELECT CASE WHEN NOT EXISTS (
                         SELECT 1 FROM custom_attribute_definitions WHERE id = NEW.attribute_id AND business_id = NEW.business_id
                     ) THEN RAISE(ABORT, \'A definição do atributo tem de pertencer ao mesmo negócio.\') END;
                 END'
            );
            $pdo->exec('CREATE INDEX idx_variants_product ON product_variants(business_id, product_id, active)');
            $pdo->exec('CREATE INDEX idx_attributes_business ON custom_attribute_definitions(business_id, active)');
            $pdo->exec('CREATE INDEX idx_attribute_values_product ON product_attribute_values(business_id, product_id)');
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (5)');
            $pdo->exec('PRAGMA user_version = 5');
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('The V6 variants migration caused a foreign key violation.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
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

    private static function applyVariantSales(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() >= 6) {
            return;
        }
        $pdo->beginTransaction();
        try {
            self::addColumn($pdo, 'sale_items', 'variant_id', 'INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT');
            self::addColumn($pdo, 'stock_movements', 'variant_id', 'INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT');
            $pdo->exec(
                'CREATE TRIGGER validate_sale_variant_insert
                 BEFORE INSERT ON sale_items
                 WHEN NEW.variant_id IS NOT NULL AND NOT EXISTS (
                     SELECT 1 FROM product_variants
                     WHERE id = NEW.variant_id AND product_id = NEW.product_id AND business_id = NEW.business_id
                 )
                 BEGIN SELECT RAISE(ABORT, \'A variante da venda não pertence ao produto e negócio indicados.\'); END'
            );
            $pdo->exec(
                'CREATE TRIGGER validate_movement_variant_insert
                 BEFORE INSERT ON stock_movements
                 WHEN NEW.variant_id IS NOT NULL AND NOT EXISTS (
                     SELECT 1 FROM product_variants
                     WHERE id = NEW.variant_id AND product_id = NEW.product_id AND business_id = NEW.business_id
                 )
                 BEGIN SELECT RAISE(ABORT, \'A variante do movimento não pertence ao produto e negócio indicados.\'); END'
            );
            $pdo->exec('CREATE INDEX idx_sale_items_variant ON sale_items(variant_id)');
            $pdo->exec('CREATE INDEX idx_movements_variant ON stock_movements(variant_id, created_at)');
            $pdo->exec('INSERT INTO schema_migrations (version) VALUES (6)');
            $pdo->exec('PRAGMA user_version = 6');
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('The V6 variant sales migration caused a foreign key violation.');
            }
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
