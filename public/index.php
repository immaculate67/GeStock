<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
$activePage = 'index.php';
$pageTitle = 'Painel';
$pageSubtitle = 'Uma leitura atualizada das vendas, da rentabilidade e do inventário.';
$trendPeriod = ($_GET['period'] ?? '') === '12m' ? '12m' : '30d';
$error = '';
try {
    $dashboard = (new InventoryService(Database::connect()))->dashboard();
} catch (Throwable $exception) {
    error_log('GeStock dashboard could not load data: ' . $exception->getMessage());
    $error = 'Não foi possível carregar os dados. Verifique a base de dados privada e tente novamente.';
    $dashboard = null;
}
$pageAction = '<a class="button button--primary" href="sales.php#new-sale">Nova venda</a>';
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?>
    <div class="feedback feedback--error" role="alert"><?= e($error) ?></div>
    <section class="panel dashboard-error"><div class="empty-state"><p>O painel está temporariamente indisponível. Nenhum valor foi substituído por dados de demonstração.</p><a class="button button--secondary" href="index.php">Tentar novamente</a></div></section>
<?php else:
    $summary = $dashboard['summary'];
    $sales = $dashboard['sales'];
    $profit = $dashboard['profit'];
    $knownMonthLines = (int) $profit['month_known_cost_lines'];
    $totalMonthLines = (int) $profit['month_total_sale_lines'];
    $monthComparison = (int) $sales['month_vs_previous_month_cents'];
    $yesterdayRevenue = (int) $sales['yesterday_revenue_cents'];
    $monthRevenue = (int) $sales['month_revenue_cents'];
    $metrics = [
        ['label' => 'Receita de hoje', 'value' => formatPrice((int) $sales['today_revenue_cents']), 'detail' => $yesterdayRevenue > 0 ? (($sales['today_vs_yesterday_cents'] >= 0 ? '+' : '') . number_format($sales['today_vs_yesterday_cents'] / $yesterdayRevenue * 100, 1, ',', '.') . '% face a ontem') : 'Comparação com ontem indisponível'],
        ['label' => 'Receita este mês', 'value' => formatPrice($monthRevenue), 'detail' => $sales['previous_month_revenue_cents'] > 0 ? (($monthComparison >= 0 ? '+' : '') . number_format($monthComparison / (int) $sales['previous_month_revenue_cents'] * 100, 1, ',', '.') . '% face ao mês anterior completo') : 'Sem receita no mês anterior'],
        ['label' => 'Vendas concluídas', 'value' => number_format((int) $sales['month_sales_count'], 0, ',', '.'), 'detail' => number_format((int) $sales['today_sales_count'], 0, ',', '.') . ' hoje · média histórica de ' . formatPrice((int) $sales['average_sale_cents'])],
        ['label' => 'Lucro bruto estimado', 'value' => $knownMonthLines > 0 ? formatPrice((int) $profit['month_profit_cents']) : '—', 'detail' => $knownMonthLines > 0 ? $knownMonthLines . ' de ' . $totalMonthLines . ' linhas com custo histórico registado' : 'Sem custos históricos registados este mês'],
    ];
    $inventoryMetrics = [
        ['label' => 'Produtos no catálogo', 'value' => number_format((int) $summary['total_products'], 0, ',', '.'), 'detail' => 'Referências ativas'],
        ['label' => 'Unidades em stock', 'value' => number_format((int) $summary['total_quantity'], 0, ',', '.'), 'detail' => (int) $summary['out_of_stock'] . ' sem stock · ' . (int) $summary['low_stock'] . ' abaixo do mínimo'],
        ['label' => 'Valor ao custo', 'value' => formatPrice((int) $summary['inventory_value_cents']), 'detail' => 'Stock atual × custo registado'],
        ['label' => 'Valor potencial de venda', 'value' => formatPrice((int) $summary['potential_sales_value_cents']), 'detail' => 'Stock atual × preço de venda'],
    ];
    $chartData = $trendPeriod === '12m' ? $dashboard['monthly_trend'] : $dashboard['revenue_trend'];
    $chartValues = [];
    foreach ($chartData as $point) {
        $chartValues[] = (int) $point['revenue_cents'];
        $chartValues[] = (int) $point['profit_cents'];
    }
    $chartMin = min([0, ...$chartValues]);
    $chartMax = max([0, ...$chartValues]);
    $chartRange = max(1, $chartMax - $chartMin);
    $chartWidth = 720;
    $chartTop = 18;
    $chartBottom = 176;
    $chartPoints = [];
    $profitPoints = [];
    $lastIndex = max(1, count($chartData) - 1);
    foreach ($chartData as $index => $point) {
        $x = 30 + $index * ($chartWidth - 60) / $lastIndex;
        $revenueY = $chartBottom - (((int) $point['revenue_cents'] - $chartMin) / $chartRange) * ($chartBottom - $chartTop);
        $profitY = $chartBottom - (((int) $point['profit_cents'] - $chartMin) / $chartRange) * ($chartBottom - $chartTop);
        $chartPoints[] = number_format($x, 1, '.', '') . ',' . number_format($revenueY, 1, '.', '');
        $profitPoints[] = number_format($x, 1, '.', '') . ',' . number_format($profitY, 1, '.', '');
    }
    $revenueLine = implode(' ', $chartPoints);
    $profitLine = implode(' ', $profitPoints);
    $revenueArea = count($chartPoints) > 0 ? '30,' . $chartBottom . ' ' . $revenueLine . ' ' . (30 + ($chartWidth - 60)) . ',' . $chartBottom : '';
