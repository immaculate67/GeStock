<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = requireCustomerAuthentication();
?>
<!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Área de cliente | GeStock</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-page portal-page"><main class="auth-card portal-card">
    <a class="brand portal-brand" href="customer.php" aria-label="GeStock, área de cliente"><span class="brand-mark">G</span><span class="brand-name">GeStock<span class="brand-period">.</span></span></a>
    <p class="eyebrow">ÁREA DE CLIENTE</p>
    <h1>Olá, <?= e($user['name']) ?></h1>
    <p class="page-subtitle">Consulte os dados associados à sua conta.</p>
    <dl class="portal-details">
        <div><dt>Nome</dt><dd><?= e($user['name']) ?></dd></div>
        <div><dt>Email</dt><dd><?= e($user['email']) ?></dd></div>
        <div><dt>Acesso</dt><dd>Conta de cliente</dd></div>
    </dl>
    <p class="portal-note">Esta área está separada dos dados internos de inventário e gestão.</p>
    <form action="logout.php" method="post" class="product-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="form-actions"><button class="button button--secondary" type="submit">Terminar sessão</button></div>
    </form>
</main></body></html>
