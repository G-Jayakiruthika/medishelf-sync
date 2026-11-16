<?php
/* Privacy centre: the record of processing (what we keep, why, on what basis, who receives it, how long, how it is protected),
   consent records, requests about personal data, the nominee, and a child's guardian consent.

   Built to the structure of ISO/IEC 27701 (privacy information management: records of processing, data-subject requests,
   consent records) and the ISO/IEC 29100 privacy principles, for India's Digital Personal Data Protection Act 2023 and
   DPDP Rules 2025. This is a design aim, not a certification. */
declare(strict_types=1);

/* ISO/IEC 29100's eleven privacy principles, and where each one shows up in AushadhBox. */
const PRIVACY_PRINCIPLES = [
    ['Consent and choice', 'Sign-up asks for agreement to the privacy notice. News e-mails, the AI helper, pharmacy sharing, SMS and analytics cookies stay off until you turn them on; the weekly expiry e-mail is on (it is a reminder about your medicines) and every one of them can be turned off here.'],
    ['Purpose legitimacy and specification', 'Each kind of data below says what it is used for. It is not used for anything else.'],
    ['Collection limitation', 'Only an e-mail, a name and a password are required. Phone, birth dates, health details and readings are optional.'],
    ['Data minimisation', 'Pharmacies see a masked name and only what you allowed; photos you scan are read on your phone and never uploaded.'],
    ['Use, retention and disclosure limitation', 'No selling or advertising use of data. Retention periods are listed below; deleted accounts are erased.'],
    ['Accuracy and quality', 'You can correct everything yourself; a correction request is answered within 30 days.'],
    ['Openness, transparency and notice', 'This page, the privacy policy and the cookie policy say what happens to your data in plain words.'],
    ['Individual participation and access', 'Download all your data, correct it, delete it, name a nominee or raise a grievance from this page.'],
    ['Accountability', 'A named Grievance Officer, a record of every consent, and a log of every request and its answer.'],
    ['Information security', 'HTTPS, Argon2id passwords, encryption of health details and phone numbers, two-factor sign-in for staff, encrypted backups, security monitoring.'],
    ['Privacy compliance', 'Built for the DPDP Act 2023 and DPDP Rules 2025; checked by automated tests on every change.'],
];

