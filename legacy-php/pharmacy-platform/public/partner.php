<?php
/* Signed API the family app (AushadhBox) calls on behalf of its users. partner.php?action=<name>, POST, signed (core/partner_sign.php).
   The family app identifies a user only by a pseudonymous customer_ref. Name, phone and address are sent only
   when the user places an order with a pharmacy (and they agreed to share them for that order). */
declare(strict_types=1);
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/migrate.php';
try { ph_migrate_if_due(); } catch (Throwable $e) { error_log('migrate: ' . $e->getMessage()); }
foreach (['compliance', 'stock', 'network', 'orders', 'delivery', 'sms', 'family', 'partner_sign'] as $f) require_once dirname(__DIR__) . "/core/$f.php";

header('X-Content-Type-Options: nosniff');
$action = (string) ($_GET['action'] ?? '');
$body = file_get_contents('php://input') ?: '';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('method', 'POST only.', 405);
$why = null;
$key = partner_verify((array) (config('partner_keys') ?? []), 'POST', (string) ($_SERVER['REQUEST_URI'] ?? ''), $body, $_SERVER, function (string $n): bool {
    q('DELETE FROM nonces WHERE created_at < NOW() - INTERVAL 15 MINUTE');
    try { q('INSERT INTO nonces (nonce) VALUES (?)', [$n]); return false; } catch (PDOException $e) { return true; }
}, $why);
if (!$key) { error_log('partner refused: ' . $why); fail('unauthorised', 'Signature check failed.', 401); }
$in = json_decode($body, true) ?: [];

/* The app user, as a network customer. Created on first use. */
function cust_ref(array $in): array
{
    $ref = (string) ($in['customer_ref'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $ref)) fail('bad_ref', 'Missing customer reference.');
    $c = q('SELECT * FROM customers WHERE customer_ref = ?', [$ref])->fetch();
    if (!$c) { customer_upsert(['customer_ref' => $ref, 'name' => mb_substr((string) ($in['name'] ?? 'AushadhBox user'), 0, 80)]); $c = q('SELECT * FROM customers WHERE customer_ref = ?', [$ref])->fetch(); }
    return $c;
}
function my_cust_order(array $c, string $number): array
{
    $o = q('SELECT * FROM orders WHERE number = ? AND customer_id = ?', [strtoupper(trim($number)), $c['id']])->fetch();
    if (!$o) fail('not_found', 'Order not found.', 404);
    return $o;
}

