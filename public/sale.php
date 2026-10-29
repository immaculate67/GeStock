<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
$service = new InventoryService(Database::connect());
$id = validRecordId($_GET['id'] ?? null);
$sale = $id === null ? null : $service->sale($id);
if ($sale === null) {
    http_response_code($id === null ? 400 : 404);
    exit('Venda não encontrada.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null) || ($_POST['action'] ?? '') !== 'cancel') {
        http_response_code(400);
        $error = 'Pedido inválido ou sessão expirada.';
    } else {
        try {
            $service->cancelSale($id, (int) $user['id']);
            setFlash('success', 'Venda anulada e stock reposto. O movimento ficou registado.');
            header('Location: sale.php?id=' . $id, true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}
$activePage = 'sales.php';
$pageTitle = 'Venda ' . $sale['sale_number'];
$pageSubtitle = e($sale['client_name_snapshot']) . ' · ' . date('d/m/Y H:i', strtotime($sale['created_at']));
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (($flash = takeFlash()) !== null): ?><div class="feedback feedback--success" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
<section class="panel products-panel"><div class="panel-heading"><div><h2>Artigos vendidos</h2><p>Preços e quantidades preservados no momento da venda.</p></div><span class="status-pill"><?= $sale['status'] === 'completed' ? 'Concluída' : 'Anulada' ?></span></div>
<div class="table-wrap"><table><thead><tr><th>PRODUTO</th><th>SKU</th><th>QUANTIDADE</th><th>PREÇO UNITÁRIO</th><th>SUBTOTAL</th></tr></thead><tbody>
<?php foreach ($sale['items'] as $item): ?><tr><td><?= e($item['product_name_snapshot']) ?></td><td><?= e($item['sku_snapshot']) ?></td><td><?= (int) $item['quantity'] ?></td><td><?= e(formatPrice((int) $item['unit_price_cents'])) ?></td><td class="amount-cell"><?= e(formatPrice((int) $item['line_total_cents'])) ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th colspan="4">Total</th><th><?= e(formatPrice((int) $sale['total_cents'])) ?></th></tr></tfoot></table></div>
<?php if ($sale['status'] === 'completed'): ?><form class="form-actions cancel-sale-form" method="post" onsubmit="return confirm('Anular esta venda e repor o stock?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="cancel"><button class="button button--danger" type="submit">Anular venda</button></form><?php endif; ?>
</section>
<?php require $root . '/app/views/footer.php'; ?>
