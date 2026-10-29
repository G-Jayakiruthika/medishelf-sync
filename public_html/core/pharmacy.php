<?php
/* AushadhBox card and pharmacy link (like Sony's harmo and other Japanese pharmacy-linked medicine notebooks).

   The family shows a QR card at the pharmacy. The QR holds only a random code: no name, no medicines.
   Nothing is shared unless the family switches it on, per person:
     share_add  — the pharmacy may add the medicines it gives (they appear in the cabinet with their schedule)
     share_view — the pharmacy may see current medicines, allergies, conditions and check-in answers,
                  so the pharmacist can check for interactions before giving a new medicine.
   Only pharmacies approved by the site admin (drug licence checked) can use a card. The family sees every
   access, can block a pharmacy, and "New card" makes the old code stop working at once. */
declare(strict_types=1);

const CARD_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I/L: easy to read out or type

function card_normalize(string $code): string
{
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', preg_replace('/^AUSHADHBOX:/i', '', trim($code))));
    return strlen($c) === 12 ? substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 4) : '';
}

function card_new(int $hid, int $memberId): string
{
    for ($try = 0; $try < 5; $try++) {
        $raw = ''; for ($i = 0; $i < 12; $i++) $raw .= CARD_ALPHABET[random_int(0, strlen(CARD_ALPHABET) - 1)];
        $code = card_normalize($raw);
        try { q('UPDATE members SET card_code = ? WHERE id = ? AND user_id = ?', [$code, $memberId, $hid]); return $code; }
        catch (PDOException $e) { /* the same code exists (practically never): try again */ }
    }
    fail('server_busy', 'Please try again.', 503);
}

