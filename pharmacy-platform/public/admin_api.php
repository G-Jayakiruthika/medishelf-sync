<?php
/* Network administration (the AushadhBox team): approve or suspend pharmacies after checking the drug licence,
   handle complaints, see the health of the network. Two-factor sign-in is required for admins. */
declare(strict_types=1);
require dirname(__DIR__) . '/core/bootstrap.php';
foreach (['network', 'orders', 'family'] as $f) require_once dirname(__DIR__) . "/core/$f.php";
security_headers(false);
start_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') require_csrf();
$in = input(); $action = (string) ($_GET['action'] ?? '');

function admin_me(): array
{
    if (empty($_SESSION['admin_id'])) fail('not_signed_in', 'Please sign in.', 401);
    $a = q('SELECT * FROM admins WHERE id = ?', [$_SESSION['admin_id']])->fetch(); if (!$a) fail('not_signed_in', 'Please sign in.', 401);
    return $a;
}

switch ($action) {
case 'session':
    $a = !empty($_SESSION['admin_id']) ? q('SELECT id, name, email, totp_on FROM admins WHERE id = ?', [$_SESSION['admin_id']])->fetch() : null;
    json_out(['admin' => $a ?: null, 'csrf' => $_SESSION['csrf'], 'needsSetup' => !$a && !empty($_SESSION['admin_setup'])]);
case 'login':
    limit('adminlogin:' . client_ip(), 6, 15);
    $a = q('SELECT * FROM admins WHERE email = ?', [strtolower((string) ($in['email'] ?? ''))])->fetch();
    if (!$a || !password_verify((string) ($in['password'] ?? ''), $a['password_hash'])) fail('bad_login', 'E-mail or password is not correct.', 401);
    if ($a['totp_on']) {
        if (!totp_ok((string) dec($a['totp_secret']), (string) ($in['code'] ?? ''))) fail('need_code', 'Enter the 6-digit code from your authenticator app.', 401);
    } else {   // first sign-in: two-factor must be set up before anything else
        $_SESSION['admin_setup'] = (int) $a['id']; $_SESSION['admin_secret'] = $_SESSION['admin_secret'] ?? b32_secret();
        if (empty($in['code'])) json_out(['setup' => true, 'secret' => $_SESSION['admin_secret'], 'uri' => 'otpauth://totp/' . rawurlencode('AushadhBox Network:' . $a['email']) . '?secret=' . $_SESSION['admin_secret'] . '&issuer=AushadhBox']);
        if (!totp_ok($_SESSION['admin_secret'], (string) $in['code'])) fail('bad_code', 'That code is not correct.', 401);
        q('UPDATE admins SET totp_secret = ?, totp_on = 1 WHERE id = ?', [enc($_SESSION['admin_secret']), $a['id']]);
        unset($_SESSION['admin_setup'], $_SESSION['admin_secret']);
    }
    session_regenerate_id(true); $_SESSION['admin_id'] = (int) $a['id']; $_SESSION['csrf'] = bin2hex(random_bytes(32));
    audit('admin', (int) $a['id'], null, null, 'login'); json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
case 'logout': $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

case 'overview':
    admin_me();
    json_out([
        'orgs' => q('SELECT status, COUNT(*) n FROM orgs GROUP BY status')->fetchAll(),
        'branches' => (int) q("SELECT COUNT(*) FROM branches WHERE status = 'active'")->fetchColumn(),
        'byArea' => q("SELECT area, COUNT(*) n FROM branches GROUP BY area")->fetchAll(),
        'byDistrict' => q("SELECT district, COUNT(*) n FROM branches GROUP BY district ORDER BY n DESC LIMIT 15")->fetchAll(),
        'orders30' => q("SELECT source, COUNT(*) n, COALESCE(SUM(total), 0) v FROM orders WHERE created_at >= NOW() - INTERVAL 30 DAY AND status <> 'cancelled' GROUP BY source")->fetchAll(),
        'passedOn30' => (int) q("SELECT COUNT(*) FROM orders WHERE asked_branch_id IS NOT NULL AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn(),
        'links' => (int) q("SELECT COUNT(*) FROM links WHERE status = 'active'")->fetchColumn(),
        'khataTotal' => (float) q('SELECT COALESCE(SUM(balance), 0) FROM khata')->fetchColumn(),
        'payMethods30' => q("SELECT method, COUNT(*) n, SUM(amount) v FROM payments WHERE status = 'confirmed' AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY method")->fetchAll(),
        'coldAlerts' => (int) q("SELECT COUNT(*) FROM devices WHERE alert_state IN ('high','low')")->fetchColumn(),
        'complaintsOpen' => (int) q("SELECT COUNT(*) FROM complaints WHERE status = 'open'")->fetchColumn(),
        'sms30' => q("SELECT direction, status, COUNT(*) n FROM sms_log WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY direction, status")->fetchAll(),
    ]);
case 'orgs':
    admin_me();
    json_out(['orgs' => array_map(fn($o) => $o + ['branches' => q('SELECT id, code, name, licence_retail, licence_whole, district, village, area, phone, pharmacist, created_at FROM branches WHERE org_id = ?', [$o['id']])->fetchAll(),
                                                 'owner' => q("SELECT name, email, phone FROM staff WHERE org_id = ? AND role = 'owner' LIMIT 1", [$o['id']])->fetch(),
                                                 'orders30' => (int) q("SELECT COUNT(*) FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.org_id = ? AND o.created_at >= NOW() - INTERVAL 30 DAY", [$o['id']])->fetchColumn(),
                                                 'complaints' => (int) q("SELECT COUNT(*) FROM complaints c JOIN branches b ON b.id = c.branch_id WHERE b.org_id = ?", [$o['id']])->fetchColumn()],
        q("SELECT * FROM orgs ORDER BY FIELD(status, 'pending', 'approved', 'suspended'), id DESC LIMIT 300")->fetchAll())]);
case 'org_status':
    $a = admin_me(); $st = (string) ($in['status'] ?? '');
    if (!in_array($st, ['approved', 'suspended', 'pending'], true)) fail('bad_status', 'Unknown status.');
    $o = q('SELECT * FROM orgs WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch(); if (!$o) fail('not_found', 'Not found.', 404);
    if ($st === 'approved' && empty($in['licence_checked'])) fail('check_licence', 'Confirm that you checked the drug licence first.');
    q('UPDATE orgs SET status = ?, approved_at = IF(? = "approved", NOW(), approved_at) WHERE id = ?', [$st, $st, $o['id']]);
    audit('admin', (int) $a['id'], (int) $o['id'], null, 'org_' . $st, $o['name'] . (isset($in['note']) ? ': ' . mb_substr((string) $in['note'], 0, 200) : ''));
    json_out(['ok' => true]);
case 'complaints':
    admin_me();
    json_out(['complaints' => q('SELECT c.*, b.name pharmacy, b.district FROM complaints c JOIN branches b ON b.id = c.branch_id ORDER BY c.status = "open" DESC, c.id DESC LIMIT 200')->fetchAll()]);
case 'complaint_close':
    $a = admin_me(); q("UPDATE complaints SET status = 'closed', reply = COALESCE(reply, ?) WHERE id = ?", [str_in($in, 'note', 1000), (int) ($in['id'] ?? 0)]);
    audit('admin', (int) $a['id'], null, null, 'complaint_close', '#' . (int) ($in['id'] ?? 0)); json_out(['ok' => true]);
case 'audit':
    admin_me(); json_out(['log' => q('SELECT * FROM audit_log ORDER BY id DESC LIMIT 300')->fetchAll()]);
default: fail('unknown_action', 'Unknown action.', 404);
}
