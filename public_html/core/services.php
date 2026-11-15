<?php
/* Optional services: SMTP e-mail, Web Push reminders, Bhashini text-to-speech.
   Each one switches itself off when its settings in config.php are empty. */
declare(strict_types=1);

/* ======================= E-mail (SMTP) ======================= */

function mail_enabled(): bool
{
    return (string) config('smtp_host') !== '' && (string) config('mail_from') !== '';
}

/* $unsub = [user id, kind] for optional e-mails (weekly, alerts, news): adds a one-click unsubscribe link and the
   List-Unsubscribe headers (RFC 8058) that Gmail and other mail apps show as an "Unsubscribe" button. */
function send_mail(string $to, string $subject, string $text, ?array $unsub = null): bool
{
    if (!mail_enabled()) return false;
    if ($unsub) $text .= "\n\n--\nYou get this because of a setting in your account. Stop these e-mails with one click: " . unsub_link((int) $unsub[0], (string) $unsub[1]);
    $host = config('smtp_host'); $port = (int) config('smtp_port');
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) { error_log("SMTP connect failed: $errstr"); return false; }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $out = ''; while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; } return $out; };
    $cmd = function (string $c, array $ok) use ($fp, $read) { fwrite($fp, $c . "\r\n"); $r = $read(); if (!in_array((int) substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($r)); return $r; };
    try {
        $read();
        $cmd('EHLO aushadhbox', [250]);
        if ($port !== 465) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('TLS failed');
            $cmd('EHLO aushadhbox', [250]);
        }
        if ((string) config('smtp_user') !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) config('smtp_user')), [334]);
            $cmd(base64_encode((string) config('smtp_pass')), [235]);
        }
        $from = (string) config('mail_from');
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $headers = [
            'From: =?UTF-8?B?' . base64_encode((string) config('mail_from_name')) . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url((string) config('app_url'), PHP_URL_HOST) ?: 'aushadhbox') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($unsub) { $headers[] = 'List-Unsubscribe: <' . unsub_link((int) $unsub[0], (string) $unsub[1]) . '>'; $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'; }
        $body = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text));
        $cmd($body . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        @fclose($fp);
        return false;
    }
}

/* ======================= Web Push (RFC 8291 + RFC 8292 VAPID) ======================= */

function b64u_enc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function push_enabled(): bool
{
    return (string) config('vapid_public') !== '' && (string) config('vapid_private') !== '';
}

/* Raw 65-byte P-256 point → OpenSSL public key */
function p256_public_key(string $raw)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

function p256_raw_public($key): string
{
    $d = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

function hkdf_sha256(string $salt, string $ikm, string $info, int $len): string
{
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
}

/* Encrypt a payload for one subscription (aes128gcm content coding). */
function push_encrypt(string $payload, string $uaPublicB64, string $authB64): string
{
    $uaPublic = b64u_dec($uaPublicB64);
    $authSecret = b64u_dec($authB64);
    $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $asPublic = p256_raw_public($local);
    $shared = openssl_pkey_derive(p256_public_key($uaPublic), $local, 32);
    if ($shared === false) throw new RuntimeException('ECDH failed');
    $ikm = hkdf_sha256($authSecret, $shared, "WebPush: info\0" . $uaPublic . $asPublic, 32);
    $salt = random_bytes(16);
    $cek = hkdf_sha256($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
    $nonce = hkdf_sha256($salt, $ikm, "Content-Encoding: nonce\0", 12);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

function vapid_jwt(string $audience): string
{
    $header = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u_enc(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => (string) config('vapid_subject')]));
    $data = $header . '.' . $claims;
    openssl_sign($data, $der, openssl_pkey_get_private((string) config('vapid_private')), OPENSSL_ALGO_SHA256);
    // DER ECDSA signature → raw 64-byte r||s
    $off = 3; $rlen = ord($der[$off]); $r = substr($der, $off + 1, $rlen);
    $off = $off + 1 + $rlen + 1; $slen = ord($der[$off]); $s = substr($der, $off + 1, $slen);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT); $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $data . '.' . b64u_enc($r . $s);
}

/* Returns HTTP status from the push service (201 = delivered; 404/410 = subscription gone). */
function push_send(array $sub, array $message): int
{
    $body = push_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE), $sub['p256dh'], $sub['auth']);
    $u = parse_url($sub['endpoint']);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 3600', 'Urgency: high',
            'Authorization: vapid t=' . vapid_jwt($aud) . ', k=' . config('vapid_public'),
        ],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}

/* ======================= Bhashini text-to-speech ======================= */

