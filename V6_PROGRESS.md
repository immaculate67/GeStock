# GeStock V6 implementation progress

## Starting point

- The current development branch is `feat/gestock-v6`, based on V5 commit
  `19489b6`. The V1–V5 history is present and preserved; the current V6
  implementation is in the working tree and must be continued rather than
  rebuilt.
- The working tree contains uncommitted V6 changes across the application,
  tests, and this progress file. Preserve these changes and review them before
  editing overlapping code.
- The live database is `storage/gestock.sqlite`. The initial assessment found
  it valid and free of foreign-key violations. Functional tests use isolated
  temporary databases; do not reset or migrate the live database as a test.
- The V5 baseline suite passed 83 checks. The current V6 suite passes 98 checks,
  and PHP syntax checks pass.
- The attached master specification is available and is the authoritative
  requirements source. V1–V5 are historical context, not separate rebuilds.

## Existing V5 functionality to preserve

- Local PHP 8.1+, PDO/SQLite application; no external runtime dependencies.
- Portuguese (Portugal) interface, GeStock styling, responsive navigation and
  Europe/Lisbon business-day calculations.
- Product catalog, categories, suppliers, customers, user administration and
  business-name settings.
- Server-validated sale creation, historical price/cost snapshots, sale
  cancellation, stock updates and movement history, all with transaction
  rollback behavior.
- Dashboard, reports, CSV export, customer account separation, password
  hashing, CSRF validation, output escaping, prepared queries and protected
  database-file permissions.
- V5 additive schema compatibility and historical sales/movement retention.

## Implementation stages

Each stage must keep the existing test suite passing, add focused isolated
database tests, and record exact verification results below.

1. **Tenant and migration foundation** — Introduce versioned, transactional,
   additive migrations; businesses and memberships; assign existing V5 records
   to a preserved legacy business; add server-side active-business scoping to
   authentication and existing data workflows.
2. **Business setup and module configuration** — Add editable industry/template
   starting configurations, business creation, business settings, and
   per-business module configuration without generating catalog/customer/
   supplier/transaction records.
3. **Catalog architecture** — Add hierarchical business-scoped categories and
   product, service and resource types, variants and validated custom
   attributes; retain the existing stock/sales semantics for stock-tracked
   products.
4. **Permissions and audit history** — Add business ownership/member
   permissions, administrator verification workflows, append-only audit events,
   and safe history-preserving policies for edits and deletion.
5. **Customer and loyalty workflows** — Add business-scoped customer records,
   customer account association, loyalty balances/entries and customer-area
   workflows.
6. **Reusable sales and operational workflows** — Extend transactions to
   support configured modules and non-stock items; preserve checkout,
   cancellation, totals, inventory and reporting integrity.
7. **Hotel operations** — Add room/resource management, availability and
   reservation lifecycle with overlap prevention and audit history.
8. **Nightclub and ticketing operations** — Add events, pass issuance, secure
   QR payloads and server-side validation/redemption with replay protection.
9. **Search, frequently used items and appearance** — Add business-scoped
   search/shortcuts and persisted appearance settings with consistent
   Portuguese UI, responsive layouts and accessible interactions.
10. **Industry-specific modules and music feasibility** — Add only reusable,
    configuration-driven modules justified by the full specifications; document
    music-provider constraints and implement no decorative or unauthorized
    playback integration.
11. **End-to-end verification and release review** — Exercise all existing and
    new workflows against temporary databases; review migration rollback,
    tenant isolation, UI consistency, and remaining limitations.

## Architecture assessment

- The application remains a server-rendered PHP application, with V6
  multi-business foundations in the current working tree. Existing operational
  records are assigned to a legacy business, business-aware repositories and
  services scope supported workflows, and SQLite triggers guard same-business
  references. The full industry-specific data model is still outstanding.
