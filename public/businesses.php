<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
$pdo = Database::connect();
$service = new BusinessService($pdo);
$error = '';
$feedback = takeFlash();
$moduleOptions = BusinessService::moduleOptions();
$postedModules = $_POST['modules'] ?? [];
$templates = $service->templates();
$selectedModules = [];
foreach ($templates as $templateRow) {
    if ($templateRow['template_key'] === 'custom') {
        $selectedModules = $templateRow['default_modules'];
        break;
    }
}
$review = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $selectedModules = [];
}
if (is_array($postedModules)) {
    $selectedModules = array_values(array_filter(
        $postedModules,
        static fn(mixed $module): bool => is_string($module)
            && in_array($module, BusinessService::configurableModules(), true)
    ));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'switch') {
        $businessId = validRecordId($_POST['business_id'] ?? null);
        $available = $businessId === null ? null : $pdo->prepare(
            'SELECT 1 FROM business_memberships WHERE business_id = :business_id AND user_id = :user_id AND active = 1'
        );
        if ($available !== null) {
            $available->execute(['business_id' => $businessId, 'user_id' => (int) $user['id']]);
        }
        if ($businessId === null || $available === null || $available->fetchColumn() === false) {
            http_response_code(403);
            $error = 'Não tem acesso ao negócio selecionado.';
        } else {
            $_SESSION['business_id'] = $businessId;
            setFlash('success', 'Negócio ativo atualizado.');
            header('Location: index.php', true, 303);
            exit;
        }
    } elseif (in_array(($_POST['action'] ?? ''), ['review', 'create'], true)) {
        if (!in_array($user['role'], ['owner', 'administrator'], true)) {
            http_response_code(403);
            $error = 'Só um proprietário ou administrador pode criar outro negócio.';
        } else {
            try {
                $requestedModules = $_POST['modules'] ?? [];
                if (!is_array($requestedModules)) {
                    throw new InvalidArgumentException('A seleção de módulos não é válida.');
                }
                $profile = [
                    'currency' => is_string($_POST['currency'] ?? null) ? $_POST['currency'] : 'EUR',
                    'tax_rate' => is_string($_POST['tax_rate'] ?? null) ? $_POST['tax_rate'] : '23',
                    'timezone' => is_string($_POST['timezone'] ?? null) ? $_POST['timezone'] : 'Europe/Lisbon',
                    'address' => is_string($_POST['address'] ?? null) ? $_POST['address'] : '',
                    'phone' => is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '',
                    'tax_id' => is_string($_POST['tax_id'] ?? null) ? $_POST['tax_id'] : '',
                    'branch_name' => is_string($_POST['branch_name'] ?? null) ? $_POST['branch_name'] : 'Local principal',
                    'branch_code' => is_string($_POST['branch_code'] ?? null) ? $_POST['branch_code'] : 'MAIN',
                    'branch_address' => is_string($_POST['branch_address'] ?? null) ? $_POST['branch_address'] : '',
                ];
                $profile['name'] = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
                if (($_POST['action'] ?? '') === 'review') {
                    $templateKey = is_string($_POST['industry_key'] ?? null) ? $_POST['industry_key'] : '';
                    $template = null;
                    foreach ($templates as $templateRow) {
                        if ($templateRow['template_key'] === $templateKey) {
                            $template = $templateRow;
                            break;
                        }
                    }
                    if ($template === null) {
                        throw new InvalidArgumentException('Selecione um modelo de negócio disponível.');
                    }
                    $businessId = null;
                    $name = trim($profile['name']);
                    if ($name === '' || mb_strlen($name) > 120) {
                        throw new InvalidArgumentException('Indique um nome de negócio com até 120 caracteres.');
                    }
                    $review = [
                        'name' => $name,
                        'template' => $template,
                        'modules' => array_values(array_filter(
                            $requestedModules,
                            static fn(mixed $module): bool => is_string($module)
                                && in_array($module, BusinessService::configurableModules(), true)
                        )),
                        'profile' => $profile,
                    ];
                } else {
                    $businessId = $service->create(
                        (int) $user['id'],
                        $profile['name'],
                        is_string($_POST['industry_key'] ?? null) ? $_POST['industry_key'] : '',
                        (int) $user['business_id'],
                        $requestedModules,
                        $profile
                    );
                    $_SESSION['business_id'] = $businessId;
                    setFlash('success', 'Negócio criado com a configuração inicial selecionada.');
                    header('Location: index.php', true, 303);
                    exit;
                }
            } catch (InvalidArgumentException|DomainException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('GeStock business creation failed: ' . $exception->getMessage());
                $error = 'Não foi possível criar o negócio. Tente novamente.';
            }
        }
    } else {
        http_response_code(400);
        $error = 'A ação solicitada não é suportada.';
    }
}

