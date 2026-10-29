<?php
/* Calls from the pharmacy network to the family app (AushadhBox), over the signed partner API.
   The family app owns the family's health data and the consent switches, so the AushadhBox card is always checked there:
   the network never stores what it is shown. */
declare(strict_types=1);
require_once __DIR__ . '/partner_sign.php';

function family_call(string $action, array $data): array
{
    $cfg = (array) config('family_app');   // ['url' => 'https://aushadhbox.in', 'key_id' => 'ph1', 'secret' => '...']
    if (empty($cfg['url']) || empty($cfg['secret'])) return ['_status' => 503, 'error' => 'not_linked', 'message' => 'The family app is not connected (set family_app in config.php).'];
    return partner_call($cfg['url'], (string) $cfg['key_id'], (string) $cfg['secret'], '/partner_api.php?action=' . $action, $data);
}

/* Who is asking: the branch and the business, as the family app shows it to the family. */
function family_branch(array $b): array
{
    return ['id' => (int) $b['id'], 'code' => $b['code'], 'name' => $b['name'], 'city' => $b['village'] ?: $b['district'], 'district' => $b['district'],
            'licence' => $b['licence_retail'], 'phone' => $b['phone'], 'approved' => ($b['org_status'] ?? '') === 'approved' && $b['status'] === 'active'];
}

/* Pass the family app's answer through, keeping its error message (e.g. "the family has not allowed pharmacies"). */
function family_or_fail(array $r): array
{
    $st = (int) ($r['_status'] ?? 500);
    if ($st >= 200 && $st < 300) { unset($r['_status']); return $r; }
    fail((string) ($r['error'] ?? 'family_app'), (string) ($r['message'] ?? 'The family app did not answer.'), $st >= 400 && $st < 600 ? $st : 502);
    return [];
}

/* Tell the customer's family (push notification in the app) about an order, a khata change or a ready refill. */
function family_notify(?string $customerRef, string $title, string $body, string $url = '/app#card', array $order = []): void
{
    if (!$customerRef) return;
    $r = family_call('notify', ['customer_ref' => $customerRef, 'title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 200), 'url' => $url, 'order' => $order]);
    if (($r['_status'] ?? 0) >= 300) error_log('family notify failed: ' . ($r['message'] ?? ''));
}
