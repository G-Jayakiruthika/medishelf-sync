<?php
/* Encrypted database backups and restore, in plain PHP (works on shared hosting without shell access or mysqldump).
   The same file is used by the family app (public_html/core/backup.php) and the pharmacy platform (pharmacy-platform/core/backup.php);
   CI checks that the two copies match.

   A backup is: every table's CREATE TABLE and its rows as INSERT statements, gzip-compressed, then encrypted in 64 KB chunks with
   libsodium secretstream (XChaCha20-Poly1305): a changed or cut file is refused on restore. The key is derived from the site's
   data key, so keep that key somewhere safe outside the server: without it no backup can be read.

   backup_make($pdo, $dir, $key, 'family')  → path of the new file (<dir>/family-YYYYmmdd-HHMMSS.sql.gz.enc), old ones pruned
   backup_restore($pdo, $file, $key)         → number of statements run (into the database $pdo is connected to)            */
declare(strict_types=1);

const BACKUP_MARK = "\n-- ;;\n";      // statement separator (values are escaped, so it never appears inside data)
const BACKUP_CHUNK = 65536;

function backup_key(string $dataKey): string
{
    return sodium_crypto_generichash('aushadhbox-backup-v1', $dataKey, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
}

function backup_make(PDO $db, string $dir, string $dataKey, string $prefix, int $keep = 8): string
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException("Cannot create $dir");
    @file_put_contents($dir . '/.htaccess', "Require all denied\n");   // in case the folder ever ends up under the web root
    $name = $prefix . '-' . date('Ymd-His');
    $tmp = $dir . '/' . $name . '.sql.gz.tmp';
    $gz = gzopen($tmp, 'wb6');
    gzwrite($gz, "-- AushadhBox backup $name\nSET FOREIGN_KEY_CHECKS=0" . BACKUP_MARK);
    $tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $create = $db->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, 'DROP TABLE IF EXISTS `' . $t . '`' . BACKUP_MARK . $create . BACKUP_MARK);
        $st = $db->query('SELECT * FROM `' . str_replace('`', '', $t) . '`', PDO::FETCH_NUM);
        $rows = [];
        foreach ($st as $r) {
            $rows[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string) $v), $r)) . ')';
            if (count($rows) >= 200) { gzwrite($gz, 'INSERT INTO `' . $t . '` VALUES ' . implode(',', $rows) . BACKUP_MARK); $rows = []; }
        }
        if ($rows) gzwrite($gz, 'INSERT INTO `' . $t . '` VALUES ' . implode(',', $rows) . BACKUP_MARK);
    }
    gzwrite($gz, 'SET FOREIGN_KEY_CHECKS=1' . BACKUP_MARK);
    gzclose($gz);

    // Encrypt in chunks so a large backup never has to fit in memory.
    $out = $dir . '/' . $name . '.sql.gz.enc';
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(backup_key($dataKey));
    $in = fopen($tmp, 'rb'); $fo = fopen($out, 'wb');
    fwrite($fo, "ABK1" . $header);
    while (!feof($in)) {
        $chunk = (string) fread($in, BACKUP_CHUNK);
        $tag = feof($in) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
        fwrite($fo, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
    }
    fclose($in); fclose($fo); unlink($tmp); @chmod($out, 0600);
    backup_prune($dir, $prefix, $keep);
    return $out;
}

function backup_prune(string $dir, string $prefix, int $keep): void
{
    $files = glob($dir . '/' . $prefix . '-*.sql.gz.enc') ?: [];
    rsort($files);
    foreach (array_slice($files, $keep) as $f) @unlink($f);
}

function backup_restore(PDO $db, string $file, string $dataKey): int
{
    $fi = fopen($file, 'rb');
    if (!$fi || fread($fi, 4) !== 'ABK1') throw new RuntimeException('Not an AushadhBox backup file.');
    $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string) fread($fi, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), backup_key($dataKey));
    $tmp = tempnam(sys_get_temp_dir(), 'abr'); $fo = fopen($tmp, 'wb'); $final = false;
    while (!feof($fi)) {
        $c = (string) fread($fi, BACKUP_CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
        if ($c === '') break;
        $r = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c);
        if ($r === false) { fclose($fo); unlink($tmp); throw new RuntimeException('The backup is damaged or the key is wrong.'); }
        fwrite($fo, $r[0]);
        if ($r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) { $final = true; break; }
    }
    fclose($fi); fclose($fo);
    if (!$final) { unlink($tmp); throw new RuntimeException('The backup file is incomplete.'); }
    $gz = gzopen($tmp, 'rb'); $buf = ''; $n = 0;
    while (!gzeof($gz)) {
        $buf .= gzread($gz, BACKUP_CHUNK);
        while (($p = strpos($buf, BACKUP_MARK)) !== false) {
            $stmt = trim(substr($buf, 0, $p)); $buf = substr($buf, $p + strlen(BACKUP_MARK));
            if ($stmt !== '' && !str_starts_with($stmt, '--')) { $db->exec($stmt); $n++; }
            elseif (str_starts_with($stmt, '--') && str_contains($stmt, "\n")) { $db->exec(trim(substr($stmt, strpos($stmt, "\n") + 1))); $n++; }
        }
    }
    gzclose($gz); unlink($tmp);
    return $n;
}
