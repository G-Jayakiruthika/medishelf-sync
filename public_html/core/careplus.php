<?php
/* Round 3 of ideas from Japanese household medicine apps, adapted to Indian families, plus rural features.
   - Repeat prescriptions (リフィル処方箋): a doctor's "continue for 3 months" prescription, used up to N times.
   - Medicine review (ポリファーマシー対策): many daily medicines, or ones older people should review (Beers criteria).
   - Food and drink cautions (飲み合わせ・食べ合わせ): milk with ciprofloxacin, alcohol with metronidazole...
   - Mother and child (母子手帳): pregnancy weeks, check-up (ANC) schedule from India's MCP card, IFA and calcium,
     danger signs, schemes to ask the ASHA about; child weight trend with a "not growing" flag.
   - Health points (EPARK くすりの窓口ポイント): earned for looking after health, spent as a voucher at a network pharmacy.
   - One-time share code (EPARK ワンタイムコード): 6 digits for 10 minutes, for a pharmacy you don't have a card link with.
   - FHIR export (マイナポータル連携 → India's ABDM): the medicine list and readings as a FHIR R4 bundle.
   - Life sensors (見守り): a door or motion sensor counts as a sign of life for watch-over.
   - Missed-call doses (99DOTS, used by India's TB programme): a missed call from a registered phone ticks the doses due now.
   - SMS reminders for family members without a smartphone. */
declare(strict_types=1);

/* The person must belong to this household (404 otherwise, so other families' ids reveal nothing). */
function member_of(int $hid, int $memberId): array
{
    $m = q('SELECT * FROM members WHERE id = ? AND user_id = ?', [$memberId, $hid])->fetch();
    if (!$m) fail('not_found', 'That record was not found.', 404);
    return $m;
}

/* ---------------- Repeat prescriptions ---------------- */
function rx_repeats(int $hid): array
{
    return array_map(fn($r) => ['id' => (int) $r['id'], 'memberId' => $r['member_id'] ? (int) $r['member_id'] : null, 'doctor' => $r['doctor'], 'issued' => $r['issued_on'], 'until' => $r['valid_until'],
                                'allowed' => (int) $r['repeats_allowed'], 'used' => (int) $r['repeats_used'], 'items' => json_decode($r['items'], true) ?: [], 'note' => $r['note'],
                                'active' => $r['valid_until'] >= date('Y-m-d') && (int) $r['repeats_used'] < (int) $r['repeats_allowed']],
        q('SELECT * FROM rx_repeats WHERE user_id = ? ORDER BY valid_until DESC, id DESC', [$hid])->fetchAll());
}

function rx_repeat_save(int $hid, array $in): int
{
    $items = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 120), (array) ($in['items'] ?? [])), fn($x) => mb_strlen($x) >= 2));
    if (!$items) fail('empty', 'Add the medicines written on the prescription.');
    $issued = (string) ($in['issued'] ?? date('Y-m-d'));
    $until = (string) ($in['until'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued) || $issued > date('Y-m-d')) fail('bad_date', 'Choose the date on the prescription.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < date('Y-m-d') || $until > date('Y-m-d', strtotime($issued . ' +1 year'))) fail('bad_until', 'The prescription must be valid today and for at most a year.');
    $allowed = max(1, min(12, (int) ($in['allowed'] ?? 3)));
    $mid = (int) ($in['member_id'] ?? 0) ?: null;
    if ($mid) member_of($hid, $mid);
    q('INSERT INTO rx_repeats (user_id, member_id, doctor, issued_on, valid_until, repeats_allowed, items, note) VALUES (?,?,?,?,?,?,?,?)',
      [$hid, $mid, str_in($in, 'doctor', 80, true), $issued, $until, $allowed, json_encode(array_slice($items, 0, 20), JSON_UNESCAPED_UNICODE), str_in($in, 'note', 200)]);
    return (int) db()->lastInsertId();
}

/* Records one use (when the pharmacy fills it again). Refuses when used up or expired. */
function rx_repeat_use(int $hid, int $id): array
{
    $r = q('SELECT * FROM rx_repeats WHERE id = ? AND user_id = ?', [$id, $hid])->fetch();
    if (!$r) fail('not_found', 'Prescription not found.', 404);
    if ($r['valid_until'] < date('Y-m-d')) fail('expired', 'This prescription has expired. Ask the doctor for a new one.');
    if ((int) $r['repeats_used'] >= (int) $r['repeats_allowed']) fail('used_up', 'All repeats on this prescription are used. Ask the doctor for a new one.');
    q('UPDATE rx_repeats SET repeats_used = repeats_used + 1, last_used = NOW() WHERE id = ?', [$id]);
    return ['left' => (int) $r['repeats_allowed'] - (int) $r['repeats_used'] - 1];
}

