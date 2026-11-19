<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/ProductRepository.php';
require_once dirname(__DIR__) . '/app/ProductValidator.php';
require_once dirname(__DIR__) . '/app/CatalogVariantService.php';
require_once dirname(__DIR__) . '/app/AuditPermissionService.php';
require_once dirname(__DIR__) . '/app/OperationalService.php';
require_once dirname(__DIR__) . '/app/PurchaseService.php';
require_once dirname(__DIR__) . '/app/BusinessRepository.php';
require_once dirname(__DIR__) . '/app/BusinessService.php';
require_once dirname(__DIR__) . '/app/BusinessConfigurationService.php';
require_once dirname(__DIR__) . '/app/BusinessCalendar.php';
require_once dirname(__DIR__) . '/app/InventoryService.php';
require_once dirname(__DIR__) . '/app/BusinessStatisticsService.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/helpers.php';

$testDirectory = sys_get_temp_dir() . '/gestock-tests-' . bin2hex(random_bytes(8));
if (!mkdir($testDirectory, 0700)) {
    throw new RuntimeException('Could not create an isolated test directory.');
}

$databasePath = $testDirectory . '/test.sqlite';
$legacyDatabasePath = $testDirectory . '/legacy.sqlite';
$failedMigrationPath = $testDirectory . '/failed-migration.sqlite';
$passed = 0;

$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }

    $passed++;
    fwrite(STDOUT, "PASS: {$message}\n");
};

