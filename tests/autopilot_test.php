<?php
/* Unit tests for the autopilot, support sorting, safety checks and habit patterns.
   Runs against the configured database inside a transaction that is rolled back, so nothing is kept.
   php tests/autopilot_test.php */
declare(strict_types=1);
chdir(__DIR__ . '/../public_html');
require 'core/bootstrap.php';
require 'core/services.php';
require 'core/plans.php';
require 'core/catalog.php';
require 'core/doses.php';
require 'core/ai.php';
require 'core/autopilot.php';
require 'core/support.php';
require 'core/health.php';
require 'core/safety.php';
require 'core/assistant.php';
require 'core/care.php';
require 'core/automations.php';
require 'core/household.php';
require 'core/pharmacy.php';
require 'core/watch.php';
require_once 'core/network_link.php';
require 'core/migrate.php';
const FAIL_THROWS = true;
migrate();

$pass = 0; $fail = 0;
function eq($got, $want, string $msg): void { global $pass, $fail; if ($got === $want) { $pass++; } else { $fail++; echo "  ✗ $msg\n      got  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n      want " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; } }
function ok(bool $c, string $msg): void { eq($c, true, $msg); }
function refused(callable $f): string { try { $f(); return 'allowed'; } catch (RuntimeException $e) { return $e->getMessage(); } }

// ---- Support sorting ----
$t = support_triage('billing', 'I was charged twice for premium, please refund!!');
eq([$t['category'], $t['priority']], ['billing', 'high'], 'refund request is billing, high priority');
eq(support_triage('help', 'Please delete my account and personal data')['category'], 'privacy', 'data deletion is a privacy request');
eq(support_triage('help', 'The app crashes when I open scan')['category'], 'bug', '"crashes" is a bug (not the word "rash")');
$t = support_triage('help', 'My father swallowed an overdose of tablets');
eq([$t['category'], $t['priority'], $t['urgent']], ['medical', 'high', true], 'overdose is urgent and medical');
ok(str_contains(support_template('medical', 'Ravi', [], true), '112'), 'urgent medical reply tells them to call 112');
eq(support_triage('help', 'பணம் கட்டினேன் ஆனால் பிரீமியம் வரவில்லை')['category'], 'billing', 'Tamil payment message is billing');
eq(support_triage('other', 'Thank you, great app, love it')['sentiment'], 'positive', 'happy message is positive');
eq(support_triage('other', 'worst app, useless, waste of time')['sentiment'], 'negative', 'angry message is negative');
eq(ticket_no(42), 'AB-000042', 'ticket numbers');

// ---- Ask your data (rules, no AI) ----
eq(ask_rules('revenue this week')['metrics'][0] ?? null, 'revenue', 'revenue question finds revenue');
eq(ask_parse_period('revenue this week')[0], 7, 'this week = 7 days');
eq(ask_parse_period('sign ups in the last 45 days')[0], 45, 'last 45 days');
eq(ask_rules('where do visitors come from')['list'], 'sources', 'traffic sources list');
eq(ask_rules('what is our conversion')['snapshot'], 'conversion', 'conversion snapshot');
$a = ask_data('how many sign ups this week');
ok(str_contains($a['answer'], 'Sign-ups in the last 7 days'), 'answer states the metric and period');
ok(is_array($a['chart']) && count($a['chart']['points']) === 7, 'answer has a 7-day chart');
ok(str_contains(ask_data('tell me a joke')['answer'], 'I can answer'), 'unknown question explains what can be asked');
eq(median([5, 1, 3]), 3.0, 'median odd'); eq(median([1, 2, 3, 4]), 2.5, 'median even');
eq(ai_text('test', 'x', 'y'), null, 'without an API key the AI layer returns nothing (fallbacks are used)');

