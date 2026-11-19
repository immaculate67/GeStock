<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
requireBusinessModule($user, 'purchases');
$pdo = Database::connect();
$businessId = (int) $user['business_id'];
$auditPermissions = new AuditPermissionService($pdo);
$error = '';
$feedback = takeFlash();
$canCreate = $auditPermissions->can($businessId, (int) $user['id'], $user['role'], 'purchases.create');
$canReceive = $auditPermissions->can($businessId, (int) $user['id'], $user['role'], 'purchases.receive');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'create') {
        if (!$canCreate) {
            http_response_code(403);
            $error = 'Não tem permissão para criar encomendas.';
        } else {
            try {
                $service = new PurchaseService($pdo, $businessId);
                $orderId = $service->create([
                    'supplier_id' => $_POST['supplier_id'] ?? null,
                    'order_number' => $_POST['order_number'] ?? null,
                    'notes' => $_POST['notes'] ?? null,
                    'branch_id' => $_POST['branch_id'] ?? null,
                    'product_id' => $_POST['product_id'] ?? [],
                    'variant_id' => $_POST['variant_id'] ?? [],
                    'quantity' => $_POST['quantity'] ?? [],
                    'unit_cost' => $_POST['unit_cost'] ?? [],
                ], (int) $user['id']);
                (new AuditPermissionService($pdo))->record($businessId, (int) $user['id'], 'criada', 'encomenda', $orderId, null, ['estado' => 'ordered']);
                setFlash('success', 'Encomenda criada.');
                header('Location: purchases.php', true, 303);
                exit;
            } catch (InvalidArgumentException|DomainException|PDOException $exception) {
                $error = $exception instanceof PDOException ? 'Não foi possível guardar a encomenda.' : $exception->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'receive') {
        $orderId = validRecordId($_POST['order_id'] ?? null);
        if (!$canReceive || $orderId === null) {
            http_response_code(403);
            $error = 'Não tem permissão para receber esta encomenda.';
        } else {
            try {
                (new PurchaseService($pdo, $businessId))->receive($orderId, (int) $user['id']);
                (new AuditPermissionService($pdo))->record($businessId, (int) $user['id'], 'recebida', 'encomenda', $orderId);
                setFlash('success', 'Encomenda recebida e stock atualizado.');
                header('Location: purchases.php', true, 303);
                exit;
            } catch (InvalidArgumentException|DomainException|PDOException $exception) {
                $error = $exception instanceof PDOException ? 'Não foi possível receber a encomenda.' : $exception->getMessage();
            }
        }
    }
}
$purchase = new PurchaseService($pdo, $businessId);
$orders = $purchase->all(
    is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
    is_string($_GET['q'] ?? null) ? trim($_GET['q']) : ''
);
$suppliers = $pdo->prepare('SELECT id, name FROM suppliers WHERE business_id = :business_id ORDER BY name');
$suppliers->execute(['business_id' => $businessId]);
$supplierRows = $suppliers->fetchAll();
$products = $pdo->prepare("SELECT p.id, p.name, p.sku, pv.id AS variant_id, pv.identifier
                           FROM products p LEFT JOIN product_variants pv ON pv.product_id = p.id AND pv.business_id = p.business_id
                           WHERE p.business_id = :business_id AND p.item_type_id IN (
                               SELECT id FROM item_types WHERE business_id = :business_id AND enabled = 1
                           ) ORDER BY p.name, pv.identifier");
$products->execute(['business_id' => $businessId]);
$productRows = $products->fetchAll();
$activePage = 'purchases.php';
$pageTitle = 'Compras';
$pageSubtitle = 'Encomendas a fornecedores e receção de stock.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel products-panel">
    <div class="panel-heading"><div><h2>Encomendas</h2><p><?= count($orders) ?> <?= count($orders) === 1 ? 'encomenda' : 'encomendas' ?></p></div></div>
    <?php if ($orders === []): ?><div class="empty-state"><p>Ainda não existem encomendas.</p></div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Referência</th><th>Fornecedor</th><th>Estado</th><th>Total</th><th>Receção</th></tr></thead><tbody>
    <?php foreach ($orders as $order): ?>
        <tr><td><?= e($order['order_number']) ?></td><td><?= e($order['supplier_name']) ?></td><td><?= e(match ($order['status']) {'draft'=>'Rascunho','ordered'=>'Enviada','received'=>'Recebida','partially_received'=>'Receção parcial','cancelled'=>'Cancelada','closed'=>'Fechada',default=>$order['status']}) ?></td><td><?= e(formatPrice((int) $order['subtotal_cents'])) ?></td><td><?php if (in_array($order['status'], ['ordered','partially_received'], true) && $canReceive): ?><form method="post" class="inline-form" onsubmit="return confirm('Receber esta encomenda e atualizar o stock?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="receive"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><button class="table-action" type="submit">Receber</button></form><?php else: ?><?= e($order['received_at'] ? substr($order['received_at'], 0, 16) : '—') ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php if ($canCreate): ?>
<section class="panel product-form-panel">
    <div class="panel-heading"><div><h2>Nova encomenda</h2><p>A receção atualizará o stock e o custo histórico dos artigos.</p></div></div>
    <form class="product-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field"><label for="order_number">Referência *</label><input id="order_number" name="order_number" maxlength="80" required></div>
            <div class="field"><label for="supplier_id">Fornecedor *</label><select id="supplier_id" name="supplier_id" required><option value="">Selecionar</option><?php foreach ($supplierRows as $supplier): ?><option value="<?= (int) $supplier['id'] ?>"><?= e($supplier['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field field--wide"><label for="notes">Notas</label><textarea id="notes" name="notes" maxlength="2000"></textarea></div>
        </div>
        <div class="form-grid">
            <div class="field"><label for="product_id">Artigo *</label><select id="product_id" name="product_id[]" required><option value="">Selecionar</option><?php foreach ($productRows as $product): ?><option value="<?= (int) $product['id'] ?>" data-variant="<?= (int) ($product['variant_id'] ?? 0) ?>"><?= e($product['name']) ?><?= $product['variant_id'] ? ' · ' . e($product['identifier']) : '' ?></option><?php endforeach; ?></select></div>
            <input type="hidden" name="variant_id[]" value="">
            <div class="field"><label for="quantity">Quantidade *</label><input id="quantity" name="quantity[]" type="number" min="1" step="1" value="1" required></div>
            <div class="field"><label for="unit_cost">Custo unitário (€) *</label><input id="unit_cost" name="unit_cost[]" inputmode="decimal" value="0,00" required></div>
        </div>
        <div class="form-actions"><button class="button button--primary" type="submit">Criar encomenda</button></div>
    </form>
</section>
<?php endif; ?>
<?php require $root . '/app/views/footer.php'; ?>
