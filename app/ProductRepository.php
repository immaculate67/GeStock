<?php
declare(strict_types=1);

final class ProductRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(string $search = '', array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        $where = [];
        $parameters = [];
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
        $sql = 'SELECT p.*, c.name AS category_name, s.name AS supplier_name FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN suppliers s ON s.id = p.supplier_id';
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
        $where = [];
        $parameters = [];
        if ($search !== '') {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $where[] = "(name LIKE :search ESCAPE '\\' COLLATE NOCASE OR sku LIKE :search ESCAPE '\\' COLLATE NOCASE)";
            $parameters['search'] = '%' . $escapedSearch . '%';
        }
        if (isset($filters['category_id']) && ctype_digit((string) $filters['category_id'])) {
            $where[] = 'category_id = :category_id';
            $parameters['category_id'] = (int) $filters['category_id'];
        }
        if (($filters['stock'] ?? '') === 'low') {
            $where[] = 'quantity > 0 AND quantity <= min_stock_level';
        } elseif (($filters['stock'] ?? '') === 'out') {
            $where[] = 'quantity = 0';
        } elseif (($filters['stock'] ?? '') === 'available') {
            $where[] = 'quantity > min_stock_level';
        }
        $sql = 'SELECT COUNT(*) FROM products' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, c.name AS category_name, s.name AS supplier_name FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = :id'
        );
        $statement->execute(['id' => $id]);
        $product = $statement->fetch();

        return $product === false ? null : $product;
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE sku = :sku COLLATE NOCASE';
        $parameters = ['sku' => $sku];

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
        $statement = $this->pdo->prepare(
            'INSERT INTO products (name, sku, description, price_cents, cost_price_cents, quantity, min_stock_level, category_id, supplier_id)
             VALUES (:name, :sku, :description, :price_cents, :cost_price_cents, :quantity, :min_stock_level, :category_id, :supplier_id)'
        );
        $statement->execute($this->completeProduct($product));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $product): bool
    {
        $product = $this->completeProduct($product);
        $product['id'] = $id;
        $product['updated_at'] = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'UPDATE products
             SET name = :name, sku = :sku, description = :description,
                 price_cents = :price_cents, cost_price_cents = :cost_price_cents,
                 category_id = :category_id, supplier_id = :supplier_id, quantity = :quantity,
                 min_stock_level = :min_stock_level, updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute($product);

        return $statement->rowCount() > 0 || $this->find($id) !== null;
    }

    public function delete(int $id): bool
    {
        $history = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM sale_items WHERE product_id = :id)
                + (SELECT COUNT(*) FROM stock_movements WHERE product_id = :id)'
        );
        $history->execute(['id' => $id]);
        if ((int) $history->fetchColumn() > 0) {
            throw new DomainException('Este produto tem histórico de vendas ou stock e não pode ser eliminado.');
        }
        $statement = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);

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
                COALESCE(SUM(CASE WHEN quantity > min_stock_level THEN 1 ELSE 0 END), 0) AS in_stock
             FROM products'
        );

        $summary = $statement->fetch();
        $inventoryValue = 0;
        foreach ($this->pdo->query('SELECT quantity, cost_price_cents FROM products') as $product) {
            $quantity = (int) $product['quantity'];
            $cost = (int) $product['cost_price_cents'];
            if ($quantity > 0 && $cost > intdiv(PHP_INT_MAX, $quantity)) {
                throw new OverflowException('O valor do inventário excede o limite numérico suportado.');
            }
            $lineValue = $quantity * $cost;
            if ($lineValue > PHP_INT_MAX - $inventoryValue) {
                throw new OverflowException('O valor do inventário excede o limite numérico suportado.');
            }
            $inventoryValue += $lineValue;
        }
        $summary['inventory_value_cents'] = $inventoryValue;

        return $summary;
    }

    public function dashboardStockItems(int $limit = 5): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, sku, quantity, min_stock_level
             FROM products
             WHERE quantity <= min_stock_level
             ORDER BY
                CASE WHEN quantity = 0 THEN 0
                     WHEN quantity <= min_stock_level THEN 1
                     ELSE 2 END,
                quantity ASC,
                name COLLATE NOCASE
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function categories(): array
    {
        return $this->pdo->query('SELECT id, name FROM categories ORDER BY name COLLATE NOCASE')->fetchAll();
    }

    private function completeProduct(array $product): array
    {
        $product['cost_price_cents'] ??= 0;
        $product['category_id'] = $product['category_id'] ?? null;
        $product['supplier_id'] = $product['supplier_id'] ?? null;

        return $product;
    }
}
