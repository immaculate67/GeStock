<?php
declare(strict_types=1);

final class BusinessRepository
{
    private const DEFINITIONS = [
        'categories' => [
            'required' => ['name'],
            'fields' => ['name', 'description'],
            'max' => ['name' => 100, 'description' => 500],
        ],
        'suppliers' => [
            'required' => ['name'],
            'fields' => ['name', 'contact_person', 'email', 'phone', 'address', 'tax_number', 'notes'],
            'max' => ['name' => 160, 'contact_person' => 120, 'email' => 254, 'phone' => 40, 'address' => 1000, 'tax_number' => 50, 'notes' => 2000],
        ],
        'clients' => [
            'required' => ['name'],
            'fields' => ['name', 'email', 'phone', 'address', 'tax_number', 'notes'],
            'max' => ['name' => 160, 'email' => 254, 'phone' => 40, 'address' => 1000, 'tax_number' => 50, 'notes' => 2000],
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(string $entity, string $search = ''): array
    {
        $this->definition($entity);
        $sql = 'SELECT * FROM ' . $entity;
        $parameters = [];
        if ($search !== '') {
            $sql .= ' WHERE name LIKE :search ESCAPE \'\\\' COLLATE NOCASE';
            $parameters['search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }
        $sql .= $entity === 'categories' ? ' ORDER BY name COLLATE NOCASE' : ' ORDER BY name COLLATE NOCASE, id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function find(string $entity, int $id): ?array
    {
        $this->definition($entity);
        $statement = $this->pdo->prepare('SELECT * FROM ' . $entity . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function save(string $entity, array $input, ?int $id = null): int
    {
        $definition = $this->definition($entity);
        $values = [];
        foreach ($definition['fields'] as $field) {
            $value = trim(is_string($input[$field] ?? null) ? $input[$field] : '');
            if (in_array($field, $definition['required'], true) && $value === '') {
                throw new InvalidArgumentException('O campo ' . $field . ' é obrigatório.');
            }
            if (mb_strlen($value) > $definition['max'][$field]) {
                throw new InvalidArgumentException('O campo ' . $field . ' excede o limite permitido.');
            }
            if ($field === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Introduza um endereço de email válido.');
            }
            $values[$field] = $value === '' ? null : $value;
        }

        if ($id === null) {
            $columns = implode(', ', array_keys($values));
            $placeholders = implode(', ', array_map(static fn(string $column): string => ':' . $column, array_keys($values)));
            $statement = $this->pdo->prepare('INSERT INTO ' . $entity . ' (' . $columns . ') VALUES (' . $placeholders . ')');
            $statement->execute($values);

            return (int) $this->pdo->lastInsertId();
        }

        $values['id'] = $id;
        $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = :' . $column, $definition['fields']));
        $statement = $this->pdo->prepare('UPDATE ' . $entity . ' SET ' . $sets . ' WHERE id = :id');
        $statement->execute($values);
        if ($statement->rowCount() === 0 && $this->find($entity, $id) === null) {
            throw new RuntimeException('O registo já não existe.');
        }

        return $id;
    }

    public function delete(string $entity, int $id): void
    {
        $this->definition($entity);
        if ($entity === 'categories') {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
            $statement->execute(['id' => $id]);
            if ((int) $statement->fetchColumn() > 0) {
                throw new DomainException('A categoria está associada a produtos. Reatribua-os antes de a eliminar.');
            }
        }
        $statement = $this->pdo->prepare('DELETE FROM ' . $entity . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            throw new RuntimeException('O registo já não existe.');
        }
    }

    private function definition(string $entity): array
    {
        if (!isset(self::DEFINITIONS[$entity])) {
            throw new InvalidArgumentException('Tipo de registo não suportado.');
        }

        return self::DEFINITIONS[$entity];
    }
}
