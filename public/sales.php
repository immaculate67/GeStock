<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
requireBusinessModule($user, 'sales');
$pdo = Database::connect();
$service = new InventoryService($pdo, (int) $user['business_id']);
$error = '';
$feedback = takeFlash();
$submittedClientId = is_string($_POST['client_id'] ?? null) ? $_POST['client_id'] : '';
$submittedSaleLines = [];
if (is_array($_POST['product_id'] ?? null)) {
    foreach ($_POST['product_id'] as $index => $productId) {
        $quantity = is_array($_POST['quantity'] ?? null) ? ($_POST['quantity'][$index] ?? '') : '';
        $variantId = is_array($_POST['variant_id'] ?? null) ? ($_POST['variant_id'][$index] ?? '') : '';
        $submittedSaleLines[] = [
            'product_id' => is_string($productId) ? $productId : '',
            'variant_id' => is_string($variantId) ? $variantId : '',
            'quantity' => is_string($quantity) ? $quantity : '',
        ];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'create') {
        try {
            $rawIds = is_array($_POST['product_id'] ?? null) ? $_POST['product_id'] : [];
            $rawQuantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
            $rawVariants = is_array($_POST['variant_id'] ?? null) ? $_POST['variant_id'] : [];
            $lines = [];
            foreach ($rawIds as $index => $productId) {
                $lines[] = [
                    'product_id' => $productId,
                    'variant_id' => $rawVariants[$index] ?? null,
                    'quantity' => $rawQuantities[$index] ?? null,
                ];
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
$totalPages = max(1, (int) ceil($salesCount / $pageSize));
$page = min($page, $totalPages);
$sales = $service->sales($search, $from, $to, $pageSize, $status, ($page - 1) * $pageSize);
$productsQuery = $pdo->prepare('SELECT id, name, sku, price_cents, quantity FROM products WHERE business_id = :business_id ORDER BY name COLLATE NOCASE');
$productsQuery->execute(['business_id' => (int) $user['business_id']]);
$products = $productsQuery->fetchAll();
$variantsQuery = $pdo->prepare(
    'SELECT id, product_id, identifier, sku, price_cents, quantity
     FROM product_variants
     WHERE business_id = :business_id AND active = 1 AND quantity > 0
     ORDER BY identifier COLLATE NOCASE'
);
$variantsQuery->execute(['business_id' => (int) $user['business_id']]);
$variantsByProduct = [];
foreach ($variantsQuery->fetchAll() as $variant) {
    $variantsByProduct[(int) $variant['product_id']][] = $variant;
}
$clientsQuery = $pdo->prepare('SELECT id, name FROM clients WHERE business_id = :business_id ORDER BY name COLLATE NOCASE');
$clientsQuery->execute(['business_id' => (int) $user['business_id']]);
$clients = $clientsQuery->fetchAll();
$productOptions = [];
foreach ($products as $product) {
    if ((int) $product['quantity'] > 0) {
        $productOptions[] = ['id' => (int) $product['id'], 'variant_id' => null, 'name' => $product['name'], 'sku' => $product['sku'], 'price' => formatPrice((int) $product['price_cents']), 'cents' => (int) $product['price_cents'], 'stock' => (int) $product['quantity'], 'label' => $product['name'] . ' · ' . $product['sku']];
    }
    foreach ($variantsByProduct[(int) $product['id']] ?? [] as $variant) {
        $variantPrice = $variant['price_cents'] === null ? (int) $product['price_cents'] : (int) $variant['price_cents'];
        $productOptions[] = [
            'id' => (int) $product['id'], 'variant_id' => (int) $variant['id'],
            'name' => $product['name'] . ' · ' . $variant['identifier'], 'sku' => $variant['sku'],
            'price' => formatPrice($variantPrice), 'cents' => $variantPrice, 'stock' => (int) $variant['quantity'],
            'label' => $product['name'] . ' · ' . $variant['identifier'] . ' · ' . $variant['sku'],
        ];
    }
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
    <?php if ($productOptions === []): ?><div class="empty-state"><p>Não há produtos com stock disponível para vender.</p><a class="button button--primary" href="products.php?action=create">Adicionar produto</a></div>
    <?php else: ?><form class="product-form sale-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="create">
        <div class="field"><label for="client_id">Cliente</label><select id="client_id" name="client_id"><option value="">Cliente ocasional</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"<?= (string) $client['id'] === $submittedClientId ? ' selected' : '' ?>><?= e($client['name']) ?></option><?php endforeach; ?></select></div>
        <?php $formLines = $submittedSaleLines !== [] ? $submittedSaleLines : [['product_id' => '', 'variant_id' => '', 'quantity' => '1']]; ?>
        <div class="sale-lines" data-products="<?= e(json_encode($productOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">
            <?php foreach ($formLines as $lineIndex => $formLine): ?>
                <?php $selectedProductAvailable = false; foreach ($productOptions as $option) { if ((string) $option['id'] === $formLine['product_id'] && (string) ($option['variant_id'] ?? '') === $formLine['variant_id']) { $selectedProductAvailable = true; break; } } ?>
                <div class="sale-line">
                    <div class="field"><label for="sale-product-<?= (int) $lineIndex ?>">Produto ou variante</label><select id="sale-product-<?= (int) $lineIndex ?>" name="product_choice[]" data-product-choice required><option value="">Selecionar produto</option><?php if ($formLine['product_id'] !== '' && !$selectedProductAvailable): ?><option value="<?= e($formLine['product_id']) ?>:<?= (int) ($formLine['variant_id'] ?: 0) ?>" selected disabled>Produto indisponível</option><?php endif; ?><?php foreach ($productOptions as $option): ?><option value="<?= (int) $option['id'] ?>:<?= (int) ($option['variant_id'] ?? 0) ?>"<?= (string) $option['id'] . ':' . (int) ($option['variant_id'] ?? 0) === (string) $formLine['product_id'] . ':' . (int) ($formLine['variant_id'] ?: 0) ? ' selected' : '' ?>><?= e($option['label'] . ' · ' . $option['stock'] . ' un. · ' . $option['price']) ?></option><?php endforeach; ?></select><input type="hidden" name="product_id[]" value="<?= e($formLine['product_id']) ?>" data-product-value><input type="hidden" name="variant_id[]" value="<?= e($formLine['variant_id']) ?>" data-variant-value></div>
                    <div class="field"><label for="sale-quantity-<?= (int) $lineIndex ?>">Quantidade</label><input id="sale-quantity-<?= (int) $lineIndex ?>" name="quantity[]" type="number" min="1" step="1" value="<?= e($formLine['quantity']) ?>" required></div>
                    <div class="sale-line-meta"><span data-unit-price>Preço unitário: —</span><strong data-line-total>Subtotal: —</strong></div>
                    <button class="button button--secondary remove-sale-line" type="button" aria-label="Remover este produto da venda" hidden>Remover</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="button button--secondary add-sale-line" type="button" hidden>Adicionar produto</button>
        <noscript><p class="field-help">Sem JavaScript, o registo permite um produto de cada vez; o total final é calculado pelo servidor.</p></noscript>
        <div class="sale-total">Total: <strong data-sale-total>Calculado ao registar</strong></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Registar venda</button></div>
    </form><?php endif; ?>
</section>
<section class="panel products-panel"><div class="panel-heading products-heading data-panel-heading"><div><h2>Histórico de vendas</h2><p><?= $salesCount ?> <?= $salesCount === 1 ? 'venda encontrada' : 'vendas encontradas' ?></p></div></div>
<form class="search-form filter-form filter-form--compact" method="get" role="search"><label class="visually-hidden" for="sale-search">Pesquisar por número de venda ou cliente</label><input id="sale-search" type="search" name="q" placeholder="N.º de venda ou cliente" value="<?= e($search) ?>"><label class="visually-hidden" for="sales-from">Data inicial</label><input id="sales-from" type="date" name="from" value="<?= e($from) ?>"><label class="visually-hidden" for="sales-to">Data final</label><input id="sales-to" type="date" name="to" value="<?= e($to) ?>"><select name="status" aria-label="Estado da venda"><option value="">Todos os estados</option><option value="completed"<?= $status === 'completed' ? ' selected' : '' ?>>Concluída</option><option value="cancelled"<?= $status === 'cancelled' ? ' selected' : '' ?>>Anulada</option></select><button class="button button--secondary" type="submit">Filtrar</button><?php if ($search !== '' || $from !== '' || $to !== '' || $status !== ''): ?><a class="clear-search" href="sales.php">Limpar filtros</a><?php endif; ?></form>
<?php if ($sales === []): ?><div class="empty-state"><p><?= $salesCount === 0 ? 'Ainda não existem vendas para apresentar.' : 'Não foram encontradas vendas para os filtros selecionados.' ?></p></div><?php else: ?><div class="table-wrap"><table class="sales-table"><thead><tr><th scope="col">N.º VENDA</th><th scope="col">CLIENTE</th><th scope="col">DATA</th><th scope="col">TOTAL</th><th scope="col">ESTADO</th></tr></thead><tbody>
<?php foreach ($sales as $sale): ?><tr><td><a class="table-action" href="sale.php?id=<?= (int) $sale['id'] ?>"><?= e($sale['sale_number']) ?></a></td><td><?= e($sale['client_name_snapshot']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($sale['created_at']))) ?></td><td class="amount-cell"><?= e(formatPrice((int) $sale['total_cents'])) ?></td><td><span class="status-pill<?= $sale['status'] === 'completed' ? ' status-pill--healthy' : ' status-pill--cancelled' ?>"><?= $sale['status'] === 'completed' ? 'Concluída' : 'Anulada' ?></span></td></tr><?php endforeach; ?>
</tbody></table></div><?php if ($salesCount > $pageSize): ?><nav class="pagination" aria-label="Paginação de vendas"><?php if ($page > 1): ?><?php $query = http_build_query(array_filter(['q' => $search, 'from' => $from, 'to' => $to, 'status' => $status, 'page' => $page - 1], static fn($value): bool => $value !== '')); ?><a class="button button--secondary" href="sales.php?<?= e($query) ?>" rel="prev">Anterior</a><?php endif; ?><span class="pagination-summary">Página <?= $page ?> de <?= $totalPages ?></span><?php if ($page < $totalPages): ?><?php $query = http_build_query(array_filter(['q' => $search, 'from' => $from, 'to' => $to, 'status' => $status, 'page' => $page + 1], static fn($value): bool => $value !== '')); ?><a class="button button--secondary" href="sales.php?<?= e($query) ?>" rel="next">Seguinte</a><?php endif; ?></nav><?php endif; ?><?php endif; ?></section>
<?php require $root . '/app/views/footer.php'; ?>
