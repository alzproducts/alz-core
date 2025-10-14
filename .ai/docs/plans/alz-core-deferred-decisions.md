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

## Rector CI/CD Integration (Future Phase)

**Decision Date**: 2025-10-14
**Implementation Target**: When CI/CD pipeline is established
**Status**: Researched via multi-model consensus, not implemented

### Decision
Run Rector in CI/CD as a **reporting/validation tool** (fails build if changes detected), rather than in local git hooks. Maintains current "manual-only for intentional refactoring" philosophy while providing automated enforcement.

### Rationale

**Why NOT in Pre-Push Hook:**
- **Validation vs Transformation**: Git hooks should validate code quality, not transform code
- **Performance Impact**: Would add 10-30 seconds to already 30-60s pre-push hook (tests + mutation testing), risking `--no-verify` bypass usage
- **Learning Opportunity**: Portfolio project benefits from intentional review of structural changes
- **Documented Philosophy**: CLAUDE.md explicitly states "manual-only for intentional refactoring"

**Why CI/CD Reporting:**
- ✅ Automated enforcement without disrupting local workflow
- ✅ Forces intentional review (must run locally before push)
- ✅ Zero local performance impact
- ✅ Preserves developer control over structural changes
- ✅ Fails build if refactoring opportunities exist

**Key Distinction:** Pint (formatting) ≠ Rector (structural). Pint makes cosmetic changes with zero semantic impact. Rector makes structural changes (type hints, control flow, method refactorings) that warrant explicit developer review.

### Implementation Instructions

When setting up GitHub Actions CI/CD pipeline:

```yaml
# .github/workflows/quality.yml
name: Code Quality

on: [push, pull_request]

jobs:
  rector-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, pdo_sqlite

      - name: Install Dependencies
        run: composer install --prefer-dist --no-progress

      - name: Check if Rector would make changes
        run: |
          ./vendor/bin/sail composer rector:dry-run
          if [ $? -ne 0 ]; then
            echo "❌ Rector would make changes."
            echo "Run './vendor/bin/sail composer rector' locally, review changes, and commit."
            exit 1
          fi
```

### Developer Workflow

When CI fails due to Rector:

1. Run locally: `./vendor/bin/sail composer rector:dry-run`
2. Review proposed changes carefully
3. Apply: `./vendor/bin/sail composer refactor` (Rector + Pint)
4. Verify: `./vendor/bin/sail composer check`
5. Commit with descriptive message explaining refactorings
6. Push (CI will pass)

### Reconsider Hook Automation When

Switch to pre-push hook automation IF:
- ✅ Codebase grows to 100+ files (manual Rector becomes tedious)
- ✅ Pushing only once per day (performance less critical)
- ✅ Rector changes become routine (no longer learning from them)
- ✅ Team grows beyond solo developer (coordination benefit increases)

**Current state:** 9 files, frequent pushes, learning phase
**Verdict:** Manual Rector with CI enforcement is optimal NOW

### Consensus Analysis

Multi-model consensus (gemini-2.5-pro vs gemini-2.5-flash) resulted in:
- **60% EXCLUDE from hooks** / 40% INCLUDE
- Both models 9/10 confidence but opposite conclusions
- Key disagreement: Is Rector "structural Pint" or fundamentally different?
- **Winning argument:** Validation-not-transformation principle + performance concerns

### References
- Consensus analysis: `.ai/docs/plans/rector-git-hooks-consensus.md` (if documented)
- Rector configuration: `rector.php`
- Current workflow: CLAUDE.md (Rector section)

---

## Security Hardening (Pre-Production Audit)

**Decision Date**: 2025-10-15
**Implementation Target**: As features are built
**Status**: Audit completed, implementation deferred to feature development

### Completed Security Fixes

The following configuration hardening was completed based on security audit findings:

1. **Session Encryption**: Enabled `SESSION_ENCRYPT=true` in `.env.production.example`
2. **Secure Cookies**: Enforced `SESSION_SECURE_COOKIE=true` for HTTPS-only transmission
3. **Redis Authentication**: Added documentation requiring strong passwords (32+ chars)
4. **PostgreSQL SSL**: Changed default from `prefer` to `require` in `config/database.php`
5. **Rate Limiting**: Added `api`, `webhooks`, and `global` rate limiters to `bootstrap/app.php`

### Deferred to Deployment (Railway)

**Pre-Deployment Checklist**:

