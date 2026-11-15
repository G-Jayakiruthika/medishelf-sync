#!/usr/bin/env bash
# Makes a throwaway pharmacy-platform database and config.php for CI (never for a real install).
set -euo pipefail
cd "$(dirname "$0")/.."
: "${DB_HOST:=127.0.0.1}" "${DB_USER:=root}" "${DB_PASS:=root}"
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS ab_pharmacy_ci CHARACTER SET utf8mb4"
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" ab_pharmacy_ci < core/database/schema.sql
KEY=$(php -r 'echo base64_encode(random_bytes(32));')
sed -e "s|'db_host'    => 'localhost'|'db_host'    => '$DB_HOST'|" -e "s|'aushadhbox_pharmacy'|'ab_pharmacy_ci'|" \
    -e "s|'pharmacy_user'|'$DB_USER'|" -e "s|'change-me'|'$DB_PASS'|" -e "s|'data_key'   => ''|'data_key'   => '$KEY'|" \
    core/config.example.php > core/config.php
echo "pharmacy platform test database ready"
