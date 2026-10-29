<?php
/* Orders, payments, khata (credit book), loyalty points and referral rewards.

   Payments work even when a shop has no printed QR or card machine:
   - upi_qr:    the platform makes a UPI QR / link from the shop's UPI ID (or its phone number @upi) with the exact amount;
   - upi_phone: the customer paid to the pharmacist's phone number in any UPI app;
   - other_qr:  paid to someone else's QR (a relative's, the shop next door);
   - cash, card;
   - khata:     buy now, pay later in the shop's credit book;
   - points:    loyalty points used as money.
   A payment the customer or rider records stays "claimed" until the shop confirms it, so both sides agree on the books. */
declare(strict_types=1);
require_once __DIR__ . '/network.php';

const ORDER_FLOW = [
    'new'       => ['accepted', 'cancelled'],
    'accepted'  => ['packed', 'cancelled'],
    'packed'    => ['ready', 'out', 'cancelled'],
    'ready'     => ['collected', 'out', 'cancelled'],
    'out'       => ['delivered', 'ready', 'cancelled'],
    'delivered' => [], 'collected' => [], 'cancelled' => [],
];

/* ---------- Customers ---------- */
function customer_upsert(array $c): int
{
    $phone = phone_norm($c['phone'] ?? null);
    $ref = isset($c['customer_ref']) && preg_match('/^[a-f0-9]{32}$/', (string) $c['customer_ref']) ? $c['customer_ref'] : null;
    $row = $ref ? q('SELECT * FROM customers WHERE customer_ref = ?', [$ref])->fetch() : null;
    if (!$row && $phone && !$ref) $row = q('SELECT * FROM customers WHERE phone_hash = ?', [phone_hash($phone)])->fetch();
    $name = trim(mb_substr((string) ($c['name'] ?? ''), 0, 80)) ?: 'Customer';
    $fields = ['name' => $name, 'village' => $c['village'] ?? null, 'district' => $c['district'] ?? null, 'lang' => in_array($c['lang'] ?? 'en', ['en', 'hi', 'bn', 'mr', 'te', 'ta', 'gu', 'kn', 'ml', 'pa'], true) ? ($c['lang'] ?? 'en') : 'en'];
    $lat = isset($c['lat']) && is_numeric($c['lat']) ? (float) $c['lat'] : null; $lng = isset($c['lng']) && is_numeric($c['lng']) ? (float) $c['lng'] : null;
    if ($row) {
        q('UPDATE customers SET name = ?, village = COALESCE(?, village), district = COALESCE(?, district), lat = COALESCE(?, lat), lng = COALESCE(?, lng), address_enc = COALESCE(?, address_enc),
           sms_ok = GREATEST(sms_ok, ?) WHERE id = ?', [$fields['name'], $fields['village'], $fields['district'], $lat, $lng, enc($c['address'] ?? null), !empty($c['sms_ok']) ? 1 : 0, $row['id']]);
        // A phone number is added to an app customer only if no one else already has it (linking an existing walk-in record needs an SMS code: see customer_link_*).
        if ($phone && !$row['phone_hash'] && !q('SELECT 1 FROM customers WHERE phone_hash = ?', [phone_hash($phone)])->fetch())
            q('UPDATE customers SET phone_enc = ?, phone_hash = ? WHERE id = ?', [enc($phone), phone_hash($phone), $row['id']]);
        return (int) $row['id'];
    }
    $hash = $phone ? phone_hash($phone) : null;
    if ($ref && $hash && q('SELECT 1 FROM customers WHERE phone_hash = ?', [$hash])->fetch()) $hash = null;   // number belongs to a walk-in record; link later with an SMS code
    for ($i = 0; $i < 5; $i++) { $rc = code(6); if (!q('SELECT 1 FROM customers WHERE ref_code = ?', [$rc])->fetch()) break; }
    q('INSERT INTO customers (customer_ref, name, phone_enc, phone_hash, address_enc, village, district, lat, lng, lang, sms_ok, ref_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
      [$ref, $name, $hash ? enc($phone) : null, $hash, enc($c['address'] ?? null), $fields['village'], $fields['district'], $lat, $lng, $fields['lang'], !empty($c['sms_ok']) ? 1 : 0, $rc]);
    return (int) db()->lastInsertId();
}
function customer_public(array $c, bool $full = false): array
{
    $phone = dec($c['phone_enc']);
    return ['id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $full ? $phone : phone_mask($phone), 'village' => $c['village'], 'district' => $c['district'],
            'address' => $full ? dec($c['address_enc']) : null, 'app' => (bool) $c['customer_ref'], 'refCode' => $c['ref_code'], 'smsOk' => (bool) $c['sms_ok']];
}

/* ---------- Orders ---------- */
function order_number(array $b): string
{
    $p = substr(preg_replace('/[^A-Z]/', '', strtoupper($b['name'])) . 'XX', 0, 2);
    for ($i = 0; $i < 8; $i++) { $n = $p . '-' . random_int(10000, 99999); if (!q('SELECT 1 FROM orders WHERE number = ?', [$n])->fetch()) return $n; }
    return $p . '-' . bin2hex(random_bytes(3));
}

/* Create an order. $o: items[{name, qty, catalog_id?}], kind pickup|delivery|point, pickup_point_id, note, source, rx_date, customer_district,
   customer_pays_full (accepts the full trip fee), asked_branch_id (passed on from another pharmacy), override (pharmacist exception with reason).
   Returns the order with the delivery OTP in plain text (only this once). */
function order_create(array $b, ?int $customerId, array $o, ?int $staffId = null): array
{
    if ($b['status'] !== 'active' || ($b['org_status'] ?? 'approved') !== 'approved') fail('not_open', 'This pharmacy is not taking orders right now.', 403);
    $items = array_slice(array_values(array_filter((array) ($o['items'] ?? []), fn($i) => is_array($i) && mb_strlen(trim((string) ($i['name'] ?? ''))) >= 2)), 0, 30);
    if (!$items) fail('empty', 'Add at least one medicine.');
    $kind = in_array($o['kind'] ?? 'pickup', ['pickup', 'delivery', 'point'], true) ? $o['kind'] : 'pickup';
    $source = in_array($o['source'] ?? 'app', ['app', 'walkin', 'sms', 'missedcall', 'asha', 'phone', 'refill'], true) ? $o['source'] : 'app';
    $cust = $customerId ? q('SELECT * FROM customers WHERE id = ?', [$customerId])->fetch() : null;
    $has = branch_has($b, $items); $lines = []; $subtotal = 0.0;
    foreach ($has as $i => $h) {
        $qty = max(1, min(999, (int) ($items[$i]['qty'] ?? 1)));
        $price = $h['price'] !== null ? (float) $h['price'] : null;
        $lines[] = ['product_id' => $h['productId'], 'name' => $h['matched'] ?: $h['name'], 'qty' => $qty, 'price' => $price, 'schedule' => $h['schedule'],
                    'status' => in_array($h['status'], ['in', 'low'], true) ? 'ok' : 'missing', 'cold' => $h['cold'], 'matched' => $h['matched']];
        if ($price !== null && in_array($h['status'], ['in', 'low'], true)) $subtotal += $price * $qty;
    }
    $rules = sale_rules($lines, $kind === 'point' ? 'delivery' : $kind, $o['customer_district'] ?? ($cust['district'] ?? null), (string) $b['district'], $source);
    if (!$rules['ok']) fail('not_allowed', implode(' ', $rules['reasons']), 422);
    $fee = 0.0; $dist = null; $worth = null; $point = null;
    if ($kind === 'delivery') {
        if (!$cust || $cust['lat'] === null || $b['lat'] === null) fail('no_location', 'Share the delivery location (or choose a village pickup point).');
        $dist = road_km(km((float) $b['lat'], (float) $b['lng'], (float) $cust['lat'], (float) $cust['lng']), $b['area']);
        $qt = delivery_quote($b, $dist, $subtotal, $lines, shared_stops((int) $b['id'], (float) $cust['lat'], (float) $cust['lng']), !empty($o['customer_pays_full']));
        if (!$qt['possible']) {
            $ov = trim((string) ($o['override'] ?? ''));
            if (!$staffId || mb_strlen($ov) < 5) fail('too_far', implode(' ', $qt['why']), 422);
            $qt['why'][] = 'Exception by the pharmacist: ' . mb_substr($ov, 0, 120); $qt['worth'] = ($qt['worth'] ?? []) + ['override' => mb_substr($ov, 0, 120)];
        }
        $fee = (float) $qt['fee']; $worth = $qt['worth'] ? json_encode($qt['worth'] + ['why' => $qt['why']], JSON_UNESCAPED_UNICODE) : null;
    }
    if ($kind === 'point') {
        $point = q('SELECT * FROM pickup_points WHERE id = ? AND branch_id = ? AND active = 1', [(int) ($o['pickup_point_id'] ?? 0), $b['id']])->fetch();
        if (!$point) fail('bad_point', 'Choose one of this pharmacy\'s pickup points.');
        $fee = min(10.0, (float) $b['fee_base']);   // village rounds: small flat fee
    }
    $otp = $kind === 'pickup' ? null : str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $number = order_number($b);
    $rxDate = isset($o['rx_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $o['rx_date']) ? $o['rx_date'] : null;
    $total = round($subtotal + $fee, 2);
    $id = tx(function () use ($b, $customerId, $source, $kind, $point, $rules, $rxDate, $subtotal, $fee, $total, $dist, $worth, $otp, $o, $cust, $number, $lines) {
        q('INSERT INTO orders (number, branch_id, asked_branch_id, customer_id, source, kind, pickup_point_id, rx_needed, rx_status, rx_date, subtotal, delivery_fee, total, distance_km, worth, otp_hash, note, address_enc)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$number, $b['id'], isset($o['asked_branch_id']) ? (int) $o['asked_branch_id'] : null, $customerId, $source, $kind, $point['id'] ?? null, $rules['rx'] ? 1 : 0,
           $rules['rx'] ? ($rxDate ? 'seen' : 'awaited') : null, $rxDate, round($subtotal, 2), $fee, $total, $dist, $worth, $otp ? hash('sha256', $number . $otp) : null,
           str_in($o, 'note', 300), $kind === 'delivery' ? ($cust['address_enc'] ?? null) : null]);
        $oid = (int) db()->lastInsertId();
        foreach ($lines as $l) q('INSERT INTO order_items (order_id, product_id, name, qty, price, schedule, status) VALUES (?,?,?,?,?,?,?)', [$oid, $l['product_id'], $l['name'], $l['qty'], $l['price'], $l['schedule'], $l['status']]);
        return $oid;
    });
    audit($staffId ? 'staff' : 'customer', $staffId, (int) $b['org_id'], (int) $b['id'], 'order_new', "$number ($source, $kind, " . money($total) . ')');
    $out = order_public(order((int) $id));
    $out['otp'] = $otp; $out['rules'] = ['rx' => $rules['rx'], 'counterCheck' => $rules['counterCheck']];
    return $out;
}

function order(int $id): array { $o = q('SELECT * FROM orders WHERE id = ?', [$id])->fetch(); if (!$o) fail('not_found', 'Order not found.', 404); return $o; }
function order_public(array $o, bool $forStaff = false): array
{
    $b = branch((int) $o['branch_id']);
    $items = q('SELECT id, product_id, name, qty, price, schedule, batch, exp, status FROM order_items WHERE order_id = ?', [$o['id']])->fetchAll();
    $cust = $o['customer_id'] ? q('SELECT * FROM customers WHERE id = ?', [$o['customer_id']])->fetch() : null;
    $del = q('SELECT * FROM deliveries WHERE order_id = ?', [$o['id']])->fetch();
    $point = $o['pickup_point_id'] ? q('SELECT name, village, kind, days FROM pickup_points WHERE id = ?', [$o['pickup_point_id']])->fetch() : null;
    return ['id' => (int) $o['id'], 'number' => $o['number'], 'status' => $o['status'], 'kind' => $o['kind'], 'source' => $o['source'],
        'pharmacy' => ['code' => $b['code'], 'name' => $b['name'], 'phone' => $b['phone'], 'address' => $b['address'], 'village' => $b['village']],
        'askedPharmacy' => $o['asked_branch_id'] ? branch((int) $o['asked_branch_id'])['name'] : null,
        'items' => array_map(fn($i) => ['id' => (int) $i['id'], 'name' => $i['name'], 'qty' => (int) $i['qty'], 'price' => $i['price'] !== null ? (float) $i['price'] : null,
                                        'schedule' => $i['schedule'], 'status' => $i['status'], 'batch' => $forStaff ? $i['batch'] : null, 'exp' => $i['exp']], $items),
        'rx' => ['needed' => (bool) $o['rx_needed'], 'status' => $o['rx_status'], 'date' => $o['rx_date']],
        'subtotal' => (float) $o['subtotal'], 'deliveryFee' => (float) $o['delivery_fee'], 'discount' => (float) $o['discount'], 'total' => (float) $o['total'],
        'paid' => (float) $o['paid'], 'payStatus' => $o['pay_status'], 'distanceKm' => $o['distance_km'] !== null ? (float) $o['distance_km'] : null,
        'worth' => $o['worth'] ? json_decode($o['worth'], true) : null, 'note' => $o['note'], 'point' => $point ?: null,
        'customer' => $cust ? customer_public($cust, $forStaff) : null,   // the shop serving the order sees the number to call back; customers see their own order only
        'address' => $forStaff && $o['kind'] === 'delivery' ? dec($o['address_enc']) : null,
        'delivery' => $del ? ['mode' => $del['mode'], 'status' => $del['status'], 'partner' => $del['partner'], 'courier' => $del['courier'], 'tracking' => $del['tracking'],
                              'lastAt' => $del['last_at'], 'lat' => $forStaff && $del['last_lat'] !== null ? (float) $del['last_lat'] : null, 'lng' => $forStaff && $del['last_lng'] !== null ? (float) $del['last_lng'] : null] : null,
        'payUpi' => $b['upi_vpa'] && (float) $o['total'] > (float) $o['paid'] ? upi_link($b, (float) $o['total'] - (float) $o['paid'], $o['number']) : null,
        'createdAt' => $o['created_at'], 'updatedAt' => $o['updated_at']];
}

/* Move an order forward. Packing takes stock (first-expiring batch first); cancelling after packing puts it back. */
function order_set_status(array $o, string $to, ?int $staffId, array $extra = []): array
{
    $from = $o['status'];
    if (!in_array($to, ORDER_FLOW[$from] ?? [], true)) fail('bad_step', "An order that is $from cannot become $to.");
    if (in_array($to, ['packed'], true) && $o['rx_needed'] && !in_array($o['rx_status'], ['seen', 'ok'], true)) fail('rx_needed', 'Check the prescription first (it has prescription-only medicines).', 422);
    try { tx(function () use ($o, $to, $from, $staffId, $extra) {
        if ($to === 'packed') {
            foreach (q("SELECT * FROM order_items WHERE order_id = ? AND status = 'ok' AND product_id IS NOT NULL", [$o['id']])->fetchAll() as $it) {
                $r = stock_take((int) $o['branch_id'], (int) $it['product_id'], (int) $it['qty'], $o['number'], $staffId);
                if ($r['short'] > 0) throw new RuntimeException('short:' . $it['name']);
                $first = $r['batches'][0] ?? null;
                q('UPDATE order_items SET batch = ?, exp = ? WHERE id = ?', [$first['batch'] ?? null, $first['exp'] ?? null, $it['id']]);
            }
        }
        if ($to === 'cancelled' && in_array($from, ['packed', 'ready', 'out'], true)) {
            foreach (q("SELECT * FROM order_items WHERE order_id = ? AND status = 'ok' AND product_id IS NOT NULL", [$o['id']])->fetchAll() as $it)
                stock_move((int) $o['branch_id'], (int) $it['product_id'], (string) $it['batch'], (int) $it['qty'], 'return', $o['number'], $staffId);
        }
        q('UPDATE orders SET status = ? WHERE id = ?', [$to, $o['id']]);
        if (in_array($to, ['delivered', 'collected'], true)) order_completed(order((int) $o['id']));
    }); } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'short:')) fail('short', 'Not enough stock of ' . substr($e->getMessage(), 6) . '. Find it in the network or mark it missing.', 422);
        throw $e;
    }
    $b = branch((int) $o['branch_id']);
    audit($staffId ? 'staff' : 'system', $staffId, (int) $b['org_id'], (int) $b['id'], 'order_' . $to, $o['number'] . (isset($extra['reason']) ? ': ' . $extra['reason'] : ''));
    return order(((int) $o['id']));
}