When deploying to Railway, verify these environment variables:
- ✅ `APP_KEY` - Run `php artisan key:generate` to generate
- ✅ `REDIS_PASSWORD` - Set strong password (32+ characters minimum)
- ✅ `HORIZON_USER` - Set username for Horizon dashboard access
- ✅ `HORIZON_PASSWORD` - Set strong password (16+ characters minimum)
- ✅ `APP_DEBUG=false` - Ensure debug mode disabled in production
- ✅ `DB_SSLMODE=require` - Enforce SSL for Supabase connections (already defaulted)

**Optional Deployment Validation Script**:

```bash
#!/bin/bash
# Add to deployment pipeline or run manually before deploy

echo "🔒 Security Pre-Deployment Checks"

# Check APP_KEY is set
if [ -z "$APP_KEY" ]; then
  echo "❌ APP_KEY not set. Run: php artisan key:generate"
  exit 1
fi

# Check Redis password is set
if [ -z "$REDIS_PASSWORD" ]; then
  echo "❌ REDIS_PASSWORD not set"
  exit 1
fi

# Check Horizon credentials
if [ -z "$HORIZON_USER" ] || [ -z "$HORIZON_PASSWORD" ]; then
  echo "⚠️  WARNING: Horizon credentials not set"
fi

# Verify debug mode disabled
if [ "$APP_DEBUG" = "true" ]; then
  echo "❌ APP_DEBUG=true in production!"
  exit 1
fi

echo "✅ All security checks passed"
```

### Deferred to Webhook Implementation

**When adding webhook endpoints** (`routes/web.php`), implement:

1. **Rate Limiting** (already configured in `bootstrap/app.php`):
   ```php
   Route::post('/webhooks/ecommerce', [WebhookController::class, 'handle'])
       ->middleware('throttle:webhooks');  // 100 requests/minute
   ```

2. **Webhook Signature Verification** (HMAC-SHA256):
   - Create middleware: `app/Http/Middleware/VerifyWebhookSignature.php`
   - Use `hash_equals()` for constant-time comparison (prevents timing attacks)
   - Store webhook secret in `.env` as `WEBHOOK_SECRET`
   - Signature format depends on provider (Stripe, Shopify, etc.)

   Example pattern:
   ```php
   $expectedSignature = hash_hmac('sha256', $payload, $secret);
   return hash_equals($expectedSignature, $providedSignature);
   ```

3. **Security Event Logging**:
   - Log all webhook attempts (success/failure) with IP addresses
   - Create dedicated `security` log channel in `config/logging.php`
   - Never log sensitive data (tokens, passwords, credit cards, full payloads)

### Deferred to Application Logging Implementation

**When adding application logging**, implement:

1. **Log Sanitization**:
   - Create middleware to strip sensitive data before logging
   - Blacklist: passwords, tokens, API keys, session IDs, credit card numbers
   - Use Laravel's `Log::channel('security')` for security events

2. **Audit Trail Requirements**:
   - Authentication attempts (success/failure with IP)
   - Webhook deliveries (timestamp, provider, signature status)
   - Failed authorization attempts
   - Queue job failures with context

3. **Log Retention**:
   - Security logs: 90 days minimum
   - Application logs: 30 days
   - Error logs: 60 days

### Security Audit Summary

**Overall Security Posture**: GOOD (Pre-Production) ⭐⭐⭐⭐☆

**Vulnerabilities Found**:
- Critical: 0
- High: 0
- Medium: 7 (all configuration, all fixed)
- Low: 4 (best practices, addressed)

**Dependencies**: Zero known vulnerabilities (`composer audit` clean)

**Key Strengths**:
- Timing attack protection (`hash_equals()` in HorizonBasicAuth)
- Zero dependency vulnerabilities
- Strict types enforced (`declare(strict_types=1)`)
- PHPStan Level max + strict rules
- Clean Architecture boundaries enforced

**Run Security Audit Again**:
```bash
# When ready to deploy or after major changes
/secaudit "Run comprehensive pre-deployment security review"
```

---

## Placeholder for Future Decisions

Document future architectural decisions here as they're made:

### Pending Research/Decisions

- **Database Migration**: Moving from SQLite (dev) to PostgreSQL/Supabase (production)
- **Error Tracking**: Sentry, Bugsnag, or other service selection
- **Backup/Recovery**: Supabase backup strategy and disaster recovery procedures

---

**Last Updated**: 2025-10-15