<?php
declare(strict_types=1);

final class IndustryTemplateService
{
    public static function apply(PDO $pdo, int $businessId, string $templateKey): void
    {
        $template = $pdo->prepare(
            'SELECT configuration FROM industry_templates WHERE template_key = :template_key'
        );
        $template->execute(['template_key' => $templateKey]);
        $configuration = $template->fetchColumn();
        if (!is_string($configuration)) {
            $templateKey = 'custom';
            $template->execute(['template_key' => $templateKey]);
            $configuration = $template->fetchColumn();
        }
        if (!is_string($configuration)) {
            throw new RuntimeException('A configuração do modelo de negócio não está disponível.');
        }

        $decoded = json_decode($configuration, true, 512, JSON_THROW_ON_ERROR);
        $modules = $decoded['modules'] ?? null;
        if (!is_array($modules)) {
            throw new RuntimeException('A configuração do modelo de negócio não é válida.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO business_modules (business_id, module_key, enabled, configuration)
             VALUES (:business_id, :module_key, :enabled, :configuration)'
        );
        foreach ($modules as $moduleKey => $enabled) {
            if (!is_string($moduleKey) || preg_match('/\A[a-z][a-z0-9_]{1,39}\z/', $moduleKey) !== 1 || !is_bool($enabled)) {
                throw new RuntimeException('A configuração do modelo contém um módulo inválido.');
            }
            $moduleConfiguration = $decoded['module_configuration'][$moduleKey] ?? [];
            $insert->execute([
                'business_id' => $businessId,
                'module_key' => $moduleKey,
                'enabled' => (int) $enabled,
                'configuration' => json_encode($moduleConfiguration, JSON_THROW_ON_ERROR),
            ]);
        }

        $setting = $pdo->prepare(
            'INSERT INTO business_settings (business_id, setting_key, setting_value)
             VALUES (:business_id, :setting_key, :setting_value)'
        );
        $setting->execute([
            'business_id' => $businessId,
            'setting_key' => 'industry_template',
            'setting_value' => $templateKey,
        ]);
        $setting->execute([
            'business_id' => $businessId,
            'setting_key' => 'industry_configuration',
            'setting_value' => json_encode($decoded, JSON_THROW_ON_ERROR),
        ]);
        $catalogTablesReady = (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'
             AND name IN ('item_types', 'business_branches')"
        )->fetchColumn() === 2;
        if ($catalogTablesReady) {
            $type = $pdo->prepare(
                'INSERT OR IGNORE INTO item_types (business_id, type_key, label, stock_tracked)
                 VALUES (:business_id, :type_key, :label, :stock_tracked)'
            );
            foreach ([['product', 'Produto', 1], ['service', 'Serviço', 0], ['resource', 'Recurso', 0]] as $itemType) {
                $type->execute([
                    'business_id' => $businessId,
                    'type_key' => $itemType[0],
                    'label' => $itemType[1],
                    'stock_tracked' => $itemType[2],
                ]);
            }
            $branch = $pdo->prepare(
                'INSERT OR IGNORE INTO business_branches (business_id, name, code)
                 VALUES (:business_id, \'Local principal\', \'MAIN\')'
            );
            $branch->execute(['business_id' => $businessId]);
        }
    }

    public static function definitions(): array
    {
        return [
            'custom' => [
                'name' => 'Negócio personalizado',
                'description' => 'Base flexível para configurar as áreas do seu negócio.',
                'configuration' => [
                    'modules' => self::coreModules(),
                    'suggested_categories' => [],
                ],
            ],
            'retail' => [
                'name' => 'Comércio',
                'description' => 'Catálogo, inventário, vendas, clientes e fornecedores.',
                'configuration' => [
                    'modules' => self::coreModules(),
                    'suggested_categories' => ['Produtos'],
                ],
            ],
            'hospitality' => [
                'name' => 'Restauração e hotelaria',
                'description' => 'Operação comercial preparada para serviço ao cliente.',
                'configuration' => [
                    'modules' => self::coreModules(),
                    'suggested_categories' => ['Produtos', 'Serviços'],
                ],
            ],
            'services' => [
                'name' => 'Serviços',
                'description' => 'Catálogo e gestão de relações para negócios de serviços.',
                'configuration' => [
                    'modules' => self::coreModules(),
                    'suggested_categories' => ['Serviços'],
                ],
            ],
            'hotel' => [
                'name' => 'Hotel',
                'description' => 'Modelo de partida para alojamento e operações hoteleiras.',
                'configuration' => [
                    'modules' => self::coreModules(),
                    'suggested_categories' => ['Alojamento', 'Serviços'],
                ],
            ],
            'nightclub' => [
                'name' => 'Discoteca',
                'description' => 'Modelo de partida para eventos, bilhética e operação de discoteca.',
                'configuration' => [
                    'modules' => self::coreModules(),
                    'suggested_categories' => ['Eventos', 'Serviços'],
                ],
            ],
        ];
    }

    private static function coreModules(): array
    {
        return [
            'catalog' => true,
            'inventory' => true,
            'sales' => true,
            'customers' => true,
            'suppliers' => true,
            'reports' => true,
            'users' => true,
            'settings' => true,
            'purchases' => false,
            'operations' => false,
            'audit' => true,
        ];
    }
}
