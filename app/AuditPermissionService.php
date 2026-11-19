<?php
declare(strict_types=1);

final class AuditPermissionService
{
    public const PERMISSIONS = [
        'catalog.view', 'catalog.edit',
        'inventory.view', 'inventory.adjust',
        'sales.view', 'sales.create', 'sales.cancel',
        'purchases.view', 'purchases.create', 'purchases.receive',
        'customers.view', 'customers.edit',
        'suppliers.view', 'suppliers.edit',
        'reports.view',
        'users.manage',
        'settings.manage',
        'audit.view',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function can(int $businessId, int $userId, string $role, string $permission): bool
    {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }
        if (in_array($role, ['owner', 'administrator'], true)) {
            return true;
        }

        $statement = $this->pdo->prepare(
            'SELECT granted FROM membership_permissions
             WHERE business_id = :business_id AND user_id = :user_id AND permission_key = :permission'
        );
        $statement->execute([
            'business_id' => $businessId,
            'user_id' => $userId,
            'permission' => $permission,
        ]);
        $granted = $statement->fetchColumn();
        if ($granted !== false) {
            return (bool) $granted;
        }

        return in_array($permission, [
            'catalog.view', 'inventory.view', 'sales.view', 'sales.create',
            'purchases.view', 'customers.view', 'suppliers.view', 'reports.view',
        ], true);
    }

    public function setPermission(int $businessId, int $actorUserId, int $targetUserId, string $permission, bool $granted): void
    {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            throw new InvalidArgumentException('A permissão solicitada não é válida.');
        }
        $membership = $this->pdo->prepare(
            'SELECT role FROM business_memberships
             WHERE business_id = :business_id AND user_id = :user_id AND active = 1'
        );
        $membership->execute(['business_id' => $businessId, 'user_id' => $targetUserId]);
        $targetRole = $membership->fetchColumn();
        if (!is_string($targetRole) || in_array($targetRole, ['owner', 'administrator'], true)) {
            throw new DomainException('As permissões dos proprietários e administradores não podem ser reduzidas.');
        }
        $actor = $this->pdo->prepare(
            "SELECT role FROM business_memberships
             WHERE business_id = :business_id AND user_id = :user_id
               AND active = 1 AND role IN ('owner','administrator')"
        );
        $actor->execute(['business_id' => $businessId, 'user_id' => $actorUserId]);
        if ($actor->fetchColumn() === false) {
            throw new DomainException('Não tem autorização para alterar permissões.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO membership_permissions
             (business_id, user_id, permission_key, granted, updated_by)
             VALUES (:business_id, :user_id, :permission, :granted, :actor)
             ON CONFLICT(business_id, user_id, permission_key)
             DO UPDATE SET granted = excluded.granted, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'business_id' => $businessId,
            'user_id' => $targetUserId,
            'permission' => $permission,
            'granted' => (int) $granted,
            'actor' => $actorUserId,
        ]);
    }

    public function branchIds(int $businessId, int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT branch_id FROM membership_branches
             WHERE business_id = :business_id AND user_id = :user_id'
        );
        $statement->execute(['business_id' => $businessId, 'user_id' => $userId]);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        return $ids;
    }

    public function setBranchAccess(int $businessId, int $actorUserId, int $targetUserId, array $branchIds): void
    {
        $actor = $this->pdo->prepare(
            "SELECT role FROM business_memberships
             WHERE business_id = :business_id AND user_id = :user_id
               AND active = 1 AND role IN ('owner','administrator')"
        );
        $actor->execute(['business_id' => $businessId, 'user_id' => $actorUserId]);
        if ($actor->fetchColumn() === false) {
            throw new DomainException('Não tem autorização para alterar locais de acesso.');
        }
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM membership_branches WHERE business_id = :business_id AND user_id = :user_id'
            );
            $delete->execute(['business_id' => $businessId, 'user_id' => $targetUserId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO membership_branches (business_id, user_id, branch_id)
                 VALUES (:business_id, :user_id, :branch_id)'
            );
            foreach (array_values(array_unique(array_map('intval', $branchIds))) as $branchId) {
                $valid = $this->pdo->prepare(
                    'SELECT 1 FROM business_branches WHERE id = :branch_id AND business_id = :business_id'
                );
                $valid->execute(['branch_id' => $branchId, 'business_id' => $businessId]);
                if ($valid->fetchColumn() === false) {
                    throw new DomainException('O local selecionado não pertence ao negócio ativo.');
                }
                $insert->execute([
                    'business_id' => $businessId,
                    'user_id' => $targetUserId,
                    'branch_id' => $branchId,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function record(int $businessId, int $actorUserId, string $action, string $entityType, ?int $entityId, mixed $before = null, mixed $after = null): int
    {
        $encode = static fn(mixed $value): ?string => $value === null
            ? null
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events
             (business_id, actor_user_id, action, entity_type, entity_id, before_json, after_json, ip_address)
             VALUES (:business_id, :actor, :action, :entity_type, :entity_id, :before_json, :after_json, :ip_address)'
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $statement->execute([
            'business_id' => $businessId,
            'actor' => $actorUserId > 0 ? $actorUserId : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $encode($before),
            'after_json' => $encode($after),
            'ip_address' => is_string($ip) ? substr($ip, 0, 45) : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function auditSearch(int $businessId, string $search = '', ?string $action = null, int $limit = 100): array
    {
        $sql = 'SELECT ae.*, u.name AS actor_name
                FROM audit_events ae LEFT JOIN users u ON u.id = ae.actor_user_id
                WHERE ae.business_id = :business_id';
        $parameters = ['business_id' => $businessId];
        if ($search !== '') {
            $sql .= ' AND (ae.entity_type LIKE :search OR ae.action LIKE :search OR ae.before_json LIKE :search OR ae.after_json LIKE :search)';
            $parameters['search'] = '%' . $search . '%';
        }
        if ($action !== null && $action !== '') {
            $sql .= ' AND ae.action = :action';
            $parameters['action'] = $action;
        }
        $sql .= ' ORDER BY ae.created_at DESC, ae.id DESC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', min(500, max(1, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}
