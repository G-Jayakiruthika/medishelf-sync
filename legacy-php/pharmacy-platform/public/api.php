<?php
/* AushadhBox Pharmacy Network: API for the pharmacy portal (/app) and the rider app (/rider).
   api.php?action=<name>. Changes use POST with the X-CSRF-Token header. Every action checks the person's role. */
declare(strict_types=1);
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/migrate.php';
try { ph_migrate_if_due(); } catch (Throwable $e) { error_log('migrate: ' . $e->getMessage()); }
foreach (['compliance', 'stock', 'network', 'orders', 'delivery', 'sms', 'devices', 'family', 'auth'] as $f) require_once dirname(__DIR__) . "/core/$f.php";

security_headers();
start_session();
$action = (string) ($_GET['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') require_csrf();
$in = input();

/* Small helpers for this file */
function my_order(array $b, int $id): array { $o = order($id); if ((int) $o['branch_id'] !== (int) $b['id']) fail('not_found', 'Order not found.', 404); return $o; }
function my_customer(int $id): array { $c = q('SELECT * FROM customers WHERE id = ?', [$id])->fetch(); if (!$c) fail('not_found', 'Customer not found.', 404); return $c; }
function branch_settings(array $b): array
{
    return branch_public($b) + ['id' => (int) $b['id'], 'licenceRetail' => $b['licence_retail'], 'licenceWhole' => $b['licence_whole'], 'pharmacist' => $b['pharmacist'], 'rxEmail' => $b['rx_email'],
        'upiVpa' => $b['upi_vpa'], 'upiName' => $b['upi_name'], 'acceptsCash' => (bool) $b['accepts_cash'], 'khataOn' => (bool) $b['khata_on'], 'referralAmt' => (float) $b['referral_amt'],
        'deliveryOn' => (bool) $b['delivery_on'], 'maxKm' => (float) $b['max_km'], 'feeBase' => (float) $b['fee_base'], 'feePerKm' => (float) $b['fee_per_km'], 'freeAbove' => (float) $b['free_above'],
        'costPerKm' => (float) $b['cost_per_km'], 'storeUrl' => base_url() . '/s/' . $b['code'], 'posterUrl' => base_url() . '/poster/' . $b['code']];
}

try {
switch ($action) {
/* ---------------- Sign-in ---------------- */
case 'session':
    $s = me();
    if (!$s) json_out(['staff' => null, 'csrf' => $_SESSION['csrf'], 'twoFactorPending' => !empty($_SESSION['pending_2fa'])]);
    $b = q('SELECT COUNT(*) FROM branches WHERE org_id = ?', [$s['org_id']])->fetchColumn() ? my_branch($s) : null;
    json_out(['staff' => staff_public($s), 'csrf' => $_SESSION['csrf'], 'org' => ['id' => (int) $s['org_id'], 'name' => $s['org_name'], 'status' => $s['org_status']],
              'branch' => $b ? branch_settings($b) : null, 'can' => array_values(array_filter(['orders', 'stock', 'network', 'customers', 'khata', 'payments', 'delivery', 'staff', 'reports', 'devices', 'card', 'rides', 'settings'], fn($w) => can($s, $w) || ($w === 'settings' && $s['role'] === 'owner'))),
              'branches' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'code' => $r['code']], $s['branch_id'] ? [] : q('SELECT id, name, code FROM branches WHERE org_id = ? ORDER BY id', [$s['org_id']])->fetchAll())]);

case 'signup':   // a new pharmacy: business + first shop + owner. Waits for approval (licence check).
    limit('signup:' . client_ip(), 5, 60);
    if (!empty($in['website'])) { audit('system', null, null, null, 'bot_signup', client_ip()); fail('bot', 'Please try again.', 400); }   // hidden field: only bots fill it
    $name = str_in($in, 'name', 120, true); $lic = str_in($in, 'licence_retail', 60, true); $district = str_in($in, 'district', 60, true);
    $owner = str_in($in, 'owner_name', 80, true); $email = strtolower((string) str_in($in, 'email', 160, true)); $phone = phone_norm($in['phone'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email', 'Enter a valid e-mail address.');
    if (!$phone) fail('bad_phone', 'Enter a 10-digit mobile number.');
    password_check((string) ($in['password'] ?? ''));
    if (empty($in['agree'])) fail('no_agree', 'Please accept the pharmacy terms.');
    if (q('SELECT 1 FROM staff WHERE email = ? OR phone = ?', [$email, $phone])->fetch()) fail('exists', 'An account with this e-mail or phone already exists. Sign in instead.', 409);
    $area = in_array($in['area'] ?? '', ['urban', 'semiurban', 'rural'], true) ? $in['area'] : 'semiurban';
    $sid = tx(function () use ($in, $name, $lic, $district, $owner, $email, $phone, $area) {
        q('INSERT INTO orgs (name, gstin) VALUES (?,?)', [$name, str_in($in, 'gstin', 15)]);
        $org = (int) db()->lastInsertId();
        do { $code = code(8); } while (q('SELECT 1 FROM branches WHERE code = ?', [$code])->fetch());
        q('INSERT INTO branches (org_id, code, name, licence_retail, district, state, village, address, pincode, lat, lng, area, phone, upi_vpa, kind) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$org, $code, $name, $lic, $district, str_in($in, 'state', 40) ?? 'Tamil Nadu', str_in($in, 'village', 80), str_in($in, 'address', 220), preg_match('/^\d{6}$/', (string) ($in['pincode'] ?? '')) ? $in['pincode'] : null,
           is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null, $area, $phone, str_in($in, 'upi_vpa', 80),
           ($in['kind'] ?? '') === 'janaushadhi' ? 'janaushadhi' : 'retail']);
        q('INSERT INTO staff (org_id, name, phone, email, password_hash, role) VALUES (?,?,?,?,?,?)', [$org, $owner, $phone, $email, pw_hash((string) $in['password']), 'owner']);
        $sid = (int) db()->lastInsertId();
        audit('staff', $sid, $org, null, 'signup', "$name, $district, licence $lic");
        return $sid;
    });
    json_out(login_finish(q('SELECT * FROM staff WHERE id = ?', [$sid])->fetch()));

case 'login':      json_out(login_start((string) ($in['id'] ?? ''), (string) ($in['password'] ?? '')));
case 'login_2fa':  json_out(login_2fa((string) ($in['code'] ?? '')));
case 'logout':
    if ($s = me()) audit('staff', (int) $s['id'], (int) $s['org_id'], null, 'logout');
    $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32));
    json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

case 'totp_setup':   // shows a secret to add to an authenticator app; turned on only after a correct code
    $s = need_me(); $secret = b32_secret(); $_SESSION['totp_new'] = $secret;
    json_out(['secret' => $secret, 'uri' => 'otpauth://totp/' . rawurlencode('AushadhBox Pharmacy:' . ($s['email'] ?: $s['phone'])) . '?secret=' . $secret . '&issuer=AushadhBox%20Pharmacy']);
case 'totp_enable':
    $s = need_me(); $secret = (string) ($_SESSION['totp_new'] ?? '');
    if (!$secret || !totp_ok($secret, (string) ($in['code'] ?? ''))) fail('bad_code', 'That code is not correct. Check the time on your phone.');
    q('UPDATE staff SET totp_secret = ?, totp_on = 1 WHERE id = ?', [enc($secret), $s['id']]); unset($_SESSION['totp_new']);
    audit('staff', (int) $s['id'], (int) $s['org_id'], null, '2fa_on'); json_out(['ok' => true]);
case 'totp_disable':
    $s = need_me();
    if (!password_verify((string) ($in['password'] ?? ''), $s['password_hash'])) fail('bad_password', 'Password is not correct.', 403);
    q('UPDATE staff SET totp_secret = NULL, totp_on = 0 WHERE id = ?', [$s['id']]); audit('staff', (int) $s['id'], (int) $s['org_id'], null, '2fa_off'); json_out(['ok' => true]);
