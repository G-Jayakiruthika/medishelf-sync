<?php
/* How the network earns, and what it owes shops.

   Commission: only on orders the network brought (from the AushadhBox family app, or passed on by a linked pharmacy),
   never on the shop's own walk-in, phone or SMS customers. A small percentage of the medicines' value (not the delivery
   fee), lower in semi-urban and rural areas, capped per order, and zero for Jan Aushadhi Kendras and shops marked
   fee-exempt. 18% GST is added on the commission (the platform's service), as on any Indian marketplace fee.

   Vouchers: AushadhBox health points (earned by families for looking after their health) are spent at a shop; the
   platform pays the shop that amount back. Commission and vouchers net off in one monthly statement. */
declare(strict_types=1);

const FEE_DEFAULTS = ['urban' => 2.0, 'semiurban' => 1.5, 'rural' => 1.0, 'cap' => 50.0, 'gst' => 18.0, 'sources' => ['app', 'refill']];

function fee_cfg(): array { return array_merge(FEE_DEFAULTS, (array) (config('fees') ?? [])); }

/* Commission for one finished order, or null when none is due. */
function fee_for_order(array $o, array $b): ?array
{
    $c = fee_cfg();
    $network = in_array($o['source'], $c['sources'], true) || !empty($o['asked_branch_id']);
    if (!$network || $b['kind'] === 'janaushadhi' || !empty($b['fee_exempt'])) return null;
    $base = round(max(0.0, (float) $o['paid'] - (float) $o['delivery_fee'] - (float) $o['discount']), 2);   // the shop's own points are a discount, not income
    if ($base <= 0) return null;
    $rate = (float) ($c[$b['area']] ?? $c['urban']);
    $amount = round(min($c['cap'], $base * $rate / 100), 2);
    return ['base' => $base, 'rate' => $rate, 'amount' => $amount, 'gst' => round($amount * $c['gst'] / 100, 2)];
}

/* Called when an order is delivered or collected. Once per order (unique key). */
function fee_record_order(array $o): void
{
    $b = branch((int) $o['branch_id']);
    $f = fee_for_order($o, $b);
    if (!$f) return;
    // A payment can arrive after delivery (khata, UPI later): the fee follows what was finally paid.
    q("INSERT INTO fees (org_id, branch_id, kind, order_id, base, rate, amount, gst, period, note) VALUES (?,?, 'commission', ?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE base = VALUES(base), rate = VALUES(rate), amount = VALUES(amount), gst = VALUES(gst)",
      [$b['org_id'], $b['id'], $o['id'], $f['base'], $f['rate'], $f['amount'], $f['gst'], date('Y-m'), $o['number']]);
}

/* A health-points voucher accepted at the counter: the platform owes the shop this amount. */
function fee_record_voucher(array $b, float $rupees, string $code, ?int $orderId): void
{
    q("INSERT INTO fees (org_id, branch_id, kind, order_id, amount, period, ref, note) VALUES (?,?, 'voucher', NULL, ?,?,?,?)",
      [$b['org_id'], $b['id'], -round($rupees, 2), date('Y-m'), $code, $orderId ? 'order ' . $orderId : null]);
}

/* Monthly statement for a business. net > 0: the shop pays the platform; net < 0: the platform pays the shop. */
function fee_statement(int $orgId, string $period): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) fail('bad_period', 'Choose a month.');
    $rows = q('SELECT f.*, b.name branch FROM fees f LEFT JOIN branches b ON b.id = f.branch_id WHERE f.org_id = ? AND f.period = ? ORDER BY f.id', [$orgId, $period])->fetchAll();
    $sum = ['commission' => 0.0, 'gst' => 0.0, 'voucher' => 0.0, 'settlement' => 0.0, 'adjustment' => 0.0, 'orders' => 0];
    foreach ($rows as $r) {
        $sum[$r['kind']] = round(($sum[$r['kind']] ?? 0) + (float) $r['amount'], 2);
        $sum['gst'] = round($sum['gst'] + (float) $r['gst'], 2);
        if ($r['kind'] === 'commission') $sum['orders']++;
    }
    $net = round($sum['commission'] + $sum['gst'] + $sum['voucher'] + $sum['adjustment'] + $sum['settlement'], 2);
    $org = q('SELECT name, gstin FROM orgs WHERE id = ?', [$orgId])->fetch() ?: ['name' => '', 'gstin' => null];
    $upi = (string) (config('platform_upi') ?? '');
    return ['period' => $period, 'org' => $org['name'], 'gstin' => $org['gstin'] ?? null, 'lines' => array_map(fn($r) => ['kind' => $r['kind'], 'branch' => $r['branch'], 'order' => $r['note'],
                'base' => $r['base'] !== null ? (float) $r['base'] : null, 'rate' => $r['rate'] !== null ? (float) $r['rate'] : null, 'amount' => (float) $r['amount'], 'gst' => (float) $r['gst'],
                'ref' => $r['ref'], 'at' => $r['created_at']], $rows),
            'totals' => $sum, 'net' => $net, 'invoice' => 'ABN-' . str_replace('-', '', $period) . '-' . str_pad((string) $orgId, 5, '0', STR_PAD_LEFT),
            'payTo' => $net > 0 && preg_match('/^[\w.\-]{2,256}@[a-zA-Z][a-zA-Z0-9.\-]{1,64}$/', $upi)
                ? 'upi://pay?' . http_build_query(['pa' => $upi, 'pn' => 'AushadhBox Network', 'am' => number_format($net, 2, '.', ''), 'cu' => 'INR', 'tn' => 'Fees ' . $period], '', '&', PHP_QUERY_RFC3986) : null];
}

/* The platform records a settlement (the shop paid, or the platform paid the shop): it brings the month's net to zero. */
function fee_settle(int $orgId, string $period, float $amount, string $utr, int $adminId): void
{
    $st = fee_statement($orgId, $period);
    if (abs($amount) < 0.01 || abs($amount) > abs($st['net']) + 0.01) fail('bad_amount', 'The amount must not be more than the balance (' . money(abs($st['net'])) . ').');
    $sign = $st['net'] > 0 ? -1 : 1;   // a shop's payment reduces what it owes
    q("INSERT INTO fees (org_id, kind, amount, period, ref, note) VALUES (?, 'settlement', ?, ?, ?, ?)", [$orgId, $sign * round(abs($amount), 2), $period, mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $utr), 0, 40), 'by admin ' . $adminId]);
}