/* Medicines on an active repeat prescription that run out within $days: a reminder to refill in time. */
function rx_repeat_due(int $hid, int $days = 5): array
{
    $out = [];
    foreach (rx_repeats($hid) as $r) {
        if (!$r['active']) continue;
        foreach ($r['items'] as $name) {
            $it = q("SELECT name, stock_count, per_dose, dose_times FROM cabinet_items WHERE user_id = ? AND (member_id <=> ?) AND name LIKE ? AND dose_times <> '' AND stock_count IS NOT NULL LIMIT 1",
                    [$hid, $r['memberId'], '%' . addcslashes(explode(' ', $name)[0], '%_') . '%'])->fetch();
            if (!$it) continue;
            $perDay = max(1, count(array_filter(explode(',', $it['dose_times']))) * max(1, (int) $it['per_dose']));
            $left = intdiv((int) $it['stock_count'], $perDay);
            if ($left <= $days) $out[] = ['rxId' => $r['id'], 'name' => $it['name'], 'daysLeft' => $left, 'repeatsLeft' => $r['allowed'] - $r['used']];
        }
    }
    return $out;
}

/* ---------------- Medicine review (polypharmacy) ---------------- */
/* Medicines that older people should review with their doctor: a short list from the AGS Beers Criteria (2023),
   limited to medicines common in India. Keyword => reason. */
const ELDERLY_REVIEW = [
    'diazepam' => 'falls, confusion', 'alprazolam' => 'falls, confusion', 'clonazepam' => 'falls, confusion', 'lorazepam' => 'falls, confusion',
    'nitrazepam' => 'falls, confusion', 'zolpidem' => 'falls, confusion', 'amitriptyline' => 'drowsiness, dizziness, constipation',
    'chlorpheniramine' => 'confusion, constipation, urine retention', 'promethazine' => 'confusion, constipation', 'hydroxyzine' => 'confusion, drowsiness',
    'diphenhydramine' => 'confusion, drowsiness', 'glibenclamide' => 'long low blood sugar', 'glimepiride' => 'low blood sugar',
    'diclofenac' => 'stomach bleeding, kidney strain', 'aceclofenac' => 'stomach bleeding, kidney strain', 'ibuprofen' => 'stomach bleeding, kidney strain (if taken daily)',
    'naproxen' => 'stomach bleeding, kidney strain', 'indomethacin' => 'stomach bleeding, confusion', 'piroxicam' => 'stomach bleeding',
    'prazosin' => 'dizziness on standing', 'digoxin' => 'toxicity at higher doses', 'metoclopramide' => 'movement problems',
];

function age_years(?string $birth): ?int
{
    if (!$birth) return null;
    return (int) (new DateTime($birth))->diff(new DateTime('today'))->y;
}

