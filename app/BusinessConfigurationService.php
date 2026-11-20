<?php
declare(strict_types=1);

final class BusinessConfigurationService
{
    public function __construct(private readonly PDO $pdo, private readonly int $businessId)
    {
        if ($businessId < 1) {
            throw new InvalidArgumentException('A empresa ativa não é válida.');
        }
    }

    public function branches(): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, name, code, address, active
             FROM business_branches
             WHERE business_id = :business_id
             ORDER BY active DESC, name COLLATE NOCASE'
        );
        $query->execute(['business_id' => $this->businessId]);

        return $query->fetchAll();
    }

    public function itemTypes(): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, type_key, label, stock_tracked, enabled
             FROM item_types
             WHERE business_id = :business_id
             ORDER BY label COLLATE NOCASE'
        );
        $query->execute(['business_id' => $this->businessId]);

        return $query->fetchAll();
    }

    public function saveBranch(string $name, string $code, string $address = '', ?int $id = null): int
    {
        $name = trim($name);
        $code = strtoupper(trim($code));
        $address = trim($address);
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,39}\z/', $code) !== 1) {
            throw new InvalidArgumentException('Indique um nome e um código de local válidos.');
        }
        if (mb_strlen($address) > 500) {
            throw new InvalidArgumentException('A morada do local não pode exceder 500 caracteres.');
        }

        if ($id === null) {
            $query = $this->pdo->prepare(
                'INSERT INTO business_branches (business_id, name, code, address)
                 VALUES (:business_id, :name, :code, :address)'
            );
            $query->execute([
                'business_id' => $this->businessId,
                'name' => $name,
                'code' => $code,
                'address' => $address === '' ? null : $address,
            ]);
            $branchId = (int) $this->pdo->lastInsertId();
            $location = $this->pdo->prepare(
                'INSERT INTO stock_locations (business_id, branch_id, name, code)
                 VALUES (:business_id, :branch_id, :name, :code)'
            );
            $location->execute([
                'business_id' => $this->businessId,
                'branch_id' => $branchId,
                'name' => 'Stock principal',
                'code' => 'PRINCIPAL',
            ]);
            return $branchId;
        }

        $query = $this->pdo->prepare(
            'UPDATE business_branches
             SET name = :name, code = :code, address = :address
             WHERE id = :id AND business_id = :business_id'
        );
        $query->execute([
            'id' => $id,
            'business_id' => $this->businessId,
            'name' => $name,
            'code' => $code,
            'address' => $address === '' ? null : $address,
        ]);
        if ($query->rowCount() === 0 && $this->findBranch($id) === null) {
            throw new DomainException('O local selecionado não pertence ao negócio ativo.');
        }

        return $id;
    }

    public function setBranchActive(int $id, bool $active): void
    {
        $query = $this->pdo->prepare(
            'UPDATE business_branches SET active = :active
             WHERE id = :id AND business_id = :business_id'
        );
        $query->execute(['id' => $id, 'business_id' => $this->businessId, 'active' => (int) $active]);
        if ($query->rowCount() === 0 && $this->findBranch($id) === null) {
            throw new DomainException('O local selecionado não pertence ao negócio ativo.');
        }
    }

    public function setItemTypeEnabled(int $id, bool $enabled): void
    {
        $query = $this->pdo->prepare(
            'UPDATE item_types SET enabled = :enabled
             WHERE id = :id AND business_id = :business_id'
        );
        $query->execute(['id' => $id, 'business_id' => $this->businessId, 'enabled' => (int) $enabled]);
        if ($query->rowCount() === 0) {
            $type = $this->pdo->prepare('SELECT enabled FROM item_types WHERE id = :id AND business_id = :business_id');
            $type->execute(['id' => $id, 'business_id' => $this->businessId]);
            if ($type->fetchColumn() === false) {
                throw new DomainException('O tipo de artigo selecionado não pertence ao negócio ativo.');
            }
        }
    }

    private function findBranch(int $id): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT id FROM business_branches WHERE id = :id AND business_id = :business_id'
        );
        $query->execute(['id' => $id, 'business_id' => $this->businessId]);
        $branch = $query->fetch();

        return $branch === false ? null : $branch;
    }
}
