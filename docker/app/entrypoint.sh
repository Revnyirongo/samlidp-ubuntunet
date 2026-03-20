#!/bin/sh
set -eu

APP_ROOT=/var/www/html

mkdir -p \
    "$APP_ROOT/var/cache" \
    "$APP_ROOT/var/log" \
    "$APP_ROOT/public/uploads/tenant-logos" \
    "$APP_ROOT/simplesamlphp/config" \
    "$APP_ROOT/simplesamlphp/metadata" \
    "$APP_ROOT/simplesamlphp/cert"

chown -R app:app \
    "$APP_ROOT/var" \
    "$APP_ROOT/public/uploads" \
    "$APP_ROOT/simplesamlphp/config" \
    "$APP_ROOT/simplesamlphp/metadata" \
    "$APP_ROOT/simplesamlphp/cert"

if [ "${1:-}" = "php-fpm" ]; then
    exec "$@"
fi

exec su-exec app "$@"