- `Database::initialize()` creates the legacy-compatible base schema and
  applies versioned V6 migrations. The migration ledger currently covers tenant
  foundations, industry templates, and tenant/category integrity. The separate
  legacy user-table upgrade is transactional; review its interaction with
  migration ordering when changing the initializer.
- SQLite is suitable for the local single-process/lightweight application and
  transactional workflows. High-concurrency, multi-host SaaS operation would
  require a server database and deployment architecture, but is not a reason to
  discard the existing SQLite application.
- Industry templates should be data/configuration records, not seeded business
  inventory. QR redemption can be secure and transactional in this stack;
  camera scanning and QR image generation need browser/platform support or a
  carefully selected local library. No third-party service should receive
  private business data without an explicit business authorization.
- V5 provides no real payment processing, online booking channel, external
  identity provider, or music licensing/provider integration. These require
  separate provider, legal and operational decisions and must not be presented
  as completed functionality without end-to-end validation.

## Current implementation status

The current working tree implements the first V6 foundation, but does not yet
complete the full master specification:

- Added transactional, versioned schema migrations for businesses, business
  memberships, per-business settings and modules, industry templates, nested
  categories, tenant-scoped records, and cross-business reference guards.
- Preserved legacy records by assigning them to a legacy business; existing
  sales retain unknown historical costs rather than receiving invented values.
- Added business-aware authentication, active-business selection, business
  creation from configuration templates, and per-business module settings.
- Added business-scoped branches and saleable item types (product, service and
  resource), with migration-safe defaults, active/inactive configuration,
  cross-business validation, and catalog integration that preserves existing
  stock-tracked products.
- Business creation now accepts a validated selection of supported operational
  modules; user management and settings remain enabled for later configuration.
- Scoped the existing catalog, stock, sales, reports, settings, and user
  workflows to the active business. New templates do not create operational
  records.
- Restricted password resets to eligible members of the active business; a
  reset cannot affect the owner or an account shared with another business.
- Added regression coverage for migration rollback, legacy data preservation,
  tenant isolation, templates, module setup, and password reset boundaries.

Not implemented or not yet verified against the complete specification:

- Item variants, custom attributes, and the full business setup/review wizard.
- Granular permissions, append-only audit history, and secure administrator
  verification.
- Industry-specific hotel, ticketing, appointments, rentals, manufacturing,
  loyalty, imports, and central search workflows.
- Demo isolation, appearance preferences, and the complete responsive
  experience.
- Full acceptance coverage for each industry workflow and a final live UI
  review.

### Progress log

- Inspected current V6 branch, V1–V5 Git history, active working-tree changes,
  migration implementation, business setup, authentication, user management,
  tenant-scoped queries, and tests. The active branch is based on V5; no V6
  implementation was overwritten.
- `php tests/run.php`: **101 checks passed** after the catalog architecture
  additions, using isolated temporary databases.
- `find app public scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l`:
  **all PHP files passed** after the current implementation changes.
- `git diff --check`: passed.
- No command in this session modified `storage/gestock.sqlite`.

### Latest checkpoint

- Implemented migration version 4 with `business_branches`, `item_types`, and
  product item-type references. Existing products default to the stock-tracked
  product type; existing businesses receive a principal branch and the three
  built-in types without generated operational records.
- Added `BusinessConfigurationService` and settings controls for adding
  business locations and enabling or disabling item types. Disabled types are
  rejected server-side and all reads/writes remain business-scoped.
- Updated product repository joins and validation while retaining the existing
  product/stock workflow as the default.
- Verification: **101 isolated functional checks passed**, all PHP syntax checks
  passed, and `git diff --check` passed.
- Exact next task: implement item variants and validated per-item custom
  attributes, then add focused migration and tenant-isolation tests before
  starting append-only audit events and granular membership permissions.

### V6.3 checkpoint

- Added additive migration version 5 for business-scoped product variants,
  independent SKUs/barcodes/price overrides and stock fields, plus validated
  custom attribute definitions and product values.
