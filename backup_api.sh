#!/bin/bash
# Simple API server for backup requests
# Run: nohup /root/sanmedic/backup_api.sh &

PORT=9999

while true; do
    # Listen for requests
    REQUEST=$(nc -l -p $PORT -q 1)
    
    # Parse request
    if echo "$REQUEST" | grep -q "local"; then
        /root/sanmedic/do_backup.sh local 2>&1
        echo "HTTP/1.1 200 OK\r\n\r\nBackup completed"
    elif echo "$REQUEST" | grep -q "both"; then
        /root/sanmedic/do_backup.sh both 2>&1
        echo "HTTP/1.1 200 OK\r\n\r\nBackup with S3 completed"
    else
        echo "HTTP/1.1 400 Bad Request\r\n\r\nInvalid request"
    fi
done
