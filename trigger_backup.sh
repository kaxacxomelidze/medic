#!/bin/bash
# Trigger check script - ყოველ წუთს ამოწმებს trigger ფაილს

TRIGGER_FILE="/root/sanmedic/public/backups/trigger"
LOCK_FILE="/tmp/sanmedic_backup.lock"

if [ -f "$TRIGGER_FILE" ]; then
    if [ -f "$LOCK_FILE" ]; then
        echo "Backup already running"
        exit 0
    fi
    
    touch "$LOCK_FILE"
    UPLOAD_S3=$(cat "$TRIGGER_FILE")
    rm -f "$TRIGGER_FILE"
    
    cd /root/sanmedic
    
    if [ "$UPLOAD_S3" = "1" ]; then
        /root/sanmedic/do_backup.sh both >> /root/sanmedic/public/backups/logs/backup_$(date +%Y-%m-%d).log 2>&1
    else
        /root/sanmedic/do_backup.sh local >> /root/sanmedic/public/backups/logs/backup_$(date +%Y-%m-%d).log 2>&1
    fi
    
    rm -f "$LOCK_FILE"
fi