function poly_review(int $hid): array
{
    $out = [];
    foreach (q('SELECT id, name, birth_date FROM members WHERE user_id = ?', [$hid])->fetchAll() as $m) {
        $meds = q("SELECT c.name, COALESCE(i.ingredient, c.name) ing FROM cabinet_items c LEFT JOIN medicine_info i ON i.id = c.info_id
                   WHERE c.user_id = ? AND c.member_id = ? AND c.dose_times <> '' AND (c.course_end IS NULL OR c.course_end >= CURDATE())", [$hid, $m['id']])->fetchAll();
        $age = age_years($m['birth_date']);
        $flag = [];
        if ($age === null || $age >= 60) foreach ($meds as $x) foreach (ELDERLY_REVIEW as $kw => $why)
            if (stripos($x['ing'] . ' ' . $x['name'], $kw) !== false) { $flag[] = ['name' => $x['name'], 'why' => $why]; break; }
        $many = count($meds) >= 5;
        if ($many || ($flag && ($age === null || $age >= 65))) $out[] = ['memberId' => (int) $m['id'], 'name' => $m['name'], 'age' => $age, 'count' => count($meds), 'many' => $many, 'flagged' => $flag];
    }
    return $out;
}

/* ---------------- Food and drink cautions ---------------- */
const FOOD_RULES = [
    ['dairy', ['ciprofloxacin', 'levofloxacin', 'ofloxacin', 'norfloxacin', 'doxycycline', 'tetracycline'], 'Keep milk, curd, calcium, iron and antacids 2 hours away from {a}: they stop it being absorbed.'],
    ['thyroid', ['levothyroxine', 'thyroxine'], 'Take {a} on an empty stomach, 30–60 minutes before breakfast or tea; keep calcium, iron and antacids 4 hours away.'],
    ['alcohol_severe', ['metronidazole', 'tinidazole'], 'No alcohol with {a}, and for 2 days after the last tablet: it causes vomiting, flushing and a racing heart.'],
    ['alcohol', ['diazepam', 'alprazolam', 'clonazepam', 'zolpidem', 'amitriptyline', 'tramadol', 'pregabalin', 'gabapentin', 'cetirizine', 'chlorpheniramine'], 'Avoid alcohol with {a}: together they cause heavy drowsiness.'],
    ['alcohol_sugar', ['glimepiride', 'glibenclamide', 'gliclazide', 'insulin', 'metformin'], 'Alcohol with {a} can cause dangerously low sugar: never drink on an empty stomach.'],
    ['grapefruit', ['atorvastatin', 'simvastatin', 'amlodipine', 'felodipine', 'nifedipine', 'cyclosporine'], 'Avoid grapefruit and its juice with {a}: it raises the medicine level.'],
    ['vitk', ['warfarin', 'acenocoumarol'], 'Eat about the same amount of green leafy vegetables (palak, methi) every week with {a}: sudden changes upset the INR.'],
    ['potassium', ['spironolactone', 'eplerenone', 'enalapril', 'ramipril', 'lisinopril', 'telmisartan', 'losartan', 'olmesartan'], 'Don\'t use "low-sodium" salt substitutes (potassium salt) with {a} without asking the doctor.'],
    ['caffeine', ['theophylline', 'doxofylline'], 'Limit strong tea and coffee with {a}: it adds to palpitations.'],
    ['iron_tea', ['ferrous', 'iron', 'folic acid + iron'], 'Take {a} with water or lemon juice, not with tea, coffee or milk (they block iron).'],
];

function food_cautions(int $hid): array
{
    $out = [];
    $rows = q("SELECT c.id, c.name, c.member_id, COALESCE(i.ingredient, c.name) ing FROM cabinet_items c LEFT JOIN medicine_info i ON i.id = c.info_id
               WHERE c.user_id = ? AND (c.course_end IS NULL OR c.course_end >= CURDATE()) AND (c.exp_month IS NULL OR LAST_DAY(c.exp_month) >= CURDATE())", [$hid])->fetchAll();
    foreach ($rows as $r) foreach (FOOD_RULES as [$code, $words, $text]) {
        foreach ($words as $w) if (stripos($r['ing'] . ' ' . $r['name'], $w) !== false) {
            $out[] = ['code' => $code, 'itemId' => (int) $r['id'], 'memberId' => $r['member_id'] ? (int) $r['member_id'] : null, 'name' => $r['name'], 'text' => str_replace('{a}', $r['name'], $text)];
            break;
        }
    }
    return $out;
}

/* ---------------- Mother and child ---------------- */
/* Check-ups (ANC) as on India's Mother and Child Protection (MCP) card: at least 4, plus the free PMSMA check-up on
   the 9th of each month in the 2nd and 3rd trimester. */
const ANC_VISITS = [[1, 0, 12, 'First check-up and registration (by 12 weeks)'], [2, 14, 26, 'Second check-up (14–26 weeks)'],
                    [3, 28, 34, 'Third check-up (28–34 weeks)'], [4, 36, 40, 'Fourth check-up (36 weeks to delivery)']];

function pregnancy_view(array $p, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $days = (int) ((strtotime($today) - strtotime($p['lmp'])) / 86400);
    $weeks = intdiv(max(0, $days), 7);
    $visits = array_map(function ($v) use ($p, $weeks) {
        [$n, $from, $to, $label] = $v;
        $done = in_array($n, array_map('intval', array_filter(explode(',', (string) $p['anc_done']))), true);
        return ['n' => $n, 'label' => $label, 'from' => date('Y-m-d', strtotime($p['lmp'] . " +$from week")), 'to' => date('Y-m-d', strtotime($p['lmp'] . " +$to week")),
                'done' => $done, 'state' => $done ? 'done' : ($weeks > $to ? 'missed' : ($weeks >= $from ? 'now' : 'later'))];
    }, ANC_VISITS);
    $next9 = date('Y-m-09', strtotime(date('d', strtotime($today)) > 9 ? "$today first day of next month" : $today));
    return ['id' => (int) $p['id'], 'memberId' => (int) $p['member_id'], 'lmp' => $p['lmp'], 'edd' => $p['edd'], 'weeks' => $weeks, 'days' => max(0, $days) % 7,
            'trimester' => $weeks < 13 ? 1 : ($weeks < 28 ? 2 : 3), 'visits' => $visits, 'ifaFrom' => date('Y-m-d', strtotime($p['lmp'] . ' +14 week')),
            'pmsma' => $weeks >= 13 && $weeks < 40 ? $next9 : null, 'status' => $p['status']];
}

function pregnancy_start(int $hid, int $memberId, string $lmp): array
{
    member_of($hid, $memberId);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lmp) || $lmp > date('Y-m-d') || $lmp < date('Y-m-d', strtotime('-44 week'))) fail('bad_lmp', 'Enter the first day of the last period (within the last 10 months).');
    $edd = date('Y-m-d', strtotime($lmp . ' +280 day'));   // Naegele's rule
    q("UPDATE pregnancies SET status = 'ended' WHERE user_id = ? AND member_id = ? AND status = 'active'", [$hid, $memberId]);
    q('INSERT INTO pregnancies (user_id, member_id, lmp, edd) VALUES (?,?,?,?)', [$hid, $memberId, $lmp, $edd]);
    return pregnancy_view(q('SELECT * FROM pregnancies WHERE id = ?', [(int) db()->lastInsertId()])->fetch());
}

function pregnancies(int $hid): array
{
    return array_map('pregnancy_view', q("SELECT * FROM pregnancies WHERE user_id = ? AND status = 'active'", [$hid])->fetchAll());
}

