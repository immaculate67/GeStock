<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$user = requireAuthentication();
requireBusinessModule($user, 'operations');
$pdo = Database::connect();
$businessId = (int) $user['business_id'];
$operational = new OperationalService($pdo, $businessId);
$error = '';
$feedback = takeFlash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'room') {
                $id = $operational->saveRoom($_POST, (int) $user['id']);
                (new AuditPermissionService($pdo))->record($businessId, (int) $user['id'], 'criado', 'quarto', $id);
                setFlash('success', 'Quarto criado.');
            } elseif ($action === 'stay') {
                $id = $operational->reserveRoom($_POST, (int) $user['id']);
                (new AuditPermissionService($pdo))->record($businessId, (int) $user['id'], 'reservada', 'estadia', $id);
                setFlash('success', 'Estadia reservada.');
            } elseif ($action === 'stay-status') {
                $stayId = validRecordId($_POST['stay_id'] ?? null);
                if ($stayId === null) throw new InvalidArgumentException('Selecione uma estadia válida.');
                $operational->changeStayStatus($stayId, (string) ($_POST['status'] ?? ''), (int) $user['id']);
                (new AuditPermissionService($pdo))->record($businessId, (int) $user['id'], 'estado atualizada', 'estadia', $stayId);
                setFlash('success', 'Estado da estadia atualizado.');
            } elseif ($action === 'record') {
                $id = $operational->createRecord($_POST, (int) $user['id']);
                (new AuditPermissionService($pdo))->record($businessId, (int) $user['id'], 'criado', 'operação', $id);
                setFlash('success', 'Registo operacional criado.');
            } elseif ($action === 'pass') {
                $eventId = validRecordId($_POST['event_id'] ?? null);
                if ($eventId === null) throw new InvalidArgumentException('Selecione um evento válido.');
                $customerId = validRecordId($_POST['customer_id'] ?? null);
                $id = $operational->issuePass($eventId, (string) ($_POST['pass_code'] ?? ''), (string) ($_POST['pass_type'] ?? ''), $customerId, (int) $user['id']);
                (new AuditPermissionService($pdo))->record($businessId, (int) $user['id'], 'emitido', 'passe', $id);
                setFlash('success', 'Passe emitido.');
            } elseif ($action === 'redeem') {
                $operational->redeemPass((string) ($_POST['pass_code'] ?? ''), (int) $user['id']);
                setFlash('success', 'Passe validado com sucesso.');
            } else {
                http_response_code(400);
                throw new InvalidArgumentException('A ação solicitada não é suportada.');
            }
            header('Location: operations.php', true, 303);
            exit;
        } catch (InvalidArgumentException|DomainException|PDOException $exception) {
            $error = $exception instanceof PDOException ? 'Não foi possível guardar a operação.' : $exception->getMessage();
        }
    }
}
$rooms = $pdo->prepare('SELECT * FROM hotel_rooms WHERE business_id = :business_id ORDER BY room_number');
$rooms->execute(['business_id' => $businessId]);
$roomRows = $rooms->fetchAll();
$stays = $pdo->prepare('SELECT hs.*, hr.room_number, c.name AS customer_name
                        FROM hotel_stays hs JOIN hotel_rooms hr ON hr.id = hs.room_id
                        LEFT JOIN clients c ON c.id = hs.customer_id
                        WHERE hs.business_id = :business_id ORDER BY hs.check_in_at DESC');
$stays->execute(['business_id' => $businessId]);
$stayRows = $stays->fetchAll();
$records = $operational->all(is_string($_GET['type'] ?? null) ? $_GET['type'] : '', is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '');
$events = $pdo->prepare("SELECT id, reference, title FROM operational_records WHERE business_id = :business_id AND record_type = 'ticket' ORDER BY starts_at DESC");
$events->execute(['business_id' => $businessId]);
$eventRows = $events->fetchAll();
$customers = $pdo->prepare('SELECT id, name FROM clients WHERE business_id = :business_id ORDER BY name');
$customers->execute(['business_id' => $businessId]);
$customerRows = $customers->fetchAll();
$activePage = 'operations.php';
$pageTitle = 'Operações';
$pageSubtitle = 'Reservas, estadias, marcações, alugueres, passes e produção.';
$pageFeedback = $feedback;
require $root . '/app/views/header.php';
?>
<?php if ($error !== ''): ?><div class="feedback feedback--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel products-panel">
    <div class="panel-heading"><div><h2>Quartos e estadias</h2><p><?= count($roomRows) ?> quartos · <?= count($stayRows) ?> estadias</p></div></div>
    <?php if ($stayRows === []): ?><div class="empty-state"><p>Ainda não existem estadias.</p></div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Referência</th><th>Quarto</th><th>Cliente</th><th>Período</th><th>Estado</th><th>Ação</th></tr></thead><tbody>
    <?php foreach ($stayRows as $stay): ?>
        <tr><td><?= e($stay['reference']) ?></td><td><?= e($stay['room_number']) ?></td><td><?= e($stay['customer_name'] ?? 'Não indicado') ?></td><td><?= e(substr($stay['check_in_at'], 0, 16)) ?> → <?= e(substr($stay['check_out_at'], 0, 16)) ?></td><td><?= e(match ($stay['status']) {'reserved'=>'Reservada','checked_in'=>'Check-in','checked_out'=>'Check-out','cancelled'=>'Cancelada','no_show'=>'Não apareceu',default=>$stay['status']}) ?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="stay-status"><input type="hidden" name="stay_id" value="<?= (int) $stay['id'] ?>"><select name="status" aria-label="Novo estado"><option value="reserved">Reservada</option><option value="checked_in">Check-in</option><option value="checked_out">Check-out</option><option value="cancelled">Cancelada</option><option value="no_show">Não apareceu</option></select><button class="table-action" type="submit">Guardar</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<section class="panel product-form-panel">
    <div class="panel-heading"><div><h2>Criar quarto</h2><p>Os recursos de alojamento ficam isolados por negócio e local.</p></div></div>
    <form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="room">
        <div class="form-grid"><div class="field"><label for="room_number">Número *</label><input id="room_number" name="room_number" maxlength="40" required></div><div class="field"><label for="room_type">Tipo *</label><input id="room_type" name="room_type" maxlength="80" required></div><div class="field"><label for="status">Estado</label><select id="status" name="status"><option value="available">Disponível</option><option value="occupied">Ocupado</option><option value="cleaning">Limpeza</option><option value="maintenance">Manutenção</option><option value="inactive">Inativo</option></select></div><div class="field"><label for="base_price_cents">Preço base (€)</label><input id="base_price_cents" name="base_price_cents" value="0,00"></div></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Adicionar quarto</button></div>
    </form>
</section>
<section class="panel product-form-panel">
    <div class="panel-heading"><div><h2>Nova estadia</h2><p>Evita reservas sobrepostas para o mesmo quarto.</p></div></div>
    <form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="stay">
        <div class="form-grid"><div class="field"><label for="stay_room">Quarto *</label><select id="stay_room" name="room_id" required><option value="">Selecionar</option><?php foreach ($roomRows as $room): ?><option value="<?= (int) $room['id'] ?>"><?= e($room['room_number']) ?> · <?= e($room['room_type']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="stay_reference">Referência *</label><input id="stay_reference" name="reference" maxlength="80" required></div><div class="field"><label for="check_in_at">Entrada *</label><input id="check_in_at" name="check_in_at" type="datetime-local" required></div><div class="field"><label for="check_out_at">Saída *</label><input id="check_out_at" name="check_out_at" type="datetime-local" required></div><div class="field"><label for="adults">Adultos</label><input id="adults" name="adults" type="number" min="1" value="1"></div><div class="field"><label for="children">Crianças</label><input id="children" name="children" type="number" min="0" value="0"></div><div class="field"><label for="stay_customer">Cliente</label><select id="stay_customer" name="customer_id"><option value="">Não indicado</option><?php foreach ($customerRows as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= e($customer['name']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="stay_total">Total (€)</label><input id="stay_total" name="total_cents" value="0,00"></div></div>
        <div class="form-actions"><button class="button button--primary" type="submit">Reservar estadia</button></div>
    </form>
</section>
<section class="panel products-panel">
    <div class="panel-heading"><div><h2>Reservas, marcações, alugueres, passes e produção</h2><p><?= count($records) ?> registos</p></div></div>
    <form class="filter-form" method="get"><div class="field"><label for="type">Tipo</label><select id="type" name="type"><option value="">Todos</option><?php foreach (['reservation'=>'Reservas','appointment'=>'Marcações','rental'=>'Alugueres','membership'=>'Assinaturas','ticket'=>'Eventos','production'=>'Produção','other'=>'Outros'] as $key=>$label): ?><option value="<?= e($key) ?>"<?= (($_GET['type'] ?? '') === $key) ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><div class="field"><label for="operations_q">Pesquisar</label><input id="operations_q" name="q" value="<?= e($_GET['q'] ?? '') ?>"></div><button class="button button--secondary">Pesquisar</button></form>
    <?php if ($records === []): ?><div class="empty-state"><p>Ainda não existem registos.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Tipo</th><th>Referência</th><th>Título</th><th>Estado</th><th>Período</th></tr></thead><tbody><?php foreach ($records as $record): ?><tr><td><?= e($record['record_type']) ?></td><td><?= e($record['reference']) ?></td><td><?= e($record['title']) ?></td><td><?= e($record['status']) ?></td><td><?= e($record['starts_at'] ? substr($record['starts_at'], 0, 16) : '—') ?><?= $record['ends_at'] ? ' → ' . e(substr($record['ends_at'], 0, 16)) : '' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<section class="panel product-form-panel">
    <div class="panel-heading"><div><h2>Novo registo operacional</h2><p>Usado por reservas, marcações, alugueres, assinaturas e produção.</p></div></div>
    <form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="record"><div class="form-grid"><div class="field"><label for="record_type">Tipo *</label><select id="record_type" name="record_type" required><option value="reservation">Reserva</option><option value="appointment">Marcação</option><option value="rental">Aluguer</option><option value="membership">Adesão</option><option value="subscription">Assinatura</option><option value="ticket">Evento / passe</option><option value="production">Produção</option><option value="other">Outro</option></select></div><div class="field"><label for="record_reference">Referência *</label><input id="record_reference" name="reference" maxlength="80" required></div><div class="field field--wide"><label for="record_title">Título *</label><input id="record_title" name="title" maxlength="160" required></div><div class="field"><label for="record_status">Estado</label><input id="record_status" name="status" maxlength="40" value="draft"></div><div class="field"><label for="starts_at">Início</label><input id="starts_at" name="starts_at" type="datetime-local"></div><div class="field"><label for="ends_at">Fim</label><input id="ends_at" name="ends_at" type="datetime-local"></div><div class="field"><label for="record_quantity">Quantidade</label><input id="record_quantity" name="quantity" type="number" min="1" value="1"></div><div class="field"><label for="record_total">Total (€)</label><input id="record_total" name="total_cents" value="0,00"></div></div><div class="form-actions"><button class="button button--primary" type="submit">Criar registo</button></div></form>
</section>
<section class="panel product-form-panel">
    <div class="panel-heading"><div><h2>Passes e validação</h2><p>Emita passes por evento e valide códigos sem permitir entradas repetidas.</p></div></div>
    <form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="pass"><div class="form-grid"><div class="field"><label for="event_id">Evento *</label><select id="event_id" name="event_id" required><option value="">Selecionar</option><?php foreach ($eventRows as $event): ?><option value="<?= (int) $event['id'] ?>"><?= e($event['title']) ?> · <?= e($event['reference']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="pass_code">Código seguro *</label><input id="pass_code" name="pass_code" minlength="16" maxlength="128" required></div><div class="field"><label for="pass_type">Tipo *</label><input id="pass_type" name="pass_type" maxlength="80" required value="Entrada geral"></div></div><div class="form-actions"><button class="button button--primary" type="submit">Emitir passe</button></div></form>
    <form class="product-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="redeem"><div class="form-grid"><div class="field field--wide"><label for="redeem_code">Validar código *</label><input id="redeem_code" name="pass_code" required></div></div><div class="form-actions"><button class="button button--primary" type="submit">Validar entrada</button></div></form>
</section>
<?php require $root . '/app/views/footer.php'; ?>
