<?php
/* AushadhBox Pharmacy: the API behind /pharmacy (pharmacy.html). A separate sign-in from family accounts.
   Every call: pharmacy_api.php?action=<name>. Changes use POST with the X-CSRF-Token header. */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/catalog.php';
require __DIR__ . '/core/services.php';
require __DIR__ . '/core/plans.php';
require __DIR__ . '/core/doses.php';
require __DIR__ . '/core/safety.php';
require __DIR__ . '/core/care.php';
require __DIR__ . '/core/ai.php';
require __DIR__ . '/core/autopilot.php';
require __DIR__ . '/core/pharmacy.php';
require_once __DIR__ . '/core/migrate.php';
try { migrate_if_due(); } catch (Throwable $e) { error_log('migrate: ' . $e->getMessage()); }

security_headers();
start_session();
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') require_csrf();

function ph_limit(string $kind, int $max, int $minutes, ?string $key = null): void
{
    $n = (int) q('SELECT COUNT(*) FROM login_attempts WHERE created_at >= NOW() - INTERVAL ' . $minutes . ' MINUTE AND email = ? AND (ip = ? OR ? IS NOT NULL) AND success = 0',
                 ['ph:' . $kind . ':' . ($key ?? ''), client_ip(), $key])->fetchColumn();
    if ($n >= $max) fail('too_many', "Too many attempts. Please wait $minutes minutes.", 429);
}
function ph_attempt(string $kind, ?string $key = null): void { q('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,0)', [client_ip(), 'ph:' . $kind . ':' . ($key ?? '')]); }

function ph_current(): ?array
{
    if (empty($_SESSION['ph_id'])) return null;
    return q('SELECT id, name, license_no, city, address, phone, email, status, created_at FROM pharmacies WHERE id = ?', [$_SESSION['ph_id']])->fetch() ?: null;
}
function ph_public(array $p): array
{
    return ['id' => (int) $p['id'], 'name' => $p['name'], 'licenseNo' => $p['license_no'], 'city' => $p['city'], 'address' => $p['address'], 'phone' => $p['phone'], 'email' => $p['email'], 'status' => $p['status']];
}
function ph_require(): array
{
    $p = ph_current();
    if (!$p) fail('not_signed_in', 'Please sign in.', 401);
    if ($p['status'] !== 'approved') fail('not_approved', $p['status'] === 'pending' ? 'Your pharmacy is waiting for approval. We check the drug licence and usually approve within one working day.' : 'This pharmacy account is suspended. Please contact support.', 403);
    return $p;
}

