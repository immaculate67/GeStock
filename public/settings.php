<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAdministrator();
$pdo = Database::connect();
$error = '';
$feedback = takeFlash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        $name = trim(is_string($_POST['business_name'] ?? null) ? $_POST['business_name'] : '');
        if ($name === '' || strlen($name) > 120) {
            $error = 'Indique um nome até 120 caracteres.';
        } else {
            $statement = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (\'business_name\', :value) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value');
            $statement->execute(['value' => $name]);
            setFlash('success', 'Definições guardadas.');
            header('Location: settings.php', true, 303);
            exit;
        }
    }
}
$name = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'business_name'")->fetchColumn() ?: 'O meu negócio';
$activePage = 'settings.php';
$pageTitle = 'Definições';
$pageSubtitle = 'Configuração básica do espaço de trabalho.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Negócio</h2><p>As alterações são guardadas na base de dados local.</p></div></div>
<form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><div class="field"><label for="business_name">Nome do negócio</label><input id="business_name" name="business_name" maxlength="120" required value="<?= e($name) ?>"></div><div class="form-actions"><button class="button button--primary" type="submit">Guardar</button></div></form></section>
<?php require $root . '/app/views/footer.php'; ?>
