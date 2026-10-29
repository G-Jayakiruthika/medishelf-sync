<?php
/* Personal "tap to tick" link: /tick/{secret}
   GET  → a big "Taken" page. With ?auto=1 (NFC sticker or QR on the medicine box) it ticks as soon as it opens.
          Link previews and bots never tick: ticking needs a POST.
   POST → ticks the doses due now. Siri Shortcuts / Google / Bixby routines can POST and read the answer aloud:
          add ?format=text for a plain sentence, otherwise JSON. */
require_once APP_DIR . '/doses.php';
require_once APP_DIR . '/watch.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

function tick_page(string $token): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $u = tick_user($token);
    if ($method === 'POST') {
        $n = (int) q("SELECT COUNT(*) FROM login_attempts WHERE created_at >= NOW() - INTERVAL 10 MINUTE AND email = 'tick:' AND ip = ?", [client_ip()])->fetchColumn();
        if ($n >= 30) { http_response_code(429); exit('Too many tries. Wait a few minutes.'); }
        if (!$u) { q("INSERT INTO login_attempts (ip, email, success) VALUES (?, 'tick:', 0)", [client_ip()]); http_response_code(404); exit(($_GET['format'] ?? '') === 'text' ? 'This link no longer works. Make a new one in the app.' : '{"error":"bad_link"}'); }
        $r = tick_now((int) ($u['household_id'] ?: $u['id']), 'tick');
        if (($_GET['format'] ?? '') === 'text') { header('Content-Type: text/plain; charset=utf-8'); exit($r['message']); }
        json_out($r);
    }
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $site = $e(config('site_name') ?: 'AushadhBox');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">',
         '<title>Taken · ', $site, '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:18px/1.4 system-ui,sans-serif;background:#1F3358;color:#fff;text-align:center}',
         'main{padding:24px;max-width:420px}button{font:inherit;font-size:1.6rem;font-weight:800;border:0;border-radius:999px;padding:22px 40px;background:#fff;color:#1F3358;cursor:pointer;width:100%}',
         'p{opacity:.9}#tickMsg{font-size:1.15rem;margin-top:18px;min-height:3em}</style></head><body><main>';
    if (!$u) { echo '<h1>Link not valid</h1><p>Make a new "tap to tick" link in the ', $site, ' app (Account → Smartwatch).</p></main></body></html>'; return; }
    echo '<p>', $site, '</p><button id="tickBtn" type="button">✓ Taken</button><p id="tickMsg">Ticks the medicines due now.</p><script src="/mini.js?v=1"></script>',
         '</main></body></html>';
}
