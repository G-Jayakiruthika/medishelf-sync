<?php
/* Ideas from Japanese medication-notebook apps (お薬手帳) and pharmacy follow-up practice:
   health diary, follow-up check-ins after a new medicine, doctor-visit sheets with leftover tablets,
   and watch-over for parents living alone. */
declare(strict_types=1);

const VITAL_KINDS = ['bp', 'sugar', 'weight', 'temp', 'spo2', 'pulse', 'hba1c', 'chol', 'hb', 'steps'];   // steps: daily count (EPARK records steps too)   // the last three are lab results from a health check-up

/* Conservative, widely used home-measurement ranges. Returns ok, low, high or urgent. */
function vital_flag(string $kind, float $v1, ?float $v2 = null, ?string $context = null): string
{
    switch ($kind) {
        case 'bp':
            if ($v1 >= 180 || ($v2 ?? 0) >= 120) return 'urgent';
            if ($v1 < 90 || ($v2 !== null && $v2 < 60)) return 'low';
            return $v1 >= 140 || ($v2 ?? 0) >= 90 ? 'high' : 'ok';
        case 'sugar':
            if ($v1 < 54 || $v1 >= 300) return 'urgent';
            if ($v1 < 70) return 'low';
            return $v1 >= ($context === 'fasting' ? 126 : 200) ? 'high' : 'ok';
        case 'temp':
            if ($v1 >= 40 || $v1 < 35) return 'urgent';
            return $v1 >= 38 ? 'high' : 'ok';
        case 'spo2':
            if ($v1 < 92) return 'urgent';
            return $v1 < 95 ? 'low' : 'ok';
        case 'hba1c': return $v1 >= 6.5 ? 'high' : 'ok';
        case 'chol': return $v1 >= 240 ? 'high' : 'ok';
        case 'hb':
            if ($v1 < 7) return 'urgent';
            return $v1 < 11 ? 'low' : 'ok';
        case 'pulse':
            if ($v1 < 40 || $v1 > 130) return 'urgent';
            if ($v1 < 50) return 'low';
            return $v1 > 100 ? 'high' : 'ok';
    }
    return 'ok';
}

/* Validates and stores one reading. Temperatures in °F are converted to °C. */
function vital_save(int $hid, array $in): array
{
    $kind = (string) ($in['kind'] ?? '');
    if (!in_array($kind, VITAL_KINDS, true)) fail('bad_kind', 'Choose what you measured.');
    $v1 = (float) ($in['v1'] ?? 0); $v2 = isset($in['v2']) && $in['v2'] !== '' && $in['v2'] !== null ? (float) $in['v2'] : null;
    if ($kind === 'temp' && $v1 > 45) $v1 = round(($v1 - 32) * 5 / 9, 1);
    $ranges = ['bp' => [50, 260], 'sugar' => [20, 700], 'weight' => [1, 300], 'temp' => [30, 45], 'spo2' => [50, 100], 'pulse' => [20, 250], 'hba1c' => [3, 20], 'chol' => [50, 600], 'hb' => [3, 25], 'steps' => [0, 80000]];
    if ($v1 < $ranges[$kind][0] || $v1 > $ranges[$kind][1]) fail('bad_value', 'That number looks wrong. Please check it.');
    if ($kind === 'bp' && ($v2 === null || $v2 < 30 || $v2 > 160 || $v2 >= $v1)) fail('bad_value', 'Enter both blood pressure numbers, for example 130 / 85.');
    if ($kind !== 'bp') $v2 = null;
    $ctx = $kind === 'sugar' && in_array($in['context'] ?? '', ['fasting', 'after_meal', 'random'], true) ? $in['context'] : null;
    $mid = (int) ($in['member_id'] ?? 0) ?: null;
    if ($mid && !q('SELECT 1 FROM members WHERE id = ? AND user_id = ?', [$mid, $hid])->fetch()) fail('not_found', 'That person was not found.', 404);
    $flag = vital_flag($kind, $v1, $v2, $ctx);
    q('INSERT INTO vitals (user_id, member_id, kind, v1, v2, context, flag, note) VALUES (?,?,?,?,?,?,?,?)',
      [$hid, $mid, $kind, $v1, $v2, $ctx, $flag, mb_substr(trim((string) ($in['note'] ?? '')), 0, 200) ?: null]);
    return ['id' => (int) db()->lastInsertId(), 'flag' => $flag, 'v1' => $v1, 'v2' => $v2];
}

