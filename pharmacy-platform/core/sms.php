<?php
/* SMS for villages and feature phones: order status, khata reminders, delivery codes, and ordering by SMS.

   India requires every business SMS to use a header and a template registered on the TRAI DLT portal
   (Jio, Airtel, Vi or BSNL). Register the templates below with the exact text, then put the template ids and
   your SMS provider's details in config.php ('sms'). Until then messages are only written to sms_log.

   Ordering by SMS (to the shop's virtual number at the SMS provider, which forwards to /sms.php):
       AB <shop code> <medicine> <qty>, <medicine> <qty> [DEL]
       e.g.  AB SRSA2K7Q dolo 650 2, metformin 500 1 DEL
   Missed call: a missed call to the shop's number creates a "please call back" task (provider forwards it to /sms.php?type=missed). */
declare(strict_types=1);
require_once __DIR__ . '/orders.php';

const SMS_TEMPLATES = [
    'order_new'    => 'AushadhBox: Order {#var#} received by {#var#}. Total Rs {#var#}. We will SMS when it is ready.',
    'order_ready'  => 'AushadhBox: Order {#var#} is ready at {#var#}. Please collect. Code {#var#}.',
    'order_out'    => 'AushadhBox: Order {#var#} is on the way. Give code {#var#} to the delivery person.',
    'khata_remind' => 'AushadhBox: Your credit balance at {#var#} is Rs {#var#}. Pay at the shop or by UPI {#var#}.',
    'link_code'    => 'AushadhBox: Your code to link your pharmacy account is {#var#}. Do not share it.',
    'sms_help'     => 'AushadhBox: To order send AB <shop code> <medicine> <qty>. Add DEL for delivery.',
    'sms_unknown'  => 'AushadhBox: Shop code not found. Check the code on the shop\'s QR poster.',
];

function sms_send(string $phone10, string $template, array $vars, ?int $branchId = null): string
{
    $phone10 = phone_norm($phone10);
    if (!$phone10 || !isset(SMS_TEMPLATES[$template])) return 'failed';
    $body = SMS_TEMPLATES[$template]; foreach ($vars as $v) $body = preg_replace('/\{#var#\}/', str_replace(['{', '}'], '', mb_substr((string) $v, 0, 30)), $body, 1);
    $cfg = (array) (config('sms') ?? []); $status = 'logged';
    if (!empty($cfg['url']) && !empty($cfg['templates'][$template])) {
        // Generic HTTP gateway (MSG91, Gupshup, Exotel, Textlocal... all take these four values in some form).
        $payload = ['to' => '91' . $phone10, 'sender' => $cfg['sender'] ?? 'ABOXRX', 'template_id' => $cfg['templates'][$template], 'message' => $body];
        $ch = curl_init($cfg['url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . ($cfg['auth'] ?? '')],
                                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300; curl_close($ch);
        $status = $ok ? 'sent' : 'failed';
    }
    q("INSERT INTO sms_log (direction, phone_hash, branch_id, template, body, status) VALUES ('out', ?, ?, ?, ?, ?)", [phone_hash($phone10), $branchId, $template, $body, $status]);
    return $status;
}

/* Parse "AB <code> name qty, name qty DEL". Returns null when it is not an order. */
function sms_parse(string $text): ?array
{
    $t = trim(preg_replace('/\s+/', ' ', $text));
    if (!preg_match('/^AB\s+([A-Z0-9]{8})\s+(.+)$/i', $t, $m)) return null;
    $rest = $m[2]; $kind = 'pickup';
    if (preg_match('/\s+(DEL|DELIVERY|HOME)\s*$/i', $rest)) { $kind = 'delivery'; $rest = preg_replace('/\s+(DEL|DELIVERY|HOME)\s*$/i', '', $rest); }
    $items = [];
    foreach (preg_split('/[,;\n]+/', $rest) as $part) {
        $part = trim($part); if ($part === '') continue;
        [$part, $qty] = sms_qty($part);
        $items[] = ['name' => mb_substr($part, 0, 80), 'qty' => max(1, min(99, $qty))];
    }
    return $items ? ['code' => strtoupper($m[1]), 'items' => array_slice($items, 0, 10), 'kind' => $kind] : null;
}

/* "dolo 650 2" → (dolo 650, 2); "crocin x 3" → (crocin, 3); "dolo 650" → (dolo 650, 1); "crocin 2" → (crocin, 2).
   A last number after another number is the quantity; after a word it is the quantity only if 20 or less (else a strength like 650). */
function sms_qty(string $part): array
{
    if (preg_match('/^(.+?)\s*[xX*]\s*(\d{1,3})$/', $part, $m)) return [trim($m[1]), (int) $m[2]];
    $tok = preg_split('/\s+/', trim($part));
    if (count($tok) >= 2 && preg_match('/^\d{1,3}$/', end($tok))) {
        $last = (int) array_pop($tok); $prev = end($tok);
        if (preg_match('/^\d+(mg|ml|mcg)?$/i', $prev) || $last <= 20) return [implode(' ', $tok), $last];
        $tok[] = (string) $last;
    }
    return [implode(' ', $tok), 1];
}

/* Incoming SMS from the provider. Creates the order and answers by SMS. */
function sms_incoming(string $from, string $text): array
{
    $phone = phone_norm($from);
    if (!$phone) return ['ok' => false, 'reply' => null];
    q("INSERT INTO sms_log (direction, phone_hash, body, status) VALUES ('in', ?, ?, 'received')", [phone_hash($phone), mb_substr($text, 0, 480)]);
    limit('sms:' . phone_hash($phone), 10, 60);
    $p = sms_parse($text);
    if (!$p) { sms_send($phone, 'sms_help', []); return ['ok' => false, 'reply' => 'sms_help']; }
    $b = branch_by_code($p['code']);
    if (!$b) { sms_send($phone, 'sms_unknown', []); return ['ok' => false, 'reply' => 'sms_unknown']; }
    $cid = customer_upsert(['phone' => $phone, 'name' => 'SMS customer', 'sms_ok' => 1]);
    // Delivery by SMS needs a saved location: the shop calls back to confirm the address, so SMS orders start as pickup with a note.
    $o = order_create($b, $cid, ['items' => $p['items'], 'kind' => 'pickup', 'source' => 'sms', 'note' => $p['kind'] === 'delivery' ? 'Asked for delivery by SMS: call to confirm the address.' : null]);
    sms_send($phone, 'order_new', [$o['number'], $b['name'], number_format($o['total'], 0)], (int) $b['id']);
    return ['ok' => true, 'order' => $o['number']];
}

/* Missed call to the shop's number: a call-back task on the shop's dashboard (no order is made). */
function missed_call(string $from, string $shopCode): array
{
    $phone = phone_norm($from); $b = branch_by_code($shopCode);
    if (!$phone || !$b) return ['ok' => false];
    limit('missed:' . phone_hash($phone), 5, 60);
    $cid = customer_upsert(['phone' => $phone, 'name' => 'Missed call']);
    q("INSERT INTO insights (branch_id, kind, title, data) VALUES (?, 'callback', ?, ?)", [$b['id'], 'Call back ' . phone_mask($phone) . ' (missed call ' . date('H:i') . ')', json_encode(['customerId' => $cid])]);
    return ['ok' => true];
}
