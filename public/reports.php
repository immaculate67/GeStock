<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
$pdo = Database::connect();
$service = new InventoryService($pdo);
$from = validDateInput($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01');
$to = validDateInput($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$report = $service->reportSummary($from, $to);
if (($_GET['export'] ?? '') === 'sales') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio-vendas.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Não foi possível gerar o ficheiro CSV.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Data', 'Venda', 'Cliente', 'Total', 'Estado'], ';', '"', '');
    $offset = 0;
    do {
        $batch = $service->sales('', $from, $to, 500, 'completed', $offset);
        foreach ($batch as $sale) {
            $cells = [$sale['created_at'], $sale['sale_number'], $sale['client_name_snapshot'], formatPrice((int) $sale['total_cents']), 'Concluída'];
            foreach ($cells as &$cell) {
                if (preg_match('/^[=+\-@]/', (string) $cell) === 1) {
                    $cell = "'" . $cell;
                }
            }
            unset($cell);
            fputcsv($output, $cells, ';', '"', '');
        }
        $offset += count($batch);
    } while (count($batch) === 500);
    fclose($output);
    exit;
}
$activePage = 'reports.php';
$pageTitle = 'Relatórios';
$pageSubtitle = 'Indicadores calculados a partir das vendas e do inventário.';
require $root . '/app/views/header.php';
$summary = $report['summary'];
?>
<section class="panel products-panel"><div class="panel-heading products-heading"><div><h2>Vendas por período</h2><p>Vendas concluídas entre <?= e(date('d/m/Y', strtotime($from))) ?> e <?= e(date('d/m/Y', strtotime($to))) ?></p></div>
<form class="search-form" method="get"><input type="date" name="from" aria-label="Data inicial" value="<?= e($from) ?>"><input type="date" name="to" aria-label="Data final" value="<?= e($to) ?>"><button class="button button--secondary" type="submit">Aplicar</button><a class="button button--secondary" href="?from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;export=sales">Exportar CSV</a></form></div>
<div class="summary-grid report-summary"><article class="summary-item"><span class="summary-label">Receita</span><strong class="summary-value"><?= e(formatPrice((int) $summary['revenue_cents'])) ?></strong><span class="summary-detail">Vendas concluídas</span></article><article class="summary-item"><span class="summary-label">Vendas</span><strong class="summary-value"><?= (int) $summary['sales_count'] ?></strong><span class="summary-detail">No intervalo selecionado</span></article><article class="summary-item"><span class="summary-label">Valor do inventário</span><strong class="summary-value"><?= e(formatPrice($report['inventory_value_cents'])) ?></strong><span class="summary-detail">Quantidade × custo atual</span></article></div>
<div class="table-wrap"><table><thead><tr><th>DIA</th><th>RECEITA</th></tr></thead><tbody><?php foreach ($report['daily'] as $day): ?><tr><td><?= e(date('d/m/Y', strtotime($day['report_date']))) ?></td><td class="amount-cell"><?= e(formatPrice((int) $day['revenue_cents'])) ?></td></tr><?php endforeach; ?><?php if ($report['daily'] === []): ?><tr><td colspan="2" class="empty-state">Sem vendas neste período.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel products-panel"><div class="panel-heading"><div><h2>Produtos mais vendidos</h2></div></div><div class="table-wrap"><table><thead><tr><th>PRODUTO</th><th>UNIDADES</th><th>RECEITA</th></tr></thead><tbody><?php foreach ($report['best_sellers'] as $item): ?><tr><td><?= e($item['name']) ?></td><td><?= (int) $item['quantity'] ?></td><td><?= e(formatPrice((int) $item['revenue_cents'])) ?></td></tr><?php endforeach; ?><?php if ($report['best_sellers'] === []): ?><tr><td colspan="3" class="empty-state">Ainda não há vendas para analisar.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel products-panel"><div class="panel-heading"><div><h2>Alertas de stock</h2><p>Produtos no mínimo, abaixo do mínimo ou sem stock.</p></div></div><div class="table-wrap"><table><thead><tr><th>PRODUTO</th><th>SKU</th><th>STOCK</th><th>MÍNIMO</th></tr></thead><tbody><?php foreach ($report['low_stock'] as $product): ?><tr><td><?= e($product['name']) ?></td><td><?= e($product['sku']) ?></td><td><?= (int) $product['quantity'] ?></td><td><?= (int) $product['min_stock_level'] ?></td></tr><?php endforeach; ?><?php if ($report['low_stock'] === []): ?><tr><td colspan="4" class="empty-state">Não existem alertas de stock baixo.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel products-panel"><div class="panel-heading"><div><h2>Receita mensal</h2><p>Vendas concluídas agrupadas por mês no intervalo selecionado.</p></div></div><div class="table-wrap"><table><thead><tr><th>MÊS</th><th>RECEITA</th></tr></thead><tbody><?php foreach ($report['monthly'] as $month): ?><tr><td><?= e($month['report_month']) ?></td><td><?= e(formatPrice((int) $month['revenue_cents'])) ?></td></tr><?php endforeach; ?><?php if ($report['monthly'] === []): ?><tr><td colspan="2" class="empty-state">Sem receita no período.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel products-panel"><div class="panel-heading"><div><h2>Movimentos de stock no período</h2></div></div><div class="table-wrap"><table><thead><tr><th>DATA</th><th>PRODUTO</th><th>TIPO</th><th>STOCK</th><th>MOTIVO</th></tr></thead><tbody><?php foreach ($report['movements'] as $movement): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($movement['created_at']))) ?></td><td><?= e($movement['product_name']) ?></td><td><?= e(movementTypeLabel($movement['movement_type'])) ?></td><td><?= (int) $movement['stock_before'] ?> → <?= (int) $movement['stock_after'] ?></td><td><?= e($movement['reason']) ?></td></tr><?php endforeach; ?><?php if ($report['movements'] === []): ?><tr><td colspan="5" class="empty-state">Sem movimentos no período.</td></tr><?php endif; ?></tbody></table></div></section>
<?php require $root . '/app/views/footer.php'; ?>
