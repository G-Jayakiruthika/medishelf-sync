<?php
/* One-time installer: creates tables, loads medicine data and the first admin account.
   After it succeeds it writes core/install.lock and refuses to run again. Delete this file afterwards. */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/catalog.php';
security_headers();
header('Content-Type: text/html; charset=utf-8');

$lock = APP_DIR . '/install.lock';
$done = is_file($lock);
$msg = []; $err = null;

if (!$done && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    if (!hash_equals((string) config('cron_key'), (string) ($_POST['setup_key'] ?? '')) || str_starts_with((string) config('cron_key'), 'change-me')) {
        $err = 'The setup key does not match cron_key in core/config.php (and it must not be the example value).';
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 10) {
        $err = 'Enter a name, a valid e-mail and an admin password of at least 10 characters.';
    } else {
        try {
            install_all($msg);
            if (!q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) {
                q("INSERT INTO users (name, email, password_hash, role, consent_version, consent_at) VALUES (?,?,?,'admin',?,NOW())", [$name, $email, password_hash($pass, PASSWORD_DEFAULT), config('consent_version')]);
                $uid = (int) db()->lastInsertId();
                q('INSERT INTO members (user_id, name, relation, is_self, account_id) VALUES (?,?,?,1,?)', [$uid, $name, 'Self', $uid]);
                q('INSERT INTO reminder_settings (user_id) VALUES (?)', [$uid]);
                $msg[] = "Admin account created for $email.";
            }
            file_put_contents($lock, date('c'));
            $done = true;
        } catch (Throwable $e) {
            $err = 'Install failed: ' . $e->getMessage();
        }
    }
}

function install_all(array &$msg): void
{
    $sql = (string) file_get_contents(APP_DIR . '/database/schema.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
        if (str_starts_with($stmt, '--') && !str_contains($stmt, "\n")) continue;
        db()->exec($stmt);
    }
    $msg[] = 'Database tables created.';
    // Columns added in later versions live in core/migrate.php: run it now so a fresh install is complete from the start.
    require_once APP_DIR . '/migrate.php';
    $up = migrate();
    if ($up) $msg[] = 'Database brought up to date (' . count($up) . ' changes).';
    $msg[] = load_info_json(APP_DIR . '/database/medicine_info.json') . ' ingredient guides loaded.';
    foreach (glob(APP_DIR . '/database/catalog-*.csv') ?: [] as $file) {
        $source = basename($file, '.csv');
        $source = substr($source, strlen('catalog-'));
        $r = import_catalog(parse_list_file((string) file_get_contents($file)), $source);
        $msg[] = "{$r['imported']} medicines imported from " . basename($file) . '.';
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install AushadhBox</title>
<style>body{font:16px/1.6 system-ui,sans-serif;max-width:560px;margin:40px auto;padding:0 16px;color:#16202E;background:#EEF2EF}
form{display:grid;gap:12px;background:#fff;padding:20px;border-radius:14px;border:1px solid #CDD6D0}input{padding:10px;border:1px solid #CDD6D0;border-radius:10px;font:inherit}
button{padding:12px;border:0;border-radius:999px;background:#1F3358;color:#fff;font-weight:700;cursor:pointer}.err{color:#B3382A}.ok{color:#3E7D49}</style></head><body>
<h1>Install AushadhBox</h1>
<?php if ($done): ?>
  <?php foreach ($msg as $m): ?><p class="ok">✔ <?= htmlspecialchars($m) ?></p><?php endforeach; ?>
  <p><b>Installation is complete.</b> Delete <code>install.php</code> from the server now, then <a href="/">open your website</a>. Your app is at <a href="/app">/app</a> and the admin dashboard at <a href="/admin.html">/admin.html</a>.</p>
<?php else: ?>
  <p>This creates the database tables, loads the medicine list and makes your administrator account.</p>
  <?php if ($err): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>
  <form method="post">
    <label>Setup key (the <code>cron_key</code> you put in core/config.php) <input name="setup_key" type="password" required></label>
    <label>Your name <input name="name" required></label>
    <label>Admin e-mail <input name="email" type="email" required></label>
    <label>Admin password (10+ characters) <input name="password" type="password" minlength="10" required></label>
    <button>Install</button>
  </form>
<?php endif; ?>
</body></html>
