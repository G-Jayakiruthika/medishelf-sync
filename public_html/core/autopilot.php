<?php
/* Admin autopilot: metrics, anomaly detection, "ask your data", the daily digest and growth automation.
   Everything is computed from the database with fixed, read-only queries. The optional AI (core/ai.php)
   only picks which metric a question is about and writes sentences around the numbers computed here. */
declare(strict_types=1);
require_once __DIR__ . '/ai.php';

/* ---------- Metric catalogue ---------- */
/* Each daily metric: [label, unit, SQL returning (d, n) between :from and :to (dates), words people use for it, "good" direction] */
function metric_catalog(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $demo = "rp_order_id NOT LIKE 'demo\\_%'";
    $m = [
        'signups'      => ['Sign-ups', 'count', 'SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY d', ['sign up', 'signup', 'sign-up', 'new user', 'registration', 'joined', 'new account'], 'up'],
        'active'       => ['Families ticking doses', 'count', "SELECT taken_on d, COUNT(DISTINCT user_id) n FROM dose_logs WHERE taken_on BETWEEN ? AND ? GROUP BY d", ['active', 'engaged', 'families using', 'daily active', 'dau', 'usage'], 'up'],
        'doses'        => ['Doses taken', 'count', "SELECT taken_on d, COUNT(*) n FROM dose_logs WHERE status = 'taken' AND taken_on BETWEEN ? AND ? GROUP BY d", ['dose', 'ticked', 'taken', 'adherence'], 'up'],
        'skips'        => ['Doses skipped', 'count', "SELECT taken_on d, COUNT(*) n FROM dose_logs WHERE status = 'skipped' AND taken_on BETWEEN ? AND ? GROUP BY d", ['skip', 'skipped'], 'down'],
        'medicines'    => ['Medicines added', 'count', 'SELECT DATE(created_at) d, COUNT(*) n FROM cabinet_items WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY d', ['medicine added', 'medicines added', 'items', 'scans', 'added'], 'up'],
        'revenue'      => ['Revenue (₹)', 'money', "SELECT DATE(paid_at) d, SUM(amount_paise)/100 n FROM payments WHERE status = 'paid' AND $demo AND paid_at >= ? AND paid_at < ? + INTERVAL 1 DAY GROUP BY d", ['revenue', 'money', 'earn', 'income', 'sales', 'rupees', '₹'], 'up'],
        'purchases'    => ['Premium purchases', 'count', "SELECT DATE(paid_at) d, COUNT(*) n FROM payments WHERE status = 'paid' AND $demo AND paid_at >= ? AND paid_at < ? + INTERVAL 1 DAY GROUP BY d", ['purchase', 'bought', 'upgrade', 'subscriptions sold', 'paid'], 'up'],
        'failed'       => ['Failed payments', 'count', "SELECT DATE(created_at) d, COUNT(*) n FROM payments WHERE status = 'failed' AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY d", ['failed payment', 'payment fail', 'declined'], 'down'],
        'visitors'     => ['Website visitors', 'count', 'SELECT DATE(created_at) d, COUNT(DISTINCT visitor_ref) n FROM analytics_events WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY d', ['visitor', 'traffic', 'visits', 'people came'], 'up'],
        'pageviews'    => ['Page views', 'count', "SELECT DATE(created_at) d, COUNT(*) n FROM analytics_events WHERE event = 'pageview' AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY d", ['page view', 'pageview', 'views'], 'up'],
        'messages'     => ['Support messages', 'count', 'SELECT DATE(created_at) d, COUNT(*) n FROM contact_messages WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY d', ['message', 'support', 'ticket', 'complaint', 'contact'], 'down'],
        'pillbox'      => ['Pillbox openings', 'count', "SELECT DATE(happened_at) d, COUNT(*) n FROM device_events WHERE kind = 'open' AND happened_at >= ? AND happened_at < ? + INTERVAL 1 DAY GROUP BY d", ['pillbox', 'iot', 'device', 'box'], 'up'],
    ];
    return $m;
}

