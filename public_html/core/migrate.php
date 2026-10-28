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
    'medicine_info' => [
        'usual_times' => "VARCHAR(10) NOT NULL DEFAULT '' AFTER slug",
        'course_days' => 'TINYINT UNSIGNED NULL AFTER usual_times',
        'food' => 'VARCHAR(20) NULL AFTER course_days',
    ],
    'users' => [
        'last_seen_at' => 'DATETIME NULL AFTER onboarded',
        'ai_opt_in' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER last_seen_at',
    ],
    'contact_messages' => [
        'category' => 'VARCHAR(12) NULL AFTER handled',
        'priority' => 'VARCHAR(6) NULL AFTER category',
        'sentiment' => 'VARCHAR(8) NULL AFTER priority',
        'due_at' => 'DATETIME NULL AFTER sentiment',
        'suggested' => 'TEXT NULL AFTER due_at',
        'reply' => 'TEXT NULL AFTER suggested',
        'replied_at' => 'DATETIME NULL AFTER reply',
    ],
    'members' => [
        'account_id' => 'INT UNSIGNED NULL AFTER is_self',
    ],
    'dose_logs' => [
        'status' => "VARCHAR(8) NOT NULL DEFAULT 'taken' AFTER taken_at",
        'reason' => 'VARCHAR(10) NULL AFTER status',
        'source' => "VARCHAR(6) NOT NULL DEFAULT 'app' AFTER reason",
    ],
    'automation_settings' => [
        'timing_on' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER cleanup_on',
    ],
];

/* Columns whose definition changed: table => [column => [check, new definition]] */
const MIGRATION_CHANGES = [
    'cabinet_items' => ['exp_month' => ['nullable', 'DATE NULL']],
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
    // 3. Changed column definitions.
    foreach (MIGRATION_CHANGES as $table => $cols) {
        foreach ($cols as $col => [$check, $def]) {
            $r = q('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col])->fetch();
            if ($r && $check === 'nullable' && $r['IS_NULLABLE'] === 'NO') { db()->exec("ALTER TABLE `$table` MODIFY `$col` $def"); $done[] = "made $table.$col optional"; }
        }
    }
    // 4. Link each owner's own "self" member to their login (used by the "did you take it?" check).
    $n = q('UPDATE members m JOIN users u ON u.id = m.user_id SET m.account_id = u.id WHERE m.is_self = 1 AND m.account_id IS NULL')->rowCount();
    if ($n) $done[] = "linked $n self members to their logins";
    // 5. Ingredient data that came with this version (usual schedules, brand names): reload when the file changes.
    $file = APP_DIR . '/database/medicine_info.json';
    $hash = is_file($file) ? md5_file($file) : '';
    $have = q("SELECT v FROM system_state WHERE k = 'info_json_hash'")->fetchColumn();
    if (function_exists('load_info_json') && $hash && $have !== $hash) {
        $done[] = 'loaded ' . load_info_json($file) . ' ingredient guides';
        q("INSERT INTO system_state (k, v) VALUES ('info_json_hash', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$hash]);
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
