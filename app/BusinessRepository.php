<?php
declare(strict_types=1);

final class BusinessRepository
{
    private const FIELD_LABELS = [
        'name' => 'nome',
        'description' => 'descrição',
        'parent_id' => 'categoria superior',
        'contact_person' => 'pessoa de contacto',
        'email' => 'email',
        'phone' => 'telefone',
        'address' => 'morada',
        'tax_number' => 'NIF',
        'notes' => 'notas',
    ];

    private const DEFINITIONS = [
        'categories' => [
            'required' => ['name'],
            'fields' => ['name', 'description', 'parent_id'],
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

    public function __construct(private readonly PDO $pdo, private readonly int $businessId)
    {
        if ($businessId < 1) {
            throw new InvalidArgumentException('A empresa ativa não é válida.');
        }
    }

    public function all(string $entity, string $search = '', ?int $limit = null, int $offset = 0): array
    {
        $this->definition($entity);
        $sql = $entity === 'categories'
            ? 'SELECT c.*, p.name AS parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.business_id = :business_id'
            : 'SELECT * FROM ' . $entity . ' WHERE business_id = :business_id';
        [$where, $parameters] = $this->searchFilter($search);
        $parameters['business_id'] = $this->businessId;
        $sql .= $where === '' ? '' : ' AND ' . substr($where, 7);
        $sql .= $entity === 'categories'
            ? ' ORDER BY COALESCE(parent_name, \'\') COLLATE NOCASE, name COLLATE NOCASE'
            : ' ORDER BY name COLLATE NOCASE, id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        if ($limit !== null) {
            $statement->bindValue('limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
            $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    public function count(string $entity, string $search = ''): int
    {
        $this->definition($entity);
        [$where, $parameters] = $this->searchFilter($search);
        $parameters['business_id'] = $this->businessId;
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $entity . ' WHERE business_id = :business_id' . ($where === '' ? '' : ' AND ' . substr($where, 7)));
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function find(string $entity, int $id): ?array
    {
        $this->definition($entity);
        $statement = $this->pdo->prepare('SELECT * FROM ' . $entity . ' WHERE id = :id AND business_id = :business_id');
        $statement->execute(['id' => $id, 'business_id' => $this->businessId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function save(string $entity, array $input, ?int $id = null): int
    {
        $definition = $this->definition($entity);
        $values = [];
        foreach ($definition['fields'] as $field) {
            if ($field === 'parent_id') {
                $rawParentId = $input[$field] ?? '';
                if ($rawParentId === '' || $rawParentId === null) {
                    $values[$field] = null;
                    continue;
                }
                if ((!is_int($rawParentId) && (!is_string($rawParentId) || preg_match('/\A[1-9][0-9]*\z/', $rawParentId) !== 1))
                    || filter_var($rawParentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new InvalidArgumentException('Selecione uma categoria superior válida.');
                }
                $parentId = (int) $rawParentId;
                if ($id === $parentId || $this->find('categories', $parentId) === null) {
                    throw new InvalidArgumentException('Selecione uma categoria superior do mesmo negócio.');
                }
                if ($id !== null) {
                    $descendants = $this->pdo->prepare(
                        'WITH RECURSIVE descendants(id) AS (
                            SELECT id FROM categories WHERE parent_id = :category_id AND business_id = :business_id
                            UNION ALL
                            SELECT child.id FROM categories child
                            JOIN descendants parent ON child.parent_id = parent.id
                            WHERE child.business_id = :business_id
                        )
                        SELECT 1 FROM descendants WHERE id = :parent_id LIMIT 1'
                    );
                    $descendants->execute([
                        'category_id' => $id,
                        'business_id' => $this->businessId,
                        'parent_id' => $parentId,
                    ]);
                    if ($descendants->fetchColumn() !== false) {
                        throw new InvalidArgumentException('A categoria superior selecionada criaria um ciclo.');
                    }
                }
                $values[$field] = $parentId;
                continue;
            }
            $value = trim(is_string($input[$field] ?? null) ? $input[$field] : '');
            if (in_array($field, $definition['required'], true) && $value === '') {
                throw new InvalidArgumentException('O campo ' . self::FIELD_LABELS[$field] . ' é obrigatório.');
            }
            if (mb_strlen($value) > $definition['max'][$field]) {
                throw new InvalidArgumentException('O campo ' . self::FIELD_LABELS[$field] . ' excede o limite permitido.');
            }
            if ($field === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Introduza um endereço de email válido.');
            }
            $values[$field] = $value === '' ? null : $value;
        }

        if ($id === null) {
            $columns = implode(', ', array_keys($values));
            $placeholders = implode(', ', array_map(static fn(string $column): string => ':' . $column, array_keys($values)));
            $values = ['business_id' => $this->businessId] + $values;
            $columns = 'business_id, ' . $columns;
            $placeholders = ':business_id, ' . $placeholders;
            $statement = $this->pdo->prepare('INSERT INTO ' . $entity . ' (' . $columns . ') VALUES (' . $placeholders . ')');
            $statement->execute($values);

            return (int) $this->pdo->lastInsertId();
        }

        $values['id'] = $id;
        $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = :' . $column, $definition['fields']));
        $statement = $this->pdo->prepare('UPDATE ' . $entity . ' SET ' . $sets . ' WHERE id = :id AND business_id = :business_id');
        $values['business_id'] = $this->businessId;
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
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id AND business_id = :business_id');
            $statement->execute(['id' => $id, 'business_id' => $this->businessId]);
            if ((int) $statement->fetchColumn() > 0) {
                throw new DomainException('A categoria está associada a produtos. Reatribua-os antes de a eliminar.');
            }
            $children = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = :id AND business_id = :business_id');
            $children->execute(['id' => $id, 'business_id' => $this->businessId]);
            if ((int) $children->fetchColumn() > 0) {
                throw new DomainException('A categoria tem subcategorias. Reatribua-as antes de a eliminar.');
            }
        }
        $statement = $this->pdo->prepare('DELETE FROM ' . $entity . ' WHERE id = :id AND business_id = :business_id');
        $statement->execute(['id' => $id, 'business_id' => $this->businessId]);
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

    private function searchFilter(string $search): array
    {
        if ($search === '') {
            return ['', []];
        }

        return [
            ' WHERE name LIKE :search ESCAPE \'\\\' COLLATE NOCASE',
            ['search' => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%'],
        ];
    }
}
