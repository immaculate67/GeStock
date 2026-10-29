<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$auth = new Auth(Database::connect());
if (!$auth->hasUsers()) {
    header('Location: setup.php', true, 303);
    exit;
}
if (Auth::current() !== null) {
    header('Location: index.php', true, 303);
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        $user = $auth->authenticate(
            is_string($_POST['email'] ?? null) ? $_POST['email'] : '',
            is_string($_POST['password'] ?? null) ? $_POST['password'] : ''
        );
        if ($user === null) {
            $error = 'Email ou palavra-passe incorretos.';
        } else {
            Auth::login($user);
            header('Location: index.php', true, 303);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Iniciar sessão | GeStock</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-page"><main class="auth-card">
    <span class="brand-mark">G</span><p class="eyebrow">GESTÃO DE NEGÓCIO</p><h1>Iniciar sessão</h1>
    <p class="page-subtitle">Aceda ao seu espaço GeStock.</p>
    <?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="product-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="username"></div>
        <div class="field"><label for="password">Palavra-passe</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Entrar</button></div>
    </form>
</main></body></html>
