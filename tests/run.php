<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/ProductRepository.php';
require_once dirname(__DIR__) . '/app/ProductValidator.php';
require_once dirname(__DIR__) . '/app/BusinessRepository.php';
require_once dirname(__DIR__) . '/app/InventoryService.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/helpers.php';

$testDirectory = sys_get_temp_dir() . '/gestock-tests-' . bin2hex(random_bytes(8));
if (!mkdir($testDirectory, 0700)) {
    throw new RuntimeException('Could not create an isolated test directory.');
}

$databasePath = $testDirectory . '/test.sqlite';
$legacyDatabasePath = $testDirectory . '/legacy.sqlite';
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
    $repository = new ProductRepository($pdo);
    $validator = new ProductValidator();

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'products'")->fetchColumn();
    $assert($tables === 'products', 'database initialisation creates the products table');
    $assert(Database::connect($databasePath)->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0, 'database initialisation is repeatable without deleting data');

    $legacyPdo = new PDO('sqlite:' . $legacyDatabasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacyPdo->exec(
        'CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, sku TEXT NOT NULL COLLATE NOCASE UNIQUE,
            description TEXT NULL, price_cents INTEGER NOT NULL, quantity INTEGER NOT NULL,
            min_stock_level INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $legacyPdo->exec("INSERT INTO products (name, sku, price_cents, quantity, min_stock_level) VALUES ('Produto legado', 'LEGACY-1', 1234, 3, 1)");
    unset($legacyPdo);
    $migratedPdo = Database::connect($legacyDatabasePath);
    $legacyProduct = $migratedPdo->query("SELECT * FROM products WHERE sku = 'LEGACY-1'")->fetch();
    $assert($legacyProduct['name'] === 'Produto legado' && $legacyProduct['cost_price_cents'] === 0, 'schema upgrade preserves existing products and adds cost data safely');
    unset($migratedPdo);

    $emptySummary = $repository->summary();
    $assert((int) $emptySummary['total_products'] === 0 && (int) $emptySummary['low_stock'] === 0 && (int) $emptySummary['out_of_stock'] === 0, 'dashboard summary supports an empty database');
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
    $assert((int) $summary['low_stock'] === 1 && (int) $summary['in_stock'] === 1, 'low and in-stock status boundaries follow the specified rules');
    $assert($repository->count('', ['stock' => 'low']) === 1 && $repository->count('', ['stock' => 'out']) === 1, 'product filters report matching low-stock and out-of-stock totals');
    $assert($repository->dashboardStockItems()[0]['id'] === $productId, 'dashboard stock prioritises out-of-stock items');

    $assert($repository->delete($lowStockId) && $repository->find($lowStockId) === null, 'product can be deleted');
    $assert($repository->delete($healthyId), 'additional test product can be deleted');
    $assert($repository->delete($productId) && $repository->summary()['total_products'] === 0, 'deleting all products restores the empty dashboard state');

    $business = new BusinessRepository($pdo);
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
    $business->save('categories', ['name' => 'Bebidas e café', 'description' => 'Atualizada'], $categoryId);
    $assert($business->find('categories', $categoryId)['name'] === 'Bebidas e café', 'categories can be updated');

    $auth = new Auth($pdo);
    $userId = $auth->createInitialAdministrator('Admin de Teste', 'admin@example.test', 'palavra-passe-forte-123');
    $assert($auth->authenticate('ADMIN@example.test', 'palavra-passe-forte-123')['role'] === 'administrator', 'user authentication is case-insensitive and verifies password hashes');
    $assert($auth->authenticate('admin@example.test', 'incorrecta') === null, 'authentication rejects an incorrect password');
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
    $service = new InventoryService($pdo);
    $managedProductId = $service->createProduct(array_replace($validProduct, [
        'name' => 'Produto com movimentos', 'sku' => 'MANAGED-1', 'quantity' => 2,
    ]), $userId);
    $assert($repository->find($managedProductId)['quantity'] === 2, 'managed product creation records initial stock through the inventory service');
    $assert(count($service->movements(['product_id' => (string) $managedProductId, 'type' => 'in'])) === 1, 'initial product stock creates a stock-in movement');
    $service->updateProduct($managedProductId, array_replace($validProduct, [
        'name' => 'Produto com movimentos', 'sku' => 'MANAGED-1', 'quantity' => 5,
    ]), $userId);
    $assert($repository->find($managedProductId)['quantity'] === 5 && count($service->movements(['product_id' => (string) $managedProductId, 'type' => 'correction'])) === 1, 'product edits that change stock record a correction movement');
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

    $_SESSION = [];
    $csrf = csrfToken();
    $assert(verifyCsrfToken($csrf) && !verifyCsrfToken('invalid-token'), 'CSRF validation accepts only the current session token');

    fwrite(STDOUT, "\n{$passed} checks passed.\n");
} finally {
    unset($repository, $pdo);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal', $legacyDatabasePath, $legacyDatabasePath . '-wal', $legacyDatabasePath . '-shm', $legacyDatabasePath . '-journal'] as $path) {
        if (is_file($path) && !unlink($path)) {
            fwrite(STDERR, "Warning: could not remove temporary test database file {$path}\n");
        }
    }
    if (is_dir($testDirectory) && !rmdir($testDirectory)) {
        fwrite(STDERR, "Warning: could not remove temporary test directory {$testDirectory}\n");
    }
}
