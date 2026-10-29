<?php
/* Read-only visit sheet for a doctor, opened from a time-limited link (like a pharmacy's one-time code in Japanese apps).
   /share/{token} — no sign-in needed, never indexed, stops working when it expires or the family stops sharing. */
require_once APP_DIR . '/doses.php';
require_once APP_DIR . '/safety.php';
require_once APP_DIR . '/care.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

function share_page(string $token): void
{
    $link = preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token) ? q('SELECT * FROM share_links WHERE token_hash = ? AND expires_at > NOW()', [hash('sha256', $token)])->fetch() : null;
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $site = $e(config('site_name') ?: 'AushadhBox');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">',
         '<title>Shared medicine record · ', $site, '</title><style>body{font:15px/1.5 system-ui,sans-serif;margin:0;background:#F7F9F7;color:#1B2430}main{max-width:820px;margin:0 auto;padding:20px 16px 48px}',
         'h1{font-size:1.4rem;margin:0 0 4px}h2{font-size:1.05rem;margin:22px 0 6px;color:#1F3358}table{border-collapse:collapse;width:100%}td,th{text-align:left;border-bottom:1px solid #D8DED9;padding:6px;vertical-align:top}',
         '.muted{color:#5A6577}.warn{color:#A86C08;font-weight:600}.bad{color:#B3382A;font-weight:700}.card{background:#fff;border:1px solid #D8DED9;border-radius:14px;padding:16px;margin-top:12px}',
         '@media print{.no-print{display:none}body{background:#fff}}</style></head><body><main>';
    if (!$link) {
        echo '<h1>This link has expired</h1><p class="muted">Shared records stop working after the time the family chose, or when they stop sharing. Ask them to share again from the ', $site, ' app.</p></main></body></html>';
        return;
    }
    q('UPDATE share_links SET views = views + 1 WHERE id = ?', [$link['id']]);
    $s = visit_sheet((int) $link['user_id'], $link['member_id'] ? (int) $link['member_id'] : null);
    $p = $s['person'];
    $slots = ['m' => 'Morning', 'a' => 'Afternoon', 'e' => 'Evening', 'b' => 'Bedtime'];
    $labels = ['bp' => ['Blood pressure', 'mmHg'], 'sugar' => ['Blood sugar', 'mg/dL'], 'weight' => ['Weight', 'kg'], 'temp' => ['Temperature', '°C'], 'spo2' => ['Oxygen', '%'], 'pulse' => ['Pulse', '/min'],
               'hba1c' => ['HbA1c', '%'], 'chol' => ['Cholesterol', 'mg/dL'], 'hb' => ['Haemoglobin', 'g/dL']];
    $fmt = fn($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    echo '<p class="muted no-print">Shared by the family through ', $site, '. Read-only; this link stops working on ', $e(substr($link['expires_at'], 0, 16)), '. <button type="button" data-print>Print</button></p>';
    echo '<h1>', $e($p['name'] ?? 'Family'), $p && $p['bloodGroup'] ? ' · <span class="bad">' . $e($p['bloodGroup']) . '</span>' : '', '</h1><p class="muted">Medicine record as of ', $e($s['date']), '</p>';
    echo '<div class="card"><b>Allergies:</b> ', $e($p['allergies'] ?? '') ?: 'none recorded', $p && $p['conditions'] ? '<br><b>Conditions:</b> ' . $e($p['conditions']) : '', '</div>';
    echo '<h2>Medicines now</h2><table><tr><th>Medicine</th><th>When</th><th>Tablets left</th></tr>';
    foreach ($s['medicines'] as $m) echo '<tr><td><b>', $e($m['name']), '</b>', $m['ingredient'] ? '<br><span class="muted">' . $e($m['ingredient']) . '</span>' : '', '</td><td>',
        $e(implode(', ', array_map(fn($x) => $slots[$x] ?? $x, $m['times']))), $m['perDose'] > 1 ? ' × ' . (int) $m['perDose'] : '', '</td><td>', $m['left'] === null ? '—' : (int) $m['left'] . ' (' . (int) $m['daysLeft'] . ' days)', '</td></tr>';
    echo $s['medicines'] ? '' : '<tr><td colspan="3" class="muted">None on a schedule.</td></tr>', '</table>';
    foreach ($s['adherence'] as $a) echo '<h2>Doses taken, last 30 days</h2><p>', $e($a['name']), ': <b>', (int) $a['taken'], ' / ', (int) $a['expected'], ' (', (int) $a['percent'], '%)</b>', $a['skipped'] ? ', ' . (int) $a['skipped'] . ' skipped' : '', $a['worstSlot'] ? ' · most missed: ' . $e(strtolower($slots[$a['worstSlot']])) : '', '</p>';
    if ($s['followups']) { echo '<h2>How the medicines feel (family check-ins)</h2><ul>'; foreach ($s['followups'] as $f) echo '<li>', $e($f['name']), ': <span class="', in_array($f['answer'], ['worse', 'side'], true) ? 'warn' : '', '">', $e(['better' => 'better', 'same' => 'same', 'worse' => 'worse', 'side' => 'side effect'][$f['answer']] ?? $f['answer']), '</span>', $f['note'] ? ' — “' . $e($f['note']) . '”' : '', ' <span class="muted">(', $e($f['d']), ')</span></li>'; echo '</ul>'; }
    if ($s['skips']) { echo '<h2>Skipped doses and why</h2><ul>'; foreach ($s['skips'] as $k) echo '<li>', $e($k['name']), ': ', $e($k['reason']), ' × ', (int) $k['n'], '</li>'; echo '</ul>'; }
    if ($s['vitals']) {
        echo '<h2>Home readings, last 30 days</h2><table><tr><th>Reading</th><th>Latest</th><th>Average</th><th>Range</th><th>Outside normal</th></tr>';
        foreach ($s['vitals'] as $v) { [$lab, $unit] = $labels[$v['kind']] ?? [$v['kind'], ''];
            echo '<tr><td>', $e($lab), '</td><td>', $fmt($v['latest']['v1']), $v['latest']['v2'] !== null ? '/' . $fmt($v['latest']['v2']) : '', ' ', $e($unit), '</td><td>', $fmt($v['avg1']), $v['avg2'] !== null ? '/' . $fmt($v['avg2']) : '',
                 '</td><td>', $fmt($v['min1']), '–', $fmt($v['max1']), '</td><td>', $v['outOfRange'] ? '<span class="warn">' . (int) $v['outOfRange'] . ' of ' . (int) $v['count'] . '</span>' : '0 of ' . (int) $v['count'], '</td></tr>'; }
        echo '</table>';
    }
    if ($s['alerts']) { echo '<h2>Automatic safety check</h2><ul>'; foreach ($s['alerts'] as $a) echo '<li>', $e(strtoupper($a['level'])), ': ', $e($a['p']['a'] ?? ''), isset($a['p']['b']) ? ' + ' . $e($a['p']['b']) : '', ' (', $e($a['code'] . (isset($a['p']['why']) ? ': ' . $a['p']['why'] : '') . (isset($a['p']['allergy']) ? ': allergy to ' . $a['p']['allergy'] : '')), ')</li>'; echo '</ul>'; }
    if ($s['next'] && $s['next']['questions']) echo '<h2>The family\'s questions</h2><p>', $e($s['next']['questions']), '</p>';
    echo '<p class="muted" style="margin-top:28px;font-size:.85rem">Entered by the family; it may be incomplete. Not a prescription.</p></main><script src="/mini.js?v=1"></script></body></html>';
}
