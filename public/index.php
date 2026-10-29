<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
$activePage = 'index.php';
$pageTitle = 'Painel';
$pageSubtitle = 'Resumo atualizado do seu inventário e das vendas.';
$error = '';
try {
    $dashboard = (new InventoryService(Database::connect()))->dashboard();
} catch (Throwable $exception) {
    error_log('GeStock dashboard could not load data: ' . $exception->getMessage());
    $error = 'Não foi possível carregar os dados. Verifique a base de dados privada e tente novamente.';
    $dashboard = [
        'summary' => ['total_products' => 0, 'total_quantity' => 0, 'inventory_value_cents' => 0, 'low_stock' => 0, 'out_of_stock' => 0],
        'today_cents' => 0,
        'month_cents' => 0,
        'recent_sales' => [],
        'stock_items' => [],
    ];
}
$summary = $dashboard['summary'];
$dataAvailable = $error === '';
$metrics = [
    ['label' => 'Produtos', 'value' => $dataAvailable ? number_format((int) $summary['total_products'], 0, ',', '.') : '—', 'detail' => 'Referências ativas no catálogo'],
    ['label' => 'Unidades em stock', 'value' => $dataAvailable ? number_format((int) $summary['total_quantity'], 0, ',', '.') : '—', 'detail' => 'Quantidade total disponível'],
    ['label' => 'Valor do inventário', 'value' => $dataAvailable ? formatPrice((int) $summary['inventory_value_cents']) : '—', 'detail' => 'Calculado ao preço de custo'],
    ['label' => 'Receita de hoje', 'value' => $dataAvailable ? formatPrice($dashboard['today_cents']) : '—', 'detail' => 'Vendas concluídas hoje'],
    ['label' => 'Receita este mês', 'value' => $dataAvailable ? formatPrice($dashboard['month_cents']) : '—', 'detail' => 'Vendas concluídas no mês atual'],
    ['label' => 'Stock baixo / sem stock', 'value' => $dataAvailable ? (int) $summary['low_stock'] . ' / ' . (int) $summary['out_of_stock'] : '—', 'detail' => 'Produtos que exigem atenção'],
];
$pageAction = '<a class="button button--primary" href="sales.php#new-sale">Nova venda</a>';
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="summary-grid dashboard-metrics" aria-label="Indicadores do negócio">
    <?php foreach ($metrics as $metric): ?><article class="summary-item"><span class="summary-label"><?= e($metric['label']) ?></span><strong class="summary-value"><?= e($metric['value']) ?></strong><span class="summary-detail"><?= e($metric['detail']) ?></span></article><?php endforeach; ?>
</section>
<section class="quick-actions" aria-label="Ações rápidas">
    <a class="quick-action" href="sales.php#new-sale"><strong>Registar venda</strong><span>Nova operação de checkout</span></a>
    <a class="quick-action" href="products.php?action=create"><strong>Adicionar produto</strong><span>Atualizar o catálogo</span></a>
    <a class="quick-action" href="reports.php"><strong>Ver relatórios</strong><span>Analisar resultados reais</span></a>
</section>
<section class="panel sales-panel"><div class="panel-heading"><div><h2>Vendas recentes</h2><p>Últimas vendas registadas no sistema.</p></div><a class="table-action" href="sales.php">Ver todas</a></div>
    <?php if ($dashboard['recent_sales'] === []): ?><div class="empty-state"><p>Ainda não existem vendas.</p><a class="button button--primary" href="sales.php#new-sale">Registar a primeira venda</a></div>
    <?php else: ?><div class="table-wrap"><table><thead><tr><th>VENDA</th><th>CLIENTE</th><th>DATA</th><th>TOTAL</th><th>ESTADO</th></tr></thead><tbody>
        <?php foreach ($dashboard['recent_sales'] as $sale): ?><tr><td><a class="table-action" href="sale.php?id=<?= (int) $sale['id'] ?>"><?= e($sale['sale_number']) ?></a></td><td><?= e($sale['client_name_snapshot']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($sale['created_at']))) ?></td><td class="amount-cell"><?= e(formatPrice((int) $sale['total_cents'])) ?></td><td><?= $sale['status'] === 'completed' ? 'Concluída' : 'Anulada' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<section class="panel stock-panel"><div class="panel-heading"><div><h2>Alertas de stock</h2><p>Produtos ordenados pela necessidade de reposição.</p></div><a class="table-action" href="stock.php">Gerir stock</a></div>
    <?php if ($dashboard['stock_items'] === []): ?><div class="empty-state"><?php if ((int) $summary['total_products'] === 0): ?><p>Ainda não existem produtos para acompanhar.</p><a class="button button--primary" href="products.php?action=create">Adicionar primeiro produto</a><?php else: ?><p>Sem alertas de stock. Todas as quantidades estão acima dos limites mínimos.</p><?php endif; ?></div>
    <?php else: ?><div class="stock-list"><?php foreach ($dashboard['stock_items'] as $item):
        $quantity = (int) $item['quantity'];
        $minimum = (int) $item['min_stock_level'];
        $status = $quantity === 0 ? 'Sem stock' : ($quantity <= $minimum ? 'Stock baixo' : 'Disponível');
        $percentage = min(100, (int) round($quantity / max($minimum, 1) * 100));
        ?><div class="stock-row"><div class="stock-product"><span class="product-initial"><?= e(strtoupper(substr($item['name'], 0, 1))) ?></span><span class="product-details"><strong><?= e($item['name']) ?></strong><span><?= e($item['sku']) ?></span></span></div>
            <div class="stock-meter" role="img" aria-label="<?= $percentage ?>% do nível mínimo"><span class="meter-track"><span class="meter-fill<?= $status !== 'Disponível' ? ' is-low' : '' ?>" style="width: <?= $percentage ?>%"></span></span></div>
            <div class="stock-quantity"><strong><?= $quantity ?> unidades</strong><span>Mínimo <?= $minimum ?></span></div>
            <span class="stock-status<?= $status !== 'Disponível' ? ' is-low' : '' ?>"><?= e($status) ?></span></div><?php endforeach; ?></div><?php endif; ?>
</section>
<?php require $root . '/app/views/footer.php'; ?>
