<?php
/* The pharmacy network: linked pharmacies, finding a medicine nearby, passing an order on, moving stock,
   and delivery quotes with the "worth it" rule for long distances. */
declare(strict_types=1);
require_once __DIR__ . '/stock.php';

/* Medicines where a long trip is worth making even at a loss: missing a dose is dangerous. */
const ESSENTIAL = ['insulin', 'levetiracetam', 'phenytoin', 'valproate', 'carbamazepine', 'phenobarbital', 'nitroglycerin', 'glyceryl trinitrate', 'isosorbide',
                   'salbutamol', 'levosalbutamol', 'budesonide', 'adrenaline', 'epinephrine', 'anti snake venom', 'warfarin', 'acenocoumarol', 'digoxin',
                   'antiretroviral', 'tenofovir', 'dolutegravir', 'isoniazid', 'rifampicin', 'tacrolimus', 'cyclosporine', 'hydrocortisone', 'levothyroxine'];

function branch(int $id): array
{
    $b = q('SELECT b.*, o.status org_status, o.name org_name FROM branches b JOIN orgs o ON o.id = b.org_id WHERE b.id = ?', [$id])->fetch();
    if (!$b) fail('not_found', 'Pharmacy not found.', 404);
    return $b;
}
function branch_by_code(string $code): ?array
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', preg_replace('/^AUSHADHBOX-PH:/i', '', trim($code))));
    if (strlen($code) !== 8) return null;
    return q("SELECT b.*, o.status org_status, o.name org_name FROM branches b JOIN orgs o ON o.id = b.org_id WHERE b.code = ? AND b.status = 'active'", [$code])->fetch() ?: null;
}
function branch_public(array $b, ?float $lat = null, ?float $lng = null): array
{
    $d = ($lat !== null && $lng !== null && $b['lat'] !== null) ? road_km(km($lat, $lng, (float) $b['lat'], (float) $b['lng']), $b['area']) : null;
    return ['code' => $b['code'], 'name' => $b['name'], 'kind' => $b['kind'], 'address' => $b['address'], 'village' => $b['village'], 'district' => $b['district'], 'state' => $b['state'],
            'pincode' => $b['pincode'], 'phone' => $b['phone'], 'hours' => $b['hours'], 'lat' => $b['lat'] !== null ? (float) $b['lat'] : null, 'lng' => $b['lng'] !== null ? (float) $b['lng'] : null,
            'area' => $b['area'], 'distanceKm' => $d, 'openNow' => open_now((string) $b['hours']),
            'pay' => ['upi' => (bool) $b['upi_vpa'], 'cash' => (bool) $b['accepts_cash'], 'khata' => (bool) $b['khata_on']],
            'delivery' => (bool) $b['delivery_on'], 'radiusKm' => (float) $b['radius_km'], 'loyaltyPct' => (float) $b['loyalty_pct'], 'janAushadhi' => $b['kind'] === 'janaushadhi'];
}

/* "Mon-Sat 08:30-21:30; Sun 09:00-13:00" → open now? Unknown hours count as open. */
function open_now(string $hours, ?int $ts = null): ?bool
{
    $hours = trim($hours); if ($hours === '') return null;
    $ts = $ts ?? time(); $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; $today = (int) date('N', $ts) - 1; $hm = date('H:i', $ts);
    foreach (explode(';', $hours) as $part) {
        if (!preg_match('/^\s*([A-Za-z]{3})(?:\s*-\s*([A-Za-z]{3}))?\s+(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})\s*$/', $part, $m)) continue;
        $a = array_search(ucfirst(strtolower($m[1])), $days, true); $z = $m[2] ? array_search(ucfirst(strtolower($m[2])), $days, true) : $a;
        if ($a === false || $z === false) continue;
        $inDays = $a <= $z ? ($today >= $a && $today <= $z) : ($today >= $a || $today <= $z);
        if ($inDays && $hm >= $m[3] && $hm < $m[4]) return true;
    }
    return false;
}

/* ---------- Links between pharmacies ---------- */
function linked_branch_ids(int $branchId, bool $stockSharingOnly = true): array
{
    $rows = q("SELECT IF(from_branch = ?, to_branch, from_branch) other FROM links WHERE status = 'active' AND (from_branch = ? OR to_branch = ?)" . ($stockSharingOnly ? ' AND share_stock = 1' : ''), [$branchId, $branchId, $branchId])->fetchAll();
    $same = q("SELECT b2.id FROM branches b1 JOIN branches b2 ON b2.org_id = b1.org_id AND b2.id <> b1.id WHERE b1.id = ? AND b2.status = 'active'", [$branchId])->fetchAll();
    return array_values(array_unique(array_merge(array_map(fn($r) => (int) $r['other'], $rows), array_map(fn($r) => (int) $r['id'], $same))));
}
function link_between(int $a, int $b): ?array
{
    return q("SELECT * FROM links WHERE status = 'active' AND ((from_branch = ? AND to_branch = ?) OR (from_branch = ? AND to_branch = ?))", [$a, $b, $b, $a])->fetch() ?: null;
}

