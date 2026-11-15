<?php
/* Keeps a running platform's database in step with the code after an upload: creates missing tables from schema.sql
   and adds missing columns listed below. Safe to run any number of times. cron.php runs it; api.php runs it at most
   once per code version (a cheap check). */
declare(strict_types=1);

const PH_MIGRATION_COLUMNS = [
    // table => [column => definition]
    'orders' => [
        'dose_pack' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER note',                // 一包化: pack each dose time in its own pouch
        'pickup_at' => 'DATETIME NULL AFTER dose_pack',                           // the customer's chosen pickup time (prescription sent ahead)
        'service'   => 'VARCHAR(10) NULL AFTER pickup_at',                        // home_visit (訪問) or review (medicine review)
    ],
    'order_items' => [
        'times' => "VARCHAR(10) NOT NULL DEFAULT '' AFTER schedule",              // dose times from the family app (m,a,e,b), for pouch labels
    ],
    'branches' => [
        'fee_exempt' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER accepts_cash',       // e.g. Jan Aushadhi Kendras: no platform commission
    ],
];

function ph_has_table(string $t): bool
{
    return (bool) q('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t])->fetch();
}

function ph_migrate(): array
{
    $done = [];
    $sql = (string) file_get_contents(PH_DIR . '/database/schema.sql');
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $stmt, $m)) continue;
        if (!ph_has_table($m[1])) { db()->exec($stmt); $done[] = "table {$m[1]}"; }
    }
    foreach (PH_MIGRATION_COLUMNS as $table => $cols) {
        if (!ph_has_table($table)) continue;
        foreach ($cols as $col => $def) {
            if (q('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col])->fetch()) continue;
            db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            $done[] = "$table.$col";
        }
    }
    return $done;
}

/* Runs ph_migrate() only when schema.sql or this file changed since the last run. */
function ph_migrate_if_due(): array
{
    $version = filemtime(PH_DIR . '/database/schema.sql') . '-' . filemtime(__FILE__);
    $file = sys_get_temp_dir() . '/abph-migrated-' . md5(PH_DIR) . '.txt';
    if (@file_get_contents($file) === $version) return [];
    $done = ph_migrate();
    @file_put_contents($file, $version);
    return $done;
}
