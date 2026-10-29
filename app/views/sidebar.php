<aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php" aria-label="GeStock dashboard">
        <span class="brand-mark" aria-hidden="true">G</span>
        <span class="brand-name">GeStock<span class="brand-period">.</span></span>
    </a>

    <div class="workspace-label">WORKSPACE</div>
    <nav class="primary-nav" aria-label="Main navigation">
        <?php foreach ([
            ['label' => 'Dashboard', 'icon' => 'grid', 'page' => 'index.php'],
            ['label' => 'Products', 'icon' => 'box', 'page' => 'products.php'],
            ['label' => 'Customers', 'icon' => 'customers'],
            ['label' => 'Sales', 'icon' => 'sales'],
            ['label' => 'Stock', 'icon' => 'stock'],
            ['label' => 'Settings', 'icon' => 'settings'],
        ] as $item): ?>
            <?php if (isset($item['page'])): ?>
                <?php $active = $activePage === $item['page']; ?>
                <a class="nav-link<?= $active ? ' is-active' : '' ?>" href="<?= e($item['page']) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <?= renderIcon($item['icon']) ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php else: ?>
                <span class="nav-link nav-link--planned" aria-disabled="true" title="This section is not available yet">
                    <?= renderIcon($item['icon']) ?>
                    <span><?= e($item['label']) ?></span>
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
