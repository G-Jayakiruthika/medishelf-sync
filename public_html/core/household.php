<?php
/* What Japan's household medication apps (EPARK お薬手帳 and others) do for the whole family, adapted for India:
   vaccination schedules made automatically, medical expenses (pharmacy bills added by themselves) with a
   tax-year export, read-only links for the doctor, and adherence streaks. */
declare(strict_types=1);

/* India's Universal Immunisation Programme (UIP) for children: [code, name, days after birth].
   Source: National Health Mission immunisation schedule. JE is given only in some districts, so it is left out. */
const UIP_SCHEDULE = [
    ['bcg', 'BCG', 0], ['hepb0', 'Hepatitis B (birth dose)', 0], ['opv0', 'OPV (birth dose)', 0],
    ['opv1', 'OPV 1', 42], ['penta1', 'Pentavalent 1', 42], ['rota1', 'Rotavirus 1', 42], ['fipv1', 'fIPV 1', 42], ['pcv1', 'PCV 1', 42],
    ['opv2', 'OPV 2', 70], ['penta2', 'Pentavalent 2', 70], ['rota2', 'Rotavirus 2', 70],
    ['opv3', 'OPV 3', 98], ['penta3', 'Pentavalent 3', 98], ['rota3', 'Rotavirus 3', 98], ['fipv2', 'fIPV 2', 98], ['pcv2', 'PCV 2', 98],
    ['mr1', 'MR 1 (measles-rubella) + Vitamin A', 274], ['pcvb', 'PCV booster', 274],
    ['dptb1', 'DPT booster 1', 487], ['opvb', 'OPV booster', 487], ['mr2', 'MR 2', 487],
    ['dptb2', 'DPT booster 2', 1826], ['td10', 'Td (10 years)', 3652], ['td16', 'Td (16 years)', 5844],
];

/* Creates (or updates) the schedule from the date of birth. Doses already marked as given are kept. */
function vaccines_make(int $hid, int $memberId, string $birth): int
{
    $n = 0;
    foreach (UIP_SCHEDULE as [$code, $name, $days]) {
        $due = date('Y-m-d', strtotime("$birth +$days day"));
        $n += q('INSERT INTO vaccinations (user_id, member_id, code, name, due_on) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE due_on = IF(given_on IS NULL, VALUES(due_on), due_on), name = VALUES(name)',
                [$hid, $memberId, $code, $name, $due])->rowCount() ? 1 : 0;
    }
    return $n;
}

function vaccines_list(int $hid): array
{
    return array_map(fn($r) => ['id' => (int) $r['id'], 'memberId' => (int) $r['member_id'], 'code' => $r['code'], 'name' => $r['name'], 'due' => $r['due_on'], 'given' => $r['given_on']],
        q('SELECT id, member_id, code, name, due_on, given_on FROM vaccinations WHERE user_id = ? ORDER BY due_on, id', [$hid])->fetchAll());
}

/* ---------- Medical expenses ---------- */
const EXPENSE_CATEGORIES = ['medicine', 'test', 'consult', 'checkup', 'other'];

