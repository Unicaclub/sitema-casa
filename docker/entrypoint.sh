#!/bin/sh

set -e

echo "Starting ERP System initialization..."

# Create necessary directories
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/cache
mkdir -p /var/www/html/storage/sessions
mkdir -p /var/www/html/storage/uploads
mkdir -p /var/log/nginx
mkdir -p /var/log/php-fpm
mkdir -p /var/log/supervisor

# Set proper permissions
chown -R www:www /var/www/html/storage
chmod -R 755 /var/www/html/storage

# Wait for database to be ready
echo "Waiting for database connection..."
until nc -z database 3306; do
    echo "Database is unavailable - sleeping"
    sleep 2
done
echo "Database is ready!"

# Wait for Redis to be ready
echo "Waiting for Redis connection..."
until nc -z redis 6379; do
    echo "Redis is unavailable - sleeping"
    sleep 2
done
echo "Redis is ready!"

# Run database migrations if needed
if [ "$APP_ENV" = "production" ]; then
    echo "Running database migrations..."
    # Add migration commands here if you have them
    # php artisan migrate --force
fi

# Clear cache
echo "Clearing application cache..."
# Add cache clearing commands here
# php artisan cache:clear
# php artisan config:cache
# php artisan route:cache

# Generate application key if not exists
if [ -z "$APP_KEY" ]; then
    echo "Generating application key..."
    # php artisan key:generate --force
fi

echo "ERP System initialization completed!"

# Execute the main command
exec "$@"