try {
    switch ($action) {
    case 'session':
        $p = ph_current();
        json_out(['pharmacy' => $p ? ph_public($p) : null, 'csrf' => $_SESSION['csrf']]);

    case 'signup':
        ph_limit('signup', 5, 60);
        $in = input();
        $name = str_in($in, 'name', 100, true); $lic = str_in($in, 'license_no', 40, true); $city = str_in($in, 'city', 60, true);
        $email = strtolower((string) str_in($in, 'email', 160, true));
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) $phone = substr($phone, 2);
        $pass = (string) ($in['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email', 'Please enter a valid e-mail address.');
        if (!preg_match('/^[6-9]\d{9}$/', $phone)) fail('bad_phone', 'Enter a 10-digit mobile number.');
        if (mb_strlen($pass) < 8) fail('weak_password', 'Use at least 8 characters for the password.');
        if (empty($in['agree'])) fail('no_agree', 'Please accept the pharmacy terms.');
        if (q('SELECT 1 FROM pharmacies WHERE email = ?', [$email])->fetch()) fail('exists', 'A pharmacy with this e-mail already exists. Sign in instead.', 409);
        ph_attempt('signup');
        q('INSERT INTO pharmacies (name, license_no, city, address, phone, email, password_hash) VALUES (?,?,?,?,?,?,?)',
          [$name, $lic, $city, str_in($in, 'address', 200), $phone, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        $id = (int) db()->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['ph_id'] = $id;
        autopilot_log('pharmacy', "New pharmacy waiting for approval: $name ($city)", "Licence $lic");
        q('INSERT IGNORE INTO insights (day, kind, metric, severity, title, body) VALUES (CURDATE(), ?, ?, ?, ?, ?)', ['support', 'pharmacy-' . $id, 'medium', "Approve pharmacy: $name, $city", "Check drug licence $lic, then approve it under Pharmacies."]);
        if (mail_enabled() && config('support_email')) send_mail((string) config('support_email'), "[Pharmacy signup] $name, $city", "Licence: $lic\nPhone: $phone\nE-mail: $email\n\nApprove it in the admin dashboard → Pharmacies.");
        json_out(['ok' => true, 'pharmacy' => ph_public(ph_current())]);

    case 'login':
        $in = input();
        $email = strtolower((string) ($in['email'] ?? ''));
        ph_limit('login', 8, 15, $email);
        $p = q('SELECT id, password_hash FROM pharmacies WHERE email = ?', [$email])->fetch();
        if (!$p || !password_verify((string) ($in['password'] ?? ''), $p['password_hash'])) { ph_attempt('login', $email); fail('bad_login', 'E-mail or password is not correct.', 401); }
        session_regenerate_id(true);
        $_SESSION['ph_id'] = (int) $p['id'];
        q('UPDATE pharmacies SET last_login_at = NOW() WHERE id = ?', [$p['id']]);
        json_out(['ok' => true, 'pharmacy' => ph_public(ph_current())]);

    case 'logout':
        unset($_SESSION['ph_id']);
        session_regenerate_id(true);
        json_out(['ok' => true, 'csrf' => $_SESSION['csrf'] ?? '']);

    case 'stats':
        $p = ph_require();
        json_out([
            'dispensed30' => (int) q("SELECT COUNT(*) FROM cabinet_items WHERE pharmacy_id = ? AND created_at >= NOW() - INTERVAL 30 DAY", [$p['id']])->fetchColumn(),
            'patients' => (int) q('SELECT COUNT(*) FROM pharmacy_links WHERE pharmacy_id = ? AND blocked = 0', [$p['id']])->fetchColumn(),
            'openRefills' => (int) q("SELECT COUNT(*) FROM refill_requests WHERE pharmacy_id = ? AND status = 'sent'", [$p['id']])->fetchColumn(),
            'needsCall' => (int) q("SELECT COUNT(*) FROM followups f JOIN cabinet_items c ON c.id = f.item_id JOIN members m ON m.id = c.member_id
                                    WHERE c.pharmacy_id = ? AND m.share_view = 1 AND f.answer IN ('worse','side') AND f.answered_at >= NOW() - INTERVAL 14 DAY", [$p['id']])->fetchColumn(),
        ]);

    case 'patient':   // scan or type the card code
        $p = ph_require();
        ph_limit('scan', 120, 60, 'p' . $p['id']);
        $code = (string) (input()['code'] ?? '');
        if (!card_normalize($code)) { ph_attempt('scan', 'p' . $p['id']); fail('bad_card', 'That is not an AushadhBox card code.'); }
        try { json_out(pharmacy_patient($p, $code)); }
        catch (Throwable $e) { throw $e; }

    case 'precheck':
        $p = ph_require(); $in = input();
        json_out(pharmacy_precheck($p, (string) ($in['code'] ?? ''), is_array($in['items'] ?? null) ? $in['items'] : []));

    case 'dispense':
        $p = ph_require(); $in = input();
        json_out(pharmacy_dispense($p, (string) ($in['code'] ?? ''), is_array($in['items'] ?? null) ? $in['items'] : []));

    case 'catalog':
        ph_require();
        $qText = trim((string) ($_GET['q'] ?? ''));
        json_out(['results' => mb_strlen($qText) < 2 ? [] : array_map(function ($r) { $i = $r['info'] ?? null; return ['id' => $r['id'], 'name' => $r['name'], 'unit' => $r['unit'], 'usualTimes' => $i['usualTimes'] ?? [], 'courseDays' => $i['courseDays'] ?? null]; }, search_catalog(mb_substr($qText, 0, 80), 12))]);

    case 'refills':
        $p = ph_require();
        $rows = q("SELECT r.id, r.items, r.note, r.status, r.created_at, r.updated_at, m.name member, u.name family, u.phone FROM refill_requests r JOIN users u ON u.id = r.user_id LEFT JOIN members m ON m.id = r.member_id
                   WHERE r.pharmacy_id = ? AND (r.status IN ('sent','ready') OR r.updated_at >= NOW() - INTERVAL 7 DAY) ORDER BY FIELD(r.status, 'sent', 'ready', 'collected', 'cancelled'), r.id DESC LIMIT 60", [$p['id']])->fetchAll();
        json_out(['refills' => array_map(fn($r) => ['id' => (int) $r['id'], 'for' => masked_name($r['member'] ?? $r['family']), 'phone' => $r['phone'], 'items' => json_decode($r['items'], true) ?: [], 'note' => $r['note'], 'status' => $r['status'], 'at' => $r['created_at']], $rows)]);

    case 'refill_status':
        $p = ph_require(); $in = input();
        $st = (string) ($in['status'] ?? '');
        if (!in_array($st, ['ready', 'collected', 'cancelled'], true)) fail('bad_status', 'Unknown status.');
        $r = q('SELECT * FROM refill_requests WHERE id = ? AND pharmacy_id = ?', [(int) ($in['id'] ?? 0), $p['id']])->fetch();
        if (!$r) fail('not_found', 'Request not found.', 404);
        q('UPDATE refill_requests SET status = ? WHERE id = ?', [$st, $r['id']]);
        pharmacy_log((int) $p['id'], (int) $r['user_id'], $r['member_id'] ? (int) $r['member_id'] : null, 'refill', "Refill request marked $st");
        if ($st !== 'collected') notify_household((int) $r['user_id'], ['title' => $st === 'ready' ? 'Your medicines are ready at ' . $p['name'] : $p['name'] . ' could not prepare your refill',
            'body' => $st === 'ready' ? 'Collect them when convenient. ' . ($p['address'] ? $p['address'] : $p['city']) : 'Please call the pharmacy: ' . $p['phone'], 'url' => '/app#card', 'tag' => 'refill-' . $r['id']]);
        json_out(['ok' => true]);

    case 'followups':   // like a pharmacist's 服薬フォローアップ: how patients are doing on medicines this pharmacy gave (only with "view" consent)
        $p = ph_require();
        $rows = q("SELECT c.name medicine, m.name member, m.card_code, f.answer, f.note, f.answered_at, f.step FROM followups f JOIN cabinet_items c ON c.id = f.item_id JOIN members m ON m.id = c.member_id
                   WHERE c.pharmacy_id = ? AND m.share_view = 1 AND f.answered_at >= NOW() - INTERVAL 60 DAY
                   AND NOT EXISTS (SELECT 1 FROM pharmacy_links l WHERE l.member_id = m.id AND l.pharmacy_id = ? AND l.blocked = 1) ORDER BY FIELD(f.answer, 'side', 'worse', 'same', 'better'), f.answered_at DESC LIMIT 60", [$p['id'], $p['id']])->fetchAll();
        json_out(['followups' => array_map(fn($r) => ['medicine' => $r['medicine'], 'for' => masked_name($r['member']), 'code' => $r['card_code'], 'answer' => $r['answer'], 'note' => $r['note'], 'at' => substr($r['answered_at'], 0, 16), 'day' => $r['step'] == 1 ? 3 : 10], $rows)]);

    case 'profile_save':
        $p = ph_require(); $in = input();
        q('UPDATE pharmacies SET address = ?, city = ? WHERE id = ?', [str_in($in, 'address', 200), str_in($in, 'city', 60, true), $p['id']]);
        json_out(['ok' => true, 'pharmacy' => ph_public(ph_current())]);

    default:
        fail('unknown_action', 'Unknown action.', 404);
    }
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('pharmacy DB error: ' . $e->getMessage());
    fail('server_error', 'Something went wrong on our side. Please try again.', 500);
}
