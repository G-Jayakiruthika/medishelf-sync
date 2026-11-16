<?php
/* Razorpay webhook. In the Razorpay Dashboard → Settings → Webhooks, add:
     URL:    https://YOUR-DOMAIN/webhook.php
     Secret: the same value as razorpay_webhook_secret in core/config.php
     Events: payment.captured, order.paid, payment.failed
   This makes Premium start even if the customer closes the browser right after paying. */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/services.php';
require __DIR__ . '/core/plans.php';
header('Content-Type: application/json');

$secret = (string) config('razorpay_webhook_secret');
$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
    http_response_code(400);
    exit('{"ok":false}');
}
$ev = json_decode($raw, true) ?: [];
$pay = $ev['payload']['payment']['entity'] ?? [];
$order = $pay['order_id'] ?? ($ev['payload']['order']['entity']['id'] ?? '');
switch ($ev['event'] ?? '') {
    case 'payment.captured':
    case 'order.paid':
        if ($order && !empty($pay['id'])) {
            // Check the amount matches what we asked for before activating.
            $p = q('SELECT amount_paise FROM payments WHERE rp_order_id = ?', [$order])->fetch();
            if ($p && (int) $p['amount_paise'] === (int) ($pay['amount'] ?? 0)) mark_paid($order, (string) $pay['id']);
        }
        break;
    case 'payment.failed':
        if ($order) q("UPDATE payments SET status = 'failed' WHERE rp_order_id = ? AND status = 'created'", [$order]);
        break;
}
echo '{"ok":true}';
