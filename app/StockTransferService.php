<?php
declare(strict_types=1);

final class StockTransferService
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
            'SELECT id, name, code FROM business_branches
             WHERE business_id = :business_id AND active = 1 ORDER BY name COLLATE NOCASE'
        );
        $query->execute(['business_id' => $this->businessId]);
        return $query->fetchAll();
    }

    public function locations(): array
    {
        $query = $this->pdo->prepare(
            'SELECT l.id, l.branch_id, l.name, l.code, b.name AS branch_name
             FROM stock_locations l
             JOIN business_branches b ON b.id = l.branch_id
             WHERE l.business_id = :business_id AND l.active = 1 AND b.business_id = :business_id
             ORDER BY b.name COLLATE NOCASE, l.name COLLATE NOCASE'
        );
        $query->execute(['business_id' => $this->businessId]);
        return $query->fetchAll();
    }

    public function balances(?int $locationId = null): array
    {
        $where = 'sb.business_id = :business_id';
        $parameters = ['business_id' => $this->businessId];
        if ($locationId !== null) {
            $where .= ' AND sb.location_id = :location_id';
            $parameters['location_id'] = $locationId;
        }
        $statement = $this->pdo->prepare(
            'SELECT sb.location_id, sb.product_id, sb.variant_id, sb.quantity,
                    p.name AS product_name, p.sku,
                    v.identifier AS variant_identifier, v.sku AS variant_sku,
                    l.name AS location_name, b.name AS branch_name
             FROM stock_balances sb
             JOIN products p ON p.id = sb.product_id AND p.business_id = sb.business_id
             LEFT JOIN product_variants v ON v.id = sb.variant_id AND v.business_id = sb.business_id
             JOIN stock_locations l ON l.id = sb.location_id AND l.business_id = sb.business_id
             JOIN business_branches b ON b.id = sb.branch_id AND b.business_id = sb.business_id
             WHERE ' . $where . ' AND sb.quantity > 0
             ORDER BY b.name COLLATE NOCASE, l.name COLLATE NOCASE, p.name COLLATE NOCASE'
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function transfers(): array
    {
        $query = $this->pdo->prepare(
            'SELECT t.*,
                    sb.name AS source_branch_name, sl.name AS source_location_name,
                    db.name AS destination_branch_name, dl.name AS destination_location_name,
                    (SELECT COUNT(*) FROM stock_transfer_items ti WHERE ti.transfer_id = t.id) AS line_count
             FROM stock_transfers t
             JOIN business_branches sb ON sb.id = t.source_branch_id
             JOIN stock_locations sl ON sl.id = t.source_location_id
             JOIN business_branches db ON db.id = t.destination_branch_id
             JOIN stock_locations dl ON dl.id = t.destination_location_id
             WHERE t.business_id = :business_id
             ORDER BY t.created_at DESC, t.id DESC'
        );
        $query->execute(['business_id' => $this->businessId]);
        return $query->fetchAll();
    }

    public function createTransfer(array $input, int $actorUserId): int
    {
        $sourceLocationId = $this->locationId($input['source_location_id'] ?? null);
        $destinationLocationId = $this->locationId($input['destination_location_id'] ?? null);
        if ($sourceLocationId === null || $destinationLocationId === null) {
            throw new InvalidArgumentException('Selecione os locais de origem e destino.');
        }
        $source = $this->location($sourceLocationId);
        $destination = $this->location($destinationLocationId);
        if ((int) $source['branch_id'] === (int) $destination['branch_id']) {
            throw new InvalidArgumentException('A origem e o destino têm de usar filiais diferentes.');
        }
        $reference = strtoupper(trim((string) ($input['reference'] ?? '')));
        if ($reference === '' || mb_strlen($reference) > 80) {
            $reference = 'TR-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        }
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('As notas não podem exceder 2000 caracteres.');
        }
        $rawLines = $input['lines'] ?? [];
        if (!is_array($rawLines) || $rawLines === []) {
            throw new InvalidArgumentException('Adicione pelo menos uma linha à transferência.');
        }
        $lines = [];
        foreach ($rawLines as $line) {
            if (!is_array($line)) continue;
            $productId = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $variantId = ($line['variant_id'] ?? '') === '' ? null :
                filter_var($line['variant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if ($productId === false || $variantId === false || $quantity === false) {
                throw new InvalidArgumentException('Os produtos e quantidades da transferência não são válidos.');
            }
            $key = (int) $productId . ':' . ($variantId === null ? 'base' : (string) (int) $variantId);
            $lines[$key] ??= ['product_id' => (int) $productId, 'variant_id' => $variantId === null ? null : (int) $variantId, 'quantity' => 0];
            $lines[$key]['quantity'] += $quantity;
            if ($lines[$key]['quantity'] > 2147483647) {
                throw new InvalidArgumentException('A quantidade da transferência excede o limite suportado.');
            }
        }
        if ($lines === []) throw new InvalidArgumentException('Adicione pelo menos uma linha à transferência.');

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO stock_transfers
                 (business_id, source_branch_id, source_location_id, destination_branch_id, destination_location_id, status, reference, notes, created_by)
                 VALUES (:business_id, :source_branch, :source_location, :destination_branch, :destination_location, \'draft\', :reference, :notes, :actor)'
            );
            $insert->execute([
                'business_id' => $this->businessId,
                'source_branch' => (int) $source['branch_id'],
                'source_location' => $sourceLocationId,
                'destination_branch' => (int) $destination['branch_id'],
                'destination_location' => $destinationLocationId,
                'reference' => $reference,
                'notes' => $notes === '' ? null : $notes,
                'actor' => $actorUserId > 0 ? $actorUserId : null,
            ]);
            $transferId = (int) $this->pdo->lastInsertId();
            $itemInsert = $this->pdo->prepare(
                'INSERT INTO stock_transfer_items (business_id, transfer_id, product_id, variant_id, quantity)
                 VALUES (:business_id, :transfer_id, :product_id, :variant_id, :quantity)'
            );
            foreach ($lines as $line) {
                $this->validateLineProduct((int) $line['product_id'], $line['variant_id']);
                $itemInsert->execute([
                    'business_id' => $this->businessId,
                    'transfer_id' => $transferId,
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'quantity' => $line['quantity'],
                ]);
            }
            $this->pdo->commit();
            return $transferId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function completeTransfer(int $transferId, int $actorUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM stock_transfers WHERE id = :id AND business_id = :business_id');
            $query->execute(['id' => $transferId, 'business_id' => $this->businessId]);
            $transfer = $query->fetch();
            if ($transfer === false) throw new DomainException('A transferência não pertence ao negócio ativo.');
            if ($transfer['status'] !== 'draft') throw new DomainException('Esta transferência já foi concluída ou cancelada.');
            $items = $this->pdo->prepare(
                'SELECT * FROM stock_transfer_items WHERE transfer_id = :transfer_id AND business_id = :business_id'
            );
            $items->execute(['transfer_id' => $transferId, 'business_id' => $this->businessId]);
            foreach ($items->fetchAll() as $item) {
                $this->moveBalance(
                    (int) $transfer['source_location_id'],
                    (int) $transfer['destination_location_id'],
                    (int) $item['product_id'],
                    $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                    (int) $item['quantity'],
                    $transferId,
                    $actorUserId
                );
            }
            $update = $this->pdo->prepare(
                'UPDATE stock_transfers SET status = \'completed\', completed_by = :actor, completed_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND business_id = :business_id AND status = \'draft\''
            );
            $update->execute(['actor' => $actorUserId > 0 ? $actorUserId : null, 'id' => $transferId, 'business_id' => $this->businessId]);
            if ($update->rowCount() !== 1) throw new DomainException('A transferência foi alterada entretanto.');
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function cancelTransfer(int $transferId): void
    {
        $update = $this->pdo->prepare(
            'UPDATE stock_transfers SET status = \'cancelled\', completed_at = NULL
             WHERE id = :id AND business_id = :business_id AND status = \'draft\''
        );
        $update->execute(['id' => $transferId, 'business_id' => $this->businessId]);
        if ($update->rowCount() !== 1) throw new DomainException('A transferência não existe ou já não está em rascunho.');
    }

    private function locationId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int) $id;
    }

    private function location(int $locationId): array
    {
        $query = $this->pdo->prepare(
            'SELECT l.id, l.branch_id FROM stock_locations l
             WHERE l.id = :id AND l.business_id = :business_id AND l.active = 1'
        );
        $query->execute(['id' => $locationId, 'business_id' => $this->businessId]);
        $location = $query->fetch();
        if ($location === false) throw new DomainException('O local de stock selecionado não pertence ao negócio ativo.');
        return $location;
    }

    private function validateLineProduct(int $productId, ?int $variantId): void
    {
        $query = $this->pdo->prepare('SELECT id FROM products WHERE id = :id AND business_id = :business_id');
        $query->execute(['id' => $productId, 'business_id' => $this->businessId]);
        if ($query->fetchColumn() === false) throw new DomainException('Um produto da transferência não pertence ao negócio ativo.');
        if ($variantId !== null) {
            $variant = $this->pdo->prepare(
                'SELECT id FROM product_variants
                 WHERE id = :id AND product_id = :product_id AND business_id = :business_id AND active = 1'
            );
            $variant->execute(['id' => $variantId, 'product_id' => $productId, 'business_id' => $this->businessId]);
            if ($variant->fetchColumn() === false) throw new DomainException('Uma variante da transferência não pertence ao produto indicado.');
        }
    }

    private function moveBalance(int $sourceLocationId, int $destinationLocationId, int $productId, ?int $variantId, int $quantity, int $transferId, int $actorUserId): void
    {
        if ($variantId === null) {
            $global = $this->pdo->prepare('SELECT quantity FROM products WHERE id = :id AND business_id = :business_id');
            $global->execute(['id' => $productId, 'business_id' => $this->businessId]);
        } else {
            $global = $this->pdo->prepare('SELECT quantity FROM product_variants WHERE id = :id AND business_id = :business_id');
            $global->execute(['id' => $variantId, 'business_id' => $this->businessId]);
        }
        $globalStock = (int) $global->fetchColumn();

        $sourceInfo = $this->location($sourceLocationId);
        $sum = $this->pdo->prepare(
            'SELECT COALESCE(SUM(quantity), 0) FROM stock_balances
             WHERE business_id = :business_id AND product_id = :product_id
               AND ((variant_id = :variant_id) OR (:variant_id IS NULL AND variant_id IS NULL))'
        );
        $sum->execute(['business_id' => $this->businessId, 'product_id' => $productId, 'variant_id' => $variantId]);
        $balanceTotal = (int) $sum->fetchColumn();
        $difference = $globalStock - $balanceTotal;
        if ($difference !== 0) {
            $sourceBalance = $this->pdo->prepare(
                'SELECT id, quantity FROM stock_balances
                 WHERE business_id = :business_id AND location_id = :location_id
                   AND product_id = :product_id
                   AND ((variant_id = :variant_id) OR (:variant_id IS NULL AND variant_id IS NULL))'
            );
            $sourceBalance->execute([
                'business_id' => $this->businessId,
                'location_id' => $sourceLocationId,
                'product_id' => $productId,
                'variant_id' => $variantId,
            ]);
            $sourceBalanceRow = $sourceBalance->fetch();
            if ($sourceBalanceRow === false) {
                $insertSource = $this->pdo->prepare(
                    'INSERT INTO stock_balances (business_id, branch_id, location_id, product_id, variant_id, quantity)
                     VALUES (:business_id, :branch_id, :location_id, :product_id, :variant_id, :quantity)'
                );
                $insertSource->execute([
                    'business_id' => $this->businessId,
                    'branch_id' => (int) $sourceInfo['branch_id'],
                    'location_id' => $sourceLocationId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => max(0, $difference),
                ]);
            } else {
                $newSourceQuantity = max(0, (int) $sourceBalanceRow['quantity'] + $difference);
                $updateSource = $this->pdo->prepare(
                    'UPDATE stock_balances SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND business_id = :business_id'
                );
                $updateSource->execute([
                    'quantity' => $newSourceQuantity,
                    'id' => (int) $sourceBalanceRow['id'],
                    'business_id' => $this->businessId,
                ]);
            }
        }

        $source = $this->pdo->prepare(
            'SELECT sb.*, l.branch_id FROM stock_balances sb
             JOIN stock_locations l ON l.id = sb.location_id
             WHERE sb.business_id = :business_id AND sb.location_id = :location_id
               AND sb.product_id = :product_id
               AND ((sb.variant_id = :variant_id) OR (:variant_id IS NULL AND sb.variant_id IS NULL))'
        );
        $source->execute(['business_id' => $this->businessId, 'location_id' => $sourceLocationId, 'product_id' => $productId, 'variant_id' => $variantId]);
        $sourceRow = $source->fetch();
        if ($sourceRow === false) throw new DomainException('Não existe stock da linha indicada na localização de origem.');
        if ((int) $sourceRow['quantity'] < $quantity) throw new DomainException('Stock insuficiente na localização de origem.');

        $destinationLocation = $this->location($destinationLocationId);
        $destination = $this->pdo->prepare(
            'SELECT id, quantity FROM stock_balances
             WHERE business_id = :business_id AND location_id = :location_id
               AND product_id = :product_id
               AND ((variant_id = :variant_id) OR (:variant_id IS NULL AND variant_id IS NULL))'
        );
        $destination->execute(['business_id' => $this->businessId, 'location_id' => $destinationLocationId, 'product_id' => $productId, 'variant_id' => $variantId]);
        $destinationRow = $destination->fetch();

        $sourceUpdate = $this->pdo->prepare(
            'UPDATE stock_balances SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND business_id = :business_id AND quantity = :before'
        );
        $sourceUpdate->execute([
            'quantity' => (int) $sourceRow['quantity'] - $quantity,
            'id' => (int) $sourceRow['id'],
            'business_id' => $this->businessId,
            'before' => (int) $sourceRow['quantity'],
        ]);
        if ($sourceUpdate->rowCount() !== 1) throw new DomainException('O stock de origem foi alterado entretanto.');

        if ($destinationRow === false) {
            $destinationInsert = $this->pdo->prepare(
                'INSERT INTO stock_balances (business_id, branch_id, location_id, product_id, variant_id, quantity)
                 VALUES (:business_id, :branch_id, :location_id, :product_id, :variant_id, :quantity)'
            );
            $destinationInsert->execute([
                'business_id' => $this->businessId,
                'branch_id' => (int) $destinationLocation['branch_id'],
                'location_id' => $destinationLocationId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
            ]);
        } else {
            $destinationUpdate = $this->pdo->prepare(
                'UPDATE stock_balances SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND business_id = :business_id AND quantity = :before'
            );
            $destinationUpdate->execute([
                'quantity' => (int) $destinationRow['quantity'] + $quantity,
                'id' => (int) $destinationRow['id'],
                'business_id' => $this->businessId,
                'before' => (int) $destinationRow['quantity'],
            ]);
            if ($destinationUpdate->rowCount() !== 1) throw new DomainException('O stock de destino foi alterado entretanto.');
        }

        if ($variantId === null) {
            $global = $this->pdo->prepare('SELECT quantity FROM products WHERE id = :id AND business_id = :business_id');
            $global->execute(['id' => $productId, 'business_id' => $this->businessId]);
        } else {
            $global = $this->pdo->prepare('SELECT quantity FROM product_variants WHERE id = :id AND business_id = :business_id');
            $global->execute(['id' => $variantId, 'business_id' => $this->businessId]);
        }
        $globalStock = (int) $global->fetchColumn();
        $movement = $this->pdo->prepare(
            'INSERT INTO stock_movements
             (business_id, product_id, variant_id, movement_type, quantity, stock_before, stock_after, reason, transfer_id, source_location_id, destination_location_id, created_by)
             VALUES (:business_id, :product_id, :variant_id, \'out\', :quantity, :stock, :stock, :reason, :transfer_id, :source_location_id, :destination_location_id, :actor)'
        );
        $movement->execute([
            'business_id' => $this->businessId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => $quantity,
            'stock' => $globalStock,
            'reason' => 'Transferência de stock',
            'transfer_id' => $transferId,
            'source_location_id' => $sourceLocationId,
            'destination_location_id' => $destinationLocationId,
            'actor' => $actorUserId > 0 ? $actorUserId : null,
        ]);
    }
}