/* The record of processing. Edit here; the upgrader copies it into the privacy_register table. */
const PRIVACY_REGISTER = [
    ['account', 'Account', 'Name, e-mail, password (stored only as an Argon2id hash), language, optional mobile number', 'Sign-in, account messages (password reset, receipts), language of the app', 'Consent (DPDP s.6)', 'required',
     'Our hosting provider (stores it)', 'Until you delete the account', 'India or abroad, with our hosting provider', 'HTTPS, Argon2id password hash, sign-out everywhere after a password change'],
    ['family', 'Family members', 'Names, relation, birth date, blood group, allergies; optional health conditions, emergency contact and phone', 'Showing whose medicine is whose, safety checks (allergies, duplicates), emergency card, vaccination dates', 'Consent; for children, the consent of their parent or guardian (DPDP s.9)', 'optional',
     'Pharmacies only when you switch sharing on for that person', 'Until you remove the person or delete the account', 'As account', 'Health conditions, emergency contacts and phones encrypted (XChaCha20-Poly1305 / XSalsa20-Poly1305)'],
    ['medicines', 'Medicines and doses', 'Medicines, batch, expiry, dose times, doses taken or skipped and why', 'Reminders, expiry alerts, refill alerts, doctor visit sheet, weekly report', 'Consent', 'required',
     'Nobody, unless you share with a pharmacy, a doctor link or family members', 'Until you delete them or the account', 'As account', 'Access only by your family account; ownership checked on every request'],
    ['health', 'Health diary and readings', 'Blood pressure, sugar, weight, temperature, oxygen, pulse, lab results, steps, check-in answers', 'Charts, safe-range alerts to your family, visit sheet', 'Consent', 'optional',
     'Doctors only through a time-limited link you create', 'Until you delete them or the account', 'As account', 'Ownership checks; doctor links expire and can be stopped'],
    ['careplus', 'Care+ (pregnancy, child growth, repeat prescriptions, points)', 'Last period date, check-ups, child weights, repeat prescriptions, health points', 'Mother-and-child reminders, refills, vouchers', 'Consent', 'optional',
     'The pharmacy you order a refill from (medicines and prescription details only)', 'Until you end it or delete the account', 'As account', 'Ownership checks'],
    ['pharmacy', 'Pharmacy orders and card', 'Orders, delivery address, phone for the order, card scans', 'Orders, delivery, refills, the pharmacy safety check', 'Consent (you choose the pharmacy and what it may see)', 'optional',
     'The pharmacy you order from or allow; the AushadhBox Pharmacy Network (separate service)', 'Orders: as drug law requires the pharmacy to keep sale records; the rest until you delete the account', 'India', 'Signed links between the two services; addresses and phones encrypted at the pharmacy network'],
    ['devices', 'Pillbox, sensors and watches', 'Lid openings, sensor signs of life, smartwatch ticks', 'Ticking doses, missed-dose and watch-over alerts', 'Consent', 'optional',
     'Nobody', 'Until you remove the device or delete the account', 'As account', 'Device tokens stored as hashes'],
    ['phones', 'Phones without internet', 'Phone numbers of family members, missed calls, SMS sent', 'SMS reminders and "missed call = taken"', 'Consent', 'optional',
     'Our SMS / missed-call provider (number and message only)', 'Until you remove the number; SMS logs 1 year', 'India (DLT-registered provider)', 'Phones encrypted; caller found by a keyed hash'],
    ['payments', 'Payments', 'Plan, amount, Razorpay order and payment ids', 'Premium, receipts, refunds, tax records', 'Consent; payment records are then kept because tax law requires it', 'optional',
     'Razorpay (processes the payment; we never see card or UPI details)', '8 years (tax law), without health data', 'India', 'Payment signatures verified on the server; prices fixed on the server'],
    ['ai', 'AI helper (optional)', 'Your question and your family cabinet list, sent when you ask', 'Answering questions about your own medicines', 'Consent (switch in Account)', 'optional',
     'The AI provider set by the site owner (Anthropic or an OpenAI-compatible service), not used to train models under their API terms', 'Not kept by us beyond the answer', 'Provider\'s servers (may be outside India)', 'Off by default; text sent as data, never as instructions; no actions taken by the AI'],
    ['support', 'Messages to support', 'Name, e-mail, message', 'Answering you', 'Consent', 'optional',
     'Our team; an AI may draft a reply that a person checks', '2 years', 'As account', 'Admin access needs two-factor sign-in'],
    ['security', 'Security and sign-in logs', 'IP address, time, kind of event (failed sign-in, blocked request)', 'Protecting accounts, stopping attacks', 'Security safeguards the DPDP Act requires (s.8(5))', 'required',
     'Nobody', '1 year', 'As account', 'Visible only to administrators with two-factor sign-in'],
    ['analytics', 'Website statistics (cookies)', 'Pages viewed, browser, rough location from the IP', 'Improving the website', 'Consent (cookie banner)', 'optional',
     'Google Analytics, only after you click Accept', '1 year', 'Google\'s servers', 'Not loaded until you accept; withdrawing deletes past statistics'],
    ['backups', 'Backups', 'A copy of the whole database', 'Restoring the service after a failure', 'Security safeguards the DPDP Act requires (s.8(5))', 'required',
     'Nobody', 'Last 8 weekly copies', 'Our server, and an offline copy kept by the site owner', 'Encrypted (XChaCha20-Poly1305); a changed file is refused'],
];

const PRIVACY_REQUEST_KINDS = ['access' => 'A copy of my data', 'correction' => 'Correct my data', 'erasure' => 'Delete my data', 'nominee' => 'About my nominee', 'grievance' => 'A complaint (grievance)', 'other' => 'Something else'];
const PRIVACY_REPLY_DAYS = 30;     // our own target; the DPDP Rules allow at most 90 days for grievances