/* Child under 5: weight readings in the health diary. Flags "not growing" when the latest weight is not more than the
   one about a month before (the growth-monitoring rule used at anganwadis), or went down. */
function child_growth(int $hid): array
{
    $out = [];
    foreach (q("SELECT id, name, birth_date FROM members WHERE user_id = ? AND birth_date IS NOT NULL AND birth_date > CURDATE() - INTERVAL 5 YEAR", [$hid])->fetchAll() as $m) {
        $w = q("SELECT v1 kg, DATE(taken_at) d FROM vitals WHERE user_id = ? AND member_id = ? AND kind = 'weight' ORDER BY taken_at DESC LIMIT 12", [$hid, $m['id']])->fetchAll();
        $flag = null;
        if (count($w) >= 2) {
            $last = $w[0];
            $prev = null;
            foreach (array_slice($w, 1) as $x) if ((strtotime($last['d']) - strtotime($x['d'])) / 86400 >= 25) { $prev = $x; break; }
            if ($prev && (float) $last['kg'] < (float) $prev['kg']) $flag = 'down';
            elseif ($prev && (float) $last['kg'] <= (float) $prev['kg'] && age_years($m['birth_date']) < 2) $flag = 'flat';
        }
        $out[] = ['memberId' => (int) $m['id'], 'name' => $m['name'], 'months' => (int) ((time() - strtotime($m['birth_date'])) / (86400 * 30.44)),
                  'weights' => array_reverse(array_map(fn($x) => ['kg' => (float) $x['kg'], 'd' => $x['d']], $w)), 'flag' => $flag];
    }
    return $out;
}

/* ---------------- Health points and vouchers ---------------- */
const POINT_RULES = ['reading' => [2, 2], 'all_doses' => [5, 1], 'followup' => [3, 3], 'vaccine' => [10, 5], 'anc' => [10, 1], 'steps' => [2, 1]];   // reason => [points, max a day]
const POINT_VALUE_PAISE = 10;          // 1 point = 10 paise; 100 points = ₹10 off at a network pharmacy (paid by the platform)
const VOUCHER_MIN = 100;

function points_award(int $hid, string $reason, ?string $ref = null): int
{
    if (!isset(POINT_RULES[$reason])) return 0;
    [$pts, $max] = POINT_RULES[$reason];
    $today = (int) q('SELECT COUNT(*) FROM health_points WHERE user_id = ? AND reason = ? AND DATE(created_at) = CURDATE()', [$hid, $reason])->fetchColumn();
    if ($today >= $max) return 0;
    if ($ref && q('SELECT 1 FROM health_points WHERE user_id = ? AND reason = ? AND ref = ?', [$hid, $reason, $ref])->fetch()) return 0;
    q('INSERT INTO health_points (user_id, delta, reason, ref) VALUES (?,?,?,?)', [$hid, $pts, $reason, $ref]);
    return $pts;
}

function points_balance(int $hid): int
{
    return (int) q('SELECT COALESCE(SUM(delta), 0) FROM health_points WHERE user_id = ?', [$hid])->fetchColumn();
}

function points_view(int $hid): array
{
    return ['balance' => points_balance($hid), 'valuePaise' => POINT_VALUE_PAISE, 'min' => VOUCHER_MIN,
            'history' => q('SELECT delta, reason, created_at FROM health_points WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$hid])->fetchAll(),
            'vouchers' => q("SELECT code, points, status, expires_at, used_at, used_by FROM point_vouchers WHERE user_id = ? ORDER BY id DESC LIMIT 10", [$hid])->fetchAll()];
}

function voucher_make(int $hid, int $points): array
{
    if ($points < VOUCHER_MIN || $points > 2000) fail('bad_points', 'A voucher is between ' . VOUCHER_MIN . ' and 2,000 points.');
    if (points_balance($hid) < $points) fail('not_enough', 'Not enough points yet.');
    if ((int) q("SELECT COUNT(*) FROM point_vouchers WHERE user_id = ? AND status = 'open'", [$hid])->fetchColumn() >= 3) fail('too_many', 'Use or cancel an open voucher first.');
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = ''; for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $nested = ph_tx();   // a transaction, or a savepoint inside one (core/pharmacy.php)
    try {
        q('INSERT INTO health_points (user_id, delta, reason, ref) VALUES (?,?,?,?)', [$hid, -$points, 'voucher', $code]);
        q("INSERT INTO point_vouchers (user_id, code, points, status, expires_at) VALUES (?,?,?, 'open', NOW() + INTERVAL 30 DAY)", [$hid, $code, $points]);
    } catch (Throwable $e) { ph_tx_end($nested, false); throw $e; }
    ph_tx_end($nested, true);
    return ['code' => $code, 'points' => $points, 'rupees' => $points * POINT_VALUE_PAISE / 100];
}

function voucher_cancel(int $hid, string $code): void
{
    $v = q("SELECT * FROM point_vouchers WHERE user_id = ? AND code = ? AND status = 'open'", [$hid, strtoupper($code)])->fetch();
    if (!$v) fail('not_found', 'Voucher not found or already used.', 404);
    q("UPDATE point_vouchers SET status = 'cancelled' WHERE id = ?", [$v['id']]);
    q('INSERT INTO health_points (user_id, delta, reason, ref) VALUES (?,?,?,?)', [$hid, (int) $v['points'], 'refund', $v['code']]);
}

/* Called by the pharmacy network when a shop accepts the voucher. Not for prescription-only medicines (the shop's
   portal enforces it); the platform pays the shop back in its monthly statement. */
function voucher_redeem(string $code, string $shop): array
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    $v = strlen($code) === 8 ? q("SELECT * FROM point_vouchers WHERE code = ?", [$code])->fetch() : null;
    if (!$v) fail('bad_voucher', 'Voucher not found.', 404);
    if ($v['status'] !== 'open') fail('voucher_used', 'This voucher was already used or cancelled.', 409);
    if (strtotime($v['expires_at']) < time()) { q("UPDATE point_vouchers SET status = 'expired' WHERE id = ?", [$v['id']]); fail('voucher_expired', 'This voucher has expired.', 410); }
    $n = q("UPDATE point_vouchers SET status = 'used', used_at = NOW(), used_by = ? WHERE id = ? AND status = 'open'", [mb_substr($shop, 0, 120), $v['id']])->rowCount();
    if (!$n) fail('voucher_used', 'This voucher was already used.', 409);
    return ['ok' => true, 'rupees' => (int) $v['points'] * POINT_VALUE_PAISE / 100, 'customer_ref' => customer_ref((int) $v['user_id'])];
}

