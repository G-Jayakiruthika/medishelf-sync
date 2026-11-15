<?php
/* Restores a backup made by tools/backup.php INTO THE DATABASE IN core/config.php, replacing its tables.
     php tools/restore.php public_html/core/backups/family-20261115-020000.sql.gz.enc --yes
   Safer: point core/config.php at a new, empty database first, restore there, check the site, then switch over.
   Needs the same data key that made the backup. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/public_html/core/bootstrap.php';
require_once APP_DIR . '/backup.php';
$file = $argv[1] ?? '';
if (!is_file($file) || !in_array('--yes', $argv, true)) { fwrite(STDERR, "Usage: php tools/restore.php BACKUP_FILE --yes   (replaces the tables of database '" . config('db_name') . "')\n"); exit(1); }
$n = backup_restore(db(), $file, fam_data_key());
echo "Restored $n statements into " . config('db_name') . ".\n";