function privacy_register_sync(): int
{
    $n = 0;
    foreach (PRIVACY_REGISTER as $r) {
        $n += q('INSERT INTO privacy_register (id, category, items, purpose, basis, needed, recipients, retention, location, protection) VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE category = VALUES(category), items = VALUES(items), purpose = VALUES(purpose), basis = VALUES(basis), needed = VALUES(needed),
                 recipients = VALUES(recipients), retention = VALUES(retention), location = VALUES(location), protection = VALUES(protection)', $r)->rowCount() ? 1 : 0;
    }
    q('DELETE FROM privacy_register WHERE id NOT IN (' . implode(',', array_fill(0, count(PRIVACY_REGISTER), '?')) . ')', array_column(PRIVACY_REGISTER, 0));
    return $n;
}

function consent_record(?int $uid, string $purpose, bool $granted, string $source, ?string $version = null): void
{
    try { q('INSERT INTO consent_records (user_id, purpose, granted, version, source) VALUES (?,?,?,?,?)', [$uid, substr($purpose, 0, 40), $granted ? 1 : 0, $version, substr($source, 0, 40)]); }
    catch (Throwable $e) { error_log('consent_record: ' . $e->getMessage()); }
}

/* How much of each kind of data this family has, for "What we keep about you". */
function privacy_counts(int $hid, int $uid): array
{
    $count = function (string $sql, array $args) { try { return (int) q($sql, $args)->fetchColumn(); } catch (Throwable $e) { return 0; } };
    return [
        'family' => $count('SELECT COUNT(*) FROM members WHERE user_id = ?', [$hid]),
        'medicines' => $count('SELECT COUNT(*) FROM cabinet_items WHERE user_id = ?', [$hid]),
        'doses' => $count('SELECT COUNT(*) FROM dose_logs WHERE user_id = ?', [$hid]),
        'health' => $count('SELECT COUNT(*) FROM vitals WHERE user_id = ?', [$hid]),
        'devices' => $count('SELECT COUNT(*) FROM devices WHERE user_id = ?', [$hid]),
        'phones' => $count('SELECT COUNT(*) FROM members WHERE user_id = ? AND phone IS NOT NULL', [$hid]),
        'pharmacy' => $count('SELECT COUNT(*) FROM pharmacy_links l JOIN members m ON m.id = l.member_id WHERE m.user_id = ?', [$hid]),
        'payments' => $count('SELECT COUNT(*) FROM payments WHERE user_id = ?', [$uid]),
        'security' => $count("SELECT COUNT(*) FROM security_events WHERE user_id = ?", [$uid]),
    ];
}

function privacy_overview(array $user, int $hid): array
{
    $uid = (int) $user['id'];
    $u = q('SELECT weekly_email, marketing_ok, ai_opt_in, nominee_name, nominee_contact, consent_at, adult_confirmed_at, created_at FROM users WHERE id = ?', [$uid])->fetch();
    $sharing = q('SELECT name, share_add, share_view, sms_remind, birth_date, guardian_consent_at FROM members WHERE user_id = ? ORDER BY is_self DESC, id', [$hid])->fetchAll();
    return [
        'register' => array_map(fn($r) => array_combine(['id', 'category', 'items', 'purpose', 'basis', 'needed', 'recipients', 'retention', 'location', 'protection'], $r), PRIVACY_REGISTER),
        'principles' => array_map(fn($p) => ['name' => $p[0], 'how' => $p[1]], PRIVACY_PRINCIPLES),
        'counts' => privacy_counts($hid, $uid),
        'choices' => ['weeklyEmail' => (bool) $u['weekly_email'], 'news' => (bool) $u['marketing_ok'], 'ai' => (bool) $u['ai_opt_in'],
                      'people' => array_map(fn($m) => ['name' => $m['name'], 'pharmacyAdd' => (bool) $m['share_add'], 'pharmacyView' => (bool) $m['share_view'], 'sms' => (bool) $m['sms_remind'],
                                                       'child' => $m['birth_date'] && $m['birth_date'] > date('Y-m-d', strtotime('-18 year')), 'guardian' => (bool) $m['guardian_consent_at']], $sharing)],
        'nominee' => ['name' => unseal($u['nominee_name']), 'contact' => unseal($u['nominee_contact'])],
        'consents' => q('SELECT purpose, granted, source, created_at FROM consent_records WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$uid])->fetchAll(),
        'requests' => array_map('privacy_request_public', q('SELECT * FROM privacy_requests WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$uid])->fetchAll()),
        'since' => ['account' => $u['created_at'], 'notice' => $u['consent_at'], 'adult' => $u['adult_confirmed_at']],
        'officer' => ['name' => (string) (biz_value('grievance_officer') ?? ''), 'email' => (string) (config('privacy_email') ?? config('support_email') ?? '')],
        'replyDays' => PRIVACY_REPLY_DAYS, 'kinds' => PRIVACY_REQUEST_KINDS,
    ];
}

function biz_value(string $k): ?string { $b = (array) (config('business') ?? []); $v = $b[$k] ?? config($k); return $v === null ? null : (string) $v; }

function privacy_request_public(array $r): array
{
    return ['id' => (int) $r['id'], 'ref' => 'PR-' . str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT), 'kind' => $r['kind'], 'details' => $r['details'], 'status' => $r['status'],
            'reply' => $r['reply'], 'due' => substr((string) $r['due_at'], 0, 10), 'at' => $r['created_at'], 'closed' => $r['closed_at']];
}

function privacy_request_create(array $user, string $kind, string $details): array
{
    if (!isset(PRIVACY_REQUEST_KINDS[$kind])) fail('bad_kind', 'Choose what the request is about.');
    $details = trim(mb_substr($details, 0, 2000));
    if (in_array($kind, ['correction', 'grievance', 'other'], true) && mb_strlen($details) < 10) fail('more_detail', 'Please describe the request in a sentence or two.');
    if ((int) q("SELECT COUNT(*) FROM privacy_requests WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 DAY", [$user['id']])->fetchColumn() >= 5) fail('too_many', 'You have sent 5 requests today. We will answer those first.', 429);
    q('INSERT INTO privacy_requests (user_id, user_ref, kind, details, due_at) VALUES (?,?,?,?, NOW() + INTERVAL ' . PRIVACY_REPLY_DAYS . ' DAY)', [$user['id'], hash('sha256', strtolower($user['email'])), $kind, $details ?: null]);
    $id = (int) db()->lastInsertId();
    $to = (string) (config('privacy_email') ?? config('support_email') ?? '');
    if ($to !== '' && function_exists('send_mail') && mail_enabled()) send_mail($to, 'Privacy request PR-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT) . ' (' . $kind . ')', "A " . PRIVACY_REQUEST_KINDS[$kind] . " request arrived. Answer it in Admin → Privacy requests within " . PRIVACY_REPLY_DAYS . " days.");
    return privacy_request_public(q('SELECT * FROM privacy_requests WHERE id = ?', [$id])->fetch());
}

function privacy_nominee_set(int $uid, string $name, string $contact): void
{
    $name = trim(mb_substr($name, 0, 80)); $contact = trim(mb_substr($contact, 0, 120));
    if (($name === '') !== ($contact === '')) fail('nominee_both', 'Give both the nominee\'s name and a phone number or e-mail, or leave both empty to remove the nominee.');
    q('UPDATE users SET nominee_name = ?, nominee_contact = ? WHERE id = ?', [seal($name ?: null), seal($contact ?: null), $uid]);
    consent_record($uid, 'nominee', $name !== '', 'privacy tab');
}

/* Optional choices from the Privacy tab (each change is recorded). */
function privacy_choice(array $user, string $what, bool $on): void
{
    $col = ['news' => 'marketing_ok', 'weekly' => 'weekly_email', 'ai' => 'ai_opt_in'][$what] ?? null;
    if (!$col) fail('bad_choice', 'Unknown setting.');
    q("UPDATE users SET $col = ? WHERE id = ?", [$on ? 1 : 0, $user['id']]);
    if ($what === 'weekly' && $on) q('DELETE FROM system_state WHERE k = ?', ['noalertmail:' . $user['id']]);
    consent_record((int) $user['id'], ['news' => 'marketing_email', 'weekly' => 'weekly_email', 'ai' => 'ai_helper'][$what], $on, 'privacy tab');
}

/* A child's data is kept only with a parent's or guardian's consent (DPDP s.9). */
function guardian_consent_required(?string $birthDate, bool $given): void
{
    if ($birthDate && $birthDate > date('Y-m-d', strtotime('-18 year')) && !$given)
        fail('guardian_needed', 'This person is under 18. Confirm that you are their parent or legal guardian to keep their details.', 422);
}