/* All of today's planned doses taken (or deliberately skipped) for the household: +5 points, once a day. */
function points_check_all_doses(int $hid): int
{
    $today = date('Y-m-d'); $due = 0; $done = 0;
    foreach (SLOTS as $s) foreach (due_items($hid, $s, $today) as $i) {
        $due++;
        if (q('SELECT 1 FROM dose_logs WHERE item_id = ? AND slot = ? AND taken_on = ?', [$i['id'], $s, $today])->fetch()) $done++;
    }
    return $due > 0 && $done === $due ? points_award($hid, 'all_doses', $today) : 0;
}

/* ---------------- One-time share code ---------------- */
function share_code_new(int $hid, int $memberId, bool $add, bool $view): array
{
    member_of($hid, $memberId);
    if (!$add && !$view) fail('nothing', 'Choose what the pharmacy may do.');
    q('DELETE FROM share_codes WHERE (member_id = ? AND used_at IS NULL) OR expires_at < NOW() - INTERVAL 1 DAY', [$memberId]);
    for ($i = 0; $i < 5; $i++) {
        $code = (string) random_int(100000, 999999);
        if (!q('SELECT 1 FROM share_codes WHERE code_hash = ? AND expires_at > NOW()', [hash('sha256', $code)])->fetch()) break;
    }
    q('INSERT INTO share_codes (member_id, code_hash, may_add, may_view, expires_at) VALUES (?,?,?,?, NOW() + INTERVAL 10 MINUTE)', [$memberId, hash('sha256', $code), $add ? 1 : 0, $view ? 1 : 0]);
    return ['code' => $code, 'minutes' => 10];
}

/* Used by card_member(): a 6-digit code works once, for 10 minutes, with the permissions chosen when it was made. */
function share_code_member(string $code): ?array
{
    if (!preg_match('/^\d{6}$/', $code)) return null;
    limit_check('sharecode:' . client_ip(), 10, 10);
    $s = q('SELECT * FROM share_codes WHERE code_hash = ? AND expires_at > NOW() AND (used_at IS NULL OR used_at > NOW() - INTERVAL 15 MINUTE)', [hash('sha256', $code)])->fetch();
    if (!$s) return null;
    q('UPDATE share_codes SET used_at = COALESCE(used_at, NOW()) WHERE id = ?', [$s['id']]);   // the same visit can look, check and add
    $m = q('SELECT m.*, u.id owner_id FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = ?', [$s['member_id']])->fetch();
    if (!$m) return null;
    $m['share_add'] = (int) $s['may_add']; $m['share_view'] = (int) $s['may_view'];
    $m['via_code'] = $code;   // the pharmacy keeps using this code for the safety check and adding medicines
    return $m;
}

function limit_check(string $key, int $max, int $minutes): void
{
    $n = (int) q('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at >= NOW() - INTERVAL ? MINUTE', [$key, $minutes])->fetchColumn();
    if ($n >= $max) fail('too_many', 'Too many tries. Wait a few minutes.', 429);
    q('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,0)', [client_ip(), $key]);
}

