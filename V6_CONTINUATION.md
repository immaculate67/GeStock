# GeStock V6 — ponto de continuação V6.7

## 1. Visão geral do projeto

GeStock é uma aplicação web local em PHP 8.1+, SQLite/PDO, HTML, CSS e JavaScript
para gestão de produtos, stock, vendas e negócios. O objetivo da especificação
mestra V6 é transformá-la numa plataforma modular e multi-negócio sem reconstruir
a base V1–V5, preservando transações históricas e mantendo isolamento estrito por
negócio e filial.

A aplicação continua a ser server-rendered e sem dependências externas. A interface
deve permanecer em português europeu. A especificação mestra exige expansão para
criação universal de negócios, catálogo completo, compras, inventário, vendas,
clientes, fornecedores, operações por setor, auditoria, permissões, relatórios,
pesquisa, definições e aparência. A implementação deste checkpoint é parcial:
fundação empresarial e catálogo estão conectados; várias áreas operacionais ainda
carecem de interfaces completas ou integração profunda.

## 2. Branch atual e commit recente

- Branch atual: `feat/gestock-v6`
- HEAD recente: `13ce53c V6.4`
- Commit de base V5: `19489b6`
- Estado final do Git: não existem alterações staged.
- Estado final: `V6_PROGRESS.md` está unstaged; `V6_CONTINUATION.md` está untracked.
- O trabalho funcional posterior ao V6.3 foi preservado no commit `13ce53c V6.4`.
- Nenhum commit novo, merge, push ou troca de branch foi realizado por este checkpoint.

## 3. Funcionalidade V1–V5 preservada

- Aplicação PHP 8.1+ com SQLite/PDO e ficheiros privados protegidos.
- Registo, autenticação por palavra-passe segura, sessão e proteção CSRF.
- Interface em português de Portugal, responsive, com GeStock styling.
- Produtos, categorias, fornecedores e clientes.
- Administração de utilizadores e nome do negócio.
- Vendas com validação no servidor, cálculo exato em cêntimos e snapshots
  históricos de preço e custo.
- Anulação de vendas e reposição de stock.
- Movimentos de stock com antes/depois e histórico.
- Dashboard, relatórios, exportação CSV e cálculos por timezone Europe/Lisbon.
- Conta separada de cliente.
- Compatibilidade e migrações aditivas V5 para dados históricos.
- Movimentos e vendas históricas preservados sem inventar custos desconhecidos.

## 4. Funcionalidade V6 implementada

### Fundação multitenant

- Negócios independentes e filiais.
- Associações utilizador-negócio com perfil de proprietário, administrador,
  colaborador ou cliente.
- Seleção de negócio ativo na sessão.
- Escopo de dados por `business_id`.
- Triggers SQLite para impedir referências cruzadas de negócio.
- Preservação do legado num negócio legado.
- Templates industriais e seleção inicial de módulos.
- Configuração posterior de nome, módulos, locais e tipos de artigo.

### Catálogo

- Categorias hierárquicas, com validação de ciclos e isolamento por negócio.
- Tipos de artigo produto, serviço e recurso.
- Produtos existentes convertidos no tipo produto.
- Variantes com identificador, SKU, barcode, preço, custo, stock e estado.
- Atributos personalizados com texto, inteiro, decimal, booleano, data e seleção.
- Validação de valores obrigatórios, predefinições e opções.
- Produtos, variantes e atributos ligados à UI de produtos, stock e checkout.
- Anulação de venda restaura stock de variante.
- Pesquisa de catálogo por SKU/barcode de variante.

### Compras e operações

- Encomendas a fornecedores e linhas de produto/variante.
- Receção transacional de stock e atualização do custo histórico.
- Estados de encomenda: rascunho, enviada, recebida, receção parcial,
  cancelada e fechada.
- Interface funcional de compras.
- Registos operacionais reutilizáveis para reserva, marcação, aluguer,
  adesão, assinatura, evento/passe, produção e outro.
- Quartos de hotel, estadias, overlap-prevention e estados de check-in/out.
- Emissão de passes de evento e rejeição de reutilização após entrada.
- Interface funcional de operações.

### Auditoria e permissões

