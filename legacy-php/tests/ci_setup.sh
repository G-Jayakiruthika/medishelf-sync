#!/usr/bin/env bash
# Prepares a throw-away test copy of AushadhBox: writes a test config.php, starts PHP's built-in server
# and runs the installer. Used by the GitHub test workflow; also works on a laptop with PHP and MySQL.
#   DB_HOST=127.0.0.1 DB_NAME=aushadhbox DB_USER=root DB_PASS=root bash tests/ci_setup.sh
set -euo pipefail
cd "$(dirname "$0")/../public_html"

DB_HOST="${DB_HOST:-127.0.0.1}"; DB_NAME="${DB_NAME:-aushadhbox}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-root}"
PORT="${PORT:-8080}"
SETUP_KEY="ci-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"

cat > core/config.php <<PHP
<?php
// TEST configuration written by tests/ci_setup.sh. Never used on the live site.
return [
  'db_host' => '$DB_HOST', 'db_port' => 3306, 'db_name' => '$DB_NAME', 'db_user' => '$DB_USER', 'db_pass' => '$DB_PASS',
  'app_url' => '', 'site_name' => 'AushadhBox', 'business_name' => 'Test Business', 'business_address' => 'Test City, India',
  'support_email' => 'support@example.in', 'support_phone' => '', 'grievance_officer' => 'Test Officer',
  'price_month' => 49, 'price_year' => 399,
  'razorpay_key_id' => '', 'razorpay_key_secret' => '', 'razorpay_webhook_secret' => 'ci-webhook-secret', 'payments_demo' => true,
  'ga4_id' => '', 'google_site_verification' => '', 'cron_key' => '$SETUP_KEY', 'ip_header' => '', 'consent_version' => '2026.2',
  'smtp_host' => '', 'smtp_port' => 587, 'smtp_user' => '', 'smtp_pass' => '', 'mail_from' => '', 'mail_from_name' => 'AushadhBox',
  'vapid_public' => '', 'vapid_private' => '', 'vapid_subject' => '', 'bhashini_user_id' => '', 'bhashini_api_key' => '',
];
PHP
rm -f core/install.lock

php -S "127.0.0.1:$PORT" index.php > /tmp/aushadhbox-server.log 2>&1 &
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/robots.txt" && break; sleep 1; done

out=$(curl -s -X POST "http://127.0.0.1:$PORT/install.php" \
  --data-urlencode "setup_key=$SETUP_KEY" --data-urlencode "name=CI Admin" \
  --data-urlencode "email=admin@example.in" --data-urlencode "password=CiAdminPass123")
if ! grep -q "Installation is complete" <<<"$out"; then
  echo "Installer failed:"; sed -e 's/<[^>]*>/ /g' <<<"$out" | tr -s ' \n' | head -c 1500; echo; cat /tmp/aushadhbox-server.log; exit 1
fi
sed -e 's/<[^>]*>/\n/g' <<<"$out" | grep "✔" || true
echo "Test site ready at http://127.0.0.1:$PORT"