case 'password_change':
    $s = need_me();
    if (!password_verify((string) ($in['current'] ?? ''), $s['password_hash'])) fail('bad_password', 'Your current password is not correct.', 403);
    password_check((string) ($in['new'] ?? ''));
    q('UPDATE staff SET password_hash = ? WHERE id = ?', [pw_hash((string) $in['new']), $s['id']]); audit('staff', (int) $s['id'], (int) $s['org_id'], null, 'password_change'); json_out(['ok' => true]);
case 'branch_switch':
    $s = need_me(); if ($s['branch_id']) fail('forbidden', 'You work at one branch only.', 403);
    $_SESSION['branch_id'] = (int) ($in['id'] ?? 0); json_out(['ok' => true, 'branch' => branch_settings(my_branch($s))]);

/* ---------------- Dashboard ---------------- */
case 'dashboard':
    $s = need('orders'); $b = my_branch($s); $id = (int) $b['id'];
    $today = q("SELECT COUNT(*) n, COALESCE(SUM(total), 0) v FROM orders WHERE branch_id = ? AND DATE(created_at) = CURDATE() AND status <> 'cancelled'", [$id])->fetch();
    json_out([
        'ordersToday' => (int) $today['n'], 'salesToday' => (float) $today['v'],
        'open' => (int) q("SELECT COUNT(*) FROM orders WHERE branch_id = ? AND status IN ('new','accepted','packed','ready','out')", [$id])->fetchColumn(),
        'newOrders' => (int) q("SELECT COUNT(*) FROM orders WHERE branch_id = ? AND status = 'new'", [$id])->fetchColumn(),
        'claims' => (int) q("SELECT COUNT(*) FROM payments WHERE branch_id = ? AND status = 'claimed'", [$id])->fetchColumn(),
        'khataTotal' => (float) q('SELECT COALESCE(SUM(balance), 0) FROM khata WHERE branch_id = ?', [$id])->fetchColumn(),
        'expiring' => (int) q('SELECT COUNT(*) FROM stock WHERE branch_id = ? AND qty > 0 AND exp BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY', [$id])->fetchColumn(),
        'expired' => (int) q('SELECT COUNT(*) FROM stock WHERE branch_id = ? AND qty > 0 AND exp < CURDATE()', [$id])->fetchColumn(),
        'linkRequests' => (int) q("SELECT COUNT(*) FROM links WHERE to_branch = ? AND status = 'pending'", [$id])->fetchColumn(),
        'transfers' => (int) q("SELECT COUNT(*) FROM transfers WHERE from_branch = ? AND status = 'asked'", [$id])->fetchColumn(),
        'networkEarned30' => (float) q("SELECT COALESCE(SUM(o.paid * l.referral_pct / 100), 0) FROM orders o JOIN links l ON ((l.from_branch = o.asked_branch_id AND l.to_branch = o.branch_id) OR (l.to_branch = o.asked_branch_id AND l.from_branch = o.branch_id))
                                         WHERE o.asked_branch_id = ? AND o.created_at >= NOW() - INTERVAL 30 DAY AND o.status IN ('delivered','collected')", [$id])->fetchColumn(),
        'devices' => q('SELECT id, name, last_c temp, alert_state state, last_seen FROM devices WHERE branch_id = ?', [$id])->fetchAll(),
        'insights' => q("SELECT id, kind, title, data, created_at FROM insights WHERE branch_id = ? AND status = 'open' ORDER BY id DESC LIMIT 20", [$id])->fetchAll(),
        'complaints' => (int) q("SELECT COUNT(*) FROM complaints WHERE branch_id = ? AND status = 'open'", [$id])->fetchColumn(),
        'week' => q("SELECT DATE(created_at) d, COUNT(*) n, COALESCE(SUM(total), 0) v FROM orders WHERE branch_id = ? AND created_at >= CURDATE() - INTERVAL 13 DAY AND status <> 'cancelled' GROUP BY DATE(created_at) ORDER BY d", [$id])->fetchAll(),
        'bySource' => q("SELECT source, COUNT(*) n FROM orders WHERE branch_id = ? AND created_at >= CURDATE() - INTERVAL 30 DAY GROUP BY source", [$id])->fetchAll(),
    ]);
case 'insight_done':
    $s = need('orders'); $b = my_branch($s);
    q("UPDATE insights SET status = ? WHERE id = ? AND branch_id = ?", [($in['dismiss'] ?? false) ? 'dismissed' : 'done', (int) ($in['id'] ?? 0), $b['id']]); json_out(['ok' => true]);

/* ---------------- Settings: shop profile, payments, delivery rules ---------------- */
case 'branch_save':
    $s = need('staff'); $b = my_branch($s);
    $vpa = str_in($in, 'upi_vpa', 80);
    if ($vpa === null && ($p = phone_norm($in['upi_phone'] ?? ''))) $vpa = $p . '@upi';   // no QR? the phone number works as a UPI ID in most apps
    if ($vpa !== null && !preg_match('/^[a-zA-Z0-9._\-]{2,256}@[a-zA-Z][a-zA-Z0-9.\-]{1,64}$/', $vpa)) fail('bad_upi', 'A UPI ID looks like name@bank or 9876543210@upi.');
    $hours = str_in($in, 'hours', 200);
    q('UPDATE branches SET name = ?, address = ?, village = ?, district = ?, pincode = ?, lat = ?, lng = ?, area = ?, phone = ?, hours = ?, pharmacist = ?, rx_email = ?, licence_whole = ?,
       upi_vpa = ?, upi_name = ?, accepts_cash = ?, khata_on = ?, loyalty_pct = ?, referral_amt = ?, delivery_on = ?, radius_km = ?, max_km = ?, fee_base = ?, fee_per_km = ?, free_above = ?, cost_per_km = ? WHERE id = ?',
      [str_in($in, 'name', 120, true), str_in($in, 'address', 220), str_in($in, 'village', 80), str_in($in, 'district', 60, true), preg_match('/^\d{6}$/', (string) ($in['pincode'] ?? '')) ? $in['pincode'] : null,
       is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : $b['lat'], is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : $b['lng'],
       in_array($in['area'] ?? '', ['urban', 'semiurban', 'rural'], true) ? $in['area'] : $b['area'], phone_norm($in['phone'] ?? '') ?: $b['phone'], $hours, str_in($in, 'pharmacist', 80),
       str_in($in, 'rx_email', 160), str_in($in, 'licence_whole', 60), $vpa, str_in($in, 'upi_name', 80), !empty($in['accepts_cash']) ? 1 : 0, !empty($in['khata_on']) ? 1 : 0,
       num_in($in, 'loyalty_pct', 0, 10, 1), num_in($in, 'referral_amt', 0, 500, 20), !empty($in['delivery_on']) ? 1 : 0, num_in($in, 'radius_km', 0.5, 50, 5), num_in($in, 'max_km', 1, 150, 25),
       num_in($in, 'fee_base', 0, 500, 20), num_in($in, 'fee_per_km', 0, 100, 8), num_in($in, 'free_above', 0, 100000, 500), num_in($in, 'cost_per_km', 0, 100, 6), $b['id']]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'branch_save');
    json_out(['ok' => true, 'branch' => branch_settings(my_branch($s))]);
case 'branch_add':   // a second shop of the same business (gets its own QR and licence)
    $s = need('staff'); if ($s['role'] !== 'owner') fail('forbidden', 'Only the owner can add a shop.', 403);
    do { $code = code(8); } while (q('SELECT 1 FROM branches WHERE code = ?', [$code])->fetch());
    q('INSERT INTO branches (org_id, code, name, licence_retail, district, village, address, phone, area) VALUES (?,?,?,?,?,?,?,?,?)',
      [$s['org_id'], $code, str_in($in, 'name', 120, true), str_in($in, 'licence_retail', 60, true), str_in($in, 'district', 60, true), str_in($in, 'village', 80), str_in($in, 'address', 220),
       phone_norm($in['phone'] ?? '') ?: fail('bad_phone', 'Enter the shop\'s 10-digit number.'), in_array($in['area'] ?? '', ['urban', 'semiurban', 'rural'], true) ? $in['area'] : 'semiurban']);
    $_SESSION['branch_id'] = (int) db()->lastInsertId(); audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $_SESSION['branch_id'], 'branch_add');
    json_out(['ok' => true, 'branch' => branch_settings(my_branch($s))]);

