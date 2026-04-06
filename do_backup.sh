#!/bin/bash

# ===== კონფიგურაცია =====
BACKUP_DIR="/root/sanmedic/public/backups"
LOGS_DIR="$BACKUP_DIR/logs"
DB_HOST="sanmedic-db"
DB_USER="root"
DB_PASS="SanMedic_Root_2026!"
DB_NAME="sanmedic_clinic"
SERVER_NAME="SanMedic"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
LOG_FILE="$LOGS_DIR/backup_$(date +%Y-%m-%d).log"

# S3 კონფიგურაცია
S3_ENABLED="true"
S3_BUCKET="sanmedic"
S3_ENDPOINT="https://nbg1.your-objectstorage.com"
S3_ACCESS_KEY="736IUKBC61V7VPA7GEE4"
S3_SECRET_KEY="1EHTR4pT2FVkXKo8VxnxUsWQHB51ZMlfjpBhEOgC"
S3_PREFIX="backups/"

# პარამეტრი: local, s3, both
BACKUP_TYPE="${1:-local}"

# ===== ფუნქციები =====

log() {
    local level="$1"
    local message="$2"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $message" | tee -a "$LOG_FILE"
}

format_size() {
    local size=$1
    if [ $size -ge 1073741824 ]; then
        echo "$(echo "scale=2; $size/1073741824" | bc) GB"
    elif [ $size -ge 1048576 ]; then
        echo "$(echo "scale=2; $size/1048576" | bc) MB"
    elif [ $size -ge 1024 ]; then
        echo "$(echo "scale=2; $size/1024" | bc) KB"
    else
        echo "$size B"
    fi
}

s3_upload() {
    local file_path="$1"
    local s3_key="$2"
    local content_type="application/octet-stream"
    local date_value=$(date -R)
    local string_to_sign="PUT\n\n${content_type}\n${date_value}\n/${S3_BUCKET}/${s3_key}"
    local signature=$(echo -en "${string_to_sign}" | openssl sha1 -hmac "${S3_SECRET_KEY}" -binary | base64)
    
    curl -s -X PUT -T "$file_path" \
        -H "Host: ${S3_BUCKET}.nbg1.your-objectstorage.com" \
        -H "Date: ${date_value}" \
        -H "Content-Type: ${content_type}" \
        -H "Authorization: AWS ${S3_ACCESS_KEY}:${signature}" \
        "https://${S3_BUCKET}.nbg1.your-objectstorage.com/${s3_key}"
}

# ===== მთავარი სკრიპტი =====

mkdir -p "$LOGS_DIR"

log "INFO" "=========================================="
log "INFO" "ბექაპი დაიწყო - $SERVER_NAME (ტიპი: $BACKUP_TYPE)"
log "INFO" "=========================================="

# 1. მონაცემთა ბაზის ექსპორტი (Docker-ით)
log "INFO" "მონაცემთა ბაზის ექსპორტი..."
docker exec $DB_HOST mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > /tmp/database.sql 2>> "$LOG_FILE"

if [ $? -ne 0 ] || [ ! -s /tmp/database.sql ]; then
    log "ERROR" "მონაცემთა ბაზის ექსპორტი ვერ მოხერხდა!"
    exit 1
fi
log "SUCCESS" "მონაცემთა ბაზა ექსპორტირებულია: $(format_size $(stat -c%s /tmp/database.sql))"

# 2. კოდის არქივირება
log "INFO" "კოდის არქივირება..."
cd /root/sanmedic/public
tar -czf /tmp/code.tar.gz \
    --exclude=backups --exclude=temp_backup --exclude="*.zip" --exclude="*.tar.gz" --exclude=old22.01.2026 --exclude=vendor \
    --exclude=uploads \
    --exclude=vendor \
    --exclude=node_modules \
    --exclude=*.log \
    . 2>> "$LOG_FILE"

if [ $? -ne 0 ]; then
    log "ERROR" "კოდის არქივირება ვერ მოხერხდა!"
    exit 1
fi
log "SUCCESS" "კოდი არქივირებულია: $(format_size $(stat -c%s /tmp/code.tar.gz))"

# 3. uploads არქივირება
log "INFO" "Uploads არქივირება..."
if [ -d "/root/sanmedic/public/uploads" ]; then
    tar -czf /tmp/uploads.tar.gz -C /root/sanmedic/public uploads 2>> "$LOG_FILE"
    log "SUCCESS" "Uploads არქივირებულია"
else
    touch /tmp/uploads.tar.gz
    log "WARN" "Uploads დირექტორია არ არსებობს"
fi

# 4. config-ის არქივირება
log "INFO" "Config არქივირება..."
if [ -d "/root/sanmedic/config" ]; then
    tar -czf /tmp/config.tar.gz -C /root/sanmedic config 2>> "$LOG_FILE"
    log "SUCCESS" "Config არქივირებულია"
else
    touch /tmp/config.tar.gz
fi

# 5. საბოლოო არქივის შექმნა
BACKUP_NAME="sanmedic_full_backup_$(date +%Y-%m-%d_%H-%M-%S).zip"
BACKUP_PATH="$BACKUP_DIR/$BACKUP_NAME"

log "INFO" "საბოლოო არქივის შექმნა: $BACKUP_NAME"
cd /tmp
zip -q "$BACKUP_PATH" database.sql code.tar.gz uploads.tar.gz config.tar.gz 2>> "$LOG_FILE"

if [ $? -ne 0 ]; then
    log "ERROR" "საბოლოო არქივის შექმნა ვერ მოხერხდა!"
    exit 1
fi

# 6. ზომის გამოთვლა
BACKUP_SIZE=$(stat -c%s "$BACKUP_PATH")
BACKUP_SIZE_FORMATTED=$(format_size $BACKUP_SIZE)

log "SUCCESS" "ბექაპი შეიქმნა: $BACKUP_NAME ($BACKUP_SIZE_FORMATTED)"

# 7. S3-ზე ატვირთვა
if [ "$BACKUP_TYPE" = "s3" ] || [ "$BACKUP_TYPE" = "both" ]; then
    if [ "$S3_ENABLED" = "true" ]; then
        log "INFO" "S3-ზე ატვირთვა..."
        s3_upload "$BACKUP_PATH" "${S3_PREFIX}${BACKUP_NAME}"
        if [ $? -eq 0 ]; then
            log "SUCCESS" "ბექაპი აიტვირთა S3-ზე!"
        else
            log "ERROR" "S3 ატვირთვა ვერ მოხერხდა"
        fi
    fi
fi

# 8. დროებითი ფაილების წაშლა
rm -f /tmp/database.sql /tmp/code.tar.gz /tmp/uploads.tar.gz /tmp/config.tar.gz
log "INFO" "დროებითი ფაილები წაიშალა"

# 9. ძველი ბექაპების წაშლა (31 დღეზე მეტი)
log "INFO" "ძველი ბექაპების წაშლა..."
find "$BACKUP_DIR" -name "sanmedic_full_backup_*.zip" -type f -mtime +31 -delete 2>> "$LOG_FILE"

# 10. JSON ინფო განახლება
cat > "$BACKUP_DIR/backup_info.json" << EOF
{
    "last_backup": "$(date '+%Y-%m-%d %H:%M:%S')",
    "backup_file": "$BACKUP_NAME",
    "backup_size": "$BACKUP_SIZE_FORMATTED",
    "backup_type": "$BACKUP_TYPE"
}
EOF

log "INFO" "=========================================="
log "SUCCESS" "ბექაპი დასრულდა წარმატებით!"
log "INFO" "=========================================="

echo "$BACKUP_NAME"