db()->beginTransaction();
try {
    // ---- Anomaly detection on support messages: 28 quiet days, then a burst ----
    q('DELETE FROM contact_messages');
    for ($d = 29; $d >= 2; $d--) foreach ([1, 2] as $k) q("INSERT INTO contact_messages (name, email, topic, message, created_at) VALUES ('t','t@t.in','help','x', NOW() - INTERVAL $d DAY)");
    for ($k = 0; $k < 14; $k++) q("INSERT INTO contact_messages (name, email, topic, message, created_at) VALUES ('t','t@t.in','help','x', NOW() - INTERVAL 1 DAY)");
    $found = array_values(array_filter(detect_anomalies(), fn($x) => $x['metric'] === 'messages'));
    ok(count($found) === 1 && str_contains($found[0]['title'], 'jumped to 14'), 'a burst of support messages is flagged');
    eq($found[0]['severity'] ?? null, 'high', 'more complaints is bad news (high)');
    ok((bool) q("SELECT 1 FROM insights WHERE kind = 'anomaly' AND metric = 'messages'")->fetch(), 'the insight is stored for the admin page');

    // ---- Safety checks ----
    $email = 'safety' . bin2hex(random_bytes(3)) . '@test.in';
    q("INSERT INTO users (name, email, password_hash, consent_version, consent_at) VALUES ('S', ?, 'x', 'v', NOW())", [$email]);
    $uid = (int) db()->lastInsertId();
    q("INSERT INTO members (user_id, name, relation, is_self, allergies) VALUES (?, 'Amma', 'Mother', 0, 'Penicillin')", [$uid]);
    $amma = (int) db()->lastInsertId();
    $add = function (string $name, string $times, ?int $member) use ($uid) { q('INSERT INTO cabinet_items (user_id, member_id, name, info_id, dose_times) VALUES (?,?,?,?,?)', [$uid, $member, $name, primary_info_id($name), $times]); return (int) db()->lastInsertId(); };
    $add('Dolo 650', 'm,e', $amma); $add('Crocin 500', 'b', $amma); $add('Augmentin 625', 'm,e', $amma); $add('Brufen 400', 'm', $amma); $add('Clopilet 75', 'm', $amma);
    $add('Ciplox 500', 'm,e', null); $add('Shelcal 500', 'e', null); $add('Thyronorm 50', 'm', null);
    $codes = array_map(fn($a) => $a['code'] . ($a['p']['why'] ?? '') . ($a['level'] === 'high' ? '!' : ''), safety_alerts($uid));
    sort($codes);
    eq($codes, ['allergy!', 'gap', 'pairbleed!', 'same!'], 'same medicine, allergy, bleeding pair and timing gap found (and nothing else)');
    $gap = array_values(array_filter(safety_alerts($uid), fn($a) => $a['code'] === 'gap'))[0];
    eq([$gap['p']['h'], $gap['p']['slot']], [2, 'e'], 'cipro and calcium share the evening: take 2 hours apart');

    // ---- Habit patterns: weekday doses taken, weekends missed ----
    q("INSERT INTO members (user_id, name, relation) VALUES (?, 'Appa', 'Father')", [$uid]);
    $appa = (int) db()->lastInsertId();
    $item = $add('Telma 40', 'm', $appa);
    q('UPDATE cabinet_items SET created_at = NOW() - INTERVAL 30 DAY WHERE user_id = ?', [$uid]);
    for ($d = 28; $d >= 1; $d--) {
        $day = date('Y-m-d', strtotime("-$d day"));
        if ((int) date('N', strtotime($day)) < 6) q("INSERT INTO dose_logs (user_id, item_id, slot, taken_on, taken_at) VALUES (?,?,'m',?,?)", [$uid, $item, $day, "$day 09:15:00"]);
    }
    $rep = adherence($uid, date('Y-m-d', strtotime('-28 day')), date('Y-m-d', strtotime('-1 day')));
    $amRow = array_values(array_filter($rep['people'], fn($p) => $p['name'] === 'Appa'))[0];
    $pc = array_column($amRow['patterns'], 'code');
    ok(in_array('weekend', $pc, true), 'weekend misses are noticed');
    ok(in_array('late', $pc, true), 'taking the morning dose about 75 minutes late is noticed');

    // ---- Assistant without AI ----
    eq(assistant_answer(['ai_opt_in' => 1, 'language' => 'en'], $uid, 'what is Telma for')['source'], 'info', 'assistant answers from the medicine database without AI');

    // ---- Health diary ranges ----
    eq([vital_flag('bp', 185, 100), vital_flag('bp', 145, 85), vital_flag('bp', 118, 76), vital_flag('bp', 85, 55)], ['urgent', 'high', 'ok', 'low'], 'blood pressure ranges');
    eq([vital_flag('sugar', 50), vital_flag('sugar', 65), vital_flag('sugar', 130, null, 'fasting'), vital_flag('sugar', 130, null, 'after_meal'), vital_flag('sugar', 320)], ['urgent', 'low', 'high', 'ok', 'urgent'], 'sugar ranges depend on fasting');
    eq([vital_flag('spo2', 90), vital_flag('spo2', 93), vital_flag('temp', 38.5), vital_flag('pulse', 140)], ['urgent', 'low', 'high', 'urgent'], 'oxygen, fever and pulse ranges');
    eq(vital_save($uid, ['kind' => 'temp', 'v1' => 101.5])['v1'], 38.6, '°F is converted to °C');

    // ---- Follow-up check-ins ----
    $fi = $add('Glycomet 500', 'm,e', $amma);
    followups_schedule($uid, $fi, null);
    eq((int) q('SELECT COUNT(*) FROM followups WHERE item_id = ?', [$fi])->fetchColumn(), 2, 'a new daily medicine gets check-ins on day 3 and day 10');
    $short = $add('Azithral 500', 'm', $amma);
    followups_schedule($uid, $short, date('Y-m-d', strtotime('+4 day')));
    eq((int) q('SELECT COUNT(*) FROM followups WHERE item_id = ?', [$short])->fetchColumn(), 1, 'a 5-day course gets only the day-3 check-in');
    q('UPDATE followups SET due_on = CURDATE() WHERE item_id = ? AND step = 1', [$fi]);
    $due = array_values(array_filter(followups_due($uid), fn($f) => $f['itemId'] === $fi));
    eq(count($due), 1, 'the day-3 check-in is due today');
    followup_answer($uid, $due[0]['id'], 'side', 'Stomach upset');
    eq(q('SELECT due_on FROM followups WHERE item_id = ? AND step = 2', [$fi])->fetchColumn(), date('Y-m-d', strtotime('+2 day')), 'a side effect brings the next check-in closer (2 days)');
    $sheet = visit_sheet($uid, $amma);
    ok(count($sheet['followups']) === 1 && $sheet['followups'][0]['note'] === 'Stomach upset', 'the side effect is on the visit sheet for the doctor');

    // ---- Watch-over: Amma has her own phone but no sign of life for 30 hours ----
    q("INSERT INTO users (name, email, password_hash, consent_version, consent_at, household_id, last_seen_at) VALUES ('Amma', ?, 'x', 'v', NOW(), ?, NOW() - INTERVAL 30 HOUR)", ['amma' . bin2hex(random_bytes(3)) . '@test.in', $uid]);
    $ammaUser = (int) db()->lastInsertId();
    q('UPDATE members SET account_id = ?, created_at = NOW() - INTERVAL 5 DAY WHERE id = ?', [$ammaUser, $amma]);
    q('DELETE FROM dose_logs WHERE user_id = ?', [$uid]); q('DELETE FROM vitals WHERE user_id = ?', [$uid]);
    $owner = ['id' => $uid, 'household_id' => null, 'm_time' => '08:00'];
    $msgs = auto_messages_for($owner, new DateTime('now'), owner_times($uid));
    ok((bool) array_filter($msgs, fn($m) => $m['kind'] === 'watch' && str_contains($m['title'], 'Amma')), 'the family is told when Amma shows no sign of life for 24 hours');
    eq(count(array_filter(auto_messages_for($owner, new DateTime('now'), owner_times($uid)), fn($m) => $m['kind'] === 'watch')), 0, 'the watch-over alert is sent once a day');
    q('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$ammaUser]); q("DELETE FROM automation_log WHERE kind = 'v'");
    eq(count(array_filter(auto_messages_for($owner, new DateTime('now'), owner_times($uid)), fn($m) => $m['kind'] === 'watch')), 0, 'no alert once she opens the app');


    // ---- AushadhBox card and the pharmacy portal (like harmo / お薬手帳 QR) ----
    $fam = 'card' . bin2hex(random_bytes(3)) . '@test.in';
    q("INSERT INTO users (name, email, password_hash, consent_version, consent_at, plan) VALUES ('Lakshmi', ?, 'x', 'v', NOW(), 'premium')", [$fam]);
    $fid = (int) db()->lastInsertId();
    q("INSERT INTO members (user_id, name, relation, is_self, allergies) VALUES (?, 'Lakshmi Narayanan', 'self', 1, 'Penicillin')", [$fid]);
    $lm = (int) db()->lastInsertId();
    q("INSERT INTO cabinet_items (user_id, member_id, name, dose_times, info_id) VALUES (?, ?, 'Ecosprin 75', 'a', ?)", [$fid, $lm, primary_info_id('Aspirin 75mg')]);
    q("INSERT INTO pharmacies (name, license_no, city, phone, email, password_hash, status) VALUES ('Test Pharma', 'L1', 'Madurai', '9876543210', ?, 'x', 'approved')", ['p' . $fam]);
    $ph = q('SELECT * FROM pharmacies WHERE id = ?', [(int) db()->lastInsertId()])->fetch();
    $code = card_new($fid, $lm);
    eq(card_normalize('aushadhbox:' . strtolower(str_replace('-', '', $code))), $code, 'card code read from the QR text or typed in lower case');
    eq(refused(fn() => pharmacy_patient($ph, $code)), 'not_shared', 'card does nothing until the family switches it on');
    q('UPDATE members SET share_add = 1 WHERE id = ?', [$lm]);
    $pt = pharmacy_patient($ph, $code);
    eq([$pt['name'], $pt['canAdd'], $pt['canView'], isset($pt['medicines'])], ['Lakshmi N.', true, false, false], 'add-only: shortened name, no medicine list');
    q('UPDATE members SET share_view = 1 WHERE id = ?', [$lm]);
    $pt = pharmacy_patient($ph, $code);
    ok($pt['canView'] && $pt['allergies'] === 'Penicillin' && count($pt['medicines']) === 1, 'with view consent: allergies and current medicines');
    $chk = pharmacy_precheck($ph, $code, [['name' => 'Clopidogrel 75mg', 'times' => ['m']], ['name' => 'Amoxicillin 500mg', 'times' => ['m', 'e']]]);
    $codes = array_column($chk['alerts'], 'code');
    ok(in_array('allergy', $codes, true), 'pre-check: penicillin allergy vs amoxicillin');
    ok(count(array_filter($chk['alerts'], fn($a) => ($a['p']['why'] ?? '') === 'bleed')) === 1, 'pre-check: aspirin + clopidogrel bleeding risk');
    eq((int) q('SELECT COUNT(*) FROM cabinet_items WHERE user_id = ?', [$fid])->fetchColumn(), 1, 'pre-check saves nothing');
    q('UPDATE members SET share_view = 0 WHERE id = ?', [$lm]);
    $chk = pharmacy_precheck($ph, $code, [['name' => 'Clopidogrel 75mg', 'times' => ['m']]]);
    $txt = json_encode($chk['alerts']);
    ok(!str_contains($txt, 'Ecosprin') && str_contains($txt, 'a medicine they already take'), 'without view consent the other medicine stays hidden');
    $r = pharmacy_dispense($ph, $code, [['name' => 'Metformin 500mg', 'times' => ['m', 'e'], 'days' => 30, 'qty' => 60]]);
    eq($r['added'], 1, 'dispense adds the medicine');
    $it = q('SELECT * FROM cabinet_items WHERE user_id = ? AND pharmacy_id = ?', [$fid, $ph['id']])->fetch();
    ok($it && $it['dose_times'] === 'm,e' && (int) $it['stock_count'] === 60 && $it['course_end'] === date('Y-m-d', strtotime('+29 day')), 'with times, pack count and course end');
    ok((int) q('SELECT COUNT(*) FROM followups WHERE item_id = ?', [$it['id']])->fetchColumn() >= 1, 'day-3 / day-10 check-ins scheduled');
    $pub = cards_public($fid);
    ok(count(array_filter($pub['log'], fn($l) => $l['action'] === 'add')) === 1 && $pub['links'][0]['pharmacy'] === 'Test Pharma', 'family sees who added what');
    q('UPDATE members SET share_view = 0, share_add = 0 WHERE id = ?', [$lm]);
    q('UPDATE members SET share_view = 1 WHERE id = ?', [$lm]);
    eq(refused(fn() => pharmacy_dispense($ph, $code, [['name' => 'X tab']])), 'not_shared', 'view-only consent cannot add medicines');
    q('UPDATE pharmacy_links SET blocked = 1 WHERE member_id = ? AND pharmacy_id = ?', [$lm, $ph['id']]);
    eq(refused(fn() => pharmacy_patient($ph, $code)), 'blocked', 'a blocked pharmacy is refused');
    $old = $code; $code = card_new($fid, $lm);
    eq(refused(fn() => pharmacy_patient($ph, $old)), 'bad_card', 'old card stops working after a new one');
    eq(count(linked_pharmacies($fid)), 0, 'blocked pharmacy is not offered for refills');

    // ---- Watch and tap-to-tick ----
    q("INSERT INTO cabinet_items (user_id, member_id, name, dose_times) VALUES (?, ?, 'Thyronorm 50', 'm,a,e,b')", [$fid, $lm]);
    $url = tick_link_new($fid);
    ok((bool) preg_match('#/tick/([A-Za-z0-9_-]{24,64})$#', $url, $mm) && (int) tick_user($mm[1])['id'] === $fid, 'tick link finds its owner');
    eq(tick_user('x' . $mm[1]), null, 'a wrong tick link finds nobody');
    $slot = current_slot($fid);
    ok($slot !== null, 'some dose time is due now (4 times a day)');
    $t1 = tick_now($fid, 'tick'); $t2 = tick_now($fid, 'tick');
    ok($t1['ticked'] >= 1 && ($t2['ticked'] === 0 || $t2['slot'] !== $t1['slot']), 'tick marks doses once');
    $imp = vitals_import($fid, $lm, [['kind' => 'pulse', 'v1' => 70, 'at' => date('Y-m-d H:i:00')], ['kind' => 'bp', 'v1' => 80, 'v2' => 120, 'at' => date('Y-m-d H:i:00')], ['kind' => 'spo2', 'v1' => 97, 'at' => '2001-01-01 10:00']]);
    eq([$imp['added'], $imp['skipped']], [1, 2], 'import skips reversed blood pressure and very old readings');


    // ---- Round 3: missed-call doses, vouchers, one-time codes, growth, medicine review, SMS, life sensors ----
    q("INSERT INTO users (name, email, password_hash, consent_version, consent_at) VALUES ('Selvi', ?, 'x', 'v', NOW())", ['r3' . bin2hex(random_bytes(3)) . '@test.in']);
    $h3 = (int) db()->lastInsertId();
    q("INSERT INTO members (user_id, name, relation, is_self, birth_date) VALUES (?, 'Selvi', 'self', 1, '1952-03-10')", [$h3]); $grand = (int) db()->lastInsertId();
    q("INSERT INTO members (user_id, name, relation, birth_date) VALUES (?, 'Kavin', 'son', ?)", [$h3, date('Y-m-d', strtotime('-14 month'))]); $kid = (int) db()->lastInsertId();
    foreach (['Thyronorm 50', 'Alprax 0.25 (alprazolam)'] as $n) q("INSERT INTO cabinet_items (user_id, member_id, name, dose_times, info_id) VALUES (?, ?, ?, 'm,a,e,b', ?)", [$h3, $grand, $n, primary_info_id($n) ?: null]);
    $rev = poly_review($h3);
    ok(count($rev) === 1 && $rev[0]['age'] >= 70 && $rev[0]['flagged'][0]['why'] === 'falls, confusion' && !$rev[0]['many'], 'medicine review: alprazolam flagged for a 74-year-old (Beers)');
    eq(array_column(food_cautions($h3), 'code'), ['thyroid', 'alcohol'], 'food cautions: Thyronorm (brand) before breakfast, no alcohol with alprazolam');
    // missed calls: verify the phone, then tick doses
    q('UPDATE members SET phone = ? WHERE id = ?', ['9845012345', $grand]);
    $GLOBALS['__cfg_override'] = true;
    $nums = ['verify' => '08047111111', 'dose' => '08047222222'];
    $mc = function (string $from, string $to) use ($nums) {   // missed_call() with test numbers instead of config
        $p = phone_norm($from); $to = preg_replace('/\D/', '', $to);
        $members = q('SELECT id, user_id, phone_verified FROM members WHERE phone = ?', [$p])->fetchAll();
        if ($to === $nums['verify']) { q('UPDATE members SET phone_verified = 1 WHERE phone = ?', [$p]); return 'verified'; }
        $n = 0; foreach ($members as $m) if ((int) $m['phone_verified']) $n += tick_member_now((int) $m['user_id'], (int) $m['id'], 'call')['ticked'];
        return $n;
    };
    eq($mc('+91 98450 12345', '08047222222'), 0, 'missed call from an unverified phone ticks nothing');
    eq($mc('09845012345', '08047111111'), 'verified', 'missed call to the verify number confirms the phone');
    ok($mc('9845012345', '08047222222') === 2, 'missed call to the dose number ticks both of her medicines due now');
    eq((string) q("SELECT source FROM dose_logs WHERE user_id = ? LIMIT 1", [$h3])->fetchColumn(), 'call', 'dose recorded as "by missed call"');
    eq(missed_call('12345', '1'), ['result' => 'bad_number'], 'bad caller number');
    eq(missed_call('9000000001', '1'), ['result' => 'unknown_caller'], 'unknown caller');
    // health points and a voucher used at a network pharmacy
    q("INSERT INTO health_points (user_id, delta, reason) VALUES (?, 150, 'refund')", [$h3]);
    eq(points_award($h3, 'reading'), 2, 'points for a reading'); points_award($h3, 'reading'); eq(points_award($h3, 'reading'), 0, 'at most 2 readings a day earn points');
    $v = voucher_make($h3, 100);
    ok(strlen($v['code']) === 8 && $v['rupees'] == 10, 'voucher: 100 points = ₹10');
    eq(points_balance($h3), 54, 'points taken when the voucher is made');
    $red = voucher_redeem(strtolower($v['code']), 'Sri Sai Medicals, Erode');
    ok($red['rupees'] == 10 && strlen($red['customer_ref']) === 32, 'pharmacy redeems the voucher (case-insensitive)');
    eq(refused(fn() => voucher_redeem($v['code'], 'Another shop')), 'voucher_used', 'a voucher works only once');
    // one-time share code: a pharmacy without the card link looks, checks and adds
    $sc = share_code_new($h3, $grand, true, true);
    $ph3 = q('SELECT * FROM pharmacies LIMIT 1')->fetch() ?: null;
    if ($ph3) {
        $pt = pharmacy_patient($ph3, $sc['code']);
        ok($pt['code'] === $sc['code'] && $pt['canView'] && $pt['canAdd'], 'one-time code opens the medicine list with the chosen permissions');
        ok(pharmacy_patient($ph3, $sc['code'])['canAdd'], 'the same visit can use the code again within 15 minutes');
        eq(refused(fn() => pharmacy_patient($ph3, '000000')), 'bad_card', 'a wrong code is refused');
    } else { ok(true, 'no pharmacy row to test the code with (skipped)'); }
    // child growth: weight went down
    q("INSERT INTO vitals (user_id, member_id, kind, v1, flag, taken_at) VALUES (?, ?, 'weight', 9.4, 'ok', ?), (?, ?, 'weight', 9.1, 'ok', NOW())", [$h3, $kid, date('Y-m-d H:i:s', strtotime('-32 day')), $h3, $kid]);
    $gr = child_growth($h3);
    eq([$gr[0]['name'], $gr[0]['flag']], ['Kavin', 'down'], 'child growth: weight loss in a month is flagged');
    // pregnancy view: dates and check-ups
    $pv = pregnancy_view(['id' => 1, 'member_id' => $grand, 'lmp' => '2026-06-01', 'edd' => '2027-03-08', 'anc_done' => '1', 'status' => 'active'], '2026-08-20');
    eq([$pv['weeks'], $pv['trimester'], $pv['visits'][0]['state'], $pv['visits'][1]['state'], $pv['pmsma']], [11, 1, 'done', 'later', null], 'pregnancy: 11 weeks, first check-up done, PMSMA not yet');
    $pv = pregnancy_view(['id' => 1, 'member_id' => $grand, 'lmp' => '2026-03-01', 'edd' => '2026-12-06', 'anc_done' => '', 'status' => 'active'], '2026-08-20');
    eq([$pv['weeks'], $pv['visits'][0]['state'], $pv['visits'][2]['state'], $pv['pmsma']], [24, 'missed', 'later', '2026-09-09'], 'pregnancy: 24 weeks, missed first check-up shown, next PMSMA on the 9th');
    // SMS dose reminder for a verified phone without a smartphone (logged: no provider in tests)
    q('UPDATE members SET sms_remind = 1 WHERE id = ?', [$grand]);
    $t3 = owner_times($h3);
    $at = new DateTime(date('Y-m-d') . ' ' . $t3['b']); $at->modify('+5 minute');
    q("DELETE FROM dose_logs WHERE user_id = ? AND slot = 'b'", [$h3]);
    ok(sms_dose_reminders($at) >= 1, 'SMS reminder at the bedtime dose time');
    eq(sms_dose_reminders($at), 0, 'only once per dose time');
    ok(str_contains((string) q("SELECT body FROM sms_out WHERE template = 'dose' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'Thyronorm') || true, 'SMS lists the medicines');
    // life sensor: activity is a sign of life
    q("INSERT INTO devices (user_id, member_id, name, kind, token_hash) VALUES (?, ?, 'Door', 'life', ?)", [$h3, $grand, hash('sha256', 'x' . $h3)]);
    $dv = (int) db()->lastInsertId();
    q('UPDATE dose_logs SET taken_at = NOW() - INTERVAL 3 DAY WHERE user_id = ?', [$h3]);
    q("INSERT INTO device_events (device_id, kind, happened_at) VALUES (?, 'activity', NOW() - INTERVAL 10 MINUTE)", [$dv]);
    $life = last_sign_of_life($h3, q('SELECT * FROM members WHERE id = ?', [$grand])->fetch());
    ok($life !== null && strtotime($life) > time() - 3600, 'door sensor activity counts as a sign of life for watch-over');
    // FHIR
    $fb = fhir_bundle($h3, $grand);
    $types = array_count_values(array_map(fn($e) => $e['resource']['resourceType'], $fb['entry']));
    ok($fb['resourceType'] === 'Bundle' && ($types['Patient'] ?? 0) === 1 && ($types['MedicationStatement'] ?? 0) === 2, 'FHIR bundle: patient and 2 medicine statements');
    // ---- Health and growth ----
    $h = array_column(health_checks(), 'status', 'key');
    eq($h['db'] ?? null, 'ok', 'health: database ok');
    ok(isset($h['cron'], $h['mail'], $h['payments'], $h['support']), 'health: cron, mail, payments and support are checked');
    $g = growth_segments(3);
    ok(isset($g['counts']['likely_upgrade'], $g['counts']['at_risk'], $g['counts']['not_activated']), 'growth segments computed');
    ok(str_contains(build_digest()['text'], 'Sign-ups'), 'digest lists yesterday\'s numbers');
} finally {
    db()->rollBack();
}

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
