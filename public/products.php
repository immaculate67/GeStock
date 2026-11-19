<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
requireBusinessModule($user, 'catalog');

$repository = null;
$pageError = null;
$mode = 'list';
$formValues = [
    'name' => '',
    'sku' => '',
    'description' => '',
    'price' => '',
    'cost_price' => '0.00',
    'quantity' => '0',
    'min_stock_level' => '0',
    'category_id' => '',
    'supplier_id' => '',
];
$errors = [];
$feedback = takeFlash();
$editingProduct = null;
$confirmProduct = null;
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (preg_match_all('/./us', $search) > 100) {
    $search = implode('', array_slice(preg_split('//u', $search, -1, PREG_SPLIT_NO_EMPTY), 0, 100));
}
$filters = [
    'category_id' => is_string($_GET['category_id'] ?? null) ? $_GET['category_id'] : '',
    'stock' => is_string($_GET['stock'] ?? null) ? $_GET['stock'] : '',
    'sort' => is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'name',
    'direction' => is_string($_GET['direction'] ?? null) ? $_GET['direction'] : 'asc',
];
$page = min(100000, validRecordId($_GET['page'] ?? null) ?? 1);
$pageSize = 20;

try {
    $businessId = (int) $user['business_id'];
    $repository = new ProductRepository(Database::connect(), $businessId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            $feedback = ['type' => 'error', 'message' => 'A sessão expirou. Atualize a página e tente novamente.'];
            $mode = $postedAction === 'save-product' ? 'form' : ($postedAction === 'delete-product' ? 'confirm-delete' : 'list');
        } elseif ($postedAction === 'save-product') {
            $rawId = $_POST['product_id'] ?? null;
            $productId = $rawId === null || $rawId === '' ? null : validRecordId($rawId);
            $mode = 'form';
            $formValues = [
                'name' => is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '',
                'sku' => is_string($_POST['sku'] ?? null) ? trim($_POST['sku']) : '',
                'description' => is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '',
                'price' => is_string($_POST['price'] ?? null) ? trim($_POST['price']) : '',
                'cost_price' => is_string($_POST['cost_price'] ?? null) ? trim($_POST['cost_price']) : '',
                'quantity' => is_string($_POST['quantity'] ?? null) ? trim($_POST['quantity']) : '',
                'min_stock_level' => is_string($_POST['min_stock_level'] ?? null) ? trim($_POST['min_stock_level']) : '',
                'category_id' => is_string($_POST['category_id'] ?? null) ? trim($_POST['category_id']) : '',
                'supplier_id' => is_string($_POST['supplier_id'] ?? null) ? trim($_POST['supplier_id']) : '',
            ];
            if ($rawId !== null && $rawId !== '' && $productId === null) {
                http_response_code(400);
                $errors['form'] = 'O identificador do produto não é válido. Volte à lista e tente novamente.';
            } else {
                $editingProduct = $productId === null ? null : $repository->find($productId);
                [$productData, $errors] = (new ProductValidator())->validate($formValues);
                if ($productData !== null && $repository->skuExists($productData['sku'], $productId)) {
                    $errors['sku'] = 'Este código já está em utilização. Introduza um código único.';
                }
                if ($productData !== null) {
                    $pdo = Database::connect();
                    foreach (['category_id' => 'categories', 'supplier_id' => 'suppliers'] as $field => $table) {
                        if ($productData[$field] !== null) {
                            $reference = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = :id AND business_id = :business_id');
                            $reference->execute(['id' => $productData[$field], 'business_id' => $businessId]);
                            if ($reference->fetchColumn() === false) {
                                $errors[$field] = 'Selecione uma opção existente.';
                            }
                        }
                    }
                }

                if ($productData !== null && $errors === []) {
                    $inventory = new InventoryService(Database::connect(), $businessId);
                    if ($productId === null) {
                        $inventory->createProduct($productData, (int) $user['id']);
                        setFlash('success', 'Produto adicionado com sucesso.');
                    } elseif ($inventory->updateProduct($productId, $productData, (int) $user['id'])) {
                        setFlash('success', 'Produto atualizado com sucesso.');
                    } else {
                        http_response_code(404);
                        $errors['form'] = 'O produto já não existe.';
                    }

                    if ($errors === []) {
                        header('Location: products.php', true, 303);
                        exit;
                    }
                }
            }
        } elseif ($postedAction === 'delete-product') {
            $mode = 'confirm-delete';
            $productId = validRecordId($_POST['product_id'] ?? null);
            if ($productId === null) {
                http_response_code(400);
                $feedback = ['type' => 'error', 'message' => 'O identificador do produto não é válido.'];
            } else {
                try {
                    if (!$repository->delete($productId)) {
                        http_response_code(404);
                        $feedback = ['type' => 'error', 'message' => 'O produto já não existe.'];
                    } else {
                        setFlash('success', 'Produto eliminado com sucesso.');
                        header('Location: products.php', true, 303);
                        exit;
                    }
                } catch (DomainException $exception) {
                    http_response_code(409);
                    $feedback = ['type' => 'error', 'message' => $exception->getMessage()];
                }
            }
            $confirmProduct = $productId === null ? null : $repository->find($productId);
        } else {
            http_response_code(400);
            $feedback = ['type' => 'error', 'message' => 'Esta ação não é suportada.'];
        }
    } else {
        $requestedAction = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        if ($requestedAction === 'create') {
            $mode = 'form';
        } elseif (in_array($requestedAction, ['edit', 'view', 'confirm-delete'], true)) {
            $productId = validRecordId($_GET['id'] ?? null);
            if ($productId === null) {
                http_response_code(400);
                $pageError = 'O identificador do produto não é válido. Volte à lista e tente novamente.';
            } else {
                $product = $repository->find($productId);
                if ($product === null) {
                    http_response_code(404);
                    $pageError = 'O produto não existe ou foi removido.';
                } elseif ($requestedAction === 'edit') {
                    $mode = 'form';
                    $editingProduct = $product;
                    $formValues = [
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'description' => $product['description'] ?? '',
                        'price' => sprintf('%d.%02d', intdiv((int) $product['price_cents'], 100), (int) $product['price_cents'] % 100),
                        'cost_price' => sprintf('%d.%02d', intdiv((int) $product['cost_price_cents'], 100), (int) $product['cost_price_cents'] % 100),
                        'quantity' => (string) $product['quantity'],
                        'min_stock_level' => (string) $product['min_stock_level'],
                        'category_id' => (string) ($product['category_id'] ?? ''),
                        'supplier_id' => (string) ($product['supplier_id'] ?? ''),
                    ];
                } elseif ($requestedAction === 'view') {
                    $mode = 'view';
                    $editingProduct = $product;
                } else {
                    $mode = 'confirm-delete';
                    $confirmProduct = $product;
                }
            }
        } elseif ($requestedAction !== '') {
            http_response_code(404);
            $pageError = 'Esta página de produto não está disponível.';
        }
    }
} catch (Throwable $exception) {
    error_log('GeStock products page could not complete the request: ' . $exception->getMessage());
    http_response_code(500);
    $pageError = 'Os dados dos produtos estão temporariamente indisponíveis. Verifique a base de dados privada e tente novamente.';
    $repository = null;
}

