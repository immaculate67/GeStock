<?php
declare(strict_types=1);

final class PurchaseService
{
    public function __construct(private readonly PDO $pdo, private readonly int $businessId)
    {
    }

    public function all(string $status = '', string $search = ''): array
    {
        $sql = 'SELECT po.*, s.name AS supplier_name
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                WHERE po.business_id = :business_id';
        $parameters = ['business_id' => $this->businessId];
        if (in_array($status, ['draft', 'ordered', 'received', 'partially_received', 'cancelled', 'closed'], true)) {
            $sql .= ' AND po.status = :status';
            $parameters['status'] = $status;
        }
        if ($search !== '') {
            $sql .= ' AND (po.order_number LIKE :search OR s.name LIKE :search)';
            $parameters['search'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY po.created_at DESC, po.id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function find(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT po.*, s.name AS supplier_name
             FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
             WHERE po.id = :id AND po.business_id = :business_id'
        );
        $statement->execute(['id' => $orderId, 'business_id' => $this->businessId]);
        $order = $statement->fetch();
        if ($order === false) {
            return null;
        }
        $items = $this->pdo->prepare(
            'SELECT poi.*, p.name AS product_name, pv.identifier AS variant_name
             FROM purchase_order_items poi
             JOIN products p ON p.id = poi.product_id
             LEFT JOIN product_variants pv ON pv.id = poi.variant_id
             WHERE poi.purchase_order_id = :order_id AND poi.business_id = :business_id
             ORDER BY poi.id'
        );
        $items->execute(['order_id' => $orderId, 'business_id' => $this->businessId]);
        $order['items'] = $items->fetchAll();
        return $order;
    }

    public function create(array $input, int $actorUserId): int
    {
        $supplierId = $this->validReference('suppliers', $input['supplier_id'] ?? null);
        $orderNumber = trim((string) ($input['order_number'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        if ($orderNumber === '' || mb_strlen($orderNumber) > 80) {
            throw new InvalidArgumentException('Indique uma referência válida para a encomenda.');
        }
        $branchId = $this->optionalReference('business_branches', $input['branch_id'] ?? null);
        $productIds = $input['product_id'] ?? [];
        $variantIds = $input['variant_id'] ?? [];
        $quantities = $input['quantity'] ?? [];
        $costs = $input['unit_cost'] ?? [];
        if (!is_array($productIds) || !is_array($variantIds) || !is_array($quantities) || !is_array($costs)) {
            throw new InvalidArgumentException('As linhas da encomenda não são válidas.');
        }
        $lineCount = count($productIds);
        if ($lineCount === 0 || $lineCount !== count($quantities) || $lineCount !== count($costs)) {
            throw new InvalidArgumentException('Adicione pelo menos uma linha válida à encomenda.');
        }

        $this->pdo->beginTransaction();
        try {
            $order = $this->pdo->prepare(
                'INSERT INTO purchase_orders
                 (business_id, branch_id, supplier_id, order_number, status, subtotal_cents, created_by, notes)
                 VALUES (:business_id, :branch_id, :supplier_id, :order_number, \'ordered\', 0, :actor, :notes)'
            );
            $order->execute([
                'business_id' => $this->businessId,
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
                'order_number' => $orderNumber,
                'actor' => $actorUserId > 0 ? $actorUserId : null,
                'notes' => $notes === '' ? null : mb_substr($notes, 0, 2000),
            ]);
            $order->closeCursor();
            $orderId = (int) $this->pdo->lastInsertId();
            $subtotal = 0;
            $insert = $this->pdo->prepare(
                'INSERT INTO purchase_order_items
                 (business_id, purchase_order_id, product_id, variant_id, quantity, unit_cost_cents, line_total_cents)
                 VALUES (:business_id, :order_id, :product_id, :variant_id, :quantity, :cost, :line_total)'
            );
            for ($index = 0; $index < $lineCount; $index++) {
                $productId = $this->validReference('products', $productIds[$index] ?? null);
                $variantValue = $variantIds[$index] ?? null;
                $variantId = null;
                if ($variantValue !== null && $variantValue !== '') {
                    $variantId = $this->validReference('product_variants', $variantValue);
                    $check = $this->pdo->prepare(
                        'SELECT 1 FROM product_variants WHERE id = :variant_id AND product_id = :product_id AND business_id = :business_id'
                    );
                    $check->execute([
                        'variant_id' => $variantId,
                        'product_id' => $productId,
                        'business_id' => $this->businessId,
                    ]);
                    if ($check->fetchColumn() === false) {
                        throw new DomainException('A variante não pertence ao produto indicado.');
                    }
                }
                $quantity = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $cost = $this->eurosToCents($costs[$index] ?? null);
                if ($quantity === false) {
                    throw new InvalidArgumentException('Todas as quantidades têm de ser números inteiros positivos.');
                }
                $lineTotal = (int) $quantity * $cost;
                if ($lineTotal > 2147483647) {
                    throw new InvalidArgumentException('O total de uma linha excede o limite permitido.');
                }
                $insert->execute([
                    'business_id' => $this->businessId,
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => (int) $quantity,
                    'cost' => $cost,
                    'line_total' => $lineTotal,
                ]);
                $insert->closeCursor();
                $subtotal += $lineTotal;
            }
            if ($subtotal > 2147483647) {
                throw new InvalidArgumentException('O total da encomenda excede o limite permitido.');
            }
            $update = $this->pdo->prepare(
                'UPDATE purchase_orders SET subtotal_cents = :subtotal WHERE id = :id AND business_id = :business_id'
            );
            $update->execute(['subtotal' => $subtotal, 'id' => $orderId, 'business_id' => $this->businessId]);
            $update->closeCursor();
            unset($order, $insert, $update);
            $this->pdo->commit();
            return $orderId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function receive(int $orderId, int $actorUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->pdo->prepare(
                "SELECT id, status FROM purchase_orders
                 WHERE id = :id AND business_id = :business_id"
            );
            $order->execute(['id' => $orderId, 'business_id' => $this->businessId]);
            $row = $order->fetch();
            if ($row === false) {
                throw new DomainException('A encomenda não pertence ao negócio ativo.');
            }
            if (!in_array($row['status'], ['ordered', 'partially_received'], true)) {
                throw new DomainException('Esta encomenda já não pode ser recebida.');
            }
            $items = $this->pdo->prepare(
                'SELECT * FROM purchase_order_items
                 WHERE purchase_order_id = :order_id AND business_id = :business_id'
            );
            $items->execute(['order_id' => $orderId, 'business_id' => $this->businessId]);
            $movement = $this->pdo->prepare(
                'INSERT INTO stock_movements
                 (business_id, product_id, variant_id, movement_type, quantity, stock_before, stock_after, reason, created_by)
                 VALUES (:business_id, :product_id, :variant_id, \'in\', :quantity, :before, :after, :reason, :actor)'
            );
            foreach ($items->fetchAll() as $item) {
                $quantity = (int) $item['quantity'];
                if ($item['variant_id'] !== null) {
                    $selectVariant = $this->pdo->prepare(
                        'SELECT quantity, cost_price_cents FROM product_variants
                         WHERE id = :id AND business_id = :business_id'
                    );
                    $selectVariant->execute(['id' => (int) $item['variant_id'], 'business_id' => $this->businessId]);
                    $variant = $selectVariant->fetch();
                    if ($variant === false) {
                        throw new DomainException('A variante recebida já não existe.');
                    }
                    $before = (int) $variant['quantity'];
                    $after = $before + $quantity;
                    if ($after > 2147483647) {
                        throw new DomainException('O stock da variante excederia o limite permitido.');
                    }
                    $updateVariant = $this->pdo->prepare(
                        'UPDATE product_variants SET quantity = :quantity, cost_price_cents = :cost, updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND business_id = :business_id'
                    );
                    $updateVariant->execute([
                        'quantity' => $after,
                        'cost' => (int) $item['unit_cost_cents'],
                        'id' => (int) $item['variant_id'],
                        'business_id' => $this->businessId,
                    ]);
                    $movement->execute([
                        'business_id' => $this->businessId,
                        'product_id' => (int) $item['product_id'],
                        'variant_id' => (int) $item['variant_id'],
                        'quantity' => $quantity,
                        'before' => $before,
                        'after' => $after,
                        'reason' => 'Receção da encomenda #' . $orderId,
                        'actor' => $actorUserId > 0 ? $actorUserId : null,
                    ]);
                } else {
                    $selectProduct = $this->pdo->prepare(
                        'SELECT quantity FROM products WHERE id = :id AND business_id = :business_id'
                    );
                    $selectProduct->execute(['id' => (int) $item['product_id'], 'business_id' => $this->businessId]);
                    $before = (int) $selectProduct->fetchColumn();
                    $after = $before + $quantity;
                    if ($after > 2147483647) {
                        throw new DomainException('O stock do produto excederia o limite permitido.');
                    }
                    $updateProduct = $this->pdo->prepare(
                        'UPDATE products SET quantity = :quantity, cost_price_cents = :cost, updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND business_id = :business_id'
                    );
                    $updateProduct->execute([
                        'quantity' => $after,
                        'cost' => (int) $item['unit_cost_cents'],
                        'id' => (int) $item['product_id'],
                        'business_id' => $this->businessId,
                    ]);
                    $movement->execute([
                        'business_id' => $this->businessId,
                        'product_id' => (int) $item['product_id'],
                        'variant_id' => null,
                        'quantity' => $quantity,
                        'before' => $before,
                        'after' => $after,
                        'reason' => 'Receção da encomenda #' . $orderId,
                        'actor' => $actorUserId > 0 ? $actorUserId : null,
                    ]);
                }
            }
            $complete = $this->pdo->prepare(
                "UPDATE purchase_orders
                 SET status = 'received', received_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND business_id = :business_id"
            );
            $complete->execute(['id' => $orderId, 'business_id' => $this->businessId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function validReference(string $table, mixed $value): int
    {
        $id = validRecordId(is_string($value) ? $value : (string) $value);
        if ($id === null) {
            throw new InvalidArgumentException('Selecione um registo válido.');
        }
        $allowed = ['suppliers', 'products', 'product_variants'];
        if (!in_array($table, $allowed, true)) {
            throw new InvalidArgumentException('Tipo de referência não suportado.');
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = :id AND business_id = :business_id');
        $statement->execute(['id' => $id, 'business_id' => $this->businessId]);
        $exists = $statement->fetchColumn();
        $statement->closeCursor();
        if ($exists === false) {
            throw new DomainException('O registo selecionado não pertence ao negócio ativo.');
        }
        return $id;
    }

    private function optionalReference(string $table, mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        if ($table !== 'business_branches') {
            throw new InvalidArgumentException('Tipo de referência não suportado.');
        }
        $id = validRecordId(is_string($value) ? $value : (string) $value);
        if ($id === null) {
            throw new InvalidArgumentException('Selecione um local válido.');
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM business_branches WHERE id = :id AND business_id = :business_id');
        $statement->execute(['id' => $id, 'business_id' => $this->businessId]);
        $exists = $statement->fetchColumn();
        $statement->closeCursor();
        if ($exists === false) {
            throw new DomainException('O local não pertence ao negócio ativo.');
        }
        return $id;
    }

    private function eurosToCents(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Introduza um custo válido.');
        }
        $euros = trim(str_replace(['.', ' '], ['', ''], (string) $value));
        $euros = str_replace(',', '.', $euros);
        if (!preg_match('/\A\d+(?:\.\d{1,2})?\z/', $euros)) {
            throw new InvalidArgumentException('Introduza um custo válido.');
        }
        [$whole, $decimal] = array_pad(explode('.', $euros, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
        if ($cents > 2147483647) {
            throw new InvalidArgumentException('O custo excede o limite permitido.');
        }
        return $cents;
    }
}
