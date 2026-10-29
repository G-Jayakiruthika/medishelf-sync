<?php
/* Smartwatches and "tap to tick", for every brand, not only Apple:
   - the watch page (/watch) and the dose reminders, which phones copy to Wear OS, Galaxy, Apple Watch, Fitbit, Garmin,
     Amazfit, Noise, boAt, Fire-Boltt and Mi bands with their Taken button;
   - a secret personal link that ticks the doses due now. It works from a Siri Shortcut (also on Apple Watch),
     a Google Assistant or Bixby routine, an NFC sticker or a printed QR on the medicine box;
   - importing readings that watches and health apps export (Apple Health, Samsung Health, Fitbit, Google Fit). */
declare(strict_types=1);

function tick_link_new(int $uid): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    q('UPDATE users SET tick_hash = ? WHERE id = ?', [hash('sha256', $token), $uid]);
    return base_url() . '/tick/' . $token;
}

function tick_user(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{24,64}$/', $token)) return null;
    return q('SELECT id, name, household_id FROM users WHERE tick_hash = ?', [hash('sha256', $token)])->fetch() ?: null;
}

/* The dose time that "now" belongs to: the closest one with doses still to take (from 2 hours before to 4 hours after),
   otherwise the latest earlier one today that still has doses to take. */
function current_slot(int $hid, ?DateTime $now = null): ?string
{
    $now = $now ?? new DateTime();
    $day = $now->format('Y-m-d');
    $min = (int) $now->format('H') * 60 + (int) $now->format('i');
    $times = owner_times($hid);
    $pending = function (string $s) use ($hid, $day) {
        foreach (due_items($hid, $s, $day) as $i) if (!q('SELECT 1 FROM dose_logs WHERE item_id = ? AND slot = ? AND taken_on = ?', [$i['id'], $s, $day])->fetch()) return true;
        return false;
    };
    $best = null;
    foreach (SLOTS as $s) {
        $d = $min - hm_minutes($times[$s]);
        if ($d >= -120 && $d <= 240 && $pending($s) && ($best === null || abs($d) < abs($min - hm_minutes($times[$best])))) $best = $s;
    }
    if ($best) return $best;
    foreach (array_reverse(SLOTS) as $s) if (hm_minutes($times[$s]) <= $min && $pending($s)) return $s;
    return null;
}

/* Ticks everything still due in the current slot. $source: watch, tick (link/NFC/Siri) or notif. */
function tick_now(int $hid, string $source): array
{
    $slot = current_slot($hid);
    if (!$slot) return ['ok' => true, 'slot' => null, 'ticked' => 0, 'message' => 'Nothing is due now. All done for the moment.'];
    $day = date('Y-m-d'); $n = 0; $names = [];
    foreach (due_items($hid, $slot, $day) as $i) {
        if (q('SELECT 1 FROM dose_logs WHERE item_id = ? AND slot = ? AND taken_on = ?', [$i['id'], $slot, $day])->fetch()) continue;
        if (dose_mark($hid, (int) $i['id'], $slot, $day, 'taken', null, $source === 'notif' ? 'notif' : 'app')) { $n++; $names[] = $i['name']; }
    }
    $label = SLOT_LABELS[$slot];
    return ['ok' => true, 'slot' => $slot, 'ticked' => $n, 'names' => $names, 'message' => $n ? "Marked $n $label medicine" . ($n > 1 ? 's' : '') . ' as taken: ' . implode(', ', $names) . '.' : "The $label medicines were already ticked."];
}

function watch_status(int $hid, int $uid): array
{
    $times = owner_times($hid); $day = date('Y-m-d');
    $slots = [];
    foreach (SLOTS as $s) {
        $due = due_items($hid, $s, $day);
        if (!$due) continue;
        $done = 0; $meds = [];
        foreach ($due as $i) {
            $l = q('SELECT status FROM dose_logs WHERE item_id = ? AND slot = ? AND taken_on = ?', [$i['id'], $s, $day])->fetchColumn();
            if ($l) $done++;
            $meds[] = ['name' => $i['name'], 'status' => $l ?: null];
        }
        $slots[] = ['slot' => $s, 'time' => $times[$s], 'meds' => $meds, 'done' => $done === count($due)];
    }
    return ['now' => current_slot($hid), 'slots' => $slots, 'date' => $day];
}

/* Readings from a watch or health-app export. Duplicates (same kind and time) are skipped. */
function vitals_import(int $hid, ?int $memberId, array $rows): array
{
    $added = 0; $skipped = 0; $urgent = 0;
    $ranges = ['bp' => [50, 260], 'sugar' => [20, 700], 'weight' => [1, 300], 'temp' => [30, 45], 'spo2' => [50, 100], 'pulse' => [20, 250]];
    foreach (array_slice($rows, 0, 2000) as $r) {
        if (!is_array($r)) { $skipped++; continue; }
        $k = (string) ($r['kind'] ?? ''); $v1 = (float) ($r['v1'] ?? 0); $v2 = isset($r['v2']) && $r['v2'] !== null ? (float) $r['v2'] : null;
        $at = (string) ($r['at'] ?? '');
        if (!isset($ranges[$k]) || $v1 < $ranges[$k][0] || $v1 > $ranges[$k][1] || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $at) || $at > date('Y-m-d H:i:s', time() + 3600) || $at < date('Y-m-d', strtotime('-2 year'))) { $skipped++; continue; }
        if ($k === 'bp' && ($v2 === null || $v2 >= $v1)) { $skipped++; continue; }
        if ($k !== 'bp') $v2 = null;
        if (q('SELECT 1 FROM vitals WHERE user_id = ? AND kind = ? AND taken_at = ? AND (member_id <=> ?)', [$hid, $k, strlen($at) === 16 ? "$at:00" : $at, $memberId])->fetch()) { $skipped++; continue; }
        $ctx = $k === 'sugar' && in_array($r['context'] ?? '', ['fasting', 'after_meal', 'random'], true) ? $r['context'] : null;
        $flag = vital_flag($k, $v1, $v2, $ctx);
        q('INSERT INTO vitals (user_id, member_id, kind, v1, v2, context, flag, note, taken_at) VALUES (?,?,?,?,?,?,?,?,?)', [$hid, $memberId, $k, $v1, $v2, $ctx, $flag, 'imported', $at]);
        $added++; if ($flag === 'urgent') $urgent++;
    }
    return ['ok' => true, 'added' => $added, 'skipped' => $skipped, 'outOfRange' => $urgent];
}
