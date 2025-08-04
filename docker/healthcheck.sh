#!/bin/sh

# Health check script for ERP System

set -e

# Check if PHP-FPM is running
if ! pgrep -f php-fpm > /dev/null; then
    echo "PHP-FPM is not running"
    exit 1
fi

# Check if Nginx is running
if ! pgrep -f nginx > /dev/null; then
    echo "Nginx is not running"
    exit 1
fi

# Check PHP-FPM status
if ! curl -f http://localhost/fpm-status > /dev/null 2>&1; then
    echo "PHP-FPM status check failed"
    exit 1
fi

# Check PHP-FPM ping
if ! curl -f http://localhost/fpm-ping > /dev/null 2>&1; then
    echo "PHP-FPM ping failed"
    exit 1
fi

# Check if the application responds
if ! curl -f http://localhost/health > /dev/null 2>&1; then
    echo "Application health check failed"
    exit 1
fi

# Check Redis connection
if ! nc -z redis 6379; then
    echo "Redis connection failed"
    exit 1
fi

# Check database connection
if ! nc -z database 3306; then
    echo "Database connection failed"
    exit 1
fi

# Check disk space
DISK_USAGE=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -gt 90 ]; then
    echo "Disk usage is too high: ${DISK_USAGE}%"
    exit 1
fi

# Check memory usage
MEMORY_USAGE=$(free | grep Mem | awk '{printf("%.0f", $3/$2 * 100.0)}')
if [ "$MEMORY_USAGE" -gt 90 ]; then
    echo "Memory usage is too high: ${MEMORY_USAGE}%"
    exit 1
fi

echo "All health checks passed"
exit 0