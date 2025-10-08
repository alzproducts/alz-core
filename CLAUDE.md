# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Development
```bash
# First-time setup (installs dependencies, sets up environment, runs migrations)
composer run setup

# Start development environment (runs server, queue, logs, and vite concurrently)
composer run dev

# Alternative individual development commands
php artisan serve          # Start development server (http://localhost:8000)
npm run dev                # Start Vite dev server for frontend assets
php artisan queue:listen   # Start queue worker
php artisan pail          # Watch real-time logs
```

### Testing
```bash
# Run all tests
composer run test
# OR
php artisan test

# Run specific test file
php artisan test tests/Feature/ExampleTest.php

# Run specific test method
php artisan test --filter test_example_method

# Run tests with coverage
php artisan test --coverage
```

### Code Quality
```bash
# Format code with Laravel Pint
./vendor/bin/pint

# Format specific file or directory
./vendor/bin/pint app/Http/Controllers
```

### Build & Production
```bash
# Build frontend assets for production
npm run build

# Clear all caches
php artisan optimize:clear

# Cache configuration for production
php artisan optimize
```

### Database
```bash
# Run migrations
php artisan migrate

# Rollback migrations
php artisan migrate:rollback

# Fresh migration (drop all tables and re-run)
php artisan migrate:fresh

# Create new migration
php artisan make:migration create_example_table
```

## Architecture

### Laravel 12 Structure
This is a Laravel 12 application using:
- **SQLite** as the default database (configured in `.env` as `DB_CONNECTION=sqlite`)
- **Vite** for asset bundling with Tailwind CSS v4
- **Composer scripts** for orchestrated development workflow

### Request Flow
1. **Entry Point**: `public/index.php` → `bootstrap/app.php`
2. **Routing**: Routes defined in `routes/web.php` (web) and `routes/console.php` (CLI)
3. **Middleware**: Configured via `withMiddleware()` in `bootstrap/app.php`
4. **Controllers**: Located in `app/Http/Controllers/`, extend base `Controller`
5. **Models**: Eloquent models in `app/Models/`, using SQLite by default
6. **Views**: Blade templates in `resources/views/`

### Key Configuration Files
- **bootstrap/app.php**: Application bootstrap and configuration (routes, middleware, exceptions)
- **config/**: All application configuration files (app, database, cache, session, queue)
- **.env**: Environment-specific settings (copy from `.env.example` for new setup)

### Development Workflow
The `composer run dev` command starts all necessary services concurrently:
- PHP development server on port 8000
- Queue worker for background jobs
- Pail for real-time log monitoring
- Vite dev server for hot-reloading frontend assets

### Testing Strategy
- **Unit Tests**: `tests/Unit/` - Test individual components in isolation
- **Feature Tests**: `tests/Feature/` - Test entire features/endpoints
- **Configuration**: `phpunit.xml` uses in-memory SQLite for fast test execution

### Frontend Build
- Vite configuration in `vite.config.js`
- Entry points: `resources/css/app.css` and `resources/js/app.js`
- Tailwind CSS v4 integrated via `@tailwindcss/vite` plugin
- Hot module replacement in development, optimized builds for production