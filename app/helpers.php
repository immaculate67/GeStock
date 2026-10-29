<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderIcon(string $name): string
{
    $paths = [
        'box' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.3 7.6 7.7 4.3 7.7-4.3M12 21v-9.1M8 5.3l8 4.5"/>',
        'sales' => '<path d="M4 19V5m0 14h16"/><path d="m7 14 4-4 3 2 5-6"/><path d="M16 6h3v3"/>',
        'stock' => '<path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3M8 12h8m-8 4h5"/>',
        'customers' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.8 1.1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.8-1.1l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.8-1.1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.8 1.1l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2Z"/>',
    ];
    $path = $paths[$name] ?? '';

    return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function validRecordId(mixed $value): ?int
{
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        return null;
    }

    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $id === false ? null : $id;
}

function formatPrice(int $priceCents): string
{
    return number_format(intdiv($priceCents, 100), 0, ',', '.') . ','
        . str_pad((string) ($priceCents % 100), 2, '0', STR_PAD_LEFT) . ' €';
}

function movementTypeLabel(string $type): string
{
    return match ($type) {
        'in' => 'Entrada',
        'out' => 'Saída',
        'correction' => 'Correção',
        'sale' => 'Venda',
        'sale_return' => 'Reposição por anulação',
        default => 'Outro',
    };
}

function requireAuthentication(): array
{
    $user = Auth::current();
    if ($user === null) {
        $auth = new Auth(Database::connect());
        header('Location: ' . ($auth->hasUsers() ? 'login.php' : 'setup.php'), true, 303);
        exit;
    }

    $statement = Database::connect()->prepare('SELECT name, email, role, active FROM users WHERE id = :id');
    $statement->execute(['id' => $user['id']]);
    $storedUser = $statement->fetch();
    if ($storedUser === false || !(bool) $storedUser['active']) {
        Auth::logout();
        header('Location: login.php', true, 303);
        exit;
    }
    $user = [
        'id' => (int) $user['id'],
        'name' => $storedUser['name'],
        'email' => $storedUser['email'],
        'role' => $storedUser['role'],
    ];
    $_SESSION['user'] = $user;

    return $user;
}

function requireAdministrator(): array
{
    $user = requireAuthentication();
    if ($user['role'] !== 'administrator') {
        http_response_code(403);
        exit('Não tem permissão para aceder a esta página.');
    }

    return $user;
}

function validDateInput(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}
