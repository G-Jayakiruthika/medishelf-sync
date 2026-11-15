<?php
/* Encrypted backup of the pharmacy network's database (cron.php also does this every Sunday).
     php tools/backup.php   → pharmacy-platform/backups/pharmacy-YYYYmmdd-HHMMSS.sql.gz.enc (last 8 kept)
   Copy backups off the server, and keep config.php's data_key safe: without it nothing can be read (customer data too). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/backup.php';
$f = backup_make(db(), dirname(__DIR__) . '/backups', data_key(), 'pharmacy');
echo "Backup written: $f (" . round(filesize($f) / 1024) . " KB)\n";
