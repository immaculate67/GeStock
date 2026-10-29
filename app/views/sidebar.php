<aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php" aria-label="Painel GeStock">
        <span class="brand-mark" aria-hidden="true">G</span>
        <span class="brand-name">GeStock<span class="brand-period">.</span></span>
    </a>

    <div class="workspace-label">ESPAÇO DE TRABALHO</div>
    <nav class="primary-nav" aria-label="Navegação principal">
        <?php foreach ([
            ['label' => 'Painel', 'icon' => 'grid', 'page' => 'index.php'],
            ['label' => 'Produtos', 'icon' => 'box', 'page' => 'products.php'],
            ['label' => 'Categorias', 'icon' => 'box', 'page' => 'categories.php'],
            ['label' => 'Fornecedores', 'icon' => 'customers', 'page' => 'suppliers.php'],
            ['label' => 'Clientes', 'icon' => 'customers', 'page' => 'clients.php'],
            ['label' => 'Vendas', 'icon' => 'sales', 'page' => 'sales.php'],
            ['label' => 'Movimentos de stock', 'icon' => 'stock', 'page' => 'stock.php'],
            ['label' => 'Relatórios', 'icon' => 'grid', 'page' => 'reports.php'],
            ['label' => 'Utilizadores', 'icon' => 'settings', 'page' => 'users.php', 'admin' => true],
            ['label' => 'Definições', 'icon' => 'settings', 'page' => 'settings.php', 'admin' => true],
        ] as $item): ?>
            <?php if (!isset($item['admin']) || (($user['role'] ?? '') === 'administrator')): ?>
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
            <span class="store-type"><?= ($user['role'] ?? '') === 'administrator' ? 'Administrador' : 'Colaborador' ?></span>
        </div>
        <form action="logout.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="logout-button" type="submit">Sair</button>
        </form>
    </div>
</aside>