/* ---------------- Staff ---------------- */
case 'staff_list':
    $s = need('staff'); json_out(['staff' => array_map('staff_public', q('SELECT * FROM staff WHERE org_id = ? ORDER BY FIELD(role, "owner","manager","pharmacist","cashier","rider"), name', [$s['org_id']])->fetchAll())]);
case 'staff_add':
    $s = need('staff');
    $role = in_array($in['role'] ?? '', ['manager', 'pharmacist', 'cashier', 'rider'], true) ? $in['role'] : fail('bad_role', 'Choose a role.');
    if ($role === 'manager' && $s['role'] !== 'owner') fail('forbidden', 'Only the owner can add managers.', 403);
    $phone = phone_norm($in['phone'] ?? ''); $email = strtolower((string) (str_in($in, 'email', 160) ?? '')) ?: null;
    if (!$phone && !$email) fail('need_contact', 'Enter a phone number or e-mail to sign in with.');
    if (q('SELECT 1 FROM staff WHERE (? IS NOT NULL AND email = ?) OR (? <> "" AND phone = ?)', [$email, $email, $phone, $phone])->fetch()) fail('exists', 'Someone already signs in with this phone or e-mail.', 409);
    $tmp = code(4) . '-' . code(4);   // first password, shown once; they change it after signing in
    q('INSERT INTO staff (org_id, branch_id, name, phone, email, password_hash, role) VALUES (?,?,?,?,?,?,?)',
      [$s['org_id'], !empty($in['branch_id']) ? (int) $in['branch_id'] : ($s['branch_id'] ?: null), str_in($in, 'name', 80, true), $phone ?: null, $email, pw_hash($tmp), $role]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], null, 'staff_add', "$role " . ($in['name'] ?? ''));
    json_out(['ok' => true, 'password' => $tmp]);
case 'staff_update':
    $s = need('staff'); $t = q('SELECT * FROM staff WHERE id = ? AND org_id = ?', [(int) ($in['id'] ?? 0), $s['org_id']])->fetch();
    if (!$t || $t['role'] === 'owner') fail('not_found', 'Not found (the owner cannot be changed here).', 404);
    if ($t['role'] === 'manager' && $s['role'] !== 'owner') fail('forbidden', 'Only the owner can change managers.', 403);
    $status = ($in['status'] ?? '') === 'blocked' ? 'blocked' : 'active';
    $role = in_array($in['role'] ?? '', ['manager', 'pharmacist', 'cashier', 'rider'], true) && !($in['role'] === 'manager' && $s['role'] !== 'owner') ? $in['role'] : $t['role'];
    $pw = null; if (!empty($in['reset'])) { $pw = code(4) . '-' . code(4); q('UPDATE staff SET password_hash = ?, totp_on = 0, totp_secret = NULL WHERE id = ?', [pw_hash($pw), $t['id']]); }
    q('UPDATE staff SET role = ?, status = ? WHERE id = ?', [$role, $status, $t['id']]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], null, 'staff_update', $t['name'] . " → $role/$status" . ($pw ? ' (password reset)' : ''));
    json_out(['ok' => true, 'password' => $pw]);

/* ---------------- Stock ---------------- */
case 'stock_list':
    $s = need('orders'); $b = my_branch($s);
    json_out(['stock' => stock_list((int) $b['id'], trim((string) ($_GET['q'] ?? '')), (string) ($_GET['filter'] ?? 'all')),
              'value' => (float) q('SELECT COALESCE(SUM(s.qty * COALESCE(s.price, p.mrp, 0)), 0) FROM stock s JOIN products p ON p.id = s.product_id WHERE s.branch_id = ?', [$b['id']])->fetchColumn()]);
case 'stock_add':   // received stock: a new batch or more of an existing one
    $s = need('stock'); $b = my_branch($s);
    $mrp = num_in($in, 'mrp', 0, 100000); $price = num_in($in, 'price', 0, 100000);
    if (!price_ok($price, $mrp)) fail('above_mrp', 'The selling price cannot be above the MRP.');
    $pid = product_upsert((int) $s['org_id'], $in);
    $exp = exp_from((string) ($in['exp'] ?? '')); if (!$exp) fail('bad_exp', 'Enter the expiry (MM/YY).');
    if ($exp < date('Y-m-d')) fail('expired', 'This batch has already expired. Do not stock it.');
    stock_move((int) $b['id'], $pid, (string) ($in['batch'] ?? ''), (int) num_in($in, 'qty', 1, 100000), 'purchase', str_in($in, 'invoice', 60), (int) $s['id'], $exp, $price ?? $mrp);
    json_out(['ok' => true, 'productId' => $pid]);
case 'stock_count':   // set the counted quantity of a batch (stock taking)
    $s = need('stock'); $b = my_branch($s);
    $row = q('SELECT * FROM stock WHERE id = ? AND branch_id = ?', [(int) ($in['stock_id'] ?? 0), $b['id']])->fetch(); if (!$row) fail('not_found', 'Not found.', 404);
    $price = num_in($in, 'price', 0, 100000);
    $mrp = q('SELECT mrp FROM products WHERE id = ?', [$row['product_id']])->fetchColumn();
    if (!price_ok($price, $mrp !== null && $mrp !== false ? (float) $mrp : null)) fail('above_mrp', 'The selling price cannot be above the MRP.');
    stock_set((int) $b['id'], (int) $row['product_id'], $row['batch'], (int) num_in($in, 'qty', 0, 100000, (float) $row['qty']), null, $price, 'count', (int) $s['id']);
    json_out(['ok' => true]);
case 'stock_expire':   // remove expired / damaged stock (for return to the distributor or safe disposal)
    $s = need('stock'); $b = my_branch($s);
    $row = q('SELECT * FROM stock WHERE id = ? AND branch_id = ?', [(int) ($in['stock_id'] ?? 0), $b['id']])->fetch(); if (!$row) fail('not_found', 'Not found.', 404);
    stock_move((int) $b['id'], (int) $row['product_id'], $row['batch'], -(int) $row['qty'], ($in['why'] ?? '') === 'damaged' ? 'damaged' : 'expire', str_in($in, 'note', 60), (int) $s['id']);
    json_out(['ok' => true]);
case 'stock_import':
    $s = need('stock'); $b = my_branch($s);
    $csv = (string) ($in['csv'] ?? ''); if (strlen($csv) > 6 * 1024 * 1024) fail('too_big', 'The file is larger than 6 MB. Export only the stock columns.');
    $r = stock_import_csv((int) $s['org_id'], (int) $b['id'], $csv, (int) $s['id'], !empty($in['full']));
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'stock_import', json_encode($r)); json_out(['ok' => true] + $r);
case 'product_search':
    $s = need('orders');
    $qq = trim((string) ($_GET['q'] ?? '')); if (mb_strlen($qq) < 2) json_out(['products' => []]);
    $b = my_branch($s);
    json_out(['products' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'generic' => $p['generic'], 'schedule' => $p['schedule'], 'mrp' => $p['mrp'] !== null ? (float) $p['mrp'] : null,
        'available' => stock_available((int) $b['id'], (int) $p['id']), 'cold' => (bool) $p['cold']],
        q('SELECT * FROM products WHERE org_id = ? AND (search LIKE ? OR barcode = ?) ORDER BY name LIMIT 15', [$s['org_id'], '%' . mb_strtolower($qq) . '%', $qq])->fetchAll())]);
case 'sync_tokens':
    $s = need('stock'); $b = my_branch($s);
    json_out(['tokens' => q('SELECT id, name, last_used, last_rows, created_at FROM sync_tokens WHERE branch_id = ?', [$b['id']])->fetchAll()]);
case 'sync_token_new':   // for the Java sync agent on the shop's billing computer
    $s = need('stock'); $b = my_branch($s); $tok = 'abs_' . bin2hex(random_bytes(24));
    q('INSERT INTO sync_tokens (branch_id, name, token_hash) VALUES (?,?,?)', [$b['id'], str_in($in, 'name', 60) ?? 'Billing computer', hash('sha256', $tok)]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'sync_token_new'); json_out(['ok' => true, 'token' => $tok, 'url' => base_url() . '/sync.php']);
