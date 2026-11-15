<?php
/* Restores a backup INTO THE DATABASE IN core/config.php, replacing its tables. Restore into a new, empty database first if you can.
     php tools/restore.php backups/pharmacy-20261115-020000.sql.gz.enc --yes */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/backup.php';
$file = $argv[1] ?? '';
if (!is_file($file) || !in_array('--yes', $argv, true)) { fwrite(STDERR, "Usage: php tools/restore.php BACKUP_FILE --yes   (replaces the tables of '" . config('db_name') . "')\n"); exit(1); }
echo 'Restored ' . backup_restore(db(), $file, data_key()) . ' statements into ' . config('db_name') . ".\n";
