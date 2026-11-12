<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$auth = new Auth(Database::connect());
if (!$auth->hasUsers()) {
    header('Location: setup.php', true, 303);
    exit;
}
$currentUser = Auth::current();
if ($currentUser !== null) {
    header('Location: ' . ($currentUser['role'] === 'customer' ? 'customer.php' : 'index.php'), true, 303);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        if ($password !== $confirmation) {
            $error = 'As palavras-passe não coincidem.';
        } else {
            try {
                $id = $auth->createUser($name, $email, $password, 'customer');
                Auth::login([
                    'id' => $id,
                    'name' => trim($name),
                    'email' => trim($email),
                    'role' => 'customer',
                ]);
                header('Location: customer.php', true, 303);
                exit;
            } catch (PDOException $exception) {
                $error = 'Este email já está associado a uma conta.';
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Criar conta | GeStock</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-page"><main class="auth-card">
    <span class="brand-mark">G</span><p class="eyebrow">CONTA DE CLIENTE</p><h1>Criar conta</h1>
    <p class="page-subtitle">Crie uma conta de cliente GeStock. Esta conta não dá acesso às áreas internas do negócio.</p>
    <?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="product-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="field"><label for="name">Nome</label><input id="name" name="name" required maxlength="120" autocomplete="name" value="<?= e(is_string($_POST['name'] ?? null) ? $_POST['name'] : '') ?>"></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= e(is_string($_POST['email'] ?? null) ? $_POST['email'] : '') ?>"></div>
        <div class="field"><label for="password">Palavra-passe</label><input id="password" name="password" type="password" required minlength="12" maxlength="4096" autocomplete="new-password"><span class="field-help">Mínimo de 12 caracteres.</span></div>
        <div class="field"><label for="password_confirmation">Confirmar palavra-passe</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="4096" autocomplete="new-password"></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Criar conta de cliente</button></div>
    </form>
    <p class="page-subtitle">Já tem conta? <a href="login.php">Iniciar sessão</a></p>
</main></body></html>
