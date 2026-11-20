<?php
declare(strict_types=1);

final class BusinessService
{
    private const MODULE_OPTIONS = [
        'catalog' => ['title' => 'Catálogo', 'description' => 'Produtos, categorias e respetivos dados.'],
        'inventory' => ['title' => 'Inventário', 'description' => 'Níveis de stock e movimentos.'],
        'sales' => ['title' => 'Vendas', 'description' => 'Checkout, histórico e anulações.'],
        'customers' => ['title' => 'Clientes', 'description' => 'Registos e contactos de clientes.'],
        'suppliers' => ['title' => 'Fornecedores', 'description' => 'Contactos e dados de fornecedores.'],
        'reports' => ['title' => 'Relatórios', 'description' => 'Indicadores e exportação de vendas.'],
        'purchases' => ['title' => 'Compras', 'description' => 'Encomendas, receções e custos dos fornecedores.'],
        'operations' => ['title' => 'Operações', 'description' => 'Reservas, marcações, alugueres, passes e produção.'],
        'audit' => ['title' => 'Auditoria', 'description' => 'Histórico append-only das alterações importantes.'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function templates(): array
    {
        $rows = $this->pdo->query(
            'SELECT template_key, name, description, configuration FROM industry_templates ORDER BY name COLLATE NOCASE'
        )->fetchAll();
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['configuration'], true);
            $row['default_modules'] = is_array($decoded['modules'] ?? null)
                ? array_keys(array_filter($decoded['modules'], static fn(mixed $enabled): bool => $enabled === true))
                : [];
            unset($row['configuration']);
        }
        unset($row);

        return $rows;
    }

    public function memberships(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.name, b.industry_key, bm.role
             FROM business_memberships bm
             JOIN businesses b ON b.id = bm.business_id
             WHERE bm.user_id = :user_id AND bm.active = 1
             ORDER BY b.name COLLATE NOCASE, b.id'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function create(
        int $ownerUserId,
        string $name,
        string $templateKey,
        int $currentBusinessId,
        ?array $enabledModules = null,
        ?array $profile = null
    ): int {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Indique um nome de negócio com até 120 caracteres.');
        }
        if ($enabledModules !== null) {
            foreach ($enabledModules as $moduleKey) {
                if (!is_string($moduleKey) || !array_key_exists($moduleKey, self::MODULE_OPTIONS)) {
                    throw new InvalidArgumentException('A seleção de módulos contém uma opção inválida.');
                }
            }
            $enabledModules = array_values(array_unique($enabledModules));
        }

        $authorization = $this->pdo->prepare(
            "SELECT 1 FROM business_memberships
             WHERE business_id = :business_id AND user_id = :user_id
               AND active = 1 AND role IN ('owner', 'administrator')"
        );
        $authorization->execute(['business_id' => $currentBusinessId, 'user_id' => $ownerUserId]);
        if ($authorization->fetchColumn() === false) {
            throw new DomainException('Só um proprietário ou administrador pode criar outro negócio.');
        }
        $template = $this->pdo->prepare('SELECT 1 FROM industry_templates WHERE template_key = :template_key');
        $template->execute(['template_key' => $templateKey]);
        if ($template->fetchColumn() === false) {
            throw new InvalidArgumentException('Selecione um modelo de negócio disponível.');
        }

