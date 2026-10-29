<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$repository = null;
$pageError = null;
$mode = 'list';
$formValues = [
    'name' => '',
    'sku' => '',
    'description' => '',
    'price' => '',
    'quantity' => '0',
    'min_stock_level' => '0',
];
$errors = [];
$feedback = takeFlash();
$editingProduct = null;
$confirmProduct = null;
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (preg_match_all('/./us', $search) > 100) {
    $search = implode('', array_slice(preg_split('//u', $search, -1, PREG_SPLIT_NO_EMPTY), 0, 100));
}

try {
    $repository = new ProductRepository(Database::connect());

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            $feedback = ['type' => 'error', 'message' => 'Your form session expired. Reload the page and try again.'];
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
                'quantity' => is_string($_POST['quantity'] ?? null) ? trim($_POST['quantity']) : '',
                'min_stock_level' => is_string($_POST['min_stock_level'] ?? null) ? trim($_POST['min_stock_level']) : '',
            ];
            if ($rawId !== null && $rawId !== '' && $productId === null) {
                http_response_code(400);
                $errors['form'] = 'The product ID is invalid. Return to the list and select a product again.';
            } else {
                [$productData, $errors] = (new ProductValidator())->validate($formValues);
                if ($productData !== null && $repository->skuExists($productData['sku'], $productId)) {
                    $errors['sku'] = 'That SKU is already in use. Enter a unique SKU.';
                }

                if ($productData !== null && $errors === []) {
                    if ($productId === null) {
                        $repository->create($productData);
                        setFlash('success', 'Product added successfully.');
                    } elseif ($repository->update($productId, $productData)) {
                        setFlash('success', 'Product updated successfully.');
                    } else {
                        http_response_code(404);
                        $errors['form'] = 'That product could not be found. It may have been removed.';
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
                $feedback = ['type' => 'error', 'message' => 'The product ID is invalid.'];
            } elseif (!$repository->delete($productId)) {
                http_response_code(404);
                $feedback = ['type' => 'error', 'message' => 'That product could not be found. It may have been removed.'];
            } else {
                setFlash('success', 'Product deleted successfully.');
                header('Location: products.php', true, 303);
                exit;
            }
            $confirmProduct = $productId === null ? null : $repository->find($productId);
        } else {
            http_response_code(400);
            $feedback = ['type' => 'error', 'message' => 'This form action is not supported.'];
        }
    } else {
        $requestedAction = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        if ($requestedAction === 'create') {
            $mode = 'form';
        } elseif ($requestedAction === 'edit' || $requestedAction === 'confirm-delete') {
            $productId = validRecordId($_GET['id'] ?? null);
            if ($productId === null) {
                http_response_code(400);
                $pageError = 'The product ID is invalid. Return to the product list and try again.';
            } else {
                $product = $repository->find($productId);
                if ($product === null) {
                    http_response_code(404);
                    $pageError = 'That product could not be found. It may have been removed.';
                } elseif ($requestedAction === 'edit') {
                    $mode = 'form';
                    $editingProduct = $product;
                    $formValues = [
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'description' => $product['description'] ?? '',
                        'price' => sprintf('%d.%02d', intdiv((int) $product['price_cents'], 100), (int) $product['price_cents'] % 100),
                        'quantity' => (string) $product['quantity'],
                        'min_stock_level' => (string) $product['min_stock_level'],
                    ];
                } else {
                    $mode = 'confirm-delete';
                    $confirmProduct = $product;
                }
            }
        } elseif ($requestedAction !== '') {
            http_response_code(404);
            $pageError = 'That product page is not available.';
        }
    }
} catch (Throwable $exception) {
    error_log('GeStock products page could not complete the request: ' . $exception->getMessage());
    http_response_code(500);
    $pageError = 'Product data is temporarily unavailable. Please check the private database storage and try again.';
    $repository = null;
}

$productSummary = null;
$products = [];
if ($repository !== null) {
    try {
        $productSummary = $repository->summary();
        if ($mode === 'list') {
            $products = $repository->all($search);
        }
    } catch (Throwable $exception) {
        error_log('GeStock products page could not read product data: ' . $exception->getMessage());
        http_response_code(500);
        $pageError = 'Product data is temporarily unavailable. Please check the private database storage and try again.';
        $repository = null;
        $productSummary = null;
    }
}

