<aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php" aria-label="Painel GeStock">
        <span class="brand-mark" aria-hidden="true">G</span>
        <span class="brand-name">GeStock<span class="brand-period">.</span></span>
    </a>

    <div class="workspace-label">ESPAÇO DE TRABALHO</div>
    <?php if (!empty($user['business_name'])): ?><div class="workspace-name"><?= e($user['business_name']) ?></div><?php endif; ?>
    <?php
    $moduleQuery = Database::connect()->prepare('SELECT module_key FROM business_modules WHERE business_id = :business_id AND enabled = 1');
    $moduleQuery->execute(['business_id' => (int) ($user['business_id'] ?? 0)]);
    $enabledModules = array_fill_keys($moduleQuery->fetchAll(PDO::FETCH_COLUMN), true);
    ?>
    <nav class="primary-nav" aria-label="Navegação principal">
        <?php foreach ([
            ['label' => 'Painel', 'icon' => 'grid', 'page' => 'index.php'],
            ['label' => 'Negócios', 'icon' => 'grid', 'page' => 'businesses.php'],
            ['label' => 'Produtos', 'icon' => 'box', 'page' => 'products.php', 'module' => 'catalog'],
            ['label' => 'Categorias', 'icon' => 'box', 'page' => 'categories.php', 'module' => 'catalog'],
            ['label' => 'Fornecedores', 'icon' => 'customers', 'page' => 'suppliers.php', 'module' => 'suppliers'],
            ['label' => 'Clientes', 'icon' => 'customers', 'page' => 'clients.php', 'module' => 'customers'],
            ['label' => 'Vendas', 'icon' => 'sales', 'page' => 'sales.php', 'module' => 'sales'],
            ['label' => 'Movimentos de stock', 'icon' => 'stock', 'page' => 'stock.php', 'module' => 'inventory'],
            ['label' => 'Relatórios', 'icon' => 'grid', 'page' => 'reports.php', 'module' => 'reports'],
            ['label' => 'Utilizadores', 'icon' => 'settings', 'page' => 'users.php', 'admin' => true, 'module' => 'users'],
            ['label' => 'Definições', 'icon' => 'settings', 'page' => 'settings.php', 'admin' => true, 'module' => 'settings'],
        ] as $item): ?>
            <?php if ((!isset($item['admin']) || in_array(($user['role'] ?? ''), ['owner', 'administrator'], true))
                && (!isset($item['module']) || isset($enabledModules[$item['module']]))): ?>
                <?php $active = $activePage === $item['page']; ?>
                <a class="nav-link<?= $active ? ' is-active' : '' ?>" href="<?= e($item['page']) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <?= renderIcon($item['icon']) ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="store-avatar" aria-hidden="true"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
        <div class="store-details">
            <span class="store-name"><?= e($user['name'] ?? '') ?></span>
            <span class="store-type"><?= match ($user['role'] ?? '') { 'owner' => 'Proprietário', 'administrator' => 'Administrador', 'customer' => 'Cliente', default => 'Colaborador' } ?></span>
        </div>
        <form action="logout.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="logout-button" type="submit">Sair</button>
        </form>
    </div>
</aside>
