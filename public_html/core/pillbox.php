<?php
/* Smart pillbox: turns "a lid was opened" into "the dose was probably taken".

   How a lid opening is read
   - Four-lid box: lid 1 = morning, 2 = afternoon, 3 = evening, 4 = bedtime.
     One-lid box: the dose time closest to the moment of opening.
   - Opened from 60 minutes before to 3 hours after the dose time → the doses due in that slot
     (for the person the box belongs to) are marked taken, source "box". The app shows them as
     "by pillbox", and anyone can untick if the guess was wrong.
   - Opened again more than 5 minutes after an opening that already counted → the family is warned
     that a second dose may have been taken (kind d).
   - Opened outside every dose window → recorded only (usually refilling the box).
   Opening a lid is not proof of swallowing. The box gives a strong hint and saves the tap; the
   missed-dose alert still fires when a compartment is not opened at all. */
declare(strict_types=1);

const BOX_BEFORE = 60, BOX_AFTER = 180, BOX_AGAIN_GAP = 5;
const BOX_LIDS = [1 => 'm', 2 => 'a', 3 => 'e', 4 => 'b'];

function device_by_token(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token)) return null;
    $d = q('SELECT * FROM devices WHERE token_hash = ?', [hash('sha256', $token)])->fetch();
    return $d ?: null;
}

function device_public(array $d): array
{
    $seen = $d['last_seen'] ? strtotime($d['last_seen']) : null;
    return ['id' => (int) $d['id'], 'name' => $d['name'], 'memberId' => $d['member_id'] ? (int) $d['member_id'] : null, 'compartments' => (int) $d['compartments'],
            'paired' => (bool) $d['token_hash'], 'pairCode' => $d['token_hash'] ? null : $d['pair_code'], 'pairExpires' => $d['token_hash'] ? null : $d['pair_expires'],
            'battery' => $d['battery'] === null ? null : (int) $d['battery'], 'firmware' => $d['firmware'], 'lastSeen' => $d['last_seen'],
            'online' => $seen !== null && time() - $seen < 20 * 60];
}

/* Which slot an opening belongs to, or null when it is outside every dose window. */
function box_slot(array $dev, int $lid, DateTime $at, array $times): ?string
{
    $min = (int) $at->format('H') * 60 + (int) $at->format('i');
    $in = fn(string $s) => $min >= hm_minutes($times[$s]) - BOX_BEFORE && $min <= hm_minutes($times[$s]) + BOX_AFTER;
    if ((int) $dev['compartments'] >= 4) { $s = BOX_LIDS[$lid] ?? null; return $s && $in($s) ? $s : null; }
    $best = null;
    foreach (SLOTS as $s) if ($in($s) && ($best === null || abs($min - hm_minutes($times[$s])) < abs($min - hm_minutes($times[$best])))) $best = $s;
    return $best;
}

/* Handles one event from the box. Returns the outcome stored with it. */
function box_event(array $dev, string $kind, ?int $lid, DateTime $at): string
{
    $hid = (int) $dev['user_id'];
    $outcome = null; $slot = null;
    if ($kind === 'open') {
        $times = owner_times($hid);
        $slot = box_slot($dev, (int) ($lid ?? 1), $at, $times);
        $day = $at->format('Y-m-d');
        if ($slot === null) $outcome = 'outside';
        else {
            $due = due_items($hid, $slot, $day, $dev['member_id'] ? (int) $dev['member_id'] : null);
            if (!$due) $outcome = 'nodose';
            else {
                $prev = q("SELECT MAX(happened_at) FROM device_events WHERE device_id = ? AND kind = 'open' AND slot = ? AND DATE(happened_at) = ? AND outcome IN ('taken','already','again')",
                          [$dev['id'], $slot, $day])->fetchColumn();
                $n = 0;
                foreach ($due as $i) if (dose_mark($hid, (int) $i['id'], $slot, $day, 'taken', null, 'box', $at->format('Y-m-d H:i:s'))) $n++;
                if ($n) $outcome = 'taken';
                elseif ($prev && $at->getTimestamp() - strtotime($prev) > BOX_AGAIN_GAP * 60
                        && q("SELECT 1 FROM device_events WHERE device_id = ? AND slot = ? AND DATE(happened_at) = ? AND outcome = 'taken'", [$dev['id'], $slot, $day])->fetch()) $outcome = 'again';
                else $outcome = 'already';
            }
        }
    }
    q('INSERT INTO device_events (device_id, kind, compartment, happened_at, slot, outcome) VALUES (?,?,?,?,?,?)',
      [$dev['id'], $kind, $lid, $at->format('Y-m-d H:i:s'), $slot, $outcome]);
    return $outcome ?? 'ok';
}

/* What the box needs to know: today's dose times per lid, and which are already done (lights them / stops the buzzer). */
function box_schedule(array $dev, DateTime $now): array
{
    $hid = (int) $dev['user_id'];
    $times = owner_times($hid);
    $day = $now->format('Y-m-d');
    $mid = $dev['member_id'] ? (int) $dev['member_id'] : null;
    $slots = [];
    foreach (SLOTS as $i => $s) {
        $due = due_items($hid, $s, $day, $mid);
        if (!$due) continue;
        $done = (int) q("SELECT COUNT(*) FROM dose_logs WHERE taken_on = ? AND slot = ? AND item_id IN (" . implode(',', array_map(fn($x) => (int) $x['id'], $due)) . ')', [$day, $s])->fetchColumn();
        $slots[] = ['slot' => $s, 'lid' => (int) $dev['compartments'] >= 4 ? $i + 1 : 1, 'time' => $times[$s], 'count' => count($due), 'done' => $done >= count($due)];
    }
    return ['ok' => true, 'name' => $dev['name'], 'now' => $now->getTimestamp(), 'utcOffset' => $now->getOffset(), 'windowBefore' => BOX_BEFORE, 'windowAfter' => BOX_AFTER, 'slots' => $slots];
}
