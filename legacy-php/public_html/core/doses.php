<?php
/* Doses: taken, skipped (with a reason) or not confirmed. Shared by the app, notification buttons and the smart pillbox. */
declare(strict_types=1);

const SKIP_REASONS = ['fine', 'side', 'out', 'doctor', 'other'];
const SLOT_LABELS = ['m' => 'morning', 'a' => 'afternoon', 'e' => 'evening', 'b' => 'bedtime'];

/* The family's dose clock (the cabinet owner's reminder times). */
function owner_times(int $hid): array
{
    $r = q('SELECT m_time, a_time, e_time, b_time FROM reminder_settings WHERE user_id = ?', [$hid])->fetch() ?: [];
    return ['m' => $r['m_time'] ?? '08:00', 'a' => $r['a_time'] ?? '13:30', 'e' => $r['e_time'] ?? '19:30', 'b' => $r['b_time'] ?? '22:00'];
}

function hm_minutes(string $hm): int { [$h, $m] = array_map('intval', explode(':', $hm)); return $h * 60 + $m; }

/* Medicines due in a time slot on a day (optionally only one person's). */
function due_items(int $hid, string $slot, string $day, ?int $memberId = null): array
{
    $sql = 'SELECT id, name, member_id, per_dose, stock_count FROM cabinet_items WHERE user_id = ? AND FIND_IN_SET(?, dose_times) AND (course_end IS NULL OR course_end >= ?)';
    $args = [$hid, $slot, $day];
    if ($memberId) { $sql .= ' AND member_id = ?'; $args[] = $memberId; }
    return q($sql . ' ORDER BY id', $args)->fetchAll();
}

/* Records a dose as taken or skipped and keeps the tablet count right. Returns true when something changed. */
function dose_mark(int $hid, int $itemId, string $slot, string $day, string $status = 'taken', ?string $reason = null, string $source = 'app', ?string $at = null): bool
{
    $at = $at ?? date('Y-m-d H:i:s');
    $cur = q('SELECT id, status FROM dose_logs WHERE item_id = ? AND slot = ? AND taken_on = ?', [$itemId, $slot, $day])->fetch();
    if ($cur && $cur['status'] === $status) {
        if ($status === 'skipped') q('UPDATE dose_logs SET reason = ? WHERE id = ?', [$reason, $cur['id']]);
        return false;
    }
    if ($cur) q('UPDATE dose_logs SET status = ?, reason = ?, source = ?, taken_at = ? WHERE id = ?', [$status, $reason, $source, $at, $cur['id']]);
    else q('INSERT INTO dose_logs (user_id, item_id, slot, taken_on, taken_at, status, reason, source) VALUES (?,?,?,?,?,?,?,?)', [$hid, $itemId, $slot, $day, $at, $status, $reason, $source]);
    if ($status === 'taken') stock_change($itemId, -1);
    elseif ($cur && $cur['status'] === 'taken') stock_change($itemId, +1);
    return true;
}

/* Back to "not confirmed". */
function dose_unmark(int $hid, int $itemId, string $slot, string $day): bool
{
    $cur = q('SELECT id, status FROM dose_logs WHERE user_id = ? AND item_id = ? AND slot = ? AND taken_on = ?', [$hid, $itemId, $slot, $day])->fetch();
    if (!$cur) return false;
    q('DELETE FROM dose_logs WHERE id = ?', [$cur['id']]);
    if ($cur['status'] === 'taken') stock_change($itemId, +1);
    return true;
}

function stock_change(int $itemId, int $sign): void
{
    if ($sign < 0) q('UPDATE cabinet_items SET stock_count = GREATEST(CAST(stock_count AS SIGNED) - CAST(per_dose AS SIGNED), 0) WHERE id = ? AND stock_count IS NOT NULL', [$itemId]);
    else q('UPDATE cabinet_items SET stock_count = LEAST(stock_count + per_dose, 9999) WHERE id = ? AND stock_count IS NOT NULL', [$itemId]);
}

/* What a skip reason does by itself:
   out    → stock set to 0, so it goes on the shopping list and the refill alert fires
   doctor → reminders for this medicine stop from tomorrow
   side   → the family is told (cron, kind s) so someone can call the doctor */
function skip_effects(int $itemId, string $reason, string $day): void
{
    if ($reason === 'out') q('UPDATE cabinet_items SET stock_count = 0 WHERE id = ?', [$itemId]);
    if ($reason === 'doctor') q('UPDATE cabinet_items SET course_end = ? WHERE id = ? AND (course_end IS NULL OR course_end > ?)', [$day, $itemId, $day]);
}

