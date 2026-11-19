<?php
declare(strict_types=1);

final class ProductRepository
{
    public function __construct(private readonly PDO $pdo, private readonly int $businessId)
    {
        if ($businessId < 1) {
            throw new InvalidArgumentException('A empresa ativa não é válida.');
        }
    }

    public function all(string $search = '', array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        $where = ['p.business_id = :business_id'];
        $parameters = ['business_id' => $this->businessId];
        if ($search !== '') {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $where[] = "(p.name LIKE :search ESCAPE '\\' COLLATE NOCASE OR p.sku LIKE :search ESCAPE '\\' COLLATE NOCASE)";
            $parameters['search'] = '%' . $escapedSearch . '%';
        }
        if (isset($filters['category_id']) && ctype_digit((string) $filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $parameters['category_id'] = (int) $filters['category_id'];
        }
        if (($filters['stock'] ?? '') === 'low') {
            $where[] = 'p.quantity > 0 AND p.quantity <= p.min_stock_level';
        } elseif (($filters['stock'] ?? '') === 'out') {
            $where[] = 'p.quantity = 0';
        } elseif (($filters['stock'] ?? '') === 'available') {
            $where[] = 'p.quantity > p.min_stock_level';
        }
        $sorts = [
            'name' => 'p.name COLLATE NOCASE',
            'sku' => 'p.sku COLLATE NOCASE',
            'price' => 'p.price_cents',
            'quantity' => 'p.quantity',
            'created' => 'p.created_at',
        ];
        $sort = $sorts[$filters['sort'] ?? 'name'] ?? $sorts['name'];
        $direction = ($filters['direction'] ?? '') === 'desc' ? 'DESC' : 'ASC';
        $sql = 'SELECT p.*, c.name AS category_name, s.name AS supplier_name, t.label AS item_type_label
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                LEFT JOIN item_types t ON t.id = p.item_type_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ' . $sort . ' ' . $direction . ', p.id';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        if ($limit !== null) {
            $statement->bindValue('limit', max(1, min(100, $limit)), PDO::PARAM_INT);
            $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    public function count(string $search = '', array $filters = []): int
    {
        $where = ['p.business_id = :business_id'];
        $parameters = ['business_id' => $this->businessId];
        if ($search !== '') {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $where[] = "(p.name LIKE :search ESCAPE '\\' COLLATE NOCASE OR p.sku LIKE :search ESCAPE '\\' COLLATE NOCASE)";
            $parameters['search'] = '%' . $escapedSearch . '%';
        }
        if (isset($filters['category_id']) && ctype_digit((string) $filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $parameters['category_id'] = (int) $filters['category_id'];
        }
        if (($filters['stock'] ?? '') === 'low') {
            $where[] = 'p.quantity > 0 AND p.quantity <= p.min_stock_level';
        } elseif (($filters['stock'] ?? '') === 'out') {
            $where[] = 'p.quantity = 0';
        } elseif (($filters['stock'] ?? '') === 'available') {
            $where[] = 'p.quantity > p.min_stock_level';
        }
        $sql = 'SELECT COUNT(*) FROM products p WHERE ' . implode(' AND ', $where);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, c.name AS category_name, s.name AS supplier_name, t.label AS item_type_label
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             LEFT JOIN item_types t ON t.id = p.item_type_id
             WHERE p.id = :id AND p.business_id = :business_id'
        );
        $statement->execute(['id' => $id, 'business_id' => $this->businessId]);
        $product = $statement->fetch();

        return $product === false ? null : $product;
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE business_id = :business_id AND sku = :sku COLLATE NOCASE';
        $parameters = ['business_id' => $this->businessId, 'sku' => $sku];

        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $parameters['id'] = $exceptId;
        }

        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function create(array $product): int
    {
        $product = $this->completeProduct($product);
        $this->validateReferences($product);
        $statement = $this->pdo->prepare(
            'INSERT INTO products (business_id, name, sku, description, price_cents, cost_price_cents, quantity, min_stock_level, category_id, supplier_id, item_type_id)
             VALUES (:business_id, :name, :sku, :description, :price_cents, :cost_price_cents, :quantity, :min_stock_level, :category_id, :supplier_id, :item_type_id)'
        );
        $statement->execute(['business_id' => $this->businessId] + $this->completeProduct($product));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $product): bool
    {
        $product = $this->completeProduct($product);
        $this->validateReferences($product);
        $product['id'] = $id;
        $product['business_id'] = $this->businessId;
        $product['updated_at'] = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'UPDATE products
             SET name = :name, sku = :sku, description = :description,
                 price_cents = :price_cents, cost_price_cents = :cost_price_cents,
                 category_id = :category_id, supplier_id = :supplier_id, item_type_id = :item_type_id, quantity = :quantity,
                 min_stock_level = :min_stock_level, updated_at = :updated_at
             WHERE id = :id AND business_id = :business_id'
        );
        $statement->execute($product);

        return $statement->rowCount() > 0 || $this->find($id) !== null;
    }

    public function delete(int $id): bool
    {
        $history = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM sale_items WHERE product_id = :id AND business_id = :business_id)
                + (SELECT COUNT(*) FROM stock_movements WHERE product_id = :id AND business_id = :business_id)'
        );
        $history->execute(['id' => $id, 'business_id' => $this->businessId]);
        if ((int) $history->fetchColumn() > 0) {
            throw new DomainException('Este produto tem histórico de vendas ou stock e não pode ser eliminado.');
        }
        $statement = $this->pdo->prepare('DELETE FROM products WHERE id = :id AND business_id = :business_id');
        $statement->execute(['id' => $id, 'business_id' => $this->businessId]);