/* ---------- Where is this medicine? ---------- */
/* One branch: is each item there? Never tells customers the exact count. */
function branch_has(array $b, array $items): array
{
    $out = [];
    foreach ($items as $it) {
        $name = trim((string) ($it['name'] ?? '')); $need = max(1, (int) ($it['qty'] ?? 1));
        $p = product_match((int) $b['org_id'], $name, isset($it['catalog_id']) ? (int) $it['catalog_id'] : null);
        $n = $p ? stock_available((int) $b['id'], (int) $p['id']) : 0;
        $price = $p ? q('SELECT MIN(price) FROM stock WHERE branch_id = ? AND product_id = ? AND qty > 0', [$b['id'], $p['id']])->fetchColumn() : null;
        $out[] = ['name' => $name, 'productId' => $p ? (int) $p['id'] : null, 'matched' => $p['name'] ?? null, 'schedule' => $p['schedule'] ?? schedule_guess($name),
                  'status' => $n >= $need ? ($n - $need < 3 ? 'low' : 'in') : ($n > 0 ? 'some' : 'out'), 'price' => $price !== null && $price !== false ? (float) $price : ($p['mrp'] !== null ? (float) ($p['mrp'] ?? 0) : null),
                  'cold' => (bool) ($p['cold'] ?? false), '_n' => $n];
    }
    return $out;
}

/* Customers: which pharmacies near me have these medicines? */
function availability(array $items, ?float $lat, ?float $lng, ?string $district, float $radiusKm = 25, int $max = 10): array
{
    $items = array_slice(array_values(array_filter($items, fn($i) => is_array($i) && mb_strlen(trim((string) ($i['name'] ?? ''))) >= 2)), 0, 15);
    if (!$items) fail('empty', 'Add at least one medicine.');
    $args = []; $where = "b.status = 'active' AND o.status = 'approved'";
    if ($lat !== null && $lng !== null) {   // a box around the point first (fast), exact distance after
        $dLat = $radiusKm / 111; $dLng = $radiusKm / (111 * max(0.2, cos(deg2rad($lat))));
        $where .= ' AND b.lat BETWEEN ? AND ? AND b.lng BETWEEN ? AND ?'; array_push($args, $lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng);
    } elseif ($district) { $where .= ' AND b.district = ?'; $args[] = $district; }
    else fail('no_place', 'Share your location or choose your district.');
    $res = [];
    foreach (q("SELECT b.*, o.status org_status FROM branches b JOIN orgs o ON o.id = b.org_id WHERE $where LIMIT 300", $args)->fetchAll() as $b) {
        $pub = branch_public($b, $lat, $lng);
        if ($pub['distanceKm'] !== null && $pub['distanceKm'] > $radiusKm * 1.3) continue;
        $has = branch_has($b, $items);
        $got = count(array_filter($has, fn($h) => in_array($h['status'], ['in', 'low'], true)));
        $subtotal = array_sum(array_map(fn($h, $it) => ($h['price'] ?? 0) * max(1, (int) ($it['qty'] ?? 1)), $has, $items));
        $quote = $pub['distanceKm'] !== null ? delivery_quote($b, $pub['distanceKm'], $subtotal, $has) : null;
        foreach ($has as &$h) unset($h['_n'], $h['productId']);
        $res[] = $pub + ['items' => $has, 'found' => $got, 'of' => count($items), 'quote' => $quote];   // $pub['delivery'] says whether the shop delivers at all
    }
    usort($res, fn($a, $b) => [$b['found'], $a['distanceKm'] ?? 999] <=> [$a['found'], $b['distanceKm'] ?? 999]);
    return array_slice($res, 0, $max);
}