/* Taken / skipped / missed per person over a date range (inclusive). Days before a medicine was added and after its course ended don't count. */
function adherence(int $hid, string $from, string $to): array
{
    $members = array_column(q('SELECT id, name FROM members WHERE user_id = ?', [$hid])->fetchAll(), 'name', 'id');
    $items = q("SELECT id, name, member_id, dose_times, DATE(created_at) added, course_end FROM cabinet_items WHERE user_id = ? AND dose_times <> ''", [$hid])->fetchAll();
    $logs = [];
    $times = owner_times($hid);
    foreach (q('SELECT item_id, slot, taken_on, status, reason, source, taken_at FROM dose_logs WHERE user_id = ? AND taken_on BETWEEN ? AND ?', [$hid, $from, $to])->fetchAll() as $l)
        $logs[$l['item_id'] . ':' . $l['slot'] . ':' . $l['taken_on']] = $l;
    $people = [];
    $blank = fn($name) => ['name' => $name, 'expected' => 0, 'taken' => 0, 'skipped' => 0, 'missed' => 0, 'box' => 0,
                           'bySlot' => array_fill_keys(SLOTS, ['expected' => 0, 'taken' => 0]), 'reasons' => [], 'medicines' => [],
                           'wk' => [0, 0, 0, 0], 'late' => array_fill_keys(SLOTS, [])];   // wk: weekday expected/missed, weekend expected/missed
    for ($d = new DateTime($from); $d->format('Y-m-d') <= $to; $d->modify('+1 day')) {
        $day = $d->format('Y-m-d');
        foreach ($items as $i) {
            if ($i['added'] > $day || ($i['course_end'] && $i['course_end'] < $day)) continue;
            $pid = (int) ($i['member_id'] ?? 0);
            $people[$pid] ??= $blank($pid && isset($members[$pid]) ? $members[$pid] : 'Whole family');
            $p = &$people[$pid];
            $p['medicines'][$i['name']] ??= ['expected' => 0, 'taken' => 0];
            $weekend = (int) $d->format('N') >= 6 ? 2 : 0;
            foreach (array_filter(explode(',', $i['dose_times'])) as $slot) {
                $p['expected']++; $p['bySlot'][$slot]['expected']++; $p['medicines'][$i['name']]['expected']++; $p['wk'][$weekend]++;
                $l = $logs[$i['id'] . ':' . $slot . ':' . $day] ?? null;
                if ($l && $l['status'] === 'taken') {
                    $p['taken']++; $p['bySlot'][$slot]['taken']++; $p['medicines'][$i['name']]['taken']++;
                    if ($l['source'] === 'box') $p['box']++;
                    $p['late'][$slot][] = hm_minutes(substr($l['taken_at'], 11, 5)) - hm_minutes($times[$slot]);
                } elseif ($l) { $p['skipped']++; $r = $l['reason'] ?: 'other'; $p['reasons'][$r] = ($p['reasons'][$r] ?? 0) + 1; }
                else { $p['missed']++; $p['wk'][$weekend + 1]++; }
            }
            unset($p);
        }
    }
    $out = [];
    foreach ($people as $pid => $p) {
        if (!$p['expected']) continue;
        $p['percent'] = (int) round(100 * $p['taken'] / $p['expected']);
        $worst = null;
        foreach ($p['bySlot'] as $s => $v) if ($v['expected'] >= 2 && ($worst === null || $v['taken'] / $v['expected'] < $p['bySlot'][$worst]['taken'] / $p['bySlot'][$worst]['expected'])) $worst = $s;
        $p['worstSlot'] = $worst !== null && $p['bySlot'][$worst]['taken'] < $p['bySlot'][$worst]['expected'] ? $worst : null;
        $p['memberId'] = $pid ?: null;
        // Patterns worth telling the family (codes, translated in the app)
        $p['patterns'] = [];
        [$we, $wm, $ee, $em] = $p['wk'];
        if ($ee >= 4 && $we >= 6 && $em / $ee >= 0.3 && $em / $ee >= 2 * max($wm / $we, 0.05)) $p['patterns'][] = ['code' => 'weekend', 'pct' => (int) round(100 * $em / $ee)];
        foreach ($p['late'] as $s => $mins) if (count($mins) >= 4) { sort($mins); $med = $mins[intdiv(count($mins), 2)]; if ($med >= 45) $p['patterns'][] = ['code' => 'late', 'slot' => $s, 'min' => (int) $med]; }
        if ($p['box'] && $p['box'] >= 0.8 * $p['taken'] && $p['taken'] >= 5) $p['patterns'][] = ['code' => 'boxgood'];
        unset($p['wk'], $p['late']);
        $p['medicines'] = array_map(fn($n, $v) => ['name' => $n] + $v, array_keys($p['medicines']), $p['medicines']);
        $out[] = $p;
    }
    usort($out, fn($a, $b) => $a['percent'] <=> $b['percent']);
    return ['from' => $from, 'to' => $to, 'people' => $out];
}
