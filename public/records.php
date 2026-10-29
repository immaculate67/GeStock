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
$definition = $definitions[$entityType];
$repository = new BusinessRepository(Database::connect());
$mode = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$id = validRecordId($_GET['id'] ?? null);
$record = $id === null ? null : $repository->find($entityType, $id);
$error = '';
$feedback = takeFlash();
$values = $record ?? array_fill_keys(array_keys($definition['fields']), '');
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

if ($mode === 'edit' || $mode === 'view' || $mode === 'delete') {
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
            $mode = 'create';
        }
    }
}
$rows = $mode === 'list' ? $repository->all($entityType, $search) : [];
$activePage = $entityType . '.php';
$pageTitle = $definition['title'];
$pageSubtitle = $definition['subtitle'];
$pageAction = '<a class="button button--primary" href="' . e($activePage) . '?action=create">Adicionar</a>';
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
                        <?php if (in_array($field, ['address', 'notes', 'description'], true)): ?>
                            <textarea id="<?= e($field) ?>" name="<?= e($field) ?>" rows="3"><?= e($values[$field] ?? '') ?></textarea>
                        <?php else: ?>
                            <input id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= $field === 'email' ? 'email' : 'text' ?>" maxlength="<?= $field === 'name' ? 160 : 254 ?>" value="<?= e($values[$field] ?? '') ?>"<?= $field === 'name' ? ' required' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-actions"><a class="button button--secondary" href="<?= e($activePage) ?>">Cancelar</a><button class="button button--primary" type="submit">Guardar</button></div>
        </form>
    </section>
<?php elseif ($mode === 'view' && $record !== null): ?>
    <section class="panel detail-panel"><div class="panel-heading"><h2><?= e($record['name']) ?></h2><a class="table-action" href="<?= e($activePage) ?>?action=edit&amp;id=<?= (int) $record['id'] ?>">Editar</a></div>
        <dl class="detail-grid"><?php foreach ($definition['fields'] as $field => $label): ?><div><dt><?= e($label) ?></dt><dd><?= nl2br(e($record[$field] ?? '—')) ?></dd></div><?php endforeach; ?></dl>
    </section>
<?php else: ?>
    <section class="panel products-panel">
        <div class="panel-heading products-heading"><div><h2>Lista de <?= e(strtolower($definition['title'])) ?></h2><p><?= count($rows) ?> registos</p></div>
            <form class="search-form" method="get"><label class="visually-hidden" for="record-search">Pesquisar</label><input id="record-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Pesquisar"><button class="button button--secondary" type="submit">Pesquisar</button></form>
        </div>
        <?php if ($rows === []): ?><div class="empty-state"><p>Não existem registos para apresentar.</p><a class="button button--primary" href="<?= e($activePage) ?>?action=create">Adicionar primeiro registo</a></div>
        <?php else: ?><div class="table-wrap"><table><thead><tr><th>Nome</th><?php if ($entityType !== 'categories'): ?><th>Email</th><th>Telefone</th><?php endif; ?><th></th></tr></thead><tbody>
            <?php foreach ($rows as $row): ?><tr><td class="product-name-cell"><strong><?= e($row['name']) ?></strong></td><?php if ($entityType !== 'categories'): ?><td><?= e($row['email'] ?? '—') ?></td><td><?= e($row['phone'] ?? '—') ?></td><?php endif; ?><td class="product-actions">
                <a class="table-action" href="<?= e($activePage) ?>?action=view&amp;id=<?= (int) $row['id'] ?>">Ver</a>
                <a class="table-action" href="<?= e($activePage) ?>?action=edit&amp;id=<?= (int) $row['id'] ?>">Editar</a>
                <form method="post" class="inline-form" onsubmit="return confirm('Eliminar este registo?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="table-action table-action--danger" type="submit">Eliminar</button></form>
            </td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
<?php endif; ?>
<?php require $root . '/app/views/footer.php'; ?>