/* Pharmacist: this medicine is missing here. Who in my network has it? */
function network_find(array $me, string $name, int $qty = 1, ?int $catalogId = null): array
{
    $out = []; $linked = linked_branch_ids((int) $me['id']);
    $near = $me['lat'] !== null ? q("SELECT b.id FROM branches b JOIN orgs o ON o.id = b.org_id WHERE b.status = 'active' AND o.status = 'approved' AND b.id <> ?
                                     AND b.lat BETWEEN ? AND ? AND b.lng BETWEEN ? AND ? LIMIT 100", [$me['id'], $me['lat'] - 0.3, $me['lat'] + 0.3, $me['lng'] - 0.3, $me['lng'] + 0.3])->fetchAll() : [];
    $ids = array_values(array_unique(array_merge($linked, array_map(fn($r) => (int) $r['id'], $near))));
    foreach ($ids as $id) {
        $b = branch($id); $isLinked = in_array($id, $linked, true);
        $h = branch_has($b, [['name' => $name, 'qty' => $qty, 'catalog_id' => $catalogId]])[0];
        if (!in_array($h['status'], ['in', 'low', 'some'], true)) continue;
        $d = ($me['lat'] !== null && $b['lat'] !== null) ? road_km(km((float) $me['lat'], (float) $me['lng'], (float) $b['lat'], (float) $b['lng']), $me['area']) : null;
        $out[] = ['branchId' => $id, 'code' => $b['code'], 'name' => $b['name'], 'village' => $b['village'], 'district' => $b['district'], 'phone' => $isLinked ? $b['phone'] : null,
                  'linked' => $isLinked, 'sameBusiness' => (int) $b['org_id'] === (int) $me['org_id'], 'distanceKm' => $d, 'status' => $h['status'],
                  'count' => $isLinked ? $h['_n'] : null, 'matched' => $h['matched'], 'price' => $h['price'], 'openNow' => open_now((string) $b['hours']),
                  'transfer' => transfer_allowed($me, $b)];
    }
    usort($out, fn($a, $b) => [!$a['linked'], $a['distanceKm'] ?? 999] <=> [!$b['linked'], $b['distanceKm'] ?? 999]);
    return $out;
}

/* ---------- Delivery fee and the "worth it" rule ---------- */
/* $roadKm: estimated road distance. $subtotal: medicine value. Returns what the customer pays and whether the shop should go.
   Inside the radius: normal fee (free above the threshold). Up to max_km: distance fee.
   Beyond max_km: allowed when it is worth it, i.e. one of:
     - the margin on the order plus the fee covers the real cost of the round trip;
     - an essential medicine (insulin, anti-epileptics, inhalers...) that is hard to get closer;
     - other deliveries are already going that way today (the trip is shared);
     - the customer agrees to pay the full trip cost.
   Otherwise the pharmacist can still override with a reason (recorded). */
function delivery_quote(array $b, float $roadKm, float $subtotal, array $items = [], int $sharedStops = 0, bool $customerPaysFull = false): array
{
    if (!$b['delivery_on']) return ['possible' => false, 'fee' => null, 'why' => ['This pharmacy does not deliver. Pick up at the counter.']];
    $radius = (float) $b['radius_km']; $maxKm = (float) $b['max_km'];
    $fee = (float) $b['fee_base'] + (float) $b['fee_per_km'] * max(0, $roadKm - 2);
    if ($roadKm <= $radius && $subtotal >= (float) $b['free_above']) $fee = 0;
    $tripCost = (float) $b['cost_per_km'] * $roadKm * 2 / max(1, $sharedStops + 1);
    $margin = $subtotal * 0.16;   // typical retail medicine margin in India is 16-20 %
    $essential = (bool) array_filter($items, fn($i) => preg_match('/' . implode('|', ESSENTIAL) . '/i', (string) (($i['matched'] ?? '') . ' ' . ($i['name'] ?? ''))) || !empty($i['cold']));
    $why = []; $possible = true; $worth = null;
    if ($roadKm <= $radius) $why[] = 'Inside the normal delivery area.';
    elseif ($roadKm <= $maxKm) $why[] = 'Outside the normal area: distance fee applies.';
    else {
        $worth = ['margin' => round($margin, 2), 'fee' => round($fee, 2), 'tripCost' => round($tripCost, 2), 'essential' => $essential, 'sharedStops' => $sharedStops, 'customerPaysFull' => $customerPaysFull];
        if ($margin + $fee >= $tripCost) $why[] = 'Long trip, but the order covers its cost.';
        elseif ($essential) $why[] = 'Long trip for an essential medicine: worth making.';
        elseif ($sharedStops > 0) $why[] = 'Other deliveries go the same way today: the trip is shared.';
        elseif ($customerPaysFull) { $fee = max($fee, ceil($tripCost - $margin)); $why[] = 'Long trip: the customer pays the full trip cost.'; }
        else { $possible = false; $why[] = 'Too far to be worth a trip (' . $roadKm . ' km). The customer can pay the full trip cost (' . money(ceil($tripCost - $margin)) . '), use a village pickup point, or the pharmacist can make an exception.'; }
    }
    return ['possible' => $possible, 'fee' => round($fee), 'roadKm' => $roadKm, 'worth' => $worth, 'fullTripFee' => (int) max(0, ceil($tripCost - $margin)), 'why' => $why];
}

/* Other open delivery orders within 3 km of this point today: a shared trip. */
function shared_stops(int $branchId, float $lat, float $lng): int
{
    $n = 0;
    foreach (q("SELECT c.lat, c.lng FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.branch_id = ? AND o.kind = 'delivery' AND o.status IN ('accepted','packed','ready')
                AND DATE(o.created_at) = CURDATE() AND c.lat IS NOT NULL", [$branchId])->fetchAll() as $r)
        if (km($lat, $lng, (float) $r['lat'], (float) $r['lng']) <= 3) $n++;
    return $n;
}