try {
switch ($action) {
case 'ping': json_out(['ok' => true, 'time' => time(), 'name' => 'AushadhBox Pharmacy Network']);

case 'store':   // what a customer sees after scanning a shop's QR
    $b = branch_by_code((string) ($in['code'] ?? ''));
    if (!$b || $b['org_status'] !== 'approved') fail('not_found', 'This pharmacy is not on AushadhBox (or not approved yet).', 404);
    $lat = is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null; $lng = is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null;
    $pub = branch_public($b, $lat, $lng);
    if (!empty($in['customer_ref']) && preg_match('/^[a-f0-9]{32}$/', (string) $in['customer_ref'])) {
        $c = cust_ref($in);
        $k = q('SELECT id FROM khata WHERE branch_id = ? AND customer_id = ?', [$b['id'], $c['id']])->fetchColumn();
        $pub['mine'] = ['points' => points_balance((int) $b['org_id'], (int) $c['id']), 'khata' => $k ? khata_view((int) $k, 10) : null, 'refCode' => $c['ref_code']];
    }
    $pub['points'] = q('SELECT id, name, kind, village, days FROM pickup_points WHERE branch_id = ? AND active = 1 ORDER BY village', [$b['id']])->fetchAll();
    $pub['upiLink'] = $b['upi_vpa'] ? upi_link($b, 0, 'payment') : null;
    json_out($pub);

case 'availability':   // which pharmacies near me have these medicines
    limit('avail:' . ($in['customer_ref'] ?? 'anon'), 60, 60);
    json_out(['pharmacies' => availability((array) ($in['items'] ?? []), is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null,
                                          str_in($in, 'district', 60), (float) min(60, max(2, (float) ($in['radius_km'] ?? 25))))]);

case 'quote':   // delivery fee and whether a long delivery is worth it, before ordering
    $b = branch_by_code((string) ($in['code'] ?? '')); if (!$b) fail('not_found', 'Pharmacy not found.', 404);
    if (!is_numeric($in['lat'] ?? null) || $b['lat'] === null) fail('no_location', 'Share your location for a delivery quote.');
    $d = road_km(km((float) $b['lat'], (float) $b['lng'], (float) $in['lat'], (float) $in['lng']), $b['area']);
    $has = branch_has($b, (array) ($in['items'] ?? []));
    $sub = array_sum(array_map(fn($h) => in_array($h['status'], ['in', 'low'], true) ? (float) ($h['price'] ?? 0) : 0, $has));
    json_out(delivery_quote($b, $d, $sub, $has, shared_stops((int) $b['id'], (float) $in['lat'], (float) $in['lng']), !empty($in['customer_pays_full'])));

case 'order_create':
    $c = cust_ref($in);
    limit('order:' . $c['id'], 10, 60 * 24);
    $b = branch_by_code((string) ($in['code'] ?? '')); if (!$b) fail('not_found', 'Pharmacy not found.', 404);
    $who = (array) ($in['customer'] ?? []);   // shared for this order only, with the user's agreement in the app
    $cid = customer_upsert(['customer_ref' => $c['customer_ref'], 'name' => $who['name'] ?? $c['name'], 'phone' => $who['phone'] ?? null, 'address' => $who['address'] ?? null,
                            'lat' => $who['lat'] ?? null, 'lng' => $who['lng'] ?? null, 'village' => $who['village'] ?? null, 'district' => $who['district'] ?? null, 'lang' => $who['lang'] ?? 'en', 'sms_ok' => !empty($who['sms_ok'])]);
    // A friend's referral code: used only if it exists and this is a new customer of the business (otherwise ignored, the order goes ahead).
    $rc = strtoupper(trim((string) ($in['referral_code'] ?? '')));
    if ($rc !== '' && ($ref = q('SELECT id FROM customers WHERE ref_code = ?', [$rc])->fetch()) && (int) $ref['id'] !== $cid
        && !q('SELECT 1 FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.org_id = ? AND o.customer_id = ? AND o.paid > 0', [$b['org_id'], $cid])->fetch())
        referral_join((int) $b['org_id'], $cid, $rc);
    $o = order_create($b, $cid, ['items' => (array) ($in['items'] ?? []), 'kind' => $in['kind'] ?? 'pickup', 'pickup_point_id' => $in['pickup_point_id'] ?? null, 'note' => $in['note'] ?? null,
                                 'source' => ($in['source'] ?? '') === 'refill' ? 'refill' : (($in['source'] ?? '') === 'asha' ? 'asha' : 'app'), 'customer_district' => $who['district'] ?? null,
                                 'rx_date' => $in['rx_date'] ?? null, 'customer_pays_full' => !empty($in['customer_pays_full']),
                                 'dose_pack' => !empty($in['dose_pack']), 'pickup_at' => $in['pickup_at'] ?? null, 'service' => $in['service'] ?? null]);
    if (!empty($in['pay']) && in_array($in['pay'], ['khata'], true)) $o['khataAsked'] = true;   // the shop decides at packing time
    json_out($o);

case 'orders':
    $c = cust_ref($in);
    json_out(['orders' => array_map(fn($o) => order_public($o), q('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 30', [$c['id']])->fetchAll())]);

case 'order_cancel':
    $c = cust_ref($in); $o = my_cust_order($c, (string) ($in['number'] ?? ''));
    if (!in_array($o['status'], ['new', 'accepted'], true)) fail('too_late', 'The pharmacy has already packed this order. Call them to cancel.');
    order_set_status($o, 'cancelled', null, ['reason' => 'cancelled by the customer']); json_out(['ok' => true]);

case 'pay_claim':   // "I paid": UPI to the app-made QR or the pharmacist's number, another person's QR, or cash. The shop confirms.
    $c = cust_ref($in); $o = my_cust_order($c, (string) ($in['number'] ?? ''));
    limit('claim:' . $c['id'], 10, 60);
    $method = in_array($in['method'] ?? '', ['upi_qr', 'upi_phone', 'other_qr', 'cash'], true) ? $in['method'] : 'upi_qr';
    if (in_array($method, ['upi_qr', 'upi_phone'], true) && empty($in['utr'])) fail('need_utr', 'Enter the 12-digit UPI reference number from your UPI app, so the pharmacy can find your payment.');
    json_out(pay_record(branch((int) $o['branch_id']), ['method' => $method, 'amount' => $in['amount'] ?? $o['total'], 'utr' => $in['utr'] ?? null, 'order_id' => $o['id'], 'note' => $in['note'] ?? null], 'customer', null));

case 'khata_pay_claim':   // paying off the credit book from the app
    $c = cust_ref($in); $b = branch_by_code((string) ($in['code'] ?? '')); if (!$b) fail('not_found', 'Pharmacy not found.', 404);
    if (!q('SELECT 1 FROM khata WHERE branch_id = ? AND customer_id = ? AND balance > 0', [$b['id'], $c['id']])->fetch()) fail('no_khata', 'You have no balance at this pharmacy.');
    limit('claim:' . $c['id'], 10, 60);
    json_out(pay_record($b, ['method' => in_array($in['method'] ?? '', ['upi_qr', 'upi_phone', 'other_qr', 'cash'], true) ? $in['method'] : 'upi_qr', 'amount' => $in['amount'] ?? 0, 'utr' => $in['utr'] ?? null,
                             'customer_id' => $c['id'], 'khata' => true], 'customer', null));

case 'wallet':   // everything money-related for this user across pharmacies: khata, points, referral code and earnings, payment claims
    $c = cust_ref($in);
    $kh = array_map(fn($k) => ['pharmacy' => $k['name'], 'code' => $k['code'], 'upiLink' => $k['upi_vpa'] && (float) $k['balance'] > 0 ? upi_link($k, (float) $k['balance'], 'credit') : null] + khata_view((int) $k['id'], 15),
                    q('SELECT k.*, b.name, b.code, b.upi_vpa, b.upi_name FROM khata k JOIN branches b ON b.id = k.branch_id WHERE k.customer_id = ?', [$c['id']])->fetchAll());
    $pts = array_map(fn($r) => ['business' => $r['name'], 'points' => round((float) $r['p'], 2)], q('SELECT o.name, SUM(p.delta) p FROM points p JOIN orgs o ON o.id = p.org_id WHERE p.customer_id = ? GROUP BY o.id HAVING p <> 0', [$c['id']])->fetchAll());
    json_out(['khata' => $kh, 'points' => $pts, 'refCode' => $c['ref_code'],
              'referrals' => q("SELECT o.name business, r.status, r.amount, r.payout FROM referrals r JOIN orgs o ON o.id = r.org_id WHERE r.referrer_id = ?", [$c['id']])->fetchAll(),
              'claims' => q("SELECT p.method, p.amount, p.status, p.created_at, b.name pharmacy FROM payments p JOIN branches b ON b.id = p.branch_id WHERE p.customer_id = ? AND p.by_whom = 'customer' ORDER BY p.id DESC LIMIT 20", [$c['id']])->fetchAll(),
              'linkedPhone' => (bool) $c['phone_hash']]);

case 'link_start':   // link a walk-in record (khata kept at the counter under a phone number) to the app account: SMS code to that phone
    $c = cust_ref($in); $phone = phone_norm($in['phone'] ?? ''); if (!$phone) fail('bad_phone', 'Enter your 10-digit mobile number.');
    limit('linkstart:' . $c['id'], 3, 60);
    $code = (string) random_int(100000, 999999);
    q('INSERT INTO hits (k) VALUES (?)', ['linkcode:' . $c['id'] . ':' . hash('sha256', $code . $phone)]);
    sms_send($phone, 'link_code', [$code]);
    json_out(['ok' => true, 'sent' => true] + (config('dev_show_codes') ? ['devCode' => $code] : []));
case 'link_confirm':
    $c = cust_ref($in); $phone = phone_norm($in['phone'] ?? ''); $code = preg_replace('/\D/', '', (string) ($in['code'] ?? ''));
    limit('linkconf:' . $c['id'], 6, 60);
    if (!$phone || !q('SELECT 1 FROM hits WHERE k = ? AND created_at >= NOW() - INTERVAL 15 MINUTE', ['linkcode:' . $c['id'] . ':' . hash('sha256', $code . $phone)])->fetch()) fail('bad_code', 'That code is not correct or has expired.', 422);
    $walk = q('SELECT * FROM customers WHERE phone_hash = ? AND id <> ?', [phone_hash($phone), $c['id']])->fetch();
    tx(function () use ($walk, $c, $phone) {
        if ($walk && !$walk['customer_ref']) {   // merge the walk-in record into the app customer
            foreach (['khata' => 'customer_id', 'orders' => 'customer_id', 'payments' => 'customer_id', 'points' => 'customer_id', 'complaints' => 'customer_id'] as $t => $col)
                q("UPDATE IGNORE $t SET $col = ? WHERE $col = ?", [$c['id'], $walk['id']]);
            q('UPDATE referrals SET referrer_id = ? WHERE referrer_id = ?', [$c['id'], $walk['id']]);
            q('UPDATE referrals SET referred_id = ? WHERE referred_id = ?', [$c['id'], $walk['id']]);
            q('DELETE FROM customers WHERE id = ?', [$walk['id']]);
        } elseif ($walk) fail('taken', 'This number is linked to another AushadhBox account.', 409);
        q('UPDATE customers SET phone_enc = ?, phone_hash = ?, sms_ok = 1 WHERE id = ?', [enc($phone), phone_hash($phone), $c['id']]);
    });
    json_out(['ok' => true, 'merged' => (bool) $walk]);

case 'referral_join':
    $c = cust_ref($in); $b = branch_by_code((string) ($in['code'] ?? '')); if (!$b) fail('not_found', 'Pharmacy not found.', 404);
    json_out(referral_join((int) $b['org_id'], (int) $c['id'], (string) ($in['referral_code'] ?? ''), ($in['payout'] ?? '') === 'cash' ? 'cash' : 'points'));

case 'complaint':
    $c = cust_ref($in); $b = branch_by_code((string) ($in['code'] ?? '')); if (!$b) fail('not_found', 'Pharmacy not found.', 404);
    limit('complaint:' . $c['id'], 5, 60 * 24);
    $topic = in_array($in['topic'] ?? '', ['overcharge', 'wrong', 'late', 'behaviour', 'quality', 'privacy', 'other'], true) ? $in['topic'] : 'other';
    $oid = !empty($in['number']) ? (int) my_cust_order($c, (string) $in['number'])['id'] : null;
    q('INSERT INTO complaints (branch_id, customer_id, order_id, topic, body) VALUES (?,?,?,?,?)', [$b['id'], $c['id'], $oid, $topic, str_in($in, 'body', 1000, true)]);
    audit('customer', (int) $c['id'], (int) $b['org_id'], (int) $b['id'], 'complaint', $topic);
    json_out(['ok' => true]);

case 'forget':   // the user deleted their AushadhBox account: remove the link and personal data, keep the shop's legal sale records anonymous
    $c = cust_ref($in);
    q("UPDATE customers SET customer_ref = NULL, name = 'Deleted user', phone_enc = NULL, phone_hash = NULL, address_enc = NULL, lat = NULL, lng = NULL, village = NULL WHERE id = ?", [$c['id']]);
    q('UPDATE orders SET address_enc = NULL WHERE customer_id = ?', [$c['id']]);
    json_out(['ok' => true]);

default: fail('unknown_action', 'Unknown action.', 404);
}
} catch (RuntimeException $e) {
    if (str_starts_with($e->getMessage(), 'short:')) fail('short', 'Not enough stock.', 422);
    throw $e;
}
