<?php
/* AushadhBox Pharmacy Network settings. Copy to config.php (never commit config.php).
   Make keys with:  php tools/make_keys.php */
return [
    'app_url'    => 'https://pharmacy.example.in',   // this platform's own address (QR posters point here)
    'db_host'    => 'localhost',
    'db_port'    => 3306,
    'db_socket'  => null,                             // or a unix socket path
    'db_name'    => 'aushadhbox_pharmacy',             // a SEPARATE database from the family app
    'db_user'    => 'pharmacy_user',
    'db_pass'    => 'change-me',

    // Encrypts customers' phone numbers and addresses (32 random bytes, base64). Keep a safe copy: without it they can't be read.
    'data_key'   => '',

    // The family app (AushadhBox). Both sides hold the same secret for this key id.
    'family_app'   => ['url' => 'https://aushadhbox.in', 'key_id' => 'ph1', 'secret' => ''],
    // Keys the family app may use to call this platform (partner.php). Several can be active while rotating.
    'partner_keys' => ['fam1' => ''],

    // SMS / missed-call provider (DLT-registered templates, see core/sms.php). Leave 'url' empty to only log messages.
    'sms' => ['url' => '', 'auth' => '', 'sender' => 'ABOXRX', 'webhook_key' => '', 'templates' => [
        'order_new' => '', 'order_ready' => '', 'order_out' => '', 'khata_remind' => '', 'link_code' => '', 'sms_help' => '', 'sms_unknown' => '',
    ]],
    // Courier companies: API keys when you have business accounts (see core/delivery.php).
    'couriers' => ['porter' => '', 'shadowfax' => '', 'dunzo' => '', 'borzo' => '', 'indiapost' => ''],

    // Network fees (core/fees.php): commission % on orders the network brings (family app, passed-on orders), by area,
    // capped per order; 18% GST on the fee. Jan Aushadhi Kendras and shops marked fee_exempt pay nothing.
    'fees' => ['urban' => 2.0, 'semiurban' => 1.5, 'rural' => 1.0, 'cap' => 50.0, 'gst' => 18.0],
    'platform_upi' => '',        // the network's UPI ID, shown on each shop's monthly statement (e.g. aushadhbox@okaxis)

    'ip_header'      => '',      // 'HTTP_CF_CONNECTING_IP' behind Cloudflare
    'dev_show_codes' => false,   // test installs only: return SMS codes in API answers
];
