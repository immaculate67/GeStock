<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAdministrator();
requireBusinessModule($user, 'users');
$pdo = Database::connect();
$auth = new Auth($pdo);
$error = '';
$feedback = takeFlash();
$formValues = [
    'name' => is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
    'email' => is_string($_POST['email'] ?? null) ? $_POST['email'] : '',
    'role' => is_string($_POST['role'] ?? null) && in_array($_POST['role'], ['administrator', 'employee', 'customer'], true) ? $_POST['role'] : 'employee',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'create') {
        try {
            $auth->createUser(
                is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
                is_string($_POST['email'] ?? null) ? $_POST['email'] : '',
                is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
                is_string($_POST['role'] ?? null) ? $_POST['role'] : 'employee',
                (int) $user['business_id']
            );
            setFlash('success', 'Utilizador criado com sucesso.');
            header('Location: users.php', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception instanceof PDOException ? 'Este email já está associado a uma conta.' : $exception->getMessage();
        }
    } elseif (($_POST['action'] ?? '') === 'set-role') {
        $targetId = validRecordId($_POST['id'] ?? null);
        $newRole = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
        if ($targetId === null || $targetId === (int) $user['id'] || !in_array($newRole, ['administrator', 'employee', 'customer'], true)) {
            $error = 'Não pode alterar o seu próprio perfil; selecione um perfil válido.';
        } else {
            $target = $pdo->prepare('SELECT role, active FROM business_memberships WHERE user_id = :id AND business_id = :business_id');
            $target->execute(['id' => $targetId, 'business_id' => (int) $user['business_id']]);
            $targetUser = $target->fetch();
            if ($targetUser === false) {
                $error = 'O utilizador não existe.';
            } elseif ($targetUser['role'] === 'owner') {
                $error = 'A propriedade do negócio não pode ser alterada nesta página.';
            } elseif (in_array($targetUser['role'], ['owner', 'administrator'], true) && $newRole !== 'administrator' && (bool) $targetUser['active']) {
                $countQuery = $pdo->prepare(
                    "SELECT COUNT(*) FROM business_memberships
                     WHERE business_id = :business_id AND role IN ('owner', 'administrator') AND active = 1"
                );
                $countQuery->execute(['business_id' => (int) $user['business_id']]);
                $count = (int) $countQuery->fetchColumn();
                if ($count <= 1) {
                    $error = 'Tem de existir pelo menos um administrador ativo.';
                }
            }
            if ($error === '') {
                $update = $pdo->prepare('UPDATE business_memberships SET role = :role WHERE user_id = :id AND business_id = :business_id');
                $update->execute(['role' => $newRole, 'id' => $targetId, 'business_id' => (int) $user['business_id']]);
                setFlash('success', 'Perfil do utilizador atualizado.');
                header('Location: users.php', true, 303);
                exit;
            }
        }
    } elseif (($_POST['action'] ?? '') === 'reset-password') {
        $targetId = validRecordId($_POST['id'] ?? null);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if ($targetId === null || strlen($password) < 12 || strlen($password) > 4096) {
            $error = 'Selecione um utilizador e introduza uma palavra-passe com pelo menos 12 caracteres.';
        } else {
            try {
                $auth->resetMemberPassword(
                    $targetId,
                    (int) $user['business_id'],
                    (int) $user['id'],
                    $password
                );
                setFlash('success', 'Palavra-passe atualizada.');
                header('Location: users.php', true, 303);
                exit;
            } catch (InvalidArgumentException|DomainException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'set-permissions') {
        $targetId = validRecordId($_POST['id'] ?? null);
        $submitted = $_POST['permissions'] ?? [];
        if ($targetId === null || !is_array($submitted)) {
            $error = 'A seleção de permissões não é válida.';
        } else {
            try {
                $permissions = new AuditPermissionService($pdo);
                foreach (AuditPermissionService::PERMISSIONS as $permission) {
                    $permissions->setPermission(
                        (int) $user['business_id'],
                        (int) $user['id'],
                        $targetId,
                        $permission,
                        in_array($permission, $submitted, true)
                    );
                }
                setFlash('success', 'Permissões granulares atualizadas.');
                header('Location: users.php', true, 303);
                exit;
            } catch (InvalidArgumentException|DomainException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'toggle') {
        $targetId = validRecordId($_POST['id'] ?? null);
        if ($targetId === null || $targetId === (int) $user['id']) {
            $error = 'Não pode desativar a sua própria conta.';
        } else {
            $target = $pdo->prepare('SELECT role, active FROM business_memberships WHERE user_id = :id AND business_id = :business_id');
            $target->execute(['id' => $targetId, 'business_id' => (int) $user['business_id']]);
            $targetUser = $target->fetch();
            if ($targetUser === false) {
                $error = 'O utilizador não existe.';
            } elseif ($targetUser['role'] === 'owner') {
                $error = 'A conta proprietária não pode ser desativada nesta página.';
            } elseif (in_array($targetUser['role'], ['owner', 'administrator'], true) && (bool) $targetUser['active']) {
                $countQuery = $pdo->prepare(
                    "SELECT COUNT(*) FROM business_memberships
                     WHERE business_id = :business_id AND role IN ('owner', 'administrator') AND active = 1"
                );
                $countQuery->execute(['business_id' => (int) $user['business_id']]);
                $count = (int) $countQuery->fetchColumn();
                if ($count <= 1) {
                    $error = 'Tem de existir pelo menos um administrador ativo.';
                }
            }
            if ($error === '') {
                $update = $pdo->prepare('UPDATE business_memberships SET active = :active WHERE user_id = :id AND business_id = :business_id');
                $update->execute([
                    'active' => (int) !(bool) $targetUser['active'],
                    'id' => $targetId,
                    'business_id' => (int) $user['business_id'],
                ]);
                setFlash('success', 'Estado do utilizador atualizado.');
                header('Location: users.php', true, 303);
                exit;
            }
        }
    }
}
$usersQuery = $pdo->prepare(
    'SELECT u.id, u.name, u.email, bm.role, bm.active, u.created_at
     FROM users u
     JOIN business_memberships bm ON bm.user_id = u.id
     WHERE bm.business_id = :business_id
     ORDER BY u.name COLLATE NOCASE'
);
$usersQuery->execute(['business_id' => (int) $user['business_id']]);
$users = $usersQuery->fetchAll();
$permissionService = new AuditPermissionService($pdo);
$activePage = 'users.php';
$pageTitle = 'Utilizadores';
$pageSubtitle = 'Contas locais e permissões de acesso.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Adicionar utilizador</h2><p>As palavras-passe são armazenadas com hash seguro.</p></div></div>
    <form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field"><label for="name">Nome <span aria-hidden="true">*</span></label><input id="name" name="name" maxlength="120" required value="<?= e($formValues['name']) ?>"></div>
            <div class="field"><label for="email">Email <span aria-hidden="true">*</span></label><input id="email" name="email" type="email" maxlength="254" required value="<?= e($formValues['email']) ?>"></div>
            <div class="field"><label for="password">Palavra-passe inicial <span aria-hidden="true">*</span></label><input id="password" name="password" type="password" minlength="12" required autocomplete="new-password"><span class="field-help">Mínimo de 12 caracteres.</span></div>
            <div class="field"><label for="role">Perfil</label><select id="role" name="role"><option value="employee"<?= $formValues['role'] === 'employee' ? ' selected' : '' ?>>Colaborador</option><option value="customer"<?= $formValues['role'] === 'customer' ? ' selected' : '' ?>>Cliente</option><option value="administrator"<?= $formValues['role'] === 'administrator' ? ' selected' : '' ?>>Administrador</option></select></div>
        </div><div class="form-actions"><button class="button button--primary" type="submit">Criar utilizador</button></div>
    </form>
</section>
<section class="panel products-panel"><div class="panel-heading"><div><h2>Contas existentes</h2><p><?= count($users) ?> <?= count($users) === 1 ? 'conta' : 'contas' ?></p></div></div><?php if ($users === []): ?><div class="empty-state"><p>Ainda não existem outras contas para apresentar.</p></div><?php else: ?><div class="table-wrap"><table class="users-table"><thead><tr><th scope="col">Nome</th><th scope="col">Email</th><th scope="col">Perfil</th><th scope="col">Estado</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>
<?php foreach ($users as $account): ?><?php $roleLabel = match ($account['role']) { 'owner' => 'Proprietário', 'administrator' => 'Administrador', 'customer' => 'Cliente', default => 'Colaborador' }; ?><tr><td><?= e($account['name']) ?><?= (int) $account['id'] === (int) $user['id'] ? ' <span class="muted-cell">(sessão atual)</span>' : '' ?></td><td><?= e($account['email']) ?></td><td><?php if ((int) $account['id'] !== (int) $user['id'] && $account['role'] !== 'owner'): ?><form method="post" class="inline-form role-form" onsubmit="return confirm('Guardar as novas permissões deste utilizador?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="set-role"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><select name="role" aria-label="Perfil de <?= e($account['name']) ?>"><option value="employee"<?= $account['role'] === 'employee' ? ' selected' : '' ?>>Colaborador</option><option value="customer"<?= $account['role'] === 'customer' ? ' selected' : '' ?>>Cliente</option><option value="administrator"<?= $account['role'] === 'administrator' ? ' selected' : '' ?>>Administrador</option></select><button class="table-action" type="submit">Guardar perfil</button></form><?php else: ?><?= e($roleLabel) ?><?php endif; ?></td><td><span class="status-pill<?= (bool) $account['active'] ? ' status-pill--healthy' : ' status-pill--inactive' ?>"><?= (bool) $account['active'] ? 'Ativo' : 'Inativo' ?></span></td><td><details class="password-reset"><summary>Redefinir palavra-passe</summary><form method="post" class="inline-form" onsubmit="return confirm('Atualizar a palavra-passe deste utilizador?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="reset-password"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><label class="visually-hidden" for="password-<?= (int) $account['id'] ?>">Nova palavra-passe</label><input id="password-<?= (int) $account['id'] ?>" type="password" name="password" minlength="12" maxlength="4096" required autocomplete="new-password"><button class="table-action" type="submit">Atualizar</button></form></details><?php if ((int) $account['id'] !== (int) $user['id'] && $account['role'] !== 'owner'): ?><form method="post" class="inline-form" onsubmit="return confirm('Confirmar alteração do estado deste utilizador?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><button class="table-action<?= (bool) $account['active'] ? ' table-action--danger' : '' ?>" type="submit"><?= (bool) $account['active'] ? 'Desativar' : 'Ativar' ?></button></form><?php else: ?><span class="muted-cell">Sessão atual</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<section class="panel product-form-panel">
    <div class="panel-heading"><div><h2>Permissões granulares</h2><p>Configure operações permitidas a colaboradores. Proprietários e administradores mantêm acesso integral.</p></div></div>
    <?php foreach ($users as $account): ?>
        <?php if (in_array($account['role'], ['owner', 'administrator'], true)) continue; ?>
        <form class="product-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="set-permissions">
            <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
            <h3><?= e($account['name']) ?></h3>
            <div class="module-settings-list">
                <?php foreach (AuditPermissionService::PERMISSIONS as $permission): ?>
                    <?php $granted = $permissionService->can((int) $user['business_id'], (int) $account['id'], (string) $account['role'], $permission); ?>
                    <label class="module-setting"><span><strong><?= e($permission) ?></strong></span><input type="checkbox" name="permissions[]" value="<?= e($permission) ?>"<?= $granted ? ' checked' : '' ?>></label>
                <?php endforeach; ?>
            </div>
            <div class="form-actions"><button class="button button--primary" type="submit">Guardar permissões</button></div>
        </form>
    <?php endforeach; ?>
</section>
<?php require $root . '/app/views/footer.php'; ?>