case 'sync_token_revoke':
    $s = need('stock'); $b = my_branch($s); q('DELETE FROM sync_tokens WHERE id = ? AND branch_id = ?', [(int) ($in['id'] ?? 0), $b['id']]); json_out(['ok' => true]);

/* ---------------- Network: linked pharmacies, find a medicine, pass an order on, move stock ---------------- */
case 'network':
    $s = need('network'); $b = my_branch($s); $id = (int) $b['id'];
    $rows = q('SELECT l.*, IF(l.from_branch = ?, l.to_branch, l.from_branch) other FROM links l WHERE l.from_branch = ? OR l.to_branch = ? ORDER BY l.status, l.id DESC', [$id, $id, $id])->fetchAll();
    json_out(['links' => array_map(function ($l) use ($id, $b) {
        $o = branch((int) $l['other']);
        return ['id' => (int) $l['id'], 'status' => $l['status'], 'incoming' => (int) $l['to_branch'] === $id, 'shareStock' => (bool) $l['share_stock'], 'referralPct' => (float) $l['referral_pct'],
                'pharmacy' => ['code' => $o['code'], 'name' => $o['name'], 'village' => $o['village'], 'district' => $o['district'], 'phone' => $l['status'] === 'active' ? $o['phone'] : null,
                               'distanceKm' => $b['lat'] !== null && $o['lat'] !== null ? road_km(km((float) $b['lat'], (float) $b['lng'], (float) $o['lat'], (float) $o['lng']), $b['area']) : null,
                               'wholesale' => (bool) $o['licence_whole']]];
    }, $rows),
    'transfers' => array_map(fn($t) => ['id' => (int) $t['id'], 'number' => $t['number'], 'from' => branch((int) $t['from_branch'])['name'], 'to' => branch((int) $t['to_branch'])['name'],
        'outgoing' => (int) $t['from_branch'] === $id, 'items' => json_decode($t['items'], true), 'value' => (float) $t['value'], 'reason' => $t['reason'], 'status' => $t['status'], 'at' => $t['created_at']],
        q('SELECT * FROM transfers WHERE from_branch = ? OR to_branch = ? ORDER BY id DESC LIMIT 40', [$id, $id])->fetchAll()),
    'nearby' => array_map(fn($r) => branch_public($r, $b['lat'] !== null ? (float) $b['lat'] : null, $b['lng'] !== null ? (float) $b['lng'] : null),
        $b['lat'] !== null ? q("SELECT b.* FROM branches b JOIN orgs o ON o.id = b.org_id WHERE o.status = 'approved' AND b.status = 'active' AND b.id <> ? AND b.lat BETWEEN ? AND ? AND b.lng BETWEEN ? AND ? LIMIT 30",
                               [$id, $b['lat'] - 0.25, $b['lat'] + 0.25, $b['lng'] - 0.25, $b['lng'] + 0.25])->fetchAll() : [])]);
case 'link_request':
    $s = need('network'); $b = my_branch($s);
    $o = branch_by_code((string) ($in['code'] ?? '')); if (!$o || $o['org_status'] !== 'approved') fail('not_found', 'No approved pharmacy has that code (it is on their QR poster).', 404);
    if ((int) $o['org_id'] === (int) $s['org_id']) fail('same', 'Shops of your own business are already linked.');
    if (q("SELECT 1 FROM links WHERE (from_branch = ? AND to_branch = ?) OR (from_branch = ? AND to_branch = ?)", [$b['id'], $o['id'], $o['id'], $b['id']])->fetch()) fail('exists', 'There is already a link or request with this pharmacy.', 409);
    q('INSERT INTO links (from_branch, to_branch, share_stock, referral_pct) VALUES (?,?,?,?)', [$b['id'], $o['id'], !empty($in['share_stock']) ? 1 : 0, num_in($in, 'referral_pct', 0, 10, 2)]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'link_request', $o['name']); json_out(['ok' => true]);
case 'link_respond':
    $s = need('network'); $b = my_branch($s);
    $l = q('SELECT * FROM links WHERE id = ? AND (to_branch = ? OR from_branch = ?)', [(int) ($in['id'] ?? 0), $b['id'], $b['id']])->fetch(); if (!$l) fail('not_found', 'Not found.', 404);
    $to = ($in['answer'] ?? '') === 'accept' ? 'active' : (($in['answer'] ?? '') === 'end' ? 'ended' : 'declined');
    if ($to === 'active' && ((int) $l['to_branch'] !== (int) $b['id'] || $l['status'] !== 'pending')) fail('forbidden', 'Only the pharmacy that was asked can accept.', 403);
    q('UPDATE links SET status = ?, responded_at = NOW() WHERE id = ?', [$to, $l['id']]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'link_' . $to, '#' . $l['id']); json_out(['ok' => true]);
case 'network_find':
    $s = need('network'); $b = my_branch($s);
    json_out(['results' => network_find($b, str_in($in, 'name', 160, true), (int) num_in($in, 'qty', 1, 999, 1))]);
case 'order_pass':   // pass missing items (or the whole order) to a linked pharmacy: it sells and delivers; we get the referral share
    $s = need('network'); $b = my_branch($s); $o = my_order($b, (int) ($in['order_id'] ?? 0));
    $to = branch((int) ($in['to_branch'] ?? 0));
    if (!link_between((int) $b['id'], (int) $to['id']) && (int) $to['org_id'] !== (int) $b['org_id']) fail('not_linked', 'Link with this pharmacy first (Network → Link).', 403);
    $ids = array_map('intval', (array) ($in['item_ids'] ?? []));
    $items = q('SELECT * FROM order_items WHERE order_id = ?', [$o['id']])->fetchAll();
    $pass = array_values(array_filter($items, fn($i) => !$ids || in_array((int) $i['id'], $ids, true)));
    if (!$pass) fail('empty', 'Choose what to pass on.');
    $new = order_create($to, $o['customer_id'] ? (int) $o['customer_id'] : null, ['items' => array_map(fn($i) => ['name' => $i['name'], 'qty' => (int) $i['qty']], $pass),
        'kind' => $o['kind'] === 'point' ? 'pickup' : $o['kind'], 'source' => $o['source'], 'asked_branch_id' => (int) $b['id'], 'rx_date' => $o['rx_date'],
        'note' => 'Passed on by ' . $b['name'] . ' (order ' . $o['number'] . ')', 'override' => $in['override'] ?? null, 'customer_pays_full' => !empty($in['customer_pays_full'])], (int) $s['id']);
    foreach ($pass as $i) q("UPDATE order_items SET status = 'removed' WHERE id = ?", [$i['id']]);
    $left = (float) q("SELECT COALESCE(SUM(price * qty), 0) FROM order_items WHERE order_id = ? AND status = 'ok'", [$o['id']])->fetchColumn();
    q('UPDATE orders SET subtotal = ?, total = ? + delivery_fee - discount WHERE id = ?', [$left, $left, $o['id']]);
    if (!q("SELECT 1 FROM order_items WHERE order_id = ? AND status IN ('ok','missing')", [$o['id']])->fetch()) order_set_status(order((int) $o['id']), 'cancelled', (int) $s['id'], ['reason' => 'passed on to ' . $to['name']]);
    if ($o['customer_id']) { $c = my_customer((int) $o['customer_id']); family_notify($c['customer_ref'], 'Your medicines come from ' . $to['name'], $b['name'] . ' did not have everything, so ' . $to['name'] . ' will ' . ($o['kind'] === 'delivery' ? 'deliver' : 'keep') . ' them. Order ' . $new['number'] . '.', '/app#card', ['number' => $new['number']]);
        if (!$c['customer_ref'] && $c['sms_ok'] && ($ph = dec($c['phone_enc']))) sms_send($ph, 'order_new', [$new['number'], $to['name'], number_format($new['total'], 0)], (int) $to['id']); }
    json_out(['ok' => true, 'order' => $new]);
