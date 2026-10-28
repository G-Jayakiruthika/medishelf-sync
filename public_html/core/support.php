<?php
/* Support autopilot: every contact message is sorted (category, priority, mood, reply target),
   gets a ticket number and an automatic acknowledgement, and a drafted reply waits for the admin.
   Rules work in English, Hindi and Tamil keywords; the optional AI writes a better draft. */
declare(strict_types=1);
require_once __DIR__ . '/ai.php';

const SUPPORT_RULES = [
    // category => [keywords, base priority]
    'medical' => [['side effect', 'can i take', 'safe to take', 'overdose', 'swallowed', 'pregnan', 'how many tablet', 'dose of', 'allerg', 'rash', 'दवा', 'खुराक', 'மருந்து', 'அளவு'], 'normal'],
    'privacy' => [['delete my', 'delete account', 'erase', 'my data', 'privacy', 'consent', 'dpdp', 'remove my', 'unsubscribe', 'personal data', 'डेटा', 'हटा', 'தரவு', 'நீக்க'], 'high'],
    'billing' => [['refund', 'charged', 'payment', 'paid', 'premium', 'razorpay', 'upi', 'invoice', 'gst', 'money', 'debited', '₹', 'पैसे', 'भुगतान', 'रिफंड', 'பணம்', 'கட்டணம்'], 'normal'],
    'bug' => [['not working', "doesn't work", 'does not work', 'error', 'crash', 'bug', 'blank', 'stuck', 'cannot', "can't", 'unable', 'problem', 'wrong', 'not showing', 'काम नहीं', 'வேலை செய்யவில்லை'], 'normal'],
    'account' => [['password', 'login', 'log in', 'sign in', 'otp', 'email change', 'locked', 'invite code', 'family code'], 'normal'],
    'feature' => [['please add', 'feature', 'would be nice', 'suggest', 'can you add', 'request', 'wish', 'idea'], 'low'],
];
const URGENT_WORDS = ['overdose', 'swallowed', 'child ate', 'chest pain', 'breathing', 'unconscious', 'emergency', 'urgent', 'immediately', 'debited twice', 'charged twice'];
const NEGATIVE_WORDS = ['angry', 'worst', 'useless', 'terrible', 'fraud', 'scam', 'cheat', 'disappointed', 'frustrat', 'annoy', 'bad', 'not happy', 'waste', 'horrible', '!!'];
const POSITIVE_WORDS = ['thank', 'love', 'great', 'awesome', 'helpful', 'nice', 'good app', 'excellent', 'amazing', 'धन्यवाद', 'நன்றி'];

