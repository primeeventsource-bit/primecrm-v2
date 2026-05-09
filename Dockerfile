# syntax=docker/dockerfile:1
FROM php:8.3-cli-bookworm AS base

ARG WWWGROUP=1000
ARG WWWUSER=1000

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl unzip ca-certificates \
    libpq-dev libzip-dev libicu-dev libssl-dev libbrotli-dev \
    libpng-dev libjpeg-dev libfreetype6-dev \
    pkg-config supervisor \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo pdo_pgsql pgsql \
        bcmath intl zip pcntl sockets opcache gd

# Redis
RUN pecl install redis && docker-php-ext-enable redis

# Swoole for Octane
RUN pecl install swoole && docker-php-ext-enable swoole

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# OPcache tuned for Octane
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.jit=tracing'; \
    echo 'opcache.jit_buffer_size=128M'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /app

# ---------------------------------------------------------------------------
# Production stage
# ---------------------------------------------------------------------------
FROM base AS production

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi || true

RUN groupadd --force -g ${WWWGROUP} sail \
    && useradd -ms /bin/bash --no-user-group -g ${WWWGROUP} -u ${WWWUSER} sail \
    && chown -R sail:sail /app

USER sail

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--workers=4", "--task-workers=2"]

# ---------------------------------------------------------------------------
# Horizon worker stage
# ---------------------------------------------------------------------------
FROM production AS horizon
USER sail
CMD ["php", "artisan", "horizon"]

# ---------------------------------------------------------------------------
# Scheduler stage
# ---------------------------------------------------------------------------
FROM production AS scheduler
USER sail
CMD ["php", "artisan", "schedule:work"]
