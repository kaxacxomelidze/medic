#!/bin/bash
# Sanmedic Docker Logs Backup Script
# Run before docker-compose down to preserve logs

BACKUP_DIR="/root/sanmedic/logs/docker_backup"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

# Backup PHP container logs
docker logs sanmedic-php > "$BACKUP_DIR/php_${DATE}.log" 2>&1

# Backup Nginx container logs  
docker logs sanmedic-nginx > "$BACKUP_DIR/nginx_${DATE}.log" 2>&1

# Backup MySQL container logs
docker logs sanmedic-db > "$BACKUP_DIR/mysql_${DATE}.log" 2>&1

echo "Logs backed up to $BACKUP_DIR"

# Keep only last 30 backups per container
find "$BACKUP_DIR" -name "php_*.log" -mtime +30 -delete 2>/dev/null
find "$BACKUP_DIR" -name "nginx_*.log" -mtime +30 -delete 2>/dev/null  
find "$BACKUP_DIR" -name "mysql_*.log" -mtime +30 -delete 2>/dev/null