        $this->pdo->beginTransaction();
        try {
            $create = $this->pdo->prepare(
                'INSERT INTO businesses (name, industry_key, owner_user_id)
                 VALUES (:name, :industry_key, :owner_user_id)'
            );
            $create->execute([
                'name' => $name,
                'industry_key' => $templateKey,
                'owner_user_id' => $ownerUserId,
            ]);
            $businessId = (int) $this->pdo->lastInsertId();
            $membership = $this->pdo->prepare(
                'INSERT INTO business_memberships (business_id, user_id, role)
                 VALUES (:business_id, :user_id, \'owner\')'
            );
            $membership->execute(['business_id' => $businessId, 'user_id' => $ownerUserId]);
            IndustryTemplateService::apply($this->pdo, $businessId, $templateKey);
            if ($enabledModules !== null) {
                $updateModule = $this->pdo->prepare(
                    'UPDATE business_modules SET enabled = :enabled
                     WHERE business_id = :business_id AND module_key = :module_key'
                );
                foreach (self::configurableModules() as $moduleKey) {
                    $updateModule->execute([
                        'enabled' => (int) in_array($moduleKey, $enabledModules, true),
                        'business_id' => $businessId,
                        'module_key' => $moduleKey,
                    ]);
                }
            }

            if ($profile !== null) {
                $normalizedProfile = self::normalizeProfile($profile);
                $updateBusiness = $this->pdo->prepare(
                    'UPDATE businesses SET timezone = :timezone WHERE id = :business_id'
                );
                $updateBusiness->execute([
                    'timezone' => $normalizedProfile['timezone'],
                    'business_id' => $businessId,
                ]);

                $setting = $this->pdo->prepare(
                    'INSERT INTO business_settings (business_id, setting_key, setting_value)
                     VALUES (:business_id, :setting_key, :setting_value)'
                );
                foreach ($normalizedProfile['settings'] as $settingKey => $settingValue) {
                    $setting->execute([
                        'business_id' => $businessId,
                        'setting_key' => $settingKey,
                        'setting_value' => $settingValue,
                    ]);
                }

                $branchName = $normalizedProfile['branch_name'];
                $branchCode = $normalizedProfile['branch_code'];
                $branchAddress = $normalizedProfile['branch_address'];
                $branch = $this->pdo->prepare(
                    'UPDATE business_branches SET name = :name, code = :code, address = :address
                     WHERE business_id = :business_id'
                );
                $branch->execute([
                    'business_id' => $businessId,
                    'name' => $branchName,
                    'code' => $branchCode,
                    'address' => $branchAddress === '' ? null : $branchAddress,
                ]);
            }
            $this->pdo->commit();

            return $businessId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function configurableModules(): array
    {
        return array_keys(self::MODULE_OPTIONS);
    }

    public static function moduleOptions(): array
    {
        return self::MODULE_OPTIONS;
    }

    public function profile(int $businessId): array
    {
        $business = $this->pdo->prepare(
            'SELECT name, timezone FROM businesses WHERE id = :business_id'
        );
        $business->execute(['business_id' => $businessId]);
        $row = $business->fetch();
        $profile = [
            'name' => is_array($row) ? (string) $row['name'] : '',
            'timezone' => is_array($row) ? (string) $row['timezone'] : 'Europe/Lisbon',
            'currency' => 'EUR',
            'tax_rate' => '23',
            'address' => '',
            'phone' => '',
            'tax_id' => '',
            'appearance_theme' => 'system',
        ];
        $settings = $this->pdo->prepare(
            'SELECT setting_key, setting_value FROM business_settings
             WHERE business_id = :business_id'
        );
        $settings->execute(['business_id' => $businessId]);
        foreach ($settings->fetchAll() as $setting) {
            if (array_key_exists((string) $setting['setting_key'], $profile)) {
                $profile[(string) $setting['setting_key']] = (string) $setting['setting_value'];
            }
        }
        $branch = $this->pdo->prepare(
            'SELECT name, code, address FROM business_branches
             WHERE business_id = :business_id ORDER BY id LIMIT 1'
        );
        $branch->execute(['business_id' => $businessId]);
        $branchRow = $branch->fetch();
        $profile['branch_name'] = is_array($branchRow) ? (string) $branchRow['name'] : 'Local principal';
        $profile['branch_code'] = is_array($branchRow) ? (string) $branchRow['code'] : 'MAIN';
        $profile['branch_address'] = is_array($branchRow) && $branchRow['address'] !== null
            ? (string) $branchRow['address'] : '';

        return $profile;
    }

    public function updateProfile(int $businessId, string $name, array $profile): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Indique um nome até 120 caracteres.');
        }
        $normalized = self::normalizeProfile(array_merge($profile, ['name' => $name]));
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                'UPDATE businesses SET name = :name, timezone = :timezone WHERE id = :business_id'
            );
            $update->execute([
                'name' => $name,
                'timezone' => $normalized['timezone'],
                'business_id' => $businessId,
            ]);
            $setting = $this->pdo->prepare(
                'INSERT INTO business_settings (business_id, setting_key, setting_value)
                 VALUES (:business_id, :setting_key, :setting_value)
                 ON CONFLICT(business_id, setting_key)
                 DO UPDATE SET setting_value = excluded.setting_value'
            );
            foreach ($normalized['settings'] as $settingKey => $settingValue) {
                $setting->execute([
                    'business_id' => $businessId,
                    'setting_key' => $settingKey,
                    'setting_value' => $settingValue,
                ]);
            }
            $branch = $this->pdo->prepare(
                'UPDATE business_branches SET name = :name, code = :code, address = :address
                 WHERE business_id = :business_id'
            );
            $branch->execute([
                'business_id' => $businessId,
                'name' => $normalized['branch_name'],
                'code' => $normalized['branch_code'],
                'address' => $normalized['branch_address'] === '' ? null : $normalized['branch_address'],
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function normalizeProfile(array $profile): array
    {
        $currency = strtoupper(trim((string) ($profile['currency'] ?? 'EUR')));
        if (!in_array($currency, ['EUR', 'USD', 'GBP'], true)) {
            throw new InvalidArgumentException('Selecione uma moeda válida.');
        }
        $taxRate = trim((string) ($profile['tax_rate'] ?? '23'));
        if (!preg_match('/\A(?:\d{1,2}|100)(?:[.,]\d{1,2})?\z/', $taxRate)) {
            throw new InvalidArgumentException('Indique uma taxa fiscal entre 0 e 100.');
        }
        $taxRate = str_replace(',', '.', $taxRate);
        if ((float) $taxRate > 100) {
            throw new InvalidArgumentException('Indique uma taxa fiscal entre 0 e 100.');
        }
        $timezone = trim((string) ($profile['timezone'] ?? 'Europe/Lisbon'));
        if (!in_array($timezone, ['Europe/Lisbon', 'Europe/Madrid', 'Europe/London', 'UTC'], true)) {
            throw new InvalidArgumentException('Selecione um timezone válido.');
        }
        $address = trim((string) ($profile['address'] ?? ''));
        $phone = trim((string) ($profile['phone'] ?? ''));
        $taxId = trim((string) ($profile['tax_id'] ?? ''));
        if (mb_strlen($address) > 500 || mb_strlen($phone) > 60 || mb_strlen($taxId) > 60) {
            throw new InvalidArgumentException('Os dados de identificação excedem o tamanho permitido.');
        }
        $branchName = trim((string) ($profile['branch_name'] ?? 'Local principal'));
        $branchCode = strtoupper(trim((string) ($profile['branch_code'] ?? 'MAIN')));
        $branchAddress = trim((string) ($profile['branch_address'] ?? $address));
        if ($branchName === '' || mb_strlen($branchName) > 120
            || preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,39}\z/', $branchCode) !== 1
            || mb_strlen($branchAddress) > 500) {
            throw new InvalidArgumentException('Indique um local principal e um código válidos.');
        }

        return [
            'timezone' => $timezone,
            'branch_name' => $branchName,
            'branch_code' => $branchCode,
            'branch_address' => $branchAddress,
            'settings' => [
                'currency' => $currency,
                'tax_rate' => $taxRate,
                'address' => $address,
                'phone' => $phone,
                'tax_id' => $taxId,
                'business_name' => trim((string) ($profile['name'] ?? '')),
            ],
        ];
    }
}
