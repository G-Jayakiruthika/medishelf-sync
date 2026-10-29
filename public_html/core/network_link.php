<?php
/* Link to AushadhBox Pharmacy Network (the separate pharmacy platform in pharmacy-platform/).
   The family app owns families' health data and the card consent switches; the network owns pharmacies, stock,
   orders, payments, khata and delivery. They talk only over signed server-to-server calls (core/partner_sign.php).

   config.php:
     'pharmacy_network' => ['url' => 'https://pharmacy.example.in', 'key_id' => 'fam1', 'secret' => '...'],   // we call them
     'partner_keys'     => ['ph1' => '...'],                                                                    // they call us
   The network knows a household only by customer_ref, an HMAC of the household id: no e-mail, no user id. */
declare(strict_types=1);
require_once __DIR__ . '/partner_sign.php';

function network_cfg(): array { return (array) (config('pharmacy_network') ?? []); }
function network_on(): bool { $c = network_cfg(); return !empty($c['url']) && !empty($c['secret']); }
function network_url(): string { return rtrim((string) (network_cfg()['url'] ?? ''), '/'); }

function network_call(string $action, array $data): array
{
    if (!network_on()) return ['_status' => 503, 'error' => 'network_off', 'message' => 'The pharmacy network is not connected on this site yet.'];
    $c = network_cfg();
    return partner_call((string) $c['url'], (string) $c['key_id'], (string) $c['secret'], '/partner.php?action=' . $action, $data);
}

/* Answer the app with the network's reply, keeping its message (e.g. "Home delivery is allowed only inside the shop's district"). */
function network_or_fail(array $r): array
{
    $st = (int) ($r['_status'] ?? 500);
    if ($st >= 200 && $st < 300) { unset($r['_status']); return $r; }
    fail((string) ($r['error'] ?? 'network'), (string) ($r['message'] ?? 'The pharmacy network did not answer. Try again.'), $st >= 400 && $st < 600 ? $st : 502);
    return [];
}

/* Pseudonymous id for a household, stable, kept on the household owner's row so incoming calls can find it. */
function customer_ref(int $hid): string
{
    $secret = (string) (network_cfg()['secret'] ?? config('cron_key') ?? 'aushadhbox');
    $ref = substr(hash_hmac('sha256', 'household:' . $hid, $secret), 0, 32);
    q('UPDATE users SET network_ref = ? WHERE id = ? AND (network_ref IS NULL OR network_ref <> ?)', [$ref, $hid, $ref]);
    return $ref;
}
function household_by_ref(string $ref): ?int
{
    if (!preg_match('/^[a-f0-9]{32}$/', $ref)) return null;
    $id = q('SELECT id FROM users WHERE network_ref = ?', [$ref])->fetchColumn();
    return $id ? (int) $id : null;
}

/* A network pharmacy as this app records it (for the card's access log and blocking). Approval comes from the network;
   the site admin here can still suspend one locally (a kill switch). */
function pharmacy_mirror(array $b): array
{
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0 || empty($b['name'])) fail('bad_branch', 'Unknown pharmacy.', 400);
    q("INSERT INTO pharmacies (network_branch_id, code, name, license_no, city, district, phone, email, password_hash, status) VALUES (?,?,?,?,?,?,?,?, '!', ?)
       ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name), license_no = VALUES(license_no), city = VALUES(city), district = VALUES(district), phone = VALUES(phone),
       status = IF(status = 'suspended' AND local_block = 1, 'suspended', VALUES(status))",
      [$id, mb_substr((string) ($b['code'] ?? ''), 0, 8) ?: null, mb_substr((string) $b['name'], 0, 100), mb_substr((string) ($b['licence'] ?? ''), 0, 40), mb_substr((string) ($b['city'] ?? ''), 0, 60),
       mb_substr((string) ($b['district'] ?? ''), 0, 60) ?: null, preg_replace('/\D/', '', (string) ($b['phone'] ?? '')) ?: '', 'branch-' . $id . '@network.local', !empty($b['approved']) ? 'approved' : 'suspended']);
    $ph = q('SELECT * FROM pharmacies WHERE network_branch_id = ?', [$id])->fetch();
    if ($ph['status'] !== 'approved') fail('suspended', 'This pharmacy cannot use AushadhBox cards right now.', 403);
    return $ph;
}
