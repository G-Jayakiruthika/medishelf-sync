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
require 'core/migrate.php';
migrate();

$pass = 0; $fail = 0;
function eq($got, $want, string $msg): void { global $pass, $fail; if ($got === $want) { $pass++; } else { $fail++; echo "  ✗ $msg\n      got  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n      want " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; } }
function ok(bool $c, string $msg): void { eq($c, true, $msg); }

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
