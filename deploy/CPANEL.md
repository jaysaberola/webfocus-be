# cPanel deployment (subfolder layout — same as cms5-api)

This clone uses the **same hosting pattern as the working project** (`cms5-api`).

The working project's API URLs look like:

```
https://cms4.webfocusprod.wsiph2.com/cms5-api/public/api/login
```

Your clone backend folder is `webfocus-be`, so URLs must be:

```
https://cms4.webfocusprod.wsiph2.com/webfocus-be/public/api/login
```

You do **not** need a domain root `.htaccess` if you use this path (same as the working project).

## Folder layout on server

```
cms4.webfocusprod.wsiph2.com/
  cms5-api/              ← working project (already live)
  webfocus-be/           ← this clone
    .env
    app/
    public/
      .htaccess
      index.php
    vendor/
```

## 1. Vercel environment variables (clone frontend)

Set these in Vercel → **Settings** → **Environment Variables** → **Production**:

```
NEXT_PUBLIC_API_URL=https://cms4.webfocusprod.wsiph2.com/webfocus-be/public
NEXT_PUBLIC_FRONTEND_URL=https://webfocus-fe.vercel.app
```

Important:

- Include `/webfocus-be/public` — same idea as `/cms5-api/public` on the working site
- Do **not** use `https://cms4.webfocusprod.wsiph2.com` alone (that hits the wrong folder)
- Redeploy after saving env vars

## 2. Backend `.env` (inside `webfocus-be/.env`)

```env
APP_URL=https://cms4.webfocusprod.wsiph2.com/webfocus-be/public
APP_DEBUG=false

CORS_ALLOWED_ORIGIN=https://webfocus-fe.vercel.app
SANCTUM_STATEFUL_DOMAINS=webfocus-fe.vercel.app
```

Then on the server:

```bash
cd /home/webfocusprod/cms4.webfocusprod.wsiph2.com/webfocus-be
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan config:cache
chmod -R 775 storage bootstrap/cache
```

## 2b. Run database migrations (required for checkout + customer portal)

Vercel only hosts the **frontend**. Checkout and the customer portal save data in the **MySQL database on cPanel**.
If you see errors like `Table '...sales_transaction_items' doesn't exist`, migrations were not run on production.

SSH or cPanel **Terminal**, from the backend folder:

```bash
cd /home/webfocusprod/cms4.webfocusprod.wsiph2.com/webfocus-be

# Checkout / orders (fixes "sales_transaction_items doesn't exist")
php artisan migrate --path=database/migrations/2026_04_29_000002_create_sales_transactions_table.php --force
php artisan migrate --path=database/migrations/2026_05_10_000004_create_sales_transaction_items_table.php --force

# Customer portal (services, notifications, support tickets)
php artisan migrate --path=database/migrations/2026_07_22_000001_create_customer_portal_tables.php --force
php artisan migrate --path=database/migrations/2026_07_22_000002_add_reference_key_to_customer_notifications.php --force
php artisan migrate --path=database/migrations/2026_07_22_000003_create_customer_payment_proofs_table.php --force
```

If a migration says the table **already exists**, skip that line and run the next one.

Optional demo data (staging only):

```bash
php artisan db:seed --class=CustomerPortalSeeder --force
```

After migrations, retry checkout on Vercel — **Proceed to Paynamics** should create the order without SQL errors.

## 3. Verify (before testing Vercel login)

| URL | Expected |
|-----|----------|
| `https://cms4.webfocusprod.wsiph2.com/webfocus-be/public/` | Laravel welcome page |
| `https://cms4.webfocusprod.wsiph2.com/webfocus-be/public/api/public/pages/home` | JSON |
| `https://cms4.webfocusprod.wsiph2.com/webfocus-be/public/api/login` | JSON (GET may say Method Not Allowed — OK) |

Compare with working project:

| Working | Clone |
|---------|-------|
| `.../cms5-api/public/api/login` | `.../webfocus-be/public/api/login` |

## 4. Login loop fix (dashboard → back to login)

If login succeeds but you are immediately sent back to `/`, cPanel is likely stripping the
`Authorization` header on API requests after login.

Ensure `webfocus-be/public/index.php` includes the Authorization restore block (in repo),
and redeploy the frontend so axios sends `X-Api-Token` as a fallback header.

## 5. Common clone mistakes

| Working project | Clone mistake |
|-----------------|---------------|
| Vercel uses `.../cms5-api/public` | Left as `.../cms5-api/public` (wrong backend) |
| Vercel uses `.../cms5-api/public` | Set to domain root only (404) |
| Backend in `cms5-api/` | Backend in `webfocus-be/` but URL not updated |
| CORS points to working Vercel URL | Still points to old Vercel app |

## Optional: clean URLs without `/webfocus-be/public`

Only if you want `https://cms4.webfocusprod.wsiph2.com/api/login` instead.

Use `deploy/cpanel-domain-root.htaccess` at the domain root, or point document root to `webfocus-be/public`.

The working project does **not** use this.
