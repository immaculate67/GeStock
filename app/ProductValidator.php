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
        $costPrice = $this->stringValue($input['cost_price'] ?? '0');
        $quantity = $this->stringValue($input['quantity'] ?? null);
        $minimum = $this->stringValue($input['min_stock_level'] ?? null);
        $categoryId = $this->optionalId($input['category_id'] ?? null);
        $supplierId = $this->optionalId($input['supplier_id'] ?? null);

        if ($name === '') {
            $errors['name'] = 'Introduza o nome do produto.';
        } elseif (!$this->isWithinLength($name, 120)) {
            $errors['name'] = 'O nome do produto não pode exceder 120 caracteres.';
        }

        if ($sku === '') {
            $errors['sku'] = 'Introduza um código único.';
        } elseif (strlen($sku) > 40 || preg_match('/\A[A-Z0-9][A-Z0-9._-]*\z/', $sku) !== 1) {
            $errors['sku'] = 'Use até 40 letras, números, pontos, sublinhados ou hífenes; comece por uma letra ou número.';
        }

        if ($description !== '' && !$this->isWithinLength($description, 1000)) {
            $errors['description'] = 'A descrição não pode exceder 1.000 caracteres.';
        }

        $priceCents = $this->priceToCents($price);
        if ($priceCents === null) {
            $errors['price'] = 'Introduza um preço não negativo com até duas casas decimais.';
        }
        $costPriceCents = $this->priceToCents($costPrice);
        if ($costPriceCents === null) {
            $errors['cost_price'] = 'Introduza um custo não negativo com até duas casas decimais.';
        }

        if (($input['category_id'] ?? '') !== '' && $categoryId === null) {
            $errors['category_id'] = 'Selecione uma categoria válida.';
        }
        if (($input['supplier_id'] ?? '') !== '' && $supplierId === null) {
            $errors['supplier_id'] = 'Selecione um fornecedor válido.';
        }

        $quantityValue = $this->nonNegativeInteger($quantity);
        if ($quantityValue === null) {
            $errors['quantity'] = 'A quantidade deve ser um número inteiro não negativo.';
        }

        $minimumValue = $this->nonNegativeInteger($minimum);
        if ($minimumValue === null) {
            $errors['min_stock_level'] = 'O stock mínimo deve ser um número inteiro não negativo.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'name' => $name,
            'sku' => $sku,
            'description' => $description === '' ? null : $description,
            'price_cents' => $priceCents,
            'cost_price_cents' => $costPriceCents,
            'quantity' => $quantityValue,
            'min_stock_level' => $minimumValue,
            'category_id' => $categoryId,
            'supplier_id' => $supplierId,
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
        $price = str_replace(',', '.', $price);
        if (preg_match('/\A(?:0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?\z/', $price, $matches) !== 1) {
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

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $id = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
