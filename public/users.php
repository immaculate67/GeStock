<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAdministrator();
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
                is_string($_POST['role'] ?? null) ? $_POST['role'] : 'employee'
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
            $target = $pdo->prepare('SELECT role, active FROM users WHERE id = :id');
            $target->execute(['id' => $targetId]);
            $targetUser = $target->fetch();
            if ($targetUser === false) {
                $error = 'O utilizador não existe.';
            } elseif ($targetUser['role'] === 'administrator' && $newRole !== 'administrator' && (bool) $targetUser['active']) {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'administrator' AND active = 1")->fetchColumn();
                if ($count <= 1) {
                    $error = 'Tem de existir pelo menos um administrador ativo.';
                }
            }
            if ($error === '') {
                $update = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
                $update->execute(['role' => $newRole, 'id' => $targetId]);
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
            $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $update->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $targetId]);
            if ($update->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
                $exists->execute(['id' => $targetId]);
                $error = $exists->fetchColumn() === false ? 'O utilizador não existe.' : 'A nova palavra-passe é igual à atual.';
            } else {
                setFlash('success', 'Palavra-passe atualizada.');
                header('Location: users.php', true, 303);
                exit;
            }
        }
    } elseif (($_POST['action'] ?? '') === 'toggle') {
        $targetId = validRecordId($_POST['id'] ?? null);
        if ($targetId === null || $targetId === (int) $user['id']) {
            $error = 'Não pode desativar a sua própria conta.';
        } else {
            $target = $pdo->prepare('SELECT role, active FROM users WHERE id = :id');
            $target->execute(['id' => $targetId]);
            $targetUser = $target->fetch();
            if ($targetUser === false) {
                $error = 'O utilizador não existe.';
            } elseif ($targetUser['role'] === 'administrator' && (bool) $targetUser['active']) {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'administrator' AND active = 1")->fetchColumn();
                if ($count <= 1) {
                    $error = 'Tem de existir pelo menos um administrador ativo.';
                }
            }
            if ($error === '') {
                $update = $pdo->prepare('UPDATE users SET active = :active WHERE id = :id');
                $update->execute(['active' => (int) !(bool) $targetUser['active'], 'id' => $targetId]);
                setFlash('success', 'Estado do utilizador atualizado.');
                header('Location: users.php', true, 303);
                exit;
            }
        }
    }
}
$users = $pdo->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY name COLLATE NOCASE')->fetchAll();
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
<?php foreach ($users as $account): ?><?php $roleLabel = match ($account['role']) { 'administrator' => 'Administrador', 'customer' => 'Cliente', default => 'Colaborador' }; ?><tr><td><?= e($account['name']) ?><?= (int) $account['id'] === (int) $user['id'] ? ' <span class="muted-cell">(sessão atual)</span>' : '' ?></td><td><?= e($account['email']) ?></td><td><?php if ((int) $account['id'] !== (int) $user['id']): ?><form method="post" class="inline-form role-form" onsubmit="return confirm('Guardar as novas permissões deste utilizador?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="set-role"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><select name="role" aria-label="Perfil de <?= e($account['name']) ?>"><option value="employee"<?= $account['role'] === 'employee' ? ' selected' : '' ?>>Colaborador</option><option value="customer"<?= $account['role'] === 'customer' ? ' selected' : '' ?>>Cliente</option><option value="administrator"<?= $account['role'] === 'administrator' ? ' selected' : '' ?>>Administrador</option></select><button class="table-action" type="submit">Guardar perfil</button></form><?php else: ?><?= e($roleLabel) ?><?php endif; ?></td><td><span class="status-pill<?= (bool) $account['active'] ? ' status-pill--healthy' : ' status-pill--inactive' ?>"><?= (bool) $account['active'] ? 'Ativo' : 'Inativo' ?></span></td><td><details class="password-reset"><summary>Redefinir palavra-passe</summary><form method="post" class="inline-form" onsubmit="return confirm('Atualizar a palavra-passe deste utilizador?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="reset-password"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><label class="visually-hidden" for="password-<?= (int) $account['id'] ?>">Nova palavra-passe</label><input id="password-<?= (int) $account['id'] ?>" type="password" name="password" minlength="12" maxlength="4096" required autocomplete="new-password"><button class="table-action" type="submit">Atualizar</button></form></details><?php if ((int) $account['id'] !== (int) $user['id']): ?><form method="post" class="inline-form" onsubmit="return confirm('Confirmar alteração do estado deste utilizador?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><button class="table-action<?= (bool) $account['active'] ? ' table-action--danger' : '' ?>" type="submit"><?= (bool) $account['active'] ? 'Desativar' : 'Ativar' ?></button></form><?php else: ?><span class="muted-cell">Sessão atual</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php require $root . '/app/views/footer.php'; ?>
