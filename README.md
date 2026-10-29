# GeStock

Small business stock and sales management system.

## Run in GitHub Codespaces

From the project root, start PHP's built-in development server:

```sh
php -S 0.0.0.0:8000 -t public
```

When Codespaces prompts you about port 8000, choose **Open in Browser**. You can
also open the **Ports** tab and follow the forwarded URL for port 8000.

The dashboard currently uses sample data only. It does not connect to a
database or process real sales.

## Project structure

```text
public/
├── assets/
│   ├── css/style.css
│   └── js/app.js
└── index.php
```

- `public/index.php` renders the dashboard and its labelled sample figures.
- `public/assets/css/style.css` contains the responsive dashboard styles.
- `public/assets/js/app.js` controls the small-screen sidebar menu.
