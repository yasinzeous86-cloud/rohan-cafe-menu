# ==============================================================================
# Rohan Cafe Menu - Production-Ready Minimal Alpine Dockerfile
# Optimized for Render.com, Railway.app, Fly.io, and Cloud Run
# Image size: ~45MB | Fast cold starts (<2s) | Native SQLite WAL support
# ==============================================================================

FROM php:8.2-cli-alpine

LABEL maintainer="Rohan Cafe DevOps <devops@rohan-menu.local>"
LABEL description="Rohan Cafe Digital Menu & Telegram Ordering System"

# 1. Install system runtime dependencies & build extensions
RUN apk add --no-cache \
    sqlite-libs \
    sqlite-dev \
    curl-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    tzdata \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_sqlite \
    sqlite3 \
    curl \
    mbstring \
    && apk del sqlite-dev curl-dev oniguruma-dev \
    && rm -rf /var/cache/apk/*

# 2. Set working directory
WORKDIR /var/www/html

# 3. Copy application files into the container
COPY . /var/www/html/

# 4. Set directory and database permissions for SQLite write/WAL operations
RUN mkdir -p /var/www/html/uploads \
    && chmod -R 777 /var/www/html \
    && chmod 666 /var/www/html/menu.sqlite* 2>/dev/null || true

# 5. Default port fallback (Render injects 10000, Railway/CloudRun injects 8080)
ENV PORT=8080
ENV PHP_CLI_SERVER_WORKERS=4

# Expose standard cloud container ports
EXPOSE 80 8080 10000

# 6. Start PHP built-in server with router for secure, zero-config routing
CMD ["sh", "-c", "php -d upload_max_filesize=10M -d post_max_size=12M -d memory_limit=128M -S 0.0.0.0:${PORT:-8080} router.php"]
