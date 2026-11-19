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
        return $this->pdo->query(
            'SELECT template_key, name, description FROM industry_templates ORDER BY name COLLATE NOCASE'
        )->fetchAll();
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
        ?array $enabledModules = null
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
}
