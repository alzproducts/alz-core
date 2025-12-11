# ==============================================================================
# Laravel Octane + Swoole Production Dockerfile
# ==============================================================================
# Base Image: serversideup/php:8.4-cli (Debian-based, security-hardened)
# Target: Railway deployment with Laravel 12 + Octane + Swoole
# ==============================================================================

# ------------------------------------------------------------------------------
# Stage 1: Builder - Install dependencies and compile extensions
# ------------------------------------------------------------------------------
FROM serversideup/php:8.4-cli AS builder

# Switch to root for installation
USER root

# Install build dependencies for Swoole compilation and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    autoconf \
    libc-dev \
    pkg-config \
    libssl-dev \
    libcurl4-openssl-dev \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libxml2-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Laravel 12 and dependencies
# - soap: Required by microsoft/bingads SDK (SOAP-based API)
# - zip: Required for Bing Ads report processing (ZIP archives)
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    pcntl \
    zip \
    bcmath \
    opcache \
    soap

# Install Swoole via PECL
# Using latest version for PHP 8.4 compatibility (installs 6.1.2 as of Jan 2025)
# Note: Specific version pinning caused build failures with PHP 8.4
RUN pecl install swoole && \
    docker-php-ext-enable swoole

# Verify Redis extension from base image
RUN php -m | grep redis || (echo "ERROR: Redis extension not found in base image!" && exit 1)

# Copy Composer from official image
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy dependency files first (for Docker layer caching)
COPY composer.json composer.lock ./

# Install PHP dependencies (production only, optimized autoloader)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# ------------------------------------------------------------------------------
# Stage 2: Runtime - Slim production image
# ------------------------------------------------------------------------------
FROM serversideup/php:8.4-cli AS runtime

# Switch to root for system configuration
USER root

# Install runtime dependencies only (no build tools)
# Note: Debian Trixie package names (libzip5, libpng16-16t64, etc.)
# - libxml2: Required by ext-soap (Bing Ads SDK)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq5 \
    libzip5 \
    libpng16-16t64 \
    libcurl4t64 \
    libxml2 \
    curl \
    tini \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions from builder
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Configure PHP for production
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory.ini && \
    echo "post_max_size = 100M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "variables_order = EGPCS" > /usr/local/etc/php/conf.d/variables.ini

# Configure OPcache for maximum performance
RUN echo "opcache.enable=1" > /usr/local/etc/php/conf.d/opcache-production.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache-production.ini && \
    echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache-production.ini && \
    echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache-production.ini && \
    echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache-production.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache-production.ini && \
    echo "opcache.save_comments=1" >> /usr/local/etc/php/conf.d/opcache-production.ini && \
    echo "opcache.fast_shutdown=1" >> /usr/local/etc/php/conf.d/opcache-production.ini

# Set working directory
WORKDIR /var/www/html

# Copy application code with proper ownership
COPY --chown=www-data:www-data . .

# Copy vendor from builder
COPY --from=builder --chown=www-data:www-data /var/www/html/vendor ./vendor

# Set permissions for Laravel directories
RUN chmod -R 755 storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Switch to non-root user for security
USER www-data

# Expose port (Railway uses dynamic PORT variable)
EXPOSE ${PORT:-8000}

# Health check (uses Laravel's /up endpoint configured in routes/web.php)
# Using shell form to enable environment variable expansion for PORT
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD sh -c "curl -f http://localhost:${PORT:-8000}/up || exit 1"

# Use tini as init system for proper signal handling (graceful shutdown)
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/docker-entrypoint.sh"]

# Default command: Start Octane with Swoole
# Worker configuration comes from environment variables (OCTANE_WORKERS, etc.)
# Using shell form to enable environment variable expansion for PORT (required for Railway)
CMD ["sh", "-c", "exec php artisan octane:start --server=swoole --host=0.0.0.0 --port=${PORT:-8000}"]
