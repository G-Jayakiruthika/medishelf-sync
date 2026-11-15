<?php
/* Makes an encrypted backup of the family app's database now (cron.php also does this every Sunday).
     php tools/backup.php            → public_html/core/backups/family-YYYYmmdd-HHMMSS.sql.gz.enc (last 8 kept)
   Copy backups off the server regularly (your computer, Google Drive): a backup on the same server does not survive losing it.
   Keep the data key (core/config.php 'data_key', or core/data.key) somewhere safe too: without it the backups cannot be read. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/public_html/core/bootstrap.php';
require_once APP_DIR . '/backup.php';
$f = backup_make(db(), APP_DIR . '/backups', fam_data_key(), 'family');
q("INSERT INTO system_state (k, v) VALUES ('last_backup', NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v)");
echo "Backup written: $f (" . round(filesize($f) / 1024) . " KB)\n";
