<?php
/* Creates the key pair for push notifications. Run once:  php tools/make_vapid_keys.php
   Paste the two values into core/config.php (vapid_public and vapid_private). */
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
openssl_pkey_export($key, $pem);
$d = openssl_pkey_get_details($key)['ec'];
$raw = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
$pub = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
echo "'vapid_public'  => '$pub',\n";
echo "'vapid_private' => <<<PEM\n" . trim($pem) . "\nPEM,\n";
