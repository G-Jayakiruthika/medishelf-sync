<?php
/* Plans, family sharing (households) and Razorpay payments.
   A "household" is the owner's account id. Members who join through an invite have users.household_id = owner id,
   and every cabinet query uses hid($user) so the whole family sees one cabinet. */
declare(strict_types=1);

const FREE_LIMITS = ['members' => 4, 'items' => 30, 'accounts' => 1];
const PREMIUM_LIMITS = ['members' => 15, 'items' => 2000, 'accounts' => 6];   // 2000 = "unlimited (fair use)"

function hid(array $u): int { return (int) ($u['household_id'] ?: $u['id']); }

function is_owner(array $u): bool { return empty($u['household_id']); }

function household_owner(array $u): array
{
    if (is_owner($u)) return $u;
    $o = q('SELECT id, name, email, plan, plan_until FROM users WHERE id = ?', [$u['household_id']])->fetch();
    return $o ?: $u;
}

/* Premium is a property of the household owner; members share it. */
function plan_info(array $u): array
{
    $o = household_owner($u);
    $active = $o['plan'] === 'premium' && $o['plan_until'] !== null && $o['plan_until'] >= date('Y-m-d');
    return ['plan' => $active ? 'premium' : 'free', 'until' => $active ? $o['plan_until'] : null,
            'expired_on' => (!$active && $o['plan_until']) ? $o['plan_until'] : null,
            'limits' => $active ? PREMIUM_LIMITS : FREE_LIMITS, 'owner' => is_owner($u)];
}

function is_premium(array $u): bool { return plan_info($u)['plan'] === 'premium'; }

function plan_usage(array $u): array
{
    $h = hid($u);
    return [
        'members' => (int) q('SELECT COUNT(*) FROM members WHERE user_id = ?', [$h])->fetchColumn(),
        'items' => (int) q('SELECT COUNT(*) FROM cabinet_items WHERE user_id = ?', [$h])->fetchColumn(),
        'accounts' => 1 + (int) q('SELECT COUNT(*) FROM users WHERE household_id = ?', [$h])->fetchColumn(),
    ];
}

function household_accounts(array $u): array
{
    $h = hid($u);
    $rows = q('SELECT id, name, email, household_id FROM users WHERE id = ? OR household_id = ? ORDER BY household_id IS NOT NULL, id', [$h, $h])->fetchAll();
    return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => mask_email($r['email']), 'owner' => $r['household_id'] === null, 'you' => (int) $r['id'] === (int) $u['id']], $rows);
}

function mask_email(string $e): string
{
    [$a, $d] = array_pad(explode('@', $e, 2), 2, '');
    return mb_substr($a, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($a) - 2))) . '@' . $d;
}

/* Add one month or one year to the owner's Premium, starting from today or from the current end date, whichever is later. */
function extend_plan(int $ownerId, string $period): string
{
    $interval = $period === 'year' ? 'INTERVAL 1 YEAR' : 'INTERVAL 1 MONTH';
    q("UPDATE users SET plan = 'premium', plan_until = GREATEST(COALESCE(plan_until, CURDATE() - INTERVAL 1 DAY), CURDATE() - INTERVAL 1 DAY) + $interval WHERE id = ?", [$ownerId]);
    return (string) q('SELECT plan_until FROM users WHERE id = ?', [$ownerId])->fetchColumn();
}

/* Idempotent: the browser callback and the Razorpay webhook may both arrive. Returns true if this call activated it. */
function mark_paid(string $orderId, string $paymentId): bool
{
    db()->beginTransaction();
    $p = q("SELECT id, user_id, period, status FROM payments WHERE rp_order_id = ? FOR UPDATE", [$orderId])->fetch();
    if (!$p || $p['status'] === 'paid') { db()->commit(); return false; }
    q("UPDATE payments SET status = 'paid', rp_payment_id = ?, paid_at = NOW() WHERE id = ?", [$paymentId, $p['id']]);
    $until = $p['user_id'] ? extend_plan((int) $p['user_id'], $p['period']) : null;
    db()->commit();
    if ($p['user_id'] && function_exists('send_mail') && mail_enabled()) {
        $u = q('SELECT name, email FROM users WHERE id = ?', [$p['user_id']])->fetch();
        $amt = (int) q('SELECT amount_paise FROM payments WHERE id = ?', [$p['id']])->fetchColumn() / 100;
        if ($u) send_mail($u['email'], 'Payment received: ' . site_title() . ' Family Premium',
            "Hello {$u['name']},\n\nThank you. We received ₹" . number_format($amt, 2) . " for Family Premium (1 {$p['period']}).\nPremium is active until $until.\n\nPayment ID: $paymentId\nOrder ID: $orderId\n\nThis e-mail is your receipt. Need a GST invoice or a refund? Just reply.\n\n" . site_title());
    }
    return true;
}

function site_title(): string { return (string) (config('site_name') ?: 'AushadhBox'); }

function razorpay_live(): bool { return (string) config('razorpay_key_id') !== '' && (string) config('razorpay_key_secret') !== ''; }

function razorpay_create_order(int $amountPaise, string $receipt, array $notes): array
{
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => config('razorpay_key_id') . ':' . config('razorpay_key_secret'),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['amount' => $amountPaise, 'currency' => 'INR', 'receipt' => $receipt, 'notes' => $notes]),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($code !== 200 || empty($data['id'])) {
        error_log('Razorpay order failed: HTTP ' . $code . ' ' . substr((string) $body, 0, 300));
        throw new RuntimeException('payment_gateway');
    }
    return $data;
}

function razorpay_signature_ok(string $orderId, string $paymentId, string $signature): bool
{
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, (string) config('razorpay_key_secret'));
    return hash_equals($expected, $signature);
}
