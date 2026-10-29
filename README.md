# GeStock

GeStock is a small-business stock and sales management application built as a
Portuguese vocational school's PAP project. The current milestone provides a
SQLite-backed product and stock module alongside the existing dashboard.

## Technology

- PHP 8.1 or later
- PDO with the SQLite driver
- HTML, CSS and vanilla JavaScript
- SQLite, stored locally without a separate database server

No Composer, npm, XAMPP or other package installation is required.

## Current features

- Responsive dashboard with product, low-stock and out-of-stock counts from
  SQLite.
- Product list searchable by product name or SKU.
- Create, edit and delete product records.
- Stock quantity and minimum-stock tracking with out-of-stock, low-stock and
  in-stock indicators.
- Server-side validation, prepared database statements, CSRF protection and
  confirmation before deleting a product.
- Sales figures and sales history remain clearly labelled sample data.
- Customers, sales processing, authentication and settings are not implemented.

## Project structure

```text
app/
├── Database.php
├── ProductRepository.php
├── ProductValidator.php
├── bootstrap.php
├── helpers.php
└── views/sidebar.php
public/
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── index.php
└── products.php
scripts/
└── init_db.php
tests/
└── run.php
storage/                 # Created as needed; private SQLite database
```

The `app/` and `storage/` directories are outside the public web root. PHP pages
and static assets served to the browser are in `public/`.

## Database setup

The application safely creates the private `storage/` directory and the
`products` table the first time it needs the database. To initialise it
explicitly from the project root, run:

```sh
php scripts/init_db.php
```

The SQLite file is `storage/gestock.sqlite`, outside `public/`. The database and
its journal files are excluded from Git. Product prices are stored as integer
cents to avoid floating-point rounding errors.

## Run in GitHub Codespaces

From the project root, start PHP's built-in development server:

```sh
php -S 0.0.0.0:8000 -t public
```

When Codespaces prompts about port 8000, choose **Open in Browser**. If no
prompt appears, open the **Ports** tab and follow the forwarded URL for port
8000. The dashboard is at `/` and product management is at `/products.php`.

## Product and stock management

Open **Products** in the sidebar or go directly to `/products.php`. Choose
**Add product** to save a name, unique SKU, price, quantity, and minimum stock
level. Use the row actions to edit a product or open its deletion confirmation.
Products are saved in SQLite and remain available after a browser refresh.

Stock status is calculated consistently: zero quantity is **Out of stock**;
positive quantity at or below the minimum is **Low stock**; quantity above the
minimum is **In stock**. The dashboard displays the saved product totals and
prioritises products needing attention in its stock overview.

## Checks and tests

Run syntax checks on all PHP files:

```sh
find app public scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Run the isolated SQLite and validation tests:

```sh
php tests/run.php
```

The tests use and remove a temporary database in the system temporary
directory; they do not add test products to the application database.

## Known limitations

Sales and customer information shown on the dashboard is illustrative sample
data. Sales processing, customer management, accounts/authentication, and
settings are not yet available. The application is intended as a learning
project and does not yet include deployment hardening such as user access
control.
