#!/bin/bash

# Exit on error
set -e

echo "Starting Deployment Process..."

# 1. Pull latest code (if applicable)
# git pull origin main

# 2. Build and start containers
echo "Building and starting Docker containers..."
docker compose down
docker compose up -d --build

# Wait for MySQL to become ready
echo "Waiting for MySQL to initialize..."
sleep 15

# 3. Install/Optimize Composer Dependencies
echo "Installing dependencies and optimizing autoloader..."
docker compose exec -T app composer install --optimize-autoloader --no-dev

# 4. Clear and Cache Laravel Configurations
echo "Optimizing Laravel configuration, routes, and views..."
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache
docker compose exec -T app php artisan event:cache

# 5. Run Database Migrations
echo "Running database migrations..."
docker compose exec -T app php artisan migrate --force

echo "Deployment completed successfully!"