/* A finished order: loyalty points on what was paid, the referral reward on a new customer's first paid order,
   and the referral share for the pharmacy that passed the order on. */
function order_completed(array $o): void
{
    if (!$o['customer_id']) return;
    $b = branch((int) $o['branch_id']);
    $paid = (float) $o['paid'];
    if ($paid > 0 && (float) $b['loyalty_pct'] > 0 && !q("SELECT 1 FROM points WHERE order_id = ? AND reason = 'purchase'", [$o['id']])->fetch())
        q("INSERT INTO points (org_id, customer_id, delta, reason, order_id) VALUES (?,?,?, 'purchase', ?)", [$b['org_id'], $o['customer_id'], round(($paid - (float) $o['delivery_fee']) * (float) $b['loyalty_pct'] / 100, 2), $o['id']]);
    $ref = q("SELECT * FROM referrals WHERE org_id = ? AND referred_id = ? AND status = 'waiting'", [$b['org_id'], $o['customer_id']])->fetch();
    if ($ref && $paid > 0) {
        q("UPDATE referrals SET status = 'earned', amount = ?, order_id = ? WHERE id = ?", [(float) $b['referral_amt'], $o['id'], $ref['id']]);
        if ($ref['payout'] === 'points') q("INSERT INTO points (org_id, customer_id, delta, reason, order_id) VALUES (?,?,?, 'referral', ?)", [$b['org_id'], $ref['referrer_id'], (float) $b['referral_amt'], $o['id']]);
    }
}

