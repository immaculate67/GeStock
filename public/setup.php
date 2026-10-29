<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$auth = new Auth(Database::connect());
if ($auth->hasUsers()) {
    header('Location: login.php', true, 303);
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        try {
            $id = $auth->createInitialAdministrator(
                is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
                is_string($_POST['email'] ?? null) ? $_POST['email'] : '',
                is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
                'administrator'
            );
            Auth::login(['id' => $id, 'name' => trim($_POST['name']), 'email' => trim($_POST['email']), 'role' => 'administrator']);
            header('Location: index.php', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception instanceof PDOException
                ? 'Não foi possível criar o utilizador. Confirme se o email já está registado.'
                : $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configuração inicial | GeStock</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-page"><main class="auth-card">
    <span class="brand-mark">G</span><p class="eyebrow">CONFIGURAÇÃO SEGURA</p><h1>Criar administrador</h1>
    <p class="page-subtitle">Esta configuração só está disponível antes da criação da primeira conta. Escolha uma palavra-passe com pelo menos 12 caracteres.</p>
    <?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="product-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="field"><label for="name">Nome</label><input id="name" name="name" required maxlength="120" autocomplete="name"></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="254" autocomplete="email"></div>
        <div class="field"><label for="password">Palavra-passe</label><input id="password" name="password" type="password" required minlength="12" maxlength="4096" autocomplete="new-password"></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Criar conta de administrador</button></div>
    </form>
</main></body></html>