case 'transfer_ask':   // ask a branch of your business (or a wholesale-licensed partner) to send stock
    $s = need('network'); $b = my_branch($s); $from = branch((int) ($in['from_branch'] ?? 0));
    $ok = transfer_allowed($from, $b); if (!$ok['ok']) fail('not_allowed', $ok['why'], 422);
    if ((int) $from['org_id'] !== (int) $b['org_id'] && !link_between((int) $b['id'], (int) $from['id'])) fail('not_linked', 'Link with this pharmacy first.', 403);
    $items = array_slice(array_values(array_filter((array) ($in['items'] ?? []), fn($i) => is_array($i) && mb_strlen(trim((string) ($i['name'] ?? ''))) >= 2)), 0, 20);
    if (!$items) fail('empty', 'Add what you need.');
    $number = 'TR-' . random_int(10000, 99999);
    q('INSERT INTO transfers (number, from_branch, to_branch, order_id, items, reason) VALUES (?,?,?,?,?,?)',
      [$number, $from['id'], $b['id'], !empty($in['order_id']) ? (int) $in['order_id'] : null, json_encode(array_map(fn($i) => ['name' => mb_substr($i['name'], 0, 120), 'qty' => max(1, (int) ($i['qty'] ?? 1))], $items), JSON_UNESCAPED_UNICODE),
       in_array($in['reason'] ?? '', ['shortage', 'expiry', 'rebalance'], true) ? $in['reason'] : 'shortage']);
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'transfer_ask', "$number from " . $from['name']); json_out(['ok' => true, 'number' => $number]);
case 'transfer_step':   // sender: send (takes stock, first-expiring first) or decline; receiver: received (adds stock)
    $s = need('network'); $b = my_branch($s);
    $t = q('SELECT * FROM transfers WHERE id = ? AND (from_branch = ? OR to_branch = ?)', [(int) ($in['id'] ?? 0), $b['id'], $b['id']])->fetch(); if (!$t) fail('not_found', 'Not found.', 404);
    $step = (string) ($in['step'] ?? ''); $items = json_decode($t['items'], true) ?: [];
    if (in_array($step, ['send', 'decline'], true) && ((int) $t['from_branch'] !== (int) $b['id'] || $t['status'] !== 'asked')) fail('forbidden', 'Only the sending shop can do this now.', 403);
    if ($step === 'received' && ((int) $t['to_branch'] !== (int) $b['id'] || $t['status'] !== 'sent')) fail('forbidden', 'Only the receiving shop can confirm, after it was sent.', 403);
    if ($step === 'send') {
        $value = 0; $sent = [];
        tx(function () use (&$items, &$value, &$sent, $b, $t, $s) {
            foreach ($items as $it) {
                $p = product_match((int) $b['org_id'], $it['name']); if (!$p) throw new RuntimeException('short:' . $it['name']);
                $r = stock_take((int) $b['id'], (int) $p['id'], (int) $it['qty'], $t['number'], (int) $s['id']);
                if ($r['short'] > 0) throw new RuntimeException('short:' . $it['name']);
                foreach ($r['batches'] as $bt) { $sent[] = ['name' => $p['name'], 'generic' => $p['generic'], 'schedule' => $p['schedule'], 'mrp' => $p['mrp'], 'batch' => $bt['batch'], 'exp' => $bt['exp'], 'qty' => $bt['qty'], 'price' => $bt['price']]; $value += ($bt['price'] ?? 0) * $bt['qty']; }
            }
            q("UPDATE transfers SET status = 'sent', items = ?, value = ? WHERE id = ?", [json_encode($sent, JSON_UNESCAPED_UNICODE), $value, $t['id']]);
        });
    } elseif ($step === 'received') {
        $to = branch((int) $t['to_branch']);
        tx(function () use ($items, $to, $t, $s) { foreach ($items as $it) { $pid = product_upsert((int) $to['org_id'], ['name' => $it['name'], 'generic' => $it['generic'] ?? null, 'schedule' => $it['schedule'] ?? null, 'mrp' => $it['mrp'] ?? null]);
            stock_move((int) $to['id'], $pid, (string) ($it['batch'] ?? ''), (int) $it['qty'], 'transfer', $t['number'], (int) $s['id'], $it['exp'] ?? null, isset($it['price']) ? (float) $it['price'] : null); }
            q("UPDATE transfers SET status = 'received' WHERE id = ?", [$t['id']]); });
    } elseif ($step === 'decline') q("UPDATE transfers SET status = 'declined' WHERE id = ?", [$t['id']]);
    else fail('bad_step', 'Unknown step.');
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'transfer_' . $step, $t['number']); json_out(['ok' => true]);

/* ---------------- Orders ---------------- */
case 'orders':
    $s = need('orders'); $b = my_branch($s); $f = (string) ($_GET['filter'] ?? 'open');
    $where = ['open' => "status IN ('new','accepted','packed','ready','out')", 'new' => "status = 'new'", 'done' => "status IN ('delivered','collected')", 'cancelled' => "status = 'cancelled'", 'all' => '1'][$f] ?? '1';
    json_out(['orders' => array_map(fn($o) => order_public($o, true), q("SELECT * FROM orders WHERE branch_id = ? AND $where ORDER BY FIELD(status, 'new','accepted','packed','ready','out','delivered','collected','cancelled'), id DESC LIMIT 80", [$b['id']])->fetchAll())]);
case 'order_get':
    $s = need('orders'); $b = my_branch($s); $o = my_order($b, (int) ($_GET['id'] ?? 0));
    json_out(['order' => order_public($o, true), 'payments' => q('SELECT id, method, amount, utr, status, by_whom, created_at FROM payments WHERE order_id = ? ORDER BY id', [$o['id']])->fetchAll()]);
case 'order_new':   // counter, phone or ASHA orders typed by staff
    $s = need('orders'); $b = my_branch($s);
    $cid = !empty($in['customer_id']) ? (int) my_customer((int) $in['customer_id'])['id'] : (!empty($in['customer']) ? customer_upsert((array) $in['customer']) : null);
    $o = order_create($b, $cid, $in + ['source' => 'walkin'], (int) $s['id']);
    json_out(['ok' => true, 'order' => $o]);
case 'order_status':
    $s = need('orders'); $b = my_branch($s); $o = my_order($b, (int) ($in['id'] ?? 0)); $to = (string) ($in['status'] ?? '');
    $o = order_set_status($o, $to, (int) $s['id'], ['reason' => str_in($in, 'reason', 120)]);
    // Tell the customer: app notification for app customers, SMS for walk-ins who agreed to SMS.
    if ($o['customer_id'] && in_array($to, ['accepted', 'ready', 'out', 'cancelled'], true)) {
        $c = my_customer((int) $o['customer_id']);
        $msg = ['accepted' => 'Your order ' . $o['number'] . ' was accepted by ' . $b['name'] . '.', 'ready' => 'Order ' . $o['number'] . ' is ready at ' . $b['name'] . '.',
                'out' => 'Order ' . $o['number'] . ' is on the way. Give the 4-digit code from your order to the delivery person.', 'cancelled' => 'Order ' . $o['number'] . ' was cancelled by ' . $b['name'] . '.'][$to];
        family_notify($c['customer_ref'], $b['name'], $msg, '/app#card', ['number' => $o['number'], 'status' => $to]);
        if (!$c['customer_ref'] && $c['sms_ok'] && ($ph = dec($c['phone_enc'])) && in_array($to, ['ready', 'out'], true)) sms_send($ph, $to === 'ready' ? 'order_ready' : 'order_out', [$o['number'], $b['name'], '----'], (int) $b['id']);
    }
    json_out(['ok' => true, 'order' => order_public($o, true)]);
case 'order_rx':   // the pharmacist saw the prescription (date of issue) or rejected it
    $s = need('orders'); if (!in_array($s['role'], ['owner', 'manager', 'pharmacist'], true)) fail('forbidden', 'Only a pharmacist can check prescriptions.', 403);
    $b = my_branch($s); $o = my_order($b, (int) ($in['id'] ?? 0));
    $ok = ($in['answer'] ?? '') === 'ok'; $date = (string) ($in['date'] ?? '');
    if ($ok && !rx_valid($date, !empty($in['chronic']))) fail('rx_old', 'The prescription must be dated within 30 days (long-term treatment) or 7 days (short illness).', 422);
    q('UPDATE orders SET rx_status = ?, rx_date = ? WHERE id = ?', [$ok ? 'ok' : 'rejected', $ok ? $date : null, $o['id']]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'rx_' . ($ok ? 'ok' : 'rejected'), $o['number'] . ($ok ? " dated $date" : '')); json_out(['ok' => true]);
