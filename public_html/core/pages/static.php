<?php
/* About, contact, help and the legal pages. Business details come from config.php so the owner never edits HTML. */

function biz(string $k, string $fallback = ''): string { $v = (string) config($k); return $v !== '' ? $v : $fallback; }

function static_page(string $page): void
{
    $name = site_name();
    $owner = biz('business_name', $name);
    $email = biz('support_email', 'support@example.com');
    $phone = biz('support_phone');
    $addr = biz('business_address', 'India');
    $officer = biz('grievance_officer', $owner);
    $p = prices();
    $mailto = '<a href="mailto:' . e($email) . '">' . e($email) . '</a>';
    $updated = '28 October 2026';

    $pages = [
    'about' => ['About us', 'Why we built ' . $name . ': a simple, multilingual medicine cabinet for Indian families.', <<<HTML
<p>Most Indian homes have a box, bag or shelf full of medicines: some for Amma's blood pressure, some left over from a fever, cough syrup opened months ago. Nobody remembers which strip expires when. Labels are tiny and printed in English only.</p>
<p>{$name} turns that box into a tidy digital cabinet, inspired by the <i>kusuri-dansu</i>, the drawer chests Japanese families have used for medicines for centuries. Every medicine gets a drawer with a coloured handle: <b style="color:var(--ok)">green</b> is safe, <b style="color:var(--warn)">yellow</b> means use it soon, <b style="color:var(--bad)">red</b> means do not use it.</p>
<h2>What we believe</h2>
<ul><li><b>Your language, not ours.</b> The app and voice assistant work in 10 Indian languages.</li><li><b>Privacy first.</b> We collect only what the app needs. Photos you scan are read on your phone and never uploaded. Optional cookies are used only if you say yes.</li><li><b>Honest money.</b> No ads and no selling data. The Free plan stays free; families who want more pay a small fee for Premium.</li><li><b>Save on medicines.</b> We show the Jan Aushadhi generic for medicines you own, using the official PMBJP product list.</li></ul>
<h2>Where our data comes from</h2>
<p>The medicine list is the public Pradhan Mantri Bhartiya Janaushadhi Pariyojana (PMBJP) product list. Ingredient guides are written in plain language and reviewed against public sources such as the National List of Essential Medicines. They are general information, not medical advice.</p>
<h2>Who runs it</h2>
<p>{$name} is run by {$owner}, {$addr}. Write to us at {$mailto}.</p>
HTML],
    'help' => ['Help centre', 'Answers to common questions about using ' . $name . ': scanning, reminders, family sharing, languages and payments.', <<<HTML
<h2>Getting started</h2>
<h3>How do I add a medicine?</h3><p>Open the app, tap <b>Scan</b>, and point your camera at the back of the strip where the batch number and expiry are printed. Check the details and tap <b>Save</b>. You can also type them in.</p>
<h3>The scan did not read my label</h3><p>Hold the phone steady, in good light, about a hand's width from the strip. Shiny foil reflects light, so tilt it slightly. If it still fails, tap <b>Type instead</b>.</p>
<h3>How do I add the app to my home screen?</h3><p><b>Android (Chrome):</b> menu ⋮ → <i>Add to Home screen</i>. <b>iPhone (Safari):</b> Share → <i>Add to Home Screen</i>.</p>
<h2>Reminders</h2>
<h3>I am not getting notifications</h3><p>Go to <b>Account → Reminders</b> and tap <b>Turn on phone notifications</b>, then allow them when your phone asks. On iPhone, add the app to your home screen first (iOS 16.4 or later).</p>
<h2>Family sharing (Premium)</h2>
<h3>How do I share the cabinet with my family?</h3><p>Go to <b>Account → Family sharing → Create invite code</b>. Send the 8-letter code to your family member. They sign up, open the same screen and enter the code. Codes work for 7 days.</p>
<h3>How do I stop sharing?</h3><p>The owner can remove a person from <b>Account → Family sharing</b>. A member can tap <b>Leave family</b>. Their own login stays; they just stop seeing the shared cabinet.</p>
<h2>Payments</h2>
<h3>Which payment methods work?</h3><p>UPI, cards, net banking and wallets, through Razorpay.</p>
<h3>I paid but I still see the Free plan</h3><p>Close and reopen the app. If it still shows Free after 10 minutes, e-mail {$mailto} with your Razorpay payment ID and we will fix it the same day.</p>
<h2>Your data</h2>
<h3>How do I download or delete my data?</h3><p><b>Account → Download my data</b> gives you a file with everything. <b>Account → Delete my account</b> removes it all permanently.</p>
<p class="note">Still stuck? <a href="/contact">Contact us</a>. We reply within 2 working days.</p>
HTML],
    'privacy' => ['Privacy policy', 'How ' . $name . ' collects, uses and protects your personal data, written for the Digital Personal Data Protection Act, 2023.', <<<HTML
<p class="muted">Last updated {$updated}. Written for the Digital Personal Data Protection Act, 2023 and the DPDP Rules, 2025.</p>
<h2>1. Who we are</h2><p>{$name} is run by {$owner}, {$addr} ("we"). We are the Data Fiduciary for the personal data you give us.</p>
<h2>2. What we collect, and why</h2>
<table><thead><tr><th>Data</th><th>Why we need it</th><th>Legal basis</th></tr></thead><tbody>
<tr><td>Name, e-mail, optional mobile, password (stored only as a secure hash)</td><td>To create your account, sign you in and reset your password</td><td>Your consent at sign-up</td></tr>
<tr><td>Family members: name, relation, allergies you choose to record</td><td>To show whose medicine is whose and warn about allergies</td><td>Your consent</td></tr>
<tr><td>Medicines: name, batch, dates, quantity, doses, notes</td><td>Expiry alerts, dose reminders and your medicine notebook</td><td>Your consent</td></tr>
<tr><td>Reminder settings and push subscription</td><td>To send reminders when the app is closed</td><td>Your consent</td></tr>
<tr><td>Payments: plan, amount, Razorpay order and payment IDs</td><td>To give you Premium and keep accounting records</td><td>Contract and legal obligation (tax law)</td></tr>
<tr><td>Contact form messages</td><td>To answer you</td><td>Your consent</td></tr>
<tr><td>Security logs: IP address and time of sign-in attempts</td><td>To block password-guessing attacks</td><td>Legitimate use for security, as allowed by the DPDP Rules</td></tr>
<tr><td>Website statistics (pages viewed, referrer, device type, language)</td><td>To understand which pages help people</td><td><b>Only if you click Accept</b> on the cookie banner</td></tr>
</tbody></table>
<p>Photos you scan are read inside your phone's browser. They are never uploaded. Only the text read from the label is sent to find the matching medicine.</p>
<p><b>We never sell your data, show advertising inside the app, or share your health information with pharmacies, insurers or advertisers.</b></p>
<h2>3. Cookies</h2><p>We use one necessary cookie to keep you signed in and one to remember your cookie choice. Analytics cookies are set only after you click <b>Accept</b> or turn them on under <b>Choose</b>. See the <a href="/cookie-policy">cookie policy</a>. You can change your choice any time with <b>Cookie settings</b> at the bottom of every page.</p>
<h2>4. Who processes data for us</h2>
<ul><li>Our web hosting provider (servers in India or abroad, with security commitments) stores the database.</li><li>Razorpay Software Pvt. Ltd. processes payments. Card and UPI details go straight to Razorpay; we never see them.</li><li>Our e-mail provider sends password-reset, receipt and weekly reminder e-mails.</li><li>Your browser's push service (Google, Mozilla or Apple) delivers notifications.</li><li>If enabled, Bhashini (Government of India) turns reply text into speech. Only the text being spoken is sent.</li><li>Only if you switch on the AI helper in your account: your question and your family's medicine list are sent to our AI provider to write the answer, and support messages may be drafted with its help. It is not used for advertising, and you can switch it off at any time.</li><li>If the site owner has enabled the smart pillbox, the box sends only lid-opening times, battery level and its firmware version.</li><li>If you accept analytics cookies and the site owner has enabled it, Google Analytics receives anonymised page statistics.</li></ul>
<h2>5. Your rights</h2>
<ul><li><b>Access:</b> Account → Download my data.</li><li><b>Correction:</b> edit your profile, family and medicines at any time.</li><li><b>Erasure and withdrawing consent:</b> Account → Delete my account removes everything at once. Payment records are kept for 8 years because tax law requires it, without your health data.</li><li><b>Withdraw cookie consent:</b> Cookie settings at the bottom of every page.</li><li><b>Nominate</b> a person to exercise these rights if you die or cannot act: e-mail us.</li><li><b>Grievances:</b> write to our Grievance Officer below. We reply within 90 days. If you are not satisfied, you may complain to the Data Protection Board of India.</li></ul>
<h2>6. How long we keep data</h2><p>Your account data stays until you delete it. Security logs and website statistics are kept for one year. Cookie consent records are kept for one year after you change or withdraw them, as proof. Payment records are kept for 8 years.</p>
<h2>7. Security</h2><p>HTTPS everywhere, hashed passwords, protection against cross-site request forgery, limits on repeated sign-in attempts, and checks that every record belongs to the signed-in family. If a breach affects your data, we will inform you and the Data Protection Board without delay, and in any case within 72 hours of becoming aware of it.</p>
<h2>8. Children</h2><p>Accounts are for adults (18+). A parent or guardian can add children as family members in their own account.</p>
<h2>9. Grievance Officer and contact</h2><p>{$officer}, {$addr}<br>E-mail: {$mailto}</p>
<p class="note">{$name} organises medicines. It does not give medical advice and does not replace your doctor or pharmacist.</p>
HTML],
    'terms' => ['Terms of service', 'The rules for using ' . $name . ', including Free and Family Premium plans.', <<<HTML
<p class="muted">Last updated {$updated}.</p>
<h2>1. Agreement</h2><p>By creating an account you agree to these terms and our <a href="/privacy">privacy policy</a>. The service is provided by {$owner}, {$addr}.</p>
<h2>2. Not medical advice</h2><p>{$name} helps you organise medicines and reminds you about expiry dates and doses. It does not diagnose, prescribe or give medical advice. Information on medicine pages is general and may be incomplete. Always follow your doctor, your pharmacist and the printed label. In an emergency call 112.</p>
<h2>3. Your account</h2><ul><li>You must be 18 or older.</li><li>Keep your password safe. You are responsible for what happens in your account.</li><li>Give correct information. Scanned details may be misread, so check them before saving.</li></ul>
<h2>4. Plans and payment</h2><ul><li><b>Free plan:</b> free for as long as we run the service, with the limits shown on the <a href="/pricing">pricing page</a>.</li><li><b>Family Premium:</b> {$p['month']} rupees per month or {$p['year']} rupees per year, paid in advance through Razorpay. Prices include applicable taxes.</li><li>Premium does <b>not</b> renew automatically. When it ends, your account returns to the Free plan and no data is deleted.</li><li>We may change prices for future periods. We will tell you at least 30 days before; it never affects a period you have already paid for.</li><li>Refunds follow our <a href="/refund-policy">refund policy</a>.</li></ul>
<h2>5. Family sharing</h2><p>The person who creates the cabinet is its owner. Members invited by the owner can see and change the shared medicines and family list. The owner can remove members at any time. Share invite codes only with people you trust.</p>
<h2>6. Fair use</h2><p>Do not misuse the service: no automated scraping, attacks, attempts to access other people's data, or reselling. "Unlimited" medicines means normal household use; we may contact you if an account is clearly not household use.</p>
<h2>7. Availability</h2><p>We work hard to keep the service running, but cannot promise it will never be interrupted. Reminders depend on your phone, browser and network, so do not rely on them as your only reminder for critical medicines.</p>
<h2>8. Liability</h2><p>To the extent the law allows, our total liability to you is limited to the amount you paid us in the 12 months before the claim. We are not liable for decisions about taking or not taking a medicine.</p>
<h2>9. Ending</h2><p>You can delete your account at any time. We may suspend accounts that break these terms, after warning you where possible.</p>
<h2>10. Law</h2><p>These terms are governed by the laws of India. Courts at the city in our address above have jurisdiction.</p>
<h2>11. Contact</h2><p>{$mailto}</p>
HTML],
    'refund-policy' => ['Refund and cancellation policy', 'Refunds and cancellation for ' . $name . ' Family Premium.', <<<HTML
<p class="muted">Last updated {$updated}.</p>
<h2>Cancellation</h2><p>Premium is a one-time payment for one month or one year. It does not renew automatically, so there is nothing to cancel. If you do not pay again, your account returns to the Free plan at the end of the period.</p>
<h2>7-day money-back promise</h2><p>If you are not happy with Premium, e-mail {$mailto} within <b>7 days</b> of paying, with the e-mail address of your account. We will refund the full amount. No questions asked.</p>
<h2>After 7 days</h2><p>Yearly plans: if you ask within 30 days of paying, we refund the unused full months. Monthly plans are not refunded after 7 days.</p>
<h2>Failed or double payments</h2><p>If money left your account but Premium did not start, or you were charged twice, write to us with the Razorpay payment ID. We refund any duplicate or failed charge in full.</p>
<h2>How refunds are paid</h2><p>Refunds go back to the original payment method through Razorpay. Once we approve a refund, it usually reaches you within <b>5–7 working days</b>, depending on your bank.</p>
<h2>Contact</h2><p>{$owner}, {$addr} · {$mailto}</p>
HTML],
    'cookie-policy' => ['Cookie policy', 'Which cookies ' . $name . ' uses, why, and how to change your choice.', <<<HTML
<p class="muted">Last updated {$updated}.</p>
<p>Cookies are small text files a website saves in your browser. We keep them to a minimum. <b>Only necessary cookies are used unless you click Accept.</b> You can change your choice at any time with <button type="button" class="linklike" style="color:var(--ai);text-decoration:underline" data-cookie-settings>Cookie settings</button>.</p>
<h2>Cookies we use</h2>
<table><thead><tr><th>Name</th><th>Type</th><th>Purpose</th><th>Kept for</th></tr></thead><tbody>
<tr><td><code>msync</code></td><td>Necessary</td><td>Keeps you signed in and protects forms against forgery</td><td>Until you sign out, up to 30 days</td></tr>
<tr><td><code>ms_consent</code></td><td>Necessary</td><td>Remembers your cookie choice and a random ID for consent records</td><td>180 days</td></tr>
<tr><td>Local storage: <code>ms-lang</code>, <code>ms-theme</code></td><td>Necessary</td><td>Remembers your language and light/dark choice on your device</td><td>Until you clear it</td></tr>
<tr><td>Our own statistics (no cookie, uses the random ID above)</td><td>Analytics: <b>only after Accept</b></td><td>Counts page views, which site sent you, device type and language</td><td>Records deleted after 1 year</td></tr>
<tr><td><code>_ga</code>, <code>_ga_*</code></td><td>Analytics: <b>only after Accept</b>, and only if Google Analytics is turned on</td><td>Google Analytics anonymised statistics</td><td>Up to 13 months</td></tr>
</tbody></table>
<h2>Marketing</h2><p>We do not use advertising cookies today. The Marketing option exists so that, if we ever run ad campaigns, we only measure them for people who agreed.</p>
<h2>Your choices</h2><ul><li><b>Accept</b> turns on analytics and marketing.</li><li><b>Reject</b> keeps only necessary cookies.</li><li><b>Choose</b> lets you pick each type.</li></ul>
<p>When you reject or withdraw, we delete the analytics cookies from your browser. Every choice is recorded (random ID, choice, time) as proof of consent, as Indian law requires.</p>
HTML],
    ];

    if ($page === 'contact') {
        render('contact', compact('email', 'phone', 'addr', 'owner'), [
            'title' => 'Contact us · ' . $name, 'description' => 'Contact ' . $name . ' for help, billing, privacy requests or partnerships. We reply within 2 working days.',
            'canonical' => base_url() . '/contact', 'jsonld' => [breadcrumbs([['Home', '/'], ['Contact', '/contact']])],
        ]);
        return;
    }
    [$title, $desc, $html] = $pages[$page];
    render('static', ['heading' => $title, 'html' => $html, 'page' => $page], [
        'title' => $title . ' · ' . $name, 'description' => $desc, 'canonical' => base_url() . '/' . $page,
        'nav' => $page === 'help' ? 'navHelp' : '', 'jsonld' => [breadcrumbs([['Home', '/'], [$title, '/' . $page]])],
    ]);
}
