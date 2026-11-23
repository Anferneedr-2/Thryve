# Thryve Platform

Laravel 13 (PHP 8.3+) API + React 19 / Tailwind 4 SPA served through Vite, MariaDB.
Plan: ../Centralized_AI_Platform_MVP_Implementation_Plan.md

## Setup
    composer install
    cp .env.example .env && php artisan key:generate
    # create a MariaDB database and set DB_* in .env (secrets stay out of git)
    php artisan migrate
    npm install

## Commands
- Dev: `npm run dev` and `php artisan serve`
- Build: `npm run build` (public/build is committed for cPanel)
- Test: `php artisan test` (needs MariaDB database `thryve_test`)

## cPanel deployment (no terminal required)

The repository includes `public/build` and production-only `vendor` dependencies. Deploy the repository root as the Laravel application; the web document root must point to `public`. Preserve the live `.env`, `storage`, uploaded files and install marker. Do not copy a development environment over the live installation.

After deploying code, sign in as Super Admin and open Settings > Updates and backups. If database changes are pending, use the database update wizard: it takes and verifies a backup before applying migrations. This release adds report request types and streaming chat control tables/columns. Earlier pending migrations may also appear, depending on the live version. Do not rerun the installer or seed production data.

Verify sign-in, an existing conversation, attachments/downloads, Reports and streaming chat/Stop after the database update. SMTP delivery, off-server backup delivery and real agent execution still require their configured services and live verification.