?>
    <section class="dashboard-kpis" aria-label="Desempenho comercial">
        <?php foreach ($metrics as $metric): ?><article class="dashboard-kpi"><span class="summary-label"><?= e($metric['label']) ?></span><strong class="summary-value"><?= e($metric['value']) ?></strong><span class="summary-detail"><?= e($metric['detail']) ?></span></article><?php endforeach; ?>
    </section>
    <section class="dashboard-inventory" aria-label="Resumo do inventário">
        <?php foreach ($inventoryMetrics as $metric): ?><article class="inventory-kpi"><span><?= e($metric['label']) ?></span><strong><?= e($metric['value']) ?></strong><small><?= e($metric['detail']) ?></small></article><?php endforeach; ?>
    </section>
    <section class="dashboard-main-grid">
        <article class="panel dashboard-chart-panel">
            <div class="panel-heading"><div><h2>Atividade de vendas</h2><p><?= $trendPeriod === '12m' ? 'Receita mensal nos últimos 12 meses' : 'Receita diária nos últimos 30 dias' ?> · valores em euros</p></div><a class="table-action" href="reports.php">Relatórios</a></div>
            <nav class="period-switch" aria-label="Período do gráfico"><a href="index.php?period=30d"<?= $trendPeriod === '30d' ? ' aria-current="true"' : '' ?>>30 dias</a><a href="index.php?period=12m"<?= $trendPeriod === '12m' ? ' aria-current="true"' : '' ?>>12 meses</a></nav>
            <div class="chart-legend"><span><i class="legend-swatch legend-swatch--revenue"></i>Receita</span><span><i class="legend-swatch legend-swatch--profit"></i>Lucro bruto conhecido</span></div>
            <div class="revenue-chart">
                <svg viewBox="0 0 720 218" role="img" aria-label="Gráfico de receita e lucro bruto diário nos últimos 30 dias">
                    <?php foreach ([0, 0.33, 0.66, 1] as $grid): $y = $chartTop + $grid * ($chartBottom - $chartTop); ?>
                        <line x1="30" y1="<?= number_format($y, 1, '.', '') ?>" x2="690" y2="<?= number_format($y, 1, '.', '') ?>" class="chart-grid-line"/>
                    <?php endforeach; ?>
                    <?php if ($revenueArea !== ''): ?><polygon points="<?= e($revenueArea) ?>" class="chart-area"/><polyline points="<?= e($revenueLine) ?>" class="chart-line chart-line--revenue"/><polyline points="<?= e($profitLine) ?>" class="chart-line chart-line--profit"/><?php endif; ?>
                    <?php foreach ([0, (int) floor((count($chartData) - 1) / 2), max(0, count($chartData) - 1)] as $index): ?>
                        <?php if (isset($chartData[$index])): $x = 30 + $index * ($chartWidth - 60) / $lastIndex; ?>
                            <text x="<?= number_format($x, 1, '.', '') ?>" y="207" text-anchor="<?= $index === 0 ? 'start' : ($index === count($chartData) - 1 ? 'end' : 'middle') ?>" class="chart-date"><?= e($chartData[$index]['label']) ?></text>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </svg>
                <?php if (array_sum(array_column($chartData, 'sales_count')) === 0): ?><p class="chart-empty">Ainda não existem vendas concluídas para apresentar neste período.</p><?php endif; ?>
            </div>
            <div class="activity-summary"><div><span>Esta semana</span><strong><?= e(formatPrice((int) $sales['week_revenue_cents'])) ?></strong><small><?= (int) $sales['week_sales_count'] ?> vendas concluídas</small></div><div><span>Este ano</span><strong><?= e(formatPrice((int) $sales['year_revenue_cents'])) ?></strong><small><?= (int) $sales['year_sales_count'] ?> vendas concluídas</small></div><div><span>Movimentos este mês</span><strong><?= (int) $dashboard['movements']['movement_count'] ?></strong><small><?= (int) $dashboard['movements']['entries_units'] ?> entradas · <?= (int) $dashboard['movements']['exits_units'] ?> saídas · <?= (int) $dashboard['movements']['corrections_count'] ?> correções</small></div></div>
        </article>
        <article class="panel dashboard-stock-panel">
            <div class="panel-heading"><div><h2>Stock a acompanhar</h2><p><?= (int) $summary['low_stock'] ?> abaixo do mínimo · <?= (int) $summary['approaching_stock'] ?> a aproximar-se</p></div><a class="table-action" href="stock.php">Gerir stock</a></div>
            <?php if ($dashboard['stock_items'] === []): ?><div class="empty-state"><?php if ((int) $summary['total_products'] === 0): ?><p>Adicione produtos para começar a acompanhar o inventário.</p><a class="button button--primary" href="products.php?action=create">Adicionar produto</a><?php else: ?><p>Sem alertas. O stock está acima dos níveis mínimos.</p><?php endif; ?></div>
            <?php else: ?><div class="stock-list"><?php foreach ($dashboard['stock_items'] as $item):
                $quantity = (int) $item['quantity'];
                $minimum = (int) $item['min_stock_level'];
                $status = $quantity === 0 ? 'Sem stock' : ($quantity <= $minimum ? 'Stock baixo' : 'A aproximar-se');
                $percentage = min(100, (int) round($quantity / max($minimum, 1) * 100));
                ?><div class="stock-row"><div class="stock-product"><span class="product-initial"><?= e(strtoupper(substr($item['name'], 0, 1))) ?></span><span class="product-details"><strong><?= e($item['name']) ?></strong><span><?= e($item['sku']) ?></span></span></div><div class="stock-meter" role="img" aria-label="<?= $percentage ?>% do nível mínimo"><span class="meter-track"><span class="meter-fill<?= $status !== 'A aproximar-se' ? ' is-low' : '' ?>" style="width: <?= $percentage ?>%"></span></span></div><div class="stock-quantity"><strong><?= $quantity ?> unidades</strong><span>Mínimo <?= $minimum ?></span></div><span class="stock-status<?= $status !== 'A aproximar-se' ? ' is-low' : '' ?>"><?= e($status) ?></span></div><?php endforeach; ?></div><?php endif; ?>
        </article>
    </section>
    <section class="dashboard-secondary-grid">
        <article class="panel dashboard-list-panel"><div class="panel-heading"><div><h2>Produtos mais vendidos</h2><p>Vendas concluídas este mês, por unidades</p></div><a class="table-action" href="reports.php">Ver análise</a></div>
            <?php if ($dashboard['best_sellers'] === []): ?><div class="empty-state"><p>Sem vendas concluídas para analisar este mês.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>PRODUTO</th><th>UNIDADES</th><th>RECEITA</th></tr></thead><tbody><?php foreach ($dashboard['best_sellers'] as $item): ?><tr><td class="product-name-cell"><strong><?= e($item['name']) ?></strong><span><?= e($item['sku']) ?></span></td><td><?= (int) $item['quantity'] ?></td><td class="amount-cell"><?= e(formatPrice((int) $item['revenue_cents'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </article>
        <article class="panel dashboard-list-panel"><div class="panel-heading"><div><h2>Vendas por cliente</h2><p>Receita por cliente este mês</p></div><a class="table-action" href="clients.php">Clientes</a></div>
            <?php if ($dashboard['customers'] === []): ?><div class="empty-state"><p>Sem vendas associadas a clientes neste mês.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>CLIENTE</th><th>VENDAS</th><th>RECEITA</th></tr></thead><tbody><?php foreach ($dashboard['customers'] as $customer): ?><tr><td><?= e($customer['name']) ?></td><td><?= (int) $customer['sales_count'] ?></td><td class="amount-cell"><?= e(formatPrice((int) $customer['revenue_cents'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </article>
    </section>
    <section class="panel dashboard-recent-panel"><div class="panel-heading"><div><h2>Vendas recentes</h2><p>Últimos registos, incluindo vendas anuladas.</p></div><a class="table-action" href="sales.php">Histórico completo</a></div>
        <?php if ($dashboard['recent_sales'] === []): ?><div class="empty-state"><p>Ainda não existem vendas.</p><a class="button button--primary" href="sales.php#new-sale">Registar a primeira venda</a></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>VENDA</th><th>CLIENTE</th><th>DATA</th><th>TOTAL</th><th>ESTADO</th></tr></thead><tbody><?php foreach ($dashboard['recent_sales'] as $sale): ?><tr><td><a class="table-action" href="sale.php?id=<?= (int) $sale['id'] ?>"><?= e($sale['sale_number']) ?></a></td><td><?= e($sale['client_name_snapshot']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($sale['created_at']))) ?></td><td class="amount-cell"><?= e(formatPrice((int) $sale['total_cents'])) ?></td><td><span class="status-pill<?= $sale['status'] === 'completed' ? '' : ' status-pill--low' ?>"><?= $sale['status'] === 'completed' ? 'Concluída' : 'Anulada' ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
<?php endif; ?>
<?php require $root . '/app/views/footer.php'; ?>
