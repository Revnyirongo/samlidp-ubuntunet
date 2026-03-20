# ── Stage 1: Composer dependencies ──────────────────────────
FROM composer:2.7 AS composer

WORKDIR /app
COPY app/composer.json app/composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs \
    --prefer-dist \
    --optimize-autoloader

COPY app/ .
RUN composer dump-autoload --optimize --classmap-authoritative

# ── Stage 2: Production PHP image ────────────────────────────
FROM php:8.4-fpm-alpine AS production

ARG APP_ENV=prod
ENV APP_ENV=$APP_ENV

# System deps
RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libpq-dev \
    libxml2-dev \
    libzip-dev \
    oniguruma-dev \
    openssl \
    su-exec \
    unzip \
    zlib-dev

# PHP extensions
RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) \
    intl \
    mbstring \
    opcache \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    xml \
    zip \
    bcmath \
 && pecl install redis apcu \
 && docker-php-ext-enable redis apcu \
 && apk del .phpize-deps

# PHP production config
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/99-prod.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/app/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

# Create non-root user
RUN addgroup -g 1000 -S app && adduser -u 1000 -S app -G app

WORKDIR /var/www/html

# Copy vendor from composer stage
COPY --from=composer --chown=app:app /app/vendor ./vendor
COPY --from=composer --chown=app:app /app/composer.json /app/composer.lock ./

# Copy app source
COPY --chown=app:app app/ .
COPY --chown=app:app VERSION /var/www/VERSION
RUN rm -rf /var/www/html/var/* \
 && mkdir -p /var/www/html/var/cache /var/www/html/var/log \
 && chown -R app:app /var/www/html/var

EXPOSE 9000

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