function bhashini_enabled(): bool
{
    return (string) config('bhashini_user_id') !== '' && (string) config('bhashini_api_key') !== '';
}

function http_json(string $url, array $headers, array $body): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($res === false || $code >= 300) { error_log("Bhashini HTTP $code: " . substr((string) $res, 0, 300)); return null; }
    return json_decode((string) $res, true);
}

/* Returns WAV bytes, or null. Results are cached on disk so repeated phrases cost nothing. */
function bhashini_tts(string $text, string $lang): ?string
{
    if (!bhashini_enabled()) return null;
    $dir = APP_DIR . '/cache/tts';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . hash('sha256', $lang . '|' . $text) . '.wav';
    if (is_file($file)) return (string) file_get_contents($file);

    // Step 1: ask Bhashini which model serves this language (cached for a day).
    $cfgFile = $dir . '/pipeline-' . $lang . '.json';
    $pipe = is_file($cfgFile) && filemtime($cfgFile) > time() - 86400 ? json_decode((string) file_get_contents($cfgFile), true) : null;
    if (!$pipe) {
        $r = http_json('https://meity-auth.ulcacontrib.org/ulca/apis/v0/model/getModelsPipeline',
            ['userID: ' . config('bhashini_user_id'), 'ulcaApiKey: ' . config('bhashini_api_key')],
            ['pipelineTasks' => [['taskType' => 'tts', 'config' => ['language' => ['sourceLanguage' => $lang]]]],
             'pipelineRequestConfig' => ['pipelineId' => '64392f96daac500b55c543cd']]);
        if (!$r) return null;
        $pipe = [
            'url' => $r['pipelineInferenceAPIEndPoint']['callbackUrl'] ?? null,
            'keyName' => $r['pipelineInferenceAPIEndPoint']['inferenceApiKey']['name'] ?? 'Authorization',
            'keyValue' => $r['pipelineInferenceAPIEndPoint']['inferenceApiKey']['value'] ?? '',
            'serviceId' => $r['pipelineResponseConfig'][0]['config'][0]['serviceId'] ?? null,
        ];
        if (!$pipe['url'] || !$pipe['serviceId']) return null;
        file_put_contents($cfgFile, json_encode($pipe));
    }
    // Step 2: run text-to-speech.
    $r = http_json($pipe['url'], [$pipe['keyName'] . ': ' . $pipe['keyValue']], [
        'pipelineTasks' => [['taskType' => 'tts', 'config' => ['language' => ['sourceLanguage' => $lang], 'serviceId' => $pipe['serviceId'], 'gender' => 'female', 'samplingRate' => 22050]]],
        'inputData' => ['input' => [['source' => $text]]],
    ]);
    $b64 = $r['pipelineResponse'][0]['audio'][0]['audioContent'] ?? null;
    if (!$b64) return null;
    $wav = base64_decode($b64);
    file_put_contents($file, $wav);
    return $wav;
}

/* Unsubscribe links carry a keyed signature, so nobody can unsubscribe someone else by changing the number. */
const UNSUB_KINDS = ['weekly' => 'the weekly expiry e-mail', 'alerts' => 'automatic alerts by e-mail', 'news' => 'product news and tips'];
function unsub_sig(int $uid, string $kind): string { return substr(hash_hmac('sha256', "unsub:$uid:$kind", fam_data_key()), 0, 32); }
function unsub_link(int $uid, string $kind): string
{
    return rtrim((string) (config('app_url') ?: config('base_url') ?: ''), '/') . '/unsubscribe?u=' . $uid . '&k=' . rawurlencode($kind) . '&t=' . unsub_sig($uid, $kind);
}
function unsubscribe(int $uid, string $kind, string $sig): bool
{
    if (!isset(UNSUB_KINDS[$kind]) || !hash_equals(unsub_sig($uid, $kind), $sig)) return false;
    if ($kind === 'weekly') q('UPDATE users SET weekly_email = 0 WHERE id = ?', [$uid]);
    if ($kind === 'news') q('UPDATE users SET marketing_ok = 0 WHERE id = ?', [$uid]);
    if ($kind === 'alerts') q("INSERT INTO system_state (k, v) VALUES (?, '1') ON DUPLICATE KEY UPDATE v = '1'", ['noalertmail:' . $uid]);
    if (function_exists('consent_record')) consent_record($uid, $kind === 'news' ? 'marketing_email' : ($kind === 'weekly' ? 'weekly_email' : 'alert_email'), false, 'unsubscribe link');
    return true;
}

function marketing_ok(int $uid): bool { return (bool) q('SELECT marketing_ok FROM users WHERE id = ?', [$uid])->fetchColumn(); }