$memberships = $service->memberships((int) $user['id']);
$activePage = 'businesses.php';
$pageTitle = 'Negócios';
$pageSubtitle = 'Alterne entre negócios ou crie um espaço isolado com uma configuração inicial editável.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel products-panel">
    <div class="panel-heading"><div><h2>Os seus negócios</h2><p><?= count($memberships) ?> <?= count($memberships) === 1 ? 'negócio associado à conta' : 'negócios associados à conta' ?></p></div></div>
    <?php if ($memberships === []): ?>
        <div class="empty-state"><p>Não existem negócios associados a esta conta.</p></div>
    <?php else: ?>
        <div class="business-list">
            <?php foreach ($memberships as $membership): ?>
                <article class="business-card">
                    <div><strong><?= e($membership['name']) ?></strong><span><?= e($membership['industry_key']) ?> · <?= e(match ($membership['role']) { 'owner' => 'Proprietário', 'administrator' => 'Administrador', 'customer' => 'Cliente', default => 'Colaborador' }) ?></span></div>
                    <?php if ((int) $membership['id'] === (int) $user['business_id']): ?>
                        <span class="status-pill status-pill--healthy">Ativo</span>
                    <?php else: ?>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="switch"><input type="hidden" name="business_id" value="<?= (int) $membership['id'] ?>"><button class="button button--secondary" type="submit">Abrir negócio</button></form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php if (in_array($user['role'], ['owner', 'administrator'], true)): ?>
    <?php if ($review !== null): ?>
        <section class="panel product-form-panel business-wizard-review">
            <div class="panel-heading"><div><h2>Rever negócio</h2><p>Confirme a configuração antes de criar o novo espaço.</p></div></div>
            <dl class="review-grid">
                <div><dt>Nome</dt><dd><?= e($review['name']) ?></dd></div>
                <div><dt>Modelo</dt><dd><?= e($review['template']['name']) ?></dd></div>
                <div><dt>Moeda</dt><dd><?= e($review['profile']['currency']) ?></dd></div>
                <div><dt>Taxa fiscal</dt><dd><?= e($review['profile']['tax_rate']) ?>%</dd></div>
                <div><dt>Timezone</dt><dd><?= e($review['profile']['timezone']) ?></dd></div>
                <div><dt>Local principal</dt><dd><?= e($review['profile']['branch_name']) ?> (<?= e($review['profile']['branch_code']) ?>)</dd></div>
            </dl>
            <h3>Módulos selecionados</h3>
            <p><?= $review['modules'] === [] ? 'Nenhum módulo opcional selecionado.' : e(implode(', ', array_map(static fn(string $key): string => BusinessService::moduleOptions()[$key]['title'] ?? $key, $review['modules']))) ?></p>
            <form class="product-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="name" value="<?= e($review['name']) ?>">
                <input type="hidden" name="industry_key" value="<?= e($review['template']['template_key']) ?>">
                <?php foreach ($review['modules'] as $module): ?><input type="hidden" name="modules[]" value="<?= e($module) ?>"><?php endforeach; ?>
                <?php foreach (['currency', 'tax_rate', 'timezone', 'address', 'phone', 'tax_id', 'branch_name', 'branch_code', 'branch_address'] as $field): ?>
                    <input type="hidden" name="<?= e($field) ?>" value="<?= e($review['profile'][$field]) ?>">
                <?php endforeach; ?>
                <div class="form-actions">
                    <button class="button button--secondary" type="button" onclick="window.location.assign('businesses.php')">Voltar</button>
                    <button class="button button--primary" type="submit">Confirmar e criar</button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="panel product-form-panel business-wizard">
            <div class="panel-heading"><div><h2>Criar negócio</h2><p>Assistente progressivo com revisão antes da criação.</p></div></div>
            <ol class="wizard-progress" aria-label="Etapas da criação">
                <li class="is-active" data-wizard-progress="1"><span>1</span> Identificação</li>
                <li data-wizard-progress="2"><span>2</span> Modelo</li>
                <li data-wizard-progress="3"><span>3</span> Localização</li>
                <li data-wizard-progress="4"><span>4</span> Módulos</li>
            </ol>
            <form class="product-form" method="post" data-business-wizard>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="review">
                <div class="wizard-step" data-wizard-step="1">
                    <div class="form-grid">
                        <div class="field">
                            <label for="name">Nome do negócio <span aria-hidden="true">*</span></label>
                            <input id="name" name="name" maxlength="120" required value="<?= e(is_string($_POST['name'] ?? null) ? $_POST['name'] : '') ?>">
                        </div>
                        <div class="field">
                            <label for="tax_id">NIF / identificação fiscal</label>
                            <input id="tax_id" name="tax_id" maxlength="60" value="<?= e(is_string($_POST['tax_id'] ?? null) ? $_POST['tax_id'] : '') ?>">
                        </div>
                        <div class="field">
                            <label for="phone">Telefone</label>
                            <input id="phone" name="phone" maxlength="60" value="<?= e(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '') ?>">
                        </div>
                        <div class="field field--wide">
                            <label for="address">Morada</label>
                            <input id="address" name="address" maxlength="500" value="<?= e(is_string($_POST['address'] ?? null) ? $_POST['address'] : '') ?>">
                        </div>
                    </div>
                </div>
                <div class="wizard-step" data-wizard-step="2" hidden>
                    <div class="field">
                        <label for="industry_key">Modelo inicial</label>
                        <select id="industry_key" name="industry_key" required>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?= e($template['template_key']) ?>" data-default-modules="<?= e(json_encode($template['default_modules'])) ?>"<?= (($_POST['industry_key'] ?? 'custom') === $template['template_key']) ? ' selected' : '' ?>>
                                    <?= e($template['name']) ?> — <?= e($template['description']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="wizard-step" data-wizard-step="3" hidden>
                    <div class="form-grid">
                        <div class="field"><label for="currency">Moeda</label><select id="currency" name="currency"><option value="EUR">EUR — Euro</option><option value="USD">USD — Dólar</option><option value="GBP">GBP — Libra</option></select></div>
                        <div class="field"><label for="tax_rate">Taxa fiscal (%)</label><input id="tax_rate" name="tax_rate" type="number" min="0" max="100" step="0.01" value="<?= e(is_string($_POST['tax_rate'] ?? null) ? $_POST['tax_rate'] : '23') ?>"></div>
                        <div class="field"><label for="timezone">Timezone</label><select id="timezone" name="timezone"><option value="Europe/Lisbon">Europe/Lisbon</option><option value="Europe/Madrid">Europe/Madrid</option><option value="Europe/London">Europe/London</option><option value="UTC">UTC</option></select></div>
                        <div class="field"><label for="branch_name">Nome do local principal</label><input id="branch_name" name="branch_name" maxlength="120" value="<?= e(is_string($_POST['branch_name'] ?? null) ? $_POST['branch_name'] : 'Local principal') ?>"></div>
                        <div class="field"><label for="branch_code">Código do local</label><input id="branch_code" name="branch_code" maxlength="40" value="<?= e(is_string($_POST['branch_code'] ?? null) ? $_POST['branch_code'] : 'MAIN') ?>"></div>
                        <div class="field field--wide"><label for="branch_address">Morada do local</label><input id="branch_address" name="branch_address" maxlength="500" value="<?= e(is_string($_POST['branch_address'] ?? null) ? $_POST['branch_address'] : '') ?>"></div>
                    </div>
                </div>
                <div class="wizard-step" data-wizard-step="4" hidden>
                    <fieldset class="module-settings-list">
                        <legend>Áreas a ativar inicialmente</legend>
                        <?php foreach ($moduleOptions as $moduleKey => $module): ?>
                            <label class="module-setting">
                                <span><strong><?= e($module['title']) ?></strong><small><?= e($module['description']) ?></small></span>
                                <input type="checkbox" name="modules[]" value="<?= e($moduleKey) ?>"<?= in_array($moduleKey, $selectedModules, true) ? ' checked' : '' ?>>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </div>
                <div class="form-actions wizard-actions">
                    <button class="button button--secondary" type="button" data-wizard-back hidden>Anterior</button>
                    <button class="button button--primary" type="button" data-wizard-next>Continuar</button>
                    <button class="button button--primary" type="submit" data-wizard-review hidden>Rever configuração</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php require $root . '/app/views/footer.php'; ?>