/* Point-in-time numbers (no daily series). */
function snapshot_metrics(): array
{
    $users = (int) q('SELECT COUNT(*) FROM users')->fetchColumn();
    $prem = (int) q("SELECT COUNT(*) FROM users WHERE plan = 'premium' AND plan_until >= CURDATE()")->fetchColumn();
    $owners = (int) q('SELECT COUNT(*) FROM users WHERE household_id IS NULL')->fetchColumn();
    return [
        'total_users' => ['Total users', $users], 'premium' => ['Premium families', $prem],
        'conversion' => ['Free → Premium conversion (%)', $owners ? round(100 * $prem / $owners, 1) : 0],
        'pillboxes' => ['Pillboxes online', (int) q("SELECT COUNT(*) FROM devices WHERE token_hash IS NOT NULL AND last_seen >= NOW() - INTERVAL 20 MINUTE")->fetchColumn()],
        'open_messages' => ['Open support messages', (int) q('SELECT COUNT(*) FROM contact_messages WHERE handled = 0')->fetchColumn()],
    ];
}

/* Daily values for the last $days days ending $to (inclusive), zeros filled in. */
function metric_series(string $key, int $days, ?string $to = null): array
{
    $cat = metric_catalog();
    $to = $to ?? date('Y-m-d');
    $from = date('Y-m-d', strtotime("$to -" . ($days - 1) . ' day'));
    $rows = [];
    foreach (q($cat[$key][2], [$from, $to])->fetchAll() as $r) $rows[$r['d']] = (float) $r['n'];
    $out = [];
    for ($i = 0; $i < $days; $i++) { $d = date('Y-m-d', strtotime("$from +$i day")); $out[$d] = $rows[$d] ?? 0.0; }
    return $out;
}

function fmt_metric(float $v, string $unit): string
{
    return $unit === 'money' ? '₹' . number_format($v, 0) : (fmod($v, 1.0) === 0.0 ? number_format($v, 0) : number_format($v, 1));
}

/* ---------- Anomaly detection (robust z-score: median and MAD of the previous 28 days) ---------- */
function median(array $a): float { sort($a); $n = count($a); if (!$n) return 0.0; return $n % 2 ? (float) $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }

function detect_anomalies(?string $day = null): array
{
    $day = $day ?? date('Y-m-d', strtotime('-1 day'));
    $found = [];
    $hints = [
        'signups:down' => 'Check that the sign-up form works and that ads or links did not stop.',
        'visitors:down' => 'Check the site is up, robots.txt and Search Console for indexing problems.',
        'revenue:down' => 'Look at failed payments and the checkout page.',
        'failed:up' => 'Check Razorpay status and the payment page; failed payments may mean a broken checkout.',
        'messages:up' => 'Something may be confusing or broken; read the newest support messages.',
        'doses:down' => 'Reminders may not be going out; check push and the cron job.',
        'active:down' => 'Fewer families used the app; check reminders and the cron job.',
        'signups:up' => 'Find out where they came from (Where visitors come from) and do more of it.',
        'visitors:up' => 'Traffic jump: see top pages and sources to learn what worked.',
        'revenue:up' => 'Good day for sales. See what changed (campaign, page, price).',
    ];
    foreach (metric_catalog() as $key => [$label, $unit, , , $good]) {
        $s = metric_series($key, 29, $day);
        $y = array_pop($s);
        $base = array_values($s);
        $active = count(array_filter($base, fn($v) => $v > 0));
        if ($active < 7) continue;                       // not enough history to judge
        $med = median($base);
        $mad = median(array_map(fn($v) => abs($v - $med), $base));
        $z = $mad > 0 ? 0.6745 * ($y - $med) / $mad : ($y - $med) / max(1.0, sqrt(max($med, 1.0)));
        $minAbs = $unit === 'money' ? 200 : 3;
        if (abs($z) < 3.5 || abs($y - $med) < $minAbs) continue;
        $dir = $y > $med ? 'up' : 'down';
        $bad = $dir !== $good;
        $sev = $bad ? (abs($z) > 6 || $y == 0.0 ? 'high' : 'medium') : 'good';
        $title = $label . ($dir === 'up' ? ' jumped to ' : ' fell to ') . fmt_metric($y, $unit) . ' on ' . date('j M', strtotime($day));
        $body = 'Usually about ' . fmt_metric($med, $unit) . ' a day. ' . ($hints["$key:$dir"] ?? '');
        $found[] = ['metric' => $key, 'severity' => $sev, 'title' => $title, 'body' => $body];
        q('INSERT IGNORE INTO insights (day, kind, metric, severity, title, body) VALUES (?,?,?,?,?,?)', [$day, 'anomaly', $key, $sev, $title, $body]);
    }
    return $found;
}