function vitals_list(int $hid, ?int $memberId, int $days = 90): array
{
    $sql = 'SELECT id, member_id memberId, kind, v1, v2, context, flag, note, taken_at at FROM vitals WHERE user_id = ? AND taken_at >= NOW() - INTERVAL ? DAY';
    $args = [$hid, $days];
    if ($memberId) { $sql .= ' AND member_id = ?'; $args[] = $memberId; }
    return array_map(fn($r) => ['id' => (int) $r['id'], 'memberId' => $r['memberId'] ? (int) $r['memberId'] : null, 'kind' => $r['kind'], 'v1' => (float) $r['v1'], 'v2' => $r['v2'] === null ? null : (float) $r['v2'],
                                 'context' => $r['context'], 'flag' => $r['flag'], 'note' => $r['note'], 'at' => $r['at']], q($sql . ' ORDER BY taken_at DESC LIMIT 400', $args)->fetchAll());
}

/* ---------- Follow-up check-ins ---------- */
function followups_schedule(int $hid, int $itemId, ?string $courseEnd = null): void
{
    foreach ([1 => 3, 2 => 10] as $step => $days) {
        $due = date('Y-m-d', strtotime("+$days day"));
        if ($step === 2 && $courseEnd && $courseEnd < $due) continue;   // a 5-day course gets only the day-3 check
        q('INSERT IGNORE INTO followups (user_id, item_id, due_on, step) VALUES (?,?,?,?)', [$hid, $itemId, $due, $step]);
    }
}

