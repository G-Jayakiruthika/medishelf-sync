<?php
/* AushadhBox JSON API. Every call: api.php?action=<name>
   Reads use GET; anything that changes data uses POST with the X-CSRF-Token header. */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/catalog.php';
require __DIR__ . '/core/services.php';
require __DIR__ . '/core/plans.php';
require __DIR__ . '/core/automations.php';
require __DIR__ . '/core/doses.php';
require __DIR__ . '/core/pillbox.php';
require __DIR__ . '/core/ai.php';
require __DIR__ . '/core/autopilot.php';
require __DIR__ . '/core/support.php';
require __DIR__ . '/core/health.php';
require __DIR__ . '/core/safety.php';
require __DIR__ . '/core/assistant.php';
require __DIR__ . '/core/care.php';
require_once __DIR__ . '/core/careplus.php';
require __DIR__ . '/core/household.php';
require __DIR__ . '/core/pharmacy.php';
require __DIR__ . '/core/watch.php';
require __DIR__ . '/core/network_link.php';
require_once __DIR__ . '/core/migrate.php';
// Right after an update the new columns must exist before anything reads them (cheap: compares two file dates).
try { migrate_if_due(); } catch (Throwable $e) { error_log('migrate: ' . $e->getMessage()); }

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
                  'features' => ['mail' => mail_enabled(), 'push' => push_enabled(), 'bhashini' => bhashini_enabled(), 'ai' => ai_enabled()]]);

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
        q('INSERT INTO members (user_id, name, relation, is_self, account_id) VALUES (?,?,?,1,?)', [$uid, $name, 'Self', $uid]);
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

    /* ---------------- Round 3: Japanese household-app ideas and rural features (core/careplus.php) ---------------- */
    case 'careplus':   // everything the Care screen shows in one call
        json_out(['repeats' => rx_repeats($hid), 'repeatDue' => rx_repeat_due($hid), 'review' => poly_review($hid), 'food' => food_cautions($hid),
                  'pregnancies' => pregnancies($hid), 'growth' => child_growth($hid), 'points' => points_view($hid), 'numbers' => telephony_numbers(),
                  'phones' => array_map(fn($m) => ['memberId' => (int) $m['id'], 'phone' => $m['phone'], 'verified' => (bool) $m['phone_verified'], 'sms' => (bool) $m['sms_remind']],
                                        q('SELECT id, phone, phone_verified, sms_remind FROM members WHERE user_id = ?', [$hid])->fetchAll())]);

    case 'rx_repeat_save':
        rx_repeat_save($hid, input());
        json_out(['ok' => true, 'repeats' => rx_repeats($hid)]);

    case 'rx_repeat_use':
        $r = rx_repeat_use($hid, (int) (input()['id'] ?? 0));
        json_out(['ok' => true, 'repeats' => rx_repeats($hid)] + $r);

    case 'rx_repeat_delete':
        q('DELETE FROM rx_repeats WHERE id = ? AND user_id = ?', [(int) (input()['id'] ?? 0), $hid]);
        json_out(['ok' => true, 'repeats' => rx_repeats($hid)]);

    case 'pregnancy_start':
        $in = input();
        json_out(['ok' => true, 'pregnancy' => pregnancy_start($hid, (int) ($in['member_id'] ?? 0), (string) ($in['lmp'] ?? ''))]);

    case 'pregnancy_anc':   // mark a check-up as done (or not)
        $in = input();
        $p = q("SELECT * FROM pregnancies WHERE id = ? AND user_id = ? AND status = 'active'", [(int) ($in['id'] ?? 0), $hid])->fetch();
        if (!$p) fail('not_found', 'Pregnancy record not found.', 404);
        $n = max(1, min(4, (int) ($in['n'] ?? 0)));
        $done = array_values(array_unique(array_filter(array_map('intval', explode(',', $p['anc_done'])))));
        $done = !empty($in['done']) ? array_values(array_unique(array_merge($done, [$n]))) : array_values(array_diff($done, [$n]));
        sort($done);
        q('UPDATE pregnancies SET anc_done = ? WHERE id = ?', [implode(',', $done), $p['id']]);
        if (!empty($in['done'])) points_award($hid, 'anc', 'anc' . $p['id'] . '-' . $n);
        json_out(['ok' => true, 'pregnancies' => pregnancies($hid)]);

    case 'pregnancy_end':
        q("UPDATE pregnancies SET status = 'ended' WHERE id = ? AND user_id = ?", [(int) (input()['id'] ?? 0), $hid]);
        json_out(['ok' => true, 'pregnancies' => pregnancies($hid)]);

    case 'points':
        json_out(points_view($hid));

    case 'voucher_make':
        $r = voucher_make($hid, (int) (input()['points'] ?? 0));
        json_out(['ok' => true, 'voucher' => $r] + points_view($hid));

    case 'voucher_cancel':
        voucher_cancel($hid, (string) (input()['code'] ?? ''));
        json_out(['ok' => true] + points_view($hid));

    case 'share_code':   // 6 digits for 10 minutes, for a pharmacy without the card link
        $in = input();
        json_out(['ok' => true] + share_code_new($hid, (int) ($in['member_id'] ?? 0), !empty($in['add']), !empty($in['view'])));

    case 'fhir':   // FHIR R4 bundle of the medicine list, allergies and readings (for ABDM health lockers and doctors' systems)
        $mid = (int) ($_GET['member_id'] ?? 0) ?: null;
        if ($mid) own('members', $mid, $hid);
        header('Content-Type: application/fhir+json; charset=utf-8');
        header('Content-Disposition: attachment; filename="aushadhbox-fhir-' . date('Y-m-d') . '.json"');
        echo json_encode(fhir_bundle($hid, $mid), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;

    case 'member_phone':   // a phone for missed-call doses and SMS reminders (family members without a smartphone)
        $in = input();
        json_out(['ok' => true] + member_phone_set($hid, (int) ($in['member_id'] ?? 0), (string) ($in['phone'] ?? ''), !empty($in['sms'])));

    case 'rural_save':   // low-data mode, village and district, health-worker (ASHA) mode
        $in = input();
        $hw = !empty($in['health_worker']) ? 1 : 0;
        $set = ['low_data' => !empty($in['low_data']) ? 1 : 0, 'district' => str_in($in, 'district', 60), 'village' => str_in($in, 'village', 80), 'health_worker' => $hw];
        q('UPDATE users SET low_data = ?, district = ?, village = ?, health_worker = ? WHERE id = ?', [$set['low_data'], $set['district'], $set['village'], $hw, $user['id']]);
        json_out(['ok' => true, 'user' => public_user($set + $user)]);

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
            'members' => q('SELECT name, relation, allergies, blood_group, conditions, emergency_contact FROM members WHERE user_id = ?', [$uid])->fetchAll(),
            'medicines' => q('SELECT c.name, m.name AS member, c.batch_no, c.mfg_month, c.exp_month, c.dose_times, c.quantity, c.notes, c.created_at FROM cabinet_items c LEFT JOIN members m ON m.id = c.member_id WHERE c.user_id = ?', [$uid])->fetchAll(),
            'doses_taken' => q('SELECT c.name, d.slot, d.taken_on, d.taken_at, d.status, d.reason, d.source FROM dose_logs d JOIN cabinet_items c ON c.id = d.item_id WHERE d.user_id = ? ORDER BY d.taken_on DESC', [$uid])->fetchAll(),
            'reminders' => q('SELECT enabled, m_time, a_time, e_time, b_time FROM reminder_settings WHERE user_id = ?', [$user['id']])->fetch(),
            'pillboxes' => q('SELECT name, compartments, firmware, battery, last_seen, created_at FROM devices WHERE user_id = ?', [$uid])->fetchAll(),
            'health_diary' => q('SELECT m.name AS member, v.kind, v.v1, v.v2, v.context, v.flag, v.note, v.taken_at FROM vitals v LEFT JOIN members m ON m.id = v.member_id WHERE v.user_id = ? ORDER BY v.taken_at DESC', [$uid])->fetchAll(),
            'follow_ups' => q('SELECT c.name AS medicine, f.due_on, f.answer, f.note, f.answered_at FROM followups f JOIN cabinet_items c ON c.id = f.item_id WHERE f.user_id = ?', [$uid])->fetchAll(),
            'vaccinations' => q('SELECT m.name AS member, v.name, v.due_on, v.given_on FROM vaccinations v JOIN members m ON m.id = v.member_id WHERE v.user_id = ?', [$uid])->fetchAll(),
            'medical_expenses' => q('SELECT m.name AS member, e.category, e.amount, e.spent_on, e.note FROM expenses e LEFT JOIN members m ON m.id = e.member_id WHERE e.user_id = ?', [$uid])->fetchAll(),
            'pharmacy_card' => q('SELECT name AS member, card_code, share_add AS pharmacies_may_add, share_view AS pharmacies_may_see FROM members WHERE user_id = ? AND card_code IS NOT NULL', [$uid])->fetchAll(),
            'pharmacy_access' => q('SELECT p.name AS pharmacy, m.name AS member, a.action, a.detail, a.at FROM pharmacy_access a JOIN pharmacies p ON p.id = a.pharmacy_id LEFT JOIN members m ON m.id = a.member_id WHERE a.user_id = ? ORDER BY a.at DESC', [$uid])->fetchAll(),
            'refill_requests' => q('SELECT p.name AS pharmacy, r.items, r.note, r.status, r.created_at FROM refill_requests r JOIN pharmacies p ON p.id = r.pharmacy_id WHERE r.user_id = ?', [$uid])->fetchAll(),
            'doctor_visits' => q('SELECT m.name AS member, a.doctor, a.place, a.at, a.questions FROM appointments a LEFT JOIN members m ON m.id = a.member_id WHERE a.user_id = ?', [$uid])->fetchAll(),
            'pillbox_openings' => q('SELECT d.name AS box, e.kind, e.compartment, e.happened_at, e.slot, e.outcome FROM device_events e JOIN devices d ON d.id = e.device_id WHERE d.user_id = ? ORDER BY e.happened_at DESC', [$uid])->fetchAll(),
        ];
        header('Content-Disposition: attachment; filename="aushadhbox-my-data.json"');
        json_out($data);

    case 'account_delete':   // DPDP: right to erasure. Everything linked to the user is removed by ON DELETE CASCADE.
        $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
        if (!password_verify((string) (input()['password'] ?? ''), $row['password_hash'])) fail('bad_password', 'Your password is not correct.', 403);
        q("INSERT INTO consent_log (user_ref, version, action) VALUES (?,?,'withdrawn')", [hash('sha256', $user['email']), $user['consent_version']]);
        q('UPDATE users SET household_id = NULL WHERE household_id = ?', [$user['id']]);   // people you shared with keep their own accounts
        // The pharmacy network forgets this household too (pharmacies keep only the anonymous sale records the drug laws require).
        if (network_on() && !$user['household_id']) { $r = network_call('forget', ['customer_ref' => customer_ref((int) $user['id'])]); if (($r['_status'] ?? 0) >= 300) error_log('network forget failed: ' . ($r['message'] ?? '')); }
        q('DELETE FROM users WHERE id = ?', [$user['id']]);
        $_SESSION = [];
        session_regenerate_id(true);
        json_out(['ok' => true]);

    /* ---------------- App data ---------------- */
    case 'bootstrap':
        $uid = $hid;
        q('UPDATE users SET last_seen_at = NOW() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 30 MINUTE)', [$user['id']]);
        $members = q('SELECT id, name, relation, allergies, is_self, birth_date, blood_group, conditions, emergency_contact FROM members WHERE user_id = ? ORDER BY is_self DESC, id', [$uid])->fetchAll();
        $items = q('SELECT id, member_id, name, catalog_id, info_id, batch_no, mfg_month, exp_month, quantity, dose_times, notes, stock_count, per_dose, course_end FROM cabinet_items WHERE user_id = ? ORDER BY exp_month', [$uid])->fetchAll();
        $today = date('Y-m-d');
        $doses = q('SELECT item_id, slot, status, reason, source, taken_at FROM dose_logs WHERE user_id = ? AND taken_on = ?', [$uid, $today])->fetchAll();
        $rem = q('SELECT enabled, m_time, a_time, e_time, b_time FROM reminder_settings WHERE user_id = ?', [$user['id']])->fetch() ?: ['enabled' => 0, 'm_time' => '08:00', 'a_time' => '13:30', 'e_time' => '19:30', 'b_time' => '22:00'];
        $kit = array_column(q('SELECT item_key FROM kit_checks WHERE user_id = ?', [$uid])->fetchAll(), 'item_key');
        json_out([
            'user' => public_user($user), 'today' => $today,
            'members' => array_map(fn($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'relation' => $m['relation'], 'allergies' => $m['allergies'], 'self' => (bool) $m['is_self'],
                                              'birthDate' => $m['birth_date'], 'bloodGroup' => $m['blood_group'], 'conditions' => $m['conditions'], 'emergencyContact' => $m['emergency_contact']], $members),
            'items' => array_map('public_item', $items),
            'taken' => array_values(array_map(fn($d) => $d['item_id'] . ':' . $d['slot'], array_filter($doses, fn($d) => $d['status'] === 'taken'))),
            'doseLog' => (object) array_column(array_map(fn($d) => ['k' => $d['item_id'] . ':' . $d['slot'], 'v' => ['status' => $d['status'], 'reason' => $d['reason'], 'source' => $d['source'], 'at' => substr($d['taken_at'], 11, 5)]], $doses), 'v', 'k'),
            'reminders' => ['enabled' => (bool) $rem['enabled'], 'm' => $rem['m_time'], 'a' => $rem['a_time'], 'e' => $rem['e_time'], 'b' => $rem['b_time']],
            'kit' => $kit,
            'billing' => billing_public($user),
            'alerts' => safety_alerts($uid),
            'followups' => followups_due($uid),
            'appointments' => appointments_list($uid),
            'pharmacy' => ['name' => $user['pharmacy_name'] ?? null, 'phone' => $user['pharmacy_phone'] ?? null],
            'vaccines' => vaccines_list($uid),
            'shares' => share_list($uid),
            'streaks' => adherence_streaks($uid),
            'tickLink' => (bool) q('SELECT tick_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn(),
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
            // Emergency-card fields are only changed when sent (older screens don't send them).
            if (array_key_exists('birth_date', $in)) {
                $bd = (string) $in['birth_date'];
                if ($bd !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) || $bd > date('Y-m-d') || $bd < '1900-01-01')) fail('bad_date', 'Choose a date of birth in the past.');
                q('UPDATE members SET birth_date = ? WHERE id = ?', [$bd ?: null, $id]);
                // A child under 17: make the government vaccination schedule by itself
                if ($bd && $bd > date('Y-m-d', strtotime('-17 year'))) vaccines_make($hid, $id, $bd);
            }
            foreach (['blood_group' => 4, 'conditions' => 300, 'emergency_contact' => 80] as $f => $max) if (array_key_exists($f, $in)) {
                $v = str_in($in, $f, $max);
                if ($f === 'blood_group' && $v !== null && !in_array(strtoupper($v), ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true)) fail('bad_blood', 'Choose a blood group from the list.');
                q("UPDATE members SET $f = ? WHERE id = ?", [$f === 'blood_group' && $v ? strtoupper($v) : $v, $id]);
            }
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
        $id = save_item(input(), $user, $hid);
        json_out(['ok' => true, 'item' => public_item(q('SELECT * FROM cabinet_items WHERE id = ?', [$id])->fetch())]);

    case 'items_bulk_save':   // everything read from one prescription or pharmacy bill, saved in one go
        $in = input();
        $list = is_array($in['items'] ?? null) ? array_slice($in['items'], 0, 40) : [];
        if (!$list) fail('empty', 'Nothing to save.');
        $lim = plan_info($user)['limits']['items'];
        $have = (int) q('SELECT COUNT(*) FROM cabinet_items WHERE user_id = ?', [$hid])->fetchColumn();
        if ($have + count($list) > $lim) fail('limit_items', "The Free plan holds $lim medicines. You have $have. Save fewer, or upgrade to Family Premium for unlimited medicines.", 402);
        db()->beginTransaction();
        $ids = [];
        foreach ($list as $one) { if (is_array($one)) $ids[] = save_item($one, $user, $hid); }
        // A scanned pharmacy bill: its total goes into medical expenses by itself
        $bill = is_array($in['bill'] ?? null) ? $in['bill'] : null;
        if ($bill && (float) ($bill['total'] ?? 0) > 0) expense_add($hid, ['category' => 'medicine', 'amount' => $bill['total'], 'spent_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($bill['date'] ?? '')) && $bill['date'] <= date('Y-m-d') ? $bill['date'] : date('Y-m-d'),
            'member_id' => $bill['member_id'] ?? null, 'note' => 'Pharmacy bill: ' . mb_substr(implode(', ', array_map(fn($x) => (string) ($x['name'] ?? ''), $list)), 0, 140)], 'bill');
        db()->commit();
        $rows = $ids ? q('SELECT * FROM cabinet_items WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll() : [];
        json_out(['ok' => true, 'items' => array_map('public_item', $rows)]);

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
        if (!empty($in['taken'])) dose_mark($hid, $id, $slot, $today, 'taken', null, 'app');
        else dose_unmark($hid, $id, $slot, $today);
        $st = q('SELECT stock_count FROM cabinet_items WHERE id = ?', [$id])->fetchColumn();
        $pts = !empty($in['taken']) ? points_check_all_doses($hid) : 0;
        json_out(['ok' => true, 'stock' => $st === null ? null : (int) $st, 'points' => $pts]);

    case 'dose_slot_taken':   // "Taken" on a reminder notification: tick every dose due in that time slot today
        $in = input();
        $slot = (string) ($in['slot'] ?? '');
        if (!in_array($slot, SLOTS, true)) fail('bad_slot', 'Unknown time of day.');
        $today = date('Y-m-d');
        $src = ($in['source'] ?? '') === 'notif' ? 'notif' : 'app';
        $n = 0;
        foreach (due_items($hid, $slot, $today) as $i) {
            if (q("SELECT 1 FROM dose_logs WHERE item_id = ? AND slot = ? AND taken_on = ? AND status = 'skipped'", [$i['id'], $slot, $today])->fetch()) continue;   // a deliberate skip stays a skip
            if (dose_mark($hid, (int) $i['id'], $slot, $today, 'taken', null, $src)) $n++;
        }
        json_out(['ok' => true, 'ticked' => $n]);

    case 'dose_skip':   // "Skip" with a reason, for one medicine or for everything due in a time slot
        $in = input();
        $slot = (string) ($in['slot'] ?? '');
        $reason = (string) ($in['reason'] ?? '');
        if (!in_array($slot, SLOTS, true)) fail('bad_slot', 'Unknown time of day.');
        if (!in_array($reason, SKIP_REASONS, true)) fail('bad_reason', 'Choose why the dose was skipped.');
        $today = date('Y-m-d');
        $id = (int) ($in['item_id'] ?? 0);
        if ($id) { own('cabinet_items', $id, $hid); $ids = [$id]; }
        else $ids = array_map(fn($i) => (int) $i['id'], array_filter(due_items($hid, $slot, $today), fn($i) => !q("SELECT 1 FROM dose_logs WHERE item_id = ? AND slot = ? AND taken_on = ? AND status = 'taken'", [$i['id'], $slot, $today])->fetch()));
        foreach ($ids as $x) { dose_mark($hid, $x, $slot, $today, 'skipped', $reason, ($in['source'] ?? '') === 'notif' ? 'notif' : 'app'); skip_effects($x, $reason, $today); }
        $items = $ids ? q('SELECT * FROM cabinet_items WHERE id IN (' . implode(',', $ids) . ')')->fetchAll() : [];
        json_out(['ok' => true, 'skipped' => count($ids), 'items' => array_map('public_item', $items)]);

    case 'adherence':   // taken / skipped / missed per person, last N full days (default 7)
        $days = max(1, min(90, (int) ($_GET['days'] ?? 7)));
        json_out(adherence($hid, date('Y-m-d', strtotime('-' . $days . ' day')), date('Y-m-d', strtotime('-1 day'))));

    /* ---------------- Smart pillbox ---------------- */
    case 'device_list':
        json_out(['devices' => array_map('device_public', q('SELECT * FROM devices WHERE user_id = ? ORDER BY id', [$hid])->fetchAll()),
                  'events' => q("SELECT e.device_id deviceId, e.kind, e.compartment lid, e.happened_at at, e.slot, e.outcome FROM device_events e JOIN devices d ON d.id = e.device_id
                                 WHERE d.user_id = ? AND e.kind = 'open' ORDER BY e.happened_at DESC, e.id DESC LIMIT 12", [$hid])->fetchAll()]);

    case 'device_add':
    case 'device_update':
        $in = input();
        $name = str_in($in, 'name', 40, true);
        $mid = (int) ($in['member_id'] ?? 0) ?: null;
        if ($mid) own('members', $mid, $hid);
        $comp = (int) ($in['compartments'] ?? 4) === 1 ? 1 : 4;
        if ($action === 'device_update') {
            $id = (int) ($in['id'] ?? 0); own('devices', $id, $hid);
            q('UPDATE devices SET name = ?, member_id = ?, compartments = ? WHERE id = ?', [$name, $mid, $comp, $id]);
        } else {
            if ((int) q('SELECT COUNT(*) FROM devices WHERE user_id = ?', [$hid])->fetchColumn() >= 10) fail('limit_devices', 'You can connect up to 10 pillboxes and sensors.');
            $kind = ($in['kind'] ?? '') === 'life' ? 'life' : 'pillbox';
            if ($kind === 'life' && !$mid) fail('need_member', 'Choose whose home the sensor is in.');
            q('INSERT INTO devices (user_id, member_id, name, compartments, kind) VALUES (?,?,?,?,?)', [$hid, $mid, $name, $kind === 'life' ? 1 : $comp, $kind]);
            $id = (int) db()->lastInsertId();
            device_new_code($id);
        }
        json_out(['ok' => true, 'device' => device_public(q('SELECT * FROM devices WHERE id = ?', [$id])->fetch())]);

    case 'device_code':   // a fresh pairing code (also unpairs the box, e.g. after a factory reset)
        $id = (int) (input()['id'] ?? 0); own('devices', $id, $hid);
        q('UPDATE devices SET token_hash = NULL WHERE id = ?', [$id]);
        device_new_code($id);
        json_out(['ok' => true, 'device' => device_public(q('SELECT * FROM devices WHERE id = ?', [$id])->fetch())]);

    case 'device_delete':
        $id = (int) (input()['id'] ?? 0); own('devices', $id, $hid);
        q('DELETE FROM devices WHERE id = ?', [$id]);
        json_out(['ok' => true]);

    case 'device_test':   // "Try without hardware": behaves exactly as if the box reported this lid opening now
        $in = input();
        $id = (int) ($in['id'] ?? 0); own('devices', $id, $hid);
        $d = q('SELECT * FROM devices WHERE id = ?', [$id])->fetch();
        $outcome = box_event($d, 'open', max(1, min(4, (int) ($in['lid'] ?? 1))), new DateTime());
        json_out(['ok' => true, 'outcome' => $outcome]);

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

    case 'catalog_match_many':   // best medicine-list match for each name read from a prescription or bill
        $names = array_slice(array_filter((array) (input()['names'] ?? []), 'is_string'), 0, 40);
        $out = [];
        foreach ($names as $n) {
            $r = match_label(mb_substr($n, 0, 200), 1);
            $out[] = ['query' => $n, 'match' => $r['matches'][0] ?? null, 'info' => $r['info']];
        }
        json_out(['results' => $out]);

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
            q('INSERT INTO members (user_id, name, relation, account_id) VALUES (?,?,?,?)', [$owner['id'], $user['name'], 'Family', $user['id']]);
        }
        q('UPDATE members SET account_id = ? WHERE user_id = ? AND name = ? AND account_id IS NULL', [$user['id'], $owner['id'], $user['name']]);
        db()->commit();
        log_attempt('join', true);
        json_out(['ok' => true, 'owner' => $owner['name']]);

    case 'household_leave':
        if (is_owner($user)) fail('is_owner', 'You own this cabinet. Remove other people instead.');
        q('UPDATE users SET household_id = NULL WHERE id = ?', [$user['id']]);
        q('UPDATE members SET account_id = NULL WHERE account_id = ? AND user_id <> ?', [$user['id'], $user['id']]);
        json_out(['ok' => true]);

    case 'household_remove':
        if (!is_owner($user)) fail('not_owner', 'Only the cabinet owner can remove people.', 403);
        $target = (int) (input()['user_id'] ?? 0);
        $n = q('UPDATE users SET household_id = NULL WHERE id = ? AND household_id = ?', [$target, $user['id']])->rowCount();
        if (!$n) fail('not_found', 'That person is not in your family cabinet.', 404);
        q('UPDATE members SET account_id = NULL WHERE account_id = ? AND user_id = ?', [$target, $user['id']]);
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
        $mid = (int) db()->lastInsertId();
        log_attempt('contact', true);
        $tr = null;
        try { $tr = support_process($mid); } catch (Throwable $e) { error_log('support: ' . $e->getMessage()); }   // sorting must never lose a message
        if (mail_enabled() && config('support_email')) send_mail((string) config('support_email'), '[' . ticket_no($mid) . ($tr ? '/' . $tr['category'] . '/' . $tr['priority'] : '') . "] $name", "From: $name <$email>\nTopic: $topic\n\n$msg");
        json_out(['ok' => true, 'ticket' => ticket_no($mid)]);

    /* ---------------- Smart helpers for families ---------------- */
    case 'safety_check':
        json_out(['alerts' => safety_alerts($hid)]);

    case 'assistant_ask':
        rate_limit('assistant', null, 40, 60);
        log_attempt('assistant', false);   // counts towards the limit (40 questions an hour)
        json_out(assistant_answer($user, $hid, (string) (input()['q'] ?? '')));

    /* ---------------- Ideas from Japanese medication-notebook apps ---------------- */
    case 'vital_save':
        $r = vital_save($hid, input());
        json_out(['ok' => true, 'points' => points_award($hid, (input()['kind'] ?? '') === 'steps' ? 'steps' : 'reading')] + $r);

    case 'vitals':
        json_out(['vitals' => vitals_list($hid, (int) ($_GET['member_id'] ?? 0) ?: null, max(7, min(365, (int) ($_GET['days'] ?? 90))))]);

    case 'vital_delete':
        q('DELETE FROM vitals WHERE id = ? AND user_id = ?', [(int) (input()['id'] ?? 0), $hid]);
        json_out(['ok' => true]);

    case 'followup_answer':
        $in = input();
        $r = followup_answer($hid, (int) ($in['id'] ?? 0), (string) ($in['answer'] ?? ''), (string) ($in['note'] ?? ''));
        points_award($hid, 'followup', 'fu' . (int) ($in['id'] ?? 0));
        json_out($r);

    case 'appointment_save':
        $in = input();
        $doctor = str_in($in, 'doctor', 80, true);
        $at = (string) ($in['at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $at) || strtotime($at) === false) fail('bad_time', 'Choose the date and time of the visit.');
        $mid = (int) ($in['member_id'] ?? 0) ?: null;
        if ($mid) own('members', $mid, $hid);
        $id = (int) ($in['id'] ?? 0);
        $vals = [$mid, $doctor, str_in($in, 'place', 120), str_replace('T', ' ', $at) . ':00', str_in($in, 'questions', 500)];
        if ($id) q('UPDATE appointments SET member_id = ?, doctor = ?, place = ?, at = ?, questions = ? WHERE id = ? AND user_id = ?', array_merge($vals, [$id, $hid]));
        else {
            if ((int) q('SELECT COUNT(*) FROM appointments WHERE user_id = ? AND at >= NOW()', [$hid])->fetchColumn() >= 30) fail('limit', 'You have 30 upcoming visits already.');
            q('INSERT INTO appointments (member_id, doctor, place, at, questions, user_id) VALUES (?,?,?,?,?,?)', array_merge($vals, [$hid]));
        }
        json_out(['ok' => true, 'appointments' => appointments_list($hid)]);

    case 'appointment_delete':
        q('DELETE FROM appointments WHERE id = ? AND user_id = ?', [(int) (input()['id'] ?? 0), $hid]);
        json_out(['ok' => true, 'appointments' => appointments_list($hid)]);

    case 'visit_sheet':
        json_out(visit_sheet($hid, (int) ($_GET['member_id'] ?? 0) ?: null));

    case 'pharmacy_save':
        $in = input();
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) $phone = substr($phone, 2);
        if ($phone !== '' && !preg_match('/^[6-9]\d{9}$/', $phone)) fail('bad_phone', 'Enter the pharmacy\'s 10-digit WhatsApp number.');
        q('UPDATE users SET pharmacy_name = ?, pharmacy_phone = ? WHERE id = ?', [str_in($in, 'name', 80), $phone ?: null, $user['id']]);
        json_out(['ok' => true, 'pharmacy' => ['name' => str_in($in, 'name', 80), 'phone' => $phone ?: null]]);

    /* ---------------- Household extras (like EPARK お薬手帳) ---------------- */
    case 'vaccine_given':
        $in = input();
        $d = (string) ($in['date'] ?? date('Y-m-d'));
        if (!empty($in['given']) && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > date('Y-m-d'))) fail('bad_date', 'Choose the date it was given.');
        q('UPDATE vaccinations SET given_on = ? WHERE id = ? AND user_id = ?', [!empty($in['given']) ? $d : null, (int) ($in['id'] ?? 0), $hid]);
        if (!empty($in['given'])) points_award($hid, 'vaccine', 'vx' . (int) ($in['id'] ?? 0));
        json_out(['ok' => true, 'vaccines' => vaccines_list($hid)]);

    case 'vaccines_mark_past':   // "already given": everything due before today for this child
        $mid = (int) (input()['member_id'] ?? 0); own('members', $mid, $hid);
        q('UPDATE vaccinations SET given_on = due_on WHERE user_id = ? AND member_id = ? AND given_on IS NULL AND due_on < CURDATE()', [$hid, $mid]);
        json_out(['ok' => true, 'vaccines' => vaccines_list($hid)]);

    case 'expense_add':
        expense_add($hid, input());
        json_out(['ok' => true] + expenses_summary($hid));

    case 'expense_delete':
        q('DELETE FROM expenses WHERE id = ? AND user_id = ?', [(int) (input()['id'] ?? 0), $hid]);
        json_out(['ok' => true] + expenses_summary($hid));

    case 'expenses':
        json_out(expenses_summary($hid, preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : null));

    case 'expenses_csv':
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : null;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="medical-expenses-' . str_replace(' ', '', fin_year($date)[2]) . '.csv"');
        echo "\xEF\xBB\xBF" . expenses_csv($hid, $date);   // BOM so Excel shows ₹ and Indian names correctly
        exit;

    case 'share_create':
        $in = input();
        $mid = (int) ($in['member_id'] ?? 0) ?: null;
        if ($mid) own('members', $mid, $hid);
        json_out(['ok' => true] + share_create($hid, $mid, (int) ($in['hours'] ?? 24)) + ['shares' => share_list($hid)]);

    case 'share_revoke':
        q('UPDATE share_links SET expires_at = NOW() WHERE id = ? AND user_id = ?', [(int) (input()['id'] ?? 0), $hid]);
        json_out(['ok' => true, 'shares' => share_list($hid)]);

    /* ---------------- AushadhBox card (pharmacy link, the family decides) ---------------- */
    case 'cards':
        json_out(cards_public($hid) + ['pharmacies' => linked_pharmacies($hid)]);

    case 'card_new':   // first card, or "New card" when one is lost: the old code stops working at once
        $mid = (int) (input()['member_id'] ?? 0); own('members', $mid, $hid);
        card_new($hid, $mid);
        json_out(cards_public($hid));

    case 'card_share':
        $in = input(); $mid = (int) ($in['member_id'] ?? 0); own('members', $mid, $hid);
        q('UPDATE members SET share_add = ?, share_view = ? WHERE id = ?', [!empty($in['add']) ? 1 : 0, !empty($in['view']) ? 1 : 0, $mid]);
        if ((!empty($in['add']) || !empty($in['view'])) && !q('SELECT card_code FROM members WHERE id = ?', [$mid])->fetchColumn()) card_new($hid, $mid);
        json_out(cards_public($hid));

    case 'pharmacy_block':
        $in = input(); $mid = (int) ($in['member_id'] ?? 0); own('members', $mid, $hid);
        q('UPDATE pharmacy_links SET blocked = ? WHERE member_id = ? AND pharmacy_id = ?', [!empty($in['blocked']) ? 1 : 0, $mid, (int) ($in['pharmacy_id'] ?? 0)]);
        json_out(cards_public($hid) + ['pharmacies' => linked_pharmacies($hid)]);

    case 'refill_request':   // "keep these ready" to a pharmacy the family's card was used at: becomes a real order at that pharmacy
        $in = input();
        $phId = (int) ($in['pharmacy_id'] ?? 0);
        $ph = array_values(array_filter(linked_pharmacies($hid), fn($r) => (int) $r['id'] === $phId))[0] ?? null;
        if (!$ph) fail('not_linked', 'Use your AushadhBox card at this pharmacy once first.', 403);
        $items = array_values(array_filter(array_map(fn($x) => is_array($x) ? ['name' => mb_substr(trim((string) ($x['name'] ?? '')), 0, 120), 'qty' => max(0, min(999, (int) ($x['qty'] ?? 0)))] : null, array_slice((array) ($in['items'] ?? []), 0, 30)), fn($x) => $x && $x['name'] !== ''));
        if (!$items) fail('empty', 'Add at least one medicine.');
        if ((int) q("SELECT COUNT(*) FROM refill_requests WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 DAY", [$hid])->fetchColumn() >= 10) fail('limit', 'You sent many requests today. Please call the pharmacy.');
        $mid = (int) ($in['member_id'] ?? 0) ?: null; if ($mid) own('members', $mid, $hid);
        $number = null;
        if (network_on() && $ph['code']) {
            $o = network_or_fail(network_call('order_create', ['customer_ref' => customer_ref($hid), 'code' => $ph['code'], 'source' => 'refill', 'kind' => 'pickup',
                'items' => array_map(fn($x) => ['name' => $x['name'], 'qty' => max(1, $x['qty'])], $items), 'note' => str_in($in, 'note', 300),
                'customer' => ['name' => (string) $user['name'], 'district' => $user['district'] ?? null]]));
            $number = $o['number'] ?? null;
        }
        q('INSERT INTO refill_requests (user_id, member_id, pharmacy_id, items, note, network_order) VALUES (?,?,?,?,?,?)', [$hid, $mid, $phId, json_encode($items, JSON_UNESCAPED_UNICODE), str_in($in, 'note', 300), $number]);
        json_out(['ok' => true, 'order' => $number] + cards_public($hid));

    /* ---------------- Pharmacy network: shops, nearby stock, orders, payments, khata, points ---------------- */
    case 'net_status':
        json_out(['on' => network_on(), 'portal' => network_on() ? network_url() . '/app' : null,
                  'mine' => q('SELECT code, name, village FROM my_pharmacies WHERE user_id = ? ORDER BY added_at DESC', [$hid])->fetchAll(),
                  'district' => $user['district'] ?? null, 'village' => $user['village'] ?? null]);
    case 'net_store':   // after scanning a shop's QR
        $in = input(); $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', preg_replace('#^.*/s/#', '', (string) ($in['code'] ?? ''))));
        $r = network_or_fail(network_call('store', ['code' => $code, 'customer_ref' => customer_ref($hid), 'lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null]));
        q('INSERT INTO my_pharmacies (user_id, code, name, village) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), village = VALUES(village)', [$hid, $r['code'], mb_substr((string) $r['name'], 0, 120), $r['village'] ?? null]);
        json_out($r);
    case 'net_forget_store':
        q('DELETE FROM my_pharmacies WHERE user_id = ? AND code = ?', [$hid, (string) (input()['code'] ?? '')]); json_out(['ok' => true]);
    case 'net_find':   // which pharmacies near me have these medicines
        $in = input();
        if (!empty($in['district'])) q('UPDATE users SET district = ? WHERE id = ?', [mb_substr((string) $in['district'], 0, 60), $user['id']]);
        json_out(network_or_fail(network_call('availability', ['customer_ref' => customer_ref($hid), 'items' => (array) ($in['items'] ?? []), 'lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null,
                                                               'district' => $in['district'] ?? ($user['district'] ?? null), 'radius_km' => $in['radius_km'] ?? 25])));
    case 'net_quote':
        $in = input(); json_out(network_or_fail(network_call('quote', ['code' => $in['code'] ?? '', 'lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null, 'items' => (array) ($in['items'] ?? []), 'customer_pays_full' => !empty($in['customer_pays_full'])])));
    case 'net_order':   // the user agreed to share name, phone and (for delivery) address with this pharmacy for this order
        $in = input();
        if (empty($in['share_ok'])) fail('need_consent', 'Agree to share your name and phone with the pharmacy for this order.');
        $who = (array) ($in['customer'] ?? []);
        $who['name'] = mb_substr(trim((string) ($who['name'] ?? $user['name'])), 0, 80); $who['district'] = $who['district'] ?? ($user['district'] ?? null);
        // Dose times from the cabinet go with each medicine, so the pharmacy can pack each dose time in its own pouch (一包化).
        $items = array_map(function ($it) use ($hid) {
            if (!is_array($it)) return $it;
            $t = q("SELECT dose_times FROM cabinet_items WHERE user_id = ? AND name = ? AND dose_times <> '' LIMIT 1", [$hid, (string) ($it['name'] ?? '')])->fetchColumn();
            return $it + ['times' => $t ? explode(',', $t) : []];
        }, array_slice((array) ($in['items'] ?? []), 0, 30));
        // A repeat prescription: the pharmacy sees who prescribed it and which refill this is; the use is counted when the order is accepted here.
        $note = $in['note'] ?? null; $rxDate = null; $rep = null;
        if (!empty($in['rx_repeat_id'])) {
            $rep = q('SELECT * FROM rx_repeats WHERE id = ? AND user_id = ?', [(int) $in['rx_repeat_id'], $hid])->fetch();
            if (!$rep) fail('not_found', 'Prescription not found.', 404);
            if ($rep['valid_until'] < date('Y-m-d') || (int) $rep['repeats_used'] >= (int) $rep['repeats_allowed']) fail('used_up', 'This prescription is used up or expired. Ask the doctor for a new one.');
            $rxDate = $rep['issued_on'];
            $note = trim('Repeat prescription by ' . $rep['doctor'] . ' (' . $rep['issued_on'] . '), refill ' . ((int) $rep['repeats_used'] + 1) . ' of ' . $rep['repeats_allowed'] . '. ' . (string) $note);
        }
        $r = network_or_fail(network_call('order_create', ['customer_ref' => customer_ref($hid), 'code' => $in['code'] ?? '', 'items' => $items, 'kind' => $in['kind'] ?? 'pickup',
            'pickup_point_id' => $in['pickup_point_id'] ?? null, 'note' => $note, 'customer' => $who, 'referral_code' => $in['referral_code'] ?? null, 'rx_date' => $rxDate,
            'customer_pays_full' => !empty($in['customer_pays_full']), 'source' => !empty($in['for_household']) ? 'asha' : ($rep ? 'refill' : 'app'),
            'dose_pack' => !empty($in['dose_pack']), 'pickup_at' => $in['pickup_at'] ?? null, 'service' => in_array($in['service'] ?? '', ['home_visit', 'review'], true) ? $in['service'] : null]));
        if ($rep && !empty($r['number'])) rx_repeat_use($hid, (int) $rep['id']);
        audit($user, 'network_order', ($r['number'] ?? '') . ' at ' . ($in['code'] ?? ''));
        if (!empty($r['number'])) q('INSERT IGNORE INTO network_orders (user_id, number, pharmacy, otp) VALUES (?,?,?,?)', [$hid, $r['number'], mb_substr((string) ($r['pharmacy']['name'] ?? ''), 0, 120), $r['otp'] ?? null]);
        json_out($r);
    case 'net_orders':
        $r = network_or_fail(network_call('orders', ['customer_ref' => customer_ref($hid)]));
        $otps = array_column(q('SELECT number, otp FROM network_orders WHERE user_id = ?', [$hid])->fetchAll(), 'otp', 'number');
        foreach ($r['orders'] as &$o) $o['otp'] = in_array($o['kind'], ['delivery', 'point'], true) && !in_array($o['status'], ['delivered', 'collected', 'cancelled'], true) ? ($otps[$o['number']] ?? null) : null;
        unset($o);
        json_out($r);
    case 'net_cancel':    json_out(network_or_fail(network_call('order_cancel', ['customer_ref' => customer_ref($hid), 'number' => input()['number'] ?? ''])));
    case 'net_pay_claim': $in = input(); json_out(network_or_fail(network_call('pay_claim', ['customer_ref' => customer_ref($hid)] + array_intersect_key($in, array_flip(['number', 'method', 'utr', 'amount', 'note'])))));
    case 'net_khata_pay': $in = input(); json_out(network_or_fail(network_call('khata_pay_claim', ['customer_ref' => customer_ref($hid)] + array_intersect_key($in, array_flip(['code', 'method', 'utr', 'amount'])))));
    case 'net_wallet':    json_out(network_or_fail(network_call('wallet', ['customer_ref' => customer_ref($hid)])));
    case 'net_link_start':   json_out(network_or_fail(network_call('link_start', ['customer_ref' => customer_ref($hid), 'phone' => input()['phone'] ?? ''])));
    case 'net_link_confirm': $in = input(); json_out(network_or_fail(network_call('link_confirm', ['customer_ref' => customer_ref($hid), 'phone' => $in['phone'] ?? '', 'code' => $in['code'] ?? ''])));
    case 'net_referral':  $in = input(); json_out(network_or_fail(network_call('referral_join', ['customer_ref' => customer_ref($hid), 'code' => $in['code'] ?? '', 'referral_code' => $in['referral_code'] ?? '', 'payout' => $in['payout'] ?? 'points'])));
    case 'net_complaint': $in = input(); json_out(network_or_fail(network_call('complaint', ['customer_ref' => customer_ref($hid)] + array_intersect_key($in, array_flip(['code', 'number', 'topic', 'body'])))));

    /* ---------------- Smartwatch and "tap to tick" ---------------- */
    case 'tick_link':   // a secret link that ticks the doses due now: Siri / Google / Bixby shortcuts, NFC tag, QR sticker, watch
        $in = input();
        if (!empty($in['revoke'])) { q('UPDATE users SET tick_hash = NULL WHERE id = ?', [$user['id']]); json_out(['ok' => true, 'url' => null]); }
        json_out(['ok' => true, 'url' => tick_link_new((int) $user['id'])]);

    case 'watch_tick':   // the big "Taken" button on the watch page
        json_out(tick_now($hid, 'watch'));

    case 'watch_status':   // what the watch page shows: the dose time due now and the next one
        json_out(watch_status($hid, (int) $user['id']));

    case 'vitals_import':   // readings exported from a watch or health app (Apple Health, Samsung Health, Fitbit, Google Fit)
        $rows = is_array(input()['readings'] ?? null) ? input()['readings'] : [];
        $mid = (int) (input()['member_id'] ?? 0) ?: null; if ($mid) own('members', $mid, $hid);
        json_out(vitals_import($hid, $mid, $rows));

    /* ---------------- Barcodes learned from scans ---------------- */
    case 'barcode_lookup':
        $code = preg_replace('/\D/', '', (string) ($_GET['code'] ?? ''));
        $r = strlen($code) >= 8 ? q('SELECT name, catalog_id, info_id FROM barcodes WHERE code = ?', [$code])->fetch() : null;
        json_out(['found' => (bool) $r, 'name' => $r['name'] ?? null, 'catalogId' => isset($r['catalog_id']) ? (int) $r['catalog_id'] : null, 'info' => $r ? info_by_id($r['info_id'] ? (int) $r['info_id'] : null) : null]);

    case 'ai_opt_in':
        $on = !empty(input()['on']) ? 1 : 0;
        q('UPDATE users SET ai_opt_in = ? WHERE id = ?', [$on, $user['id']]);
        json_out(['ok' => true, 'aiOptIn' => (bool) $on, 'available' => ai_enabled()]);

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
        $rows = q("SELECT id, name, email, topic, message, handled, created_at, category, priority, sentiment, due_at, suggested, reply, replied_at FROM contact_messages
                   ORDER BY handled, FIELD(priority, 'high', 'normal', 'low'), due_at, id DESC LIMIT 100")->fetchAll();
        foreach ($rows as &$r) { $r['ticket'] = ticket_no((int) $r['id']); $r['overdue'] = !$r['handled'] && $r['due_at'] && $r['due_at'] < date('Y-m-d H:i:s'); }
        unset($r);
        json_out(['messages' => $rows, 'mail' => mail_enabled()]);

    case 'admin_message_reply':   // send the (edited) drafted reply and close the ticket
        require_admin($user);
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $text = trim((string) ($in['reply'] ?? ''));
        $m = q('SELECT * FROM contact_messages WHERE id = ?', [$id])->fetch();
        if (!$m) fail('not_found', 'Message not found.', 404);
        if (mb_strlen($text) < 5) fail('empty', 'Write a reply first.');
        $sent = mail_enabled() && send_mail((string) $m['email'], 'Re: your message · ' . ticket_no($id) . ' · ' . site_title(), $text . "\n\n---\nYou wrote:\n" . mb_substr((string) $m['message'], 0, 1500));
        q('UPDATE contact_messages SET reply = ?, replied_at = NOW(), handled = 1 WHERE id = ?', [$text, $id]);
        audit($user, 'support_reply', ticket_no($id) . ($sent ? ' (e-mailed)' : ' (saved, e-mail off)'));
        json_out(['ok' => true, 'emailed' => $sent, 'mailto' => $sent ? null : 'mailto:' . rawurlencode((string) $m['email']) . '?subject=' . rawurlencode('Re: your message · ' . ticket_no($id)) . '&body=' . rawurlencode($text)]);

    case 'admin_message_retriage':
        require_admin($user);
        json_out(['ok' => true] + (support_process((int) (input()['id'] ?? 0), false) ?? []));

    /* ---------------- Admin autopilot ---------------- */
    case 'admin_autopilot':
        require_admin($user);
        json_out([
            'insights' => q('SELECT id, day, kind, metric, severity, title, body FROM insights WHERE dismissed = 0 AND day >= CURDATE() - INTERVAL 14 DAY ORDER BY day DESC, FIELD(severity, \'high\', \'medium\', \'low\', \'good\'), id DESC LIMIT 30')->fetchAll(),
            'health' => health_checks(),
            'growth' => growth_segments(8),
            'ai' => ai_usage(),
            'settings' => ['digest_on' => state_get('digest_on', '1') === '1', 'nudges_on' => state_get('nudges_on', '1') === '1', 'ack_on' => state_get('ack_on', '1') === '1',
                           'ai_on' => state_get('ai_on', '1') === '1', 'autoheal_on' => state_get('autoheal_on', '1') === '1'],
            'log' => q('SELECT kind, title, created_at FROM autopilot_log ORDER BY id DESC LIMIT 25')->fetchAll(),
            'audit' => q('SELECT a.action, a.detail, a.created_at, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 20')->fetchAll(),
            'lastRun' => state_get('autopilot_last_run'), 'indexnow' => state_get('indexnow_last'),
        ]);

    case 'admin_ask':
        require_admin($user);
        rate_limit('admin_ask', null, 60, 60); log_attempt('admin_ask', false);
        json_out(ask_data((string) (input()['q'] ?? '')));

    case 'admin_settings':
        require_admin($user);
        $in = input(); $changed = [];
        foreach (['digest_on', 'nudges_on', 'ack_on', 'ai_on', 'autoheal_on'] as $k) if (array_key_exists($k, $in)) { state_set($k, $in[$k] ? '1' : '0'); $changed[] = "$k=" . ($in[$k] ? 'on' : 'off'); }
        audit($user, 'autopilot_settings', implode(', ', $changed));
        json_out(['ok' => true]);

    case 'admin_run':   // "Run the autopilot now": checks, repairs, anomaly scan and growth tips
        require_admin($user);
        $fixed = self_heal();
        $anom = detect_anomalies();
        $tips = growth_insights();
        state_set('autopilot_last_run', date('Y-m-d H:i:s'));
        audit($user, 'autopilot_run', count($fixed) . ' fixes, ' . count($anom) . ' anomalies');
        json_out(['ok' => true, 'fixed' => $fixed, 'anomalies' => count($anom), 'tips' => count($tips)]);

    case 'admin_insight_dismiss':
        require_admin($user);
        q('UPDATE insights SET dismissed = 1 WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        json_out(['ok' => true]);

    case 'admin_digest':   // preview of the daily digest e-mail
        require_admin($user);
        json_out(build_digest());

    case 'admin_pharmacies':   // pharmacies from the network that have used family cards on this site
        require_admin($user);
        json_out(['network' => network_on() ? network_url() : null, 'pharmacies' => q("SELECT p.id, p.code, p.name, p.license_no, p.city, p.district, p.phone, p.status, p.local_block, p.created_at,
                                       (SELECT COUNT(*) FROM pharmacy_links l WHERE l.pharmacy_id = p.id) patients,
                                       (SELECT COUNT(*) FROM cabinet_items c WHERE c.pharmacy_id = p.id) dispensed
                                     FROM pharmacies p ORDER BY p.local_block DESC, p.id DESC LIMIT 200")->fetchAll()]);

    case 'admin_pharmacy_status':   // local kill switch: stop a pharmacy using family cards on this site (approval itself happens on the network)
        require_admin($user);
        $in = input(); $block = ($in['status'] ?? '') === 'suspended';
        $ph = q('SELECT * FROM pharmacies WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch();
        if (!$ph) fail('not_found', 'Pharmacy not found.', 404);
        q("UPDATE pharmacies SET local_block = ?, status = ? WHERE id = ?", [$block ? 1 : 0, $block ? 'suspended' : 'approved', $ph['id']]);
        audit($user, 'pharmacy_' . ($block ? 'blocked' : 'unblocked'), $ph['name'] . ' (' . $ph['license_no'] . ')');
        json_out(['ok' => true]);

    case 'admin_indexnow':
        require_admin($user);
        $r = indexnow_submit(true);
        audit($user, 'indexnow', json_encode($r));
        json_out(['ok' => true] + $r);

    case 'admin_message_done':
        require_admin($user);
        q('UPDATE contact_messages SET handled = 1 WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        audit($user, 'support_done', ticket_no((int) (input()['id'] ?? 0)));
        json_out(['ok' => true]);

    case 'admin_import':
        require_admin($user);
        $in = input();
        $source = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($in['source'] ?? 'import')));
        $rows = parse_list_file((string) ($in['content'] ?? ''));
        if (!$rows) fail('empty', 'No rows found. Use CSV (drug_code,generic_name,unit_size) or pipe format.');
        if (count($rows) > 20000) fail('too_big', 'Import at most 20,000 rows at a time.');
        audit($user, 'catalog_import', "$source: " . count($rows) . ' rows');
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
/* Validates and saves one medicine (new or edited). Expiry is optional: a prescription doesn't print one. */
function save_item(array $in, array $user, int $hid): int
{
    $name = str_in($in, 'name', 120, true);
    $expIn = str_in($in, 'exp', 7);
    $exp = $expIn !== null ? month_to_date($expIn) : null;
    if ($expIn !== null && !$exp) fail('bad_exp', 'Enter the expiry month and year printed on the pack.');
    $mfg = month_to_date(str_in($in, 'mfg', 7));
    if ($mfg && $exp && $mfg > $exp) fail('bad_dates', 'The manufacturing date must be before the expiry date.');
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
        return $id;
    }
    $lim = plan_info($user)['limits']['items'];
    if ((int) q('SELECT COUNT(*) c FROM cabinet_items WHERE user_id = ?', [$hid])->fetch()['c'] >= $lim) fail('limit_items', $lim < PREMIUM_LIMITS['items'] ? "The Free plan holds $lim medicines. Remove expired ones, or upgrade to Family Premium for unlimited medicines." : "Your cabinet is full ($lim medicines).", 402);
    q('INSERT INTO cabinet_items (member_id, name, catalog_id, info_id, batch_no, mfg_month, exp_month, quantity, dose_times, notes, stock_count, per_dose, course_end, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$hid]));
    $newId = (int) db()->lastInsertId();
    // A pack scanned with its barcode: remember it, so the next family scanning the same pack gets it filled in.
    $bc = preg_replace('/\D/', '', (string) ($in['barcode'] ?? ''));
    if (strlen($bc) >= 8 && strlen($bc) <= 14) q('INSERT INTO barcodes (code, name, catalog_id, info_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE uses = uses + 1, name = IF(uses < 3, VALUES(name), name), catalog_id = COALESCE(VALUES(catalog_id), catalog_id), info_id = COALESCE(VALUES(info_id), info_id)',
                                                [$bc, $name, $catalog, $infoId]);
    if ($vals[8] !== '') followups_schedule($hid, $newId, $courseEnd);   // a new medicine taken on a schedule: check in on day 3 and day 10
    return $newId;
}

function lang_in($v): string
{
    return is_string($v) && in_array($v, LANGS, true) ? $v : 'en';
}

function public_user(array $u): array
{
    $pi = plan_info($u);
    return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'language' => $u['language'],
            'admin' => $u['role'] === 'admin', 'consentCurrent' => $u['consent_version'] === config('consent_version'),
            'plan' => $pi['plan'], 'planUntil' => $pi['until'], 'owner' => $pi['owner'], 'weeklyEmail' => (bool) $u['weekly_email'], 'onboarded' => (bool) $u['onboarded'], 'aiOptIn' => (bool) ($u['ai_opt_in'] ?? 0),
            'lowData' => (bool) ($u['low_data'] ?? 0), 'district' => $u['district'] ?? null, 'village' => $u['village'] ?? null, 'healthWorker' => (bool) ($u['health_worker'] ?? 0)];
}

function automation_public(array $a): array
{
    return ['refill' => (bool) $a['refill_on'], 'refillDays' => (int) $a['refill_days'], 'missed' => (bool) $a['missed_on'], 'missedAfter' => (int) $a['missed_after'],
            'course' => (bool) $a['course_on'], 'cleanup' => (bool) $a['cleanup_on'], 'timing' => (bool) ($a['timing_on'] ?? 1), 'watchHours' => (int) ($a['watch_hours'] ?? 24), 'calendar' => !empty($a['ics_token'])];
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
    $allowed = ['members', 'cabinet_items', 'devices'];
    if (!in_array($table, $allowed, true)) fail('bad_table', 'Not allowed.', 500);
    $row = q("SELECT * FROM $table WHERE id = ? AND user_id = ?", [$id, $uid])->fetch();
    if (!$row) fail('not_found', 'That record was not found.', 404);
    return $row;
}

function device_new_code(int $id): void
{
    for ($i = 0; $i < 5; $i++) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        try { q('UPDATE devices SET pair_code = ?, pair_expires = NOW() + INTERVAL 30 MINUTE WHERE id = ?', [$code, $id]); return; }
        catch (PDOException $e) { /* the same code is in use right now; try another */ }
    }
    fail('server_busy', 'Please try again.', 503);
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