        return $statement->rowCount() > 0;
    }

    public function summary(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                COUNT(*) AS total_products,
                COALESCE(SUM(quantity), 0) AS total_quantity,
                COALESCE(SUM(CASE WHEN quantity > 0 AND quantity <= min_stock_level THEN 1 ELSE 0 END), 0) AS low_stock,
                COALESCE(SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
                COALESCE(SUM(CASE WHEN quantity > min_stock_level THEN 1 ELSE 0 END), 0) AS in_stock,
                COALESCE(SUM(CASE WHEN min_stock_level > 0
                    AND quantity > min_stock_level
                    AND quantity - min_stock_level <= MAX(1, CAST((min_stock_level + 4) / 5 AS INTEGER))
                    THEN 1 ELSE 0 END), 0) AS approaching_stock,
                COALESCE(SUM(quantity * cost_price_cents), 0) AS inventory_value_cents,
                COALESCE(SUM(quantity * price_cents), 0) AS potential_sales_value_cents
             FROM products
             WHERE business_id = :business_id'
        );
        $statement->execute(['business_id' => $this->businessId]);

        $summary = $statement->fetch();
        $summary['inventory_value_cents'] = (int) $summary['inventory_value_cents'];
        $summary['potential_sales_value_cents'] = (int) $summary['potential_sales_value_cents'];

        return $summary;
    }

    public function dashboardStockItems(int $limit = 5): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, sku, quantity, min_stock_level
             FROM products
             WHERE business_id = :business_id AND quantity <= min_stock_level
             ORDER BY
                CASE WHEN quantity = 0 THEN 0
                     WHEN quantity <= min_stock_level THEN 1
                     ELSE 2 END,
                quantity ASC,
                name COLLATE NOCASE
             LIMIT :limit'
        );
        $statement->bindValue('business_id', $this->businessId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function approachingStockItems(int $limit = 5): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, sku, quantity, min_stock_level
             FROM products
             WHERE business_id = :business_id AND min_stock_level > 0
               AND quantity > min_stock_level
               AND quantity - min_stock_level <= MAX(1, CAST((min_stock_level + 4) / 5 AS INTEGER))
             ORDER BY quantity - min_stock_level ASC, name COLLATE NOCASE
             LIMIT :limit'
        );
        $statement->bindValue('business_id', $this->businessId, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function categories(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.name, c.parent_id, p.name AS parent_name
             FROM categories c
             LEFT JOIN categories p ON p.id = c.parent_id
             WHERE c.business_id = :business_id
             ORDER BY COALESCE(parent_name, \'\') COLLATE NOCASE, c.name COLLATE NOCASE'
        );
        $statement->execute(['business_id' => $this->businessId]);

        return $statement->fetchAll();
    }

    private function completeProduct(array $product): array
    {
        $product['cost_price_cents'] ??= 0;
        $product['category_id'] = $product['category_id'] ?? null;
        $product['supplier_id'] = $product['supplier_id'] ?? null;
        if (!array_key_exists('item_type_id', $product) || $product['item_type_id'] === null) {
            $defaultType = $this->pdo->prepare(
                "SELECT id FROM item_types
                 WHERE business_id = :business_id AND type_key = 'product' AND enabled = 1"
            );
            $defaultType->execute(['business_id' => $this->businessId]);
            $product['item_type_id'] = $defaultType->fetchColumn();
            $product['item_type_id'] = $product['item_type_id'] === false ? null : (int) $product['item_type_id'];
        }

        return $product;
    }

    private function validateReferences(array $product): void
    {
        if (($product['item_type_id'] ?? null) !== null) {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM item_types WHERE id = :id AND business_id = :business_id AND enabled = 1'
            );
            $statement->execute(['id' => $product['item_type_id'], 'business_id' => $this->businessId]);
            if ($statement->fetchColumn() === false) {
                throw new DomainException('O tipo de artigo selecionado não pertence à empresa ativa ou está inativo.');
            }
        }
        foreach (['category_id' => 'categories', 'supplier_id' => 'suppliers'] as $field => $table) {
            if (($product[$field] ?? null) === null) {
                continue;
            }
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM ' . $table . ' WHERE id = :id AND business_id = :business_id'
            );
            $statement->execute(['id' => $product[$field], 'business_id' => $this->businessId]);
            if ($statement->fetchColumn() === false) {
                throw new DomainException('A categoria ou o fornecedor selecionado não pertence à empresa ativa.');
            }
        }
    }
}