/* For the family: each person's card, switches, the pharmacies it was used at, and the access log. */
function cards_public(int $hid): array
{
    $members = q('SELECT id, name, card_code, share_add, share_view FROM members WHERE user_id = ? ORDER BY is_self DESC, id', [$hid])->fetchAll();
    $links = q('SELECT l.member_id, l.pharmacy_id, l.first_at, l.last_at, l.blocked, p.name, p.city FROM pharmacy_links l JOIN pharmacies p ON p.id = l.pharmacy_id
                JOIN members m ON m.id = l.member_id WHERE m.user_id = ? ORDER BY l.last_at DESC', [$hid])->fetchAll();
    $log = q('SELECT a.action, a.detail, a.at, a.member_id, p.name pharmacy FROM pharmacy_access a JOIN pharmacies p ON p.id = a.pharmacy_id WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 30', [$hid])->fetchAll();
    return [
        'cards' => array_map(fn($m) => ['memberId' => (int) $m['id'], 'name' => $m['name'], 'code' => $m['card_code'], 'shareAdd' => (bool) $m['share_add'], 'shareView' => (bool) $m['share_view']], $members),
        'links' => array_map(fn($l) => ['memberId' => (int) $l['member_id'], 'pharmacyId' => (int) $l['pharmacy_id'], 'pharmacy' => $l['name'], 'city' => $l['city'], 'first' => $l['first_at'], 'last' => $l['last_at'], 'blocked' => (bool) $l['blocked']], $links),
        'log' => array_map(fn($l) => ['action' => $l['action'], 'detail' => $l['detail'], 'at' => $l['at'], 'memberId' => $l['member_id'] ? (int) $l['member_id'] : null, 'pharmacy' => $l['pharmacy']], $log),
        'refills' => array_map(fn($r) => ['id' => (int) $r['id'], 'pharmacy' => $r['pname'], 'items' => json_decode($r['items'], true) ?: [], 'status' => $r['status'], 'at' => $r['created_at'], 'updated' => $r['updated_at']],
            q('SELECT r.*, p.name pname FROM refill_requests r JOIN pharmacies p ON p.id = r.pharmacy_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 10', [$hid])->fetchAll()),
    ];
}

/* Pharmacies the family can send a refill request to: used with any card in the household and not blocked. */
function linked_pharmacies(int $hid): array
{
    return q('SELECT DISTINCT p.id, p.name, p.city FROM pharmacy_links l JOIN pharmacies p ON p.id = l.pharmacy_id JOIN members m ON m.id = l.member_id
              WHERE m.user_id = ? AND l.blocked = 0 AND p.status = \'approved\' ORDER BY p.name', [$hid])->fetchAll();
}

function pharmacy_log(int $phId, int $hid, ?int $memberId, string $action, string $detail = ''): void
{
    q('INSERT INTO pharmacy_access (pharmacy_id, user_id, member_id, action, detail) VALUES (?,?,?,?,?)', [$phId, $hid, $memberId, $action, mb_substr($detail, 0, 255)]);
}

/* Push a note to everyone sharing the family cabinet. */
function notify_household(int $hid, array $msg): int
{
    if (!function_exists('push_to_user')) return 0;
    $n = 0;
    foreach (q('SELECT id FROM users WHERE id = ? OR household_id = ?', [$hid, $hid])->fetchAll() as $u) $n += push_to_user((int) $u['id'], $msg);
    return $n;
}

/* ---------- What a pharmacy may do with a card ---------- */
function card_member(array $ph, string $code): array
{
    $code = card_normalize($code);
    $m = $code ? q('SELECT m.*, u.id owner_id FROM members m JOIN users u ON u.id = m.user_id WHERE m.card_code = ?', [$code])->fetch() : null;
    if (!$m) fail('bad_card', 'This card was not found. It may have been replaced: ask for the new card.', 404);
    if (!$m['share_add'] && !$m['share_view']) fail('not_shared', 'The family has not allowed pharmacies to use this card. They can switch it on in the app (Account → AushadhBox card).', 403);
    $link = q('SELECT blocked FROM pharmacy_links WHERE member_id = ? AND pharmacy_id = ?', [$m['id'], $ph['id']])->fetch();
    if ($link && $link['blocked']) fail('blocked', 'The family has stopped sharing with this pharmacy.', 403);
    q('INSERT INTO pharmacy_links (member_id, pharmacy_id) VALUES (?,?) ON DUPLICATE KEY UPDATE last_at = NOW()', [$m['id'], $ph['id']]);
    return $m;
}

function masked_name(string $n): string
{
    $parts = preg_split('/\s+/', trim($n));
    return implode(' ', array_map(fn($p, $i) => $i === 0 ? $p : mb_substr($p, 0, 1) . '.', $parts, array_keys($parts)));
}

function pharmacy_patient(array $ph, string $code): array
{
    $m = card_member($ph, $code);
    $hid = (int) $m['user_id'];
    $out = ['code' => $m['card_code'], 'name' => masked_name($m['name']), 'canAdd' => (bool) $m['share_add'], 'canView' => (bool) $m['share_view']];
    if ($m['share_view']) {
        $out['allergies'] = $m['allergies']; $out['conditions'] = $m['conditions']; $out['bloodGroup'] = $m['blood_group'];
        $out['medicines'] = array_map(fn($r) => ['name' => $r['name'], 'ingredient' => $r['ingredient'], 'times' => array_values(array_filter(explode(',', $r['dose_times']))), 'fromYou' => (int) $r['pharmacy_id'] === (int) $ph['id']],
            q("SELECT c.name, c.dose_times, c.pharmacy_id, i.ingredient FROM cabinet_items c LEFT JOIN medicine_info i ON i.id = c.info_id WHERE c.user_id = ? AND (c.member_id = ? OR c.member_id IS NULL)
               AND (c.course_end IS NULL OR c.course_end >= CURDATE()) AND (c.exp_month IS NULL OR LAST_DAY(c.exp_month) >= CURDATE()) ORDER BY c.name", [$hid, $m['id']])->fetchAll());
        $out['followups'] = q("SELECT c.name, f.answer, f.note, DATE(f.answered_at) d FROM followups f JOIN cabinet_items c ON c.id = f.item_id WHERE f.user_id = ? AND c.member_id = ? AND f.answered_at >= NOW() - INTERVAL 90 DAY ORDER BY f.answered_at DESC LIMIT 20", [$hid, $m['id']])->fetchAll();
        pharmacy_log((int) $ph['id'], $hid, (int) $m['id'], 'view', 'Saw the medicine list');
    } else {
        pharmacy_log((int) $ph['id'], $hid, (int) $m['id'], 'scan', 'Scanned the card');
    }
    return $out;
}

/* Turns what the pharmacist typed or scanned into cabinet rows. Shared by the safety pre-check and the real save. */
function pharmacy_insert_items(array $ph, array $m, array $items): array
{
    $hid = (int) $m['user_id']; $ids = [];
    foreach (array_slice($items, 0, 20) as $in) {
        if (!is_array($in)) continue;
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 120));
        if (mb_strlen($name) < 2) continue;
        $catalog = (int) ($in['catalog_id'] ?? 0) ?: null;
        if ($catalog && !q('SELECT 1 FROM medicine_catalog WHERE id = ?', [$catalog])->fetch()) $catalog = null;
        $info = $catalog ? q('SELECT info_id FROM medicine_catalog WHERE id = ?', [$catalog])->fetchColumn() : null;
        if (!$info) $info = primary_info_id($name);
        $times = valid_times(is_array($in['times'] ?? null) ? implode(',', $in['times']) : (string) ($in['times'] ?? ''));
        $days = max(0, min(365, (int) ($in['days'] ?? 0)));
        $qty = isset($in['qty']) && $in['qty'] !== '' && $in['qty'] !== null ? max(0, min(9999, (int) $in['qty'])) : null;
        $exp = preg_match('/^\d{4}-\d{2}$/', (string) ($in['exp'] ?? '')) ? month_to_date((string) $in['exp']) : null;
        $note = trim('Given by ' . $ph['name'] . (empty($in['notes']) ? '' : ': ' . mb_substr((string) $in['notes'], 0, 200)));
        q('INSERT INTO cabinet_items (user_id, member_id, name, catalog_id, info_id, batch_no, exp_month, dose_times, notes, stock_count, per_dose, course_end, pharmacy_id) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)',
          [$hid, $m['id'], $name, $catalog, $info ?: null, mb_substr((string) ($in['batch'] ?? ''), 0, 40) ?: null, $exp, $times, $note, $qty, $days && $times !== '' ? date('Y-m-d', strtotime('+' . ($days - 1) . ' day')) : null, $ph['id']]);
        $ids[] = (int) db()->lastInsertId();
    }
    return $ids;
}

/* Safety check of the medicines about to be given, against what the person already has. Nothing is saved.
   Without "view" consent, names of the family's other medicines are hidden from the pharmacist. */
/* A transaction that also works inside another one (as a savepoint), so callers and tests can wrap these. */
function ph_tx(): bool { if (db()->inTransaction()) { db()->exec('SAVEPOINT ph_tx'); return true; } db()->beginTransaction(); return false; }
function ph_tx_end(bool $nested, bool $keep): void
{
    if ($nested) db()->exec($keep ? 'RELEASE SAVEPOINT ph_tx' : 'ROLLBACK TO SAVEPOINT ph_tx');
    elseif ($keep) db()->commit(); else db()->rollBack();
}

function pharmacy_precheck(array $ph, string $code, array $items): array
{
    $m = card_member($ph, $code);
    $nested = ph_tx();
    try {
        $new = pharmacy_insert_items($ph, $m, $items);
        $alerts = array_values(array_filter(safety_alerts((int) $m['user_id']), fn($a) => array_intersect($a['items'], $new) && ($a['memberId'] === (int) $m['id'])));
    } finally { ph_tx_end($nested, false); }
    if (!$m['share_view']) foreach ($alerts as &$a) foreach (['a', 'b'] as $k) if (isset($a['p'][$k]) && !in_array($a['items'][$k === 'a' ? 0 : 1] ?? 0, $new, true)) $a['p'][$k] = 'a medicine they already take';
    unset($a);
    return ['alerts' => $alerts];
}

function pharmacy_dispense(array $ph, string $code, array $items): array
{
    $m = card_member($ph, $code);
    if (!$m['share_add']) fail('not_shared', 'The family allows viewing only. Ask them to switch on "Pharmacies may add medicines".', 403);
    $hid = (int) $m['user_id'];
    $owner = q('SELECT * FROM users WHERE id = ?', [$hid])->fetch();
    $lim = plan_info($owner)['limits']['items'];
    if ((int) q('SELECT COUNT(*) FROM cabinet_items WHERE user_id = ?', [$hid])->fetchColumn() + count($items) > $lim) fail('limit_items', 'The family\'s cabinet is full on the Free plan. They can remove expired medicines or upgrade.', 402);
    $nested = ph_tx();
    try { $ids = pharmacy_insert_items($ph, $m, $items); } catch (Throwable $e) { ph_tx_end($nested, false); throw $e; }
    ph_tx_end($nested, true);
    if (!$ids) fail('empty', 'Add at least one medicine.');
    foreach (q('SELECT id, dose_times, course_end FROM cabinet_items WHERE id IN (' . implode(',', $ids) . ')')->fetchAll() as $r)
        if ($r['dose_times'] !== '' && function_exists('followups_schedule')) followups_schedule($hid, (int) $r['id'], $r['course_end']);
    $names = array_column(q('SELECT name FROM cabinet_items WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(), 'name');
    pharmacy_log((int) $ph['id'], $hid, (int) $m['id'], 'add', implode(', ', $names));
    notify_household($hid, ['title' => $ph['name'] . ' added ' . count($ids) . ' medicine' . (count($ids) > 1 ? 's' : ''), 'body' => $m['name'] . ': ' . implode(', ', $names) . '. Reminders are set. Check the times in the app.', 'url' => '/app#cabinet', 'tag' => 'pharmacy-add']);
    $alerts = array_values(array_filter(safety_alerts($hid), fn($a) => (bool) array_intersect($a['items'], $ids)));
    return ['ok' => true, 'added' => count($ids), 'alerts' => count($alerts)];
}