- Added `CatalogVariantService` with tenant checks, variant uniqueness,
  configurable text/integer/decimal/boolean/date/select fields, required
  values, defaults and select-option validation.
- Added isolated coverage for variant identity/stock, duplicate identifiers,
  cross-business references, custom defaults, required values and invalid
  options.
- Verification: `php tests/run.php` passed **109 isolated checks**; all PHP
  syntax checks passed; `git diff --check` passed. No command in this session
  modified `storage/gestock.sqlite`.
- Next task: connect variants and custom attributes to the catalog UI and
  stock/sales workflows, then implement append-only audit events and
  granular membership permissions.

### V6.4 connected catalog and checkout milestone

- Connected variants to the product interface: product lists show variant
  counts, product details show variant identifiers/SKUs/barcodes/prices/stock,
  and product editing can add variants and edit validated custom attributes.
- Connected variants to checkout: users can select stocked base products or
  variants, server-side totals use variant price overrides, sale items preserve
  variant snapshots, and cancellation restores the variant stock.
- Connected variant movements to stock management and movement history,
  including manual entry, exit and correction handling and visible variant
  labels.
- Variant SKU/barcode searches now match from the product catalog search.
- Verification: `php tests/run.php` passed **112 isolated checks** after the
  connected workflow additions; PHP syntax, JavaScript syntax and
  `git diff --check` passed. The live database was not modified.
- Remaining limitation for this milestone: variant editing/deactivation and
  custom-attribute definition management are not yet exposed as dedicated
  settings screens; audit history, granular permissions, purchases and the
  industry workflows remain unfinished.

### V6.5 auditoria, permissões e operações integradas

- Added additive migration version 7 for append-only audit events, granular
  membership permissions, branch access records, purchase orders and line
  items, reusable operational records, hotel rooms/stays, event passes and
  stock locations.
- Added `AuditPermissionService` for permission checks, append-only audit
  searches and branch/permission administration. Owner and administrator roles
  retain full access; employee defaults and explicit overrides are evaluated
  on the server.
- Added `PurchaseService` with tenant-scoped orders, supplier and product
  validation, cost snapshots and transactional stock receipts for base products
  and variants.
- Added `OperationalService` with overlap-safe hotel reservations,
  check-in/check-out lifecycle operations, ticket pass issuance and
  replay-prevented pass redemption.
- Added functional Portuguese pages for auditoria, compras and operações.
  Settings now include persisted light/dark/system appearance preferences,
  and user administration exposes granular permission controls.
- Verification: `php tests/run.php` passed **117 isolated checks**. PHP syntax
  checks passed for all application and test PHP files, JavaScript syntax
  passed, and `git diff --check` passed. No live database command was used to
  migrate or reset `storage/gestock.sqlite`.
- Remaining work: dedicated category bulk/archive workflows, purchase line
  variant editing and cancellation, restaurant and manufacturing-specific
  interfaces, central global search, branch stock-location enforcement,
  supplier/customer purchase-history views and the final responsive UI review.

## Checkpoint V6.4 congelado

- Data do checkpoint: 2026-11-19.
- Implementation work is stopped; no feature work was performed for this
  checkpoint.
- Current branch: `feat/gestock-v6`.
- Current HEAD: `13ce53c V6.4`.
- Working state: no staged changes; `V6_PROGRESS.md` is unstaged and
  `V6_CONTINUATION.md` is untracked.
- Latest implemented schema migration: version 7.
- Verification before checkpoint freeze: `php tests/run.php` passed 117 checks;
  PHP syntax, JavaScript syntax and `git diff --check` passed.
- Complete continuation record: `V6_CONTINUATION.md`.
- This checkpoint does not represent completion of the full master specification.
- Exact next priority: complete the progressive business creation/custom
  configuration wizard and pre-creation review, then continue purchases,
  branch transfers, category bulk workflows and uniform permission enforcement.
