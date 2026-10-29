<?php
declare(strict_types=1);

final class ProductRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(string $search = ''): array
    {
        if ($search === '') {
            $statement = $this->pdo->query(
                'SELECT * FROM products ORDER BY name COLLATE NOCASE, id'
            );
        } else {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $statement = $this->pdo->prepare(
                "SELECT * FROM products
                 WHERE name LIKE :search ESCAPE '\\' COLLATE NOCASE
                    OR sku LIKE :search ESCAPE '\\' COLLATE NOCASE
                 ORDER BY name COLLATE NOCASE, id"
            );
            $statement->execute(['search' => '%' . $escapedSearch . '%']);
        }

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
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
            'INSERT INTO products (name, sku, description, price_cents, quantity, min_stock_level)
             VALUES (:name, :sku, :description, :price_cents, :quantity, :min_stock_level)'
        );
        $statement->execute($product);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $product): bool
    {
        $product['id'] = $id;
        $product['updated_at'] = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'UPDATE products
             SET name = :name, sku = :sku, description = :description,
                 price_cents = :price_cents, quantity = :quantity,
                 min_stock_level = :min_stock_level, updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute($product);

        return $statement->rowCount() > 0 || $this->find($id) !== null;
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function summary(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                COUNT(*) AS total_products,
                COALESCE(SUM(CASE WHEN quantity > 0 AND quantity <= min_stock_level THEN 1 ELSE 0 END), 0) AS low_stock,
                COALESCE(SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
                COALESCE(SUM(CASE WHEN quantity > min_stock_level THEN 1 ELSE 0 END), 0) AS in_stock
             FROM products'
        );

        return $statement->fetch();
    }

    public function dashboardStockItems(int $limit = 5): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, sku, quantity, min_stock_level
             FROM products
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
}