/* Business suggestions worked out from the data (kind growth). */
function growth_insights(?string $day = null): array
{
    $day = $day ?? date('Y-m-d');
    $seg = growth_segments(5);
    $tips = [];
    if ($seg['counts']['likely_upgrade'] > 0)
        $tips[] = ['upgrade', 'medium', $seg['counts']['likely_upgrade'] . ' free families are close to the Free plan limits', 'They are active and near 30 medicines or 4 people. The autopilot sends them one friendly note; consider a first-month discount.'];
    if ($seg['counts']['at_risk'] > 0)
        $tips[] = ['at_risk', 'high', $seg['counts']['at_risk'] . ' Premium families may not renew', 'Premium ends within 7 days or they have not opened the app for 2 weeks. Renewal reminders go out by themselves; a personal message helps.'];
    if ($seg['counts']['not_activated'] >= 3)
        $tips[] = ['activation', 'medium', $seg['counts']['not_activated'] . ' new accounts never added a medicine', 'They get a nudge to scan a prescription. If this stays high, simplify the first screen.'];
    $c = q('SELECT SUM(analytics = 1) a, COUNT(*) n FROM cookie_consents WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetch();
    if ((int) $c['n'] >= 30 && (int) $c['a'] / (int) $c['n'] < 0.35)
        $tips[] = ['consent', 'low', 'Only ' . round(100 * $c['a'] / $c['n']) . '% accept analytics cookies', 'Your visitor numbers only count people who accept. That is fine for privacy; judge trends, not totals.'];
    $src = q("SELECT COALESCE(NULLIF(utm_source,''), NULLIF(referrer,''), '(direct)') s, COUNT(DISTINCT visitor_ref) n FROM analytics_events WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY s ORDER BY n DESC LIMIT 1")->fetch();
    if ($src && (int) $src['n'] >= 20) $tips[] = ['source', 'good', 'Top source this week: ' . $src['s'] . ' (' . $src['n'] . ' visitors)', 'Do more of what brings these visitors.'];
    foreach ($tips as [$metric, $sev, $title, $body]) q('INSERT IGNORE INTO insights (day, kind, metric, severity, title, body) VALUES (?,?,?,?,?,?)', [$day, 'growth', $metric, $sev, $title, $body]);
    return $tips;
}

/* ---------- Ask your data ---------- */
function ask_parse_period(string $q): array
{
    $q = mb_strtolower($q);
    if (preg_match('/last\s+(\d{1,3})\s+days?/', $q, $m)) return [(int) $m[1], "the last {$m[1]} days"];
    if (str_contains($q, 'yesterday')) return [1, 'yesterday', date('Y-m-d', strtotime('-1 day'))];
    if (str_contains($q, 'today')) return [1, 'today'];
    if (preg_match('/this week|last week|7 days|weekly|week/', $q)) return [7, 'the last 7 days'];
    if (preg_match('/quarter|90 days|3 months/', $q)) return [90, 'the last 90 days'];
    if (preg_match('/year|365/', $q)) return [365, 'the last 365 days'];
    return [30, 'the last 30 days'];
}

function ask_rules(string $q): array
{
    $ql = mb_strtolower($q);
    $hits = [];
    foreach (metric_catalog() as $k => $m) foreach ($m[3] as $w) if (str_contains($ql, $w)) { $hits[$k] = ($hits[$k] ?? 0) + strlen($w); }
    arsort($hits);
    $snap = null;
    if (preg_match('/conversion|convert/', $ql)) $snap = 'conversion';
    elseif (preg_match('/premium|paying|subscriber/', $ql) && !isset($hits['revenue'])) $snap = 'premium';
    elseif (preg_match('/total users|how many users|user count/', $ql)) $snap = 'total_users';
    elseif (preg_match('/online/', $ql) && isset($hits['pillbox'])) $snap = 'pillboxes';
    $list = null;
    if (preg_match('/top pages|which pages|popular page/', $ql)) $list = 'pages';
    elseif (preg_match('/where.*(come|from)|source|referr|channel/', $ql)) $list = 'sources';
    return ['metrics' => array_slice(array_keys($hits), 0, 2), 'snapshot' => $snap, 'list' => $list];
}

function ask_data(string $question): array
{
    $question = trim(mb_substr($question, 0, 300));
    $cat = metric_catalog();
    $plan = ask_rules($question);
    $usedAi = false;
    if (!$plan['metrics'] && !$plan['snapshot'] && !$plan['list'] && ai_enabled()) {
        $keys = implode("\n", array_map(fn($k, $m) => "$k: {$m[0]}", array_keys($cat), $cat));
        $snapKeys = implode(', ', array_keys(snapshot_metrics()));
        $j = ai_json('ask_route', "You route a business owner's question to metrics of a family medicine-reminder web app. Daily metrics:\n$keys\nSnapshot metrics: $snapKeys\nLists: pages, sources. " . AI_UNTRUSTED_RULE,
            ai_untrusted('owner question', $question) . "\nReturn {\"metrics\": [up to 2 daily metric keys], \"snapshot\": key or null, \"list\": \"pages\"|\"sources\"|null, \"days\": number of days}");
        if ($j) {
            $usedAi = true;
            $plan['metrics'] = array_values(array_intersect((array) ($j['metrics'] ?? []), array_keys($cat)));
            $plan['snapshot'] = isset(snapshot_metrics()[(string) ($j['snapshot'] ?? '')]) ? (string) $j['snapshot'] : null;
            $plan['list'] = in_array($j['list'] ?? null, ['pages', 'sources'], true) ? $j['list'] : null;
            if (!empty($j['days'])) $plan['days'] = max(1, min(365, (int) $j['days']));
        }
    }
    $p = ask_parse_period($question);
    $days = $plan['days'] ?? $p[0];
    $label = isset($plan['days']) ? "the last $days days" : $p[1];
    $to = $p[2] ?? date('Y-m-d');
    $facts = []; $chart = null; $lines = [];
    foreach ($plan['metrics'] as $k) {
        [$mlabel, $unit] = $cat[$k];
        $cur = metric_series($k, $days, $to);
        $prev = metric_series($k, $days, date('Y-m-d', strtotime("$to -$days day")));
        $sum = array_sum($cur); $psum = array_sum($prev);
        $chg = $psum > 0 ? round(100 * ($sum - $psum) / $psum) : null;
        $facts[] = ['metric' => $mlabel, 'period' => $label, 'value' => fmt_metric($sum, $unit), 'previous' => fmt_metric($psum, $unit), 'changePercent' => $chg,
                    'bestDay' => $sum > 0 ? array_search(max($cur), $cur, true) : null];
        $lines[] = "$mlabel in $label: " . fmt_metric($sum, $unit) . ($chg === null ? '' : ' (' . ($chg >= 0 ? '+' : '') . "$chg% vs the period before)") . '.';
        if (!$chart && $days > 1) $chart = ['label' => $mlabel, 'unit' => $unit, 'points' => array_map(fn($d, $v) => ['d' => $d, 'n' => $v], array_keys($cur), $cur)];
    }
    if ($plan['snapshot']) { [$sl, $sv] = snapshot_metrics()[$plan['snapshot']]; $facts[] = ['metric' => $sl, 'value' => $sv]; $lines[] = "$sl: $sv."; }
    $table = null;
    if ($plan['list'] === 'pages') $table = q("SELECT path label, COUNT(*) n FROM analytics_events WHERE event = 'pageview' AND created_at >= NOW() - INTERVAL ? DAY GROUP BY path ORDER BY n DESC LIMIT 8", [$days])->fetchAll();
    if ($plan['list'] === 'sources') $table = q("SELECT COALESCE(NULLIF(utm_source,''), NULLIF(referrer,''), '(direct)') label, COUNT(DISTINCT visitor_ref) n FROM analytics_events WHERE created_at >= NOW() - INTERVAL ? DAY GROUP BY label ORDER BY n DESC LIMIT 8", [$days])->fetchAll();
    if ($table !== null) { $facts[] = ['list' => $plan['list'], 'top' => $table]; $lines[] = $table ? 'Top ' . $plan['list'] . ': ' . implode(', ', array_map(fn($r) => "{$r['label']} ({$r['n']})", array_slice($table, 0, 3))) . '.' : 'No data yet.'; }
    if (!$facts) {
        return ['answer' => 'I can answer questions about sign-ups, active families, doses, medicines added, revenue, purchases, failed payments, visitors, page views, support messages, pillbox openings, Premium families and conversion, top pages and traffic sources. Try "revenue this week" or "where do visitors come from".',
                'facts' => [], 'chart' => null, 'table' => null, 'ai' => $usedAi];
    }
    $answer = implode(' ', $lines);
    $nice = ai_text('ask_answer', 'You are a concise analytics assistant for a small Indian health-tech startup. Answer in 2-3 plain sentences using ONLY the numbers given. Do not invent numbers. If a change is large, suggest one practical next step.',
                    "Question: $question\nFacts (computed from the database): " . json_encode($facts, JSON_UNESCAPED_UNICODE), 220);
    if ($nice) { $answer = $nice; $usedAi = true; }
    return ['answer' => $answer, 'facts' => $facts, 'chart' => $chart, 'table' => $table, 'ai' => $usedAi];
}

/* ---------- Growth: health scores and segments ---------- */
function growth_segments(int $limit = 10): array
{
    $rows = q("SELECT u.id, u.name, u.email, u.created_at, u.last_seen_at, u.plan, u.plan_until, u.onboarded,
                 (SELECT COUNT(*) FROM cabinet_items c WHERE c.user_id = u.id) items,
                 (SELECT COUNT(*) FROM cabinet_items c WHERE c.user_id = u.id AND c.dose_times <> '') timed,
                 (SELECT COUNT(*) FROM members m WHERE m.user_id = u.id) members,
                 (SELECT COUNT(*) FROM dose_logs d WHERE d.user_id = u.id AND d.taken_on >= CURDATE() - INTERVAL 7 DAY) ticks7,
                 (SELECT COUNT(*) FROM push_subscriptions p WHERE p.user_id = u.id) push,
                 (SELECT COUNT(*) FROM devices v WHERE v.user_id = u.id AND v.token_hash IS NOT NULL) boxes
               FROM users u WHERE u.household_id IS NULL ORDER BY u.id DESC LIMIT 5000")->fetchAll();
    $seg = ['likely_upgrade' => [], 'at_risk' => [], 'not_activated' => [], 'dormant' => [], 'champions' => []];
    $now = time();
    foreach ($rows as $r) {
        $score = ($r['items'] >= 1 ? 20 : 0) + ($r['items'] >= 3 ? 15 : 0) + ($r['timed'] >= 1 ? 15 : 0) + ($r['ticks7'] >= 1 ? 20 : 0)
               + ($r['members'] >= 2 ? 10 : 0) + ($r['push'] >= 1 ? 10 : 0) + ($r['onboarded'] ? 5 : 0) + ($r['boxes'] ? 5 : 0);
        $seen = $r['last_seen_at'] ? (int) (($now - strtotime($r['last_seen_at'])) / 86400) : null;
        $age = (int) (($now - strtotime($r['created_at'])) / 86400);
        $premium = $r['plan'] === 'premium' && $r['plan_until'] >= date('Y-m-d');
        $row = ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => mask_email($r['email']), 'score' => $score, 'items' => (int) $r['items'], 'members' => (int) $r['members'],
                'lastSeenDays' => $seen, 'premium' => $premium, 'planUntil' => $r['plan_until']];
        if (!$premium && ($r['items'] >= 24 || $r['members'] >= 4) && $seen !== null && $seen <= 7) { $row['why'] = $r['items'] >= 24 ? "{$r['items']} of 30 medicines" : "{$r['members']} of 4 people"; $seg['likely_upgrade'][] = $row; }
        if ($premium && (($seen !== null && $seen > 14) || $r['plan_until'] <= date('Y-m-d', strtotime('+7 day')))) { $row['why'] = $r['plan_until'] <= date('Y-m-d', strtotime('+7 day')) ? 'Premium ends ' . $r['plan_until'] : "not seen for $seen days"; $seg['at_risk'][] = $row; }
        if ($age >= 2 && $score < 40 && (int) $r['items'] === 0) { $row['why'] = "joined $age days ago, no medicines"; $seg['not_activated'][] = $row; }
        if (!$premium && (int) $r['items'] > 0 && $seen !== null && $seen > 21) { $row['why'] = "not seen for $seen days"; $seg['dormant'][] = $row; }
        if ($score >= 80) { $row['why'] = "score $score"; $seg['champions'][] = $row; }
    }
    $counts = array_map('count', $seg);
    return ['counts' => $counts, 'segments' => array_map(fn($l) => array_slice($l, 0, $limit), $seg), 'scored' => count($rows)];
}

/* Helpful, data-based reminders that bring people back (at most one per person per week). */
function push_to_user(int $uid, array $msg): int
{
    if (!push_enabled()) return 0;
    $n = 0;
    foreach (q('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?', [$uid])->fetchAll() as $sub) {
        try { $code = push_send($sub, $msg); if ($code === 404 || $code === 410) q('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]); elseif ($code < 300) $n++; }
        catch (Throwable $e) { error_log('push: ' . $e->getMessage()); }
    }
    return $n;
}

function run_growth_nudges(DateTime $now): array
{
    if (state_get('nudges_on', '1') !== '1') return ['skipped' => 'switched off'];
    $today = $now->format('Y-m-d');
    $sent = ['activation' => 0, 'comeback' => 0, 'limit' => 0];
    $recent = fn(int $uid) => (bool) q("SELECT 1 FROM automation_log WHERE user_id = ? AND kind IN ('a','k','u') AND sent_on >= ? - INTERVAL 7 DAY", [$uid, $today])->fetch();
    $mark = fn(int $uid, string $k) => q('INSERT IGNORE INTO automation_log (user_id, kind, item_id, slot, sent_on) VALUES (?,?,0,\'\',?)', [$uid, $k, $today]);
    $base = rtrim((string) config('app_url'), '/');
    // a — joined 1 to 4 days ago and has not added a medicine
    foreach (q("SELECT u.id, u.name, u.email FROM users u WHERE u.household_id IS NULL AND u.created_at BETWEEN ? - INTERVAL 4 DAY AND ? - INTERVAL 1 DAY
                AND NOT EXISTS (SELECT 1 FROM cabinet_items c WHERE c.user_id = u.id)", [$today, $today])->fetchAll() as $u) {
        if ($recent((int) $u['id']) || q("SELECT 1 FROM automation_log WHERE user_id = ? AND kind = 'a'", [$u['id']])->fetch()) continue;
        $mark((int) $u['id'], 'a');
        $msg = ['title' => 'Add your medicines in one photo', 'body' => 'Photograph a prescription or pharmacy bill: every medicine, time and day is filled in for you.', 'url' => '/app#scan', 'tag' => 'onboard'];
        if (push_to_user((int) $u['id'], $msg)) $sent['activation']++;
        elseif (mail_enabled() && marketing_ok((int) $u['id']) && send_mail($u['email'], 'Your medicine box is empty · ' . site_title(), "Hello {$u['name']},\n\n{$msg['body']}\nIt takes less than a minute: $base/app#scan\n\n" . site_title(), [(int) $u['id'], 'news'])) $sent['activation']++;
    }
    // k — has medicines, away 14 to 60 days, and something in the cabinet needs attention (a real reason to come back)
    foreach (q("SELECT u.id, (SELECT COUNT(*) FROM cabinet_items c WHERE c.user_id = u.id AND c.exp_month IS NOT NULL AND LAST_DAY(c.exp_month) < ? + INTERVAL 30 DAY) soon
                FROM users u WHERE u.household_id IS NULL AND u.last_seen_at BETWEEN ? - INTERVAL 60 DAY AND ? - INTERVAL 14 DAY", [$today, $today, $today])->fetchAll() as $u) {
        if ((int) $u['soon'] === 0 || $recent((int) $u['id']) || q("SELECT 1 FROM automation_log WHERE user_id = ? AND kind = 'k' AND sent_on >= ? - INTERVAL 30 DAY", [$u['id'], $today])->fetch()) continue;
        $mark((int) $u['id'], 'k');
        if (push_to_user((int) $u['id'], ['title' => $u['soon'] . ' medicines expire within a month', 'body' => 'Open your cabinet to see which ones, and add them to the shopping list.', 'url' => '/app#cabinet', 'tag' => 'comeback'])) $sent['comeback']++;
    }
    // u — free plan, 27+ medicines: tell them before they hit the limit
    foreach (q("SELECT u.id, COUNT(c.id) n FROM users u JOIN cabinet_items c ON c.user_id = u.id
                WHERE u.household_id IS NULL AND (u.plan = 'free' OR u.plan_until < ?) GROUP BY u.id HAVING n >= 27", [$today])->fetchAll() as $u) {
        if ($recent((int) $u['id']) || q("SELECT 1 FROM automation_log WHERE user_id = ? AND kind = 'u' AND sent_on >= ? - INTERVAL 30 DAY", [$u['id'], $today])->fetch()) continue;
        $mark((int) $u['id'], 'u');
        if (push_to_user((int) $u['id'], ['title' => "You have {$u['n']} of 30 medicines", 'body' => 'Remove expired ones from the cabinet, or Family Premium removes the limit and adds family sharing.', 'url' => '/app#account', 'tag' => 'limit'])) $sent['limit']++;
    }
    if (array_sum($sent)) autopilot_log('growth', 'Sent ' . array_sum($sent) . ' helpful reminders', json_encode($sent));
    return $sent;
}

/* ---------- Daily digest for the admins ---------- */
function build_digest(?string $day = null): array
{
    $day = $day ?? date('Y-m-d', strtotime('-1 day'));
    $lines = [];
    foreach (['signups', 'active', 'doses', 'revenue', 'purchases', 'failed', 'visitors', 'messages'] as $k) {
        [$label, $unit] = metric_catalog()[$k];
        $s = metric_series($k, 8, $day); $y = array_pop($s); $avg = array_sum($s) / 7;
        $lines[] = sprintf('%-24s %8s   (7-day average %s)', $label, fmt_metric($y, $unit), fmt_metric($avg, $unit));
    }
    $snap = snapshot_metrics();
    $ins = q("SELECT severity, title FROM insights WHERE day >= ? - INTERVAL 1 DAY AND dismissed = 0 ORDER BY FIELD(severity,'high','medium','low','good'), id DESC LIMIT 8", [$day])->fetchAll();
    $overdue = (int) q('SELECT COUNT(*) FROM contact_messages WHERE handled = 0 AND due_at < NOW()')->fetchColumn();
    $health = function_exists('health_checks') ? array_filter(health_checks(), fn($c) => $c['status'] !== 'ok') : [];
    // Security events of that day (failed sign-ins, blocked requests, bots, two-factor failures), and checks that fail
    $sec = [];
    try { $sec = q('SELECT kind, COUNT(*) n FROM security_events WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY kind ORDER BY n DESC', [$day, $day])->fetchAll(); } catch (Throwable $e) { /* table not there yet */ }
    $secBad = function_exists('security_checks') ? array_filter(security_checks(), fn($c) => !$c['ok']) : [];
    $text = "Yesterday (" . date('D j M', strtotime($day)) . ")\n" . implode("\n", $lines) .
        "\n\nNow: {$snap['premium'][1]} Premium families · {$snap['total_users'][1]} users · conversion {$snap['conversion'][1]}% · {$snap['open_messages'][1]} open messages" . ($overdue ? " ($overdue overdue)" : '') .
        ($ins ? "\n\nWhat the autopilot noticed:\n" . implode("\n", array_map(fn($i) => '• [' . $i['severity'] . '] ' . $i['title'], $ins)) : "\n\nNothing unusual.") .
        ($health ? "\n\nNeeds attention:\n" . implode("\n", array_map(fn($c) => '• ' . $c['label'] . ': ' . $c['detail'], $health)) : '') .
        ($sec ? "\n\nSecurity events: " . implode(' · ', array_map(fn($r) => str_replace('_', ' ', $r['kind']) . ' ' . $r['n'], $sec)) : '') .
        ($secBad ? "\n\nSecurity settings to fix (Admin → Security):\n" . implode("\n", array_map(fn($c) => '• ' . $c['check'], $secBad)) : '');
    $summary = ai_text('digest', 'You write a 2-sentence morning summary for the founder of a small health app. Use only the facts given. Mention the single most important thing to do today.', $text, 160);
    return ['day' => $day, 'text' => ($summary ? $summary . "\n\n" : '') . $text, 'ai' => (bool) $summary];
}

function send_digest(DateTime $now): int
{
    if (state_get('digest_on', '1') !== '1') return 0;
    $d = build_digest(date('Y-m-d', strtotime($now->format('Y-m-d') . ' -1 day')));
    $base = rtrim((string) config('app_url'), '/');
    $n = 0;
    foreach (q("SELECT id, name, email FROM users WHERE role = 'admin'")->fetchAll() as $a) {
        if (mail_enabled() && send_mail($a['email'], site_title() . ' daily digest · ' . date('j M', strtotime($d['day'])), "Hello {$a['name']},\n\n{$d['text']}\n\nAdmin dashboard: $base/admin.html\n")) $n++;
        $high = (int) q("SELECT COUNT(*) FROM insights WHERE day >= ? AND severity = 'high' AND dismissed = 0", [$d['day']])->fetchColumn();
        $n += push_to_user((int) $a['id'], ['title' => 'Daily digest' . ($high ? " · $high need attention" : ''), 'body' => strtok($d['text'], "\n"), 'url' => '/admin.html', 'tag' => 'digest']);
    }
    autopilot_log('digest', $n ? "Daily digest sent ($n)" : 'Daily digest ready (turn on e-mail or push to receive it)', $d['text']);
    return $n;
}

function audit(?array $user, string $action, string $detail = ''): void
{
    q('INSERT INTO audit_log (user_id, action, detail, ip) VALUES (?,?,?,?)', [$user['id'] ?? null, $action, mb_substr($detail, 0, 255), client_ip()]);
}
