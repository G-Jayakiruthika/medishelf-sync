<?php
/* Test helper: makes a signed partner call like AushadhBox Pharmacy Network does.
   php tests/partner_call.php BASE_URL ACTION 'JSON' [--replay] [--bad-sig]
   Uses the first key in public_html/core/config.php 'partner_keys' (test installs only). Prints "STATUS BODY". */
require __DIR__ . '/../public_html/core/partner_sign.php';
$cfg = require __DIR__ . '/../public_html/core/config.php';
[$base, $action, $json] = [$argv[1], $argv[2], $argv[3] ?? '{}'];
$keys = (array) ($cfg['partner_keys'] ?? []); $keyId = (string) array_key_first($keys); $secret = (string) ($keys[$keyId] ?? '');
$path = '/partner_api.php?action=' . $action; $time = time(); $nonce = bin2hex(random_bytes(16));
$sig = partner_signature(in_array('--bad-sig', $argv, true) ? str_repeat('0', 64) : $secret, 'POST', $path, $time, $nonce, $json);
$send = function () use ($base, $path, $json, $keyId, $time, $nonce, $sig) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-AB-Key: $keyId", "X-AB-Time: $time", "X-AB-Nonce: $nonce", "X-AB-Sig: $sig"]]);
    $body = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$st, $body];
};
[$st, $body] = $send();
if (in_array('--replay', $argv, true)) [$st, $body] = $send();
echo $st . ' ' . $body;
