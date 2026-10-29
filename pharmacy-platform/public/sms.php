<?php
/* Webhook for the SMS / telephony provider (MSG91, Exotel, Gupshup, Textlocal...). Set the provider's
   "incoming SMS" and "missed call" URLs to:
     https://<platform>/sms.php?key=<sms.webhook_key>&type=sms      (fields: from, text)
     https://<platform>/sms.php?key=<sms.webhook_key>&type=missed&shop=<shop code>   (field: from)
   Field names differ between providers; the common ones are accepted below. */
declare(strict_types=1);
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/sms.php';
header('X-Content-Type-Options: nosniff');
$cfg = (array) (config('sms') ?? []);
if (empty($cfg['webhook_key']) || !hash_equals((string) $cfg['webhook_key'], (string) ($_GET['key'] ?? ''))) fail('unauthorised', 'Bad key.', 401);
$in = array_merge($_GET, $_POST, input());
$from = (string) ($in['from'] ?? $in['From'] ?? $in['mobile'] ?? $in['sender'] ?? $in['CallFrom'] ?? '');
switch ((string) ($_GET['type'] ?? 'sms')) {
case 'sms':    json_out(sms_incoming($from, (string) ($in['text'] ?? $in['message'] ?? $in['Body'] ?? $in['content'] ?? '')));
case 'missed': json_out(missed_call($from, (string) ($_GET['shop'] ?? '')));
default: fail('unknown_type', 'Unknown type.', 404);
}
