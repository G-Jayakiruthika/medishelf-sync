<?php
/* Signed server-to-server calls between AushadhBox (family app) and AushadhBox Pharmacy Network.
   The SAME file is used by both projects (copy kept in public_html/core/partner_sign.php).

   Every request carries:
     X-AB-Key    key id (lets you rotate secrets: old and new keys can work side by side)
     X-AB-Time   unix seconds; refused if more than 5 minutes away from the server clock
     X-AB-Nonce  32 random hex characters; each one is accepted once (replay protection)
     X-AB-Sig    hex HMAC-SHA256 over  METHOD \n PATH?QUERY \n TIME \n NONCE \n SHA256(BODY)
   Only HTTPS should be used in production. Secrets live in each project's config.php ('partner_keys'). */
declare(strict_types=1);

function partner_signature(string $secret, string $method, string $pathQuery, int $time, string $nonce, string $body): string
{
    return hash_hmac('sha256', strtoupper($method) . "\n" . $pathQuery . "\n" . $time . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
}

/* Call the other side. $base like https://pharmacy.example.in, $path like /partner.php?action=availability */
function partner_call(string $base, string $keyId, string $secret, string $path, array $data, int $timeout = 8): array
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $time = time(); $nonce = bin2hex(random_bytes(16));
    $headers = ['Content-Type: application/json', 'X-AB-Key: ' . $keyId, 'X-AB-Time: ' . $time, 'X-AB-Nonce: ' . $nonce,
                'X-AB-Sig: ' . partner_signature($secret, 'POST', $path, $time, $nonce, $body)];
    $ch = curl_init(rtrim($base, '/') . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false]);
    $raw = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) return ['_status' => 0, 'error' => 'unreachable', 'message' => 'The other service could not be reached (' . $err . ').'];
    $out = json_decode((string) $raw, true);
    if (!is_array($out)) $out = ['error' => 'bad_response', 'message' => 'Unexpected answer from the other service.'];
    $out['_status'] = $status;
    return $out;
}

/* Check an incoming signed request. $keys = ['keyId' => 'secret', ...]. $nonceSeen($nonce) must return true if
   the nonce was already used and otherwise remember it. Returns the key id, or null with a reason. */
function partner_verify(array $keys, string $method, string $pathQuery, string $body, array $server, callable $nonceSeen, ?string &$why = null): ?string
{
    $key = (string) ($server['HTTP_X_AB_KEY'] ?? ''); $time = (int) ($server['HTTP_X_AB_TIME'] ?? 0);
    $nonce = (string) ($server['HTTP_X_AB_NONCE'] ?? ''); $sig = (string) ($server['HTTP_X_AB_SIG'] ?? '');
    if (!isset($keys[$key]) || $keys[$key] === '' || strlen((string) $keys[$key]) < 32) { $why = 'unknown key'; return null; }
    if (abs(time() - $time) > 300) { $why = 'clock'; return null; }
    if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) { $why = 'nonce'; return null; }
    if (!hash_equals(partner_signature((string) $keys[$key], $method, $pathQuery, $time, $nonce, $body), $sig)) { $why = 'signature'; return null; }
    if ($nonceSeen($nonce)) { $why = 'replay'; return null; }
    return $key;
}