/* ---------------- FHIR R4 export (for ABDM health lockers and doctors' systems) ---------------- */
function fhir_bundle(int $hid, ?int $memberId = null): array
{
    $entries = [];
    $members = q('SELECT * FROM members WHERE user_id = ?' . ($memberId ? ' AND id = ' . (int) $memberId : ''), [$hid])->fetchAll();
    foreach ($members as $m) {
        $pid = 'patient-' . $m['id'];
        $pat = ['resourceType' => 'Patient', 'id' => $pid, 'name' => [['text' => $m['name']]]];
        if ($m['birth_date']) $pat['birthDate'] = $m['birth_date'];
        $entries[] = ['fullUrl' => "urn:uuid:$pid", 'resource' => $pat];
        if ($m['allergies']) foreach (array_filter(array_map('trim', explode(',', $m['allergies']))) as $i => $a)
            $entries[] = ['fullUrl' => "urn:uuid:allergy-{$m['id']}-$i", 'resource' => ['resourceType' => 'AllergyIntolerance', 'id' => "allergy-{$m['id']}-$i",
                'clinicalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical', 'code' => 'active']]],
                'code' => ['text' => $a], 'patient' => ['reference' => "urn:uuid:$pid"]]];
        foreach (q("SELECT c.*, i.ingredient FROM cabinet_items c LEFT JOIN medicine_info i ON i.id = c.info_id WHERE c.user_id = ? AND c.member_id = ?", [$hid, $m['id']])->fetchAll() as $c) {
            $active = $c['dose_times'] !== '' && (!$c['course_end'] || $c['course_end'] >= date('Y-m-d'));
            $times = array_filter(explode(',', $c['dose_times']));
            $ms = ['resourceType' => 'MedicationStatement', 'id' => 'med-' . $c['id'], 'status' => $active ? 'active' : 'completed',
                   'medicationCodeableConcept' => ['text' => $c['name'] . ($c['ingredient'] ? ' (' . $c['ingredient'] . ')' : '')], 'subject' => ['reference' => "urn:uuid:$pid"]];
            if ($times) $ms['dosage'] = [['text' => implode(', ', array_map(fn($s) => SLOT_LABELS[$s] ?? $s, $times)) . ((int) $c['per_dose'] > 1 ? ' × ' . $c['per_dose'] : ''),
                                         'timing' => ['repeat' => ['frequency' => count($times), 'period' => 1, 'periodUnit' => 'd']]]];
            if ($c['course_end']) $ms['effectivePeriod'] = ['end' => $c['course_end']];
            $entries[] = ['fullUrl' => 'urn:uuid:med-' . $c['id'], 'resource' => $ms];
        }
        $loinc = ['bp' => ['85354-9', 'Blood pressure panel'], 'sugar' => ['2339-0', 'Glucose [Mass/volume] in Blood'], 'weight' => ['29463-7', 'Body weight'],
                  'temp' => ['8310-5', 'Body temperature'], 'spo2' => ['59408-5', 'Oxygen saturation by pulse oximetry'], 'pulse' => ['8867-4', 'Heart rate'],
                  'hba1c' => ['4548-4', 'Hemoglobin A1c'], 'chol' => ['2093-3', 'Cholesterol [Mass/volume] in Serum or Plasma'], 'hb' => ['718-7', 'Hemoglobin [Mass/volume] in Blood']];
        $units = ['sugar' => 'mg/dL', 'weight' => 'kg', 'temp' => 'Cel', 'spo2' => '%', 'pulse' => '/min', 'hba1c' => '%', 'chol' => 'mg/dL', 'hb' => 'g/dL'];
        foreach (q('SELECT * FROM vitals WHERE user_id = ? AND member_id = ? ORDER BY taken_at DESC LIMIT 200', [$hid, $m['id']])->fetchAll() as $v) {
            if (!isset($loinc[$v['kind']])) continue;
            $o = ['resourceType' => 'Observation', 'id' => 'obs-' . $v['id'], 'status' => 'final', 'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => $loinc[$v['kind']][0], 'display' => $loinc[$v['kind']][1]]]],
                  'subject' => ['reference' => "urn:uuid:$pid"], 'effectiveDateTime' => str_replace(' ', 'T', $v['taken_at']) . '+05:30'];
            if ($v['kind'] === 'bp') $o['component'] = [
                ['code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8480-6', 'display' => 'Systolic blood pressure']]], 'valueQuantity' => ['value' => (float) $v['v1'], 'unit' => 'mm[Hg]']],
                ['code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8462-4', 'display' => 'Diastolic blood pressure']]], 'valueQuantity' => ['value' => (float) $v['v2'], 'unit' => 'mm[Hg]']]];
            else $o['valueQuantity'] = ['value' => (float) $v['v1'], 'unit' => $units[$v['kind']] ?? ''];
            $entries[] = ['fullUrl' => 'urn:uuid:obs-' . $v['id'], 'resource' => $o];
        }
    }
    return ['resourceType' => 'Bundle', 'type' => 'collection', 'timestamp' => date('c'), 'meta' => ['source' => 'AushadhBox'], 'entry' => $entries];
}

/* ---------------- Phones: missed-call doses and SMS reminders ---------------- */
function phone_norm(string $p): ?string
{
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
    if (strlen($d) === 11 && str_starts_with($d, '0')) $d = substr($d, 1);
    return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
}

