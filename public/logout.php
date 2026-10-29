<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Pedido inválido.');
}
Auth::logout();
header('Location: login.php', true, 303);
