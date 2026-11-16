<?php
/* Cold chain: a thermometer (ESP32 + DS18B20, see iot/coldchain) in the medicine fridge reports every 5 minutes.
   Insulin and vaccines must stay at 2-8 °C. Outside the range for 15 minutes, or no report for 30 minutes, the
   pharmacy is warned. The record also proves to an inspector (or a customer) that the cold chain was kept. */
declare(strict_types=1);

function device_pair(int $branchId, string $name): array
{
    $code = (string) random_int(100000, 999999);
    q('INSERT INTO devices (branch_id, name, pair_code, pair_until) VALUES (?,?,?, NOW() + INTERVAL 30 MINUTE)', [$branchId, mb_substr($name, 0, 60), $code]);
    return ['id' => (int) db()->lastInsertId(), 'pairCode' => $code];
}

/* The device exchanges the 6-digit code for a long secret token (kept as a hash here). */
function device_claim(string $code): ?array
{
    $d = q('SELECT * FROM devices WHERE pair_code = ? AND pair_until > NOW()', [preg_replace('/\D/', '', $code)])->fetch();
    if (!$d) return null;
    $token = bin2hex(random_bytes(24));
    q('UPDATE devices SET token_hash = ?, pair_code = NULL, pair_until = NULL WHERE id = ?', [hash('sha256', $token), $d['id']]);
    return ['token' => $token, 'deviceId' => (int) $d['id'], 'min' => (float) $d['min_c'], 'max' => (float) $d['max_c']];
}

function device_by_token(string $token): ?array
{
    return preg_match('/^[a-f0-9]{48}$/', $token) ? (q('SELECT * FROM devices WHERE token_hash = ?', [hash('sha256', $token)])->fetch() ?: null) : null;
}

/* readings: [{t: unix, c: temp}], buffered by the device while offline. Returns the state and whether to sound the buzzer. */
function device_report(array $d, array $readings, ?int $battery): array
{
    $last = null;
    foreach (array_slice($readings, -300) as $r) {
        if (!is_array($r) || !is_numeric($r['c'] ?? null)) continue;
        $c = round((float) $r['c'], 1); if ($c < -40 || $c > 80) continue;   // sensor error
        $t = isset($r['t']) && (int) $r['t'] > 1600000000 && (int) $r['t'] <= time() + 60 ? (int) $r['t'] : time();
        q('INSERT INTO readings (device_id, temp_c, at) VALUES (?,?,?)', [$d['id'], $c, date('Y-m-d H:i:s', $t)]);
        $last = $c;
    }
    q('UPDATE devices SET last_c = COALESCE(?, last_c), battery = COALESCE(?, battery), last_seen = NOW() WHERE id = ?', [$last, $battery, $d['id']]);
    return device_check(q('SELECT * FROM devices WHERE id = ?', [$d['id']])->fetch());
}

/* Out of range for the last 15 minutes (all readings), or silent for 30 minutes. */
function device_check(array $d): array
{
    $state = 'ok';
    if (!$d['last_seen'] || strtotime($d['last_seen']) < time() - 1800) $state = 'offline';
    else {
        $r = q('SELECT MIN(temp_c) lo, MAX(temp_c) hi, COUNT(*) n FROM readings WHERE device_id = ? AND at >= NOW() - INTERVAL 15 MINUTE', [$d['id']])->fetch();
        if ((int) $r['n'] >= 2 && (float) $r['lo'] > (float) $d['max_c']) $state = 'high';
        elseif ((int) $r['n'] >= 2 && (float) $r['hi'] < (float) $d['min_c']) $state = 'low';
    }
    if ($state !== $d['alert_state']) {
        q('UPDATE devices SET alert_state = ? WHERE id = ?', [$state, $d['id']]);
        $msg = ['high' => 'Fridge "' . $d['name'] . '" is too warm (' . $d['last_c'] . ' °C). Insulin and vaccines may be damaged: check the door and power.',
                'low' => 'Fridge "' . $d['name'] . '" is too cold (' . $d['last_c'] . ' °C). Freezing damages insulin: move it away from the freezer.',
                'offline' => 'Fridge thermometer "' . $d['name'] . '" stopped reporting. Check power and Wi-Fi.', 'ok' => 'Fridge "' . $d['name'] . '" is back in range.'][$state];
        q("INSERT INTO insights (branch_id, kind, title, data) VALUES (?, 'coldchain', ?, ?)", [$d['branch_id'], $msg, json_encode(['deviceId' => (int) $d['id'], 'state' => $state, 'temp' => $d['last_c']])]);
        audit('device', (int) $d['id'], null, (int) $d['branch_id'], 'coldchain_' . $state, $msg);
    }
    return ['state' => $state, 'buzz' => in_array($state, ['high', 'low'], true), 'min' => (float) $d['min_c'], 'max' => (float) $d['max_c']];
}

/* Daily min/max per day for the inspection log (last 30 days). */
function device_log(int $deviceId): array
{
    return q('SELECT DATE(at) d, MIN(temp_c) lo, MAX(temp_c) hi, ROUND(AVG(temp_c), 1) avg, COUNT(*) n FROM readings WHERE device_id = ? AND at >= CURDATE() - INTERVAL 30 DAY GROUP BY DATE(at) ORDER BY d DESC', [$deviceId])->fetchAll();
}
