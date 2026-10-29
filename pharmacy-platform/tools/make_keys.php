<?php
/* Prints fresh secrets for config.php: the data encryption key and a pair of partner secrets for the two apps. */
echo "'data_key' => '" . base64_encode(random_bytes(32)) . "',\n";
$a = bin2hex(random_bytes(32)); $b = bin2hex(random_bytes(32));
echo "\n// Platform config.php\n'family_app'   => ['url' => 'https://aushadhbox.in', 'key_id' => 'ph1', 'secret' => '$a'],\n'partner_keys' => ['fam1' => '$b'],\n";
echo "\n// Family app (public_html/core/config.php)\n'pharmacy_network' => ['url' => 'https://pharmacy.example.in', 'key_id' => 'fam1', 'secret' => '$b'],\n'partner_keys' => ['ph1' => '$a'],\n";
