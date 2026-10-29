<?php
/* Router for AushadhBox Pharmacy Network.
   /                 about the network, for pharmacies
   /app              pharmacy portal (staff)          /rider   delivery app        /admin   network administration
   /s/{code}         a shop's public page (what the QR poster opens): pay by UPI for any amount, open in the AushadhBox app
   /poster/{code}    printable A4 QR poster for the counter */
declare(strict_types=1);
$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if (PHP_SAPI === 'cli-server' && $path !== '' && is_file(__DIR__ . '/' . $path)) return false;   // php -S: real files (api.php, css, js) are served directly
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/network.php';
require_once dirname(__DIR__) . '/core/orders.php';
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
security_headers();

$static = ['' => 'landing.html', 'app' => 'portal.html', 'rider' => 'portal.html', 'admin' => 'admin.html'];   // riders use the portal: their role shows only their deliveries
if (isset($static[$path])) { header('Content-Type: text/html; charset=utf-8'); if ($path !== '') header('X-Robots-Tag: noindex'); readfile(__DIR__ . '/' . $static[$path]); return; }

if (preg_match('#^(s|poster)/([A-Za-z0-9]{8})$#', $path, $m)) {
    $b = branch_by_code($m[2]);
    header('Content-Type: text/html; charset=utf-8');
    if (!$b || $b['org_status'] !== 'approved') { http_response_code(404); echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not found</title><link rel="stylesheet" href="/ph.css"><main class="wrap pad"><h1>Pharmacy not found</h1><p>This QR code is not active. Ask the pharmacy for help.</p></main>'; return; }
    $family = rtrim((string) (config('family_app')['url'] ?? ''), '/');
    $appLink = ($family ?: 'https://aushadhbox.in') . '/app#store-' . $b['code'];
    $storeUrl = base_url() . '/s/' . $b['code'];
    $open = open_now((string) $b['hours']);
    if ($m[1] === 'poster') {   // printable poster
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QR poster · ', $e($b['name']), '</title><link rel="stylesheet" href="/ph.css"></head>',
             '<body class="poster-page"><div class="poster" data-qr="', $e($storeUrl), '"><div class="poster-top">AushadhBox</div><h1>', $e($b['name']), '</h1>',
             '<p class="poster-sub">', $e(trim(($b['village'] ? $b['village'] . ', ' : '') . $b['district'])), '</p><canvas id="qr" width="560" height="560" aria-label="QR code"></canvas>',
             '<ul class="poster-list"><li>Pay by UPI · UPI மூலம் செலுத்த · UPI से भुगतान</li><li>Order medicines and check stock · மருந்து ஆர்டர் · दवा ऑर्डर</li><li>Credit book and reward points · கடன் புத்தகம் · उधार खाता</li></ul>',
             '<p class="poster-code">Shop code <b>', $e($b['code']), '</b> · SMS orders: <b>AB ', $e($b['code']), ' medicine qty</b></p><p class="poster-foot">Licence ', $e($b['licence_retail']), ' · ', $e($b['phone']), '</p>',
             '<button class="btn noprint" type="button" data-print>Print</button></div><script src="/lib/qrcode.js"></script><script src="/poster.js"></script></body></html>';
        return;
    }
    $upi = $b['upi_vpa'] ? $e($b['upi_vpa']) : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>', $e($b['name']), ' · AushadhBox</title>',
         '<meta name="description" content="', $e($b['name'] . ', ' . $b['district'] . ': pay by UPI, order medicines and check stock with AushadhBox.'), '"><link rel="stylesheet" href="/ph.css"></head><body><main class="wrap pad store">',
         '<p class="eyebrow">AushadhBox pharmacy</p><h1>', $e($b['name']), '</h1><p class="muted">', $e(trim(($b['address'] ?: '') . ' ' . ($b['village'] ?: '') . ', ' . $b['district'])), '</p>',
         '<p>', $open === null ? '' : ($open ? '<span class="pill ok">Open now</span> ' : '<span class="pill warn">Closed now</span> '), $e($b['hours'] ?? ''), '</p>',
         '<p><a class="btn" href="tel:+91', $e($b['phone']), '">Call ', $e($b['phone']), '</a></p>';
    if ($upi) echo '<section class="card stack" id="payBox" data-vpa="', $upi, '" data-name="', $e($b['upi_name'] ?: $b['name']), '"><h2>Pay by UPI</h2><p class="muted">Works with GPay, PhonePe, Paytm, BHIM and any bank app.</p>',
         '<label class="field">Amount (₹)<input id="amt" inputmode="decimal" placeholder="250"></label><a class="btn primary" id="payBtn" href="#">Pay</a><canvas id="payQr" width="240" height="240" hidden aria-label="UPI QR"></canvas>',
         '<p class="muted small">UPI ID: ', $upi, '. Keep the 12-digit UPI reference: the pharmacy may ask for it.</p></section>';
    echo '<section class="card stack"><h2>Order, check stock, credit book</h2><p>Open this pharmacy in the AushadhBox app to order medicines, see if they are in stock, keep your credit book (khata) and earn reward points.</p>',
         '<a class="btn primary" href="', $e($appLink), '">Open in AushadhBox</a><p class="muted small">No smartphone? Send an SMS: <b>AB ', $e($b['code']), ' dolo 650 2</b></p></section>',
         '<p class="muted small">Licence ', $e($b['licence_retail']), $b['pharmacist'] ? ' · Pharmacist ' . $e($b['pharmacist']) : '', '</p></main><script src="/lib/qrcode.js"></script><script src="/store.js"></script></body></html>';
    return;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not found</title><link rel="stylesheet" href="/ph.css"><main class="wrap pad"><h1>Page not found</h1><p><a href="/">AushadhBox Pharmacy Network</a></p></main>';
