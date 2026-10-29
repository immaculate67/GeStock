# GeStock

GeStock é uma aplicação local de gestão de produtos, stock e vendas para
pequenos negócios, desenvolvida como PAP. A interface está em português
europeu e utiliza PHP, PDO/SQLite, HTML, CSS e JavaScript sem dependências
externas.

## Requisitos

- PHP 8.1 ou superior com `pdo_sqlite`
- Permissão de escrita na pasta privada `storage/`

Não é necessário instalar MySQL, Composer, npm, Docker ou serviços externos.

## Preparação e primeira conta

Na raiz do projeto, inicialize a base de dados:

```sh
php scripts/init_db.php
```

O SQLite fica em `storage/gestock.sqlite`, fora da pasta pública. A inicialização
é repetível e aplica upgrades de esquema sem apagar produtos ou outros dados
existentes. As tabelas usam chaves estrangeiras e o SQLite ativa a respetiva
verificação em cada ligação.

Inicie o servidor PHP:

```sh
php -S 0.0.0.0:8000 -t public
```

Abra `http://localhost:8000/setup.php` para criar a primeira conta de
administrador. O assistente só fica disponível enquanto ainda não existir
nenhum utilizador; não há credenciais predefinidas no código. Use uma
palavra-passe com pelo menos 12 caracteres. Depois da criação, inicie sessão
em `login.php`. Os administradores podem criar e desativar contas de
colaboradores e administradores na área **Utilizadores**.

Para executar uma instância de desenvolvimento com outra base de dados,
defina opcionalmente `GESTOCK_DB_PATH` antes de iniciar o servidor. Por
exemplo:

```sh
GESTOCK_DB_PATH=/tmp/gestock-dev.sqlite php -S 0.0.0.0:8000 -t public
```

## Módulos

- **Painel:** totais do catálogo e do stock, valor de inventário ao custo,
  receita do dia e do mês, vendas recentes e alertas de reposição.
- **Produtos:** pesquisa, filtros, ordenação, paginação, consulta, criação,
  edição e eliminação protegida; códigos únicos, custos, preços, categorias,
  fornecedores e mínimos de stock.
- **Categorias, fornecedores e clientes:** operações de consulta e manutenção,
  com validação; referências usadas são protegidas ou anuladas com segurança.
- **Vendas:** checkout com artigos/quantidades, cliente opcional, preços
  históricos e totais calculados no servidor; insuficiência de stock bloqueia
  a venda. A anulação repõe o stock e não pode ser repetida.
- **Movimentos de stock:** entradas, saídas e correções com motivo obrigatório,
  histórico com quantidades anteriores e posteriores, e movimentos automáticos
  associados a vendas e anulações.
- **Relatórios:** receita por período, totais diários/mensais, artigos mais
  vendidos, valor de inventário, alertas e histórico de movimentos; exportação
  de vendas em CSV.
- **Utilizadores e definições:** gestão de contas e configuração básica do
  negócio, disponível apenas para administradores.

Não existe processamento real de pagamentos. As vendas são registos internos
de operação e não representam pagamentos cobrados.

## Segurança e integridade

As páginas exigem sessão autenticada; permissões administrativas são verificadas
no servidor. As palavras-passe usam `password_hash`, os formulários com
alterações verificam CSRF, a saída é escapada e as consultas usam instruções
preparadas. O stock é alterado por transações que mantêm os movimentos e as
quantidades sincronizados. A base de dados fica fora da raiz pública e recebe
permissões restritas no sistema de ficheiros.

## Testes

Execute os testes funcionais isolados:

```sh
php tests/run.php
```

Os testes usam uma base de dados temporária e cobrem a compatibilidade do
catálogo existente, inicialização repetida, categorias, fornecedores, clientes,
autenticação, vendas, validação de stock, rollback transacional, movimentos,
anulação e cálculos de relatório.

Verifique a sintaxe PHP:

```sh
find app public scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
```