function expense_add(int $hid, array $in, string $source = 'manual'): int
{
    $cat = in_array($in['category'] ?? '', EXPENSE_CATEGORIES, true) ? $in['category'] : 'medicine';
    $amount = round((float) str_replace(',', '', (string) ($in['amount'] ?? 0)), 2);
    if ($amount <= 0 || $amount > 1000000) fail('bad_amount', 'Enter the amount in rupees.');
    $on = (string) ($in['spent_on'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > date('Y-m-d', strtotime('+1 day'))) fail('bad_date', 'Choose the date of the expense.');
    $mid = (int) ($in['member_id'] ?? 0) ?: null;
    if ($mid && !q('SELECT 1 FROM members WHERE id = ? AND user_id = ?', [$mid, $hid])->fetch()) fail('not_found', 'That person was not found.', 404);
    q('INSERT INTO expenses (user_id, member_id, category, amount, spent_on, note, source) VALUES (?,?,?,?,?,?,?)',
      [$hid, $mid, $cat, $amount, $on, mb_substr(trim((string) ($in['note'] ?? '')), 0, 160) ?: null, $source]);
    return (int) db()->lastInsertId();
}

/* Indian financial year (April to March) that contains $date. */
function fin_year(?string $date = null): array
{
    $t = strtotime($date ?? date('Y-m-d'));
    $y = (int) date('Y', $t) - ((int) date('n', $t) < 4 ? 1 : 0);
    return ["$y-04-01", ($y + 1) . '-03-31', "FY $y-" . substr((string) ($y + 1), 2)];
}

function expenses_summary(int $hid, ?string $date = null): array
{
    [$from, $to, $label] = fin_year($date);
    $rows = q('SELECT e.id, e.member_id, m.name member, e.category, e.amount, e.spent_on, e.note, e.source FROM expenses e LEFT JOIN members m ON m.id = e.member_id
               WHERE e.user_id = ? AND e.spent_on BETWEEN ? AND ? ORDER BY e.spent_on DESC, e.id DESC', [$hid, $from, $to])->fetchAll();
    $byCat = array_fill_keys(EXPENSE_CATEGORIES, 0.0); $byPerson = [];
    foreach ($rows as $r) { $byCat[$r['category']] += (float) $r['amount']; $k = $r['member'] ?? 'Whole family'; $byPerson[$k] = ($byPerson[$k] ?? 0) + (float) $r['amount']; }
    return ['year' => $label, 'from' => $from, 'to' => $to, 'total' => round(array_sum($byCat), 2), 'byCategory' => $byCat, 'byPerson' => $byPerson,
            'items' => array_map(fn($r) => ['id' => (int) $r['id'], 'memberId' => $r['member_id'] ? (int) $r['member_id'] : null, 'member' => $r['member'], 'category' => $r['category'],
                                             'amount' => (float) $r['amount'], 'date' => $r['spent_on'], 'note' => $r['note'], 'source' => $r['source']], array_slice($rows, 0, 50))];
}

function expenses_csv(int $hid, ?string $date = null): string
{
    [$from, $to, $label] = fin_year($date);
    $out = fopen('php://temp', 'w+');
    fputcsv($out, ["Medical expenses $label ($from to $to) - AushadhBox"]);
    fputcsv($out, ['Date', 'Person', 'Category', 'Amount (Rs)', 'Note', 'Added by']);
    $total = 0.0;
    foreach (q('SELECT e.spent_on, m.name, e.category, e.amount, e.note, e.source FROM expenses e LEFT JOIN members m ON m.id = e.member_id WHERE e.user_id = ? AND e.spent_on BETWEEN ? AND ? ORDER BY e.spent_on', [$hid, $from, $to]) as $r) {
        fputcsv($out, [$r['spent_on'], $r['name'] ?? 'Whole family', $r['category'], number_format((float) $r['amount'], 2, '.', ''), $r['note'] ?? '', $r['source'] === 'bill' ? 'scanned bill' : 'typed']);
        $total += (float) $r['amount'];
    }
    fputcsv($out, ['', '', 'Total', number_format($total, 2, '.', '')]);
    fputcsv($out, ['Keep the original bills. Ask your tax adviser which expenses you can claim.']);
    rewind($out);
    return (string) stream_get_contents($out);
}

/* ---------- Read-only links for the doctor ---------- */
function share_create(int $hid, ?int $memberId, int $hours): array
{
    $hours = in_array($hours, [1, 24, 72], true) ? $hours : 24;
    if ((int) q('SELECT COUNT(*) FROM share_links WHERE user_id = ? AND expires_at > NOW()', [$hid])->fetchColumn() >= 10) fail('limit', 'You have 10 active links. Stop sharing an old one first.');
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    q('INSERT INTO share_links (user_id, member_id, token_hash, expires_at) VALUES (?,?,?, NOW() + INTERVAL ? HOUR)', [$hid, $memberId, hash('sha256', $token), $hours]);
    return ['url' => base_url() . '/share/' . $token, 'expires' => date('Y-m-d H:i', time() + $hours * 3600)];
}

function share_list(int $hid): array
{
    return array_map(fn($r) => ['id' => (int) $r['id'], 'memberId' => $r['member_id'] ? (int) $r['member_id'] : null, 'expires' => substr($r['expires_at'], 0, 16), 'views' => (int) $r['views']],
        q('SELECT id, member_id, expires_at, views FROM share_links WHERE user_id = ? AND expires_at > NOW() ORDER BY id DESC', [$hid])->fetchAll());
}

/* ---------- Adherence streak: days in a row with every planned dose taken or deliberately skipped ---------- */
function adherence_streaks(int $hid): array
{
    $out = [];
    $people = q('SELECT DISTINCT COALESCE(member_id, 0) mid FROM cabinet_items WHERE user_id = ? AND dose_times <> \'\'', [$hid])->fetchAll();
    foreach ($people as $p) {
        $mid = (int) $p['mid'];
        $streak = 0;
        for ($d = 1; $d <= 120; $d++) {
            $day = date('Y-m-d', strtotime("-$d day"));
            $items = q('SELECT id, dose_times FROM cabinet_items WHERE user_id = ? AND COALESCE(member_id, 0) = ? AND dose_times <> \'\' AND DATE(created_at) <= ? AND (course_end IS NULL OR course_end >= ?)', [$hid, $mid, $day, $day])->fetchAll();
            if (!$items) break;
            $expected = array_sum(array_map(fn($i) => count(array_filter(explode(',', $i['dose_times']))), $items));
            $logged = (int) q('SELECT COUNT(*) FROM dose_logs WHERE taken_on = ? AND item_id IN (' . implode(',', array_map(fn($i) => (int) $i['id'], $items)) . ')', [$day])->fetchColumn();
            if ($logged < $expected) break;
            $streak++;
        }
        $out[] = ['memberId' => $mid ?: null, 'days' => $streak];
    }
    return $out;
}
