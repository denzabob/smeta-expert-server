#!/bin/sh
set -e

APP_DIR="/var/www/html"

mkdir -p "$APP_DIR/storage" \
  "$APP_DIR/storage/app" \
  "$APP_DIR/storage/app/public" \
  "$APP_DIR/storage/framework/cache" \
  "$APP_DIR/storage/framework/sessions" \
  "$APP_DIR/storage/framework/views" \
  "$APP_DIR/storage/logs" \
  "$APP_DIR/bootstrap/cache"

# Pre-create frequently used upload roots so runtime uploads do not fail on first write.
mkdir -p "$APP_DIR/storage/app/public/ideas/attachments" \
  "$APP_DIR/storage/app/public/screenshots/manual"

# On bind mounts, ownership and permissions from build stage are overridden.
# Ensure Laravel runtime paths remain writable for php-fpm workers.
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" || true
chmod -R ug+rwx "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" || true

exec "$@"