/* ---------- Payments ---------- */
/* UPI deep link (NPCI format). Opens any UPI app with the shop's UPI ID, name, amount and the order number filled in. */
function upi_link(array $b, float $amount, string $note): ?string
{
    $vpa = trim((string) $b['upi_vpa']);
    if (!preg_match('/^[a-zA-Z0-9._\-]{2,256}@[a-zA-Z][a-zA-Z0-9.\-]{1,64}$/', $vpa)) return null;
    return 'upi://pay?' . http_build_query(['pa' => $vpa, 'pn' => mb_substr((string) ($b['upi_name'] ?: $b['name']), 0, 40), 'am' => number_format($amount, 2, '.', ''),
                                            'cu' => 'INR', 'tn' => mb_substr('AushadhBox ' . $note, 0, 40)], '', '&', PHP_QUERY_RFC3986);
}

/* Record a payment. Staff entries are confirmed at once; customer and rider entries wait for the shop to confirm. */
function pay_record(array $b, array $in, string $by, ?int $staffId): array
{
    $method = (string) ($in['method'] ?? '');
    if (!in_array($method, ['upi_qr', 'upi_phone', 'other_qr', 'cash', 'card', 'points'], true)) fail('bad_method', 'Choose how it was paid.');
    $amount = round((float) ($in['amount'] ?? 0), 2);
    if ($amount <= 0 || $amount > 200000) fail('bad_amount', 'Enter the amount paid.');
    $utr = preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['utr'] ?? '')) ?: null;
    if ($utr && !preg_match('/^[A-Za-z0-9]{8,22}$/', $utr)) fail('bad_utr', 'The UPI reference number is 12 digits (in the UPI app under the payment).');
    if ($utr && q("SELECT 1 FROM payments WHERE utr = ? AND status <> 'rejected'", [$utr])->fetch()) fail('dup_utr', 'This UPI reference number was already recorded.', 409);
    $order = isset($in['order_id']) && $in['order_id'] ? order((int) $in['order_id']) : null;
    if ($order && (int) $order['branch_id'] !== (int) $b['id']) fail('not_found', 'Order not found.', 404);
    $cust = $order['customer_id'] ?? ($in['customer_id'] ?? null);
    $khataPay = !empty($in['khata']);   // paying off the credit book
    if ($method === 'points') {
        if (!$cust || points_balance((int) $b['org_id'], (int) $cust) < $amount) fail('few_points', 'Not enough points.');
        $by = 'staff';
    }
    $status = $by === 'staff' ? 'confirmed' : 'claimed';
    q('INSERT INTO payments (branch_id, customer_id, order_id, method, amount, utr, note, status, by_whom, staff_id, confirmed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$b['id'], $cust, $order['id'] ?? null, $method, $amount, $utr, str_in($in, 'note', 200), $status, $by, $staffId, $status === 'confirmed' ? date('Y-m-d H:i:s') : null]);
    $pid = (int) db()->lastInsertId();
    if ($khataPay) q('UPDATE payments SET note = CONCAT(COALESCE(note, ""), ?) WHERE id = ?', [' [khata]', $pid]);
    if ($status === 'confirmed') pay_apply(q('SELECT * FROM payments WHERE id = ?', [$pid])->fetch(), $khataPay);
    audit($by === 'staff' ? 'staff' : $by, $staffId, (int) $b['org_id'], (int) $b['id'], 'payment_' . $status, "$method " . money($amount) . ($order ? ' for ' . $order['number'] : '') . ($utr ? " UTR $utr" : ''));
    return ['id' => $pid, 'status' => $status];
}

