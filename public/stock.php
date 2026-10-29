<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
$pdo = Database::connect();
$service = new InventoryService($pdo);
$error = '';
$feedback = takeFlash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        try {
            $productId = validRecordId($_POST['product_id'] ?? null);
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
            if ($productId === null || $quantity === false) {
                throw new InvalidArgumentException('Selecione um produto e introduza uma quantidade válida.');
            }
            $service->adjustStock($productId, (string) ($_POST['movement_type'] ?? ''), $quantity, is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '', (int) $user['id']);
            setFlash('success', 'Movimento de stock registado.');
            header('Location: stock.php', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}
$products = $pdo->query('SELECT id, name, sku, quantity, min_stock_level FROM products ORDER BY name COLLATE NOCASE')->fetchAll();
$filters = [
    'product_id' => is_string($_GET['product_id'] ?? null) ? $_GET['product_id'] : '',
    'type' => is_string($_GET['type'] ?? null) ? $_GET['type'] : '',
    'from' => validDateInput($_GET['from'] ?? null) ? $_GET['from'] : '',
    'to' => validDateInput($_GET['to'] ?? null) ? $_GET['to'] : '',
];
$movements = $service->movements($filters);
$activePage = 'stock.php';
$pageTitle = 'Movimentos de stock';
$pageSubtitle = 'Consulte níveis atuais e registe entradas, saídas ou correções.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Ajustar stock</h2><p>As alterações ficam associadas a um motivo e a um utilizador.</p></div></div>
<form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><div class="form-grid">
<div class="field"><label for="product_id">Produto</label><select id="product_id" name="product_id" required><option value="">Selecionar produto</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"><?= e($product['name'] . ' · ' . $product['sku'] . ' (' . $product['quantity'] . ' un.)') ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="movement_type">Tipo de movimento</label><select id="movement_type" name="movement_type"><option value="in">Entrada</option><option value="out">Saída</option><option value="correction">Correção (stock final)</option></select></div>
<div class="field"><label for="quantity">Quantidade</label><input id="quantity" name="quantity" type="number" min="0" step="1" required></div>
<div class="field"><label for="reason">Motivo</label><input id="reason" name="reason" maxlength="500" required></div>
</div><div class="form-actions"><button class="button button--primary" type="submit">Registar movimento</button></div></form></section>
<section class="panel products-panel"><div class="panel-heading products-heading"><div><h2>Stock atual</h2></div><a class="table-action" href="reports.php">Ver alertas e relatórios</a></div><div class="table-wrap"><table><thead><tr><th>PRODUTO</th><th>SKU</th><th>DISPONÍVEL</th><th>MÍNIMO</th><th>ESTADO</th></tr></thead><tbody>
<?php foreach ($products as $product): $level = (int) $product['quantity'] === 0 ? 'Sem stock' : ((int) $product['quantity'] <= (int) $product['min_stock_level'] ? 'Stock baixo' : 'Disponível'); ?>
<tr><td><?= e($product['name']) ?></td><td><?= e($product['sku']) ?></td><td><?= (int) $product['quantity'] ?></td><td><?= (int) $product['min_stock_level'] ?></td><td><?= e($level) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel products-panel"><div class="panel-heading products-heading"><div><h2>Histórico de movimentos</h2></div>
<form class="search-form" method="get"><select name="product_id" aria-label="Filtrar produto"><option value="">Todos os produtos</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"<?= (string) $product['id'] === $filters['product_id'] ? ' selected' : '' ?>><?= e($product['name']) ?></option><?php endforeach; ?></select><select name="type" aria-label="Filtrar tipo"><option value="">Todos os tipos</option><?php foreach (['in' => 'Entrada', 'out' => 'Saída', 'correction' => 'Correção', 'sale' => 'Venda', 'sale_return' => 'Devolução'] as $type => $label): ?><option value="<?= e($type) ?>"<?= $filters['type'] === $type ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><input type="date" name="from" aria-label="Data inicial" value="<?= e($filters['from']) ?>"><input type="date" name="to" aria-label="Data final" value="<?= e($filters['to']) ?>"><button class="button button--secondary" type="submit">Filtrar</button></form></div>
<?php if ($movements === []): ?><div class="empty-state"><p>Não existem movimentos para os filtros selecionados.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>DATA</th><th>PRODUTO</th><th>TIPO</th><th>ALTERAÇÃO</th><th>ANTES</th><th>DEPOIS</th><th>MOTIVO</th></tr></thead><tbody>
<?php foreach ($movements as $movement): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($movement['created_at']))) ?></td><td><?= e($movement['product_name']) ?></td><td><?= e(movementTypeLabel($movement['movement_type'])) ?></td><td><?= (int) $movement['quantity'] ?></td><td><?= (int) $movement['stock_before'] ?></td><td><?= (int) $movement['stock_after'] ?></td><td><?= e($movement['reason']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php require $root . '/app/views/footer.php'; ?>
