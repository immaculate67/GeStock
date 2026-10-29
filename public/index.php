<?php
// These figures are illustrative sample data and are not connected to a database.
$summary = [
    ['label' => 'Total products', 'value' => '248', 'detail' => 'Across all categories', 'icon' => 'box'],
    ['label' => 'Sales this month', 'value' => '$12,840', 'detail' => 'Sample monthly total', 'icon' => 'sales'],
    ['label' => 'Low-stock items', 'value' => '08', 'detail' => 'At or below reorder level', 'icon' => 'stock'],
    ['label' => 'Customers', 'value' => '326', 'detail' => 'Sample customer count', 'icon' => 'customers'],
];

$recentSales = [
    ['reference' => 'GS-1048', 'customer' => "Maya's Market", 'date' => 'Oct 08, 2026', 'items' => 4, 'total' => '$248.00', 'status' => 'Complete'],
    ['reference' => 'GS-1047', 'customer' => 'Northside Café', 'date' => 'Oct 08, 2026', 'items' => 2, 'total' => '$96.50', 'status' => 'Complete'],
    ['reference' => 'GS-1046', 'customer' => 'Elm Street Goods', 'date' => 'Oct 07, 2026', 'items' => 7, 'total' => '$512.00', 'status' => 'Complete'],
    ['reference' => 'GS-1045', 'customer' => "Maya's Market", 'date' => 'Oct 07, 2026', 'items' => 1, 'total' => '$34.00', 'status' => 'Complete'],
];

$stockItems = [
    ['name' => 'Arabica coffee beans', 'category' => 'Pantry', 'quantity' => 8, 'unit' => 'bags', 'reorder' => 20, 'level' => 'Low'],
    ['name' => 'Glass storage jar', 'category' => 'Housewares', 'quantity' => 14, 'unit' => 'units', 'reorder' => 18, 'level' => 'Low'],
    ['name' => 'Cotton tote bag', 'category' => 'Accessories', 'quantity' => 32, 'unit' => 'units', 'reorder' => 20, 'level' => 'Healthy'],
    ['name' => 'Olive oil, 500 ml', 'category' => 'Pantry', 'quantity' => 46, 'unit' => 'bottles', 'reorder' => 20, 'level' => 'Healthy'],
];

$iconPaths = [
    'box' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.3 7.6 7.7 4.3 7.7-4.3M12 21v-9.1M8 5.3l8 4.5"/>',
    'sales' => '<path d="M4 19V5m0 14h16"/><path d="m7 14 4-4 3 2 5-6"/><path d="M16 6h3v3"/>',
    'stock' => '<path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3M8 12h8m-8 4h5"/>',
    'customers' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
];

$navigation = [
    ['label' => 'Dashboard', 'icon' => 'grid', 'active' => true],
    ['label' => 'Products', 'icon' => 'box'],
    ['label' => 'Customers', 'icon' => 'customers'],
    ['label' => 'Sales', 'icon' => 'sales'],
    ['label' => 'Stock', 'icon' => 'stock'],
    ['label' => 'Settings', 'icon' => 'settings'],
];

function renderIcon(string $name, array $paths): string
{
    $path = $paths[$name] ?? match ($name) {
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.8 1.1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.8-1.1l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.8-1.1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.8 1.1l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2Z"/>',
        default => '',
    };

    return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}
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
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="#dashboard" aria-label="GeStock dashboard">
            <span class="brand-mark" aria-hidden="true">G</span>
            <span class="brand-name">GeStock<span class="brand-period">.</span></span>
        </a>

        <div class="workspace-label">WORKSPACE</div>
        <nav class="primary-nav" aria-label="Main navigation">
            <?php foreach ($navigation as $item): ?>
                <?php if (!empty($item['active'])): ?>
                    <a class="nav-link is-active" href="#dashboard" aria-current="page">
                        <?= renderIcon($item['icon'], $iconPaths) ?>
                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php else: ?>
                    <span class="nav-link nav-link--planned" aria-disabled="true" title="This section is not available yet">
                        <?= renderIcon($item['icon'], $iconPaths) ?>
                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="store-avatar" aria-hidden="true">M</div>
            <div class="store-details">
                <span class="store-name">Maple &amp; Main</span>
                <span class="store-type">Sample workspace</span>
            </div>
            <span class="store-menu" aria-hidden="true">•••</span>
        </div>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <button class="menu-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
                <span></span><span></span><span></span>
            </button>
            <div class="breadcrumb"><span>Workspace</span><span class="breadcrumb-divider">/</span><strong>Dashboard</strong></div>
            <div class="topbar-right">
                <span class="sample-label"><span class="sample-dot"></span>Sample data</span>
                <span class="topbar-date"><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></span>
                <div class="user-avatar" aria-label="User: Alex Morgan">AM</div>
            </div>
        </header>

        <main class="content" id="dashboard">
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

            <section class="summary-grid" aria-label="Store summary sample figures">
                <?php foreach ($summary as $item): ?>
                    <article class="summary-item">
                        <div class="summary-top">
                            <span class="summary-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="summary-icon"><?= renderIcon($item['icon'], $iconPaths) ?></span>
                        </div>
                        <strong class="summary-value"><?= htmlspecialchars($item['value'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="summary-detail"><?= htmlspecialchars($item['detail'], ENT_QUOTES, 'UTF-8') ?></span>
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
                        <p>Sample quantities compared with reorder levels</p>
                    </div>
                    <span class="stock-count">4 items shown</span>
                </div>
                <div class="stock-list">
                    <?php foreach ($stockItems as $item): ?>
                        <?php $percentage = min(100, (int) round(($item['quantity'] / max($item['reorder'], 1)) * 100)); ?>
                        <div class="stock-row">
                            <div class="stock-product">
                                <span class="product-initial" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($item['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="product-details">
                                    <strong><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                            </div>
                            <div class="stock-meter" role="img" aria-label="<?= $percentage ?> percent of reorder level">
                                <span class="meter-track"><span class="meter-fill<?= $item['level'] === 'Low' ? ' is-low' : '' ?>" style="width: <?= $percentage ?>%"></span></span>
                            </div>
                            <div class="stock-quantity">
                                <strong><?= (int) $item['quantity'] ?> <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>reorder at <?= (int) $item['reorder'] ?></span>
                            </div>
                            <span class="stock-status<?= $item['level'] === 'Low' ? ' is-low' : '' ?>"><?= htmlspecialchars($item['level'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <footer class="page-footer">GeStock <span>·</span> A clear view of your business.</footer>
        </main>
    </div>
</div>
</body>
</html>