function followups_due(int $hid): array
{
    return array_map(fn($r) => ['id' => (int) $r['id'], 'itemId' => (int) $r['item_id'], 'name' => $r['name'], 'memberId' => $r['member_id'] ? (int) $r['member_id'] : null, 'step' => (int) $r['step'], 'due' => $r['due_on']],
        q("SELECT f.id, f.item_id, f.step, f.due_on, c.name, c.member_id FROM followups f JOIN cabinet_items c ON c.id = f.item_id
           WHERE f.user_id = ? AND f.answer IS NULL AND f.due_on BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() ORDER BY f.due_on", [$hid])->fetchAll());
}

function followup_answer(int $hid, int $id, string $answer, string $note): array
{
    if (!in_array($answer, ['better', 'same', 'worse', 'side'], true)) fail('bad_answer', 'Choose how it is going.');
    $f = q('SELECT id FROM followups WHERE id = ? AND user_id = ?', [$id, $hid])->fetch();
    if (!$f) fail('not_found', 'That question was not found.', 404);
    q('UPDATE followups SET answer = ?, note = ?, answered_at = NOW() WHERE id = ?', [$answer, mb_substr(trim($note), 0, 300) ?: null, $id]);
    // If another check-in for the same medicine is still waiting and this one went well, it stays; a problem moves the next check-in closer.
    if (in_array($answer, ['worse', 'side'], true)) q('UPDATE followups SET due_on = LEAST(due_on, CURDATE() + INTERVAL 2 DAY) WHERE item_id = (SELECT item_id FROM (SELECT item_id FROM followups WHERE id = ?) x) AND answer IS NULL', [$id]);
    return ['ok' => true, 'answer' => $answer];
}

/* ---------- Appointments ---------- */
function appointments_list(int $hid): array
{
    return array_map(fn($r) => ['id' => (int) $r['id'], 'memberId' => $r['member_id'] ? (int) $r['member_id'] : null, 'doctor' => $r['doctor'], 'place' => $r['place'], 'at' => $r['at'], 'questions' => $r['questions']],
        q('SELECT * FROM appointments WHERE user_id = ? AND at >= NOW() - INTERVAL 30 DAY ORDER BY at LIMIT 30', [$hid])->fetchAll());
}

/* Everything to take to the doctor for one person (works like the visit summary of お薬手帳 apps). */
function visit_sheet(int $hid, ?int $memberId): array
{
    $m = $memberId ? q('SELECT id, name, relation, allergies, blood_group, conditions FROM members WHERE id = ? AND user_id = ?', [$memberId, $hid])->fetch() : null;
    if ($memberId && !$m) fail('not_found', 'That person was not found.', 404);
    $cond = $memberId ? 'AND (c.member_id = ? OR c.member_id IS NULL)' : '';
    $args = $memberId ? [$hid, $memberId] : [$hid];
    $meds = q("SELECT c.id, c.name, c.dose_times, c.per_dose, c.stock_count, c.course_end, c.notes, c.exp_month, i.ingredient FROM cabinet_items c LEFT JOIN medicine_info i ON i.id = c.info_id
               WHERE c.user_id = ? $cond AND c.dose_times <> '' AND (c.course_end IS NULL OR c.course_end >= CURDATE() - INTERVAL 30 DAY) ORDER BY c.name", $args)->fetchAll();
    $medsOut = array_map(function ($r) {
        $perDay = count(array_filter(explode(',', $r['dose_times']))) * max(1, (int) $r['per_dose']);
        return ['name' => $r['name'], 'ingredient' => $r['ingredient'], 'times' => array_values(array_filter(explode(',', $r['dose_times']))), 'perDose' => (int) $r['per_dose'],
                'left' => $r['stock_count'] === null ? null : (int) $r['stock_count'], 'daysLeft' => $r['stock_count'] === null || !$perDay ? null : intdiv((int) $r['stock_count'], $perDay),
                'courseEnd' => $r['course_end'], 'notes' => $r['notes']];
    }, $meds);
    $adh = adherence($hid, date('Y-m-d', strtotime('-30 day')), date('Y-m-d', strtotime('-1 day')));
    $adhOut = array_values(array_filter($adh['people'], fn($p) => $memberId ? $p['memberId'] === $memberId : true));
    $fu = q("SELECT c.name, f.answer, f.note, DATE(f.answered_at) d FROM followups f JOIN cabinet_items c ON c.id = f.item_id
             WHERE f.user_id = ? " . ($memberId ? 'AND c.member_id = ?' : '') . " AND f.answered_at >= NOW() - INTERVAL 60 DAY ORDER BY f.answered_at DESC", $args)->fetchAll();
    $skips = q("SELECT c.name, d.reason, COUNT(*) n FROM dose_logs d JOIN cabinet_items c ON c.id = d.item_id WHERE d.user_id = ? " . ($memberId ? 'AND c.member_id = ?' : '') .
               " AND d.status = 'skipped' AND d.taken_on >= CURDATE() - INTERVAL 30 DAY GROUP BY c.name, d.reason", $args)->fetchAll();
    $vit = [];
    foreach (VITAL_KINDS as $k) {
        $rows = q('SELECT v1, v2, flag, taken_at FROM vitals WHERE user_id = ? AND kind = ? ' . ($memberId ? 'AND member_id = ? ' : '') . 'AND taken_at >= NOW() - INTERVAL 30 DAY ORDER BY taken_at DESC',
                  $memberId ? [$hid, $k, $memberId] : [$hid, $k])->fetchAll();
        if (!$rows) continue;
        $a = array_map(fn($r) => (float) $r['v1'], $rows); $b = array_filter(array_map(fn($r) => $r['v2'] === null ? null : (float) $r['v2'], $rows), fn($x) => $x !== null);
        $vit[] = ['kind' => $k, 'count' => count($rows), 'latest' => ['v1' => (float) $rows[0]['v1'], 'v2' => $rows[0]['v2'] === null ? null : (float) $rows[0]['v2'], 'at' => $rows[0]['taken_at']],
                  'avg1' => round(array_sum($a) / count($a), 1), 'avg2' => $b ? round(array_sum($b) / count($b), 1) : null, 'min1' => min($a), 'max1' => max($a),
                  'outOfRange' => count(array_filter($rows, fn($r) => $r['flag'] !== 'ok'))];
    }
    $next = q('SELECT doctor, place, at, questions FROM appointments WHERE user_id = ? ' . ($memberId ? 'AND (member_id = ? OR member_id IS NULL) ' : '') . 'AND at >= NOW() ORDER BY at LIMIT 1', $args)->fetch() ?: null;
    $alerts = array_values(array_filter(safety_alerts($hid), fn($a) => !$memberId || $a['memberId'] === $memberId || $a['memberId'] === null));
    return ['person' => $m ? ['name' => $m['name'], 'relation' => $m['relation'], 'allergies' => $m['allergies'], 'bloodGroup' => $m['blood_group'], 'conditions' => $m['conditions']] : null,
            'date' => date('Y-m-d'), 'medicines' => $medsOut, 'adherence' => $adhOut, 'followups' => $fu, 'skips' => $skips, 'vitals' => $vit, 'next' => $next, 'alerts' => $alerts];
}

/* ---------- Watch-over (見守り): when did this person last show a sign of life? ---------- */
function last_sign_of_life(int $hid, array $member): ?string
{
    $times = [];
    if ($member['account_id']) $times[] = q('SELECT last_seen_at FROM users WHERE id = ?', [$member['account_id']])->fetchColumn() ?: null;
    $times[] = q("SELECT MAX(d.taken_at) FROM dose_logs d JOIN cabinet_items c ON c.id = d.item_id WHERE d.user_id = ? AND c.member_id = ? AND d.status = 'taken'", [$hid, $member['id']])->fetchColumn() ?: null;
    $times[] = q("SELECT MAX(e.happened_at) FROM device_events e JOIN devices v ON v.id = e.device_id WHERE v.user_id = ? AND v.member_id = ? AND e.kind IN ('open', 'activity')", [$hid, $member['id']])->fetchColumn() ?: null;
    $times[] = q('SELECT MAX(taken_at) FROM vitals WHERE user_id = ? AND member_id = ?', [$hid, $member['id']])->fetchColumn() ?: null;
    $times = array_filter($times);
    return $times ? max($times) : null;
}
