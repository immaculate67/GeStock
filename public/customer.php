<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = requireCustomerAuthentication();
?>
<!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Área de cliente | GeStock</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-page"><main class="auth-card">
    <span class="brand-mark">G</span><p class="eyebrow">ÁREA DE CLIENTE</p><h1>Olá, <?= e($user['name']) ?></h1>
    <p class="page-subtitle">A sua conta de cliente está ativa. Esta área está separada dos dados internos de inventário e gestão.</p>
    <p><?= e($user['email']) ?></p>
    <form action="logout.php" method="post" class="product-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="form-actions"><button class="button button--secondary" type="submit">Terminar sessão</button></div>
    </form>
</main></body></html>