case 'order_item':   // mark an item missing / removed, or change the quantity
    $s = need('orders'); $b = my_branch($s); $o = my_order($b, (int) ($in['order_id'] ?? 0));
    if (!in_array($o['status'], ['new', 'accepted'], true)) fail('locked', 'Items can be changed before packing only.');
    $it = q('SELECT * FROM order_items WHERE id = ? AND order_id = ?', [(int) ($in['item_id'] ?? 0), $o['id']])->fetch(); if (!$it) fail('not_found', 'Not found.', 404);
    $st = in_array($in['status'] ?? '', ['ok', 'missing', 'removed'], true) ? $in['status'] : $it['status'];
    q('UPDATE order_items SET status = ?, qty = ? WHERE id = ?', [$st, (int) num_in($in, 'qty', 1, 999, (float) $it['qty']), $it['id']]);
    $sub = (float) q("SELECT COALESCE(SUM(price * qty), 0) FROM order_items WHERE order_id = ? AND status = 'ok'", [$o['id']])->fetchColumn();
    q('UPDATE orders SET subtotal = ?, total = ? + delivery_fee - discount WHERE id = ?', [$sub, $sub, $o['id']]);
    json_out(['ok' => true, 'order' => order_public(order((int) $o['id']), true)]);

/* ---------------- Payments ---------------- */
case 'pay_record':
    $s = need('payments'); $b = my_branch($s);
    if (!empty($in['order_id'])) my_order($b, (int) $in['order_id']);
    json_out(['ok' => true] + pay_record($b, $in, 'staff', (int) $s['id']));
case 'pay_claims':   // payments customers or riders recorded, waiting for the shop to check its UPI app / cash box
    $s = need('payments'); $b = my_branch($s);
    json_out(['claims' => array_map(fn($p) => $p + ['order' => $p['order_id'] ? q('SELECT number FROM orders WHERE id = ?', [$p['order_id']])->fetchColumn() : null,
                                                    'customer' => $p['customer_id'] ? q('SELECT name FROM customers WHERE id = ?', [$p['customer_id']])->fetchColumn() : null],
        q("SELECT id, order_id, customer_id, method, amount, utr, note, by_whom, created_at FROM payments WHERE branch_id = ? AND status = 'claimed' ORDER BY id", [$b['id']])->fetchAll()),
        'today' => q("SELECT method, COUNT(*) n, SUM(amount) v FROM payments WHERE branch_id = ? AND status = 'confirmed' AND DATE(created_at) = CURDATE() GROUP BY method", [$b['id']])->fetchAll()]);
case 'pay_decide':
    $s = need('payments'); $b = my_branch($s); pay_decide($b, (int) ($in['id'] ?? 0), !empty($in['ok']), (int) $s['id']); json_out(['ok' => true]);
case 'upi_qr':   // a payment QR for any amount, for shops without a printed QR
    $s = need('payments'); $b = my_branch($s);
    if (!$b['upi_vpa']) fail('no_upi', 'Add your UPI ID or UPI phone number in Settings first.');
    json_out(['link' => upi_link($b, (float) num_in($in, 'amount', 1, 200000), (string) ($in['note'] ?? 'bill'))]);

/* ---------------- Network fees, health-points vouchers, dose-pouch labels ---------------- */
case 'fees':   // this business's monthly statement with the network: commission on network orders, minus vouchers it accepted
    $s = need('reports');
    $period = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['period'] ?? '')) ? $_GET['period'] : date('Y-m');
    json_out(fee_statement((int) $s['org_id'], $period) + ['rates' => array_intersect_key(fee_cfg(), array_flip(['urban', 'semiurban', 'rural', 'cap', 'gst']))]);

case 'voucher_redeem':   // a customer's AushadhBox health-points voucher, for non-prescription items; the network pays the shop back
    $s = need('payments'); $b = my_branch($s);
    limit('voucher:' . $b['id'], 30, 60);
    $order = !empty($in['order_id']) ? my_order($b, (int) $in['order_id']) : null;
    if ($order) {
        $otc = (float) q("SELECT COALESCE(SUM(price * qty), 0) FROM order_items WHERE order_id = ? AND status = 'ok' AND schedule IN ('OTC', 'G')", [$order['id']])->fetchColumn();
        if ($otc <= 0) fail('rx_only', 'Health-points vouchers are for non-prescription items (OTC, nutrition, devices), and this order has none.', 422);
    }
    $r = family_or_fail(family_call('voucher_redeem', ['branch' => family_branch($b), 'code' => (string) ($in['code'] ?? '')]));
    $value = (float) $r['rupees'];
    if ($order && $value > $otc) $value = $otc;   // never more than the non-prescription part; the rest of the voucher is forfeited (the customer was told)
    $pay = pay_record($b, ['method' => 'voucher', '_voucher_ok' => true, 'amount' => $value, 'order_id' => $order['id'] ?? null, 'note' => 'AushadhBox health points ' . strtoupper((string) $in['code'])], 'staff', (int) $s['id']);
    fee_record_voucher($b, $value, strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $in['code'])), $order['id'] ?? null);
    json_out(['ok' => true, 'rupees' => $value, 'payment' => $pay]);

case 'labels':   // 一包化 (dose packing): one label per pouch, for each dose time and day
    $s = need('orders'); $b = my_branch($s); $o = my_order($b, (int) ($_GET['id'] ?? 0));
    $days = max(1, min(31, (int) ($_GET['days'] ?? 7)));
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('+1 day'));
    $items = q("SELECT name, times FROM order_items WHERE order_id = ? AND status = 'ok' AND times <> ''", [$o['id']])->fetchAll();
    if (!$items) fail('no_times', 'This order has no dose times. Ask the customer when each medicine is taken, or order from the family app.');
    $cust = $o['customer_id'] ? q('SELECT name FROM customers WHERE id = ?', [$o['customer_id']])->fetchColumn() : '';
    $labels = [];
    for ($d = 0; $d < $days; $d++) foreach (['m' => 'Morning', 'a' => 'Afternoon', 'e' => 'Evening', 'b' => 'Bedtime'] as $k => $label) {
        $meds = array_values(array_map(fn($i) => $i['name'], array_filter($items, fn($i) => in_array($k, explode(',', $i['times']), true))));
        if ($meds) $labels[] = ['date' => date('D j M', strtotime("$start +$d day")), 'slot' => $label, 'key' => $k, 'meds' => $meds];
    }
    json_out(['order' => $o['number'], 'for' => $cust, 'pharmacy' => $b['name'], 'phone' => $b['phone'], 'labels' => $labels]);

