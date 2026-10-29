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
    } elseif (($_POST['action'] ?? '') === 'create') {
        try {
            $rawIds = is_array($_POST['product_id'] ?? null) ? $_POST['product_id'] : [];
            $rawQuantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
            $lines = [];
            foreach ($rawIds as $index => $productId) {
                $lines[] = ['product_id' => $productId, 'quantity' => $rawQuantities[$index] ?? null];
            }
            $clientId = ($_POST['client_id'] ?? '') === '' ? null : validRecordId($_POST['client_id']);
            if (($_POST['client_id'] ?? '') !== '' && $clientId === null) {
                throw new InvalidArgumentException('Selecione um cliente válido.');
            }
            $saleId = $service->createSale($clientId, $lines, (int) $user['id']);
            setFlash('success', 'Venda registada com sucesso.');
            header('Location: sale.php?id=' . $saleId, true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$from = validDateInput($_GET['from'] ?? null) ? $_GET['from'] : '';
$to = validDateInput($_GET['to'] ?? null) ? $_GET['to'] : '';
$status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['completed', 'cancelled'], true) ? $_GET['status'] : '';
$page = min(100000, validRecordId($_GET['page'] ?? null) ?? 1);
$pageSize = 25;
$salesCount = $service->salesCount($search, $from, $to, $status);
$sales = $service->sales($search, $from, $to, $pageSize, $status, ($page - 1) * $pageSize);
$products = $pdo->query('SELECT id, name, sku, price_cents, quantity FROM products WHERE quantity > 0 ORDER BY name COLLATE NOCASE')->fetchAll();
$clients = $pdo->query('SELECT id, name FROM clients ORDER BY name COLLATE NOCASE')->fetchAll();
$productOptions = [];
foreach ($products as $product) {
    $productOptions[] = ['id' => (int) $product['id'], 'name' => $product['name'], 'sku' => $product['sku'], 'price' => formatPrice((int) $product['price_cents']), 'cents' => (int) $product['price_cents'], 'stock' => (int) $product['quantity']];
}
$activePage = 'sales.php';
$pageTitle = 'Vendas';
$pageSubtitle = 'Registe vendas, consulte o histórico e acompanhe os totais.';
$pageAction = '<a class="button button--primary" href="#new-sale">Nova venda</a>';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel product-form-panel" id="new-sale"><div class="panel-heading"><div><h2>Nova venda</h2><p>O total e o stock são calculados e verificados no servidor.</p></div></div>
    <?php if ($products === []): ?><div class="empty-state"><p>Não há produtos com stock disponível para vender.</p><a class="button button--primary" href="products.php?action=create">Adicionar produto</a></div>
    <?php else: ?><form class="product-form sale-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="create">
        <div class="field"><label for="client_id">Cliente</label><select id="client_id" name="client_id"><option value="">Cliente ocasional</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option><?php endforeach; ?></select></div>
        <div class="sale-lines" data-products="<?= e(json_encode($productOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"></div>
        <button class="button button--secondary add-sale-line" type="button">Adicionar produto</button>
        <div class="sale-total">Total: <strong data-sale-total>0,00 €</strong></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Registar venda</button></div>
    </form><?php endif; ?>
</section>
<section class="panel products-panel"><div class="panel-heading products-heading"><div><h2>Histórico de vendas</h2><p><?= count($sales) ?> registos encontrados</p></div>
<form class="search-form" method="get"><input type="search" name="q" placeholder="N.º de venda ou cliente" value="<?= e($search) ?>"><input aria-label="Data inicial" type="date" name="from" value="<?= e($from) ?>"><input aria-label="Data final" type="date" name="to" value="<?= e($to) ?>"><select name="status" aria-label="Estado da venda"><option value="">Todos os estados</option><option value="completed"<?= $status === 'completed' ? ' selected' : '' ?>>Concluída</option><option value="cancelled"<?= $status === 'cancelled' ? ' selected' : '' ?>>Anulada</option></select><button class="button button--secondary" type="submit">Filtrar</button></form></div>
<?php if ($sales === []): ?><div class="empty-state"><p>Ainda não existem vendas neste período.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>N.º VENDA</th><th>CLIENTE</th><th>DATA</th><th>TOTAL</th><th>ESTADO</th></tr></thead><tbody>
<?php foreach ($sales as $sale): ?><tr><td><a class="table-action" href="sale.php?id=<?= (int) $sale['id'] ?>"><?= e($sale['sale_number']) ?></a></td><td><?= e($sale['client_name_snapshot']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($sale['created_at']))) ?></td><td class="amount-cell"><?= e(formatPrice((int) $sale['total_cents'])) ?></td><td><?= $sale['status'] === 'completed' ? 'Concluída' : 'Anulada' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php if ($salesCount > $pageSize): ?><nav class="pagination" aria-label="Paginação"><?php for ($pageNumber = 1; $pageNumber <= (int) ceil($salesCount / $pageSize); $pageNumber++): ?><?php $query = http_build_query(array_filter(['q' => $search, 'from' => $from, 'to' => $to, 'status' => $status, 'page' => $pageNumber], static fn($value): bool => $value !== '')); ?><a class="button <?= $page === $pageNumber ? 'button--primary' : 'button--secondary' ?>" href="sales.php?<?= e($query) ?>"><?= $pageNumber ?></a><?php endfor; ?></nav><?php endif; ?><?php endif; ?></section>
<?php require $root . '/app/views/footer.php'; ?>
