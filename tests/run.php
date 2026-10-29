<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/ProductRepository.php';
require_once dirname(__DIR__) . '/app/ProductValidator.php';

$testDirectory = sys_get_temp_dir() . '/gestock-tests-' . bin2hex(random_bytes(8));
if (!mkdir($testDirectory, 0700)) {
    throw new RuntimeException('Could not create an isolated test directory.');
}

$databasePath = $testDirectory . '/test.sqlite';
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
    $assert($repository->dashboardStockItems()[0]['id'] === $productId, 'dashboard stock prioritises out-of-stock items');

    $assert($repository->delete($lowStockId) && $repository->find($lowStockId) === null, 'product can be deleted');
    $assert($repository->delete($healthyId), 'additional test product can be deleted');
    $assert($repository->delete($productId) && $repository->summary()['total_products'] === 0, 'deleting all products restores the empty dashboard state');

    fwrite(STDOUT, "\n{$passed} checks passed.\n");
} finally {
    unset($repository, $pdo);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $path) {
        if (is_file($path) && !unlink($path)) {
            fwrite(STDERR, "Warning: could not remove temporary test database file {$path}\n");
        }
    }
    if (is_dir($testDirectory) && !rmdir($testDirectory)) {
        fwrite(STDERR, "Warning: could not remove temporary test directory {$testDirectory}\n");
    }
}
