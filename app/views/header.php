<?php
$pageTitle ??= 'GeStock';
$pageSubtitle ??= '';
$user ??= Auth::current();
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Gestão de produtos, stock e vendas com GeStock.">
    <title><?= e($pageTitle) ?> | GeStock</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
<div class="app-shell">
    <?php require dirname(__DIR__) . '/views/sidebar.php'; ?>
    <div class="main-area">
        <header class="topbar">
            <button class="menu-toggle" type="button" aria-label="Abrir navegação" aria-expanded="false" aria-controls="sidebar">
                <span></span><span></span><span></span>
            </button>
            <div class="breadcrumb"><span>GeStock</span><span class="breadcrumb-divider">/</span><strong><?= e($pageTitle) ?></strong></div>
            <div class="topbar-right">
                <span class="sample-label"><span class="sample-dot"></span>Dados reais</span>
                <span class="topbar-date"><?= e(date('d/m/Y')) ?></span>
                <div class="user-avatar" aria-label="<?= e($user['name'] ?? '') ?>"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
            </div>
        </header>
        <main class="content">
            <section class="page-heading" aria-labelledby="page-title">
                <div>
                    <p class="eyebrow">GESTÃO</p>
                    <h1 id="page-title"><?= e($pageTitle) ?></h1>
                    <?php if ($pageSubtitle !== ''): ?><p class="page-subtitle"><?= e($pageSubtitle) ?></p><?php endif; ?>
                </div>
                <?php if (isset($pageAction)): ?><?= $pageAction ?><?php endif; ?>
            </section>
            <?php if (!empty($pageFeedback)): ?>
                <div class="feedback feedback--<?= ($pageFeedback['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="<?= ($pageFeedback['type'] ?? '') === 'success' ? 'status' : 'alert' ?>"><?= e($pageFeedback['message'] ?? '') ?></div>
            <?php endif; ?>
