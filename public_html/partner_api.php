<?php
/* Signed calls FROM AushadhBox Pharmacy Network (pharmacy-platform/) TO this family app.
   The network asks; this app decides, using the family's own switches on the AushadhBox card.
     card_patient   {branch, code}           → what the family allows the pharmacy to see (logged for the family)
     card_precheck  {branch, code, items}    → safety check of medicines about to be given (nothing saved)
     card_dispense  {branch, code, items}    → adds the medicines to the family's cabinet with reminders (needs "may add")
     notify         {customer_ref, title, body, url, order}  → a push notification to the household (order ready, khata, refills) */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
foreach (['catalog', 'services', 'plans', 'doses', 'safety', 'care', 'ai', 'autopilot', 'pharmacy', 'network_link'] as $f) require_once __DIR__ . "/core/$f.php";

header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('method', 'POST only.', 405);
$body = file_get_contents('php://input') ?: '';
$why = null;
$key = partner_verify((array) (config('partner_keys') ?? []), 'POST', (string) ($_SERVER['REQUEST_URI'] ?? ''), $body, $_SERVER, function (string $n): bool {
    q("DELETE FROM login_attempts WHERE email = 'nonce' AND created_at < NOW() - INTERVAL 15 MINUTE");
    if (q("SELECT 1 FROM login_attempts WHERE email = 'nonce' AND ip = ?", [$n])->fetch()) return true;
    q("INSERT INTO login_attempts (ip, email, success) VALUES (?, 'nonce', 1)", [$n]);
    return false;
}, $why);
if (!$key) { error_log('partner_api refused: ' . $why); fail('unauthorised', 'Signature check failed.', 401); }
$in = json_decode($body, true) ?: [];

switch ((string) ($_GET['action'] ?? '')) {
case 'card_patient':
    json_out(pharmacy_patient(pharmacy_mirror((array) ($in['branch'] ?? [])), (string) ($in['code'] ?? '')));
case 'card_precheck':
    json_out(pharmacy_precheck(pharmacy_mirror((array) ($in['branch'] ?? [])), (string) ($in['code'] ?? ''), (array) ($in['items'] ?? [])));
case 'card_dispense':
    $ph = pharmacy_mirror((array) ($in['branch'] ?? []));
    $r = pharmacy_dispense($ph, (string) ($in['code'] ?? ''), (array) ($in['items'] ?? []));
    $m = q('SELECT user_id, name FROM members WHERE card_code = ?', [card_normalize((string) ($in['code'] ?? ''))])->fetch();
    json_out($r + ['customer_ref' => customer_ref((int) $m['user_id']), 'name' => masked_name($m['name'])]);   // lets the pharmacy bill it to the same (pseudonymous) customer
case 'notify':
    $hid = household_by_ref((string) ($in['customer_ref'] ?? ''));
    if (!$hid) json_out(['ok' => true, 'sent' => 0]);   // unknown or deleted household: nothing to do
    $order = (array) ($in['order'] ?? []);
    if (!empty($order['number']) && !empty($order['status'])) {   // keep the family's refill list in step with the pharmacy
        $map = ['ready' => 'ready', 'collected' => 'collected', 'delivered' => 'collected', 'cancelled' => 'cancelled', 'out' => 'ready'];
        if (isset($map[$order['status']])) q('UPDATE refill_requests SET status = ? WHERE user_id = ? AND network_order = ?', [$map[$order['status']], $hid, mb_substr((string) $order['number'], 0, 16)]);
    }
    json_out(['ok' => true, 'sent' => notify_household($hid, ['title' => mb_substr((string) ($in['title'] ?? 'AushadhBox'), 0, 80), 'body' => mb_substr((string) ($in['body'] ?? ''), 0, 200),
                                                             'url' => preg_match('#^/app#', (string) ($in['url'] ?? '')) ? $in['url'] : '/app#card', 'tag' => 'network-' . substr(md5((string) ($order['number'] ?? $in['title'] ?? '')), 0, 8)])]);
default: fail('unknown_action', 'Unknown action.', 404);
}
