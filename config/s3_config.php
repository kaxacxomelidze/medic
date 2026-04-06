<?php
/**
 * Hetzner Object Storage (S3 Compatible) Configuration
 * SanMedic Backup Storage - NBG1 Region
 */

define('S3_ENABLED', true);
define('S3_ENDPOINT', 'https://nbg1.your-objectstorage.com');
define('S3_REGION', 'nbg1');
define('S3_BUCKET', 'sanmedic');
define('S3_ACCESS_KEY', '736IUKBC61V7VPA7GEE4');
define('S3_SECRET_KEY', '1EHTR4pT2FVkXKo8VxnxUsWQHB51ZMlfjpBhEOgC');
define('S3_BACKUP_PREFIX', 'sanmedic/');

// URL expiry for downloads
define('S3_URL_EXPIRY', 3600); // 1 hour
