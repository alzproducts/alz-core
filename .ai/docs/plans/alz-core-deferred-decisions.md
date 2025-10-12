# ALZ Core - Deferred Architectural Decisions

This document tracks architectural decisions that have been researched and planned but **not yet implemented**. These are decisions we've made for future phases or features.

---

## Scheduler Implementation (Phase 2)

**Decision Date**: 2025-10-13
**Implementation Target**: Phase 2 (Weeks 2-3)
**Status**: Researched, not implemented

### Decision
Use Railway's native cron job feature to run Laravel scheduled tasks, rather than a continuous `schedule:work` process.

### Setup Instructions

When implementing scheduled tasks in Phase 2:

1. **Create Railway Cron Service**:
   - In Railway dashboard, configure cron schedule for your service
   - Set start command: `php artisan schedule:run`
   - Set cron schedule: `*/5 * * * *` (every 5 minutes)

2. **Define scheduled tasks** in `app/Console/Kernel.php`:
   ```php
   protected function schedule(Schedule $schedule): void
   {
       // Daily sync at 2 AM UTC
       $schedule->command('sync:orders')->dailyAt('02:00');

       // Hourly inventory sync
       $schedule->command('sync:inventory')->hourly();

       // Daily product sync at 3 AM UTC
       $schedule->command('sync:products')->dailyAt('03:00');
   }
   ```

### Why This Approach

**Compatibility**: Project plan specifies daily/hourly sync jobs, which are well above Railway's 5-minute minimum interval.

**Cost-efficient**: Pay only for brief execution time (seconds) instead of running a 24/7 process.

**Platform-native**: Uses Railway's intended cron feature rather than workarounds.

### Important Constraints

- **5-minute minimum**: Railway cron cannot run more frequently than every 5 minutes
- **Timing precision**: Execution may vary by a few minutes (not guaranteed to-the-second)

### If Requirements Change

If you need tasks that run **more frequently than every 5 minutes**:

1. Add to Procfile:
   ```procfile
   scheduler: php artisan schedule:work
   ```

2. This runs a continuous process that checks every minute (Laravel standard)

3. Trade-off: 24/7 resource consumption vs Railway cron's pay-per-execution

### References
- [Railway Cron Documentation](https://docs.railway.com/reference/cron-jobs)
- [Laravel Scheduling Documentation](https://laravel.com/docs/12.x/scheduling)

---

## Web Process Definition

**Decision Date**: 2025-10-13
**Status**: Deferred - using Railway auto-detection

### Current Approach
Railway's Railpack automatically detects Laravel applications and configures FrankenPHP (production-ready web server). No explicit `web` process needed in Procfile.

### If Auto-Detection Fails

Add to Procfile:
```procfile
web: php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
```

**Note**: This uses Laravel's development server, which is acceptable for small-scale internal apps (3-4 users) but not optimal for high-traffic production.

### Future: Octane (Phase 3)

When implementing Laravel Octane in Phase 3:
```procfile
web: php artisan octane:start --server=swoole --host=0.0.0.0 --port=${PORT:-8000}
```

Requires:
```bash
composer require laravel/octane
php artisan octane:install --server=swoole
```

---

## Placeholder for Future Decisions

Document future architectural decisions here as they're made:

### Pending Research/Decisions

- **Database Migration**: Moving from SQLite (dev) to PostgreSQL/Supabase (production)
- **Error Tracking**: Sentry, Bugsnag, or other service selection
- **Webhook Signature Verification**: Implementation approach for e-commerce webhooks
- **API Rate Limiting**: Strategy for protecting webhook endpoints
- **Logging Strategy**: Log channels, retention policies, monitoring approach
- **Backup/Recovery**: Supabase backup strategy and disaster recovery procedures

---

**Last Updated**: 2025-10-13