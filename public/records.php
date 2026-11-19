<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
$definitions = [
    'categories' => ['title' => 'Categorias', 'subtitle' => 'Organize os produtos por família.', 'fields' => ['name' => 'Nome', 'description' => 'Descrição']],
    'suppliers' => ['title' => 'Fornecedores', 'subtitle' => 'Contactos e dados dos seus fornecedores.', 'fields' => ['name' => 'Empresa / nome', 'contact_person' => 'Pessoa de contacto', 'email' => 'Email', 'phone' => 'Telefone', 'address' => 'Morada', 'tax_number' => 'NIF', 'notes' => 'Notas']],
    'clients' => ['title' => 'Clientes', 'subtitle' => 'Contactos dos clientes e empresas.', 'fields' => ['name' => 'Nome / empresa', 'email' => 'Email', 'phone' => 'Telefone', 'address' => 'Morada', 'tax_number' => 'NIF', 'notes' => 'Notas']],
];
$entityType = isset($definitions[$entityType]) ? $entityType : '';
if ($entityType === '') {
    http_response_code(404);
    exit('Página não encontrada.');
}
requireBusinessModule($user, match ($entityType) {
    'categories' => 'catalog',
    'suppliers' => 'suppliers',
    'clients' => 'customers',
});
$definition = $definitions[$entityType];
$repository = new BusinessRepository(Database::connect(), (int) $user['business_id']);
$requestedMode = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$mode = $requestedMode === 'delete' ? 'confirm-delete' : $requestedMode;
$id = validRecordId($_GET['id'] ?? null);
$record = $id === null ? null : $repository->find($entityType, $id);
$error = '';
$feedback = takeFlash();
$values = $record ?? array_fill_keys(array_keys($definition['fields']), '');
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$fieldMaxLengths = $entityType === 'categories'
    ? ['name' => 100, 'description' => 500]
    : ['name' => 160, 'contact_person' => 120, 'email' => 254, 'phone' => 40, 'address' => 1000, 'tax_number' => 50, 'description' => 500, 'notes' => 2000];
$pageSize = 25;
$page = min(100000, validRecordId($_GET['page'] ?? null) ?? 1);
$recordCount = 0;
$totalPages = 1;