/* ---------------- Customers, khata, points ---------------- */
case 'customer_find':
    $s = need('customers'); $b = my_branch($s); $qq = trim((string) ($_GET['q'] ?? ''));
    $phone = phone_norm($qq);
    $rows = $phone ? q('SELECT * FROM customers WHERE phone_hash = ?', [phone_hash($phone)])->fetchAll()
        : (mb_strlen($qq) >= 2 ? q("SELECT DISTINCT c.* FROM customers c LEFT JOIN khata k ON k.customer_id = c.id AND k.branch_id = ? LEFT JOIN orders o ON o.customer_id = c.id AND o.branch_id = ?
                                    WHERE (k.id IS NOT NULL OR o.id IS NOT NULL) AND (c.name LIKE ? OR c.village LIKE ? OR c.ref_code = ?) LIMIT 20", [$b['id'], $b['id'], '%' . $qq . '%', '%' . $qq . '%', strtoupper($qq)])->fetchAll() : []);
    json_out(['customers' => array_map(fn($c) => customer_public($c) + ['khata' => (float) (q('SELECT balance FROM khata WHERE branch_id = ? AND customer_id = ?', [$b['id'], $c['id']])->fetchColumn() ?: 0),
                                                                       'points' => points_balance((int) $b['org_id'], (int) $c['id'])], $rows)]);
case 'customer_add':
    $s = need('customers'); $b = my_branch($s);
    if (!phone_norm($in['phone'] ?? '') && empty($in['name'])) fail('need_contact', 'Enter at least a name or phone number.');
    $in['district'] = $in['district'] ?? $b['district'];
    $cid = customer_upsert($in);
    if (!empty($in['referral_code'])) referral_join((int) $b['org_id'], $cid, (string) $in['referral_code']);
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'customer_add', '#' . $cid); json_out(['ok' => true, 'id' => $cid]);
case 'customer_get':
    $s = need('customers'); $b = my_branch($s); $c = my_customer((int) ($_GET['id'] ?? 0));
    $k = q('SELECT id FROM khata WHERE branch_id = ? AND customer_id = ?', [$b['id'], $c['id']])->fetchColumn();
    json_out(['customer' => customer_public($c, can($s, 'delivery')), 'points' => points_balance((int) $b['org_id'], (int) $c['id']), 'khata' => $k ? khata_view((int) $k) : null,
              'orders' => array_map(fn($o) => ['id' => (int) $o['id'], 'number' => $o['number'], 'status' => $o['status'], 'total' => (float) $o['total'], 'paid' => (float) $o['paid'], 'at' => $o['created_at']],
                                    q('SELECT * FROM orders WHERE customer_id = ? AND branch_id = ? ORDER BY id DESC LIMIT 20', [$c['id'], $b['id']])->fetchAll()),
              'referrals' => q("SELECT r.status, r.amount, r.payout, c.name FROM referrals r JOIN customers c ON c.id = r.referred_id WHERE r.referrer_id = ? AND r.org_id = ?", [$c['id'], $b['org_id']])->fetchAll()]);
case 'khata_credit':   // put a bill (or part) on the credit book
    $s = need('khata'); $b = my_branch($s); $c = my_customer((int) ($in['customer_id'] ?? 0));
    if (!empty($in['order_id'])) my_order($b, (int) $in['order_id']);
    $k = khata_credit($b, (int) $c['id'], (float) num_in($in, 'amount', 1, 100000), !empty($in['order_id']) ? (int) $in['order_id'] : null, (int) $s['id'], (string) ($in['note'] ?? ''));
    family_notify($c['customer_ref'], 'Credit book at ' . $b['name'], money((float) $in['amount']) . ' added. You now owe ' . money((float) $k['balance']) . '.', '/app#card');
    json_out(['ok' => true, 'khata' => khata_view((int) $k['id'])]);
case 'khata_pay':   // the customer pays off (part of) the credit book
    $s = need('khata'); $b = my_branch($s); $c = my_customer((int) ($in['customer_id'] ?? 0));
    pay_record($b, ['method' => $in['method'] ?? 'cash', 'amount' => $in['amount'] ?? 0, 'utr' => $in['utr'] ?? null, 'customer_id' => $c['id'], 'khata' => true], 'staff', (int) $s['id']);
    json_out(['ok' => true, 'khata' => khata_view((int) khata_get((int) $b['id'], (int) $c['id'])['id'])]);
case 'khata_settings':
    $s = need('khata'); if (!in_array($s['role'], ['owner', 'manager'], true)) fail('forbidden', 'Only the owner or manager can change credit limits.', 403);
    $b = my_branch($s); $k = khata_get((int) $b['id'], (int) my_customer((int) ($in['customer_id'] ?? 0))['id']);
    q('UPDATE khata SET credit_limit = ?, remind_day = ?, status = ? WHERE id = ?', [num_in($in, 'limit', 0, 200000, (float) $k['credit_limit']), (int) num_in($in, 'remind_day', 1, 28, (float) $k['remind_day']), ($in['status'] ?? 'open') === 'closed' ? 'closed' : 'open', $k['id']]);
    json_out(['ok' => true]);
case 'khata_list':
    $s = need('khata'); $b = my_branch($s);
    json_out(['khata' => array_map(fn($r) => ['customerId' => (int) $r['customer_id'], 'name' => $r['name'], 'village' => $r['village'], 'balance' => (float) $r['balance'], 'limit' => (float) $r['credit_limit'], 'since' => $r['updated_at']],
        q('SELECT k.*, c.name, c.village FROM khata k JOIN customers c ON c.id = k.customer_id WHERE k.branch_id = ? AND k.balance > 0 ORDER BY k.balance DESC LIMIT 200', [$b['id']])->fetchAll())]);
case 'khata_remind':   // a gentle reminder by app notification or SMS
    $s = need('khata'); $b = my_branch($s); $c = my_customer((int) ($in['customer_id'] ?? 0)); $k = khata_get((int) $b['id'], (int) $c['id']);
    limit('remind:' . $k['id'], 1, 60 * 24 * 3);   // at most every 3 days
    family_notify($c['customer_ref'], 'Credit book at ' . $b['name'], 'Your balance is ' . money((float) $k['balance']) . '. Pay at the shop or by UPI.', '/app#card');
    $sms = !$c['customer_ref'] && $c['sms_ok'] && ($ph = dec($c['phone_enc'])) ? sms_send($ph, 'khata_remind', [$b['name'], number_format((float) $k['balance'], 0), $b['upi_vpa'] ?: 'cash'], (int) $b['id']) : null;
    json_out(['ok' => true, 'sms' => $sms]);
case 'points_add':   // a bonus, e.g. for refilling on time
    $s = need('customers'); $b = my_branch($s); $c = my_customer((int) ($in['customer_id'] ?? 0));
    q("INSERT INTO points (org_id, customer_id, delta, reason) VALUES (?,?,?, 'bonus')", [$b['org_id'], $c['id'], num_in($in, 'points', 1, 500)]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'points_bonus', $c['name'] . ' +' . $in['points']); json_out(['ok' => true]);
case 'referral_payout':   // pay a cash referral reward
    $s = need('payments'); $b = my_branch($s);
    $r = q("SELECT * FROM referrals WHERE id = ? AND org_id = ? AND status = 'earned' AND payout = 'cash'", [(int) ($in['id'] ?? 0), $b['org_id']])->fetch(); if (!$r) fail('not_found', 'Not found.', 404);
    q("UPDATE referrals SET status = 'paid' WHERE id = ?", [$r['id']]); audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'referral_paid', money((float) $r['amount'])); json_out(['ok' => true]);
case 'referrals':
    $s = need('customers'); $b = my_branch($s);
    json_out(['referrals' => q("SELECT r.id, r.status, r.amount, r.payout, r.created_at, a.name referrer, c.name referred FROM referrals r JOIN customers a ON a.id = r.referrer_id JOIN customers c ON c.id = r.referred_id WHERE r.org_id = ? ORDER BY r.id DESC LIMIT 100", [$b['org_id']])->fetchAll()]);

/* ---------------- Delivery ---------------- */
case 'delivery_setup':
    $s = need('delivery'); $b = my_branch($s);
    json_out(['riders' => array_map('staff_public', q("SELECT * FROM staff WHERE org_id = ? AND role = 'rider' AND status = 'active'", [$s['org_id']])->fetchAll()),
              'points' => q('SELECT * FROM pickup_points WHERE branch_id = ? ORDER BY village', [$b['id']])->fetchAll(), 'couriers' => COURIERS,
              'live' => array_map(fn($d) => $d + ['rider' => $d['rider_id'] ? q('SELECT name FROM staff WHERE id = ?', [$d['rider_id']])->fetchColumn() : null],
                  q("SELECT d.order_id, d.mode, d.status, d.partner, d.courier, d.tracking, d.last_lat, d.last_lng, d.last_at, d.rider_id, o.number FROM deliveries d JOIN orders o ON o.id = d.order_id WHERE o.branch_id = ? AND d.status IN ('assigned','picked')", [$b['id']])->fetchAll())]);
case 'delivery_quote':
    $s = need('delivery'); $b = my_branch($s); if ($b['lat'] === null) fail('no_location', 'Set the shop\'s location in Settings first.');
    $lat = (float) num_in($in, 'lat', -90, 90); $lng = (float) num_in($in, 'lng', -180, 180);
    json_out(delivery_quote($b, road_km(km((float) $b['lat'], (float) $b['lng'], $lat, $lng), $b['area']), (float) num_in($in, 'subtotal', 0, 1000000, 0), (array) ($in['items'] ?? []), shared_stops((int) $b['id'], $lat, $lng), !empty($in['customer_pays_full'])));
