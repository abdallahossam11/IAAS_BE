#!/bin/sh
set -e

echo "==> Setting storage permissions..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

echo "==> Clearing config/route/view caches..."
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "==> Running database migrations..."
    php artisan migrate --force
else
    echo "==> Skipping migrations (RUN_MIGRATIONS is not set to true)"
fi

echo "==> Clearing application cache after migrations..."
php artisan cache:clear || true

echo "==> Caching configuration for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Starting PHP-FPM..."
exec "$@"