if (in_array($mode, ['edit', 'view', 'confirm-delete'], true)) {
    if ($id === null || $record === null) {
        http_response_code($id === null ? 400 : 404);
        $mode = 'list';
        $error = 'O registo solicitado não existe.';
    }
} elseif (!in_array($mode, ['list', 'create'], true)) {
    http_response_code(404);
    $mode = 'list';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        try {
            $deleteId = validRecordId($_POST['id'] ?? null);
            if ($deleteId === null) {
                throw new InvalidArgumentException('O identificador do registo não é válido.');
            }
            $repository->delete($entityType, $deleteId);
            setFlash('success', 'Registo eliminado com sucesso.');
            header('Location: ' . $entityType . '.php', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception instanceof PDOException ? 'Não foi possível eliminar o registo porque existem referências associadas.' : $exception->getMessage();
            $mode = 'list';
        }
    } elseif (($_POST['action'] ?? '') === 'save') {
        $values = [];
        foreach (array_keys($definition['fields']) as $field) {
            $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
        }
        try {
            $postedId = ($_POST['id'] ?? '') === '' ? null : validRecordId($_POST['id'] ?? null);
            if (($_POST['id'] ?? '') !== '' && $postedId === null) {
                throw new InvalidArgumentException('O identificador do registo não é válido.');
            }
            $repository->save($entityType, $values, $postedId);
            setFlash('success', 'Registo guardado com sucesso.');
            header('Location: ' . $entityType . '.php', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception instanceof PDOException ? 'Não foi possível guardar. Confirme se não existe já um registo com estes dados.' : $exception->getMessage();
            $mode = $postedId === null ? 'create' : 'edit';
        }
    }
}
if ($mode === 'list') {
    $recordCount = $repository->count($entityType, $search);
    $totalPages = max(1, (int) ceil($recordCount / $pageSize));
    $page = min($page, $totalPages);
    $rows = $repository->all($entityType, $search, $pageSize, ($page - 1) * $pageSize);
} else {
    $rows = [];
}
$categoryOptions = $entityType === 'categories' ? $repository->all('categories', '', 1000) : [];
$resultStart = $recordCount === 0 ? 0 : (($page - 1) * $pageSize) + 1;
$resultEnd = min($page * $pageSize, $recordCount);
$resultLabel = $recordCount === 0
    ? ($search !== '' ? 'Nenhum resultado' : 'Nenhum registo')
    : 'A mostrar ' . $resultStart . '-' . $resultEnd . ' de ' . $recordCount . ($search !== '' ? ' resultados' : ' registos');
$activePage = $entityType . '.php';
$pageTitle = $definition['title'];
$pageSubtitle = $definition['subtitle'];
$pageAction = $mode === 'list'
    ? '<a class="button button--primary" href="' . e($activePage) . '?action=create">Adicionar</a>'
    : '<a class="button button--secondary" href="' . e($activePage) . '">Voltar à lista</a>';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($mode === 'create' || $mode === 'edit'): ?>
    <section class="panel product-form-panel"><div class="panel-heading"><div><h2><?= $mode === 'edit' ? 'Editar registo' : 'Novo registo' ?></h2></div></div>
        <form class="product-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($record['id'] ?? 0) ?: '' ?>">
            <div class="form-grid">
                <?php foreach ($definition['fields'] as $field => $label): ?>
                    <div class="field<?= in_array($field, ['address', 'notes', 'description'], true) ? ' field--wide' : '' ?>">
                        <label for="<?= e($field) ?>"><?= e($label) ?><?= $field === 'name' ? ' *' : '' ?></label>
                        <?php if ($entityType === 'categories' && $field === 'parent_id'): ?>
                            <select id="<?= e($field) ?>" name="<?= e($field) ?>">
                                <option value="">Categoria principal</option>
                                <?php foreach ($categoryOptions as $categoryOption): if ((int) ($record['id'] ?? 0) === (int) $categoryOption['id']) { continue; } ?>
                                    <option value="<?= (int) $categoryOption['id'] ?>"<?= (string) $categoryOption['id'] === (string) ($values[$field] ?? '') ? ' selected' : '' ?>><?= e(($categoryOption['parent_name'] ? $categoryOption['parent_name'] . ' / ' : '') . $categoryOption['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif (in_array($field, ['address', 'notes', 'description'], true)): ?>
                            <textarea id="<?= e($field) ?>" name="<?= e($field) ?>" rows="3" maxlength="<?= $fieldMaxLengths[$field] ?>"><?= e($values[$field] ?? '') ?></textarea>
                        <?php else: ?>
                            <input id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') ?>" maxlength="<?= $fieldMaxLengths[$field] ?>" value="<?= e($values[$field] ?? '') ?>"<?= $field === 'name' ? ' required' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-actions"><a class="button button--secondary" href="<?= e($activePage) ?>">Cancelar</a><button class="button button--primary" type="submit">Guardar</button></div>
        </form>
    </section>
<?php elseif ($mode === 'view' && $record !== null): ?>
    <section class="panel detail-panel"><div class="panel-heading"><h2><?= e($record['name']) ?></h2><a class="table-action" href="<?= e($activePage) ?>?action=edit&amp;id=<?= (int) $record['id'] ?>">Editar</a></div>
        <dl class="detail-grid"><?php foreach ($definition['fields'] as $field => $label): ?><div><dt><?= e($label) ?></dt><dd><?= nl2br(e($field === 'parent_id' ? ($record['parent_name'] ?? 'Categoria principal') : ($record[$field] ?? '—'))) ?></dd></div><?php endforeach; ?></dl>
    </section>
<?php elseif ($mode === 'confirm-delete' && $record !== null): ?>
    <section class="panel confirmation-panel" aria-labelledby="delete-title">
        <div class="panel-heading"><div><h2 id="delete-title">Eliminar registo?</h2><p>Esta ação não pode ser desfeita.</p></div></div>
        <div class="confirm-content">
            <p>Tem a certeza de que pretende eliminar <strong><?= e($record['name']) ?></strong>?</p>
            <?php if ($entityType === 'categories'): ?><p>Uma categoria associada a produtos não pode ser eliminada.</p><?php endif; ?>
            <form method="post" class="form-actions"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $record['id'] ?>"><a class="button button--secondary" href="<?= e($activePage) ?>">Cancelar</a><button class="button button--danger" type="submit">Eliminar registo</button></form>
        </div>
    </section>
<?php else: ?>
    <section class="panel products-panel">
        <div class="panel-heading products-heading data-panel-heading"><div><h2>Lista de <?= e(strtolower($definition['title'])) ?></h2><p><?= e($resultLabel) ?></p></div></div>
        <form class="search-form filter-form filter-form--compact" method="get" role="search"><label class="visually-hidden" for="record-search">Pesquisar <?= e(strtolower($definition['title'])) ?></label><input id="record-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Pesquisar"><button class="button button--secondary" type="submit">Pesquisar</button><?php if ($search !== ''): ?><a class="clear-search" href="<?= e($activePage) ?>">Limpar pesquisa</a><?php endif; ?></form>
        <?php if ($rows === []): ?><div class="empty-state"><p><?= $search !== '' ? 'Não foram encontrados registos para esta pesquisa.' : 'Ainda não existem registos para apresentar.' ?></p><a class="button button--primary" href="<?= e($activePage) ?>?action=create">Adicionar primeiro registo</a></div>
        <?php else: ?><div class="table-wrap"><table><thead><tr><th scope="col">Nome</th><?php if ($entityType !== 'categories'): ?><th scope="col">Email</th><th scope="col">Telefone</th><?php endif; ?><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>
            <?php foreach ($rows as $row): ?><tr><td class="product-name-cell"><strong><?= e($row['name']) ?></strong><?php if ($entityType === 'categories' && !empty($row['parent_name'])): ?><small class="category-parent-label"><?= e($row['parent_name']) ?></small><?php endif; ?></td><?php if ($entityType !== 'categories'): ?><td><?= e($row['email'] ?? '—') ?></td><td><?= e($row['phone'] ?? '—') ?></td><?php endif; ?><td class="product-actions">
                <a class="table-action" href="<?= e($activePage) ?>?action=view&amp;id=<?= (int) $row['id'] ?>">Ver<span class="visually-hidden"> <?= e($row['name']) ?></span></a>
                <a class="table-action" href="<?= e($activePage) ?>?action=edit&amp;id=<?= (int) $row['id'] ?>">Editar<span class="visually-hidden"> <?= e($row['name']) ?></span></a>
                <a class="table-action table-action--danger" href="<?= e($activePage) ?>?action=delete&amp;id=<?= (int) $row['id'] ?>">Eliminar<span class="visually-hidden"> <?= e($row['name']) ?></span></a>
            </td></tr><?php endforeach; ?>
        </tbody></table></div><?php if ($recordCount > $pageSize): ?><nav class="pagination" aria-label="Paginação de <?= e(strtolower($definition['title'])) ?>"><?php if ($page > 1): ?><?php $query = http_build_query(array_filter(['q' => $search, 'page' => $page - 1], static fn($value): bool => $value !== '')); ?><a class="button button--secondary" href="<?= e($activePage) ?>?<?= e($query) ?>" rel="prev">Anterior</a><?php endif; ?><span class="pagination-summary">Página <?= $page ?> de <?= $totalPages ?></span><?php if ($page < $totalPages): ?><?php $query = http_build_query(array_filter(['q' => $search, 'page' => $page + 1], static fn($value): bool => $value !== '')); ?><a class="button button--secondary" href="<?= e($activePage) ?>?<?= e($query) ?>" rel="next">Seguinte</a><?php endif; ?></nav><?php endif; ?><?php endif; ?>
    </section>
<?php endif; ?>
<?php require $root . '/app/views/footer.php'; ?>
