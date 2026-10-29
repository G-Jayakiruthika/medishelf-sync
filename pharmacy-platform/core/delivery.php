<?php
/* Delivery: the shop's own rider, a local partner (auto driver, bus parcel, India Post, ASHA worker) or a courier company.
   The customer gives a 4-digit code to whoever hands over the medicines; cash on delivery is recorded as a claim the shop confirms. */
declare(strict_types=1);
require_once __DIR__ . '/orders.php';

const COURIERS = ['porter' => 'Porter', 'shadowfax' => 'Shadowfax', 'dunzo' => 'Dunzo', 'borzo' => 'Borzo', 'indiapost' => 'India Post (Speed Post)'];

function delivery_assign(array $o, array $in, int $staffId): array
{
    if (!in_array($o['kind'], ['delivery', 'point'], true)) fail('not_delivery', 'This order is for pickup at the counter.');
    if (!in_array($o['status'], ['packed', 'ready'], true)) fail('not_packed', 'Pack the order first.');
    $mode = in_array($in['mode'] ?? '', ['own', 'partner', 'courier'], true) ? $in['mode'] : 'own';
    $rider = null; $partner = null; $phone = null; $courier = null; $tracking = null;
    if ($mode === 'own') {
        $rider = q("SELECT * FROM staff WHERE id = ? AND role = 'rider' AND status = 'active' AND org_id = (SELECT org_id FROM branches WHERE id = ?)", [(int) ($in['rider_id'] ?? 0), $o['branch_id']])->fetch();
        if (!$rider) fail('bad_rider', 'Choose one of your delivery staff.');
    } elseif ($mode === 'partner') {
        $partner = str_in($in, 'partner', 80, true); $phone = phone_norm($in['partner_phone'] ?? '') ?: null;
    } else {
        $courier = array_key_exists($in['courier'] ?? '', COURIERS) ? $in['courier'] : fail('bad_courier', 'Choose a courier.');
        $tracking = courier_book($courier, $o, $in);
    }
    q('INSERT INTO deliveries (order_id, mode, rider_id, partner, partner_phone, courier, tracking, cod_amount) VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE mode = VALUES(mode), rider_id = VALUES(rider_id), partner = VALUES(partner), partner_phone = VALUES(partner_phone), courier = VALUES(courier),
       tracking = VALUES(tracking), status = \'assigned\', cod_amount = VALUES(cod_amount)',
      [$o['id'], $mode, $rider['id'] ?? null, $partner, $phone, $courier, $tracking, max(0, (float) $o['total'] - (float) $o['paid'])]);
    if ($o['status'] !== 'out') order_set_status($o, 'out', $staffId);
    return q('SELECT * FROM deliveries WHERE order_id = ?', [$o['id']])->fetch();
}

/* Courier companies need a business account and an API key per company (config 'couriers').
   Without a key the booking is recorded for the pharmacist to book by phone, and they type the tracking number. */
function courier_book(string $courier, array $o, array $in): ?string
{
    $keys = (array) (config('couriers') ?? []);
    if (empty($keys[$courier])) return isset($in['tracking']) ? mb_substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $in['tracking']), 0, 60) : null;
    // A real connector goes here (each company has its own API). The interface: book(order, pickup, drop) → tracking id.
    return 'PENDING-' . strtoupper($courier) . '-' . $o['number'];
}

/* Rider app: where I am (shown to the shop; never shown to the customer as an exact point). */
function rider_ping(int $riderId, int $orderId, float $lat, float $lng): void
{
    q("UPDATE deliveries SET last_lat = ?, last_lng = ?, last_at = NOW(), status = IF(status = 'assigned', 'picked', status) WHERE order_id = ? AND rider_id = ? AND status IN ('assigned','picked')", [$lat, $lng, $orderId, $riderId]);
}

/* Hand over with the customer's 4-digit code. Cash collected is recorded and waits for the shop to confirm. */
function delivery_done(array $o, string $otp, float $cash, ?int $riderId, ?int $staffId): array
{
    $d = q('SELECT * FROM deliveries WHERE order_id = ?', [$o['id']])->fetch();
    if (!$d || !in_array($d['status'], ['assigned', 'picked'], true)) fail('no_delivery', 'This order is not out for delivery.');
    if ($riderId && (int) $d['rider_id'] !== $riderId) fail('not_yours', 'This delivery is assigned to someone else.', 403);
    $key = 'otp:' . $o['id'];
    limit($key, 6, 30);
    if (!$o['otp_hash'] || !hash_equals($o['otp_hash'], hash('sha256', $o['number'] . preg_replace('/\D/', '', $otp)))) fail('bad_otp', 'The code is not correct. Ask the customer for the 4-digit code in their app or SMS.', 422);
    $b = branch((int) $o['branch_id']);
    if ($cash > 0) pay_record($b, ['method' => 'cash', 'amount' => $cash, 'order_id' => $o['id'], 'note' => 'Collected on delivery'], $riderId ? 'rider' : 'staff', $riderId ?? $staffId);
    q("UPDATE deliveries SET status = 'delivered', done_at = NOW(), cod_collected = ? WHERE id = ?", [$cash, $d['id']]);
    return order_set_status(order((int) $o['id']), 'delivered', $riderId ?? $staffId);
}

function delivery_failed(array $o, string $reason, ?int $riderId): void
{
    q("UPDATE deliveries SET status = 'failed', fail_reason = ?, done_at = NOW() WHERE order_id = ?", [mb_substr($reason, 0, 120), $o['id']]);
    if ($o['status'] === 'out') order_set_status($o, 'ready', $riderId, ['reason' => 'delivery failed: ' . $reason]);
}

/* The rider's list for today, nearest-neighbour order from the shop (Python analytics can do better: analytics/routes.py). */
function rider_route(int $riderId): array
{
    $rows = q("SELECT o.*, d.status dstatus, c.lat clat, c.lng clng, c.name cname, c.village cvillage, b.lat blat, b.lng blng
               FROM deliveries d JOIN orders o ON o.id = d.order_id JOIN branches b ON b.id = o.branch_id LEFT JOIN customers c ON c.id = o.customer_id
               WHERE d.rider_id = ? AND d.status IN ('assigned','picked')", [$riderId])->fetchAll();
    if (!$rows) return [];
    $here = [(float) ($rows[0]['blat'] ?? 0), (float) ($rows[0]['blng'] ?? 0)]; $out = [];
    while ($rows) {
        usort($rows, fn($a, $b) => (($a['clat'] === null) <=> ($b['clat'] === null)) ?: (km($here[0], $here[1], (float) $a['clat'], (float) $a['clng']) <=> km($here[0], $here[1], (float) $b['clat'], (float) $b['clng'])));
        $r = array_shift($rows);
        if ($r['clat'] !== null) $here = [(float) $r['clat'], (float) $r['clng']];
        $p = order_public($r, true);
        $out[] = $p + ['mapUrl' => $r['clat'] !== null ? 'https://www.google.com/maps/dir/?api=1&destination=' . $r['clat'] . ',' . $r['clng'] : null, 'cod' => max(0, (float) $r['total'] - (float) $r['paid'])];
    }
    return $out;
}
