<?php
declare(strict_types=1);

final class InventoryService
{
    public function __construct(private readonly PDO $pdo, private readonly int $businessId)
    {
        if ($businessId < 1) {
            throw new InvalidArgumentException('A empresa ativa não é válida.');
        }
    }

    public function createProduct(array $product, ?int $userId = null): int
    {
        $this->pdo->beginTransaction();
        try {
            $initialQuantity = (int) $product['quantity'];
            $product['quantity'] = 0;
            $id = (new ProductRepository($this->pdo, $this->businessId))->create($product);
            if ($initialQuantity > 0) {
                $this->changeStock($id, 'in', $initialQuantity, 'Stock inicial do produto', $userId, null);
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

    public function updateProduct(int $id, array $product, ?int $userId = null): bool
    {
        $repository = new ProductRepository($this->pdo, $this->businessId);
        $this->pdo->beginTransaction();
        try {
            $current = $repository->find($id);
            if ($current === null) {
                $this->pdo->rollBack();
                return false;
            }
            $newQuantity = (int) $product['quantity'];
            $product['quantity'] = (int) $current['quantity'];
            $updated = $repository->update($id, $product);
            if ($updated && $newQuantity !== (int) $current['quantity']) {
                $this->changeStock($id, 'correction', $newQuantity, 'Correção através da edição do produto', $userId, null);
            }
            $this->pdo->commit();

            return $updated;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createSale(?int $clientId, array $lines, ?int $userId = null): int
    {
        if ($lines === []) {
            throw new InvalidArgumentException('Adicione pelo menos um produto à venda.');
        }
        $quantities = [];
        foreach ($lines as $line) {
            $productId = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if ($productId === false || $quantity === false) {
                throw new InvalidArgumentException('Os produtos e quantidades da venda não são válidos.');
            }
            $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
            if ($quantities[$productId] > 2147483647) {
                throw new InvalidArgumentException('A quantidade total de um produto excede o limite suportado.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $clientName = 'Cliente ocasional';
            if ($clientId !== null) {
                $clientQuery = $this->pdo->prepare('SELECT name FROM clients WHERE id = :id AND business_id = :business_id');
                $clientQuery->execute(['id' => $clientId, 'business_id' => $this->businessId]);
                $client = $clientQuery->fetch();
                if ($client === false) {
                    throw new DomainException('O cliente selecionado não existe.');
                }
                $clientName = $client['name'];
            }
            $products = [];
            $total = 0;
            foreach ($quantities as $productId => $quantity) {
                $query = $this->pdo->prepare('SELECT id, name, sku, price_cents, cost_price_cents, quantity FROM products WHERE id = :id AND business_id = :business_id');
                $query->execute(['id' => $productId, 'business_id' => $this->businessId]);
                $product = $query->fetch();
                if ($product === false) {
                    throw new DomainException('Um produto da venda já não existe.');
                }
                if ((int) $product['quantity'] < $quantity) {
                    throw new DomainException('Stock insuficiente para ' . $product['name'] . '. Disponível: ' . $product['quantity'] . '.');
                }
                if ((int) $product['price_cents'] > intdiv(PHP_INT_MAX, $quantity)) {
                    throw new DomainException('O total da venda excede o limite suportado.');
                }
                $lineTotal = (int) $product['price_cents'] * $quantity;
                if ($lineTotal > PHP_INT_MAX - $total) {
                    throw new DomainException('O total da venda excede o limite suportado.');
                }
                $total += $lineTotal;
                $products[$productId] = [$product, $quantity, $lineTotal];
            }

            $saleNumber = 'GS-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $sale = $this->pdo->prepare(
                'INSERT INTO sales (business_id, sale_number, client_id, client_name_snapshot, total_cents, created_by)
                 VALUES (:business_id, :sale_number, :client_id, :client_name, :total, :user_id)'
            );
            $sale->execute([
                'business_id' => $this->businessId,
                'sale_number' => $saleNumber,
                'client_id' => $clientId,
                'client_name' => $clientName,
                'total' => $total,
                'user_id' => $userId,
            ]);
            $saleId = (int) $this->pdo->lastInsertId();
            $insertItem = $this->pdo->prepare(
                'INSERT INTO sale_items (business_id, sale_id, product_id, product_name_snapshot, sku_snapshot, quantity, unit_price_cents, line_total_cents, unit_cost_cents)
                 VALUES (:business_id, :sale_id, :product_id, :name, :sku, :quantity, :price, :line_total, :unit_cost)'
            );
            foreach ($products as $productId => [$product, $quantity, $lineTotal]) {
                $before = (int) $product['quantity'];
                $this->changeStock($productId, 'sale', $quantity, 'Venda ' . $saleNumber, $userId, $saleId);
                $insertItem->execute([
                    'business_id' => $this->businessId,
                    'sale_id' => $saleId,
                    'product_id' => $productId,
                    'name' => $product['name'],
                    'sku' => $product['sku'],
                    'quantity' => $quantity,
                    'price' => $product['price_cents'],
                    'line_total' => $lineTotal,
                    'unit_cost' => $product['cost_price_cents'],
                ]);
            }
            $this->pdo->commit();

            return $saleId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelSale(int $saleId, ?int $userId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM sales WHERE id = :id AND business_id = :business_id');
            $query->execute(['id' => $saleId, 'business_id' => $this->businessId]);
            $sale = $query->fetch();
            if ($sale === false || $sale['status'] !== 'completed') {
                throw new DomainException('A venda não existe ou já foi anulada.');
            }
            $items = $this->pdo->prepare('SELECT product_id, quantity FROM sale_items WHERE sale_id = :id AND business_id = :business_id');
            $items->execute(['id' => $saleId, 'business_id' => $this->businessId]);
            $cancel = $this->pdo->prepare(
                'UPDATE sales SET status = \'cancelled\', cancelled_at = CURRENT_TIMESTAMP, cancelled_by = :user_id
                 WHERE id = :id AND business_id = :business_id AND status = \'completed\''
            );
            $cancel->execute(['id' => $saleId, 'business_id' => $this->businessId, 'user_id' => $userId]);
            if ($cancel->rowCount() !== 1) {
                throw new DomainException('A venda já foi anulada.');
            }

            foreach ($items->fetchAll() as $item) {
                $this->changeStock(
                    (int) $item['product_id'],
                    'sale_return',
                    (int) $item['quantity'],
                    'Anulação da venda ' . $sale['sale_number'],
                    $userId,
                    $saleId
                );
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function adjustStock(int $productId, string $type, int $quantity, string $reason, ?int $userId = null): void
    {
        if (!in_array($type, ['in', 'out', 'correction'], true) || $quantity < 0 || $quantity > 2147483647) {
            throw new InvalidArgumentException('O tipo ou a quantidade do movimento não são válidos.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Indique um motivo com até 500 caracteres.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->changeStock($productId, $type, $quantity, $reason, $userId, null);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function sales(string $search = '', string $from = '', string $to = '', int $limit = 100, string $status = '', int $offset = 0): array
    {
        $where = [];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(sale_number LIKE :search OR client_name_snapshot LIKE :search)';
            $parameters['search'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
        }
        if ($this->validDate($from)) {
            $where[] = 'created_at >= :from_date';
            $parameters['from_date'] = BusinessCalendar::startOfDayUtc($from);
        }
        if ($this->validDate($to)) {
            $where[] = 'created_at < :to_date';
            $parameters['to_date'] = BusinessCalendar::startOfNextDayUtc($to);
        }
        if (in_array($status, ['completed', 'cancelled'], true)) {
            $where[] = 'status = :status';
            $parameters['status'] = $status;
        }
        $where[] = 'business_id = :business_id';
        $parameters['business_id'] = $this->businessId;
        $sql = 'SELECT * FROM sales WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function salesCount(string $search = '', string $from = '', string $to = '', string $status = ''): int
    {
        $where = [];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(sale_number LIKE :search OR client_name_snapshot LIKE :search)';
            $parameters['search'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
        }
        if ($this->validDate($from)) {
            $where[] = 'created_at >= :from_date';
            $parameters['from_date'] = BusinessCalendar::startOfDayUtc($from);
        }
        if ($this->validDate($to)) {
            $where[] = 'created_at < :to_date';
            $parameters['to_date'] = BusinessCalendar::startOfNextDayUtc($to);
        }
        if (in_array($status, ['completed', 'cancelled'], true)) {
            $where[] = 'status = :status';
            $parameters['status'] = $status;
        }
        $where[] = 'business_id = :business_id';
        $parameters['business_id'] = $this->businessId;
        $sql = 'SELECT COUNT(*) FROM sales WHERE ' . implode(' AND ', $where);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function sale(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sales WHERE id = :id AND business_id = :business_id');
        $statement->execute(['id' => $id, 'business_id' => $this->businessId]);
        $sale = $statement->fetch();
        if ($sale === false) {
            return null;
        }
        $items = $this->pdo->prepare('SELECT * FROM sale_items WHERE sale_id = :id AND business_id = :business_id ORDER BY id');
        $items->execute(['id' => $id, 'business_id' => $this->businessId]);
        $sale['items'] = $items->fetchAll();

        return $sale;
    }

    public function movements(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        [$where, $parameters] = $this->movementFilter($filters);
        $where[] = 'm.business_id = :business_id';
        $parameters['business_id'] = $this->businessId;
        $sql = 'SELECT m.*, p.name AS product_name, p.sku FROM stock_movements m
                JOIN products p ON p.id = m.product_id' .
            ' WHERE ' . implode(' AND ', $where) . ' ORDER BY m.created_at DESC, m.id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function movementCount(array $filters = []): int
    {
        [$where, $parameters] = $this->movementFilter($filters);
        $where[] = 'm.business_id = :business_id';
        $parameters['business_id'] = $this->businessId;
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM stock_movements m WHERE ' . implode(' AND ', $where));
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function dashboard(): array
    {
        return (new BusinessStatisticsService($this->pdo, $this->businessId))->dashboard();
    }

    public function reportSummary(string $from, string $to): array
    {
        $report = (new BusinessStatisticsService($this->pdo, $this->businessId))->reportSummary($from, $to);
        $report['inventory_value_cents'] = $report['inventory']['inventory_value_cents'];
        $report['movements'] = $this->movements(['from' => $from, 'to' => $to], 100);

        return $report;
    }

    private function changeStock(int $productId, string $type, int $quantity, string $reason, ?int $userId, ?int $saleId): void
    {
        $query = $this->pdo->prepare('SELECT quantity FROM products WHERE id = :id AND business_id = :business_id');
        $query->execute(['id' => $productId, 'business_id' => $this->businessId]);
        $found = $query->fetchColumn();
        if ($found === false) {
            throw new DomainException('O produto indicado não existe.');
        }
        $before = (int) $found;
        if ($type === 'in' || $type === 'sale_return') {
            $after = $before + $quantity;
            $movementQuantity = $quantity;
        } elseif ($type === 'correction') {
            $after = $quantity;
            $movementQuantity = abs($after - $before);
            if ($movementQuantity === 0) {
                throw new DomainException('A quantidade de correção é igual ao stock atual.');
            }
        } else {
            if ($quantity > $before) {
                throw new DomainException('Stock insuficiente para este movimento.');
            }
            $after = $before - $quantity;
            $movementQuantity = $quantity;
        }
        if ($after > 2147483647 || $movementQuantity < 1) {
            throw new DomainException('A quantidade final de stock não é válida.');
        }
        $update = $this->pdo->prepare(
            'UPDATE products SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND business_id = :business_id AND quantity = :before'
        );
        $update->execute(['quantity' => $after, 'id' => $productId, 'business_id' => $this->businessId, 'before' => $before]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('O stock foi alterado entretanto. Atualize os dados e tente novamente.');
        }
        $movement = $this->pdo->prepare(
            'INSERT INTO stock_movements (business_id, product_id, movement_type, quantity, stock_before, stock_after, reason, sale_id, created_by)
             VALUES (:business_id, :product_id, :type, :quantity, :before, :after, :reason, :sale_id, :user_id)'
        );
        $movement->execute([
            'business_id' => $this->businessId,
            'product_id' => $productId,
            'type' => $type,
            'quantity' => $movementQuantity,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'sale_id' => $saleId,
            'user_id' => $userId,
        ]);
    }

    private function movementFilter(array $filters): array
    {
        $where = [];
        $parameters = [];
        if (isset($filters['product_id']) && ctype_digit((string) $filters['product_id'])) {
            $where[] = 'm.product_id = :product_id';
            $parameters['product_id'] = (int) $filters['product_id'];
        }
        if (in_array($filters['type'] ?? '', ['in', 'out', 'correction', 'sale', 'sale_return'], true)) {
            $where[] = 'm.movement_type = :type';
            $parameters['type'] = $filters['type'];
        }
        if ($this->validDate($filters['from'] ?? '')) {
            $where[] = 'm.created_at >= :from_date';
            $parameters['from_date'] = BusinessCalendar::startOfDayUtc($filters['from']);
        }
        if ($this->validDate($filters['to'] ?? '')) {
            $where[] = 'm.created_at < :to_date';
            $parameters['to_date'] = BusinessCalendar::startOfNextDayUtc($filters['to']);
        }

        return [$where, $parameters];
    }

    private function validDate(string $date): bool
    {
        return BusinessCalendar::validDate($date);
    }
}