- Eventos append-only protegidos por triggers contra alteração e eliminação.
- Pesquisa por negócio, texto e ação.
- Permissões granulares por utilizador.
- Restrição segura de páginas administrativas.
- Registo de locais de acesso por membro.
- Interface de auditoria pesquisável.
- Interface de permissões na administração de utilizadores.

### Aparência e interfaces

- Tema claro, escuro e sistema persistido por negócio.
- Variáveis CSS para modo escuro.
- Navegação inclui negócios, compras, operações e auditoria conforme módulos.
- Interface server-rendered em português europeu.

## 5. Trabalho detalhado mais recente: V6.4 e estado posterior

O marco denominado V6.4 conectou variantes à aplicação:

- Migração versão 5 para variantes e atributos personalizados.
- Contadores de variantes na listagem de produtos.
- Identificadores, SKU, barcode, preços e stock mostrados nos detalhes.
- Criação de variantes e edição de atributos no fluxo de produto.
- Seleção de produto ou variante no checkout.
- Preço de variante usado no servidor e snapshots em `sale_items`.
- Cancelamento restaura corretamente a variante.
- Ajustes manuais e movimentos de stock aceitam variante.
- Pesquisa por SKU/barcode de variante.
- Cobertura isolada para unicidade, referências cruzadas, defaults, campos
  obrigatórios e opções inválidas.

Após o marco V6.4, o estado atual contém trabalho adicional não confirmado
como migração e interface:

- Migração versão 7 para auditoria, permissões, compras, operações, hotéis,
  passes e localizações de stock.
- Serviço `AuditPermissionService`.
- Serviço `OperationalService`.
- Serviço `PurchaseService`.
- Páginas `audit.php`, `operations.php` e `purchases.php`.
- Configuração de tema em `settings.php`.
- Permissões granulares em `users.php`.

Este checkpoint não deve ser interpretado como conclusão de todos os marcos
específicos da especificação mestra.

## 6. Ficheiros, serviços, migrações e interfaces relevantes

### Núcleo

- `app/Database.php` — ligação SQLite, inicialização e migrador.
- `app/DatabaseMigrations.php` — migrações aditivas das versões 1–7.
- `app/bootstrap.php` — carregamento central de classes e sessão.
- `app/Auth.php` — autenticação e isolamento de palavras-passe/membros.
- `app/helpers.php` — CSRF, roles, módulos, escape e validação.

### Negócios e configuração

- `app/BusinessService.php`
- `app/BusinessRepository.php`
- `app/BusinessConfigurationService.php`
- `app/IndustryTemplateService.php`
- `public/businesses.php`
- `public/settings.php`

### Catálogo e stock

- `app/CatalogVariantService.php`
- `app/ProductRepository.php`
- `app/ProductValidator.php`
- `app/InventoryService.php`
- `public/products.php`
- `public/categories.php`
- `public/stock.php`

### Vendas e relatórios

- `public/sale.php`
- `public/sales.php`
- `public/reports.php`
- `app/BusinessStatisticsService.php`

### Compras, operações e auditoria

- `app/PurchaseService.php`
- `app/OperationalService.php`
- `app/AuditPermissionService.php`
- `public/purchases.php`
- `public/operations.php`
- `public/audit.php`
- `public/users.php`

### Interface

- `app/views/header.php`
- `app/views/sidebar.php`
- `public/assets/css/style.css`
- `public/assets/js/app.js`

### Testes e checkpoint

- `tests/run.php`
- `V6_PROGRESS.md`
- `V6_CONTINUATION.md`

## 7. Versão atual da base de dados

A migração máxima aplicada pelo suite isolado é a versão **8**.

Resumo das versões conhecidas:

- Versão 1: empresas, membros, configurações, módulos e integração tenant legada.
- Versão 2: templates industriais.
- Versão 3: integridade tenant, categorias hierárquicas e referências cruzadas.
- Versão 4: filiais e tipos de artigo.
- Versão 5: variantes e atributos personalizados.
- Versão 6: referências de variante em vendas e movimentos.
- Versão 7: auditoria, permissões, locais de membros, compras, operações,
  hotéis, passes e localizações de stock.
