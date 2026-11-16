<?php
/* A small file cache for public pages under load (shared hosting often has no APCu or Redis).
   Values are stored as JSON, never PHP-serialised, so a changed cache file cannot create PHP objects (no unsafe
   deserialisation). Files live in the system temp folder, outside the web root. cache_clear() empties it, e.g. after
   the medicine list is imported. */
declare(strict_types=1);

function cache_dir(): string
{
    $d = sys_get_temp_dir() . '/abx-cache-' . substr(md5(APP_DIR), 0, 12);
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

function cache_remember(string $key, int $ttl, callable $make)
{
    $f = cache_dir() . '/' . sha1($key) . '.json';
    if (is_file($f) && filemtime($f) > time() - $ttl) {
        $raw = @file_get_contents($f);
        if ($raw !== false) { $v = json_decode($raw, true); if (is_array($v) && array_key_exists('v', $v)) return $v['v']; }
    }
    $val = $make();
    $tmp = $f . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode(['v' => $val], JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $f);   // atomic: readers never see half a file
    return $val;
}

function cache_clear(): void
{
    foreach (glob(cache_dir() . '/*.json') ?: [] as $f) @unlink($f);
}
