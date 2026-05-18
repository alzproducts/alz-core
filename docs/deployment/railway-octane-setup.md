# Railway Octane Deployment Configuration

This guide documents the Railway Dashboard UI configuration required for Laravel Octane with Swoole.

**IMPORTANT**: All configuration is done via the Railway Dashboard UI. Settings persist across deployments and are not managed in code.

## Overview

- **Automatic Swoole installation**: Railway's Nixpacks auto-detects `ext-swoole` in `composer.json` and installs it during build
- **No Dockerfile needed**: Railway handles all PHP extension installation automatically
- **UI-only configuration**: All settings below are configured in the Railway Dashboard

---

## Web Service Configuration

### 1. Start Command

**Location**: Railway Dashboard → Your Project → Web Service → Settings → Deploy → Start Command

**Update to**:
```bash
php artisan octane:start --server=swoole --host=0.0.0.0 --port=${PORT:-8000}
```

**Why this works**:
- `--server=swoole`: Use Swoole server (auto-installed via Nixpacks)
- `--host=0.0.0.0`: Bind to all interfaces (required for Railway)
- `--port=${PORT:-8000}`: Use Railway's dynamic PORT variable

**Replaces**: Previous `php artisan serve` or nginx + php-fpm

---

### 2. Deploy Command (Migrations)

**Location**: Railway Dashboard → Your Project → Web Service → Settings → Deploy → Deploy Command

**Recommended**:
```bash
php artisan migrate --force && php artisan config:cache && php artisan route:cache
```

**What it does**:
- Runs database migrations before starting Octane
- Caches configuration for performance
- Caches routes for faster routing

---

### 3. Health Check Endpoint

**Location**: Railway Dashboard → Your Project → Web Service → Settings → Deploy → Health Check Path

**Set to**: `/up`

**Verification**:
Before deploying, test locally:
```bash
./vendor/bin/sail artisan octane:start
curl http://localhost:8000/up
```

Expected response: `200 OK`

---

### 4. Environment Variables

**Location**: Railway Dashboard → Your Project → Web Service → Settings → Environment

**Add these Octane-specific variables**:

```env
OCTANE_SERVER=swoole
OCTANE_WORKERS=4
OCTANE_TASK_WORKERS=6
OCTANE_MAX_REQUESTS=500
```

**Variable explanations**:

| Variable | Value | Purpose |
|----------|-------|---------|
| `OCTANE_SERVER` | `swoole` | Force Swoole server (default in config) |
| `OCTANE_WORKERS` | `4` | HTTP workers for concurrent requests |
| `OCTANE_TASK_WORKERS` | `6` | Background task workers |
| `OCTANE_MAX_REQUESTS` | `500` | Restart worker after N requests (prevents memory leaks) |

**Note**: These values are configured for Railway Pro tier. Adjust based on your resource limits.

---

## Worker Service Configuration

**Location**: Railway Dashboard → Your Project → Worker Service → Settings → Deploy

**Start Command remains unchanged**:
```bash
php artisan horizon
```

**Why**: Horizon runs independently of Octane. The web service handles HTTP requests via Octane, while the worker service processes queued jobs via Horizon.

---

## Verification Steps

After updating Railway configuration:

### 1. Check Build Logs

Railway build logs should show:
```
[nixpacks] Installing PHP extensions: swoole, pcntl, pdo, pdo_pgsql
```

### 2. Monitor Deployment Logs

Watch for Octane startup message:
```
Server running on [http://0.0.0.0:8000]
Press Ctrl+C to stop the server
```

### 3. Test Health Endpoint

```bash
curl https://your-app.up.railway.app/up
```

Expected: `200 OK`

### 4. Monitor Worker Status

Use `artisan octane:status` to check worker health (requires access to container):
```bash
railway run php artisan octane:status
```

---

## Troubleshooting

### Issue: Swoole not found

**Symptoms**: `Extension swoole is not installed`

**Solution**: Verify `ext-swoole` is in `composer.json` `require` section:
```json
{
    "require": {
        "ext-swoole": "*"
    }
}
```

Then redeploy. Railway Nixpacks will detect and install it.

---

### Issue: Workers crashing

**Symptoms**: Frequent worker restarts in logs

**Check**:
1. Memory usage (reduce `OCTANE_WORKERS` if OOM)
2. `OCTANE_MAX_REQUESTS` too low (increase to 1000+)
3. Application errors (check error logs)

---

### Issue: Health check failing

**Symptoms**: Deployment fails with health check timeout

**Verify**:
1. Health check path is `/up` (not `/health` or other)
2. Octane bound to `0.0.0.0` (not `127.0.0.1`)
3. PORT variable used correctly

---

## Configuration Summary

**Changes Made in Railway UI**:

| Setting | Old Value | New Value |
|---------|-----------|-----------|
| Web Service → Start Command | `php artisan serve` | `php artisan octane:start --server=swoole --host=0.0.0.0 --port=${PORT:-8000}` |
| Web Service → Environment | None | Added 4 Octane variables |
| Web Service → Health Check | `/health` | `/up` |
| Worker Service | No changes | Horizon continues as-is |

**No changes needed for**:
- Dockerfile (we don't use one - Nixpacks auto-detects)
- Build configuration
- Port settings (Railway handles via `$PORT`)
- Database/Redis configuration (unchanged)

---

## Next Deployment

After configuring the above in Railway UI:

1. Push code changes (Octane package, config files)
2. Railway detects `ext-swoole` in `composer.json`
3. Railway builds with Swoole extension
4. Railway starts with new Octane command
5. Health check verifies `/up` endpoint
6. Deployment succeeds ✅

**No further Railway configuration needed** - settings persist across all future deployments.
