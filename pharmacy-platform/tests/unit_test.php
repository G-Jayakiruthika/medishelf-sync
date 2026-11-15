<?php
/* Unit tests for the network's money rules and the database upgrade. Runs inside a transaction that is rolled back.
   php tests/unit_test.php */
declare(strict_types=1);
chdir(__DIR__ . '/..');
const FAIL_THROWS = true;
require 'core/bootstrap.php';
require_once 'core/migrate.php';
foreach (['compliance', 'stock', 'network', 'orders', 'delivery', 'fees', 'analytics'] as $f) require_once "core/$f.php";

$pass = 0; $fail = 0;
function eq($got, $want, string $msg): void { global $pass, $fail; if ($got === $want) { $pass++; echo "  ✓ $msg\n"; } else { $fail++; echo "  ✗ $msg\n      got  " . json_encode($got) . "\n      want " . json_encode($want) . "\n"; } }
function ok(bool $c, string $msg): void { eq($c, true, $msg); }

echo "Commission rules\n";
$urban = ['kind' => 'retail', 'area' => 'urban', 'fee_exempt' => 0];
$o = ['source' => 'app', 'asked_branch_id' => null, 'paid' => 540.0, 'delivery_fee' => 40.0, 'discount' => 0.0];
eq(fee_for_order($o, $urban), ['base' => 500.0, 'rate' => 2.0, 'amount' => 10.0, 'gst' => 1.8], 'app order in a city: 2% of medicines (not the delivery fee) + 18% GST');
eq(fee_for_order(['source' => 'walkin'] + $o, $urban), null, 'walk-in customers are the shop\'s own: no commission');
eq(fee_for_order(['source' => 'sms'] + $o, $urban), null, 'SMS orders to the shop\'s own number: no commission');
ok(fee_for_order(['source' => 'walkin', 'asked_branch_id' => 7] + $o, $urban) !== null, 'an order passed on by a linked pharmacy pays commission');
eq(fee_for_order($o, ['area' => 'rural'] + $urban)['rate'], 1.0, 'villages pay 1%');
eq(fee_for_order($o, ['area' => 'semiurban'] + $urban)['rate'], 1.5, 'towns pay 1.5%');
eq(fee_for_order(['paid' => 9040.0] + $o, $urban)['amount'], 50.0, 'capped at ₹50 an order');
eq(fee_for_order($o, ['kind' => 'janaushadhi'] + $urban), null, 'Jan Aushadhi Kendras pay nothing');
eq(fee_for_order($o, ['fee_exempt' => 1] + $urban), null, 'fee-exempt shops pay nothing');
eq(fee_for_order(['discount' => 100.0] + $o, $urban)['base'], 400.0, 'the shop\'s own loyalty points are a discount, not income');
eq(fee_for_order(['paid' => 0.0] + $o, $urban), null, 'unpaid orders: nothing yet');
eq(fee_for_order(['source' => 'asha'] + $o, $urban), null, 'orders placed by ASHA workers for families: no commission');

// A fresh database built from schema.sql must already have everything; running the upgrade must change nothing.
$first = ph_migrate();
db()->beginTransaction();
try {
    echo "Statements and settlement\n";
    q("INSERT INTO orgs (name, status) VALUES ('Unit Test Pharma', 'approved')");
    $org = (int) db()->lastInsertId();
    $p = '2031-01';
    q("INSERT INTO fees (org_id, kind, order_id, base, rate, amount, gst, period) VALUES (?, 'commission', 900001, 500, 2, 10, 1.8, ?), (?, 'commission', 900002, 1000, 2, 20, 3.6, ?)", [$org, $p, $org, $p]);
    q("INSERT INTO fees (org_id, kind, amount, period, ref) VALUES (?, 'voucher', -10, ?, 'ABCDEFGH')", [$org, $p]);
    $st = fee_statement($org, $p);
    eq([$st['totals']['commission'], $st['totals']['gst'], $st['totals']['voucher'], $st['totals']['orders'], $st['net']], [30.0, 5.4, -10.0, 2, 25.4], 'statement: commission ₹30 + GST ₹5.40 − voucher ₹10 = ₹25.40 to pay');
    ok(str_starts_with($st['invoice'], 'ABN-203101-'), 'statement has an invoice number');
    eq(refused(fn() => fee_settle($org, $p, 100, 'UTR1', 1)), 'bad_amount', 'cannot settle more than the balance');
    fee_settle($org, $p, 25.4, '123456789012', 1);
    eq(fee_statement($org, $p)['net'], 0.0, 'after the shop pays, the month is settled');
    $p2 = '2031-02';
    q("INSERT INTO fees (org_id, kind, amount, period, ref) VALUES (?, 'voucher', -50, ?, 'VOUCHER2')", [$org, $p2]);
    eq(fee_statement($org, $p2)['net'], -50.0, 'only vouchers: the platform owes the shop');
    fee_settle($org, $p2, 50, '998877665544', 1);
    eq(fee_statement($org, $p2)['net'], 0.0, 'the platform\'s payout settles it');
    eq(refused(fn() => fee_statement($org, '2031-13x')), 'bad_period', 'bad month refused');

    echo "Database upgrade\n";
    eq($first, [], 'schema.sql already has every column the upgrader knows about');
    eq(ph_migrate(), [], 'upgrade is idempotent (nothing left to do)');
    ok(ph_has_table('fees') && !ph_has_table('no_such_table'), 'table check');
} finally {
    db()->rollBack();
}

function refused(callable $f): string { try { $f(); return 'allowed'; } catch (RuntimeException $e) { return $e->getMessage(); } }

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
