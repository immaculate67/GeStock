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
$configuration = new BusinessConfigurationService($pdo, (int) $user['business_id']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'save-branch') {
        try {
            $configuration->saveBranch(
                is_string($_POST['branch_name'] ?? null) ? $_POST['branch_name'] : '',
                is_string($_POST['branch_code'] ?? null) ? $_POST['branch_code'] : '',
                is_string($_POST['branch_address'] ?? null) ? $_POST['branch_address'] : ''
            );
            setFlash('success', 'Local guardado.');
            header('Location: settings.php', true, 303);
            exit;
        } catch (InvalidArgumentException|PDOException $exception) {
            $error = $exception instanceof PDOException
                ? 'O nome ou código do local já está em utilização.'
                : $exception->getMessage();
        }
    } elseif (($_POST['action'] ?? '') === 'toggle-item-type') {
        $typeId = validRecordId($_POST['item_type_id'] ?? null);
        if ($typeId === null) {
            $error = 'O tipo de artigo selecionado não é válido.';
        } else {
            try {
                $configuration->setItemTypeEnabled($typeId, ($_POST['enabled'] ?? '') === '1');
                setFlash('success', 'Tipos de artigo atualizados.');
                header('Location: settings.php', true, 303);
                exit;
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'save-appearance') {
        $theme = is_string($_POST['theme'] ?? null) ? $_POST['theme'] : 'system';
        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            $error = 'O tema selecionado não é válido.';
        } else {
            $setting = $pdo->prepare(
                'INSERT INTO business_settings (business_id, setting_key, setting_value)
                 VALUES (:business_id, \'appearance_theme\', :value)
                 ON CONFLICT(business_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            );
            $setting->execute(['business_id' => (int) $user['business_id'], 'value' => $theme]);
            setFlash('success', 'Preferência de aparência guardada.');
            header('Location: settings.php', true, 303);
            exit;
        }
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
        try {
            (new BusinessService($pdo))->updateProfile(
                (int) $user['business_id'],
                $name,
                [
                    'currency' => is_string($_POST['currency'] ?? null) ? $_POST['currency'] : 'EUR',
                    'tax_rate' => is_string($_POST['tax_rate'] ?? null) ? $_POST['tax_rate'] : '23',
                    'timezone' => is_string($_POST['timezone'] ?? null) ? $_POST['timezone'] : 'Europe/Lisbon',
                    'address' => is_string($_POST['address'] ?? null) ? $_POST['address'] : '',
                    'phone' => is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '',
                    'tax_id' => is_string($_POST['tax_id'] ?? null) ? $_POST['tax_id'] : '',
                    'branch_name' => is_string($_POST['branch_name'] ?? null) ? $_POST['branch_name'] : 'Local principal',
                    'branch_code' => is_string($_POST['branch_code'] ?? null) ? $_POST['branch_code'] : 'MAIN',
                    'branch_address' => is_string($_POST['branch_address'] ?? null) ? $_POST['branch_address'] : '',
                ]
            );
            setFlash('success', 'Definições guardadas.');
            header('Location: settings.php', true, 303);
            exit;
        } catch (InvalidArgumentException|PDOException $exception) {
            $error = $exception instanceof PDOException
                ? 'O código do local principal já está em utilização.'
                : $exception->getMessage();
        }
    }
}
$profile = (new BusinessService($pdo))->profile((int) $user['business_id']);
$name = $profile['name'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'save-modules' && $error !== '' && is_string($_POST['business_name'] ?? null)) {
    $name = $_POST['business_name'];
}
$modulesQuery = $pdo->prepare(
    'SELECT module_key, enabled FROM business_modules WHERE business_id = :business_id ORDER BY module_key'
);
$modulesQuery->execute(['business_id' => (int) $user['business_id']]);
$moduleRows = $modulesQuery->fetchAll();
$branches = $configuration->branches();
$itemTypes = $configuration->itemTypes();
$themeQuery = $pdo->prepare("SELECT setting_value FROM business_settings WHERE business_id = :business_id AND setting_key = 'appearance_theme'");
$themeQuery->execute(['business_id' => (int) $user['business_id']]);
$theme = $themeQuery->fetchColumn();
$theme = in_array($theme, ['light', 'dark', 'system'], true) ? (string) $theme : 'system';
$activePage = 'settings.php';
$pageTitle = 'Definições';
$pageSubtitle = 'Configuração básica do espaço de trabalho.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" id="settings-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Aparência</h2><p>Escolha claro, escuro ou seguir o sistema.</p></div></div>
<form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save-appearance"><div class="field"><label for="theme">Tema</label><select id="theme" name="theme"><option value="light"<?= $theme === 'light' ? ' selected' : '' ?>>Claro</option><option value="dark"<?= $theme === 'dark' ? ' selected' : '' ?>>Escuro</option><option value="system"<?= $theme === 'system' ? ' selected' : '' ?>>Sistema</option></select></div><div class="form-actions"><button class="button button--primary" type="submit">Guardar aparência</button></div></form></section>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Negócio</h2><p>Identidade, localização fiscal e local principal.</p></div></div>
<form class="product-form" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="save-business">
    <div class="form-grid">
        <div class="field"><label for="business_name">Nome do negócio <span aria-hidden="true">*</span></label><input id="business_name" name="business_name" maxlength="120" required value="<?= e($name) ?>"<?= $error !== '' && ($_POST['action'] ?? '') !== 'save-modules' ? ' aria-invalid="true" aria-describedby="settings-error"' : '' ?>></div>
        <div class="field"><label for="tax_id">NIF / identificação fiscal</label><input id="tax_id" name="tax_id" maxlength="60" value="<?= e(is_string($_POST['tax_id'] ?? null) ? $_POST['tax_id'] : $profile['tax_id']) ?>"></div>
        <div class="field"><label for="phone">Telefone</label><input id="phone" name="phone" maxlength="60" value="<?= e(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : $profile['phone']) ?>"></div>
        <div class="field field--wide"><label for="address">Morada</label><input id="address" name="address" maxlength="500" value="<?= e(is_string($_POST['address'] ?? null) ? $_POST['address'] : $profile['address']) ?>"></div>
        <div class="field"><label for="currency">Moeda</label><select id="currency" name="currency"><?php foreach (['EUR' => 'EUR — Euro', 'USD' => 'USD — Dólar', 'GBP' => 'GBP — Libra'] as $code => $label): ?><option value="<?= e($code) ?>"<?= $profile['currency'] === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="tax_rate">Taxa fiscal (%)</label><input id="tax_rate" name="tax_rate" type="number" min="0" max="100" step="0.01" value="<?= e(is_string($_POST['tax_rate'] ?? null) ? $_POST['tax_rate'] : $profile['tax_rate']) ?>"></div>
        <div class="field"><label for="timezone">Timezone</label><select id="timezone" name="timezone"><?php foreach (['Europe/Lisbon', 'Europe/Madrid', 'Europe/London', 'UTC'] as $timezone): ?><option value="<?= e($timezone) ?>"<?= $profile['timezone'] === $timezone ? ' selected' : '' ?>><?= e($timezone) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="branch_name">Nome do local principal</label><input id="branch_name" name="branch_name" maxlength="120" value="<?= e(is_string($_POST['branch_name'] ?? null) ? $_POST['branch_name'] : $profile['branch_name']) ?>"></div>
        <div class="field"><label for="branch_code">Código do local</label><input id="branch_code" name="branch_code" maxlength="40" value="<?= e(is_string($_POST['branch_code'] ?? null) ? $_POST['branch_code'] : $profile['branch_code']) ?>"></div>
        <div class="field field--wide"><label for="branch_address">Morada do local</label><input id="branch_address" name="branch_address" maxlength="500" value="<?= e(is_string($_POST['branch_address'] ?? null) ? $_POST['branch_address'] : $profile['branch_address']) ?>"></div>
    </div>
    <div class="form-actions"><button class="button button--primary" type="submit">Guardar definições</button></div>
</form></section>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Módulos do negócio</h2><p>Ative apenas as áreas utilizadas. As alterações não eliminam os dados existentes.</p></div></div>
    <form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save-modules">
        <div class="module-settings-list"><?php foreach ($moduleRows as $module): if (!isset($moduleOptions[$module['module_key']])) { continue; } ?>
            <label class="module-setting"><span><strong><?= e($moduleOptions[$module['module_key']]['title']) ?></strong><small><?= e($moduleOptions[$module['module_key']]['description']) ?></small></span><input type="checkbox" name="modules[]" value="<?= e($module['module_key']) ?>"<?= (bool) $module['enabled'] ? ' checked' : '' ?>></label>
        <?php endforeach; ?></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Guardar módulos</button></div>
    </form>
</section>
<section class="panel product-form-panel"><div class="panel-heading"><div><h2>Locais e tipos de artigo</h2><p>Prepare filiais, serviços e recursos sem criar inventário automaticamente.</p></div></div>
    <div class="business-list">
        <?php foreach ($branches as $branch): ?>
            <article class="business-card"><div><strong><?= e($branch['name']) ?></strong><span><?= e($branch['code']) ?><?= $branch['address'] !== null ? ' · ' . e($branch['address']) : '' ?></span></div><span class="status-pill<?= (int) $branch['active'] ? ' status-pill--healthy' : '' ?>"><?= (int) $branch['active'] ? 'Ativo' : 'Inativo' ?></span></article>
        <?php endforeach; ?>
    </div>
    <form class="product-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save-branch">
        <div class="form-grid"><div class="field"><label for="branch_name">Nome do local</label><input id="branch_name" name="branch_name" maxlength="120" required></div><div class="field"><label for="branch_code">Código</label><input id="branch_code" name="branch_code" maxlength="40" required></div><div class="field field--wide"><label for="branch_address">Morada <span class="field-optional">(opcional)</span></label><input id="branch_address" name="branch_address" maxlength="500"></div></div>
        <div class="form-actions"><button class="button button--secondary" type="submit">Adicionar local</button></div>
    </form>
    <div class="module-settings-list"><?php foreach ($itemTypes as $itemType): ?>
        <form class="module-setting" method="post"><span><strong><?= e($itemType['label']) ?></strong><small><?= (int) $itemType['stock_tracked'] ? 'Controla stock' : 'Sem stock automático' ?></small></span><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="toggle-item-type"><input type="hidden" name="item_type_id" value="<?= (int) $itemType['id'] ?>"><input type="hidden" name="enabled" value="<?= (int) $itemType['enabled'] ? '0' : '1' ?>"><button class="button button--secondary" type="submit"><?= (int) $itemType['enabled'] ? 'Desativar' : 'Ativar' ?></button></form>
    <?php endforeach; ?></div>
</section>
<?php require $root . '/app/views/footer.php'; ?>
