#!/bin/sh
set -e

echo "==> Setting storage permissions..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

echo "==> Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "==> Caching configuration for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations only if RUN_MIGRATIONS=true
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "==> Running database migrations..."
    php artisan migrate --force
else
    echo "==> Skipping migrations (RUN_MIGRATIONS is not set to true)"
fi

echo "==> Starting PHP-FPM..."
exec "$@"