/* A family member's phone is confirmed by a missed call from it to the verification number (no SMS cost, works on any phone). */
function member_phone_set(int $hid, int $memberId, string $phone, bool $sms): array
{
    member_of($hid, $memberId);
    $p = $phone === '' ? null : phone_norm($phone);
    if ($phone !== '' && !$p) fail('bad_phone', 'Enter a 10-digit Indian mobile number.');
    $cur = member_open(q('SELECT phone, phone_verified FROM members WHERE id = ?', [$memberId])->fetch());
    $verified = $p && $p === $cur['phone'] ? (int) $cur['phone_verified'] : 0;
    // Stored encrypted; the keyed hash lets a missed call find the member without decrypting every phone.
    q('UPDATE members SET phone = ?, phone_hash = ?, phone_verified = ?, sms_remind = ? WHERE id = ?', [seal($p), $p ? blind_index($p) : null, $verified, $sms && $p ? 1 : 0, $memberId]);
    return ['phone' => $p, 'verified' => (bool) $verified, 'sms' => $sms && $p, 'numbers' => telephony_numbers()];
}

function telephony_numbers(): array
{
    $t = (array) (config('telephony') ?? []);
    return ['verify' => $t['verify_number'] ?? null, 'dose' => $t['dose_number'] ?? null];
}

/* Webhook from the telephony provider (Exotel, Knowlarity, MyOperator...) for a missed call.
   to = which of our numbers was called; from = the caller. Returns what happened (for the provider log). */
function missed_call(string $from, string $to): array
{
    $p = phone_norm($from); $nums = telephony_numbers();
    if (!$p) return ['result' => 'bad_number'];
    $to = phone_norm($to) ?? preg_replace('/\D/', '', $to);
    $verifyNo = $nums['verify'] ? (phone_norm((string) $nums['verify']) ?? preg_replace('/\D/', '', (string) $nums['verify'])) : null;
    $doseNo = $nums['dose'] ? (phone_norm((string) $nums['dose']) ?? preg_replace('/\D/', '', (string) $nums['dose'])) : null;
    $members = q('SELECT id, user_id, name, phone_verified FROM members WHERE phone_hash = ?', [blind_index($p)])->fetchAll();
    if (!$members) return ['result' => 'unknown_caller'];
    if ($to === $verifyNo) {
        q('UPDATE members SET phone_verified = 1 WHERE phone_hash = ?', [blind_index($p)]);
        return ['result' => 'verified', 'members' => count($members)];
    }
    if ($to === $doseNo) {
        $done = 0;
        foreach ($members as $m) {
            if (!(int) $m['phone_verified']) continue;
            $r = tick_member_now((int) $m['user_id'], (int) $m['id'], 'call');
            $done += $r['ticked'];
        }
        return ['result' => $done ? 'ticked' : 'nothing_due', 'ticked' => $done];
    }
    return ['result' => 'unknown_number'];
}

/* Like tick_now() in watch.php, but only this person's medicines. */
function tick_member_now(int $hid, int $memberId, string $source): array
{
    $slot = current_slot($hid);
    if (!$slot) return ['ticked' => 0, 'slot' => null];
    $day = date('Y-m-d'); $n = 0;
    foreach (due_items($hid, $slot, $day, $memberId) as $i) {
        if (q('SELECT 1 FROM dose_logs WHERE item_id = ? AND slot = ? AND taken_on = ?', [$i['id'], $slot, $day])->fetch()) continue;
        if (dose_mark($hid, (int) $i['id'], $slot, $day, 'taken', null, $source)) $n++;
    }
    return ['ticked' => $n, 'slot' => $slot];
}

/* SMS through the provider in config.php ('sms' => [provider, key, sender, templates]). India requires DLT-registered
   templates; without a provider set, messages are only logged (so nothing is sent by accident). */
function sms_send(string $phone, string $template, array $vars, ?string $ref = null): bool
{
    $cfg = (array) (config('sms') ?? []);
    $tpl = $cfg['templates'][$template] ?? null;
    $text = $tpl['text'] ?? '';
    foreach ($vars as $k => $v) $text = str_replace('{' . $k . '}', (string) $v, $text);
    q('INSERT INTO sms_out (phone_hash, template, ref, body, status) VALUES (?,?,?,?,?)', [hash('sha256', $phone), $template, $ref, mb_substr($text, 0, 300), empty($cfg['key']) || !$tpl ? 'logged' : 'queued']);
    if (empty($cfg['key']) || !$tpl) return false;
    $id = (int) db()->lastInsertId();
    // MSG91 Flow API (others are similar): one HTTPS call with the DLT template id and variables.
    $ch = curl_init('https://control.msg91.com/api/v5/flow/');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['authkey: ' . $cfg['key'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['template_id' => $tpl['id'], 'short_url' => '0', 'recipients' => [['mobiles' => '91' . $phone] + $vars]])]);
    $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    q('UPDATE sms_out SET status = ? WHERE id = ?', [$code === 200 ? 'sent' : 'failed', $id]);
    return $code === 200 && $res !== false;
}

