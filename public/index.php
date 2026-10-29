<?php
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$databaseError = null;
try {
    $repository = new ProductRepository(Database::connect());
    $productSummary = $repository->summary();
    $stockItems = $repository->dashboardStockItems();
} catch (Throwable $exception) {
    error_log('GeStock dashboard could not load product data: ' . $exception->getMessage());
    $databaseError = 'Product and stock data is temporarily unavailable. Please check the private database storage and try again.';
    $productSummary = null;
    $stockItems = [];
}

$summary = [
    ['label' => 'Total products', 'value' => $productSummary === null ? '—' : (string) $productSummary['total_products'], 'detail' => 'Saved in your product list', 'icon' => 'box'],
    ['label' => 'Sales this month', 'value' => '$12,840', 'detail' => 'Sample monthly total', 'icon' => 'sales'],
    ['label' => 'Low-stock items', 'value' => $productSummary === null ? '—' : (string) $productSummary['low_stock'], 'detail' => 'Above zero, at or below minimum', 'icon' => 'stock'],
    ['label' => 'Out of stock', 'value' => $productSummary === null ? '—' : (string) $productSummary['out_of_stock'], 'detail' => 'Products with zero quantity', 'icon' => 'box'],
];

$recentSales = [
    ['reference' => 'GS-1048', 'customer' => "Maya's Market", 'date' => 'Oct 08, 2026', 'items' => 4, 'total' => '$248.00', 'status' => 'Complete'],
    ['reference' => 'GS-1047', 'customer' => 'Northside Café', 'date' => 'Oct 08, 2026', 'items' => 2, 'total' => '$96.50', 'status' => 'Complete'],
    ['reference' => 'GS-1046', 'customer' => 'Elm Street Goods', 'date' => 'Oct 07, 2026', 'items' => 7, 'total' => '$512.00', 'status' => 'Complete'],
    ['reference' => 'GS-1045', 'customer' => "Maya's Market", 'date' => 'Oct 07, 2026', 'items' => 1, 'total' => '$34.00', 'status' => 'Complete'],
];

$activePage = 'index.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="GeStock stock and sales overview for small businesses.">
    <title>Dashboard | GeStock</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
<div class="app-shell">
    <?php require $root . '/app/views/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <button class="menu-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
                <span></span><span></span><span></span>
            </button>
            <div class="breadcrumb"><span>Workspace</span><span class="breadcrumb-divider">/</span><strong>Dashboard</strong></div>
            <div class="topbar-right">
                <span class="sample-label"><span class="sample-dot"></span>Sample sales</span>
                <span class="topbar-date"><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></span>
                <div class="user-avatar" aria-label="User: Alex Morgan">AM</div>
            </div>
        </header>

        <main class="content" id="dashboard">
            <?php if ($databaseError !== null): ?>
                <div class="feedback feedback--error" role="alert"><?= e($databaseError) ?></div>
            <?php endif; ?>
            <section class="page-heading" aria-labelledby="page-title">
                <div>
                    <p class="eyebrow">OVERVIEW</p>
                    <h1 id="page-title">Good morning, Alex <span aria-hidden="true">☀</span></h1>
                    <p class="page-subtitle">Here’s what’s happening with your store today.</p>
                </div>
                <div class="period-control" aria-label="Showing this month">
                    <span class="calendar-icon" aria-hidden="true">▦</span>
                    This month
                    <span class="chevron" aria-hidden="true">⌄</span>
                </div>
            </section>

            <section class="summary-grid" aria-label="Product and stock summary">
                <?php foreach ($summary as $item): ?>
                    <article class="summary-item">
                        <div class="summary-top">
                            <span class="summary-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="summary-icon"><?= renderIcon($item['icon']) ?></span>
                        </div>
                        <strong class="summary-value"><?= e($item['value']) ?></strong>
                        <span class="summary-detail"><?= e($item['detail']) ?></span>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="panel sales-panel" aria-labelledby="sales-title">
                <div class="panel-heading">
                    <div>
                        <h2 id="sales-title">Recent sales</h2>
                        <p>Latest sample orders from your store</p>
                    </div>
                    <span class="subtle-action">Sample records</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th scope="col">SALE</th>
                            <th scope="col">CUSTOMER</th>
                            <th scope="col">DATE</th>
                            <th scope="col">ITEMS</th>
                            <th scope="col">TOTAL</th>
                            <th scope="col">STATUS</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td class="sale-reference"><?= htmlspecialchars($sale['reference'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($sale['customer'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="muted-cell"><?= htmlspecialchars($sale['date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $sale['items'] ?></td>
                                <td class="amount-cell"><?= htmlspecialchars($sale['total'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="status-pill"><?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel stock-panel" aria-labelledby="stock-title">
                <div class="panel-heading">
                    <div>
                        <h2 id="stock-title">Stock overview</h2>
                        <p>Saved product quantities compared with minimum levels</p>
                    </div>
                        <span class="stock-count"><?= $productSummary === null ? 'Unavailable' : count($stockItems) . ' of ' . (int) $productSummary['total_products'] . ' shown' ?></span>
                </div>
                <div class="stock-list">
                    <?php foreach ($stockItems as $item): ?>
                            <?php
                            $level = match (true) {
                                (int) $item['quantity'] === 0 => 'Out of stock',
                                (int) $item['quantity'] <= (int) $item['min_stock_level'] => 'Low stock',
                                default => 'In stock',
                            };
                            $percentage = min(100, (int) round(((int) $item['quantity'] / max((int) $item['min_stock_level'], 1)) * 100));
                            ?>
                            <div class="stock-row">
                                <div class="stock-product">
                                    <span class="product-initial" aria-hidden="true"><?= e(strtoupper(substr($item['name'], 0, 1))) ?></span>
                                    <span class="product-details">
                                        <strong><?= e($item['name']) ?></strong>
                                        <span><?= e($item['sku']) ?></span>
                                    </span>
                            </div>
                                <div class="stock-meter" role="img" aria-label="<?= $percentage ?> percent of minimum stock level">
                                    <span class="meter-track"><span class="meter-fill<?= $level !== 'In stock' ? ' is-low' : '' ?>" style="width: <?= $percentage ?>%"></span></span>
                                </div>
                                <div class="stock-quantity">
                                    <strong><?= (int) $item['quantity'] ?> in stock</strong>
                                    <span>minimum <?= (int) $item['min_stock_level'] ?></span>
                                </div>
                                <span class="stock-status<?= $level !== 'In stock' ? ' is-low' : '' ?>"><?= e($level) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($databaseError === null && $stockItems === []): ?>
                            <p class="empty-state">No products yet. <a href="products.php?action=create">Add your first product</a> to start tracking stock.</p>
                        <?php endif; ?>
                    </div>
            </section>

            <footer class="page-footer">GeStock <span>·</span> A clear view of your business.</footer>
        </main>
    </div>
</div>
</body>
</html>