/* The shop confirms or rejects a payment someone else recorded. */
function pay_decide(array $b, int $paymentId, bool $ok, int $staffId): void
{
    $p = q("SELECT * FROM payments WHERE id = ? AND branch_id = ? AND status = 'claimed'", [$paymentId, $b['id']])->fetch();
    if (!$p) fail('not_found', 'Payment not found or already decided.', 404);
    q('UPDATE payments SET status = ?, staff_id = ?, confirmed_at = NOW() WHERE id = ?', [$ok ? 'confirmed' : 'rejected', $staffId, $p['id']]);
    if ($ok) pay_apply(q('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch(), str_contains((string) $p['note'], '[khata]'));
    audit('staff', $staffId, (int) $b['org_id'], (int) $b['id'], $ok ? 'payment_confirmed' : 'payment_rejected', $p['method'] . ' ' . money((float) $p['amount']));
}

function pay_apply(array $p, bool $khataPay): void
{
    if ($p['method'] === 'points') q("INSERT INTO points (org_id, customer_id, delta, reason, order_id) SELECT org_id, ?, ?, 'redeem', ? FROM branches WHERE id = ?", [$p['customer_id'], -(float) $p['amount'], $p['order_id'], $p['branch_id']]);
    if ($p['order_id']) {
        q("UPDATE orders SET paid = paid + ?, discount = discount + ?, pay_status = IF(paid + ? >= total, 'paid', 'partial') WHERE id = ?",
          [(float) $p['amount'], $p['method'] === 'points' ? (float) $p['amount'] : 0, (float) $p['amount'], $p['order_id']]);
        $o = order((int) $p['order_id']);
        if (in_array($o['status'], ['delivered', 'collected'], true)) order_completed($o);
    }
    if ($khataPay && $p['customer_id']) {
        $k = khata_get((int) $p['branch_id'], (int) $p['customer_id']);
        q('UPDATE khata SET balance = GREATEST(0, balance - ?) WHERE id = ?', [(float) $p['amount'], $k['id']]);
        q("INSERT INTO khata_entries (khata_id, kind, amount, payment_id, note, staff_id) VALUES (?, 'payment', ?, ?, ?, ?)", [$k['id'], (float) $p['amount'], $p['id'], $p['method'], $p['staff_id']]);
    }
}

/* ---------- Khata (credit book) ---------- */
function khata_get(int $branchId, int $customerId): array
{
    q('INSERT IGNORE INTO khata (branch_id, customer_id) VALUES (?,?)', [$branchId, $customerId]);
    return q('SELECT * FROM khata WHERE branch_id = ? AND customer_id = ?', [$branchId, $customerId])->fetch();
}
/* Put (part of) an order on the credit book. */
function khata_credit(array $b, int $customerId, float $amount, ?int $orderId, ?int $staffId, string $note = ''): array
{
    if (!$b['khata_on']) fail('khata_off', 'This pharmacy does not keep a credit book.');
    $k = khata_get((int) $b['id'], $customerId);
    if ($k['status'] !== 'open') fail('khata_closed', 'The credit book for this customer is closed.');
    if ((float) $k['balance'] + $amount > (float) $k['credit_limit']) fail('over_limit', 'This is above the credit limit (' . money((float) $k['credit_limit']) . '). Balance now ' . money((float) $k['balance']) . '.', 422);
    q('UPDATE khata SET balance = balance + ? WHERE id = ?', [$amount, $k['id']]);
    q("INSERT INTO khata_entries (khata_id, kind, amount, order_id, note, staff_id) VALUES (?, 'credit', ?, ?, ?, ?)", [$k['id'], $amount, $orderId, mb_substr($note, 0, 200), $staffId]);
    if ($orderId) q("UPDATE orders SET paid = paid + ?, pay_status = IF(paid + ? >= total, 'khata', 'partial') WHERE id = ?", [$amount, $amount, $orderId]);
    audit('staff', $staffId, (int) $b['org_id'], (int) $b['id'], 'khata_credit', money($amount) . " customer #$customerId");
    return khata_get((int) $b['id'], $customerId);
}
function khata_view(int $khataId, int $limit = 50): array
{
    $k = q('SELECT * FROM khata WHERE id = ?', [$khataId])->fetch();
    return ['balance' => (float) $k['balance'], 'limit' => (float) $k['credit_limit'], 'remindDay' => (int) $k['remind_day'], 'status' => $k['status'],
            'entries' => array_map(fn($e) => ['kind' => $e['kind'], 'amount' => (float) $e['amount'], 'note' => $e['note'], 'at' => $e['created_at'],
                                              'order' => $e['order_id'] ? q('SELECT number FROM orders WHERE id = ?', [$e['order_id']])->fetchColumn() : null],
                                  q('SELECT * FROM khata_entries WHERE khata_id = ? ORDER BY id DESC LIMIT ' . $limit, [$khataId])->fetchAll())];
}

/* ---------- Loyalty and referrals ---------- */
function points_balance(int $orgId, int $customerId): float { return round((float) q('SELECT COALESCE(SUM(delta), 0) FROM points WHERE org_id = ? AND customer_id = ?', [$orgId, $customerId])->fetchColumn(), 2); }

/* A new customer gives a friend's referral code. Rewards come after the new customer's first paid order. */
function referral_join(int $orgId, int $newCustomerId, string $code, string $payout = 'points'): array
{
    $ref = q('SELECT * FROM customers WHERE ref_code = ?', [strtoupper(trim($code))])->fetch();
    if (!$ref) fail('bad_code', 'That referral code was not found.', 404);
    if ((int) $ref['id'] === $newCustomerId) fail('own_code', 'You cannot use your own code.');
    if (q("SELECT 1 FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.org_id = ? AND o.customer_id = ? AND o.paid > 0", [$orgId, $newCustomerId])->fetch()) fail('not_new', 'Referral codes are for new customers of this pharmacy.');
    q('INSERT IGNORE INTO referrals (org_id, referrer_id, referred_id, payout) VALUES (?,?,?,?)', [$orgId, $ref['id'], $newCustomerId, $payout === 'cash' ? 'cash' : 'points']);
    q('UPDATE customers SET referred_by = COALESCE(referred_by, ?) WHERE id = ?', [$ref['id'], $newCustomerId]);
    return ['ok' => true, 'referrer' => mb_substr($ref['name'], 0, 1) . '***'];
}