function ticket_no(int $id): string { return 'AB-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }

function support_triage(string $topic, string $message): array
{
    $t = mb_strtolower($topic . ' ' . $message);
    $scores = [];
    $has = fn(string $w) => (bool) preg_match('/(^|[^\p{L}])' . preg_quote($w, '/') . '/u', $t);   // match at the start of a word ("rash" must not match "crash")
    foreach (SUPPORT_RULES as $cat => [$words]) foreach ($words as $w) if ($has($w)) $scores[$cat] = ($scores[$cat] ?? 0) + 1;
    $map = ['billing' => 'billing', 'payment' => 'billing', 'privacy' => 'privacy', 'bug' => 'bug', 'feature' => 'feature', 'help' => null];
    if (isset($map[$topic]) && $map[$topic]) $scores[$map[$topic]] = ($scores[$map[$topic]] ?? 0) + 2;
    arsort($scores);
    $cat = $scores ? (string) array_key_first($scores) : 'other';
    $prio = SUPPORT_RULES[$cat][1] ?? 'normal';
    $urgent = false; foreach (URGENT_WORDS as $w) if ($has($w)) $urgent = true;
    if ($urgent || ($cat === 'billing' && preg_match('/refund|charged|debited|failed/', $t))) $prio = 'high';
    $neg = 0; foreach (NEGATIVE_WORDS as $w) $neg += substr_count($t, $w);
    $pos = 0; foreach (POSITIVE_WORDS as $w) $pos += substr_count($t, $w);
    $sent = $neg > $pos ? 'negative' : ($pos > $neg ? 'positive' : 'neutral');
    if ($sent === 'negative' && $prio === 'low') $prio = 'normal';
    $hours = ['high' => 24, 'normal' => 72, 'low' => 168][$prio];
    if ($cat === 'privacy') $hours = min($hours, 72);
    return ['category' => $cat, 'priority' => $prio, 'sentiment' => $sent, 'urgent' => $urgent, 'hours' => $hours];
}

/* What we know about the sender, to make the draft specific (only shown to the admin). */
function support_context(string $email): array
{
    $u = q('SELECT id, name, plan, plan_until, created_at, household_id FROM users WHERE email = ?', [strtolower($email)])->fetch();
    if (!$u) return ['account' => false];
    $owner = (int) ($u['household_id'] ?: $u['id']);
    $pay = q('SELECT status, period, amount_paise/100 amount, created_at, rp_order_id FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$owner])->fetch();
    return ['account' => true, 'plan' => $u['plan'] === 'premium' && $u['plan_until'] >= date('Y-m-d') ? 'premium until ' . $u['plan_until'] : 'free',
            'joined' => substr($u['created_at'], 0, 10), 'lastPayment' => $pay ? "{$pay['status']} ₹{$pay['amount']} ({$pay['period']}) on " . substr($pay['created_at'], 0, 10) : 'none',
            'medicines' => (int) q('SELECT COUNT(*) FROM cabinet_items WHERE user_id = ?', [$owner])->fetchColumn()];
}

function support_template(string $cat, string $name, array $ctx, bool $urgent): string
{
    $site = site_title(); $first = explode(' ', trim($name))[0] ?: 'there';
    $base = rtrim((string) config('app_url'), '/');
    $body = match ($cat) {
        'medical' => ($urgent ? "If someone has taken too much of a medicine or feels very unwell, please call 112 or go to the nearest hospital now.\n\n" : '') .
            "Thank you for writing. $site helps you organise medicines and reminders, but we can't give medical advice for a particular person. Please ask your doctor or pharmacist about doses, side effects or combining medicines. The medicine pages on our site show general information and storage advice: $base/ingredients",
        'privacy' => "Thank you for your request about your personal data. You can download everything we hold (Account → Download my data) and delete your account and all its data at any time (Account → Delete my account). If you'd like us to do it for you, reply from this e-mail address to confirm and we'll complete it and confirm by e-mail." .
            ($ctx['account'] ?? false ? '' : "\n\nWe couldn't find an account with this e-mail address. If you signed up with another address, please tell us which one."),
        'billing' => "Thank you for writing about your payment." . (($ctx['lastPayment'] ?? 'none') !== 'none' ? " We can see your latest payment: {$ctx['lastPayment']}." : '') .
            " Premium never renews by itself, so you are never charged without paying yourself. If money was taken but Premium did not start, it usually activates within a few minutes; if not, reply with the Razorpay payment ID from your SMS or e-mail and we'll fix it the same day. Our refund policy: $base/refund-policy",
        'bug' => "Thank you for telling us, and sorry for the trouble. Could you tell us your phone model, browser (Chrome, Safari…) and the steps that led to the problem? A screenshot helps a lot. Meanwhile, closing and reopening the app, or updating Chrome, often fixes it.",
        'account' => "Thank you for writing. To reset your password, use \"Forgot password?\" on the sign-in screen: $base/app#signin. Family invite codes work once and expire after 7 days; the family owner can create a new one under Account → Family sharing.",
        'feature' => "Thank you for the idea! We read every suggestion and use them to decide what to build next. We'll let you know if it's added.",
        default => "Thank you for your message. We'll get back to you soon.",
    };
    return "Hello $first,\n\n$body\n\nWarm regards,\n$site support";
}

/* Sorts one stored message, drafts a reply, and (once) sends the acknowledgement. */
function support_process(int $id, bool $acknowledge = true): ?array
{
    $m = q('SELECT * FROM contact_messages WHERE id = ?', [$id])->fetch();
    if (!$m) return null;
    $tr = support_triage((string) $m['topic'], (string) $m['message']);
    $ctx = support_context((string) $m['email']);
    $draft = support_template($tr['category'], (string) $m['name'], $ctx, $tr['urgent']);
    $ai = ai_text('support_draft', 'You draft a short, warm reply to a customer of ' . site_title() . ', a family medicine-cabinet and reminder app in India (free plan, Family Premium paid with Razorpay, never auto-renews; data can be downloaded or deleted in Account). Rules: never give medical advice or doses; for anything medical say to ask a doctor or pharmacist, and for emergencies call 112. Never promise refunds or features; say the team will check. Reply in the same language as the customer. Under 140 words. Sign as "' . site_title() . ' support".',
        "Customer name: {$m['name']}\nTopic: {$m['topic']}\nCategory (auto): {$tr['category']}\nWhat we know: " . json_encode($ctx) . "\nMessage:\n{$m['message']}\n\nA safe template you may improve:\n$draft", 400);
    q('UPDATE contact_messages SET category = ?, priority = ?, sentiment = ?, due_at = created_at + INTERVAL ? HOUR, suggested = ? WHERE id = ?',
      [$tr['category'], $tr['priority'], $tr['sentiment'], $tr['hours'], $ai ?: $draft, $id]);
    if ($acknowledge && state_get('ack_on', '1') === '1' && mail_enabled()) {
        $when = $tr['hours'] <= 24 ? 'within 1 day' : ($tr['hours'] <= 72 ? 'within 3 days' : 'within a week');
        send_mail((string) $m['email'], 'We got your message · ' . ticket_no($id) . ' · ' . site_title(),
            "Hello {$m['name']},\n\nThank you for writing to " . site_title() . ". Your ticket number is " . ticket_no($id) . ".\nWe usually reply $when." .
            ($tr['urgent'] && $tr['category'] === 'medical' ? "\n\nIf this is a medical emergency, please call 112 or go to the nearest hospital now." : '') .
            "\n\nYour message:\n" . mb_substr((string) $m['message'], 0, 1500) . "\n\n" . site_title() . " support");
    }
    return $tr + ['ticket' => ticket_no($id), 'ai' => (bool) $ai];
}

/* Messages that arrived before the autopilot existed, or when processing failed. */
function support_backfill(int $max = 30): int
{
    $n = 0;
    foreach (q('SELECT id FROM contact_messages WHERE category IS NULL ORDER BY id DESC LIMIT ' . $max)->fetchAll() as $r) { support_process((int) $r['id'], false); $n++; }
    return $n;
}