/* Cron, every 15 minutes: SMS dose reminders to members without a smartphone, at their dose times (once per slot). */
function sms_dose_reminders(?DateTime $now = null): int
{
    $now = $now ?? new DateTime();
    $sent = 0;
    foreach (q("SELECT m.id, m.user_id, m.name, m.phone FROM members m WHERE m.sms_remind = 1 AND m.phone IS NOT NULL AND m.phone_verified = 1")->fetchAll() as $m) {
        $times = owner_times((int) $m['user_id']);
        foreach (SLOTS as $s) {
            $diff = ((int) $now->format('H') * 60 + (int) $now->format('i')) - hm_minutes($times[$s]);
            if ($diff < 0 || $diff >= 15) continue;
            $due = due_items((int) $m['user_id'], $s, $now->format('Y-m-d'), (int) $m['id']);
            if (!$due) continue;
            $ref = $m['id'] . ':' . $s . ':' . $now->format('Y-m-d');
            if (q("SELECT 1 FROM sms_out WHERE template = 'dose' AND ref = ?", [$ref])->fetch()) continue;
            $names = implode(', ', array_slice(array_map(fn($i) => $i['name'], $due), 0, 3));
            sms_send((string) unseal($m['phone']), 'dose', ['name' => $m['name'], 'meds' => mb_substr($names, 0, 60), 'time' => SLOT_LABELS[$s]], $ref);
            $sent++;
        }
    }
    return $sent;
}

/* Cron, once each morning: repeat-prescription refills due, pregnancy check-ups starting and the free PMSMA check-up
   (9th of each month), children not gaining weight. Push to the household; nothing is sent twice for the same thing. */
function careplus_daily(DateTime $now): array
{
    $sent = ['repeat' => 0, 'anc' => 0, 'pmsma' => 0, 'growth' => 0];
    $push = function (int $hid, string $key, array $msg) use (&$sent): void {
        $kind = explode(':', $key)[0];
        if (q("SELECT 1 FROM sms_out WHERE template = 'push' AND ref = ?", [$hid . ':' . $key])->fetch()) return;
        q("INSERT INTO sms_out (phone_hash, template, ref, body, status) VALUES (?, 'push', ?, ?, 'logged')", [str_repeat('0', 64), $hid . ':' . $key, mb_substr($msg['title'], 0, 300)]);
        if (function_exists('push_to_user')) foreach (q('SELECT id FROM users WHERE id = ? OR household_id = ?', [$hid, $hid])->fetchAll() as $u) push_to_user((int) $u['id'], $msg);
        $sent[$kind]++;
    };
    $today = $now->format('Y-m-d');
    foreach (q('SELECT DISTINCT user_id FROM rx_repeats WHERE valid_until >= CURDATE() AND repeats_used < repeats_allowed')->fetchAll() as $r)
        foreach (rx_repeat_due((int) $r['user_id']) as $d)
            $push((int) $r['user_id'], 'repeat:' . $d['rxId'] . ':' . $d['name'] . ':' . $now->format('Y-W'), ['title' => $d['name'] . ' runs out in ' . $d['daysLeft'] . ' days',
                  'body' => 'Your repeat prescription still has ' . $d['repeatsLeft'] . ' refill(s). Order from your pharmacy in time.', 'url' => '/app#care', 'tag' => 'repeat-' . $d['rxId']]);
    foreach (q("SELECT p.*, m.name FROM pregnancies p JOIN members m ON m.id = p.member_id WHERE p.status = 'active'")->fetchAll() as $p) {
        $v = pregnancy_view($p, $today);
        foreach ($v['visits'] as $a) if ($a['state'] === 'now' && !$a['done'])
            $push((int) $p['user_id'], 'anc:' . $p['id'] . ':' . $a['n'], ['title' => $p['name'] . ': time for check-up ' . $a['n'], 'body' => $a['label'] . '. Free at the government health centre; your ASHA can help.', 'url' => '/app#care', 'tag' => 'anc-' . $p['id']]);
        if ($v['pmsma'] && $now->format('d') === '08')
            $push((int) $p['user_id'], 'pmsma:' . $p['id'] . ':' . $now->format('Y-m'), ['title' => 'Free pregnancy check-up tomorrow (PMSMA)', 'body' => 'On the 9th of every month, government hospitals give a free check-up by a doctor for pregnant women in months 4–9.', 'url' => '/app#care', 'tag' => 'pmsma']);
    }
    foreach (q('SELECT DISTINCT user_id FROM members WHERE birth_date > CURDATE() - INTERVAL 5 YEAR')->fetchAll() as $r)
        foreach (child_growth((int) $r['user_id']) as $g) if ($g['flag'])
            $push((int) $r['user_id'], 'growth:' . $g['memberId'] . ':' . end($g['weights'])['d'], ['title' => $g['name'] . ($g['flag'] === 'down' ? ' has lost weight' : ' has not gained weight this month'),
                  'body' => 'Please show the child to the anganwadi worker or a doctor.', 'url' => '/app#care', 'tag' => 'growth-' . $g['memberId']]);
    return $sent;
}
