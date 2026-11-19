<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
requireBusinessModule($user, 'audit');
$pdo = Database::connect();
$audit = new AuditPermissionService($pdo);
$serviceRole = $user['role'];
$businessId = (int) $user['business_id'];
if (!$audit->can($businessId, (int) $user['id'], $serviceRole, 'audit.view')) {
    http_response_code(403);
    exit('Não tem permissão para consultar o histórico de auditoria.');
}
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$action = is_string($_GET['action'] ?? null) ? trim($_GET['action']) : null;
$events = $audit->auditSearch($businessId, $search, $action);
$activePage = 'audit.php';
$pageTitle = 'Auditoria';
$pageSubtitle = 'Histórico append-only das alterações da empresa.';
$pageFeedback = takeFlash();
require $root . '/app/views/header.php';
?>
<section class="panel products-panel">
    <div class="panel-heading"><div><h2>Eventos registados</h2><p><?= count($events) ?> <?= count($events) === 1 ? 'evento' : 'eventos' ?></p></div></div>
    <form class="filter-form" method="get">
        <div class="field"><label for="q">Pesquisar</label><input id="q" name="q" value="<?= e($search) ?>" placeholder="Entidade, ação ou valores"></div>
        <div class="field"><label for="action">Ação</label><input id="action" name="action" value="<?= e($action ?? '') ?>" placeholder="ex.: criado, recebido"></div>
        <button class="button button--secondary" type="submit">Pesquisar</button>
    </form>
    <?php if ($events === []): ?>
        <div class="empty-state"><p>Não existem eventos de auditoria correspondentes.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Data</th><th>Ator</th><th>Ação</th><th>Entidade</th><th>Alterações</th></tr></thead><tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?= e(substr((string) $event['created_at'], 0, 19)) ?></td>
                <td><?= e($event['actor_name'] ?? 'Sistema') ?></td>
                <td><?= e($event['action']) ?></td>
                <td><?= e($event['entity_type']) ?><?= $event['entity_id'] !== null ? ' #' . (int) $event['entity_id'] : '' ?></td>
                <td><small><?= e($event['before_json'] ? 'Antes: ' . $event['before_json'] : '') ?><?= e($event['before_json'] && $event['after_json'] ? ' · ' : '') ?><?= e($event['after_json'] ? 'Depois: ' . $event['after_json'] : '') ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php require $root . '/app/views/footer.php'; ?>
