<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAdministrator();
requireBusinessModule($user, 'settings');
$pdo = Database::connect();
$error = '';
$feedback = takeFlash();
$moduleOptions = BusinessService::moduleOptions();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'save-modules') {
        $submittedModules = $_POST['modules'] ?? [];
        if (!is_array($submittedModules)
            || array_filter(
                $submittedModules,
                static fn(mixed $module): bool => !is_string($module)
                    || !array_key_exists($module, BusinessService::moduleOptions())
            ) !== []) {
            $error = 'A configuração dos módulos não é válida.';
        } else {
            $submittedModules = array_values(array_unique($submittedModules));
            try {
                $pdo->beginTransaction();
                $moduleQuery = $pdo->prepare(
                    'SELECT module_key FROM business_modules WHERE business_id = :business_id'
                );
                $moduleQuery->execute(['business_id' => (int) $user['business_id']]);
                $knownModules = $moduleQuery->fetchAll(PDO::FETCH_COLUMN);
                $updateModule = $pdo->prepare(
                    'UPDATE business_modules SET enabled = :enabled, updated_at = CURRENT_TIMESTAMP
                     WHERE business_id = :business_id AND module_key = :module_key'
                );
                foreach ($knownModules as $moduleKey) {
                    if (!array_key_exists($moduleKey, $moduleOptions)) {
                        continue;
                    }
                    $updateModule->execute([
                        'enabled' => (int) in_array($moduleKey, $submittedModules, true),
                        'business_id' => (int) $user['business_id'],
                        'module_key' => $moduleKey,
                    ]);
                }
                $pdo->commit();
                setFlash('success', 'Configuração dos módulos guardada.');
                header('Location: settings.php', true, 303);
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Não foi possível guardar a configuração dos módulos.';
                error_log('GeStock module settings could not be saved: ' . $exception->getMessage());
            }
        }
    } else {
        $name = trim(is_string($_POST['business_name'] ?? null) ? $_POST['business_name'] : '');
        if ($name === '' || strlen($name) > 120) {
            $error = 'Indique um nome até 120 caracteres.';
        } else {
            $statement = $pdo->prepare('UPDATE businesses SET name = :name WHERE id = :business_id');
            $statement->execute(['name' => $name, 'business_id' => (int) $user['business_id']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('O negócio ativo já não existe.');
            }
            $setting = $pdo->prepare(
                'INSERT INTO business_settings (business_id, setting_key, setting_value)
                 VALUES (:business_id, \'business_name\', :value)
                 ON CONFLICT(business_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            );
            $setting->execute(['business_id' => (int) $user['business_id'], 'value' => $name]);
            setFlash('success', 'Definições guardadas.');
            header('Location: settings.php', true, 303);
            exit;
        }
    }
}
$nameQuery = $pdo->prepare('SELECT name FROM businesses WHERE id = :business_id');
$nameQuery->execute(['business_id' => (int) $user['business_id']]);
$name = $nameQuery->fetchColumn() ?: 'O meu negócio';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'save-modules' && $error !== '' && is_string($_POST['business_name'] ?? null)) {
    $name = $_POST['business_name'];
}
$modulesQuery = $pdo->prepare(
    'SELECT module_key, enabled FROM business_modules WHERE business_id = :business_id ORDER BY module_key'
);
$modulesQuery->execute(['business_id' => (int) $user['business_id']]);
$moduleRows = $modulesQuery->fetchAll();
$activePage = 'settings.php';
$pageTitle = 'Definições';
$pageSubtitle = 'Configuração básica do espaço de trabalho.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" id="settings-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Negócio</h2><p>As alterações são guardadas na base de dados local.</p></div></div>
<form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save-business"><div class="field"><label for="business_name">Nome do negócio <span aria-hidden="true">*</span></label><input id="business_name" name="business_name" maxlength="120" required value="<?= e($name) ?>"<?= $error !== '' && ($_POST['action'] ?? '') !== 'save-modules' ? ' aria-invalid="true" aria-describedby="settings-error"' : '' ?>></div><div class="form-actions"><button class="button button--primary" type="submit">Guardar definições</button></div></form></section>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Módulos do negócio</h2><p>Ative apenas as áreas utilizadas. As alterações não eliminam os dados existentes.</p></div></div>
    <form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save-modules">
        <div class="module-settings-list"><?php foreach ($moduleRows as $module): if (!isset($moduleOptions[$module['module_key']])) { continue; } ?>
            <label class="module-setting"><span><strong><?= e($moduleOptions[$module['module_key']]['title']) ?></strong><small><?= e($moduleOptions[$module['module_key']]['description']) ?></small></span><input type="checkbox" name="modules[]" value="<?= e($module['module_key']) ?>"<?= (bool) $module['enabled'] ? ' checked' : '' ?>></label>
        <?php endforeach; ?></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Guardar módulos</button></div>
    </form>
</section>
<?php require $root . '/app/views/footer.php'; ?>
