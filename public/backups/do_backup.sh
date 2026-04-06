#!/bin/bash

# ===== SanMedic Backup Script =====
BACKUP_DIR="/var/www/html/backups"
LOGS_DIR="$BACKUP_DIR/logs"
DB_HOST="sanmedic-db"
DB_USER="root"
DB_PASS="SanMedic_Root_2026!"
DB_NAME="sanmedic_db"
SERVER_NAME="SanMedic"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
DATE_ONLY=$(date +"%Y-%m-%d")
LOG_FILE="$LOGS_DIR/backup_${DATE_ONLY}.log"

# S3 Config
S3_ENDPOINT="nbg1.your-objectstorage.com"
S3_BUCKET="sanmedic"
S3_ACCESS_KEY="736IUKBC61V7VPA7GEE4"
S3_SECRET_KEY="1EHTR4pT2FVkXKo8VxnxUsWQHB51ZMlfjpBhEOgC"

# Backup type: local, s3, both
BACKUP_TYPE="${1:-local}"

# ===== Functions =====

log() {
    local level="$1"
    local message="$2"
    local now=$(date "+%Y-%m-%d %H:%M:%S")
    echo "[$now] [$level] $message" | tee -a "$LOG_FILE"
}

format_size() {
    local size=$1
    if [ $size -ge 1073741824 ]; then
        echo "$(awk "BEGIN {printf \"%.2f\", $size/1073741824}") GB"
    elif [ $size -ge 1048576 ]; then
        echo "$(awk "BEGIN {printf \"%.2f\", $size/1048576}") MB"
    elif [ $size -ge 1024 ]; then
        echo "$(awk "BEGIN {printf \"%.2f\", $size/1024}") KB"
    else
        echo "$size B"
    fi
}

# ===== Main Script =====

mkdir -p "$LOGS_DIR" 2>/dev/null || true

log "INFO" "=========================================="
log "INFO" "Backup started - $SERVER_NAME (type: $BACKUP_TYPE)"
log "INFO" "=========================================="

# 1. Database export
log "INFO" "Exporting database..."
mysqldump --no-tablespaces -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > /tmp/sanmedic_database.sql 2>&1

if [ $? -ne 0 ] || [ ! -s /tmp/sanmedic_database.sql ]; then
    log "ERROR" "Database export failed!"
    cat /tmp/sanmedic_database.sql >> "$LOG_FILE" 2>/dev/null
    exit 1
fi
log "SUCCESS" "Database exported"

# 2. Create backup zip
BACKUP_NAME="sanmedic_full_backup_${DATE_ONLY}.zip"
BACKUP_PATH="$BACKUP_DIR/$BACKUP_NAME"

log "INFO" "Creating backup archive: $BACKUP_NAME"

# Remove old backup with same name if exists
rm -f "$BACKUP_PATH" 2>/dev/null

cd /var/www/html

# Create zip with all files
zip -r "$BACKUP_PATH" . -x "backups/*" -x "*.log" >> "$LOG_FILE" 2>&1

# Add database to zip
zip -j "$BACKUP_PATH" /tmp/sanmedic_database.sql >> "$LOG_FILE" 2>&1

if [ $? -ne 0 ] || [ ! -f "$BACKUP_PATH" ]; then
    log "ERROR" "Archive creation failed!"
    exit 1
fi

# 3. Get backup size
BACKUP_SIZE=$(stat -c%s "$BACKUP_PATH" 2>/dev/null || stat -f%z "$BACKUP_PATH" 2>/dev/null || echo "0")
BACKUP_SIZE_FORMATTED=$(format_size $BACKUP_SIZE)

log "SUCCESS" "Backup created: $BACKUP_NAME ($BACKUP_SIZE_FORMATTED)"

# 4. Upload to S3 if needed
if [ "$BACKUP_TYPE" = "s3" ] || [ "$BACKUP_TYPE" = "both" ]; then
    log "INFO" "Uploading to Hetzner S3..."
    
    S3_KEY="backups/${BACKUP_NAME}"
    CONTENT_TYPE="application/octet-stream"
    DATE_VALUE=$(date -R)
    STRING_TO_SIGN="PUT\n\n${CONTENT_TYPE}\n${DATE_VALUE}\n/${S3_BUCKET}/${S3_KEY}"
    SIGNATURE=$(echo -en "${STRING_TO_SIGN}" | openssl sha1 -hmac "${S3_SECRET_KEY}" -binary | base64)
    
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X PUT -T "${BACKUP_PATH}" \
        -H "Host: ${S3_BUCKET}.${S3_ENDPOINT}" \
        -H "Date: ${DATE_VALUE}" \
        -H "Content-Type: ${CONTENT_TYPE}" \
        -H "Authorization: AWS ${S3_ACCESS_KEY}:${SIGNATURE}" \
        "https://${S3_BUCKET}.${S3_ENDPOINT}/${S3_KEY}")
    
    if [ "$HTTP_CODE" = "200" ]; then
        log "SUCCESS" "Uploaded to S3: $S3_KEY"
    else
        log "ERROR" "S3 upload failed! HTTP: $HTTP_CODE"
    fi
fi

# 5. Cleanup temp files
rm -f /tmp/sanmedic_database.sql
log "INFO" "Temp files cleaned"

# 6. Delete old backups (older than 31 days)
log "INFO" "Deleting old backups..."
find "$BACKUP_DIR" -name "sanmedic_*.zip" -type f -mtime +31 -delete 2>/dev/null || true

log "INFO" "=========================================="
log "SUCCESS" "Backup completed successfully!"
log "INFO" "=========================================="

# Save info
NOW=$(date "+%Y-%m-%d %H:%M:%S")
echo "{\"last_backup\": \"$BACKUP_NAME\", \"size\": \"$BACKUP_SIZE_FORMATTED\", \"date\": \"$NOW\", \"type\": \"$BACKUP_TYPE\"}" > "$BACKUP_DIR/backup_info.json"

echo "$BACKUP_NAME"
