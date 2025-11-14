#!/bin/bash
# ==============================================================================
# Docker Entrypoint Script for Laravel Octane + Swoole
# ==============================================================================
# Purpose: Runtime configuration and graceful startup for production deployment
# ==============================================================================

set -e

# ------------------------------------------------------------------------------
# Color output for logging
# ------------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# ------------------------------------------------------------------------------
# Environment validation
# ------------------------------------------------------------------------------
log_info "Starting Laravel Octane entrypoint script..."

if [ -z "$APP_ENV" ]; then
    log_warning "APP_ENV not set, defaulting to 'production'"
    export APP_ENV=production
fi

log_info "Environment: $APP_ENV"
log_info "Laravel version: $(php artisan --version)"

# ------------------------------------------------------------------------------
# Validate required environment variables
# ------------------------------------------------------------------------------
log_info "Validating environment variables..."

REQUIRED_VARS=("APP_KEY" "DB_CONNECTION")

for VAR in "${REQUIRED_VARS[@]}"; do
    if [ -z "${!VAR}" ]; then
        log_error "Required environment variable $VAR is not set!"
        exit 1
    fi
done

log_success "Environment variables validated"

# ------------------------------------------------------------------------------
# Railway-specific: PORT handling
# ------------------------------------------------------------------------------
if [ -n "$PORT" ]; then
    log_info "Railway PORT detected: $PORT"
    export OCTANE_PORT=$PORT
else
    log_info "No PORT variable, using default: 8000"
    export PORT=8000
    export OCTANE_PORT=8000
fi

# ------------------------------------------------------------------------------
# Database connectivity check (optional, set SKIP_DB_CHECK=true to disable)
# ------------------------------------------------------------------------------
if [ "${SKIP_DB_CHECK}" != "true" ]; then
    log_info "Checking database connectivity..."

    # Database connection timeout: 90 retries × 2 seconds = 180 seconds (3 minutes)
    # Accommodates Railway + Supabase serverless cold starts
    # Override with DB_CONNECT_RETRIES env var if needed
    MAX_RETRIES=${DB_CONNECT_RETRIES:-90}
    RETRY_COUNT=0

    while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
        if php artisan db:show --database="${DB_CONNECTION:-pgsql}" > /dev/null 2>&1; then
            log_success "Database connection established"
            break
        else
            RETRY_COUNT=$((RETRY_COUNT + 1))
            if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
                log_error "Failed to connect to database after $MAX_RETRIES attempts"
                exit 1
            fi
            log_warning "Database not ready, retrying ($RETRY_COUNT/$MAX_RETRIES)..."
            sleep 2
        fi
    done
else
    log_warning "Database connectivity check skipped (SKIP_DB_CHECK=true)"
fi

# ------------------------------------------------------------------------------
# Run database migrations (optional, set AUTO_MIGRATE=true to enable)
# ------------------------------------------------------------------------------
if [ "${AUTO_MIGRATE}" = "true" ]; then
    log_info "Running database migrations..."

    if php artisan migrate --force --no-interaction; then
        log_success "Database migrations completed"
    else
        log_error "Database migrations failed!"
        exit 1
    fi
else
    log_info "Auto-migration disabled (set AUTO_MIGRATE=true to enable)"
fi

# ------------------------------------------------------------------------------
# Laravel optimization caching (must run AFTER .env is available)
# ------------------------------------------------------------------------------
log_info "Running Laravel optimization commands..."

# Clear any existing caches first
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Cache configuration for faster bootstrapping
if php artisan config:cache; then
    log_success "Configuration cached"
else
    log_error "Failed to cache configuration"
    exit 1
fi

# Cache routes for faster routing
if php artisan route:cache; then
    log_success "Routes cached"
else
    log_error "Failed to cache routes"
    exit 1
fi

# Cache events
if php artisan event:cache; then
    log_success "Events cached"
else
    log_error "Event caching failed"
    exit 1
fi

log_success "Laravel optimization completed"

# ------------------------------------------------------------------------------
# Octane configuration summary
# ------------------------------------------------------------------------------
log_info "Octane Configuration:"
log_info "  - Server: Swoole"
log_info "  - Host: 0.0.0.0"
log_info "  - Port: ${OCTANE_PORT}"
log_info "  - Workers: ${OCTANE_WORKERS:-auto}"
log_info "  - Task Workers: ${OCTANE_TASK_WORKERS:-auto}"
log_info "  - Max Requests: ${OCTANE_MAX_REQUESTS:-500}"

# ------------------------------------------------------------------------------
# Start application
# ------------------------------------------------------------------------------
log_success "Entrypoint script completed successfully"
log_info "Starting Octane server..."

# Execute the CMD from Dockerfile (php artisan octane:start)
exec "$@"
