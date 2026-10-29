<?php
declare(strict_types=1);

final class ProductValidator
{
    private const MAX_INTEGER = 2147483647;

    public function validate(array $input): array
    {
        $errors = [];
        $name = $this->stringValue($input['name'] ?? null);
        $sku = strtoupper($this->stringValue($input['sku'] ?? null));
        $description = $this->stringValue($input['description'] ?? null);
        $price = $this->stringValue($input['price'] ?? null);
        $quantity = $this->stringValue($input['quantity'] ?? null);
        $minimum = $this->stringValue($input['min_stock_level'] ?? null);

        if ($name === '') {
            $errors['name'] = 'Enter a product name.';
        } elseif (!$this->isWithinLength($name, 120)) {
            $errors['name'] = 'Product names must be 120 characters or fewer.';
        }

        if ($sku === '') {
            $errors['sku'] = 'Enter a SKU.';
        } elseif (strlen($sku) > 40 || preg_match('/\A[A-Z0-9][A-Z0-9._-]*\z/', $sku) !== 1) {
            $errors['sku'] = 'Use up to 40 letters, numbers, dots, underscores or hyphens; start with a letter or number.';
        }

        if ($description !== '' && !$this->isWithinLength($description, 1000)) {
            $errors['description'] = 'Descriptions must be 1,000 characters or fewer.';
        }

        $priceCents = $this->priceToCents($price);
        if ($priceCents === null) {
            $errors['price'] = 'Enter a non-negative price with up to two decimal places.';
        }

        $quantityValue = $this->nonNegativeInteger($quantity);
        if ($quantityValue === null) {
            $errors['quantity'] = 'Quantity must be a non-negative whole number.';
        }

        $minimumValue = $this->nonNegativeInteger($minimum);
        if ($minimumValue === null) {
            $errors['min_stock_level'] = 'Minimum stock level must be a non-negative whole number.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'name' => $name,
            'sku' => $sku,
            'description' => $description === '' ? null : $description,
            'price_cents' => $priceCents,
            'quantity' => $quantityValue,
            'min_stock_level' => $minimumValue,
        ], []];
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private function isWithinLength(string $value, int $maximum): bool
    {
        $length = preg_match_all('/./us', $value);

        return $length !== false && $length <= $maximum;
    }

    private function priceToCents(string $price): ?int
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', $price, $matches) !== 1) {
            return null;
        }

        $parts = explode('.', $price, 2);
        $whole = (int) $parts[0];
        $fraction = isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0;

        return $whole * 100 + $fraction;
    }

    private function nonNegativeInteger(string $value): ?int
    {
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_INTEGER],
        ]);

        return $integer === false ? null : $integer;
    }
}
