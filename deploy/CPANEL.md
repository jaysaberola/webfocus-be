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
