<?php
/* Pricing page (all languages). Comparison table and billing FAQ are in English for accuracy. */
$p = prices();
$rows = [
    ['Family members tracked', '4', '15'],
    ['Medicines in the cabinet', '30', 'Unlimited (fair use)'],
    ['Accounts sharing one cabinet', '1', 'Up to 6'],
    ['Scan strips, bottles and QR codes', 'Yes', 'Yes'],
    ['Expiry alerts (app and phone notification)', 'Yes', 'Yes'],
    ['Voice assistant in 10 languages', 'Yes', 'Yes'],
    ['Jan Aushadhi generic finder', 'Yes', 'Yes'],
    ['Weekly expiry e-mail', '—', 'Yes'],
    ['Printable medicine report for your doctor', '—', 'Yes'],
    ['Export and delete your data any time', 'Yes', 'Yes'],
];
$billingFaq = [
    ['How do I pay?', 'Payments are handled by Razorpay. You can pay with UPI, debit or credit card, net banking or wallets. We never see or store your card details.'],
    ['Does Premium renew automatically?', 'No. You pay for one month or one year at a time. We e-mail you a reminder before it ends, and you choose whether to renew.'],
    ['Can I get a refund?', 'Yes. If you are not happy, write to us within 7 days of paying and we will refund you in full. See the refund policy for details.'],
    ['What happens when Premium ends?', 'Nothing is deleted. Your account goes back to the Free plan. If you have more than the Free limits, you can still see everything, but you cannot add more until you remove some or renew.'],
    ['Do I get a GST invoice?', 'Yes, a receipt is e-mailed after every payment. Write to us if you need a GST invoice with your business details.'],
];
$ld = [
    ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => site_name() . ' Family Premium', 'description' => 'Family medicine cabinet with expiry alerts, sharing and reports.',
     'brand' => ['@type' => 'Brand', 'name' => site_name()],
     'offers' => [['@type' => 'Offer', 'price' => (string) $p['month'], 'priceCurrency' => 'INR', 'availability' => 'https://schema.org/InStock', 'url' => base_url() . '/pricing'],
                  ['@type' => 'Offer', 'price' => (string) $p['year'], 'priceCurrency' => 'INR', 'availability' => 'https://schema.org/InStock', 'url' => base_url() . '/pricing']]],
    ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $billingFaq)],
];
render('pricing', ['p' => $p, 'rows' => $rows, 'billingFaq' => $billingFaq], [
    'title' => st('navPricing') . ' · ' . site_name() . ' — Free and Family Premium',
    'description' => st('pricingSub') . ' Family Premium ' . money($p['month']) . '/month or ' . money($p['year']) . '/year.',
    'canonical' => base_url() . url('pricing'), 'alternates' => true, 'page' => 'pricing', 'nav' => 'navPricing', 'jsonld' => $ld,
]);