case 'delivery_assign':
    $s = need('delivery'); $b = my_branch($s); json_out(['ok' => true, 'delivery' => delivery_assign(my_order($b, (int) ($in['order_id'] ?? 0)), $in, (int) $s['id'])]);
case 'delivery_done':   // staff hand-over at a pickup point or partner delivery (needs the customer's code too)
    $s = need('delivery'); $b = my_branch($s);
    json_out(['ok' => true, 'order' => order_public(delivery_done(my_order($b, (int) ($in['order_id'] ?? 0)), (string) ($in['otp'] ?? ''), (float) num_in($in, 'cash', 0, 200000, 0), null, (int) $s['id']), true)]);
case 'point_save':
    $s = need('delivery'); $b = my_branch($s);
    $vals = [str_in($in, 'name', 100, true), in_array($in['kind'] ?? '', ['shop', 'asha', 'panchayat', 'school', 'busstand', 'post'], true) ? $in['kind'] : 'shop', str_in($in, 'village', 80, true),
             str_in($in, 'contact', 80), phone_norm($in['phone'] ?? '') ?: null, is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null, str_in($in, 'days', 40), !isset($in['active']) || $in['active'] ? 1 : 0];
    if (!empty($in['id'])) q('UPDATE pickup_points SET name = ?, kind = ?, village = ?, contact = ?, phone = ?, lat = ?, lng = ?, days = ?, active = ? WHERE id = ? AND branch_id = ?', array_merge($vals, [(int) $in['id'], $b['id']]));
    else q('INSERT INTO pickup_points (name, kind, village, contact, phone, lat, lng, days, active, branch_id) VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$b['id']]));
    json_out(['ok' => true]);

/* ---------------- Rider app ---------------- */
case 'rides':     $s = need('rides'); json_out(['rides' => rider_route((int) $s['id'])]);
case 'ride_ping': $s = need('rides'); rider_ping((int) $s['id'], (int) ($in['order_id'] ?? 0), (float) num_in($in, 'lat', -90, 90), (float) num_in($in, 'lng', -180, 180)); json_out(['ok' => true]);
case 'ride_done':
    $s = need('rides'); $o = order((int) ($in['order_id'] ?? 0));
    json_out(['ok' => true, 'order' => order_public(delivery_done($o, (string) ($in['otp'] ?? ''), (float) num_in($in, 'cash', 0, 200000, 0), (int) $s['id'], null))]);
case 'ride_failed':
    $s = need('rides'); $o = order((int) ($in['order_id'] ?? 0));
    if (!q('SELECT 1 FROM deliveries WHERE order_id = ? AND rider_id = ?', [$o['id'], $s['id']])->fetch()) fail('not_yours', 'Not your delivery.', 403);
    delivery_failed($o, str_in($in, 'reason', 120, true), (int) $s['id']); json_out(['ok' => true]);

/* ---------------- AushadhBox card (the family app checks consent; nothing is stored here) ---------------- */
case 'card_patient':
    $s = need('card'); $b = my_branch($s);
    limit('card:' . $b['id'], 120, 60);
    json_out(family_or_fail(family_call('card_patient', ['branch' => family_branch($b), 'code' => (string) ($in['code'] ?? '')])));
case 'card_precheck':
    $s = need('card'); $b = my_branch($s);
    json_out(family_or_fail(family_call('card_precheck', ['branch' => family_branch($b), 'code' => (string) ($in['code'] ?? ''), 'items' => (array) ($in['items'] ?? [])])));
case 'card_dispense':   // adds the medicines to the family's app; optionally also bills them here (takes stock)
    $s = need('card'); $b = my_branch($s);
    $r = family_or_fail(family_call('card_dispense', ['branch' => family_branch($b), 'code' => (string) ($in['code'] ?? ''), 'items' => (array) ($in['items'] ?? [])]));
    if (!empty($in['bill']) && !empty($r['customer_ref'])) {
        $cid = customer_upsert(['customer_ref' => $r['customer_ref'], 'name' => $r['name'] ?? 'Card customer', 'district' => $b['district']]);
        $o = order_create($b, $cid, ['items' => array_map(fn($i) => ['name' => $i['name'], 'qty' => max(1, (int) ($i['qty'] ?? 1))], (array) $in['items']), 'kind' => 'pickup', 'source' => 'walkin', 'rx_date' => $in['rx_date'] ?? null], (int) $s['id']);
        $r['order'] = $o;
    }
    audit('staff', (int) $s['id'], (int) $s['org_id'], (int) $b['id'], 'card_dispense', (string) ($r['added'] ?? 0) . ' medicines');
    json_out($r);

/* ---------------- Cold chain ---------------- */
case 'devices':
    $s = need('devices'); $b = my_branch($s);
    json_out(['devices' => array_map(fn($d) => ['id' => (int) $d['id'], 'name' => $d['name'], 'min' => (float) $d['min_c'], 'max' => (float) $d['max_c'], 'temp' => $d['last_c'] !== null ? (float) $d['last_c'] : null,
        'battery' => $d['battery'] !== null ? (int) $d['battery'] : null, 'lastSeen' => $d['last_seen'], 'state' => $d['token_hash'] ? device_check($d)['state'] : 'unpaired', 'pairCode' => $d['pair_until'] > date('Y-m-d H:i:s') ? $d['pair_code'] : null,
        'today' => q('SELECT DATE_FORMAT(at, "%H:%i") t, temp_c c FROM readings WHERE device_id = ? AND at >= NOW() - INTERVAL 24 HOUR ORDER BY at', [$d['id']])->fetchAll(), 'log' => device_log((int) $d['id'])],
        q('SELECT * FROM devices WHERE branch_id = ?', [$b['id']])->fetchAll())]);
case 'device_add':
    $s = need('devices'); $b = my_branch($s); json_out(['ok' => true] + device_pair((int) $b['id'], str_in($in, 'name', 60, true)));
case 'device_range':
    $s = need('devices'); $b = my_branch($s);
    $min = (float) num_in($in, 'min', -30, 30, 2); $max = (float) num_in($in, 'max', -20, 40, 8); if ($min >= $max) fail('bad_range', 'The lowest temperature must be below the highest.');
    q('UPDATE devices SET min_c = ?, max_c = ? WHERE id = ? AND branch_id = ?', [$min, $max, (int) ($in['id'] ?? 0), $b['id']]); json_out(['ok' => true]);

/* ---------------- Complaints and audit ---------------- */
case 'complaints':
    $s = need('orders'); $b = my_branch($s); json_out(['complaints' => q('SELECT * FROM complaints WHERE branch_id = ? ORDER BY status = "open" DESC, id DESC LIMIT 50', [$b['id']])->fetchAll()]);
case 'complaint_reply':
    $s = need('orders'); if (!in_array($s['role'], ['owner', 'manager', 'pharmacist'], true)) fail('forbidden', 'Ask the pharmacist or owner to reply.', 403);
    $b = my_branch($s); q("UPDATE complaints SET reply = ?, status = 'answered' WHERE id = ? AND branch_id = ?", [str_in($in, 'reply', 1000, true), (int) ($in['id'] ?? 0), $b['id']]); json_out(['ok' => true]);
case 'audit':
    $s = need('reports'); json_out(['log' => q('SELECT a.action, a.detail, a.actor, a.created_at, s.name FROM audit_log a LEFT JOIN staff s ON a.actor = "staff" AND s.id = a.actor_id WHERE a.org_id = ? ORDER BY a.id DESC LIMIT 200', [$s['org_id']])->fetchAll()]);

default: fail('unknown_action', 'Unknown action.', 404);
}
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('pharmacy-platform DB: ' . $e->getMessage());
    fail('server_error', 'Something went wrong on our side. Please try again.', 500);
} catch (RuntimeException $e) {
    if (str_starts_with($e->getMessage(), 'short:')) fail('short', 'Not enough stock of ' . substr($e->getMessage(), 6) . '.', 422);
    throw $e;
}
