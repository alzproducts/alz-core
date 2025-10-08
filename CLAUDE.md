# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Goal

Backend service for e-commerce webhooks and background jobs. Replaces legacy PHP app for 3-4 internal staff.

**Key Functions**: Process webhooks, sync orders/inventory/products, scheduled tasks
**Frontend**: Separate Next.js app using Supabase (already built)
**Deployment**: Railway (planned)

See detailed plan: `.ai/docs/plans/alz-core-initial-plan.md`

## Current Stack

- Laravel 12 (backend-only, no frontend)
- PHP 8.2+
- SQLite (development) → PostgreSQL/Supabase (production, planned)
- Redis (cache/queues, planned)
- Horizon + Telescope (monitoring, planned)

## Development Commands

### Using Sail (no local PHP required)
```bash
# First time: Install via Docker
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs

docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest php artisan sail:install

# Start/stop
./vendor/bin/sail up -d
./vendor/bin/sail down

# Run commands
./vendor/bin/sail artisan test
./vendor/bin/sail artisan migrate
```

### With local PHP 8.2+
```bash
composer run setup    # First-time setup
composer run dev      # Start all services (server, queue, logs, vite)
composer run test     # Run tests
```

## Key Architectural Decisions

1. **Cache-first**: Default to caching, remove only when needed
2. **Thin SDK**: E-commerce API package (planned) stays simple, Laravel handles logic
3. **Queue everything**: Webhooks respond immediately, process async
4. **Supabase shared**: Same PostgreSQL database as Next.js frontend

---

*This file grows as we build. See plan for full roadmap.*