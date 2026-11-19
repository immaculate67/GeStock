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
$selectedModules = BusinessService::configurableModules();
$postedModules = $_POST['modules'] ?? [];
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
    } elseif (($_POST['action'] ?? '') === 'create') {
        if (!in_array($user['role'], ['owner', 'administrator'], true)) {
            http_response_code(403);
            $error = 'Só um proprietário ou administrador pode criar outro negócio.';
        } else {
            try {
                $requestedModules = $_POST['modules'] ?? [];
                if (!is_array($requestedModules)) {
                    throw new InvalidArgumentException('A seleção de módulos não é válida.');
                }
                $businessId = $service->create(
                    (int) $user['id'],
                    is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
                    is_string($_POST['industry_key'] ?? null) ? $_POST['industry_key'] : '',
                    (int) $user['business_id'],
                    $requestedModules
                );
                $_SESSION['business_id'] = $businessId;
                setFlash('success', 'Negócio criado com a configuração inicial selecionada.');
                header('Location: index.php', true, 303);
                exit;
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
$templates = $service->templates();
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
    <section class="panel product-form-panel">
        <div class="panel-heading"><div><h2>Criar negócio</h2><p>Os modelos só configuram módulos e sugestões. Produtos, serviços, preços, clientes e movimentos são adicionados por si.</p></div></div>
        <form class="product-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="field">
                    <label for="name">Nome do negócio <span aria-hidden="true">*</span></label>
                    <input id="name" name="name" maxlength="120" required value="<?= e(is_string($_POST['name'] ?? null) ? $_POST['name'] : '') ?>">
                </div>
                <div class="field">
                    <label for="industry_key">Configuração inicial</label>
                    <select id="industry_key" name="industry_key" required>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?= e($template['template_key']) ?>"<?= (($_POST['industry_key'] ?? 'custom') === $template['template_key']) ? ' selected' : '' ?>>
                                <?= e($template['name']) ?> — <?= e($template['description']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <fieldset class="module-settings-list">
                <legend>Áreas a ativar inicialmente</legend>
                <?php foreach ($moduleOptions as $moduleKey => $module): ?>
                    <label class="module-setting">
                        <span><strong><?= e($module['title']) ?></strong><small><?= e($module['description']) ?></small></span>
                        <input type="checkbox" name="modules[]" value="<?= e($moduleKey) ?>"<?= in_array($moduleKey, $selectedModules, true) ? ' checked' : '' ?>>
                    </label>
                <?php endforeach; ?>
                <p class="field-help">Utilizadores e definições permanecem disponíveis para gerir o negócio e alterar esta seleção.</p>
            </fieldset>
            <div class="form-actions"><button class="button button--primary" type="submit">Criar negócio</button></div>
        </form>
    </section>
<?php endif; ?>
<?php require $root . '/app/views/footer.php'; ?>