$productSummary = null;
$products = [];
$productCount = 0;
$totalPages = 1;
$categories = [];
$suppliers = [];
if ($repository !== null) {
    try {
        $productSummary = $repository->summary();
        $categories = $repository->categories();
        $supplierQuery = Database::connect()->prepare('SELECT id, name FROM suppliers WHERE business_id = :business_id ORDER BY name COLLATE NOCASE');
        $supplierQuery->execute(['business_id' => (int) $user['business_id']]);
        $suppliers = $supplierQuery->fetchAll();
        if ($mode === 'list') {
            $productCount = $repository->count($search, $filters);
            $totalPages = max(1, (int) ceil($productCount / $pageSize));
            $page = min($page, $totalPages);
            $products = $repository->all($search, $filters, $pageSize, ($page - 1) * $pageSize);
        }
    } catch (Throwable $exception) {
        error_log('GeStock products page could not read product data: ' . $exception->getMessage());
        http_response_code(500);
        $pageError = 'Os dados dos produtos estão temporariamente indisponíveis. Verifique a base de dados privada e tente novamente.';
        $repository = null;
        $productSummary = null;
    }
}

$activePage = 'products.php';
$pageTitle = 'Produtos';
$pageSubtitle = 'Gerir dados, preços e níveis de stock dos seus produtos.';
$pageAction = $mode === 'list'
    ? '<a class="button button--primary" href="products.php?action=create">Adicionar produto</a>'
    : '<a class="button button--secondary" href="products.php">Voltar aos produtos</a>';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>

            <?php if ($pageError !== null): ?>
                <div class="feedback feedback--error" role="alert"><?= e($pageError) ?></div>
            <?php endif; ?>

            <section class="summary-grid product-summary" aria-label="Resumo de stock">
                <article class="summary-item">
                    <span class="summary-label">Total de produtos</span>
                    <strong class="summary-value"><?= $productSummary === null ? '—' : (int) $productSummary['total_products'] ?></strong>
                    <span class="summary-detail">Registos guardados</span>
                </article>
                <article class="summary-item">
                    <span class="summary-label">Stock baixo</span>
                    <strong class="summary-value"><?= $productSummary === null ? '—' : (int) $productSummary['low_stock'] ?></strong>
                    <span class="summary-detail">Acima de zero, até ao mínimo</span>
                </article>
                <article class="summary-item">
                    <span class="summary-label">Sem stock</span>
                    <strong class="summary-value"><?= $productSummary === null ? '—' : (int) $productSummary['out_of_stock'] ?></strong>
                    <span class="summary-detail">Produtos sem unidades disponíveis</span>
                </article>
            </section>

            <?php if ($mode === 'view' && $editingProduct !== null): ?>
                <section class="panel detail-panel"><div class="panel-heading"><div><h2><?= e($editingProduct['name']) ?></h2><p><?= e($editingProduct['sku']) ?></p></div><a class="table-action" href="products.php?action=edit&amp;id=<?= (int) $editingProduct['id'] ?>">Editar</a></div>
                    <dl class="detail-grid">
                        <div><dt>Categoria</dt><dd><?= e($editingProduct['category_name'] ?? 'Sem categoria') ?></dd></div>
                        <div><dt>Fornecedor</dt><dd><?= e($editingProduct['supplier_name'] ?? 'Sem fornecedor') ?></dd></div>
                        <div><dt>Preço de custo</dt><dd><?= e(formatPrice((int) $editingProduct['cost_price_cents'])) ?></dd></div>
                        <div><dt>Preço de venda</dt><dd><?= e(formatPrice((int) $editingProduct['price_cents'])) ?></dd></div>
                        <div><dt>Stock atual</dt><dd><?= (int) $editingProduct['quantity'] ?></dd></div>
                        <div><dt>Stock mínimo</dt><dd><?= (int) $editingProduct['min_stock_level'] ?></dd></div>
                        <div class="detail-wide"><dt>Descrição</dt><dd><?= nl2br(e($editingProduct['description'] ?? '—')) ?></dd></div>
                    </dl>
                </section>
            <?php elseif ($mode === 'form'): ?>
                <section class="panel product-form-panel" aria-labelledby="form-title">
                    <div class="panel-heading">
                        <div>
                            <h2 id="form-title"><?= $editingProduct === null ? 'Adicionar produto' : 'Editar produto' ?></h2>
                            <p>Preencha os dados e a quantidade atual em inventário.</p>
                        </div>
                    </div>
                    <form class="product-form" method="post" action="products.php">
                        <input type="hidden" name="action" value="save-product">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <?php if ($editingProduct !== null): ?>
                            <input type="hidden" name="product_id" value="<?= (int) $editingProduct['id'] ?>">
                        <?php endif; ?>
                        <?php if (isset($errors['form'])): ?>
                            <div class="feedback feedback--error form-message" role="alert"><?= e($errors['form']) ?></div>
                        <?php endif; ?>

                        <div class="form-grid">
                            <div class="field">
                                <label for="name">Nome do produto <span aria-hidden="true">*</span></label>
                                <input id="name" name="name" type="text" maxlength="120" required value="<?= e($formValues['name']) ?>"<?= isset($errors['name']) ? ' aria-invalid="true" aria-describedby="name-error"' : '' ?>>
                                <?php if (isset($errors['name'])): ?><span class="field-error" id="name-error"><?= e($errors['name']) ?></span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="sku">Código / SKU <span aria-hidden="true">*</span></label>
                                <input id="sku" name="sku" type="text" maxlength="40" required autocomplete="off" value="<?= e($formValues['sku']) ?>"<?= isset($errors['sku']) ? ' aria-invalid="true" aria-describedby="sku-error"' : '' ?>>
                                <?php if (isset($errors['sku'])): ?><span class="field-error" id="sku-error"><?= e($errors['sku']) ?></span><?php else: ?><span class="field-help">Letras, números, pontos, sublinhados e hífenes.</span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="price">Preço de venda <span aria-hidden="true">*</span></label>
                                <div class="input-with-prefix"><span aria-hidden="true">€</span><input id="price" name="price" type="text" inputmode="decimal" maxlength="11" placeholder="0,00" required value="<?= e($formValues['price']) ?>"<?= isset($errors['price']) ? ' aria-invalid="true" aria-describedby="price-error"' : '' ?>></div>
                                <?php if (isset($errors['price'])): ?><span class="field-error" id="price-error"><?= e($errors['price']) ?></span><?php else: ?><span class="field-help">Até duas casas decimais.</span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="cost_price">Preço de custo</label>
                                <div class="input-with-prefix"><span aria-hidden="true">€</span><input id="cost_price" name="cost_price" type="text" inputmode="decimal" maxlength="11" placeholder="0,00" value="<?= e($formValues['cost_price']) ?>"<?= isset($errors['cost_price']) ? ' aria-invalid="true" aria-describedby="cost-price-error"' : '' ?>></div>
                                <?php if (isset($errors['cost_price'])): ?><span class="field-error" id="cost-price-error"><?= e($errors['cost_price']) ?></span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="category_id">Categoria</label>
                                <select id="category_id" name="category_id"><option value="">Sem categoria</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"<?= (string) $category['id'] === $formValues['category_id'] ? ' selected' : '' ?>><?= e(($category['parent_name'] ? $category['parent_name'] . ' / ' : '') . $category['name']) ?></option><?php endforeach; ?></select>
                            </div>
                            <div class="field">
                                <label for="supplier_id">Fornecedor</label>
                                <select id="supplier_id" name="supplier_id"><option value="">Sem fornecedor</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>"<?= (string) $supplier['id'] === $formValues['supplier_id'] ? ' selected' : '' ?>><?= e($supplier['name']) ?></option><?php endforeach; ?></select>
                            </div>
                            <div class="field">
                                <label for="quantity">Quantidade em stock <span aria-hidden="true">*</span></label>
                                <input id="quantity" name="quantity" type="number" min="0" max="2147483647" step="1" required value="<?= e($formValues['quantity']) ?>"<?= isset($errors['quantity']) ? ' aria-invalid="true" aria-describedby="quantity-error"' : '' ?>>
                                <?php if (isset($errors['quantity'])): ?><span class="field-error" id="quantity-error"><?= e($errors['quantity']) ?></span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="min_stock_level">Limite mínimo de stock <span aria-hidden="true">*</span></label>
                                <input id="min_stock_level" name="min_stock_level" type="number" min="0" max="2147483647" step="1" required value="<?= e($formValues['min_stock_level']) ?>"<?= isset($errors['min_stock_level']) ? ' aria-invalid="true" aria-describedby="minimum-error"' : '' ?>>
                                <?php if (isset($errors['min_stock_level'])): ?><span class="field-error" id="minimum-error"><?= e($errors['min_stock_level']) ?></span><?php endif; ?>
                            </div>
                            <div class="field field--wide">
                                <label for="description">Descrição <span class="field-optional">(opcional)</span></label>
                                <textarea id="description" name="description" maxlength="1000" rows="3"<?= isset($errors['description']) ? ' aria-invalid="true" aria-describedby="description-error"' : '' ?>><?= e($formValues['description']) ?></textarea>
                                <?php if (isset($errors['description'])): ?><span class="field-error" id="description-error"><?= e($errors['description']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="form-actions">
                            <a class="button button--secondary" href="products.php">Cancelar</a>
                            <button class="button button--primary" type="submit"><?= $editingProduct === null ? 'Guardar produto' : 'Guardar alterações' ?></button>
                        </div>
                    </form>
                </section>
            <?php elseif ($mode === 'confirm-delete'): ?>
                <section class="panel confirm-panel" aria-labelledby="confirm-title">
                    <div class="panel-heading">
                        <div>
                            <h2 id="confirm-title">Eliminar produto?</h2>
                            <p>Esta ação não pode ser desfeita.</p>
                        </div>
                    </div>
                    <div class="confirm-content">
                        <?php if ($confirmProduct !== null): ?>
                            <p>Eliminar <strong><?= e($confirmProduct['name']) ?></strong> (SKU <?= e($confirmProduct['sku']) ?>)?</p>
                            <form method="post" action="products.php" class="form-actions">
                                <input type="hidden" name="action" value="delete-product">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="product_id" value="<?= (int) $confirmProduct['id'] ?>">
                                <a class="button button--secondary" href="products.php">Cancelar</a>
                                <button class="button button--danger" type="submit">Eliminar produto</button>
                            </form>
                        <?php else: ?>
                            <p class="empty-state">O produto já não está disponível. Volte à lista para continuar.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif ($mode === 'list'): ?>
                <section class="panel products-panel" aria-labelledby="products-title">
                    <div class="panel-heading products-heading data-panel-heading">
                        <div>
                        <h2 id="products-title">Lista de produtos</h2>
                        <p><?= $productCount ?> <?= $productCount === 1 ? 'produto' : 'produtos' ?><?= $search !== '' ? ' encontrados' : ' no inventário' ?></p>
                        </div>
                    </div>
                    <form class="search-form filter-form" method="get" action="products.php" role="search">
                        <label class="visually-hidden" for="product-search">Pesquisar por nome ou código</label>
                        <input id="product-search" type="search" name="q" placeholder="Nome ou código" value="<?= e($search) ?>">
                        <select name="category_id" aria-label="Filtrar categoria"><option value="">Todas as categorias</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"<?= (string) $category['id'] === $filters['category_id'] ? ' selected' : '' ?>><?= e(($category['parent_name'] ? $category['parent_name'] . ' / ' : '') . $category['name']) ?></option><?php endforeach; ?></select>
                        <select name="stock" aria-label="Filtrar estado de stock"><option value="">Todos os estados</option><option value="low"<?= $filters['stock'] === 'low' ? ' selected' : '' ?>>Stock baixo</option><option value="out"<?= $filters['stock'] === 'out' ? ' selected' : '' ?>>Sem stock</option><option value="available"<?= $filters['stock'] === 'available' ? ' selected' : '' ?>>Disponível</option></select>
                        <select name="sort" aria-label="Ordenar por"><option value="name"<?= $filters['sort'] === 'name' ? ' selected' : '' ?>>Nome</option><option value="sku"<?= $filters['sort'] === 'sku' ? ' selected' : '' ?>>Código</option><option value="price"<?= $filters['sort'] === 'price' ? ' selected' : '' ?>>Preço</option><option value="quantity"<?= $filters['sort'] === 'quantity' ? ' selected' : '' ?>>Stock</option><option value="created"<?= $filters['sort'] === 'created' ? ' selected' : '' ?>>Data</option></select>
                        <select name="direction" aria-label="Sentido"><option value="asc">Ascendente</option><option value="desc"<?= $filters['direction'] === 'desc' ? ' selected' : '' ?>>Descendente</option></select>
                        <button class="button button--secondary" type="submit">Filtrar</button>
                        <?php if ($search !== '' || $filters['category_id'] !== '' || $filters['stock'] !== '' || $filters['sort'] !== 'name' || $filters['direction'] !== 'asc'): ?><a class="clear-search" href="products.php">Limpar filtros</a><?php endif; ?>
                        </form>
                    <?php if ($repository === null): ?>
                        <div class="empty-state"><p>A lista de produtos está temporariamente indisponível. Volte a tentar quando o acesso à base de dados for restabelecido.</p></div>
                    <?php elseif ($products === []): ?>
                        <div class="empty-state">
                            <?php if ($search !== ''): ?>
                                <p>Não existem produtos para estes filtros. Experimente outros critérios.</p>
                            <?php else: ?>
                                <p>Ainda não existem produtos. Adicione o primeiro para começar a controlar o stock.</p>
                                <a class="button button--primary" href="products.php?action=create">Adicionar primeiro produto</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="products-table">
                                <thead>
                                <tr>
                                    <th scope="col">PRODUTO</th>
                                    <th scope="col">CÓDIGO</th>
                                    <th scope="col">CATEGORIA</th>
                                    <th scope="col">CUSTO</th>
                                    <th scope="col">PREÇO</th>
                                    <th scope="col">STOCK</th>
                                    <th scope="col">MÍNIMO</th>
                                    <th scope="col">ESTADO</th>
                                    <th scope="col"><span class="visually-hidden">Ações</span></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($products as $product): ?>
                                    <?php
                                    $quantity = (int) $product['quantity'];
                                    $minimum = (int) $product['min_stock_level'];
                                    $status = match (true) {
                                        $quantity === 0 => 'Sem stock',
                                        $quantity <= $minimum => 'Stock baixo',
                                        default => 'Disponível',
                                    };
                                    ?>
                                    <tr>
                                        <td class="product-name-cell">
                                            <strong><?= e($product['name']) ?></strong>
                                            <?php if ($product['description'] !== null && $product['description'] !== ''): ?><span><?= e($product['description']) ?></span><?php endif; ?>
                                        </td>
                                        <td><?= e($product['sku']) ?></td>
                                        <td><?= e($product['category_name'] ?? '—') ?></td>
                                        <td class="amount-cell"><?= e(formatPrice((int) $product['cost_price_cents'])) ?></td>
                                        <td class="amount-cell"><?= e(formatPrice((int) $product['price_cents'])) ?></td>
                                        <td><?= $quantity ?></td>
                                        <td><?= $minimum ?></td>
                                        <td><span class="status-pill<?= $status === 'Disponível' ? ' status-pill--healthy' : ' status-pill--low' ?>"><?= e($status) ?></span></td>
                                        <td class="product-actions">
                                            <a class="table-action" href="products.php?action=view&amp;id=<?= (int) $product['id'] ?>">Ver<span class="visually-hidden"> <?= e($product['name']) ?></span></a>
                                            <a class="table-action" href="products.php?action=edit&amp;id=<?= (int) $product['id'] ?>">Editar<span class="visually-hidden"> <?= e($product['name']) ?></span></a>
                                            <a class="table-action table-action--danger" href="products.php?action=confirm-delete&amp;id=<?= (int) $product['id'] ?>">Eliminar<span class="visually-hidden"> <?= e($product['name']) ?></span></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($productCount > $pageSize): ?>
                            <nav class="pagination" aria-label="Paginação de produtos">
                                <?php if ($page > 1): ?>
                                    <?php $query = http_build_query(array_filter(['q' => $search, 'category_id' => $filters['category_id'], 'stock' => $filters['stock'], 'sort' => $filters['sort'], 'direction' => $filters['direction'], 'page' => $page - 1], static fn($value): bool => $value !== '')); ?>
                                    <a class="button button--secondary" href="products.php?<?= e($query) ?>" rel="prev">Anterior</a>
                                <?php endif; ?>
                                <span class="pagination-summary">Página <?= $page ?> de <?= $totalPages ?></span>
                                <?php if ($page < $totalPages): ?>
                                    <?php $query = http_build_query(array_filter(['q' => $search, 'category_id' => $filters['category_id'], 'stock' => $filters['stock'], 'sort' => $filters['sort'], 'direction' => $filters['direction'], 'page' => $page + 1], static fn($value): bool => $value !== '')); ?>
                                    <a class="button button--secondary" href="products.php?<?= e($query) ?>" rel="next">Seguinte</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

<?php require $root . '/app/views/footer.php'; ?>