- Versão 8: receções parciais, devoluções e histórico de recibos/devoluções
  em linhas de encomenda.

Não foram usados comandos contra `storage/gestock.sqlite` para migrar ou alterar
a base ativa durante este checkpoint.

## 8. Testes e resultados

Resultado verificado neste checkpoint:

- `php tests/run.php`: **117 verificações passaram** em bases SQLite temporárias.
- Syntax PHP: todos os ficheiros PHP em `app`, `public`, `scripts` e `tests`
  passaram na verificação `php -l`.
- JavaScript: `node --check public/assets/js/app.js` passou.
- `git diff --check` passou.
- Nenhum teste alterou a base ativa.

Cobertura inclui migração rollback, dados legados, isolamento tenant,
categorias, templates, membros, palavras-passe, variantes, atributos,
vendas históricas, stock, hotelaria, passes, compras e auditoria.

## 9. Funcionalidades parcialmente implementadas

- Configuração de negócio: templates e módulos existem, mas falta revisão final
  e campos completos de moeda, impostos, localização e identidade.
- Catálogo: categorias, variantes e atributos existem, mas falta edição dedicada
  de variante/desativação, gestão dedicada de definições, merge/arquivo/restauro
  e operações em massa completas.
- Compras: criação e receção existem, mas falta edição/cancelamento de encomendas,
  receções parciais, devoluções e gestão histórica avançada.
- Clientes/fornecedores: CRUD e histórico básico existem em partes, mas falta
  histórico unificado de compras, classificações, fidelização e relatórios dedicados.
- Operações: modelos genéricos e hotel/passes existem, mas não há interfaces
  completas de menus/restaurante, ingredientes/alérgenos, aluguer de ativos,
  condições/devoluções, produção ou assinaturas com faturação real.
- Auditoria: eventos e UI existem, mas chamadas das principais mutações ainda
  não são todas cobertas por `record()`.
- Permissões: o serviço existe, mas nem todas as rotas relevantes usam granular
  checks antes da ação.
- Aparência: tema persistido existe, mas falta revisão responsiva completa,
  acessibilidade em todos os controlos e suporte de movimento reduzido.
- Relatórios: V5 cobre vendas/base, mas faltam relatórios completos por fornecedor,
  cliente, devoluções, taxas, inventário por local e indústria.
- Pesquisa global: não implementada.

## 10. Funcionalidades ainda por terminar

- Wizard progressivo completo com catálogo extenso de setores e negócio personalizado.
- Configuração posterior de moeda, impostos, localização e identidade.
- Filiais e inventário por filial/local com transferências.
- Categoria: mover, merge, arquivo/restauro, bulk operations, import/export.
- Variantes/atributos: gestão completa e histórica.
- Compras: devoluções, cancelamentos, receção parcial, fornecedores, custos e
  histórico de preço.
- Vendas: pagamentos mais completos, reembolsos/devoluções, recibos/faturas,
  descontos e taxa por configuração.
- Clientes: fidelização, classificações e histórico por negócio.
- Hotéis: housekeeping e manutenção ainda não integradas.
- Restaurantes: menus, ingredientes, modificadores, alergénios e preparação.
- Discotecas: limite de capacidade por evento e QR/câmara, além de passe textual.
- Salões/gins: staff, duração e agenda.
- Alugueres: ativos, depósitos, condição e devolução.
- Produção: matérias-primas, ordens de produção e stock de acabados.
- Wholesale: preços por volume e quantidades mínimas.
- Pesquisa global e filtros recentes.
- Integrações externas configuráveis apenas quando autorizadas.
- Verificação live final em desktop/tablet/mobile.

## 11. Bugs, limitações e dívida técnica

- O trabalho funcional V6 está preservado no commit `13ce53c V6.4`; os dois
  documentos deste checkpoint permanecem fora desse commit até decisão explícita.
- A base ativa não deve ser aberta/migrada por ferramentas de teste.
- `V6_PROGRESS.md` ainda contém afirmações/status antigos acima das suas secções
  mais recentes; ambas precisam de leitura como histórico, não como verdade única.
- Nenhum ficheiro `AGENTS.md` ou especificação mestra completa foi encontrado no
  diretório neste momento.
