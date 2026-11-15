<?php
/* Privacy notice and terms for the AushadhBox Pharmacy Network (pharmacies, their staff and their customers).
   Business details come from config.php ('operator' => [...]) so nobody edits HTML. */
declare(strict_types=1);

function legal_page(string $which): void
{
    $op = (array) (config('operator') ?? []);
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $name = $e($op['name'] ?? 'the operator of AushadhBox Pharmacy Network');
    $addr = $e($op['address'] ?? 'India');
    $mail = $e($op['email'] ?? '');
    $officer = $e($op['grievance_officer'] ?? ($op['name'] ?? 'our Grievance Officer'));
    $mailto = $mail ? '<a href="mailto:' . $mail . '">' . $mail . '</a>' : 'the e-mail address shown on this site';
    $updated = '15 November 2026';
    $fee = fee_cfg();
    $pages = [
        'privacy' => ['Privacy notice', <<<HTML
<p class="muted">Last updated {$updated}. Written for India's Digital Personal Data Protection Act, 2023 and DPDP Rules, 2025.</p>
<h2>Who decides what</h2>
<ul><li><b>Pharmacy staff accounts</b> (name, phone, e-mail, role, sign-in history): {$name} decides how these are used, to run the network. We are the Data Fiduciary.</li>
<li><b>A pharmacy's own customers</b> (walk-in customers, their khata, orders and prescriptions): the pharmacy is the Data Fiduciary. We store and process them for the pharmacy, only to provide this service (we are its Data Processor), and never use them for anything else.</li>
<li><b>Families using the AushadhBox app</b>: the family decides what a pharmacy may see or add, person by person. Their app shows every access and lets them stop it.</li></ul>
<h2>What we keep, and why</h2>
<table><thead><tr><th>Data</th><th>Why</th><th>How long</th></tr></thead><tbody>
<tr><td>Business and shop details, drug licence numbers, GSTIN, UPI ID, opening hours, location</td><td>Approving the pharmacy, showing it to families, payments</td><td>While the pharmacy is on the network, then as tax law requires</td></tr>
<tr><td>Staff: name, phone, e-mail, role, password (Argon2id hash only), two-factor secret (encrypted)</td><td>Sign-in, permissions, the activity log</td><td>Until the account is removed</td></tr>
<tr><td>Customers: name, phone and address (encrypted), khata, orders, payments, consent to SMS</td><td>Orders, delivery, credit book, reminders, as the pharmacy instructs</td><td>Sale and Schedule H1 / X records as drug law requires (at least 3 years); the rest until the pharmacy or customer removes it</td></tr>
<tr><td>Stock, batches, prices, fridge temperatures</td><td>Stock, expiry and cold-chain checks, forecasts</td><td>While the pharmacy is on the network</td></tr>
<tr><td>Activity log (who did what and when, IP address)</td><td>Security and the pharmacy's own audit trail</td><td>1 year</td></tr>
<tr><td>Encrypted backups</td><td>Restoring the service after a failure</td><td>8 weekly copies</td></tr>
</tbody></table>
<h2>Who receives data</h2>
<ul><li>Our hosting provider (stores it). An SMS provider (registered on DLT) for order and khata messages the customer agreed to. Courier companies, only the delivery details of an order sent with them. The AushadhBox family app, only for that family's own orders and only through signed requests.</li><li>We never sell data or use it for advertising.</li></ul>
<h2>How it is protected</h2>
<p>HTTPS, Argon2id password hashes, two-factor sign-in, encryption of customer phones and addresses, role-based permissions per shop, an activity log, request limits, encrypted backups, a separate database from the family app, and least-privilege database access. Designed to follow the ISO/IEC 29100 privacy principles and the ISO/IEC 27701 way of keeping records; this is a design aim, not a certification. If a breach affects personal data, we inform the affected pharmacies, people and the Data Protection Board as the DPDP Rules require.</p>
<h2>Your rights</h2>
<p>Staff and customers can ask for access, correction or erasure of their data, name a nominee, or raise a grievance with {$officer}, {$addr} ({$mailto}). Customers of a pharmacy can also ask the pharmacy directly. We reply within 30 days; the law allows at most 90 for a grievance. You may complain to the Data Protection Board of India.</p>
HTML],
        'terms' => ['Terms for pharmacies', <<<HTML
<p class="muted">Last updated {$updated}. Between {$name}, {$addr} and the pharmacy that registers.</p>
<h2>1. Who may join</h2><p>Retail pharmacies with a valid drug licence and a registered pharmacist. We check the licence before approving a pharmacy and may suspend a pharmacy whose licence lapses or that breaks these terms.</p>
<h2>2. Your duties</h2><ul><li>Dispense prescription medicines only against a valid prescription, and keep the registers the Drugs and Cosmetics Rules require.</li><li>Never charge above MRP. Prices you enter are checked against MRP.</li><li>Scan a family's AushadhBox card only when they show it, and use what you see only for their care.</li><li>Keep staff passwords private, give each person their own account and the smallest role they need, and remove staff who leave.</li><li>Get your customers' agreement before sending them SMS, and record it.</li></ul>
<h2>3. Fees</h2><p>Using the portal for your own walk-in, phone and SMS customers is free. For orders the network brings you (from the AushadhBox app, or passed on by a linked pharmacy) a commission applies: {$fee['urban']}% in cities, {$fee['semiurban']}% in towns, {$fee['rural']}% in villages, at most ₹{$fee['cap']} an order, plus {$fee['gst']}% GST. Jan Aushadhi Kendras pay nothing. Health-point vouchers you accept are paid back to you. Everything is in one monthly statement with an invoice; there are no other charges.</p>
<h2>4. Data</h2><p>You remain responsible for your customers' data (see the <a href="/privacy">privacy notice</a>); we process it only to provide this service and on your instructions. You can export your data, and when you leave we delete it, keeping only what the law requires.</p>
<h2>5. Availability and liability</h2><p>We work to keep the service running but cannot promise it will never be interrupted. Keep your own paper or software records as drug law requires. To the extent the law allows, our liability is limited to the fees you paid us in the last 12 months.</p>
<h2>6. Law</h2><p>These terms are governed by the laws of India. Contact: {$mailto}</p>
HTML],
    ];
    [$title, $body] = $pages[$which];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>', $title, ' · AushadhBox Pharmacy Network</title><link rel="stylesheet" href="/ph.css"></head><body>',
         '<a class="skip-link" href="#main">Skip to content</a><header class="top"><div class="wrap"><a class="brand" href="/"><img src="/icon.svg" width="36" height="36" alt=""><div><b>AushadhBox Pharmacy</b><small>Network of neighbourhood pharmacies</small></div></a></div></header>',
         '<main class="wrap pad prose" id="main"><h1>', $title, '</h1>', $body, '</main>',
         '<footer class="site"><div class="wrap"><a href="/privacy">Privacy</a> · <a href="/terms">Terms</a> · <a href="/">Home</a></div></footer></body></html>';
}
