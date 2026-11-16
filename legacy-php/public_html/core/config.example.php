<?php
/*
 * AushadhBox configuration.
 * Copy this file to config.php and fill in your own values.
 * Never upload config.php to GitHub (it is listed in .gitignore).
 */
return [
    // Database (from your hosting control panel → MySQL Databases)
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'aushadhbox',
    'db_user' => 'aushadhbox_user',
    'db_pass' => 'change-me',

    // Public address of the site, without a trailing slash (your domain, with https)
    'app_url' => 'https://your-site.example.com',

    // ---- Your business details (shown on Contact, Privacy, Terms, Refund pages and invoices) ----
    'site_name'        => 'AushadhBox',
    'business_name'    => 'Your Name (Proprietor)',     // legal name used with Razorpay
    'business_address' => 'Street, City, Tamil Nadu, PIN, India',
    'support_email'    => 'support@your-site.example.com',
    'support_phone'    => '',                          // optional, e.g. +91 98xxxxxxxx
    'grievance_officer'=> 'Your Name',
    'privacy_email'    => '',                          // where privacy requests go (defaults to support_email)
    'gstin'            => '',                          // optional: shown in the footer and on receipts when registered
    'business_reg'     => '',                          // optional: Udyam / CIN / shop registration number

    // ---- Premium plan prices in rupees ----
    'price_month' => 49,
    'price_year'  => 399,

    // ---- Razorpay (Dashboard → Account & Settings → API Keys). Leave empty to hide paid plans. ----
    'razorpay_key_id'         => '',
    'razorpay_key_secret'     => '',
    'razorpay_webhook_secret' => '',   // Dashboard → Webhooks → secret for /webhook.php (events: payment.captured, order.paid, payment.failed)
    // Demo mode for testing without Razorpay: pretends every payment succeeds. NEVER true on the live site.
    'payments_demo' => false,

    // ---- Optional: Google Analytics 4 measurement ID (loads ONLY after the visitor accepts analytics cookies) ----
    'ga4_id' => '',
    // Google Search Console verification code (the content="..." value of their meta tag), optional
    'google_site_verification' => '',

    // A long random string. Used to protect the cron URL.
    // Generate one with: php -r "echo bin2hex(random_bytes(24));"
    'cron_key' => 'change-me-to-a-long-random-string',

    // If your host puts a proxy/CDN in front (Cloudflare etc.), name the header with the real visitor IP,
    // e.g. 'HTTP_CF_CONNECTING_IP'. Leave empty on normal hosting.
    'ip_header' => '',

    // Version of your privacy policy. Change it when the policy changes; users are asked to agree again.
    'consent_version' => '2026.2',

    // ---- Optional: e-mail for password reset (any SMTP service: Brevo, Zoho, Gmail app password) ----
    'smtp_host' => '',          // e.g. smtp-relay.brevo.com
    'smtp_port' => 587,         // 587 = STARTTLS, 465 = SSL
    'smtp_user' => '',
    'smtp_pass' => '',
    'mail_from' => '',          // e.g. no-reply@your-site.example.com
    'mail_from_name' => 'AushadhBox',

    // ---- Optional: background push reminders (create keys with: php tools/make_vapid_keys.php) ----
    'vapid_public'  => '',
    'vapid_private' => '',      // PEM text, keep secret
    'vapid_subject' => 'mailto:support@your-site.example.com',

    // ---- Optional: Bhashini (Government of India) voices for all Indian languages ----
    // Register at https://bhashini.gov.in/ulca and copy your User ID and API key.
    'bhashini_user_id' => '',
    'bhashini_api_key' => '',

    // ---- Optional: AI (smarter support replies, "ask your data" answers, the in-app assistant) ----
    // Everything works without it (rules and templates). With a key, answers are written by the AI,
    // always from numbers and records the site computed itself.
    // 'anthropic' = Claude API (console.anthropic.com), 'openai' = any OpenAI-compatible endpoint.
    'ai_provider' => '',                 // '' (off), 'anthropic' or 'openai'
    'ai_api_key'  => '',
    'ai_model'    => 'claude-haiku-5-5', // a small, low-cost model is enough
    'ai_base_url' => '',                 // only for 'openai': e.g. https://api.openai.com/v1
    'ai_monthly_limit' => 3000,          // stop calling the AI after this many calls in a month

    // ---- Optional: IndexNow (tells Bing and other search engines about new pages straight away) ----
    'indexnow_on' => true,

    // ---- Optional: phones for family members without a smartphone (core/careplus.php) ----
    // Missed calls (like 99DOTS in India's TB programme): rent two numbers from Exotel, Knowlarity or MyOperator and point
    // their missed-call webhook at https://yourdomain/telephony.php?key=SECRET. The caller pays nothing.
    'telephony' => ['secret' => '', 'verify_number' => '', 'dose_number' => ''],
    // SMS dose reminders (MSG91 Flow API; DLT-registered templates are required in India). Without a key, messages are only logged.
    'sms' => ['key' => '', 'templates' => [
        'dose' => ['id' => '', 'text' => 'AushadhBox: {name}, time for your {time} medicines: {meds}. Missed call to confirm.'],
    ]],
];
