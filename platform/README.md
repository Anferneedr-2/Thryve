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
- Build: `npm run build` (public/build is gitignored; ship built assets to cPanel)
- Test: `php artisan test` (needs MariaDB database `thryve_test`)
