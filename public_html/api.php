<?php
/* AushadhBox JSON API. Every call: api.php?action=<name>
   Reads use GET; anything that changes data uses POST with the X-CSRF-Token header. */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/catalog.php';
require __DIR__ . '/core/services.php';
require __DIR__ . '/core/plans.php';
require __DIR__ . '/core/automations.php';

security_headers();
start_session();
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$public = ['session', 'signup', 'login', 'forgot', 'reset', 'push_key', 'consent_log', 'track', 'contact_send'];
if ($method === 'POST') require_csrf();
if (!in_array($action, $public, true)) { $user = require_user(); $hid = hid($user); }

try {
    switch ($action) {

    /* ---------------- Account ---------------- */
    case 'session':
        $u = current_user();
        json_out(['user' => $u ? public_user($u) : null, 'csrf' => $_SESSION['csrf'], 'consentVersion' => config('consent_version'),
                  'features' => ['mail' => mail_enabled(), 'push' => push_enabled(), 'bhashini' => bhashini_enabled()]]);

    case 'signup':
        rate_limit('signup', null, 5, 60);
        $in = input();
        $name = str_in($in, 'name', 80, true);
        $email = strtolower((string) str_in($in, 'email', 160, true));
        $phone = str_in($in, 'phone', 15);
        $pass = (string) ($in['password'] ?? '');
        $lang = lang_in($in['language'] ?? null);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email', 'Please enter a valid e-mail address.');
        if ($phone !== null && !preg_match('/^[6-9]\d{9}$/', $phone)) fail('bad_phone', 'Enter a 10-digit Indian mobile number, or leave it empty.');
        if (mb_strlen($pass) < 8) fail('weak_password', 'Use at least 8 characters for your password.');
        if (empty($in['consent'])) fail('no_consent', 'Please read and agree to the privacy notice to create an account.');
        if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) {
            log_attempt('signup', false);
            fail('email_taken', 'An account with this e-mail already exists. Try signing in.', 409);
        }
        db()->beginTransaction();
        q('INSERT INTO users (name, email, phone, password_hash, language, consent_version, consent_at) VALUES (?,?,?,?,?,?,NOW())',
          [$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), $lang, config('consent_version')]);
        $uid = (int) db()->lastInsertId();
        q('INSERT INTO members (user_id, name, relation, is_self) VALUES (?,?,?,1)', [$uid, $name, 'Self']);
        q('INSERT INTO reminder_settings (user_id) VALUES (?)', [$uid]);
        q("INSERT INTO consent_log (user_ref, version, action) VALUES (?,?,'given')", [hash('sha256', $email), config('consent_version')]);
        db()->commit();
        log_attempt('signup', true);
        sign_in($uid);
        json_out(['ok' => true, 'user' => public_user(current_user())]);

    case 'login':
        $in = input();
        $email = strtolower((string) str_in($in, 'email', 160, true));
        rate_limit('login', $email, 8, 15);
        $row = q('SELECT id, password_hash FROM users WHERE email = ?', [$email])->fetch();
        if (!$row || !password_verify((string) ($in['password'] ?? ''), $row['password_hash'])) {
            log_attempt('login', false, $email);
            fail('bad_login', 'E-mail or password is not correct.', 401);
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $in['password'], PASSWORD_DEFAULT), $row['id']]);
        log_attempt('login', true, $email);
        sign_in((int) $row['id']);
        json_out(['ok' => true, 'user' => public_user(current_user())]);

    case 'logout':
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

    case 'forgot':
        rate_limit('forgot', null, 5, 60);
        $email = strtolower((string) str_in(input(), 'email', 160, true));
        if (!mail_enabled()) fail('mail_off', 'Password reset by e-mail is not set up yet. Please contact support.', 503);
        $u = q('SELECT id, name FROM users WHERE email = ?', [$email])->fetch();
        if ($u) {
            $token = bin2hex(random_bytes(32));
            q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, NOW() + INTERVAL 1 HOUR)', [$u['id'], hash('sha256', $token)]);
            $link = base_app_url() . '/app#reset=' . $token;
            send_mail($email, 'Reset your AushadhBox password', "Hello {$u['name']},\n\nOpen this link within 1 hour to set a new password:\n$link\n\nIf you did not ask for this, ignore this e-mail.\n\nAushadhBox");
        }
        log_attempt('forgot', true);
        json_out(['ok' => true]);   // same answer whether or not the e-mail exists

    case 'reset':
        $in = input();
        $token = (string) ($in['token'] ?? '');
        $pass = (string) ($in['password'] ?? '');
        if (mb_strlen($pass) < 8) fail('weak_password', 'Use at least 8 characters for your password.');
        $r = q('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $token)])->fetch();
        if (!$r) fail('bad_token', 'This reset link has expired. Ask for a new one.', 400);
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $r['user_id']]);
        q('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$r['id']]);
        sign_in((int) $r['user_id']);
        json_out(['ok' => true]);

    case 'settings_save':
        $in = input();
        $name = str_in($in, 'name', 80, true);
        $phone = str_in($in, 'phone', 15);
        if ($phone !== null && !preg_match('/^[6-9]\d{9}$/', $phone)) fail('bad_phone', 'Enter a 10-digit Indian mobile number, or leave it empty.');
        $lang = lang_in($in['language'] ?? null);
        q('UPDATE users SET name = ?, phone = ?, language = ?, weekly_email = ? WHERE id = ?', [$name, $phone, $lang, array_key_exists('weekly_email', $in) ? (!empty($in['weekly_email']) ? 1 : 0) : (int) $user['weekly_email'], $user['id']]);
        json_out(['ok' => true]);

    case 'language_save':
        $lang = lang_in(input()['language'] ?? null);
        q('UPDATE users SET language = ? WHERE id = ?', [$lang, $user['id']]);
        json_out(['ok' => true]);

    case 'consent_renew':
        q('UPDATE users SET consent_version = ?, consent_at = NOW() WHERE id = ?', [config('consent_version'), $user['id']]);
        q("INSERT INTO consent_log (user_ref, version, action) VALUES (?,?,'given')", [hash('sha256', $user['email']), config('consent_version')]);
        json_out(['ok' => true]);

    case 'password_change':
        $in = input();
        $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
        if (!password_verify((string) ($in['current'] ?? ''), $row['password_hash'])) fail('bad_password', 'Your current password is not correct.', 403);
        if (mb_strlen((string) ($in['new'] ?? '')) < 8) fail('weak_password', 'Use at least 8 characters for your new password.');
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $in['new'], PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        json_out(['ok' => true]);

    case 'export':   // DPDP: right to access your data
        $uid = hid($user);
        $data = [
            'note' => is_owner($user) ? 'Your family cabinet.' : 'The family cabinet you share (owned by another account).',
            'exported_at' => date('c'),
            'account' => q('SELECT name, email, phone, language, consent_version, consent_at, created_at, plan, plan_until, weekly_email FROM users WHERE id = ?', [$user['id']])->fetch(),
            'payments' => q("SELECT period, amount_paise/100 AS amount_rupees, status, rp_order_id, rp_payment_id, created_at, paid_at FROM payments WHERE user_id = ?", [$user['id']])->fetchAll(),
            'members' => q('SELECT name, relation, allergies FROM members WHERE user_id = ?', [$uid])->fetchAll(),
            'medicines' => q('SELECT c.name, m.name AS member, c.batch_no, c.mfg_month, c.exp_month, c.dose_times, c.quantity, c.notes, c.created_at FROM cabinet_items c LEFT JOIN members m ON m.id = c.member_id WHERE c.user_id = ?', [$uid])->fetchAll(),
            'doses_taken' => q('SELECT c.name, d.slot, d.taken_on, d.taken_at FROM dose_logs d JOIN cabinet_items c ON c.id = d.item_id WHERE d.user_id = ? ORDER BY d.taken_on DESC', [$uid])->fetchAll(),
            'reminders' => q('SELECT enabled, m_time, a_time, e_time, b_time FROM reminder_settings WHERE user_id = ?', [$user['id']])->fetch(),
        ];
        header('Content-Disposition: attachment; filename="aushadhbox-my-data.json"');
        json_out($data);

    case 'account_delete':   // DPDP: right to erasure. Everything linked to the user is removed by ON DELETE CASCADE.
        $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
        if (!password_verify((string) (input()['password'] ?? ''), $row['password_hash'])) fail('bad_password', 'Your password is not correct.', 403);
        q("INSERT INTO consent_log (user_ref, version, action) VALUES (?,?,'withdrawn')", [hash('sha256', $user['email']), $user['consent_version']]);
        q('UPDATE users SET household_id = NULL WHERE household_id = ?', [$user['id']]);   // people you shared with keep their own accounts
        q('DELETE FROM users WHERE id = ?', [$user['id']]);
        $_SESSION = [];
        session_regenerate_id(true);
        json_out(['ok' => true]);

    /* ---------------- App data ---------------- */
    case 'bootstrap':
        $uid = $hid;
        $members = q('SELECT id, name, relation, allergies, is_self FROM members WHERE user_id = ? ORDER BY is_self DESC, id', [$uid])->fetchAll();
        $items = q('SELECT id, member_id, name, catalog_id, info_id, batch_no, mfg_month, exp_month, quantity, dose_times, notes, stock_count, per_dose, course_end FROM cabinet_items WHERE user_id = ? ORDER BY exp_month', [$uid])->fetchAll();
        $today = date('Y-m-d');
        $doses = q('SELECT item_id, slot FROM dose_logs WHERE user_id = ? AND taken_on = ?', [$uid, $today])->fetchAll();
        $rem = q('SELECT enabled, m_time, a_time, e_time, b_time FROM reminder_settings WHERE user_id = ?', [$user['id']])->fetch() ?: ['enabled' => 0, 'm_time' => '08:00', 'a_time' => '13:30', 'e_time' => '19:30', 'b_time' => '22:00'];
        $kit = array_column(q('SELECT item_key FROM kit_checks WHERE user_id = ?', [$uid])->fetchAll(), 'item_key');
        json_out([
            'user' => public_user($user), 'today' => $today,
            'members' => array_map(fn($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'relation' => $m['relation'], 'allergies' => $m['allergies'], 'self' => (bool) $m['is_self']], $members),
            'items' => array_map('public_item', $items),
            'taken' => array_map(fn($d) => $d['item_id'] . ':' . $d['slot'], $doses),
            'reminders' => ['enabled' => (bool) $rem['enabled'], 'm' => $rem['m_time'], 'a' => $rem['a_time'], 'e' => $rem['e_time'], 'b' => $rem['b_time']],
            'kit' => $kit,
            'billing' => billing_public($user),
        ]);

    case 'member_save':
        $in = input();
        $name = str_in($in, 'name', 60, true);
        $rel = str_in($in, 'relation', 30);
        $all = str_in($in, 'allergies', 500);
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            own('members', $id, $hid);
            q('UPDATE members SET name = ?, relation = ?, allergies = ? WHERE id = ? AND user_id = ?', [$name, $rel, $all, $id, $hid]);
        } else {
            $lim = plan_info($user)['limits']['members'];
            if ((int) q('SELECT COUNT(*) c FROM members WHERE user_id = ?', [$hid])->fetch()['c'] >= $lim) fail('limit_members', $lim < PREMIUM_LIMITS['members'] ? "The Free plan covers $lim family members. Upgrade to Family Premium to add up to " . PREMIUM_LIMITS['members'] . '.' : "A household can have up to $lim members.", 402);
            q('INSERT INTO members (user_id, name, relation, allergies) VALUES (?,?,?,?)', [$hid, $name, $rel, $all]);
            $id = (int) db()->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'member_delete':
        $id = (int) (input()['id'] ?? 0);
        $m = own('members', $id, $hid);
        if (!empty($m['is_self'])) fail('cannot_delete_self', 'You cannot remove yourself. Delete the account instead.');
        q('DELETE FROM members WHERE id = ? AND user_id = ?', [$id, $hid]);
        json_out(['ok' => true]);

    case 'item_save':
        $in = input();
        $name = str_in($in, 'name', 120, true);
        $exp = month_to_date(str_in($in, 'exp', 7, true));
        if (!$exp) fail('bad_exp', 'Enter the expiry month and year printed on the pack.');
        $mfg = month_to_date(str_in($in, 'mfg', 7));
        if ($mfg && $mfg > $exp) fail('bad_dates', 'The manufacturing date must be before the expiry date.');
        $member = !empty($in['member_id']) ? (int) $in['member_id'] : null;
        if ($member) own('members', $member, $hid);
        $catalog = !empty($in['catalog_id']) ? (int) $in['catalog_id'] : null;
        if ($catalog && !q('SELECT 1 FROM medicine_catalog WHERE id = ?', [$catalog])->fetch()) $catalog = null;
        $infoId = $catalog ? q('SELECT info_id FROM medicine_catalog WHERE id = ?', [$catalog])->fetch()['info_id'] : null;
        if (!$infoId) $infoId = primary_info_id($name . ' ' . (string) ($in['generic'] ?? ''));
        $stock = (isset($in['stock']) && $in['stock'] !== '' && $in['stock'] !== null) ? max(0, min(9999, (int) $in['stock'])) : null;
        $perDose = max(1, min(20, (int) ($in['per_dose'] ?? 1)));
        $courseEnd = str_in($in, 'course_end', 10);
        if ($courseEnd !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $courseEnd)) fail('bad_course_end', 'Enter the course end date as a date.');
        $vals = [$member, $name, $catalog, $infoId, str_in($in, 'batch', 40), $mfg, $exp, str_in($in, 'quantity', 40), valid_times(is_array($in['times'] ?? null) ? implode(',', $in['times']) : (string) ($in['times'] ?? '')), str_in($in, 'notes', 300), $stock, $perDose, $courseEnd];
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            own('cabinet_items', $id, $hid);
            q('UPDATE cabinet_items SET member_id=?, name=?, catalog_id=?, info_id=?, batch_no=?, mfg_month=?, exp_month=?, quantity=?, dose_times=?, notes=?, stock_count=?, per_dose=?, course_end=? WHERE id=? AND user_id=?', array_merge($vals, [$id, $hid]));
        } else {
            $lim = plan_info($user)['limits']['items'];
            if ((int) q('SELECT COUNT(*) c FROM cabinet_items WHERE user_id = ?', [$hid])->fetch()['c'] >= $lim) fail('limit_items', $lim < PREMIUM_LIMITS['items'] ? "The Free plan holds $lim medicines. Remove expired ones, or upgrade to Family Premium for unlimited medicines." : "Your cabinet is full ($lim medicines).", 402);
            q('INSERT INTO cabinet_items (member_id, name, catalog_id, info_id, batch_no, mfg_month, exp_month, quantity, dose_times, notes, stock_count, per_dose, course_end, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$hid]));
            $id = (int) db()->lastInsertId();
        }
        json_out(['ok' => true, 'item' => public_item(q('SELECT * FROM cabinet_items WHERE id = ?', [$id])->fetch())]);

    case 'item_delete':
        $id = (int) (input()['id'] ?? 0);
        own('cabinet_items', $id, $hid);
        q('DELETE FROM cabinet_items WHERE id = ? AND user_id = ?', [$id, $hid]);
        json_out(['ok' => true]);

    case 'dose_toggle':
        $in = input();
        $id = (int) ($in['item_id'] ?? 0);
        $slot = (string) ($in['slot'] ?? '');
        if (!in_array($slot, SLOTS, true)) fail('bad_slot', 'Unknown time of day.');
        own('cabinet_items', $id, $hid);
        $today = date('Y-m-d');
        // Automatic stock count: a new tick uses up per_dose; un-ticking puts it back. Only real changes count.
        if (!empty($in['taken'])) {
            $changed = q('INSERT IGNORE INTO dose_logs (user_id, item_id, slot, taken_on) VALUES (?,?,?,?)', [$hid, $id, $slot, $today])->rowCount();
            if ($changed) q('UPDATE cabinet_items SET stock_count = GREATEST(CAST(stock_count AS SIGNED) - per_dose, 0) WHERE id = ? AND stock_count IS NOT NULL', [$id]);
        } else {
            $changed = q('DELETE FROM dose_logs WHERE user_id = ? AND item_id = ? AND slot = ? AND taken_on = ?', [$hid, $id, $slot, $today])->rowCount();
            if ($changed) q('UPDATE cabinet_items SET stock_count = LEAST(stock_count + per_dose, 9999) WHERE id = ? AND stock_count IS NOT NULL', [$id]);
        }
        $st = q('SELECT stock_count FROM cabinet_items WHERE id = ?', [$id])->fetchColumn();
        json_out(['ok' => true, 'stock' => $st === null ? null : (int) $st]);

    case 'dose_history':   // last 14 days, for the adherence view
        $rows = q('SELECT taken_on, COUNT(*) n FROM dose_logs WHERE user_id = ? AND taken_on >= CURDATE() - INTERVAL 13 DAY GROUP BY taken_on', [$hid])->fetchAll();
        json_out(['days' => $rows]);

    case 'reminders_save':
        $in = input();
        $t = [];
        foreach (SLOTS as $s) {
            $v = (string) ($in[$s] ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) fail('bad_time', 'Times must look like 08:00.');
            $t[$s] = $v;
        }
        q('INSERT INTO reminder_settings (user_id, enabled, m_time, a_time, e_time, b_time) VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), m_time=VALUES(m_time), a_time=VALUES(a_time), e_time=VALUES(e_time), b_time=VALUES(b_time)',
          [$user['id'], !empty($in['enabled']) ? 1 : 0, $t['m'], $t['a'], $t['e'], $t['b']]);
        json_out(['ok' => true]);

    case 'kit_toggle':
        $in = input();
        $key = preg_replace('/[^a-z0-9_-]/', '', (string) ($in['key'] ?? ''));
        if ($key === '' || strlen($key) > 20) fail('bad_key', 'Unknown checklist item.');
        if (!empty($in['checked'])) q('INSERT IGNORE INTO kit_checks (user_id, item_key) VALUES (?,?)', [$hid, $key]);
        else q('DELETE FROM kit_checks WHERE user_id = ? AND item_key = ?', [$hid, $key]);
        json_out(['ok' => true]);

    /* ---------------- Medicine data ---------------- */
    case 'catalog_search':
        $qText = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($qText) < 2) json_out(['results' => []]);
        json_out(['results' => search_catalog(mb_substr($qText, 0, 80))]);

    case 'catalog_match':
        $text = (string) (input()['text'] ?? '');
        json_out(match_label(mb_substr($text, 0, 4000)));

    case 'info_list':
        json_out(['info' => array_map('info_public', info_rows())]);

    /* ---------------- Push reminders ---------------- */
    case 'push_key':
        json_out(['key' => push_enabled() ? config('vapid_public') : null]);

    case 'push_subscribe':
        $in = input();
        $ep = (string) ($in['endpoint'] ?? '');
        $p = (string) ($in['keys']['p256dh'] ?? '');
        $a = (string) ($in['keys']['auth'] ?? '');
        if (!preg_match('#^https://#', $ep) || strlen($ep) > 600 || strlen(b64u_dec($p)) !== 65 || strlen(b64u_dec($a)) !== 16) fail('bad_subscription', 'This browser sent an invalid push subscription.');
        q('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), p256dh=VALUES(p256dh), auth=VALUES(auth)', [$user['id'], $ep, $p, $a]);
        json_out(['ok' => true]);

    case 'push_unsubscribe':
        q('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?', [$user['id'], (string) (input()['endpoint'] ?? '')]);
        json_out(['ok' => true]);

    /* ---------------- Voice ---------------- */
    case 'tts':
        $in = input();
        $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 400);
        $lang = lang_in($in['lang'] ?? null);
        if ($text === '' || !bhashini_enabled()) fail('tts_off', 'Server voice is not set up.', 501);
        rate_limit('tts', null, 60, 10);
        log_attempt('tts', true);
        $wav = bhashini_tts($text, $lang);
        if (!$wav) fail('tts_failed', 'The voice service did not answer.', 502);
        header('Content-Type: audio/wav');
        header('Cache-Control: private, max-age=86400');
        echo $wav;
        exit;

    /* ---------------- Plans, payments and family sharing ---------------- */
    case 'billing_status':
        json_out(billing_public($user) + ['accounts' => household_accounts($user)]);

    case 'billing_order':
        if (!is_owner($user)) fail('not_owner', 'Only the person who owns the family cabinet can pay for Premium.', 403);
        if (!payments_ready()) fail('payments_off', 'Payments are not set up yet. Please try again later.', 503);
        $period = (string) (input()['period'] ?? '') === 'year' ? 'year' : 'month';
        $amount = (int) (config($period === 'year' ? 'price_year' : 'price_month') ?: ($period === 'year' ? 399 : 49)) * 100;
        rate_limit('order', null, 20, 60);
        log_attempt('order', true);
        $receipt = 'u' . $user['id'] . '-' . time();
        if (razorpay_live()) {
            try { $o = razorpay_create_order($amount, $receipt, ['user_id' => (string) $user['id'], 'period' => $period]); }
            catch (RuntimeException $e) { fail('gateway', 'The payment service did not answer. Please try again in a minute.', 502); }
            $orderId = $o['id'];
        } else {
            $orderId = 'demo_' . bin2hex(random_bytes(8));
        }
        q('INSERT INTO payments (user_id, rp_order_id, period, amount_paise) VALUES (?,?,?,?)', [$user['id'], $orderId, $period, $amount]);
        json_out(['order_id' => $orderId, 'amount' => $amount, 'currency' => 'INR', 'period' => $period, 'demo' => !razorpay_live(),
                  'key' => razorpay_live() ? config('razorpay_key_id') : null, 'name' => site_title(),
                  'description' => 'Family Premium · 1 ' . $period, 'prefill' => ['name' => $user['name'], 'email' => $user['email'], 'contact' => $user['phone'] ?? '']]);

    case 'billing_verify':
        $in = input();
        $orderId = (string) ($in['order_id'] ?? '');
        $p = q('SELECT user_id, status FROM payments WHERE rp_order_id = ?', [$orderId])->fetch();
        if (!$p || (int) $p['user_id'] !== (int) $user['id']) fail('not_found', 'Payment not found.', 404);
        if (str_starts_with($orderId, 'demo_')) {
            if (razorpay_live() || !config('payments_demo')) fail('demo_off', 'Demo payments are switched off.', 403);
            $paymentId = 'demo_pay_' . bin2hex(random_bytes(6));
        } else {
            $paymentId = (string) ($in['payment_id'] ?? '');
            if (!razorpay_signature_ok($orderId, $paymentId, (string) ($in['signature'] ?? ''))) {
                error_log("Razorpay signature mismatch for order $orderId");
                fail('bad_signature', 'We could not confirm this payment. If money was taken, contact us with your payment ID.', 400);
            }
        }
        mark_paid($orderId, $paymentId);
        json_out(['ok' => true, 'billing' => billing_public(q('SELECT * FROM users WHERE id = ?', [$user['id']])->fetch())]);

    case 'invite_create':
        if (!is_owner($user)) fail('not_owner', 'Only the cabinet owner can invite people.', 403);
        if (!is_premium($user)) fail('premium_only', 'Family sharing is part of Family Premium.', 402);
        if (plan_usage($user)['accounts'] >= PREMIUM_LIMITS['accounts']) fail('limit_accounts', 'You already share with ' . (PREMIUM_LIMITS['accounts'] - 1) . ' people, the most allowed.', 402);
        q('DELETE FROM invites WHERE owner_id = ? AND (used_by IS NOT NULL OR expires_at < NOW())', [$user['id']]);
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = ''; for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        q('INSERT INTO invites (code, owner_id, expires_at) VALUES (?,?, NOW() + INTERVAL 7 DAY)', [$code, $user['id']]);
        json_out(['code' => $code, 'expires_in_days' => 7, 'link' => base_app_url() . '/app#join=' . $code]);

    case 'invite_join':
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (input()['code'] ?? '')));
        rate_limit('join', null, 10, 60);
        $inv = q('SELECT code, owner_id FROM invites WHERE code = ? AND used_by IS NULL AND expires_at > NOW()', [$code])->fetch();
        if (!$inv) { log_attempt('join', false); fail('bad_code', 'This invite code is wrong or has expired. Ask for a new one.', 404); }
        if ((int) $inv['owner_id'] === (int) $user['id']) fail('own_code', 'This is your own invite code. Send it to a family member.');
        if (!is_owner($user)) fail('already_member', 'You are already part of a family cabinet. Leave it first.');
        if ((int) q('SELECT COUNT(*) FROM users WHERE household_id = ?', [$user['id']])->fetchColumn() > 0) fail('has_members', 'Other people share your cabinet. Remove them before joining another family.');
        $owner = q('SELECT * FROM users WHERE id = ?', [$inv['owner_id']])->fetch();
        if (!$owner || !is_premium($owner)) fail('premium_only', 'The family owner’s Premium has ended, so new people cannot join right now.', 402);
        if (plan_usage($owner)['accounts'] >= PREMIUM_LIMITS['accounts']) fail('limit_accounts', 'This family cabinet is already shared with the maximum number of people.', 402);
        db()->beginTransaction();
        q('UPDATE users SET household_id = ? WHERE id = ?', [$owner['id'], $user['id']]);
        q('UPDATE invites SET used_by = ? WHERE code = ?', [$user['id'], $code]);
        if ((int) q('SELECT COUNT(*) FROM members WHERE user_id = ?', [$owner['id']])->fetchColumn() < PREMIUM_LIMITS['members']
            && !q('SELECT 1 FROM members WHERE user_id = ? AND name = ?', [$owner['id'], $user['name']])->fetch()) {
            q('INSERT INTO members (user_id, name, relation) VALUES (?,?,?)', [$owner['id'], $user['name'], 'Family']);
        }
        db()->commit();
        log_attempt('join', true);
        json_out(['ok' => true, 'owner' => $owner['name']]);

    case 'household_leave':
        if (is_owner($user)) fail('is_owner', 'You own this cabinet. Remove other people instead.');
        q('UPDATE users SET household_id = NULL WHERE id = ?', [$user['id']]);
        json_out(['ok' => true]);

    case 'household_remove':
        if (!is_owner($user)) fail('not_owner', 'Only the cabinet owner can remove people.', 403);
        $target = (int) (input()['user_id'] ?? 0);
        $n = q('UPDATE users SET household_id = NULL WHERE id = ? AND household_id = ?', [$target, $user['id']])->rowCount();
        if (!$n) fail('not_found', 'That person is not in your family cabinet.', 404);
        json_out(['ok' => true]);

    case 'onboarded':
        q('UPDATE users SET onboarded = 1 WHERE id = ?', [$user['id']]);
        json_out(['ok' => true]);

    /* ---------------- Automations ---------------- */
    case 'automation_get':
        $a = auto_settings((int) $user['id']);
        json_out(automation_public($a));

    case 'automation_save':
        json_out(automation_public(auto_save((int) $user['id'], input())));

    case 'calendar_link':   // creates (or with renew=1 replaces) the secret calendar address
        $a = auto_settings((int) $user['id']);
        $token = (!empty(input()['renew']) || empty($a['ics_token'])) ? auto_new_ics_token((int) $user['id']) : $a['ics_token'];
        json_out(['url' => base_app_url() . '/calendar/' . $token . '.ics']);

    /* ---------------- Website: cookie consent, analytics, contact (no sign-in needed) ---------------- */
    case 'consent_log':
        $in = input();
        $ref = (string) ($in['ref'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $ref)) fail('bad_ref', 'Invalid reference.');
        rate_limit('consent', null, 30, 10);
        log_attempt('consent', true);
        q('INSERT INTO cookie_consents (visitor_ref, analytics, marketing, version) VALUES (?,?,?,?)', [$ref, !empty($in['analytics']) ? 1 : 0, !empty($in['marketing']) ? 1 : 0, '1']);
        if (empty($in['analytics'])) q('DELETE FROM analytics_events WHERE visitor_ref = ?', [$ref]);   // withdrawing consent erases past statistics
        json_out(['ok' => true]);

    case 'track':
        // Only called by consent.js after the visitor accepted analytics. We double-check the cookie here too.
        $in = input();
        $ref = (string) ($in['ref'] ?? '');
        $c = explode('.', (string) ($_COOKIE['ms_consent'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $ref) || ($c[1] ?? '0') !== '1' || ($c[3] ?? '') !== $ref) json_out(['ok' => false]);
        if (preg_match('/bot|crawl|spider|slurp|headless/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) json_out(['ok' => false]);
        $clip = fn($k, $n) => mb_substr(preg_replace('/[\x00-\x1F]/u', '', (string) ($in[$k] ?? '')), 0, $n);
        $event = preg_replace('/[^a-z0-9_]/', '', strtolower($clip('event', 40))) ?: 'pageview';
        q('INSERT INTO analytics_events (visitor_ref, event, path, referrer, utm_source, utm_medium, utm_campaign, lang, device) VALUES (?,?,?,?,?,?,?,?,?)',
          [$ref, $event, $clip('path', 200), $clip('referrer', 120), $clip('utm_source', 60), $clip('utm_medium', 60), $clip('utm_campaign', 80),
           lang_in($in['lang'] ?? null), in_array($in['device'] ?? '', ['mobile', 'tablet', 'desktop'], true) ? $in['device'] : 'desktop']);
        json_out(['ok' => true]);

    case 'contact_send':
        $in = input();
        if (!empty($in['website'])) json_out(['ok' => true]);   // honeypot: bots fill hidden fields
        rate_limit('contact', null, 5, 60);
        $name = str_in($in, 'name', 80, true);
        $email = strtolower((string) str_in($in, 'email', 160, true));
        $topic = in_array($in['topic'] ?? '', ['help', 'billing', 'privacy', 'partnership', 'other'], true) ? $in['topic'] : 'other';
        $msg = trim((string) ($in['message'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email', 'Please enter a valid e-mail address.');
        if (mb_strlen($msg) < 10 || mb_strlen($msg) > 4000) fail('bad_message', 'Please write a message between 10 and 4000 characters.');
        q('INSERT INTO contact_messages (name, email, topic, message) VALUES (?,?,?,?)', [$name, $email, $topic, $msg]);
        log_attempt('contact', true);
        if (mail_enabled() && config('support_email')) send_mail((string) config('support_email'), "[Contact/$topic] $name", "From: $name <$email>\nTopic: $topic\n\n$msg");
        json_out(['ok' => true]);

    /* ---------------- Admin ---------------- */
    case 'admin_stats':
        require_admin($user);
        json_out([
            'users' => (int) q('SELECT COUNT(*) c FROM users')->fetch()['c'],
            'new_users_7d' => (int) q('SELECT COUNT(*) c FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY')->fetch()['c'],
            'items' => (int) q('SELECT COUNT(*) c FROM cabinet_items')->fetch()['c'],
            'catalog' => q('SELECT source, COUNT(*) n FROM medicine_catalog GROUP BY source')->fetchAll(),
            'info' => (int) q('SELECT COUNT(*) c FROM medicine_info')->fetch()['c'],
            'push_subscriptions' => (int) q('SELECT COUNT(*) c FROM push_subscriptions')->fetch()['c'],
            'features' => ['mail' => mail_enabled(), 'push' => push_enabled(), 'bhashini' => bhashini_enabled(), 'payments' => razorpay_live() ? 'live' : (config('payments_demo') ? 'demo' : 'off')],
            'premium_users' => (int) q("SELECT COUNT(*) FROM users WHERE plan = 'premium' AND plan_until >= CURDATE()")->fetchColumn(),
            'revenue_30d' => (int) q("SELECT COALESCE(SUM(amount_paise),0) FROM payments WHERE status = 'paid' AND rp_order_id NOT LIKE 'demo\\_%' AND paid_at >= NOW() - INTERVAL 30 DAY")->fetchColumn() / 100,
            'revenue_total' => (int) q("SELECT COALESCE(SUM(amount_paise),0) FROM payments WHERE status = 'paid' AND rp_order_id NOT LIKE 'demo\\_%'")->fetchColumn() / 100,
            'payments_recent' => q("SELECT p.created_at, p.period, p.amount_paise/100 AS amount, p.status, LEFT(p.rp_order_id, 30) AS order_id, u.name FROM payments p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 15")->fetchAll(),
            'signups_14d' => q('SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at) ORDER BY d')->fetchAll(),
            'visitors_30d' => (int) q("SELECT COUNT(DISTINCT visitor_ref) FROM analytics_events WHERE created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn(),
            'pageviews_30d' => (int) q("SELECT COUNT(*) FROM analytics_events WHERE event = 'pageview' AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn(),
            'visits_14d' => q("SELECT DATE(created_at) d, COUNT(DISTINCT visitor_ref) n FROM analytics_events WHERE created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at) ORDER BY d")->fetchAll(),
            'top_pages' => q("SELECT path, COUNT(*) n FROM analytics_events WHERE event = 'pageview' AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY path ORDER BY n DESC LIMIT 10")->fetchAll(),
            'top_referrers' => q("SELECT COALESCE(NULLIF(utm_source,''), NULLIF(referrer,''), '(direct)') src, COUNT(DISTINCT visitor_ref) n FROM analytics_events WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY src ORDER BY n DESC LIMIT 10")->fetchAll(),
            'devices' => q("SELECT device, COUNT(DISTINCT visitor_ref) n FROM analytics_events WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY device ORDER BY n DESC")->fetchAll(),
            'cta_clicks_30d' => q("SELECT event, COUNT(*) n FROM analytics_events WHERE event <> 'pageview' AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY event ORDER BY n DESC LIMIT 10")->fetchAll(),
            'consent' => q('SELECT SUM(analytics = 1) accepted, SUM(analytics = 0) rejected FROM cookie_consents WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetch(),
            'messages_open' => (int) q('SELECT COUNT(*) FROM contact_messages WHERE handled = 0')->fetchColumn(),
        ]);

    case 'admin_messages':
        require_admin($user);
        json_out(['messages' => q('SELECT id, name, email, topic, message, handled, created_at FROM contact_messages ORDER BY handled, id DESC LIMIT 100')->fetchAll()]);

    case 'admin_message_done':
        require_admin($user);
        q('UPDATE contact_messages SET handled = 1 WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        json_out(['ok' => true]);

    case 'admin_import':
        require_admin($user);
        $in = input();
        $source = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($in['source'] ?? 'import')));
        $rows = parse_list_file((string) ($in['content'] ?? ''));
        if (!$rows) fail('empty', 'No rows found. Use CSV (drug_code,generic_name,unit_size) or pipe format.');
        if (count($rows) > 20000) fail('too_big', 'Import at most 20,000 rows at a time.');
        json_out(import_catalog($rows, $source ?: 'import'));

    default:
        fail('unknown_action', 'Unknown action.', 404);
    }
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('DB error: ' . $e->getMessage());
    fail('server_error', 'Something went wrong on our side. Please try again.', 500);
}

/* ---------------- helpers ---------------- */
function lang_in($v): string
{
    return is_string($v) && in_array($v, LANGS, true) ? $v : 'en';
}

function public_user(array $u): array
{
    $pi = plan_info($u);
    return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'language' => $u['language'],
            'admin' => $u['role'] === 'admin', 'consentCurrent' => $u['consent_version'] === config('consent_version'),
            'plan' => $pi['plan'], 'planUntil' => $pi['until'], 'owner' => $pi['owner'], 'weeklyEmail' => (bool) $u['weekly_email'], 'onboarded' => (bool) $u['onboarded']];
}

function automation_public(array $a): array
{
    return ['refill' => (bool) $a['refill_on'], 'refillDays' => (int) $a['refill_days'], 'missed' => (bool) $a['missed_on'], 'missedAfter' => (int) $a['missed_after'],
            'course' => (bool) $a['course_on'], 'cleanup' => (bool) $a['cleanup_on'], 'calendar' => !empty($a['ics_token'])];
}

function payments_ready(): bool { return razorpay_live() || (bool) config('payments_demo'); }

function base_app_url(): string
{
    $u = rtrim((string) config('app_url'), '/');
    return ($u === '' || str_contains($u, 'example.com')) ? (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') : $u;
}

function billing_public(array $u): array
{
    $pi = plan_info($u);
    $o = household_owner($u);
    return ['plan' => $pi['plan'], 'until' => $pi['until'], 'expiredOn' => $pi['expired_on'], 'owner' => $pi['owner'], 'ownerName' => $o['name'],
            'limits' => $pi['limits'], 'usage' => plan_usage($u), 'payments' => payments_ready() ? (razorpay_live() ? 'live' : 'demo') : 'off',
            'prices' => ['month' => (int) (config('price_month') ?: 49), 'year' => (int) (config('price_year') ?: 399)]];
}

function public_item(array $r): array
{
    return ['id' => (int) $r['id'], 'member_id' => $r['member_id'] ? (int) $r['member_id'] : null, 'name' => $r['name'],
            'catalog_id' => $r['catalog_id'] ? (int) $r['catalog_id'] : null, 'info' => info_by_id($r['info_id'] ? (int) $r['info_id'] : null),
            'batch' => $r['batch_no'], 'mfg' => date_to_month($r['mfg_month']), 'exp' => date_to_month($r['exp_month']),
            'quantity' => $r['quantity'], 'times' => $r['dose_times'] === '' ? [] : explode(',', $r['dose_times']), 'notes' => $r['notes'],
            'stock' => $r['stock_count'] === null ? null : (int) $r['stock_count'], 'perDose' => (int) $r['per_dose'], 'courseEnd' => $r['course_end']];
}

function sign_in(int $uid): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$uid]);
}

function own(string $table, int $id, int $uid): array
{
    $allowed = ['members', 'cabinet_items'];
    if (!in_array($table, $allowed, true)) fail('bad_table', 'Not allowed.', 500);
    $row = q("SELECT * FROM $table WHERE id = ? AND user_id = ?", [$id, $uid])->fetch();
    if (!$row) fail('not_found', 'That record was not found.', 404);
    return $row;
}

function log_attempt(string $kind, bool $ok, ?string $email = null): void
{
    q('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,?)', [client_ip(), $kind . ':' . ($email ?? ''), $ok ? 1 : 0]);
}

/* Allow at most $max failed (or, for sign-up/forgot/tts, any) attempts per IP / e-mail in $minutes. */
function rate_limit(string $kind, ?string $email, int $max, int $minutes): void
{
    $onlyFailures = $kind === 'login';
    $sql = 'SELECT COUNT(*) c FROM login_attempts WHERE created_at >= NOW() - INTERVAL ' . (int) $minutes . ' MINUTE AND email LIKE ? AND ip = ?' . ($onlyFailures ? ' AND success = 0' : '');
    $n = (int) q($sql, [$kind . ':%', client_ip()])->fetch()['c'];
    if ($email !== null && $onlyFailures) {
        $n = max($n, (int) q('SELECT COUNT(*) c FROM login_attempts WHERE created_at >= NOW() - INTERVAL ' . (int) $minutes . ' MINUTE AND email = ? AND success = 0', [$kind . ':' . $email])->fetch()['c']);
    }
    if ($n >= $max) fail('too_many', "Too many attempts. Please wait $minutes minutes and try again.", 429);
}

function require_admin(array $u): void
{
    if ($u['role'] !== 'admin') fail('forbidden', 'Only administrators can do this.', 403);
}