try {
    $pdo = Database::connect($databasePath);
    $validator = new ProductValidator();

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'products'")->fetchColumn();
    $assert($tables === 'products', 'database initialisation creates the products table');
    $assert(Database::connect($databasePath)->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0, 'database initialisation is repeatable without deleting data');
    $pdo->exec("INSERT INTO businesses (name, industry_key) VALUES ('Empresa de teste', 'custom')");
    $businessId = (int) $pdo->lastInsertId();
    IndustryTemplateService::apply($pdo, $businessId, 'custom');
    $repository = new ProductRepository($pdo, $businessId);
    $assert(
        (int) $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === 7
        && (int) $pdo->query('SELECT COUNT(*) FROM item_types WHERE business_id = ' . $businessId)->fetchColumn() === 3
        && (int) $pdo->query('SELECT COUNT(*) FROM business_branches WHERE business_id = ' . $businessId)->fetchColumn() === 1,
        'catalog architecture creates isolated item types and a default branch for each business'
    );
    $auditPermissions = new AuditPermissionService($pdo);
    $auditId = $auditPermissions->record($businessId, 0, 'teste', 'produto', null, null, ['nome' => 'Teste']);
    $assert($auditId > 0 && (int) $pdo->query('SELECT COUNT(*) FROM audit_events WHERE business_id = ' . $businessId)->fetchColumn() === 1, 'append-only audit records are created inside the active business');
    $auditUpdateRejected = false;
    try {
        $pdo->exec('UPDATE audit_events SET action = \'alterado\' WHERE id = ' . $auditId);
    } catch (PDOException) {
        $auditUpdateRejected = true;
    }
    $assert($auditUpdateRejected, 'audit history rejects update attempts');
    $operational = new OperationalService($pdo, $businessId);
    $roomId = $operational->saveRoom([
        'room_number' => '101',
        'room_type' => 'Duplo',
        'status' => 'available',
        'base_price_cents' => '85',
        'branch_id' => null,
    ], 0);
    $stayId = $operational->reserveRoom([
        'room_id' => (string) $roomId,
        'reference' => 'ESTADIA-1',
        'check_in_at' => '2026-12-20T15:00',
        'check_out_at' => '2026-12-22T11:00',
        'adults' => '2',
        'children' => '0',
        'total_cents' => '170',
    ], 0);
    $overlapRejected = false;
    try {
        $operational->reserveRoom([
            'room_id' => (string) $roomId,
            'reference' => 'ESTADIA-2',
            'check_in_at' => '2026-12-21T15:00',
            'check_out_at' => '2026-12-23T11:00',
        ], 0);
    } catch (DomainException) {
        $overlapRejected = true;
    }
    $assert($stayId > 0 && $overlapRejected, 'hotel reservations prevent overlapping stays');
    $eventId = $operational->createRecord([
        'record_type' => 'ticket',
        'reference' => 'EVENTO-1',
        'title' => 'Noite de teste',
        'status' => 'draft',
        'quantity' => '1',
        'total_cents' => '25',
    ], 0);
    $passId = $operational->issuePass($eventId, 'PASSCODE-TEST-123456789', 'Entrada geral', null, 0);
    $operational->redeemPass('PASSCODE-TEST-123456789', 0);
    $duplicatePassRejected = false;
    try {
        $operational->redeemPass('PASSCODE-TEST-123456789', 0);
    } catch (DomainException) {
        $duplicatePassRejected = true;
    }
    $assert($passId > 0 && $duplicatePassRejected, 'event passes support redemption and reject duplicate entry');
    $supplierId = $pdo->prepare("INSERT INTO suppliers (business_id, name) VALUES (:business_id, 'Fornecedor teste') RETURNING id");
    $supplierId->execute(['business_id' => $businessId]);
    $supplierRecordId = (int) $supplierId->fetchColumn();
    $supplierId->closeCursor();
    $productTypeId = (int) $pdo->query("SELECT id FROM item_types WHERE business_id = {$businessId} AND type_key = 'product'")->fetchColumn();
    $insertPurchaseProduct = $pdo->prepare(
        'INSERT INTO products (business_id, item_type_id, name, sku, price_cents, cost_price_cents, quantity, min_stock_level)
         VALUES (:business_id, :item_type_id, :name, :sku, 2500, 0, 2, 0)'
    );
    $insertPurchaseProduct->execute([
        'business_id' => $businessId,
        'item_type_id' => $productTypeId,
        'name' => 'Produto compra',
        'sku' => 'COMPRA-1',
    ]);
    $insertPurchaseProduct->closeCursor();
    $purchaseProductId = (int) $pdo->lastInsertId();
    $purchase = new PurchaseService($pdo, $businessId);
    $purchaseId = $purchase->create([
        'supplier_id' => (string) $supplierRecordId,
        'order_number' => 'OC-1',
        'notes' => 'Receção de teste',
        'branch_id' => null,
        'product_id' => [(string) $purchaseProductId],
        'variant_id' => [''],
        'quantity' => ['5'],
        'unit_cost' => ['12,50'],
    ], 0);
    $purchase->receive($purchaseId, 0);
    $purchaseResult = $purchase->find($purchaseId);
    $stockAfterPurchase = (int) $pdo->query("SELECT quantity FROM products WHERE id = {$purchaseProductId}")->fetchColumn();
    $costAfterPurchase = (int) $pdo->query("SELECT cost_price_cents FROM products WHERE id = {$purchaseProductId}")->fetchColumn();
    $assert($purchaseResult['status'] === 'received' && $stockAfterPurchase === 7 && $costAfterPurchase === 1250, 'purchase orders receive stock and update the supplier cost snapshot');
    $pdo->exec("DELETE FROM stock_movements WHERE business_id = {$businessId} AND product_id = {$purchaseProductId}");
    $pdo->exec("DELETE FROM purchase_order_items WHERE business_id = {$businessId} AND purchase_order_id = {$purchaseId}");
    $pdo->exec("DELETE FROM purchase_orders WHERE id = {$purchaseId} AND business_id = {$businessId}");
    $pdo->exec("DELETE FROM products WHERE id = {$purchaseProductId} AND business_id = {$businessId}");
    $pdo->exec("DELETE FROM suppliers WHERE id = {$supplierRecordId} AND business_id = {$businessId}");
    $configuration = new BusinessConfigurationService($pdo, $businessId);
    $branchId = $configuration->saveBranch('Loja Centro', 'CENTRO', 'Rua Principal, 1');
    $assert(
        $branchId > 0
        && count($configuration->branches()) === 2
        && count($configuration->itemTypes()) === 3,
        'business configuration can add a branch and list saleable item types'
    );
    $serviceType = $pdo->query("SELECT id FROM item_types WHERE business_id = {$businessId} AND type_key = 'service'")->fetchColumn();
    $configuration->setItemTypeEnabled((int) $serviceType, false);
    $inactiveTypeRejected = false;
    try {
        $repository->create([
            'name' => 'Serviço inativo', 'sku' => 'INACTIVE-1', 'description' => null,
            'price_cents' => 1000, 'cost_price_cents' => 0, 'quantity' => 0, 'min_stock_level' => 0,
            'category_id' => null, 'supplier_id' => null, 'item_type_id' => (int) $serviceType,
        ]);
    } catch (DomainException) {
        $inactiveTypeRejected = true;
    }
    $assert($inactiveTypeRejected, 'item types cannot be used after being disabled');

    $failedMigrationPdo = new PDO('sqlite:' . $failedMigrationPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $failedMigrationPdo->exec('CREATE TABLE businesses (id INTEGER PRIMARY KEY)');
    unset($failedMigrationPdo);
    $migrationFailed = false;
    try {
        Database::connect($failedMigrationPath);
    } catch (PDOException) {
        $migrationFailed = true;
    }
    $failedMigrationPdo = new PDO('sqlite:' . $failedMigrationPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $assert(
        $migrationFailed
        && (int) $failedMigrationPdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'business_memberships'")->fetchColumn() === 0
        && (int) $failedMigrationPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 0,
        'failed V6 schema migrations roll back all versioned DDL and leave no applied migration marker'
    );
    unset($failedMigrationPdo);

    $legacyPdo = new PDO('sqlite:' . $legacyDatabasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacyPdo->exec(
        'CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, sku TEXT NOT NULL COLLATE NOCASE UNIQUE,
            description TEXT NULL, price_cents INTEGER NOT NULL, quantity INTEGER NOT NULL,
            min_stock_level INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $legacyPdo->exec('PRAGMA foreign_keys = ON');
    $legacyPdo->exec(
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
            email TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(email) BETWEEN 3 AND 254),
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN (\'administrator\', \'employee\')),
            active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $legacyPdo->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Admin legado', 'legacy@example.test', 'hash-legado', 'administrator')");
    $legacyPdo->exec('CREATE TABLE legacy_user_refs (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id))');
    $legacyPdo->exec('INSERT INTO legacy_user_refs (id, user_id) VALUES (1, 1)');
    $legacyPdo->exec("INSERT INTO products (name, sku, price_cents, quantity, min_stock_level) VALUES ('Produto legado', 'LEGACY-1', 1234, 3, 1)");
    $legacyPdo->exec(
        'CREATE TABLE sales (
            id INTEGER PRIMARY KEY, sale_number TEXT NOT NULL, client_id INTEGER NULL,
            client_name_snapshot TEXT NOT NULL, total_cents INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT \'completed\', created_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, cancelled_at TEXT NULL,
            cancelled_by INTEGER NULL
        )'
    );
    $legacyPdo->exec(
        'CREATE TABLE sale_items (
            id INTEGER PRIMARY KEY, sale_id INTEGER NOT NULL REFERENCES sales(id),
            product_id INTEGER NOT NULL REFERENCES products(id), product_name_snapshot TEXT NOT NULL,
            sku_snapshot TEXT NOT NULL, quantity INTEGER NOT NULL, unit_price_cents INTEGER NOT NULL,
            line_total_cents INTEGER NOT NULL
        )'
    );
    $legacyPdo->exec("INSERT INTO sales (id, sale_number, client_name_snapshot, total_cents) VALUES (1, 'LEGACY-SALE', 'Cliente legado', 1234)");
    $legacyPdo->exec("INSERT INTO sale_items (id, sale_id, product_id, product_name_snapshot, sku_snapshot, quantity, unit_price_cents, line_total_cents) VALUES (1, 1, 1, 'Produto legado', 'LEGACY-1', 1, 1234, 1234)");
    unset($legacyPdo);
    $migratedPdo = Database::connect($legacyDatabasePath);
    $legacyBusinessId = (int) $migratedPdo->query('SELECT id FROM businesses LIMIT 1')->fetchColumn();
    $legacyProduct = $migratedPdo->query("SELECT * FROM products WHERE sku = 'LEGACY-1'")->fetch();
    $assert($legacyProduct['name'] === 'Produto legado' && $legacyProduct['cost_price_cents'] === 0 && (int) $legacyProduct['business_id'] === $legacyBusinessId, 'schema upgrade preserves and assigns existing products to the legacy business');
    $legacyUser = $migratedPdo->query("SELECT name, role FROM users WHERE email = 'legacy@example.test'")->fetch();
    $legacyAuth = new Auth($migratedPdo);
    $legacyCustomerId = $legacyAuth->createUser('Cliente legado', 'customer@example.test', 'palavra-passe-forte-123', 'customer');
    $assert($legacyUser['name'] === 'Admin legado' && $legacyUser['role'] === 'administrator', 'customer account migration preserves existing users');
    $legacySaleItem = $migratedPdo->query('SELECT unit_cost_cents FROM sale_items WHERE id = 1')->fetch();
    $assert($legacySaleItem['unit_cost_cents'] === null && (int) $migratedPdo->query('SELECT COUNT(*) FROM sale_items')->fetchColumn() === 1, 'historical sale items survive the additive cost snapshot migration as unknown');
    $migratedPdo->exec("UPDATE sales SET created_at = '2026-07-01 10:00:00' WHERE id = 1");
    $legacyProfit = (new BusinessStatisticsService($migratedPdo, $legacyBusinessId))->reportSummary('2026-07-01', '2026-07-01')['summary'];
    $assert($legacyProfit['known_cost_lines'] === 0 && $legacyProfit['gross_profit_cents'] === 0, 'reports do not infer missing historical costs from current product records');
    $assert($legacyAuth->authenticate('customer@example.test', 'palavra-passe-forte-123')['role'] === 'customer', 'customer accounts can authenticate after upgrading the schema');
    $assert(
        $migratedPdo->query('PRAGMA foreign_key_check')->fetch() === false
        && (int) $migratedPdo->query('SELECT user_id FROM legacy_user_refs WHERE id = 1')->fetchColumn() === 1
        && (int) $migratedPdo->query('SELECT business_id FROM sale_items WHERE id = 1')->fetchColumn() === $legacyBusinessId,
        'tenant migration preserves historical foreign keys and associates sale history with the legacy business'
    );
    unset($migratedPdo);

    $emptySummary = $repository->summary();
    $assert((int) $emptySummary['total_products'] === 0 && (int) $emptySummary['low_stock'] === 0 && (int) $emptySummary['out_of_stock'] === 0, 'dashboard summary supports an empty database');
    $emptyDashboard = (new BusinessStatisticsService($pdo, $businessId))->dashboard(new DateTimeImmutable('2026-07-01 12:00:00', BusinessCalendar::timezone()));
    $assert($emptyDashboard['sales']['today_revenue_cents'] === 0 && $emptyDashboard['sales']['year_sales_count'] === 0 && count($emptyDashboard['revenue_trend']) === 30 && count($emptyDashboard['monthly_trend']) === 12, 'business statistics return zero totals and usable daily and monthly trends for an empty database');
    $assert($repository->dashboardStockItems() === [], 'dashboard stock list supports an empty database');

    [$validProduct, $validationErrors] = $validator->validate([
        'name' => 'Arabica coffee beans',
        'sku' => 'coffee-1',
        'description' => '',
        'price' => '12.99',
        'quantity' => '4',
        'min_stock_level' => '5',
    ]);
    $assert($validationErrors === [] && $validProduct['price_cents'] === 1299, 'validator accepts valid input and converts price to exact cents');
    [$europeanPrice, $europeanPriceErrors] = $validator->validate([
        'name' => 'Produto europeu', 'sku' => 'EURO-1', 'price' => '12,34', 'cost_price' => '5,20',
        'quantity' => '1', 'min_stock_level' => '0',
    ]);
    $assert($europeanPriceErrors === [] && $europeanPrice['price_cents'] === 1234 && $europeanPrice['cost_price_cents'] === 520, 'validator accepts European decimal separators for price and cost');

    $invalidInputs = [
        ['name' => '', 'sku' => 'A-1', 'price' => '1.00', 'quantity' => '1', 'min_stock_level' => '1'],
        ['name' => 'Missing SKU', 'sku' => '', 'price' => '1.00', 'quantity' => '1', 'min_stock_level' => '1'],
        ['name' => 'Missing price', 'sku' => 'A-2', 'price' => '', 'quantity' => '1', 'min_stock_level' => '1'],
        ['name' => 'Missing quantity', 'sku' => 'A-3', 'price' => '1.00', 'quantity' => '', 'min_stock_level' => '1'],
        ['name' => 'Missing minimum', 'sku' => 'A-4', 'price' => '1.00', 'quantity' => '1', 'min_stock_level' => ''],
        ['name' => 'Invalid price', 'sku' => 'A-5', 'price' => '-1.00', 'quantity' => '1', 'min_stock_level' => '1'],
        ['name' => 'Invalid quantity', 'sku' => 'A-6', 'price' => '1.00', 'quantity' => '-1', 'min_stock_level' => '1'],
        ['name' => 'Invalid minimum', 'sku' => 'A-7', 'price' => '1.00', 'quantity' => '1', 'min_stock_level' => '-1'],
    ];
    foreach ($invalidInputs as $index => $invalidInput) {
        [$data, $errors] = $validator->validate($invalidInput);
        $assert($data === null && $errors !== [], 'validator rejects invalid input case ' . ($index + 1));
    }

    $productId = $repository->create($validProduct);
    $assert($productId > 0 && $repository->find($productId)['price_cents'] === 1299, 'product can be inserted and retrieved');
    $assert(count($repository->all('COFFEE')) === 1, 'product search matches SKU without regard to case');
    $assert($repository->skuExists('COFFEE-1'), 'SKU uniqueness check is case-insensitive');

    $duplicateRejected = false;
    try {
        $repository->create(array_replace($validProduct, ['name' => 'Duplicate', 'sku' => 'COFFEE-1']));
    } catch (PDOException) {
        $duplicateRejected = true;
    }
    $assert($duplicateRejected, 'database rejects duplicate SKUs');

    $updated = array_replace($validProduct, ['quantity' => 0, 'min_stock_level' => 5, 'price_cents' => 1350]);
    $assert($repository->update($productId, $updated), 'product can be updated');
    $updatedProduct = $repository->find($productId);
    $assert($updatedProduct['quantity'] === 0 && $updatedProduct['price_cents'] === 1350, 'updated values are persisted');

    $summary = $repository->summary();
    $assert((int) $summary['total_products'] === 1 && (int) $summary['out_of_stock'] === 1, 'dashboard summary reflects saved out-of-stock product');

    $lowStockId = $repository->create(array_replace($validProduct, [
        'name' => 'Olive oil',
        'sku' => 'OIL-1',
        'quantity' => 5,
        'min_stock_level' => 5,
    ]));
    $healthyId = $repository->create(array_replace($validProduct, [
        'name' => 'Tote bag',
        'sku' => 'TOTE-1',
        'quantity' => 6,
        'min_stock_level' => 5,
    ]));
    $summary = $repository->summary();
    $assert((int) $summary['low_stock'] === 1 && (int) $summary['in_stock'] === 1 && (int) $summary['approaching_stock'] === 1, 'low, in-stock and approaching-minimum boundaries follow the specified rules');
    $assert((int) $summary['potential_sales_value_cents'] === 14289, 'potential inventory value uses current stock and selling prices in integer cents');
    $assert($repository->count('', ['stock' => 'low']) === 1 && $repository->count('', ['stock' => 'out']) === 1, 'product filters report matching low-stock and out-of-stock totals');
    $assert($repository->dashboardStockItems()[0]['id'] === $productId, 'dashboard stock prioritises out-of-stock items');

    $assert($repository->delete($lowStockId) && $repository->find($lowStockId) === null, 'product can be deleted');
    $assert($repository->delete($healthyId), 'additional test product can be deleted');
    $assert($repository->delete($productId) && $repository->summary()['total_products'] === 0, 'deleting all products restores the empty dashboard state');

    $business = new BusinessRepository($pdo, $businessId);
    $categoryId = $business->save('categories', ['name' => 'Bebidas', 'description' => 'Artigos para beber']);
    $supplierId = $business->save('suppliers', [
        'name' => 'Fornecedor Local', 'contact_person' => 'Rita Silva', 'email' => 'rita@example.test',
        'phone' => '910000000', 'address' => 'Rua Central', 'tax_number' => 'PT123', 'notes' => '',
    ]);
    $clientId = $business->save('clients', [
        'name' => 'Cliente de Teste', 'email' => 'cliente@example.test', 'phone' => '',
        'address' => '', 'tax_number' => '', 'notes' => 'Conta de validação',
    ]);
    $assert($business->find('categories', $categoryId)['name'] === 'Bebidas', 'categories can be created and retrieved');
    $assert($business->find('suppliers', $supplierId)['email'] === 'rita@example.test', 'suppliers can be created and retrieved');
    $assert($business->find('clients', $clientId)['name'] === 'Cliente de Teste', 'clients can be created and retrieved');
    $requiredRecordMessage = '';
    try {
        $business->save('categories', ['name' => '', 'description' => '']);
    } catch (InvalidArgumentException $exception) {
        $requiredRecordMessage = $exception->getMessage();
    }
    $assert($requiredRecordMessage === 'O campo nome é obrigatório.', 'record validation feedback uses Portuguese field labels');
    $business->save('categories', ['name' => 'Bebidas e café', 'description' => 'Atualizada'], $categoryId);
    $assert($business->find('categories', $categoryId)['name'] === 'Bebidas e café', 'categories can be updated');
    $business->save('categories', ['name' => 'Cereais', 'description' => '']);
    $business->save('categories', ['name' => 'Conservas', 'description' => '']);
    $categoryPage = $business->all('categories', '', 1, 1);
    $assert($business->count('categories') === 3 && $business->count('categories', 'Cereais') === 1 && count($categoryPage) === 1 && $categoryPage[0]['name'] === 'Cereais', 'business records support filtered counts and stable pagination');
    $subcategoryId = $business->save('categories', [
        'name' => 'Chás',
        'description' => '',
        'parent_id' => $categoryId,
    ]);
    $subcategory = $business->find('categories', $subcategoryId);
    $assert((int) $subcategory['parent_id'] === $categoryId, 'categories can be nested under a same-business parent');
    $cycleRejected = false;
    try {
        $business->save('categories', [
            'name' => 'Bebidas e café',
            'description' => 'Atualizada',
            'parent_id' => $subcategoryId,
        ], $categoryId);
    } catch (InvalidArgumentException) {
        $cycleRejected = true;
    }
    $assert($cycleRejected, 'category updates reject hierarchy cycles');
    $pdo->exec("INSERT INTO businesses (name, industry_key) VALUES ('Outra empresa', 'custom')");
    $secondBusinessId = (int) $pdo->lastInsertId();
    $secondBusiness = new BusinessRepository($pdo, $secondBusinessId);
    $secondCategoryId = $secondBusiness->save('categories', ['name' => 'Bebidas', 'description' => 'Outra empresa']);
    $secondRepository = new ProductRepository($pdo, $secondBusinessId);
    $assert(
        $secondCategoryId !== $categoryId
        && $secondBusiness->count('categories') === 1
        && $secondRepository->count() === 0,
        'business records and category names are isolated between businesses'
    );
    $crossBusinessParentRejected = false;
    try {
        $secondBusiness->save('categories', [
            'name' => 'Categoria externa',
            'description' => '',
            'parent_id' => $categoryId,
        ]);
    } catch (InvalidArgumentException) {
        $crossBusinessParentRejected = true;
    }
    $crossBusinessDatabaseReferenceRejected = false;
    try {
        $pdo->prepare(
            'INSERT INTO products (
                business_id, name, sku, price_cents, quantity, min_stock_level, category_id
             ) VALUES (:business_id, :name, :sku, 100, 0, 0, :category_id)'
        )->execute([
            'business_id' => $secondBusinessId,
            'name' => 'Referência inválida',
            'sku' => 'CROSS-1',
            'category_id' => $categoryId,
        ]);
    } catch (PDOException) {
        $crossBusinessDatabaseReferenceRejected = true;
    }
    $assert(
        $crossBusinessParentRejected && $crossBusinessDatabaseReferenceRejected,
        'category parents and product references cannot cross business boundaries'
    );

    $auth = new Auth($pdo);
    $userId = $auth->createInitialAdministrator('Admin de Teste', 'admin@example.test', 'palavra-passe-forte-123');
    $ownerRole = $pdo->prepare('SELECT role FROM business_memberships WHERE business_id = :business_id AND user_id = :user_id');
    $ownerRole->execute(['business_id' => $businessId, 'user_id' => $userId]);
    $assert($ownerRole->fetchColumn() === 'owner', 'the initial administrator becomes the owner of the existing business');
    $assert($auth->authenticate('ADMIN@example.test', 'palavra-passe-forte-123')['role'] === 'owner', 'user authentication is case-insensitive and verifies password hashes with business membership roles');
    $assert($auth->authenticate('admin@example.test', 'incorrecta') === null, 'authentication rejects an incorrect password');
    $memberId = $auth->createUser('Colaborador Local', 'local@example.test', 'palavra-passe-forte-123', 'employee', $businessId);
    $auth->resetMemberPassword($memberId, $businessId, $userId, 'nova-palavra-passe-segura-123');
    $assert($auth->authenticate('local@example.test', 'nova-palavra-passe-segura-123') !== null, 'an administrator can reset a non-owner password within the active business');
    $sharedMemberId = $auth->createUser('Colaborador Partilhado', 'shared@example.test', 'palavra-passe-forte-123', 'employee', $businessId);
    $sharedMembership = $pdo->prepare(
        'INSERT INTO business_memberships (business_id, user_id, role) VALUES (:business_id, :user_id, \'employee\')'
    );
    $sharedMembership->execute(['business_id' => $secondBusinessId, 'user_id' => $sharedMemberId]);
    $sharedPasswordRejected = false;
    try {
        $auth->resetMemberPassword($sharedMemberId, $businessId, $userId, 'outra-palavra-passe-segura-123');
    } catch (DomainException) {
        $sharedPasswordRejected = true;
    }
    $assert(
        $sharedPasswordRejected
        && $auth->authenticate('shared@example.test', 'palavra-passe-forte-123') !== null
        && $auth->authenticate('shared@example.test', 'outra-palavra-passe-segura-123') === null,
        'password resets cannot alter credentials for accounts shared with another business'
    );
    $ownerPasswordRejected = false;
    try {
        $auth->resetMemberPassword($userId, $businessId, $memberId, 'tentativa-palavra-passe-segura');
    } catch (DomainException) {
        $ownerPasswordRejected = true;
    }
    $assert($ownerPasswordRejected, 'business members cannot reset the owner account password');
    $customerId = $auth->createUser('Cliente de Teste', 'customer@example.test', 'palavra-passe-forte-123', 'customer');
    $assert($customerId > 0 && $auth->authenticate('CUSTOMER@example.test', 'palavra-passe-forte-123')['role'] === 'customer', 'customer accounts can be created and authenticated separately');
    $businessService = new BusinessService($pdo);
    $hotelBusinessId = $businessService->create($userId, 'Hotel V6', 'hotel', $businessId);
    $hotelBusiness = $pdo->prepare('SELECT industry_key FROM businesses WHERE id = :id');
    $hotelBusiness->execute(['id' => $hotelBusinessId]);
    $hotelModules = $pdo->prepare(
        'SELECT module_key FROM business_modules WHERE business_id = :business_id AND enabled = 1'
    );
    $hotelModules->execute(['business_id' => $hotelBusinessId]);
    $hotelModuleKeys = $hotelModules->fetchAll(PDO::FETCH_COLUMN);
    $hotelMembership = $pdo->prepare(
        'SELECT role FROM business_memberships WHERE business_id = :business_id AND user_id = :user_id'
    );
    $hotelMembership->execute(['business_id' => $hotelBusinessId, 'user_id' => $userId]);
    $hotelCatalogRows = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM products WHERE business_id = :business_id)
            + (SELECT COUNT(*) FROM categories WHERE business_id = :business_id)
            + (SELECT COUNT(*) FROM clients WHERE business_id = :business_id)
            + (SELECT COUNT(*) FROM suppliers WHERE business_id = :business_id)
            + (SELECT COUNT(*) FROM sales WHERE business_id = :business_id)
            + (SELECT COUNT(*) FROM stock_movements WHERE business_id = :business_id)'
    );
    $hotelCatalogRows->execute(['business_id' => $hotelBusinessId]);
    $assert(
        $hotelBusiness->fetchColumn() === 'hotel'
        && in_array('sales', $hotelModuleKeys, true)
        && $hotelMembership->fetchColumn() === 'owner'
        && (int) $hotelCatalogRows->fetchColumn() === 0,
        'business creation applies an editable template without generating operational records'
    );
    $configuredBusinessId = $businessService->create(
        $userId,
        'Negócio configurado',
        'custom',
        $businessId,
        ['sales', 'customers', 'sales']
    );
    $configuredModules = $pdo->prepare(
        'SELECT module_key FROM business_modules WHERE business_id = :business_id AND enabled = 1 ORDER BY module_key'
    );
    $configuredModules->execute(['business_id' => $configuredBusinessId]);
    $assert(
        $configuredModules->fetchAll(PDO::FETCH_COLUMN) === ['customers', 'sales', 'settings', 'users'],
        'business creation applies only selected modules and keeps users and settings available'
    );
    $businessCountBeforeInvalidModule = (int) $pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn();
    $invalidModuleRejected = false;
    try {
        $businessService->create($userId, 'Módulo inválido', 'custom', $businessId, ['unknown']);
    } catch (InvalidArgumentException) {
        $invalidModuleRejected = true;
    }
    $assert(
        $invalidModuleRejected
        && (int) $pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === $businessCountBeforeInvalidModule,
        'business creation rejects unknown modules without creating partial businesses'
    );
    $employeeId = $auth->createUser(
        'Colaborador de Teste',
        'employee@example.test',
        'palavra-passe-forte-123',
        'employee',
        $businessId
    );
    $employeeCreateRejected = false;
    try {
        $businessService->create($employeeId, 'Não autorizado', 'custom', $businessId);
    } catch (DomainException) {
        $employeeCreateRejected = true;
    }
    $assert($employeeCreateRejected, 'business creation is restricted to owner and administrator memberships');
    $assert($auth->hasUsers(), 'initial administrator setup is detected after account creation');
    $secondInitialAdminRejected = false;
    try {
        $auth->createInitialAdministrator('Outro Admin', 'other@example.test', 'palavra-passe-forte-123');
    } catch (DomainException) {
        $secondInitialAdminRejected = true;
    }
    $assert($secondInitialAdminRejected, 'the initial administrator can only be created once');

    $saleProductId = $repository->create(array_replace($validProduct, [
        'name' => 'Chá verde', 'sku' => 'TEA-1', 'price_cents' => 1250, 'cost_price_cents' => 500,
        'quantity' => 10, 'min_stock_level' => 2, 'category_id' => $categoryId, 'supplier_id' => $supplierId,
    ]));
    $otherBusinessProductId = $secondRepository->create(array_replace($validProduct, [
        'name' => 'Outro chá verde', 'sku' => 'TEA-1', 'quantity' => 1,
    ]));
    $crossTenantReferenceRejected = false;
    try {
        $secondRepository->create(array_replace($validProduct, [
            'name' => 'Produto com referência externa', 'sku' => 'OTHER-1', 'category_id' => $categoryId,
        ]));
    } catch (DomainException) {
        $crossTenantReferenceRejected = true;
    }
    $assert(
        $otherBusinessProductId > 0
        && $repository->find($otherBusinessProductId) === null
        && $secondRepository->find($saleProductId) === null
        && $crossTenantReferenceRejected,
        'catalog reads, SKU uniqueness and references remain scoped to the owning business'
    );
    $secondService = new InventoryService($pdo, $secondBusinessId);
    $crossTenantSaleRejected = false;
    try {
        $secondService->createSale(null, [['product_id' => $saleProductId, 'quantity' => 1]]);
    } catch (DomainException) {
        $crossTenantSaleRejected = true;
    }
    $assert($crossTenantSaleRejected && $secondService->salesCount() === 0, 'sales cannot read or consume products from another business');
    $service = new InventoryService($pdo, $businessId);
    $managedProductId = $service->createProduct(array_replace($validProduct, [
        'name' => 'Produto com movimentos', 'sku' => 'MANAGED-1', 'quantity' => 2,
    ]), $userId);
    $assert($repository->find($managedProductId)['quantity'] === 2, 'managed product creation records initial stock through the inventory service');
    $assert(count($service->movements(['product_id' => (string) $managedProductId, 'type' => 'in'])) === 1, 'initial product stock creates a stock-in movement');
    $service->updateProduct($managedProductId, array_replace($validProduct, [
        'name' => 'Produto com movimentos', 'sku' => 'MANAGED-1', 'quantity' => 5,
    ]), $userId);
    $assert($repository->find($managedProductId)['quantity'] === 5 && count($service->movements(['product_id' => (string) $managedProductId, 'type' => 'correction'])) === 1, 'product edits that change stock record a correction movement');
    $movementPage = $service->movements(['product_id' => (string) $managedProductId], 1, 1);
    $assert($service->movementCount(['product_id' => (string) $managedProductId]) === 2 && count($movementPage) === 1 && $movementPage[0]['movement_type'] === 'in', 'stock movement history supports filtered counts and pagination');
    $managedProductDeleteRejected = false;
    try {
        $repository->delete($managedProductId);
    } catch (DomainException) {
        $managedProductDeleteRejected = true;
    }
    $assert($managedProductDeleteRejected, 'products with stock audit history are protected from deletion');

    $saleId = $service->createSale($clientId, [
        ['product_id' => (string) $saleProductId, 'quantity' => '2'],
        ['product_id' => (string) $saleProductId, 'quantity' => '1'],
    ], $userId);
    $sale = $service->sale($saleId);
    $assert($sale['sale_number'] !== '' && $sale['total_cents'] === 3750, 'sale creation calculates and stores accurate server-side totals');
    $assert(count($sale['items']) === 1 && $sale['items'][0]['quantity'] === 3 && $sale['items'][0]['unit_price_cents'] === 1250, 'sale items preserve historical price and consolidate duplicate product lines');
    $assert($repository->find($saleProductId)['quantity'] === 7, 'sale creation deducts stock');
    $movementRows = $service->movements(['product_id' => (string) $saleProductId, 'type' => 'sale']);
    $assert(count($movementRows) === 1 && $movementRows[0]['stock_before'] === 10 && $movementRows[0]['stock_after'] === 7, 'sale records a stock-out movement with before and after quantities');

    $salesBeforeFailure = count($service->sales());
    $failedForStock = false;
    try {
        $service->createSale(null, [['product_id' => $saleProductId, 'quantity' => 99]], $userId);
    } catch (DomainException) {
        $failedForStock = true;
    }
    $assert($failedForStock && $repository->find($saleProductId)['quantity'] === 7 && count($service->sales()) === $salesBeforeFailure, 'insufficient stock rejects sale without changing data');

    $pdo->exec("CREATE TRIGGER fail_sale_item BEFORE INSERT ON sale_items BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
    $rolledBack = false;
    try {
        $service->createSale(null, [['product_id' => $saleProductId, 'quantity' => 1]], $userId);
    } catch (PDOException) {
        $rolledBack = true;
    }
    $pdo->exec('DROP TRIGGER fail_sale_item');
    $assert($rolledBack && $repository->find($saleProductId)['quantity'] === 7 && count($service->sales()) === $salesBeforeFailure, 'sale transaction rolls back stock and sale when a later insert fails');

    $service->adjustStock($saleProductId, 'in', 4, 'Reposição de teste', $userId);
    $assert($repository->find($saleProductId)['quantity'] === 11, 'manual stock addition updates quantity through the movement service');
    $service->cancelSale($saleId, $userId);
    $assert($repository->find($saleProductId)['quantity'] === 14, 'cancelling a sale restores stock');
    $returnedMovements = $service->movements(['product_id' => (string) $saleProductId, 'type' => 'sale_return']);
    $assert(count($returnedMovements) === 1, 'sale cancellation records a restoration movement');
    $duplicateCancellationRejected = false;
    try {
        $service->cancelSale($saleId, $userId);
    } catch (DomainException) {
        $duplicateCancellationRejected = true;
    }
    $assert($duplicateCancellationRejected && $repository->find($saleProductId)['quantity'] === 14, 'a sale cannot be cancelled twice');
    $assert($service->salesCount('', '', '', 'cancelled') === 1 && $service->salesCount('', '', '', 'completed') === 0, 'sales can be filtered by completion status');

    $report = $service->reportSummary(date('Y-m-01'), date('Y-m-d'));
    $assert((int) $report['summary']['sales_count'] === 0 && $report['inventory_value_cents'] === 7000, 'reports exclude cancelled sales and calculate inventory value from cost');

    $performanceProductA = $repository->create(array_replace($validProduct, [
        'name' => 'Produto de desempenho A', 'sku' => 'PERF-A', 'price_cents' => 1000, 'cost_price_cents' => 300,
        'quantity' => 100, 'min_stock_level' => 10,
    ]));
    $performanceProductB = $repository->create(array_replace($validProduct, [
        'name' => 'Produto de desempenho B', 'sku' => 'PERF-B', 'price_cents' => 2000, 'cost_price_cents' => 600,
        'quantity' => 100, 'min_stock_level' => 10,
    ]));
    $setPerformanceSaleDate = static function (int $id, string $utcDateTime) use ($pdo): void {
        $saleDate = $pdo->prepare('UPDATE sales SET created_at = :created_at WHERE id = :id');
        $saleDate->execute(['created_at' => $utcDateTime, 'id' => $id]);
        $movementDate = $pdo->prepare('UPDATE stock_movements SET created_at = :created_at WHERE sale_id = :id');
        $movementDate->execute(['created_at' => $utcDateTime, 'id' => $id]);
    };
    $historicSale = $service->createSale($clientId, [
        ['product_id' => $performanceProductA, 'quantity' => 2],
        ['product_id' => $performanceProductB, 'quantity' => 1],
    ], $userId);
    $setPerformanceSaleDate($historicSale, '2026-06-30 22:50:00');
    $historicItems = $service->sale($historicSale)['items'];
    $assert((int) $service->sale($historicSale)['total_cents'] === 4000 && count($historicItems) === 2, 'a sale with multiple products preserves its exact aggregate revenue');
    $assert((int) $historicItems[0]['unit_price_cents'] === 1000 && (int) $historicItems[0]['unit_cost_cents'] === 300, 'sale items snapshot historical selling price and purchase cost');

    $updatedPerformanceProduct = array_intersect_key(
        $repository->find($performanceProductA),
        array_flip(['name', 'sku', 'description', 'price_cents', 'cost_price_cents', 'quantity', 'min_stock_level', 'category_id', 'supplier_id'])
    );
    $repository->update($performanceProductA, array_replace($updatedPerformanceProduct, ['price_cents' => 1500, 'cost_price_cents' => 450]));
    $cancelledPerformanceSale = $service->createSale($clientId, [
        ['product_id' => $performanceProductA, 'quantity' => 1],
        ['product_id' => $performanceProductB, 'quantity' => 1],
    ], $userId);
    $setPerformanceSaleDate($cancelledPerformanceSale, '2026-06-30 23:10:00');
    $service->cancelSale($cancelledPerformanceSale, $userId);
    $setPerformanceSaleDate($cancelledPerformanceSale, '2026-06-30 23:10:00');
    $currentPriceSale = $service->createSale($clientId, [['product_id' => $performanceProductA, 'quantity' => 2]], $userId);
    $setPerformanceSaleDate($currentPriceSale, '2026-07-01 01:00:00');
    $currentPerformanceProduct = array_intersect_key(
        $repository->find($performanceProductA),
        array_flip(['name', 'sku', 'description', 'price_cents', 'cost_price_cents', 'quantity', 'min_stock_level', 'category_id', 'supplier_id'])
    );
    $repository->update($performanceProductA, array_replace($currentPerformanceProduct, ['price_cents' => 1900, 'cost_price_cents' => 999]));
    $secondTodaySale = $service->createSale($clientId, [['product_id' => $performanceProductB, 'quantity' => 1]], $userId);
    $setPerformanceSaleDate($secondTodaySale, '2026-07-01 05:00:00');
    $assert((int) $service->sale($currentPriceSale)['items'][0]['unit_price_cents'] === 1500 && (int) $service->sale($currentPriceSale)['items'][0]['unit_cost_cents'] === 450, 'later sales keep changed historical selling prices and costs');

    $yearBoundarySale = $service->createSale($clientId, [['product_id' => $performanceProductB, 'quantity' => 1]], $userId);
    $setPerformanceSaleDate($yearBoundarySale, '2026-01-01 00:30:00');
    $priorYearSale = $service->createSale($clientId, [['product_id' => $performanceProductB, 'quantity' => 1]], $userId);
    $setPerformanceSaleDate($priorYearSale, '2025-12-31 23:30:00');
    $service->adjustStock($performanceProductA, 'correction', 94, 'Correção para testar o painel', $userId);
    $pdo->prepare('UPDATE stock_movements SET created_at = :created_at WHERE product_id = :product_id AND movement_type = \'correction\'')
        ->execute(['created_at' => '2026-07-01 03:00:00', 'product_id' => $performanceProductA]);

    $fixedNow = new DateTimeImmutable('2026-07-01 12:00:00', BusinessCalendar::timezone());
    $performanceDashboard = (new BusinessStatisticsService($pdo, $businessId))->dashboard($fixedNow);
    $assert($performanceDashboard['sales']['today_revenue_cents'] === 5000 && $performanceDashboard['sales']['yesterday_revenue_cents'] === 4000, 'sales crossing Lisbon midnight are assigned to the correct local day');
    $assert($performanceDashboard['sales']['month_revenue_cents'] === 5000 && $performanceDashboard['sales']['previous_month_revenue_cents'] === 4000, 'sales across a local month boundary are assigned to the correct month');
    $assert($performanceDashboard['sales']['today_sales_count'] === 2 && $performanceDashboard['sales']['month_sales_count'] === 2 && $performanceDashboard['sales']['average_sale_cents'] === 2600, 'completed sale counts and the all-time average use completed sales only');
    $assert($performanceDashboard['sales']['year_revenue_cents'] === 11000 && $performanceDashboard['sales']['year_sales_count'] === 4, 'year totals use Lisbon boundaries and exclude cancelled and prior-year sales');
    $assert($performanceDashboard['profit']['month_cost_cents'] === 1500 && $performanceDashboard['profit']['month_profit_cents'] === 3500, 'gross profit uses per-sale historical costs and excludes cancelled sales');
    $assert($performanceDashboard['best_sellers'][0]['quantity'] === 2 && $performanceDashboard['best_sellers'][0]['revenue_cents'] === 3000, 'product performance aggregates multiple items at historical sale prices');
    $assert($performanceDashboard['revenue_trend'][29]['date'] === '2026-07-01' && $performanceDashboard['revenue_trend'][29]['revenue_cents'] === 5000, 'dashboard daily trend sums sales from multiple hours on the same local date');
    $assert($performanceDashboard['customers'][0]['sales_count'] === 2 && $performanceDashboard['customers'][0]['revenue_cents'] === 5000, 'customer breakdown aggregates completed sales against customer snapshots');
    $assert($performanceDashboard['movements']['corrections_count'] === 1 && $performanceDashboard['movements']['corrections_units'] === 2, 'movement totals distinguish stock corrections and adjusted units');
    $assert($performanceDashboard['movements']['entries_units'] === 2 && $performanceDashboard['movements']['exits_units'] === 7, 'movement totals include sale returns and correction direction');
    $performanceReport = $service->reportSummary('2026-06-30', '2026-07-01');
    $assert($performanceReport['summary']['sales_count'] === 3 && $performanceReport['summary']['revenue_cents'] === 9000, 'reports and sales history share the same local date boundaries and completed-sale totals');
    $assert($performanceReport['summary']['cost_cents'] === 2700 && $performanceReport['summary']['gross_profit_cents'] === 6300, 'report profit and cost agree with historical sale-item snapshots');
    $assert($service->salesCount('', '2026-07-01', '2026-07-01', 'completed') === 2, 'sales history date filtering follows Europe/Lisbon calendar days');

    $categoryDeleteRejected = false;
    try {
        $business->delete('categories', $categoryId);
    } catch (DomainException) {
        $categoryDeleteRejected = true;
    }
    $assert($categoryDeleteRejected, 'categories in use cannot be deleted');
    $productDeleteRejected = false;
    try {
        $repository->delete($saleProductId);
    } catch (DomainException) {
        $productDeleteRejected = true;
    }
    $assert($productDeleteRejected, 'products referenced by sales are protected from deletion');
    $business->delete('suppliers', $supplierId);
    $business->delete('clients', $clientId);
    $assert($repository->find($saleProductId)['supplier_id'] === null && $service->sale($saleId)['client_name_snapshot'] === 'Cliente de Teste', 'supplier and client deletion preserve historical records safely');

    $catalog = new CatalogVariantService($pdo, $businessId);
    $variantId = $catalog->createVariant($saleProductId, [
        'identifier' => 'Tamanho grande', 'sku' => 'TEA-1-L', 'barcode' => '5601234567890',
        'price_cents' => 1450, 'cost_price_cents' => 600, 'quantity' => 4, 'min_stock_level' => 1,
    ]);
    $assert(
        $variantId > 0
        && $catalog->variants($saleProductId)[0]['sku'] === 'TEA-1-L'
        && (int) $catalog->variants($saleProductId)[0]['quantity'] === 4,
        'variants have independent SKU, barcode, price and stock values'
    );
    $variantTenantRejected = false;
    try {
        (new CatalogVariantService($pdo, $secondBusinessId))->createVariant($saleProductId, [
            'identifier' => 'Externa', 'sku' => 'EXTERNAL-1', 'quantity' => 1,
        ]);
    } catch (DomainException) {
        $variantTenantRejected = true;
    }
    $assert($variantTenantRejected, 'variants cannot reference a product from another business');
    $duplicateVariantRejected = false;
    try {
        $catalog->createVariant($saleProductId, ['identifier' => 'Outra', 'sku' => 'TEA-1-L', 'quantity' => 1]);
    } catch (PDOException) {
        $duplicateVariantRejected = true;
    }
    $assert($duplicateVariantRejected, 'variant SKUs are unique within a business');
    $variantSaleService = new InventoryService($pdo, $businessId);
    $variantSaleId = $variantSaleService->createSale(null, [[
        'product_id' => $saleProductId, 'variant_id' => $variantId, 'quantity' => 2,
    ]], $userId);
    $variantSaleItem = $variantSaleService->sale($variantSaleId)['items'][0];
    $variantAfterSale = $catalog->variants($saleProductId)[0];
    $assert(
        (int) $variantSaleItem['variant_id'] === $variantId
        && $variantSaleItem['sku_snapshot'] === 'TEA-1-L'
        && (int) $variantSaleItem['unit_price_cents'] === 1450
        && (int) $variantAfterSale['quantity'] === 2,
        'checkout prices and consumes the selected variant stock with historical snapshots'
    );
    $variantSaleService->cancelSale($variantSaleId, $userId);
    $assert((int) $catalog->variants($saleProductId)[0]['quantity'] === 4, 'cancelling a variant sale restores variant stock');
    $variantSaleService->adjustVariantStock($variantId, 'in', 3, 'Receção de compra', $userId);
    $assert((int) $catalog->variants($saleProductId)[0]['quantity'] === 7, 'stock management can adjust a variant and records its movement');

    $sizeAttributeId = $catalog->createAttributeDefinition([
        'attribute_key' => 'size', 'label' => 'Tamanho', 'field_type' => 'select',
        'options' => ['Pequeno', 'Grande'], 'required' => true, 'default_value' => 'Grande',
    ]);
    $organicAttributeId = $catalog->createAttributeDefinition([
        'attribute_key' => 'organic', 'label' => 'Biológico', 'field_type' => 'boolean',
        'required' => false, 'default_value' => 'false',
    ]);
    $requiredAttributeId = $catalog->createAttributeDefinition([
        'attribute_key' => 'batch_code', 'label' => 'Lote', 'field_type' => 'text',
        'required' => true,
    ]);
    $dateAttributeId = $catalog->createAttributeDefinition([
        'attribute_key' => 'expiry_date', 'label' => 'Validade', 'field_type' => 'date',
    ]);
    $catalog->saveProductAttributes($saleProductId, [$sizeAttributeId => 'Pequeno', $requiredAttributeId => 'L1']);
    $attributes = $catalog->productAttributes($saleProductId);
    $attributeValues = [];
    foreach ($attributes as $attribute) {
        $attributeValues[$attribute['attribute_key']] = $attribute['value'];
    }
    $assert(
        count($attributes) === 4
        && $attributeValues['size'] === 'Pequeno'
        && $attributeValues['organic'] === 'false'
        && $attributeValues['batch_code'] === 'L1',
        'custom attributes apply field validation and defaults to products'
    );
    $invalidAttributeRejected = false;
    try {
        $catalog->saveProductAttributes($saleProductId, [$sizeAttributeId => 'Inexistente']);
    } catch (InvalidArgumentException) {
        $invalidAttributeRejected = true;
    }
    $assert($invalidAttributeRejected, 'custom attributes reject values outside configured options');
    $requiredAttributeRejected = false;
    try {
        $catalog->saveProductAttributes($saleProductId, [$organicAttributeId => 'true']);
    } catch (InvalidArgumentException) {
        $requiredAttributeRejected = true;
    }
    $assert($requiredAttributeRejected, 'required custom attributes reject missing values');
    $invalidDateRejected = false;
    try {
        $catalog->saveProductAttributes($saleProductId, [
            $sizeAttributeId => 'Grande', $requiredAttributeId => 'L2', $dateAttributeId => '2026-02-31',
        ]);
    } catch (InvalidArgumentException) {
        $invalidDateRejected = true;
    }
    $assert($invalidDateRejected, 'date custom attributes reject impossible calendar dates');
    $crossTenantAttributeRejected = false;
    try {
        (new CatalogVariantService($pdo, $secondBusinessId))->createAttributeDefinition([
            'attribute_key' => 'size', 'label' => 'Tamanho', 'field_type' => 'text',
        ]);
        (new CatalogVariantService($pdo, $secondBusinessId))->saveProductAttributes($saleProductId, [$sizeAttributeId => 'Grande']);
    } catch (DomainException) {
        $crossTenantAttributeRejected = true;
    }
    $assert($crossTenantAttributeRejected, 'custom attribute values remain isolated by business');

    $_SESSION = [];
    $csrf = csrfToken();
    $assert(verifyCsrfToken($csrf) && !verifyCsrfToken('invalid-token'), 'CSRF validation accepts only the current session token');

    fwrite(STDOUT, "\n{$passed} checks passed.\n");
} finally {
    unset($repository, $pdo);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal', $legacyDatabasePath, $legacyDatabasePath . '-wal', $legacyDatabasePath . '-shm', $legacyDatabasePath . '-journal', $failedMigrationPath, $failedMigrationPath . '-wal', $failedMigrationPath . '-shm', $failedMigrationPath . '-journal'] as $path) {
        if (is_file($path) && !unlink($path)) {
            fwrite(STDERR, "Warning: could not remove temporary test database file {$path}\n");
        }
    }
    if (is_dir($testDirectory) && !rmdir($testDirectory)) {
        fwrite(STDERR, "Warning: could not remove temporary test directory {$testDirectory}\n");
    }
}