- Não foi implementado QR real nem leitura por câmara; os passes são por código
  textual seguro e validação no servidor.
- Faltam limites de capacidade em passes e eventos.
- `PurchaseService::receive()` recebe toda a quantidade da encomenda de uma vez;
  receções parciais ainda não existem.
- A UI de compras suporta apenas uma linha nesta versão.
- Os locais de stock existem no esquema, mas não estão integrados no inventário
  nem nas transferências.
- O modo escuro foi adicionado com CSS, mas precisa de revisão visual e
  de `prefers-reduced-motion`.
- Nem todas as rotas de mutação chamam `AuditPermissionService::record()`.
- Não há revogação central de privilégios além dos checks existentes.
- O modelo de permissões granulares não cobre cada operação existente
  (por exemplo vendas/anulações) de forma uniforme.
- Fluxos específicos de hotel usam uma implementação reutilizável, não devem ser
  apresentados como um sistema hoteleiro completo.

## 12. Próximas prioridades exatas

1. Concluído: wizard progressivo completo de criação/configuração de negócio,
   perfil fiscal/localização e revisão pré-criação.
2. Concluído: compras — receções parciais, cancelamento, devolução, histórico
   de linhas e UI multi-linha.
3. Completar transferências de stock por filial/local.
4. Concluído: transferências de stock por filial/local.
5. Completar categoria: arquivo/restauro, mover, merge e operações em massa.
6. Converter permissões em enforcement uniforme em todas as rotas mutáveis.
7. Cobrir todas as mutações relevantes com auditoria append-only.
8. Implementar menus/restaurante, marcações/salões e produção de forma conectada.
9. Implementar alugueres/ativos, fidelização e clientes/fornecedores avançados.
10. Implementar pesquisa global permission-aware e relatórios por setor.
11. Executar revisão UI, acessibilidade, mobile e suite final.

## 13. Comandos exatos para retomar

```bash
git --no-pager status --short --branch
git --no-pager log -5 --oneline --decorate
php tests/run.php
find app public scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/js/app.js
git --no-pager diff --check
```

Não executar a aplicação com migração automática sobre `storage/gestock.sqlite`
durante testes sem decisão explícita. O suite usa bases temporárias.

## 14. Preservação da arquitetura e dos dados

- Não reconstruir V1–V5.
- Não apagar nem resetar ficheiros ou dados.
- Usar apenas migrações aditivas.
- Preservar o estado histórico de vendas, itens, movimentos e custos.
- Scope todo o SQL por `business_id`.
- Validar também filial/local no servidor.
- Aplicar CSRF em POST.
- Escapar saída com `e()`.
- Usar transações em operações de stock e operações compostas.
- Não inventar dados de demonstração em negócios reais.
- Não apresentar botões ou páginas como completos sem operações persistentes.
- Não atribuir custos históricos desconhecidos.
- Português europeu em UI e documentação.
- Não introduzir frameworks, Composer, npm ou infraestrutura nova.

## 15. Decisões e restrições da especificação mestra

- Negócio pode ser template industrial ou personalizado.
- Cada negócio é tenant independente.
- Registos partilhados devem manter snapshot histórico.
- Produtos, serviços e recursos usam tipos configuráveis.
- Variantes têm identidade independente.
- Inventário, vendas e compras devem manter integridade transacional.
- Permissões são enforceadas no servidor.
- Auditoria é append-only.
- Hotéis precisam de quartos, disponibilidade, reservas, check-in/out,
  housekeeping e manutenção.
- Restaurantes precisam de menus, ingredientes, modificadores, alergénios e
  preparação.
- Passes de eventos precisam de validação no servidor e prevenção de duplicado.
- Alugueres precisam de ativos, depósito, estado e devolução.
- Produção precisa de matérias-primas e produção/acabados.
- Reports devem usar datas locais de Lisboa e dados reais.

## 16. Checkpoint V6.6 retoma o wizard

- Data: 2026-11-20.
- Branch: `feat/gestock-v6`.
- HEAD preservado: `0dee05d V6.4.2`.
- Estado: alterações deste checkpoint ainda não confirmadas; não foram usadas
  operações destrutivas e a base ativa não foi modificada.
