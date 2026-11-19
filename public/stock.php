<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
requireBusinessModule($user, 'inventory');
$pdo = Database::connect();
$service = new InventoryService($pdo, (int) $user['business_id']);
$error = '';
$feedback = takeFlash();
$formValues = [
    'product_id' => is_string($_POST['product_id'] ?? null) ? $_POST['product_id'] : '',
    'movement_type' => is_string($_POST['movement_type'] ?? null) ? $_POST['movement_type'] : 'in',
    'quantity' => is_string($_POST['quantity'] ?? null) ? $_POST['quantity'] : '',
    'reason' => is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '',
];
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
            $service->adjustStock($productId, $formValues['movement_type'], $quantity, $formValues['reason'], (int) $user['id']);
            setFlash('success', 'Movimento de stock registado.');
            header('Location: stock.php', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}
$productsQuery = $pdo->prepare('SELECT id, name, sku, quantity, min_stock_level FROM products WHERE business_id = :business_id ORDER BY name COLLATE NOCASE');
$productsQuery->execute(['business_id' => (int) $user['business_id']]);
$products = $productsQuery->fetchAll();
$filters = [
    'product_id' => is_string($_GET['product_id'] ?? null) ? $_GET['product_id'] : '',
    'type' => is_string($_GET['type'] ?? null) && in_array($_GET['type'], ['in', 'out', 'correction', 'sale', 'sale_return'], true) ? $_GET['type'] : '',
    'from' => validDateInput($_GET['from'] ?? null) ? $_GET['from'] : '',
    'to' => validDateInput($_GET['to'] ?? null) ? $_GET['to'] : '',
];
$pageSize = 25;
$movementCount = $service->movementCount($filters);
$totalPages = max(1, (int) ceil($movementCount / $pageSize));
$page = min(min(100000, validRecordId($_GET['page'] ?? null) ?? 1), $totalPages);
$movements = $service->movements($filters, $pageSize, ($page - 1) * $pageSize);
$activePage = 'stock.php';
$pageTitle = 'Movimentos de stock';
$pageSubtitle = 'Consulte níveis atuais e registe entradas, saídas ou correções.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Ajustar stock</h2><p>As alterações ficam associadas a um motivo e a um utilizador.</p></div></div>
<?php if ($products === []): ?><div class="empty-state"><p>Adicione um produto antes de registar movimentos de stock.</p><a class="button button--primary" href="products.php?action=create">Adicionar produto</a></div>
<?php else: ?><form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><?php if ($error !== ''): ?><div class="feedback feedback--error form-message" role="alert"><?= e($error) ?></div><?php endif; ?><div class="form-grid">
<div class="field"><label for="product_id">Produto <span aria-hidden="true">*</span></label><select id="product_id" name="product_id" required><option value="">Selecionar produto</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"<?= (string) $product['id'] === $formValues['product_id'] ? ' selected' : '' ?>><?= e($product['name'] . ' · ' . $product['sku'] . ' (' . $product['quantity'] . ' un.)') ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="movement_type">Tipo de movimento <span aria-hidden="true">*</span></label><select id="movement_type" name="movement_type" required><option value="in"<?= $formValues['movement_type'] === 'in' ? ' selected' : '' ?>>Entrada</option><option value="out"<?= $formValues['movement_type'] === 'out' ? ' selected' : '' ?>>Saída</option><option value="correction"<?= $formValues['movement_type'] === 'correction' ? ' selected' : '' ?>>Correção (stock final)</option></select></div>
<div class="field"><label for="quantity">Quantidade <span aria-hidden="true">*</span></label><input id="quantity" name="quantity" type="number" min="0" step="1" required value="<?= e($formValues['quantity']) ?>"></div>
<div class="field"><label for="reason">Motivo <span aria-hidden="true">*</span></label><input id="reason" name="reason" maxlength="500" required value="<?= e($formValues['reason']) ?>"></div>
</div><div class="form-actions"><button class="button button--primary" type="submit">Registar movimento</button></div></form><?php endif; ?></section>
<section class="panel products-panel"><div class="panel-heading products-heading"><div><h2>Stock atual</h2></div><a class="table-action" href="reports.php">Ver alertas e relatórios</a></div><div class="table-wrap"><table><thead><tr><th scope="col">PRODUTO</th><th scope="col">SKU</th><th scope="col">DISPONÍVEL</th><th scope="col">MÍNIMO</th><th scope="col">ESTADO</th></tr></thead><tbody>
<?php foreach ($products as $product): $level = (int) $product['quantity'] === 0 ? 'Sem stock' : ((int) $product['quantity'] <= (int) $product['min_stock_level'] ? 'Stock baixo' : 'Disponível'); ?>
<tr><td><?= e($product['name']) ?></td><td><?= e($product['sku']) ?></td><td><?= (int) $product['quantity'] ?></td><td><?= (int) $product['min_stock_level'] ?></td><td><span class="status-pill<?= $level === 'Disponível' ? ' status-pill--healthy' : ' status-pill--low' ?>"><?= e($level) ?></span></td></tr><?php endforeach; ?>
<?php if ($products === []): ?><tr><td colspan="5" class="muted-cell">Ainda não existem produtos.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel products-panel"><div class="panel-heading products-heading data-panel-heading"><div><h2>Histórico de movimentos</h2><p><?= $movementCount ?> <?= $movementCount === 1 ? 'movimento' : 'movimentos' ?><?= implode('', $filters) !== '' ? ' encontrados' : ' registados' ?></p></div></div>
<form class="search-form filter-form filter-form--compact" method="get"><select name="product_id" aria-label="Filtrar produto"><option value="">Todos os produtos</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"<?= (string) $product['id'] === $filters['product_id'] ? ' selected' : '' ?>><?= e($product['name']) ?></option><?php endforeach; ?></select><select name="type" aria-label="Filtrar tipo"><option value="">Todos os tipos</option><?php foreach (['in' => 'Entrada', 'out' => 'Saída', 'correction' => 'Correção', 'sale' => 'Venda', 'sale_return' => 'Devolução'] as $type => $label): ?><option value="<?= e($type) ?>"<?= $filters['type'] === $type ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><label class="visually-hidden" for="movement-from">Data inicial</label><input id="movement-from" type="date" name="from" value="<?= e($filters['from']) ?>"><label class="visually-hidden" for="movement-to">Data final</label><input id="movement-to" type="date" name="to" value="<?= e($filters['to']) ?>"><button class="button button--secondary" type="submit">Filtrar</button><?php if (implode('', $filters) !== ''): ?><a class="clear-search" href="stock.php">Limpar filtros</a><?php endif; ?></form>
<?php if ($movements === []): ?><div class="empty-state"><p><?= $movementCount === 0 ? 'Ainda não existem movimentos de stock.' : 'Não foram encontrados movimentos para os filtros selecionados.' ?></p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th scope="col">DATA</th><th scope="col">PRODUTO</th><th scope="col">TIPO</th><th scope="col">ALTERAÇÃO</th><th scope="col">ANTES</th><th scope="col">DEPOIS</th><th scope="col">MOTIVO</th></tr></thead><tbody>
<?php foreach ($movements as $movement): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($movement['created_at']))) ?></td><td><?= e($movement['product_name']) ?></td><td><?= e(movementTypeLabel($movement['movement_type'])) ?></td><td><?= (int) $movement['quantity'] ?></td><td><?= (int) $movement['stock_before'] ?></td><td><?= (int) $movement['stock_after'] ?></td><td><?= e($movement['reason']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php if ($movementCount > $pageSize): ?><nav class="pagination" aria-label="Paginação de movimentos"><?php if ($page > 1): ?><?php $query = http_build_query(array_filter($filters + ['page' => $page - 1], static fn($value): bool => $value !== '')); ?><a class="button button--secondary" href="stock.php?<?= e($query) ?>" rel="prev">Anterior</a><?php endif; ?><span class="pagination-summary">Página <?= $page ?> de <?= $totalPages ?></span><?php if ($page < $totalPages): ?><?php $query = http_build_query(array_filter($filters + ['page' => $page + 1], static fn($value): bool => $value !== '')); ?><a class="button button--secondary" href="stock.php?<?= e($query) ?>" rel="next">Seguinte</a><?php endif; ?></nav><?php endif; ?><?php endif; ?></section>
<?php require $root . '/app/views/footer.php'; ?>
