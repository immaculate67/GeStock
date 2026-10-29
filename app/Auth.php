<?php
declare(strict_types=1);

final class Auth
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasUsers(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    public function createUser(string $name, string $email, string $password, string $role = 'employee'): int
    {
        $name = trim($name);
        $email = trim($email);
        if ($name === '' || mb_strlen($name) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('Indique um nome e email válidos.');
        }
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new InvalidArgumentException('A palavra-passe deve ter pelo menos 12 caracteres.');
        }
        if (!in_array($role, ['administrator', 'employee'], true)) {
            throw new InvalidArgumentException('O perfil selecionado não é válido.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createInitialAdministrator(string $name, string $email, string $password): int
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->hasUsers()) {
                throw new DomainException('A conta inicial já foi criada.');
            }
            $id = $this->createUser($name, $email, $password, 'administrator');
            $this->pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function authenticate(string $email, string $password): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role, active FROM users WHERE email = :email COLLATE NOCASE'
        );
        $statement->execute(['email' => trim($email)]);
        $user = $statement->fetch();
        if ($user === false || !(bool) $user['active'] || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $update = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }
        unset($user['password_hash'], $user['active']);

        return $user;
    }

    public static function current(): ?array
    {
        $user = $_SESSION['user'] ?? null;

        return is_array($user) && isset($user['id'], $user['role']) ? $user : null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], $parameters['secure'], $parameters['httponly']);
        }
        session_destroy();
    }
}
