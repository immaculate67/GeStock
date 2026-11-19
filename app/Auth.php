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

    public function createUser(string $name, string $email, string $password, string $role = 'employee', ?int $businessId = null): int
    {
        $name = trim($name);
        $email = trim($email);
        if ($name === '' || mb_strlen($name) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('Indique um nome e email válidos.');
        }
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new InvalidArgumentException('A palavra-passe deve ter pelo menos 12 caracteres.');
        }
        if (!in_array($role, ['administrator', 'employee', 'customer'], true)) {
            throw new InvalidArgumentException('O perfil selecionado não é válido.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            if ($businessId !== null) {
                $membership = $this->pdo->prepare(
                    'INSERT INTO business_memberships (business_id, user_id, role) VALUES (:business_id, :user_id, :role)'
                );
                $membership->execute(['business_id' => $businessId, 'user_id' => $id, 'role' => $role]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $id;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function resetMemberPassword(int $userId, int $businessId, int $actorUserId, string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new InvalidArgumentException('A palavra-passe deve ter pelo menos 12 caracteres.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash
             WHERE id = :user_id
               AND EXISTS (
                   SELECT 1 FROM business_memberships membership
                   WHERE membership.user_id = users.id
                     AND membership.business_id = :business_id
                     AND (
                         membership.user_id = :actor_user_id
                         OR (
                             membership.role <> \'owner\'
                             AND NOT EXISTS (
                                 SELECT 1 FROM business_memberships other_membership
                                 WHERE other_membership.user_id = users.id
                                   AND other_membership.business_id <> :other_business_id
                             )
                         )
                     )
               )'
        );
        $statement->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'user_id' => $userId,
            'business_id' => $businessId,
            'actor_user_id' => $actorUserId,
            'other_business_id' => $businessId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException(
                'Só pode redefinir a palavra-passe de membros não proprietários associados exclusivamente ao negócio ativo.'
            );
        }
    }

    public function createInitialAdministrator(string $name, string $email, string $password): int
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->hasUsers()) {
                throw new DomainException('A conta inicial já foi criada.');
            }
            $id = $this->createUser($name, $email, $password, 'administrator');
            $business = $this->pdo->query('SELECT id FROM businesses ORDER BY id LIMIT 1')->fetchColumn();
            if ($business === false) {
                $nameSetting = $this->pdo->query(
                    "SELECT setting_value FROM settings WHERE setting_key = 'business_name' LIMIT 1"
                )->fetchColumn();
                $businessName = is_string($nameSetting) && trim($nameSetting) !== ''
                    ? mb_substr(trim($nameSetting), 0, 120)
                    : 'O meu negócio';
                $createBusiness = $this->pdo->prepare(
                    'INSERT INTO businesses (name, industry_key, owner_user_id) VALUES (:name, \'custom\', :owner)'
                );
                $createBusiness->execute(['name' => $businessName, 'owner' => $id]);
                $businessId = (int) $this->pdo->lastInsertId();
            } else {
                $businessId = (int) $business;
                $owner = $this->pdo->prepare('UPDATE businesses SET owner_user_id = :owner WHERE id = :id AND owner_user_id IS NULL');
                $owner->execute(['owner' => $id, 'id' => $businessId]);
            }
            $membership = $this->pdo->prepare(
                'INSERT INTO business_memberships (business_id, user_id, role) VALUES (:business_id, :user_id, \'owner\')'
            );
            $membership->execute(['business_id' => $businessId, 'user_id' => $id]);
            $moduleCount = $this->pdo->prepare('SELECT COUNT(*) FROM business_modules WHERE business_id = :business_id');
            $moduleCount->execute(['business_id' => $businessId]);
            if ((int) $moduleCount->fetchColumn() === 0) {
                require_once __DIR__ . '/IndustryTemplateService.php';
                IndustryTemplateService::apply($this->pdo, $businessId, 'custom');
            }
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
            'SELECT u.id, u.name, u.email, u.password_hash, u.role AS account_role, u.active,
                    bm.business_id, bm.role AS membership_role
             FROM users u
             LEFT JOIN business_memberships bm ON bm.user_id = u.id AND bm.active = 1
             WHERE u.email = :email COLLATE NOCASE
             ORDER BY bm.business_id
             LIMIT 1'
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
        $user['role'] = $user['membership_role'] ?? $user['account_role'];
        unset($user['password_hash'], $user['active'], $user['account_role'], $user['membership_role']);

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
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
        if (isset($user['business_id'])) {
            $_SESSION['business_id'] = (int) $user['business_id'];
        } else {
            unset($_SESSION['business_id']);
        }
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
