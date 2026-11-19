<?php
declare(strict_types=1);

final class CatalogVariantService
{
    private const FIELD_TYPES = ['text', 'integer', 'decimal', 'boolean', 'date', 'select'];

    public function __construct(private readonly PDO $pdo, private readonly int $businessId)
    {
        if ($businessId < 1) {
            throw new InvalidArgumentException('A empresa ativa não é válida.');
        }
    }

    public function createVariant(int $productId, array $input): int
    {
        $this->assertProduct($productId);
        $data = $this->validateVariant($input);
        $statement = $this->pdo->prepare(
            'INSERT INTO product_variants
             (business_id, product_id, branch_id, identifier, sku, barcode, price_cents, cost_price_cents, quantity, min_stock_level)
             VALUES (:business_id, :product_id, :branch_id, :identifier, :sku, :barcode, :price_cents, :cost_price_cents, :quantity, :min_stock_level)'
        );
        $statement->execute(['business_id' => $this->businessId, 'product_id' => $productId] + $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function variants(int $productId, bool $activeOnly = false): array
    {
        $this->assertProduct($productId);
        $sql = 'SELECT v.*, b.name AS branch_name FROM product_variants v
                LEFT JOIN business_branches b ON b.id = v.branch_id
                WHERE v.business_id = :business_id AND v.product_id = :product_id';
        if ($activeOnly) {
            $sql .= ' AND v.active = 1';
        }
        $sql .= ' ORDER BY v.identifier COLLATE NOCASE, v.id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['business_id' => $this->businessId, 'product_id' => $productId]);
        return $statement->fetchAll();
    }

    public function createAttributeDefinition(array $input): int
    {
        $data = $this->validateDefinition($input);
        $statement = $this->pdo->prepare(
            'INSERT INTO custom_attribute_definitions
             (business_id, attribute_key, label, field_type, options_json, required, default_value)
             VALUES (:business_id, :attribute_key, :label, :field_type, :options_json, :required, :default_value)'
        );
        $statement->execute(['business_id' => $this->businessId] + $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function attributeDefinitions(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM custom_attribute_definitions
             WHERE business_id = :business_id ORDER BY label COLLATE NOCASE, id'
        );
        $statement->execute(['business_id' => $this->businessId]);
        return $statement->fetchAll();
    }

    public function saveProductAttributes(int $productId, array $values): void
    {
        $this->assertProduct($productId);
        $definitions = [];
        foreach ($this->attributeDefinitions() as $definition) {
            if ((int) $definition['active'] !== 1) {
                continue;
            }
            $definitions[(int) $definition['id']] = $definition;
        }
        foreach (array_keys($values) as $attributeId) {
            if (!is_int($attributeId) && !ctype_digit((string) $attributeId)) {
                throw new InvalidArgumentException('O identificador do atributo não é válido.');
            }
            if (!isset($definitions[(int) $attributeId]) || !array_key_exists($attributeId, $values)) {
                throw new DomainException('O atributo indicado não pertence à empresa ativa.');
            }
        }
        $normalized = [];
        foreach ($definitions as $attributeId => $definition) {
            $value = array_key_exists($attributeId, $values) ? $values[$attributeId] : $definition['default_value'];
            if ($value === null || (is_string($value) && trim($value) === '')) {
                if ((int) $definition['required'] === 1) {
                    throw new InvalidArgumentException('O atributo "' . $definition['label'] . '" é obrigatório.');
                }
                continue;
            }
            $normalized[$attributeId] = $this->validateValue($definition, $value);
        }
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM product_attribute_values WHERE business_id = :business_id AND product_id = :product_id'
            );
            $delete->execute(['business_id' => $this->businessId, 'product_id' => $productId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO product_attribute_values (business_id, product_id, attribute_id, value)
                 VALUES (:business_id, :product_id, :attribute_id, :value)'
            );
            foreach ($normalized as $attributeId => $value) {
                $insert->execute([
                    'business_id' => $this->businessId, 'product_id' => $productId,
                    'attribute_id' => $attributeId, 'value' => $value,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function productAttributes(int $productId): array
    {
        $this->assertProduct($productId);
        $statement = $this->pdo->prepare(
            'SELECT d.*, v.value FROM custom_attribute_definitions d
             LEFT JOIN product_attribute_values v ON v.attribute_id = d.id AND v.product_id = :product_id
             WHERE d.business_id = :business_id AND d.active = 1
             ORDER BY d.label COLLATE NOCASE, d.id'
        );
        $statement->execute(['business_id' => $this->businessId, 'product_id' => $productId]);
        return $statement->fetchAll();
    }

    private function assertProduct(int $productId): void
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM products WHERE id = :id AND business_id = :business_id');
        $statement->execute(['id' => $productId, 'business_id' => $this->businessId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('O produto não pertence à empresa ativa.');
        }
    }

    private function validateVariant(array $input): array
    {
        $identifier = trim((string) ($input['identifier'] ?? ''));
        $sku = strtoupper(trim((string) ($input['sku'] ?? '')));
        $barcode = trim((string) ($input['barcode'] ?? ''));
        if ($identifier === '' || strlen($identifier) > 120 || $sku === '' || strlen($sku) > 40 || preg_match('/\A[A-Z0-9][A-Z0-9._-]*\z/', $sku) !== 1) {
            throw new InvalidArgumentException('A variante requer um identificador e um SKU válidos.');
        }
        if ($barcode !== '' && strlen($barcode) > 80) {
            throw new InvalidArgumentException('O código de barras não pode exceder 80 caracteres.');
        }
        foreach (['quantity', 'min_stock_level'] as $field) {
            $value = filter_var($input[$field] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
            if ($value === false) {
                throw new InvalidArgumentException('A quantidade da variante não é válida.');
            }
            $input[$field] = $value;
        }
        foreach (['price_cents', 'cost_price_cents'] as $field) {
            if (($input[$field] ?? null) !== null && filter_var($input[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                throw new InvalidArgumentException('O preço da variante não é válido.');
            }
        }
        $branchId = $input['branch_id'] ?? null;
        if ($branchId !== null && filter_var($branchId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('O local da variante não é válido.');
        }
        return [
            'branch_id' => $branchId === null ? null : (int) $branchId,
            'identifier' => $identifier, 'sku' => $sku, 'barcode' => $barcode === '' ? null : $barcode,
            'price_cents' => $input['price_cents'] ?? null, 'cost_price_cents' => $input['cost_price_cents'] ?? null,
            'quantity' => $input['quantity'], 'min_stock_level' => $input['min_stock_level'],
        ];
    }

    private function validateDefinition(array $input): array
    {
        $key = strtolower(trim((string) ($input['attribute_key'] ?? '')));
        $label = trim((string) ($input['label'] ?? ''));
        $type = (string) ($input['field_type'] ?? '');
        if (preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $key) !== 1 || $label === '' || strlen($label) > 120 || !in_array($type, self::FIELD_TYPES, true)) {
            throw new InvalidArgumentException('A definição do atributo não é válida.');
        }
        $options = $input['options'] ?? [];
        if ($type === 'select' && (!is_array($options) || $options === [])) {
            throw new InvalidArgumentException('Um atributo de seleção requer opções.');
        }
        if (!is_array($options)) {
            throw new InvalidArgumentException('As opções do atributo não são válidas.');
        }
        $options = array_values(array_filter(array_map(static fn ($option): string => trim((string) $option), $options), static fn (string $option): bool => $option !== ''));
        if ($type !== 'select') {
            $options = [];
        }
        $default = $input['default_value'] ?? null;
        if ($default !== null && $default !== '') {
            $default = $this->validateValue(['field_type' => $type, 'options_json' => json_encode($options)], $default);
        } else {
            $default = null;
        }
        return [
            'attribute_key' => $key, 'label' => $label, 'field_type' => $type,
            'options_json' => json_encode($options, JSON_THROW_ON_ERROR),
            'required' => !empty($input['required']) ? 1 : 0, 'default_value' => $default,
        ];
    }

    private function validateValue(array $definition, mixed $value): string
    {
        $value = trim((string) $value);
        $type = $definition['field_type'];
        if ($type === 'text' && strlen($value) > 500) {
            throw new InvalidArgumentException('O valor do atributo é demasiado longo.');
        }
        if ($type === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('O valor deve ser um número inteiro.');
        }
        if ($type === 'decimal' && !is_numeric($value)) {
            throw new InvalidArgumentException('O valor deve ser numérico.');
        }
        if ($type === 'boolean' && !in_array(strtolower($value), ['0', '1', 'true', 'false', 'sim', 'não', 'nao'], true)) {
            throw new InvalidArgumentException('O valor deve ser booleano.');
        }
        if ($type === 'date') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                throw new InvalidArgumentException('O valor deve ser uma data válida.');
            }
        }
        if ($type === 'select' && !in_array($value, json_decode((string) $definition['options_json'], true, 512, JSON_THROW_ON_ERROR), true)) {
            throw new InvalidArgumentException('O valor não corresponde a uma opção válida.');
        }
        return $value;
    }
}
