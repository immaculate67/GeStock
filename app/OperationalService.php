<?php
declare(strict_types=1);

final class OperationalService
{
    public function __construct(private readonly PDO $pdo, private readonly int $businessId)
    {
    }

    public function createRecord(array $input, int $actorUserId): int
    {
        $allowedTypes = ['reservation', 'appointment', 'rental', 'membership', 'subscription', 'ticket', 'production', 'other'];
        $type = trim((string) ($input['record_type'] ?? ''));
        $reference = trim((string) ($input['reference'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'draft'));
        if (!in_array($type, $allowedTypes, true) || $reference === '' || mb_strlen($reference) > 80
            || $title === '' || mb_strlen($title) > 160 || mb_strlen($status) > 40) {
            throw new InvalidArgumentException('Indique dados válidos para o registo operacional.');
        }
        $quantity = filter_var($input['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $totalCents = $this->parseCents($input['total_cents'] ?? 0);
        $depositCents = $this->parseCents($input['deposit_cents'] ?? 0);
        if ($quantity === false) {
            throw new InvalidArgumentException('A quantidade tem de ser um número inteiro positivo.');
        }
        $branchId = $this->optionalBusinessId('business_branches', $input['branch_id'] ?? null);
        $customerId = $this->optionalBusinessId('clients', $input['customer_id'] ?? null);
        $resourceId = $this->optionalBusinessId('products', $input['resource_id'] ?? null);
        $metadata = is_string($input['metadata_json'] ?? null) ? trim($input['metadata_json']) : '{}';
        json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO operational_records
             (business_id, branch_id, record_type, reference, title, status, customer_id, resource_id,
              quantity, starts_at, ends_at, deposit_cents, total_cents, metadata_json, created_by)
             VALUES (:business_id, :branch_id, :record_type, :reference, :title, :status, :customer_id,
             :resource_id, :quantity, :starts_at, :ends_at, :deposit_cents, :total_cents, :metadata_json, :actor)'
        );
        $statement->execute([
            'business_id' => $this->businessId,
            'branch_id' => $branchId,
            'record_type' => $type,
            'reference' => $reference,
            'title' => $title,
            'status' => $status,
            'customer_id' => $customerId,
            'resource_id' => $resourceId,
            'quantity' => (int) $quantity,
            'starts_at' => $this->optionalTimestamp($input['starts_at'] ?? null),
            'ends_at' => $this->optionalTimestamp($input['ends_at'] ?? null),
            'deposit_cents' => $depositCents,
            'total_cents' => $totalCents,
            'metadata_json' => $metadata,
            'actor' => $actorUserId > 0 ? $actorUserId : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function all(string $type = '', string $search = ''): array
    {
        $sql = 'SELECT o.*, c.name AS customer_name, p.name AS resource_name
                FROM operational_records o
                LEFT JOIN clients c ON c.id = o.customer_id
                LEFT JOIN products p ON p.id = o.resource_id
                WHERE o.business_id = :business_id';
        $parameters = ['business_id' => $this->businessId];
        if (in_array($type, ['reservation', 'appointment', 'rental', 'membership', 'subscription', 'ticket', 'production', 'other'], true)) {
            $sql .= ' AND o.record_type = :type';
            $parameters['type'] = $type;
        }
        if ($search !== '') {
            $sql .= ' AND (o.reference LIKE :search OR o.title LIKE :search)';
            $parameters['search'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY o.updated_at DESC, o.id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function saveRoom(array $input, int $actorUserId): int
    {
        $number = trim((string) ($input['room_number'] ?? ''));
        $roomType = trim((string) ($input['room_type'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'available'));
        $branchId = $this->optionalBusinessId('business_branches', $input['branch_id'] ?? null);
        if ($number === '' || mb_strlen($number) > 40 || $roomType === '' || mb_strlen($roomType) > 80
            || !in_array($status, ['available', 'occupied', 'cleaning', 'maintenance', 'inactive'], true)) {
            throw new InvalidArgumentException('Indique número, tipo e estado válidos para o quarto.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO hotel_rooms (business_id, branch_id, room_number, room_type, floor, status, base_price_cents, metadata_json)
             VALUES (:business_id, :branch_id, :room_number, :room_type, :floor, :status, :price, :metadata)'
        );
        $statement->execute([
            'business_id' => $this->businessId,
            'branch_id' => $branchId,
            'room_number' => $number,
            'room_type' => $roomType,
            'floor' => is_string($input['floor'] ?? null) ? mb_substr(trim($input['floor']), 0, 40) : null,
            'status' => $status,
            'price' => $this->parseCents($input['base_price_cents'] ?? 0),
            'metadata' => '{}',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function reserveRoom(array $input, int $actorUserId): int
    {
        $roomId = validRecordId($input['room_id'] ?? null);
        $checkIn = $this->optionalTimestamp($input['check_in_at'] ?? null);
        $checkOut = $this->optionalTimestamp($input['check_out_at'] ?? null);
        $reference = trim((string) ($input['reference'] ?? ''));
        if ($roomId === null || $checkIn === null || $checkOut === null || $checkIn >= $checkOut || $reference === '') {
            throw new InvalidArgumentException('Selecione um quarto e um período de estadia válido.');
        }
        $room = $this->pdo->prepare('SELECT id FROM hotel_rooms WHERE id = :id AND business_id = :business_id');
        $room->execute(['id' => $roomId, 'business_id' => $this->businessId]);
        if ($room->fetchColumn() === false) {
            throw new DomainException('O quarto não pertence ao negócio ativo.');
        }
        $overlap = $this->pdo->prepare(
            "SELECT 1 FROM hotel_stays
             WHERE room_id = :room_id AND business_id = :business_id
               AND status IN ('reserved','checked_in')
               AND check_in_at < :check_out AND check_out_at > :check_in
             LIMIT 1"
        );
        $overlap->execute([
            'room_id' => $roomId,
            'business_id' => $this->businessId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);
        if ($overlap->fetchColumn() !== false) {
            throw new DomainException('O quarto já está reservado ou ocupado nesse período.');
        }
        $adults = filter_var($input['adults'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $children = filter_var($input['children'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($adults === false || $children === false) {
            throw new InvalidArgumentException('O número de adultos ou crianças não é válido.');
        }
        $customerId = $this->optionalBusinessId('clients', $input['customer_id'] ?? null);
        $statement = $this->pdo->prepare(
            'INSERT INTO hotel_stays
             (business_id, room_id, customer_id, reference, status, check_in_at, check_out_at, adults, children, total_cents, created_by)
             VALUES (:business_id, :room_id, :customer_id, :reference, \'reserved\', :check_in, :check_out, :adults, :children, :total, :actor)'
        );
        $statement->execute([
            'business_id' => $this->businessId,
            'room_id' => $roomId,
            'customer_id' => $customerId,
            'reference' => mb_substr($reference, 0, 80),
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => (int) $adults,
            'children' => (int) $children,
            'total' => $this->parseCents($input['total_cents'] ?? 0),
            'actor' => $actorUserId > 0 ? $actorUserId : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function changeStayStatus(int $stayId, string $status, int $actorUserId): void
    {
        if (!in_array($status, ['reserved', 'checked_in', 'checked_out', 'cancelled', 'no_show'], true)) {
            throw new InvalidArgumentException('O estado da estadia não é válido.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE hotel_stays SET status = :status
             WHERE id = :id AND business_id = :business_id'
        );
        $statement->execute(['status' => $status, 'id' => $stayId, 'business_id' => $this->businessId]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('A estadia não pertence ao negócio ativo.');
        }
    }

    public function issuePass(int $eventId, string $passCode, string $passType, ?int $customerId, int $actorUserId): int
    {
        $event = $this->pdo->prepare(
            "SELECT 1 FROM operational_records
             WHERE id = :id AND business_id = :business_id AND record_type = 'ticket'"
        );
        $event->execute(['id' => $eventId, 'business_id' => $this->businessId]);
        if ($event->fetchColumn() === false) {
            throw new DomainException('O evento não existe no negócio ativo.');
        }
        $passCode = trim($passCode);
        $passType = trim($passType);
        if (strlen($passCode) < 16 || strlen($passCode) > 128 || $passType === '' || mb_strlen($passType) > 80) {
            throw new InvalidArgumentException('Indique um código de passe seguro e um tipo válido.');
        }
        $customerId = $customerId === null ? null : $this->optionalBusinessId('clients', (string) $customerId);
        $statement = $this->pdo->prepare(
            'INSERT INTO event_passes
             (business_id, event_id, customer_id, pass_code, pass_type)
             VALUES (:business_id, :event_id, :customer_id, :pass_code, :pass_type)'
        );
        $statement->execute([
            'business_id' => $this->businessId,
            'event_id' => $eventId,
            'customer_id' => $customerId,
            'pass_code' => $passCode,
            'pass_type' => mb_substr($passType, 0, 80),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function redeemPass(string $passCode, int $actorUserId): array
    {
        $passCode = trim($passCode);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, status, event_id FROM event_passes
                 WHERE business_id = :business_id AND pass_code = :pass_code'
            );
            $statement->execute(['business_id' => $this->businessId, 'pass_code' => $passCode]);
            $pass = $statement->fetch();
            if ($pass === false) {
                throw new DomainException('O passe não foi encontrado.');
            }
            if ($pass['status'] === 'used') {
                throw new DomainException('Este passe já foi utilizado.');
            }
            if ($pass['status'] !== 'issued') {
                throw new DomainException('Este passe não está válido.');
            }
            $update = $this->pdo->prepare(
                "UPDATE event_passes SET status = 'used', redeemed_at = CURRENT_TIMESTAMP, redeemed_by = :actor
                 WHERE id = :id AND business_id = :business_id AND status = 'issued'"
            );
            $update->execute([
                'actor' => $actorUserId > 0 ? $actorUserId : null,
                'id' => (int) $pass['id'],
                'business_id' => $this->businessId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Não foi possível validar o passe.');
            }
            $this->pdo->commit();
            return $pass;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function optionalBusinessId(string $table, mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $id = validRecordId(is_string($value) ? $value : (string) $value);
        if ($id === null) {
            throw new InvalidArgumentException('O identificador selecionado não é válido.');
        }
        $allowedTables = ['business_branches', 'clients', 'products'];
        if (!in_array($table, $allowedTables, true)) {
            throw new InvalidArgumentException('O tipo de referência não é válido.');
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = :id AND business_id = :business_id');
        $statement->execute(['id' => $id, 'business_id' => $this->businessId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('O registo selecionado não pertence ao negócio ativo.');
        }
        return $id;
    }

    private function optionalTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A data indicada não é válida.');
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Introduza uma data e hora válidas.');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function parseCents(mixed $value): int
    {
        if (is_int($value)) {
            $euros = (string) $value;
        } elseif (is_string($value)) {
            $euros = trim(str_replace(['.', ' '], ['', ''], $value));
            $euros = str_replace(',', '.', $euros);
        } else {
            throw new InvalidArgumentException('O montante não é válido.');
        }
        if (!preg_match('/\A\d+(?:\.\d{1,2})?\z/', $euros)) {
            throw new InvalidArgumentException('O montante não é válido.');
        }
        [$whole, $decimal] = array_pad(explode('.', $euros, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
        if ($cents > 2147483647) {
            throw new InvalidArgumentException('O montante excede o limite permitido.');
        }
        return $cents;
    }
}
