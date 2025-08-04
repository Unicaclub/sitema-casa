#!/bin/sh

# ERP System Backup Script
# Automated backup for MySQL, Redis, and application files

set -e

# Configuration
BACKUP_DIR="/backups"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-30}

# Database configuration
DB_HOST=${DB_HOST:-database}
DB_NAME=${DB_DATABASE:-erp_sistema}
DB_USER=${DB_USERNAME:-erp_user}
DB_PASS=${DB_PASSWORD}

# Create backup directory
mkdir -p "$BACKUP_DIR"

echo "Starting backup process - $DATE"

# Function to log messages
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Function to cleanup old backups
cleanup_old_backups() {
    log "Cleaning up backups older than $RETENTION_DAYS days"
    find "$BACKUP_DIR" -name "*.tar.gz" -mtime +$RETENTION_DAYS -delete
    find "$BACKUP_DIR" -name "*.sql.gz" -mtime +$RETENTION_DAYS -delete
}

# MySQL Database Backup
backup_database() {
    log "Starting MySQL database backup"
    
    MYSQL_BACKUP_FILE="$BACKUP_DIR/mysql_${DB_NAME}_${DATE}.sql.gz"
    
    # Check if database is accessible
    if ! mysqladmin ping -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" --silent; then
        log "ERROR: Cannot connect to MySQL database"
        return 1
    fi
    
    # Create database dump
    mysqldump \
        -h "$DB_HOST" \
        -u "$DB_USER" \
        -p"$DB_PASS" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --hex-blob \
        --opt \
        "$DB_NAME" | gzip > "$MYSQL_BACKUP_FILE"
    
    if [ $? -eq 0 ]; then
        log "MySQL backup completed: $MYSQL_BACKUP_FILE"
        log "Backup size: $(du -h $MYSQL_BACKUP_FILE | cut -f1)"
    else
        log "ERROR: MySQL backup failed"
        return 1
    fi
}

# Redis Backup
backup_redis() {
    log "Starting Redis backup"
    
    REDIS_BACKUP_FILE="$BACKUP_DIR/redis_${DATE}.rdb"
    
    # Check if Redis is accessible
    if ! redis-cli -h redis ping > /dev/null 2>&1; then
        log "ERROR: Cannot connect to Redis"
        return 1
    fi
    
    # Force Redis to save
    redis-cli -h redis BGSAVE
    
    # Wait for background save to complete
    while [ "$(redis-cli -h redis LASTSAVE)" = "$(redis-cli -h redis LASTSAVE)" ]; do
        sleep 1
    done
    
    # Copy Redis dump file
    if [ -f "/backup/redis/dump.rdb" ]; then
        cp "/backup/redis/dump.rdb" "$REDIS_BACKUP_FILE"
        gzip "$REDIS_BACKUP_FILE"
        log "Redis backup completed: ${REDIS_BACKUP_FILE}.gz"
        log "Backup size: $(du -h ${REDIS_BACKUP_FILE}.gz | cut -f1)"
    else
        log "ERROR: Redis dump file not found"
        return 1
    fi
}

# Application Files Backup
backup_files() {
    log "Starting application files backup"
    
    APP_BACKUP_FILE="$BACKUP_DIR/app_files_${DATE}.tar.gz"
    
    # Create tar archive of storage directory
    if [ -d "/backup/storage" ]; then
        tar -czf "$APP_BACKUP_FILE" -C "/backup" storage/
        
        if [ $? -eq 0 ]; then
            log "Application files backup completed: $APP_BACKUP_FILE"
            log "Backup size: $(du -h $APP_BACKUP_FILE | cut -f1)"
        else
            log "ERROR: Application files backup failed"
            return 1
        fi
    else
        log "WARNING: Storage directory not found, skipping files backup"
    fi
}

# System Information Backup
backup_system_info() {
    log "Creating system information backup"
    
    SYSINFO_FILE="$BACKUP_DIR/system_info_${DATE}.txt"
    
    {
        echo "=== ERP System Backup Information ==="
        echo "Backup Date: $(date)"
        echo "Hostname: $(hostname)"
        echo "System: $(uname -a)"
        echo ""
        echo "=== Database Information ==="
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SELECT VERSION() as mysql_version;" 2>/dev/null || echo "MySQL version: N/A"
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SHOW DATABASES;" 2>/dev/null || echo "Databases: N/A"
        echo ""
        echo "=== Redis Information ==="
        redis-cli -h redis INFO server 2>/dev/null || echo "Redis info: N/A"
        echo ""
        echo "=== Disk Usage ==="
        df -h
        echo ""
        echo "=== Backup Files ==="
        ls -la "$BACKUP_DIR"/*${DATE}* 2>/dev/null || echo "No backup files found"
    } > "$SYSINFO_FILE"
    
    log "System information saved: $SYSINFO_FILE"
}

# Verify Backups
verify_backups() {
    log "Verifying backup files"
    
    # Check MySQL backup
    MYSQL_BACKUP_FILE="$BACKUP_DIR/mysql_${DB_NAME}_${DATE}.sql.gz"
    if [ -f "$MYSQL_BACKUP_FILE" ]; then
        if gzip -t "$MYSQL_BACKUP_FILE" 2>/dev/null; then
            log "MySQL backup verification: PASSED"
        else
            log "ERROR: MySQL backup verification FAILED"
            return 1
        fi
    fi
    
    # Check Redis backup
    REDIS_BACKUP_FILE="$BACKUP_DIR/redis_${DATE}.rdb.gz"
    if [ -f "$REDIS_BACKUP_FILE" ]; then
        if gzip -t "$REDIS_BACKUP_FILE" 2>/dev/null; then
            log "Redis backup verification: PASSED"
        else
            log "ERROR: Redis backup verification FAILED"
            return 1
        fi
    fi
    
    # Check application files backup
    APP_BACKUP_FILE="$BACKUP_DIR/app_files_${DATE}.tar.gz"
    if [ -f "$APP_BACKUP_FILE" ]; then
        if tar -tzf "$APP_BACKUP_FILE" >/dev/null 2>&1; then
            log "Application files backup verification: PASSED"
        else
            log "ERROR: Application files backup verification FAILED"
            return 1
        fi
    fi
}

# Main backup process
main() {
    log "=== ERP System Backup Started ==="
    
    # Create backup directory if it doesn't exist
    mkdir -p "$BACKUP_DIR"
    
    # Run backups
    backup_database || log "Database backup failed"
    backup_redis || log "Redis backup failed"
    backup_files || log "Files backup failed"
    backup_system_info
    
    # Verify backups
    verify_backups || log "Backup verification failed"
    
    # Cleanup old backups
    cleanup_old_backups
    
    # Final summary
    log "=== Backup Summary ==="
    log "Backup location: $BACKUP_DIR"
    log "Available backups:"
    ls -la "$BACKUP_DIR"/*${DATE}* 2>/dev/null || log "No backup files found"
    
    # Calculate total backup size
    TOTAL_SIZE=$(du -sh "$BACKUP_DIR" | cut -f1)
    log "Total backup size: $TOTAL_SIZE"
    
    log "=== ERP System Backup Completed ==="
}

# Run main function
main "$@"

exit 0