$activePage = 'products.php';
$formAction = $editingProduct === null ? 'Add product' : 'Edit product';
$flashType = $feedback['type'] ?? 'success';
$flashMessage = $feedback['message'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage product details and stock levels in GeStock.">
    <title>Products | GeStock</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
<div class="app-shell">
    <?php require $root . '/app/views/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <button class="menu-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
                <span></span><span></span><span></span>
            </button>
            <div class="breadcrumb"><span>Workspace</span><span class="breadcrumb-divider">/</span><strong>Products</strong></div>
            <div class="topbar-right">
                <span class="sample-label"><span class="sample-dot"></span>Live stock</span>
                <span class="topbar-date"><?= e(date('l, F j, Y')) ?></span>
                <div class="user-avatar" aria-label="User: Alex Morgan">AM</div>
            </div>
        </header>

        <main class="content">
            <section class="page-heading" aria-labelledby="page-title">
                <div>
                    <p class="eyebrow">INVENTORY</p>
                    <h1 id="page-title">Products</h1>
                    <p class="page-subtitle">Manage your product details and keep stock levels up to date.</p>
                </div>
                <?php if ($mode === 'list'): ?>
                    <a class="button button--primary" href="products.php?action=create">Add product</a>
                <?php else: ?>
                    <a class="button button--secondary" href="products.php">Back to products</a>
                <?php endif; ?>
            </section>

            <?php if ($pageError !== null): ?>
                <div class="feedback feedback--error" role="alert"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php if ($flashMessage !== ''): ?>
                <div class="feedback feedback--<?= $flashType === 'success' ? 'success' : 'error' ?>" role="<?= $flashType === 'success' ? 'status' : 'alert' ?>"><?= e($flashMessage) ?></div>
            <?php endif; ?>

            <section class="summary-grid product-summary" aria-label="Product stock summary">
                <article class="summary-item">
                    <span class="summary-label">Total products</span>
                    <strong class="summary-value"><?= $productSummary === null ? '—' : (int) $productSummary['total_products'] ?></strong>
                    <span class="summary-detail">Saved product records</span>
                </article>
                <article class="summary-item">
                    <span class="summary-label">Low stock</span>
                    <strong class="summary-value"><?= $productSummary === null ? '—' : (int) $productSummary['low_stock'] ?></strong>
                    <span class="summary-detail">Above zero, at or below minimum</span>
                </article>
                <article class="summary-item">
                    <span class="summary-label">Out of stock</span>
                    <strong class="summary-value"><?= $productSummary === null ? '—' : (int) $productSummary['out_of_stock'] ?></strong>
                    <span class="summary-detail">Products with zero quantity</span>
                </article>
            </section>

            <?php if ($mode === 'form'): ?>
                <section class="panel product-form-panel" aria-labelledby="form-title">
                    <div class="panel-heading">
                        <div>
                            <h2 id="form-title"><?= e($formAction) ?></h2>
                            <p>Enter the product details and current stock quantity.</p>
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
                                <label for="name">Product name <span aria-hidden="true">*</span></label>
                                <input id="name" name="name" type="text" maxlength="120" required value="<?= e($formValues['name']) ?>"<?= isset($errors['name']) ? ' aria-invalid="true" aria-describedby="name-error"' : '' ?>>
                                <?php if (isset($errors['name'])): ?><span class="field-error" id="name-error"><?= e($errors['name']) ?></span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="sku">SKU <span aria-hidden="true">*</span></label>
                                <input id="sku" name="sku" type="text" maxlength="40" required autocomplete="off" value="<?= e($formValues['sku']) ?>"<?= isset($errors['sku']) ? ' aria-invalid="true" aria-describedby="sku-error"' : '' ?>>
                                <?php if (isset($errors['sku'])): ?><span class="field-error" id="sku-error"><?= e($errors['sku']) ?></span><?php else: ?><span class="field-help">Letters, numbers, dots, underscores and hyphens.</span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="price">Price <span aria-hidden="true">*</span></label>
                                <div class="input-with-prefix"><span aria-hidden="true">$</span><input id="price" name="price" type="text" inputmode="decimal" maxlength="11" placeholder="0.00" required value="<?= e($formValues['price']) ?>"<?= isset($errors['price']) ? ' aria-invalid="true" aria-describedby="price-error"' : '' ?>></div>
                                <?php if (isset($errors['price'])): ?><span class="field-error" id="price-error"><?= e($errors['price']) ?></span><?php else: ?><span class="field-help">Use up to two decimal places.</span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="quantity">Quantity <span aria-hidden="true">*</span></label>
                                <input id="quantity" name="quantity" type="number" min="0" max="2147483647" step="1" required value="<?= e($formValues['quantity']) ?>"<?= isset($errors['quantity']) ? ' aria-invalid="true" aria-describedby="quantity-error"' : '' ?>>
                                <?php if (isset($errors['quantity'])): ?><span class="field-error" id="quantity-error"><?= e($errors['quantity']) ?></span><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="min_stock_level">Minimum stock level <span aria-hidden="true">*</span></label>
                                <input id="min_stock_level" name="min_stock_level" type="number" min="0" max="2147483647" step="1" required value="<?= e($formValues['min_stock_level']) ?>"<?= isset($errors['min_stock_level']) ? ' aria-invalid="true" aria-describedby="minimum-error"' : '' ?>>
                                <?php if (isset($errors['min_stock_level'])): ?><span class="field-error" id="minimum-error"><?= e($errors['min_stock_level']) ?></span><?php endif; ?>
                            </div>
                            <div class="field field--wide">
                                <label for="description">Description <span class="field-optional">(optional)</span></label>
                                <textarea id="description" name="description" maxlength="1000" rows="3"<?= isset($errors['description']) ? ' aria-invalid="true" aria-describedby="description-error"' : '' ?>><?= e($formValues['description']) ?></textarea>
                                <?php if (isset($errors['description'])): ?><span class="field-error" id="description-error"><?= e($errors['description']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="form-actions">
                            <a class="button button--secondary" href="products.php">Cancel</a>
                            <button class="button button--primary" type="submit"><?= $editingProduct === null ? 'Save product' : 'Save changes' ?></button>
                        </div>
                    </form>
                </section>
            <?php elseif ($mode === 'confirm-delete'): ?>
                <section class="panel confirm-panel" aria-labelledby="confirm-title">
                    <div class="panel-heading">
                        <div>
                            <h2 id="confirm-title">Delete product?</h2>
                            <p>This action cannot be undone.</p>
                        </div>
                    </div>
                    <div class="confirm-content">
                        <?php if ($confirmProduct !== null): ?>
                            <p>Delete <strong><?= e($confirmProduct['name']) ?></strong> (SKU <?= e($confirmProduct['sku']) ?>)?</p>
                            <form method="post" action="products.php" class="form-actions">
                                <input type="hidden" name="action" value="delete-product">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="product_id" value="<?= (int) $confirmProduct['id'] ?>">
                                <a class="button button--secondary" href="products.php">Cancel</a>
                                <button class="button button--danger" type="submit">Delete product</button>
                            </form>
                        <?php else: ?>
                            <p class="empty-state">The product is unavailable. Return to the list to continue.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif ($mode === 'list'): ?>
                <section class="panel products-panel" aria-labelledby="products-title">
                    <div class="panel-heading products-heading">
                        <div>
                            <h2 id="products-title">Product list</h2>
                            <p><?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?><?= $search !== '' ? ' matching your search' : ' in your inventory' ?></p>
                        </div>
                        <form class="search-form" method="get" action="products.php" role="search">
                            <label class="visually-hidden" for="product-search">Search by product name or SKU</label>
                            <input id="product-search" type="search" name="q" placeholder="Search name or SKU" value="<?= e($search) ?>">
                            <button class="button button--secondary" type="submit">Search</button>
                            <?php if ($search !== ''): ?><a class="clear-search" href="products.php">Clear</a><?php endif; ?>
                        </form>
                    </div>
                    <?php if ($repository === null): ?>
                        <div class="empty-state"><p>The product list is unavailable right now. Use the error message above and try again when database access is restored.</p></div>
                    <?php elseif ($products === []): ?>
                        <div class="empty-state">
                            <?php if ($search !== ''): ?>
                                <p>No products match “<?= e($search) ?>”. Try another name or SKU.</p>
                            <?php else: ?>
                                <p>No products have been added yet. Add your first product to begin tracking stock.</p>
                                <a class="button button--primary" href="products.php?action=create">Add your first product</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="products-table">
                                <thead>
                                <tr>
                                    <th scope="col">PRODUCT</th>
                                    <th scope="col">SKU</th>
                                    <th scope="col">PRICE</th>
                                    <th scope="col">QUANTITY</th>
                                    <th scope="col">MINIMUM</th>
                                    <th scope="col">STATUS</th>
                                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($products as $product): ?>
                                    <?php
                                    $quantity = (int) $product['quantity'];
                                    $minimum = (int) $product['min_stock_level'];
                                    $status = match (true) {
                                        $quantity === 0 => 'Out of stock',
                                        $quantity <= $minimum => 'Low stock',
                                        default => 'In stock',
                                    };
                                    ?>
                                    <tr>
                                        <td class="product-name-cell">
                                            <strong><?= e($product['name']) ?></strong>
                                            <?php if ($product['description'] !== null && $product['description'] !== ''): ?><span><?= e($product['description']) ?></span><?php endif; ?>
                                        </td>
                                        <td><?= e($product['sku']) ?></td>
                                        <td class="amount-cell"><?= e(formatPrice((int) $product['price_cents'])) ?></td>
                                        <td><?= $quantity ?></td>
                                        <td><?= $minimum ?></td>
                                        <td><span class="status-pill<?= $status === 'In stock' ? ' status-pill--healthy' : ' status-pill--low' ?>"><?= e($status) ?></span></td>
                                        <td class="product-actions">
                                            <a class="table-action" href="products.php?action=edit&amp;id=<?= (int) $product['id'] ?>">Edit<span class="visually-hidden"> <?= e($product['name']) ?></span></a>
                                            <a class="table-action table-action--danger" href="products.php?action=confirm-delete&amp;id=<?= (int) $product['id'] ?>">Delete<span class="visually-hidden"> <?= e($product['name']) ?></span></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <footer class="page-footer">GeStock <span>·</span> A clear view of your business.</footer>
        </main>
    </div>
</div>
</body>
</html>