- Concluído: assistente progressivo de quatro etapas, revisão pré-criação,
  persistência de moeda, taxa fiscal, timezone, identidade, morada, telefone,
  NIF e local principal, mais edição correspondente nas definições.
- Concluído pelo servidor: normalização e validação do perfil, atualização
  transacional do negócio/local e testes isolados de criação assistida.
- Verificação: 118 testes isolados passaram; PHP syntax, JavaScript syntax e
  `git diff --check` passaram.
- Próximo bloqueio exato: compras — receções parciais, cancelamento, devolução,
  linhas múltiplas e histórico avançado; só depois iniciar transferências
  entre filiais e locais.

## 17. Checkpoint V6.7 concluiu compras

- Data: 2026-11-20.
- Branch: `feat/gestock-v6`.
- HEAD preservado: `0dee05d V6.4.2`.
- Estado: a implementação nova permanece não confirmada; não foi usado git
  reset/revert/checkout e a base ativa não foi modificada.
- Migração aditiva 8: quantidade recebida/devolvida por linha, recibos e
  devoluções de compra.
- Concluído: receção completa/parcial, cancelamento pré-receção, devolução
  transacional com atualização de stock, UI de linhas múltiplas e cobertura
  isolada de variantes de stock.
- Verificação: 119 testes isolados passaram; PHP syntax, JavaScript syntax e
  `git diff --check` passaram.
- Próximo bloqueio exato: completar transferências de stock por filial/local,
  incluindo criação, aprovação/execução, movimentos antes/depois e isolamento.

## 18. Checkpoint V6.8 concluiu transferências

- Data: 2026-11-20.
- Branch: `feat/gestock-v6`.
- HEAD preservado: `0dee05d V6.4.2`.
- Estado: implementação nova permanece não confirmada; não foi usado git reset,
  revert ou checkout e a base ativa não foi modificada.
- Migração aditiva 9: saldos por filial/localização, rascunhos e histórico de
  transferências, além de referências de origem/destino nos movimentos.
- Concluído: criação de rascunhos, execução/cancelamento transacional,
  isolamento por negócio, linhas produto/variante, saldo por localização e
  histórico visível na interface de stock.
- As filiais novas criadas nas definições recebem automaticamente uma
  localização principal de stock.
- Verificação: 122 testes isolados passaram; PHP syntax, JavaScript syntax e
  `git diff --check` passaram.
- Próximo bloqueio exato: categorias — arquivo/restauro, mover, merge e
  operações em massa; só depois enforcement uniforme de permissões e auditoria.

NEXT SESSION — EXACT CONTINUATION INSTRUCTIONS

O próximo Copilot Agent deve:

1. Ler a especificação mestra completa fornecida pelo utilizador; não existe
   atualmente nenhum ficheiro mestre localizado no repositório.
2. Ler `AGENTS.md` se aparecer ou for criado; neste checkpoint não existe.
3. Ler `V6_PROGRESS.md` e `V6_CONTINUATION.md`.
4. Inspecionar a branch atual e toda a árvore de trabalho.
5. Continuar exatamente a partir deste estado, sem reconstruir V1–V5.
6. Nunca descartar, resetar, reverter ou sobrescrever trabalho V6 existente.
7. Não commitar nem fazer push salvo autorização explícita.
8. Preservar os ficheiros unstaged e untracked finais: `V6_PROGRESS.md` e
   `V6_CONTINUATION.md`.
9. Estado retomado: wizard completo e compras parciais/conclusão já estão
   implementados.
10. Retomar pela prioridade exata: categorias — arquivo/restauro, mover, merge
    e operações em massa; depois enforcement uniforme de permissões/auditoria.
11. Implementar workflows completos e visíveis ao utilizador; não declarar
    módulos completos por existência de tabelas ou serviços isolados.
12. Executar os testes temporários, syntax checks, JavaScript check e
    `git diff --check` após mudanças relevantes.
13. Atualizar `V6_PROGRESS.md` e `V6_CONTINUATION.md` em cada novo checkpoint,
    registando trabalho concluído, limitações e próximo bloqueio exato.
