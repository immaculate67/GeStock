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

    public function receive(int $orderId, int $actorUserId, ?array $quantities = null): void
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
            $updateItem = $this->pdo->prepare(
                'UPDATE purchase_order_items SET received_quantity = :received
                 WHERE id = :id AND business_id = :business_id'
            );
            $receipt = $this->pdo->prepare(
                'INSERT INTO purchase_receipts
                 (business_id, purchase_order_id, purchase_order_item_id, quantity, unit_cost_cents, created_by)
                 VALUES (:business_id, :order_id, :item_id, :quantity, :cost, :actor)'
            );
            $receivedAny = false;
            $hasRemaining = false;
            foreach ($items->fetchAll() as $item) {
                $lineId = (int) $item['id'];
                $remaining = (int) $item['quantity'] - (int) $item['received_quantity'];
                $requested = $quantities === null ? $remaining : ($quantities[(string) $lineId] ?? $quantities[$lineId] ?? 0);
                $quantity = $requested === null ? 0 : filter_var($requested, FILTER_VALIDATE_INT);
                if ($remaining > 0 && $quantities !== null && $quantity === false) {
                    throw new InvalidArgumentException('As quantidades recebidas têm de ser números inteiros.');
                }
                if ($quantity === false) {
                    $quantity = 0;
                }
                if ($quantity < 0 || $quantity > $remaining) {
                    throw new InvalidArgumentException('A quantidade recebida excede a quantidade em falta.');
                }
                if ($quantity > 0) {
                    $receivedAny = true;
                    $this->addPurchaseReceiptStock(
                        $movement,
                        (int) $item['product_id'],
                        $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                        $quantity,
                        (int) $item['unit_cost_cents'],
                        $orderId,
                        $actorUserId
                    );
                    $newReceived = (int) $item['received_quantity'] + $quantity;
                    $updateItem->execute([
                        'received' => $newReceived,
                        'id' => $lineId,
                        'business_id' => $this->businessId,
                    ]);
                    $receipt->execute([
                        'business_id' => $this->businessId,
                        'order_id' => $orderId,
                        'item_id' => $lineId,
                        'quantity' => $quantity,
                        'cost' => (int) $item['unit_cost_cents'],
                        'actor' => $actorUserId > 0 ? $actorUserId : null,
                    ]);
                }
                if ((int) $item['received_quantity'] + $quantity < (int) $item['quantity']) {
                    $hasRemaining = true;
                }
            }
            if (!$receivedAny) {
                throw new DomainException('Indique pelo menos uma quantidade a receber.');
            }
            $complete = $this->pdo->prepare(
                'UPDATE purchase_orders
                 SET status = :status, received_at = CASE WHEN :status = \'received\' THEN CURRENT_TIMESTAMP ELSE received_at END
                 WHERE id = :id AND business_id = :business_id'
            );
            $complete->execute([
                'status' => $hasRemaining ? 'partially_received' : 'received',
                'id' => $orderId,
                'business_id' => $this->businessId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancel(int $orderId, int $actorUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->pdo->prepare(
                "SELECT status FROM purchase_orders WHERE id = :id AND business_id = :business_id"
            );
            $order->execute(['id' => $orderId, 'business_id' => $this->businessId]);
            $row = $order->fetch();
            if ($row === false) {
                throw new DomainException('A encomenda não pertence ao negócio ativo.');
            }
            if (!in_array($row['status'], ['draft', 'ordered', 'partially_received'], true)) {
                throw new DomainException('Esta encomenda já não pode ser cancelada.');
            }
            $received = $this->pdo->prepare(
                'SELECT COUNT(*) FROM purchase_order_items
                 WHERE purchase_order_id = :order_id AND business_id = :business_id AND received_quantity > 0'
            );
            $received->execute(['order_id' => $orderId, 'business_id' => $this->businessId]);
            if ((int) $received->fetchColumn() > 0) {
                throw new DomainException('Uma encomenda com receções deve ser devolvida linha a linha antes do cancelamento.');
            }
            $cancel = $this->pdo->prepare(
                "UPDATE purchase_orders SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND business_id = :business_id"
            );
            $cancel->execute(['id' => $orderId, 'business_id' => $this->businessId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function returnItems(int $orderId, array $quantities, string $reason, int $actorUserId): void
    {
        $reason = trim($reason);
        $this->pdo->beginTransaction();
        try {
            $order = $this->pdo->prepare(
                "SELECT status FROM purchase_orders WHERE id = :id AND business_id = :business_id"
            );
            $order->execute(['id' => $orderId, 'business_id' => $this->businessId]);
            $row = $order->fetch();
            if ($row === false) {
                throw new DomainException('A encomenda não pertence ao negócio ativo.');
            }
            if (!in_array($row['status'], ['received', 'partially_received'], true)) {
                throw new DomainException('Só podem ser devolvidas encomendas recebidas.');
            }
            $items = $this->pdo->prepare(
                'SELECT * FROM purchase_order_items
                 WHERE purchase_order_id = :order_id AND business_id = :business_id'
            );
            $items->execute(['order_id' => $orderId, 'business_id' => $this->businessId]);
            $movement = $this->pdo->prepare(
                'INSERT INTO stock_movements
                 (business_id, product_id, variant_id, movement_type, quantity, stock_before, stock_after, reason, created_by)
                 VALUES (:business_id, :product_id, :variant_id, \'out\', :quantity, :before, :after, :reason, :actor)'
            );
            $updateItem = $this->pdo->prepare(
                'UPDATE purchase_order_items SET returned_quantity = :returned
                 WHERE id = :id AND business_id = :business_id'
            );
            $return = $this->pdo->prepare(
                'INSERT INTO purchase_returns
                 (business_id, purchase_order_id, purchase_order_item_id, quantity, reason, created_by)
                 VALUES (:business_id, :order_id, :item_id, :quantity, :reason, :actor)'
            );
            $returnedAny = false;
            foreach ($items->fetchAll() as $item) {
                $lineId = (int) $item['id'];
                $requested = $quantities[(string) $lineId] ?? $quantities[$lineId] ?? 0;
                $quantity = filter_var($requested, FILTER_VALIDATE_INT);
                $available = (int) $item['received_quantity'] - (int) $item['returned_quantity'];
                if ($quantity === false) {
                    throw new InvalidArgumentException('As quantidades devolvidas têm de ser números inteiros.');
                }
                if ($quantity < 0 || $quantity > $available) {
                    throw new InvalidArgumentException('A quantidade devolvida excede o recebido líquido.');
                }
                if ($quantity === 0) {
                    continue;
                }
                $returnedAny = true;
                $this->removePurchaseReturnStock(
                    $movement,
                    (int) $item['product_id'],
                    $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                    $quantity,
                    $orderId,
                    $actorUserId
                );
                $updateItem->execute([
                    'returned' => (int) $item['returned_quantity'] + $quantity,
                    'id' => $lineId,
                    'business_id' => $this->businessId,
                ]);
                $return->execute([
                    'business_id' => $this->businessId,
                    'order_id' => $orderId,
                    'item_id' => $lineId,
                    'quantity' => $quantity,
                    'reason' => $reason === '' ? null : mb_substr($reason, 0, 2000),
                    'actor' => $actorUserId > 0 ? $actorUserId : null,
                ]);
            }
            if (!$returnedAny) {
                throw new DomainException('Indique pelo menos uma quantidade a devolver.');
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function addPurchaseReceiptStock(
        PDOStatement $movement,
        int $productId,
        ?int $variantId,
        int $quantity,
        int $cost,
        int $orderId,
        int $actorUserId
    ): void {
        if ($variantId !== null) {
            $select = $this->pdo->prepare(
                'SELECT quantity FROM product_variants
                 WHERE id = :id AND product_id = :product_id AND business_id = :business_id'
            );
            $select->execute(['id' => $variantId, 'product_id' => $productId, 'business_id' => $this->businessId]);
            $variant = $select->fetch();
            if ($variant === false) {
                throw new DomainException('A variante recebida já não existe.');
            }
            $before = (int) $variant['quantity'];
            $after = $before + $quantity;
            if ($after > 2147483647) {
                throw new DomainException('O stock da variante excederia o limite permitido.');
            }
            $update = $this->pdo->prepare(
                'UPDATE product_variants SET quantity = :quantity, cost_price_cents = :cost, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND business_id = :business_id'
            );
            $update->execute([
                'quantity' => $after,
                'cost' => $cost,
                'id' => $variantId,
                'business_id' => $this->businessId,
            ]);
            $movement->execute([
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'before' => $before,
                'after' => $after,
                'reason' => 'Receção parcial da encomenda #' . $orderId,
                'actor' => $actorUserId > 0 ? $actorUserId : null,
            ]);
            return;
        }
        $select = $this->pdo->prepare(
            'SELECT quantity FROM products WHERE id = :id AND business_id = :business_id'
        );
        $select->execute(['id' => $productId, 'business_id' => $this->businessId]);
        $before = (int) $select->fetchColumn();
        $after = $before + $quantity;
        if ($after > 2147483647) {
            throw new DomainException('O stock do produto excederia o limite permitido.');
        }
        $update = $this->pdo->prepare(
            'UPDATE products SET quantity = :quantity, cost_price_cents = :cost, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND business_id = :business_id'
        );
        $update->execute([
            'quantity' => $after,
            'cost' => $cost,
            'id' => $productId,
            'business_id' => $this->businessId,
        ]);
        $movement->execute([
            'business_id' => $this->businessId,
            'product_id' => $productId,
            'variant_id' => null,
            'quantity' => $quantity,
            'before' => $before,
            'after' => $after,
            'reason' => 'Receção parcial da encomenda #' . $orderId,
            'actor' => $actorUserId > 0 ? $actorUserId : null,
        ]);
    }

    private function removePurchaseReturnStock(
        PDOStatement $movement,
        int $productId,
        ?int $variantId,
        int $quantity,
        int $orderId,
        int $actorUserId
    ): void {
        if ($variantId !== null) {
            $select = $this->pdo->prepare(
                'SELECT quantity FROM product_variants WHERE id = :id AND business_id = :business_id'
            );
            $select->execute(['id' => $variantId, 'business_id' => $this->businessId]);
            $before = (int) $select->fetchColumn();
            if ($before < $quantity) {
                throw new DomainException('O stock da variante já não permite esta devolução.');
            }
            $after = $before - $quantity;
            $update = $this->pdo->prepare(
                'UPDATE product_variants SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND business_id = :business_id'
            );
            $update->execute(['quantity' => $after, 'id' => $variantId, 'business_id' => $this->businessId]);
            $movement->execute([
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'before' => $before,
                'after' => $after,
                'reason' => 'Devolução da encomenda #' . $orderId,
                'actor' => $actorUserId > 0 ? $actorUserId : null,
            ]);
            return;
        }
        $select = $this->pdo->prepare(
            'SELECT quantity FROM products WHERE id = :id AND business_id = :business_id'
        );
        $select->execute(['id' => $productId, 'business_id' => $this->businessId]);
        $before = (int) $select->fetchColumn();
        if ($before < $quantity) {
            throw new DomainException('O stock do produto já não permite esta devolução.');
        }
        $after = $before - $quantity;
        $update = $this->pdo->prepare(
            'UPDATE products SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND business_id = :business_id'
        );
        $update->execute(['quantity' => $after, 'id' => $productId, 'business_id' => $this->businessId]);
        $movement->execute([
            'business_id' => $this->businessId,
            'product_id' => $productId,
            'variant_id' => null,
            'quantity' => $quantity,
            'before' => $before,
            'after' => $after,
            'reason' => 'Devolução da encomenda #' . $orderId,
            'actor' => $actorUserId > 0 ? $actorUserId : null,
        ]);
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
