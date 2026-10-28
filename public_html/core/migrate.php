<?php
/* Keeps an existing database up to date after you upload a new version of the code.
   Safe to run any number of times: it only creates missing tables and adds missing columns.
   cron.php runs it automatically (at most once an hour). */
declare(strict_types=1);

const MIGRATION_COLUMNS = [
    // table => [column => definition]
    'cabinet_items' => [
        'stock_count' => 'SMALLINT UNSIGNED NULL AFTER notes',
        'per_dose' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER stock_count',
        'course_end' => 'DATE NULL AFTER per_dose',
    ],
];

function migrate(): array
{
    $done = [];
    // 1. Tables: every CREATE TABLE IF NOT EXISTS in schema.sql (existing tables are left alone).
    $sql = (string) file_get_contents(APP_DIR . '/database/schema.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $stmt, $m)) continue;
        if (!q('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$m[1]])->fetch()) {
            db()->exec($stmt);
            $done[] = "created table {$m[1]}";
        }
    }
    // 2. Columns added in later versions.
    foreach (MIGRATION_COLUMNS as $table => $cols) {
        foreach ($cols as $col => $def) {
            if (!q('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col])->fetch()) {
                db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                $done[] = "added $table.$col";
            }
        }
    }
    return $done;
}

/* Runs migrate() at most once an hour, remembered in a small file next to the lock. */
function migrate_if_due(): array
{
    $stamp = APP_DIR . '/cache/migrated.txt';
    $version = (string) filemtime(APP_DIR . '/database/schema.sql') . '-' . (string) filemtime(__FILE__);
    if (is_file($stamp) && trim((string) file_get_contents($stamp)) === $version) return [];
    $done = migrate();
    @mkdir(dirname($stamp), 0750, true);
    @file_put_contents($stamp, $version);
    return $done;
}
