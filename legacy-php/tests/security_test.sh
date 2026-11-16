#!/usr/bin/env bash
# Security tests: tries to break in, and checks that each protection actually holds. Run against a TEST install.
#   DB_CMD="mysql -h127.0.0.1 -uroot -proot aushadhbox" bash tests/security_test.sh http://127.0.0.1:8080 http://127.0.0.1:8090
# The second address (pharmacy network) is optional; its checks are skipped when it is not running.
R="${1:-http://127.0.0.1:8080}"; P="${2:-http://127.0.0.1:8090}"; B="$R/api.php"
DB_CMD="${DB_CMD:-mysql -h127.0.0.1 -uroot -proot aushadhbox}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T=$(mktemp -d); PASS=0; FAIL=0; SKIP=0; S=$RANDOM$RANDOM
sql() { $DB_CMD -N -B -e "SET time_zone = '+05:30'; $1" 2>/dev/null; }
csrf() { curl -s -b "$T/$1" -c "$T/$1" "$B?action=session" | sed -E 's/.*"csrf":"([a-f0-9]+)".*/\1/'; }
post() { local c; c=$(csrf "$1"); curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -H "Origin: $R" -X POST "$B?action=$2" -d "$3"; }
get() { curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" "$B?action=$2"; }
ok() { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1 ${2:-}"; }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "(expected $3, got $2) $(head -c 160 "$T/out")"; fi; }
has() { if grep -qi -- "$2" "$3"; then ok "$1"; else bad "$1" "(missing: $2)"; fi; }
hasnt() { if grep -qi -- "$2" "$3"; then bad "$1" "(found: $2)"; else ok "$1"; fi; }
sql "DELETE FROM login_attempts" >/dev/null
U="sec$S@test.in"; V="sec2$S@test.in"; PW="a long and quiet river"

echo "Security headers (pages and API)"
curl -s -D "$T/h1" -o /dev/null "$R/"; curl -s -D "$T/h2" -o /dev/null "$B?action=session"
for h in "content-security-policy: default-src 'self'" "x-frame-options: deny" "x-content-type-options: nosniff" "referrer-policy:" "permissions-policy:" "cross-origin-opener-policy: same-origin"; do
  has "page sends $h" "$h" "$T/h1"; has "API sends $h" "$h" "$T/h2"; done
hasnt "PHP version not announced (no X-Powered-By)" "x-powered-by" "$T/h2"
has "CSP forbids framing (frame-ancestors 'none')" "frame-ancestors 'none'" "$T/h1"
grep -q 'Strict-Transport-Security "max-age=31536000; includeSubDomains"' "$ROOT/public_html/.htaccess" && ok "HSTS set for HTTPS (.htaccess)" || bad "HSTS set for HTTPS (.htaccess)"
grep -q 'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} \[L,R=301\]' "$ROOT/public_html/.htaccess" && ok "HTTP is redirected to HTTPS (.htaccess)" || bad "HTTP is redirected to HTTPS"
A=$(grep -o 'Header always set Content-Security-Policy "[^"]*"' "$ROOT/public_html/.htaccess" | sed 's/.*"\(.*\)"/\1/'); Bp=$(grep -o "header(\"Content-Security-Policy: [^\"]*\")" "$ROOT/public_html/core/bootstrap.php" | sed 's/header("Content-Security-Policy: \(.*\)")/\1/')
[ -n "$A" ] && [ "$A" = "$Bp" ] && ok "the PHP and .htaccess security policies are the same" || bad "the PHP and .htaccess security policies differ"

echo "CORS (other websites cannot call the API)"
curl -s -D "$T/h3" -o /dev/null -H "Origin: https://evil.example" "$B?action=session"
hasnt "no Access-Control-Allow-Origin for another site" "access-control-allow-origin" "$T/h3"
curl -s -D "$T/h4" -o /dev/null -X OPTIONS -H "Origin: https://evil.example" -H "Access-Control-Request-Method: POST" "$B?action=login"
hasnt "preflight from another site is not allowed" "access-control-allow" "$T/h4"

echo "Sign-up rules"
expect "under-18 / no age confirmation refused" "$(post x signup "{\"name\":\"X\",\"email\":\"x$S@test.in\",\"password\":\"$PW\",\"consent\":true}")" 400
expect "bot (hidden field filled) refused" "$(post x signup "{\"name\":\"X\",\"email\":\"x$S@test.in\",\"password\":\"$PW\",\"consent\":true,\"adult\":true,\"website\":\"http://spam\"}")" 400
expect "a common password refused" "$(post x signup "{\"name\":\"X\",\"email\":\"x$S@test.in\",\"password\":\"password123\",\"consent\":true,\"adult\":true}")" 400
expect "a 200-character password refused (hashing cost cap)" "$(post x signup "{\"name\":\"X\",\"email\":\"x$S@test.in\",\"password\":\"$(printf 'a%.0s' $(seq 1 200))\",\"consent\":true,\"adult\":true}")" 400
expect "mass assignment: sign-up trying to be admin and Premium" "$(post a signup "{\"name\":\"Sec\",\"email\":\"$U\",\"password\":\"$PW\",\"consent\":true,\"adult\":true,\"role\":\"admin\",\"plan\":\"premium\",\"plan_until\":\"2099-01-01\",\"totp_on\":1}")" 200
[ "$(sql "SELECT CONCAT(role,'/',plan,'/',IFNULL(plan_until,'-'),'/',totp_on) FROM users WHERE email='$U'")" = "user/free/-/0" ] && ok "  … but stays an ordinary free user" || bad "mass assignment changed role or plan: $(sql "SELECT role, plan FROM users WHERE email='$U'")"
expect "second account" "$(post b signup "{\"name\":\"Sec2\",\"email\":\"$V\",\"password\":\"$PW\",\"consent\":true,\"adult\":true}")" 200
case "$(sql "SELECT LEFT(password_hash, 10) FROM users WHERE email='$U'")" in '$argon2id$') ok "password stored as an Argon2id hash";; *) bad "password not Argon2id";; esac
[ "$(sql "SELECT COUNT(*) FROM consent_records c JOIN users u ON u.id=c.user_id WHERE u.email='$U' AND c.purpose IN ('privacy_notice','adult_18')")" = "2" ] && ok "consent to the notice and 18+ recorded" || bad "consent records missing"

echo "Sessions and cookies"
curl -s -D "$T/h5" -o /dev/null -c "$T/cj" "$B?action=session"
grep -i "^set-cookie: msync=" "$T/h5" | grep -qi "httponly" && ok "session cookie is HttpOnly (scripts cannot read it)" || bad "session cookie not HttpOnly"
grep -i "^set-cookie: msync=" "$T/h5" | grep -qi "samesite=lax" && ok "session cookie is SameSite" || bad "session cookie not SameSite"
grep -q "'secure' => is_https()" "$ROOT/public_html/core/bootstrap.php" && ok "session cookie is Secure on HTTPS" || bad "Secure flag not set on HTTPS"
expect "CSRF: a change without the token is refused" "$(curl -s -o "$T/out" -w "%{http_code}" -b "$T/a" -H "Content-Type: application/json" -X POST "$B?action=settings_save" -d '{"name":"x"}')" 419
c=$(csrf a); expect "cross-site request with a stolen token is refused (Origin check)" "$(curl -s -o "$T/out" -w "%{http_code}" -b "$T/a" -c "$T/a" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -H "Origin: https://evil.example" -X POST "$B?action=settings_save" -d '{"name":"Hacked"}')" 403
[ "$(sql "SELECT COUNT(*) FROM security_events WHERE kind='bad_origin'")" -ge 1 ] && ok "blocked cross-site request logged as a security event" || bad "bad_origin not logged"

echo "Sign-in"
expect "wrong password refused" "$(post z login "{\"email\":\"$U\",\"password\":\"nope nope nope\"}")" 401; M1=$(head -c 200 "$T/out")
expect "unknown e-mail refused" "$(post z login "{\"email\":\"nobody$S@test.in\",\"password\":\"nope nope nope\"}")" 401; M2=$(head -c 200 "$T/out")
[ "$M1" = "$M2" ] && ok "same answer for a wrong password and an unknown e-mail (no account guessing)" || bad "different answers reveal which e-mails exist"
t1=$(curl -s -o /dev/null -w "%{time_total}" -H "Content-Type: application/json" -H "X-CSRF-Token: $(csrf t)" -H "Origin: $R" -b "$T/t" -c "$T/t" -X POST "$B?action=login" -d "{\"email\":\"$U\",\"password\":\"wrong wrong\"}")
t2=$(curl -s -o /dev/null -w "%{time_total}" -H "Content-Type: application/json" -H "X-CSRF-Token: $(csrf t)" -H "Origin: $R" -b "$T/t" -c "$T/t" -X POST "$B?action=login" -d "{\"email\":\"none$S@test.in\",\"password\":\"wrong wrong\"}")
python3 -c "import sys; a,b=float('$t1' or 0),float('$t2' or 0); sys.exit(0 if max(a,b) < 3*max(0.02,min(a,b)) else 1)" && ok "similar response time for known and unknown e-mails ($t1 s / $t2 s)" || bad "timing differs a lot ($t1 s vs $t2 s)"
for i in 1 2 3 4 5 6; do post z login "{\"email\":\"$U\",\"password\":\"bad $i bad\"}" >/dev/null; done
expect "password guessing is stopped (rate limit)" "$(post z login "{\"email\":\"$U\",\"password\":\"bad 9 bad\"}")" 429
[ "$(sql "SELECT COUNT(*) FROM security_events WHERE kind='login_fail' AND detail='$U'")" -ge 3 ] && ok "failed sign-ins logged as security events" || bad "login_fail not logged"
sql "DELETE FROM login_attempts" >/dev/null
expect "right password signs in" "$(post a login "{\"email\":\"$U\",\"password\":\"$PW\"}")" 200
expect "second device signs in" "$(post a2 login "{\"email\":\"$U\",\"password\":\"$PW\"}")" 200
expect "password change" "$(post a password_change "{\"current\":\"$PW\",\"new\":\"$PW and more\"}")" 200
expect "  … signs out the other device" "$(get a2 bootstrap)" 401
expect "  … this device stays signed in" "$(get a bootstrap)" 200
PW="$PW and more"

echo "Password reset links"
UID_A=$(sql "SELECT id FROM users WHERE email='$U'")
sql "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES ($UID_A, SHA2('expiredtoken$S',256), NOW() - INTERVAL 1 MINUTE), ($UID_A, SHA2('goodtoken$S',256), NOW() + INTERVAL 1 HOUR), ($UID_A, SHA2('othertoken$S',256), NOW() + INTERVAL 1 HOUR)"
expect "an expired reset link is refused" "$(post r reset "{\"token\":\"expiredtoken$S\",\"password\":\"a brand new sentence\"}")" 400
expect "a valid reset link works" "$(post r reset "{\"token\":\"goodtoken$S\",\"password\":\"a brand new sentence\"}")" 200
expect "  … and cannot be used twice" "$(post r reset "{\"token\":\"goodtoken$S\",\"password\":\"another new sentence\"}")" 400
expect "  … and other open links for the account are cancelled" "$(post r reset "{\"token\":\"othertoken$S\",\"password\":\"another new sentence\"}")" 400
expect "  … and devices signed in before are signed out" "$(get a bootstrap)" 401
PW="a brand new sentence"; post a login "{\"email\":\"$U\",\"password\":\"$PW\"}" >/dev/null

echo "Admin routes"
for act in admin_stats admin_security admin_privacy admin_autopilot; do expect "$act: refused for an ordinary user" "$(get a $act)" 403; expect "$act: refused when signed out" "$(get nobody $act)" 401; done
ADM="adm$S@test.in"; H=$(php -r 'echo password_hash("admin pass phrase", PASSWORD_ARGON2ID, ["memory_cost"=>19456,"time_cost"=>2,"threads"=>1]);')
sql "INSERT INTO users (name, email, password_hash, role, consent_version, consent_at, adult_confirmed_at) VALUES ('Admin', '$ADM', '$H', 'admin', 'x', NOW(), NOW())"
expect "admin signs in" "$(post adm login "{\"email\":\"$ADM\",\"password\":\"admin pass phrase\"}")" 200
expect "admin dashboard locked until two-factor is on" "$(get adm admin_stats)" 403
grep -q setup_2fa "$T/out" && ok "  … and says to set up two-factor" || bad "no setup_2fa message"
expect "two-factor set-up gives a secret" "$(post adm admin_2fa_setup '{}')" 200
SEC=$(sed -E 's/.*"secret":"([A-Z2-7]+)".*/\1/' "$T/out")
[ "$(sql "SELECT LEFT(totp_secret,3) FROM users WHERE email='$ADM'")" = "e1:" ] && ok "two-factor secret stored encrypted" || bad "totp secret not encrypted"
expect "a wrong code does not turn it on" "$(post adm admin_2fa_enable '{"code":"000000"}')" 422
CODE=$(cd "$ROOT/public_html" && php -r 'require "core/bootstrap.php"; echo totp($argv[1]);' "$SEC")
expect "the right code turns it on" "$(post adm admin_2fa_enable "{\"code\":\"$CODE\"}")" 200
expect "admin dashboard opens" "$(get adm admin_stats)" 200
expect "sign-in without the code is refused" "$(post adm2 login "{\"email\":\"$ADM\",\"password\":\"admin pass phrase\"}")" 401
grep -q need_code "$T/out" && ok "  … and asks for the code" || bad "no need_code"
expect "sign-in with a wrong code is refused" "$(post adm2 login "{\"email\":\"$ADM\",\"password\":\"admin pass phrase\",\"code\":\"123456\"}")" 401
CODE=$(cd "$ROOT/public_html" && php -r 'require "core/bootstrap.php"; echo totp($argv[1]);' "$SEC")
expect "sign-in with the right code works" "$(post adm2 login "{\"email\":\"$ADM\",\"password\":\"admin pass phrase\",\"code\":\"$CODE\"}")" 200
expect "security panel works" "$(get adm admin_security)" 200
grep -q '"checks"' "$T/out" && ok "  … with configuration self-checks" || bad "no checks"

echo "Other families' data (isolation)"
expect "A adds a medicine" "$(post a item_save '{"name":"Secret Medicine","exp_month":"2030-01","dose_times":"m"}')" 200
ITEM=$(sed -E 's/.*"id":([0-9]+).*/\1/' "$T/out")
expect "B cannot change A's medicine" "$(post b item_save "{\"id\":$ITEM,\"name\":\"Mine now\",\"exp_month\":\"2030-01\"}")" 404
expect "B cannot tick A's dose" "$(post b dose_toggle "{\"item_id\":$ITEM,\"slot\":\"m\",\"taken\":true}")" 404
get b bootstrap >/dev/null; hasnt "B's data contains nothing of A's" "Secret Medicine" "$T/out"
MID_A=$(sql "SELECT id FROM members WHERE user_id=$UID_A LIMIT 1")
expect "B cannot edit A's family member" "$(post b member_save "{\"id\":$MID_A,\"name\":\"Taken over\"}")" 404
expect "field tampering: a user_id in the request is ignored" "$(post b item_save '{"name":"Mine","exp_month":"2030-01","user_id":'"$UID_A"'}')" 200
[ "$(sql "SELECT COUNT(*) FROM cabinet_items WHERE user_id=$UID_A AND name='Mine'")" = "0" ] && ok "  … the medicine went to B, not A" || bad "user_id tampering worked"

echo "Injection and bad input"
code=$(curl -s -o "$T/out" -w "%{http_code}" "$R/medicines?q=%27%20OR%201%3D1%20--%20")
[ "$code" = "200" ] && ok "SQL text in a search is treated as text" || bad "search with SQL failed ($code)"
hasnt "  … no database error shown" "SQLSTATE\|syntax error\|PDOException" "$T/out"
expect "SQL text in the sign-in e-mail is harmless" "$(post z login '{"email":"x'"'"' OR '"'"'1'"'"'='"'"'1","password":"x"}')" 401
curl -s -o "$T/out" "$R/medicines?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E"
hasnt "a script in the search box is not echoed into the page (XSS)" "<script>alert(1)</script>" "$T/out"
expect "HTML in a name is stored as plain text" "$(post a settings_save '{"name":"<img src=x onerror=alert(1)>","language":"en"}')" 200
get a session >/dev/null; has "  … and returned as text for safe display" '<img src=x onerror=alert(1)>' "$T/out"
head -c 600000 /dev/zero | tr '\0' 'a' > "$T/big"; c=$(csrf a)
expect "a 600 KB request is refused (memory protection)" "$(curl -s -o "$T/out" -w "%{http_code}" -b "$T/a" -c "$T/a" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -H "Origin: $R" -X POST "$B?action=settings_save" --data-binary @"$T/big")" 413
expect "broken JSON gives a clean error" "$(curl -s -o "$T/out" -w "%{http_code}" -b "$T/a" -c "$T/a" -H "Content-Type: application/json" -H "X-CSRF-Token: $(csrf a)" -H "Origin: $R" -X POST "$B?action=item_save" -d '{bad json')" 400
hasnt "  … with no stack trace or file paths" "Stack trace\|/home/\|\.php on line" "$T/out"
expect "an unknown action gives a clean error" "$(get a no_such_action)" 404
hasnt "  … with no internals" "Stack trace\|Fatal\|Warning:" "$T/out"
expect "price tampering: Premium checkout ignores a price from the browser" "$(post a billing_order '{"period":"month","amount":1,"amount_paise":100}')" 200
AMT=$(sed -E 's/.*"amount":([0-9]+).*/\1/' "$T/out"); [ "${AMT:-0}" -gt 100 ] && ok "  … the server's price is used (₹$((AMT/100)))" || bad "price from the browser was used: $AMT"
c=$(csrf adm); expect "upload restriction: a binary file is refused by the medicine-list import" "$(curl -s -o "$T/out" -w "%{http_code}" -b "$T/adm" -c "$T/adm" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -H "Origin: $R" -X POST "$B?action=admin_import" -d '{"source":"x","content":"PK\u0000\u0003binary"}')" 400

echo "Encryption at rest and trimmed answers"
expect "A saves a health condition" "$(post a member_save "{\"id\":$MID_A,\"name\":\"Self\",\"conditions\":\"Diabetes type 2\",\"emergency_contact\":\"Ravi 9876543210\"}")" 200
[ "$(sql "SELECT LEFT(conditions,3) FROM members WHERE id=$MID_A")" = "e1:" ] && ok "health condition stored encrypted" || bad "condition stored in plain text"
[ "$(sql "SELECT LEFT(emergency_contact,3) FROM members WHERE id=$MID_A")" = "e1:" ] && ok "emergency contact stored encrypted" || bad "contact stored in plain text"
get a bootstrap >/dev/null; has "  … and shown decrypted to the family" "Diabetes type 2" "$T/out"
for w in password_hash totp_secret token_hash auth_epoch nominee_contact; do get a session >/dev/null; hasnt "session answer has no $w" "$w" "$T/out"; get a bootstrap >/dev/null; hasnt "bootstrap answer has no $w" "$w" "$T/out"; done

echo "Privacy rights"
expect "privacy overview" "$(get a privacy)" 200
python3 -c "import json,sys; d=json.load(open('$T/out')); sys.exit(0 if len(d['register'])>=14 and len(d['principles'])==11 else 1)" && ok "record of processing (14 kinds) and the 11 ISO/IEC 29100 principles" || bad "privacy overview incomplete"
expect "a request gets a reference" "$(post a privacy_request '{"kind":"access","details":"copy please"}')" 200
grep -q '"ref":"PR-' "$T/out" && ok "  … PR-number and a due date" || bad "no reference"
get b privacy >/dev/null; hasnt "B does not see A's requests" "copy please" "$T/out"
expect "nominee saved" "$(post a privacy_nominee '{"name":"Ravi","contact":"9876543210"}')" 200
[ "$(sql "SELECT LEFT(nominee_contact,3) FROM users WHERE id=$UID_A")" = "e1:" ] && ok "  … encrypted" || bad "nominee not encrypted"
KID=$(sql "INSERT INTO members (user_id, name, relation) VALUES ($UID_A, 'Kid', 'child'); SELECT LAST_INSERT_ID();")
expect "a child's birth date needs guardian consent" "$(post a member_save "{\"id\":$KID,\"name\":\"Kid\",\"birth_date\":\"$(date -d '-3 year' +%F)\"}")" 422
expect "  … accepted with it" "$(post a member_save "{\"id\":$KID,\"name\":\"Kid\",\"birth_date\":\"$(date -d '-3 year' +%F)\",\"guardian\":true}")" 200
expect "download my data" "$(get a export)" 200
has "  … includes the family" "Diabetes type 2" "$T/out"
QS=$(cd "$ROOT/public_html" && php -r 'require "core/bootstrap.php"; require "core/services.php"; echo parse_url(unsub_link((int) $argv[1], "news"), PHP_URL_QUERY);' "$UID_A"); URL="$R/unsubscribe?$QS"
expect "unsubscribe page opens without a sign-in" "$(curl -s -o "$T/out" -w "%{http_code}" "$URL")" 200
expect "a changed unsubscribe link is refused" "$(curl -s -o "$T/out" -w "%{http_code}" "${URL%t=*}t=00000000000000000000000000000000")" 400
sql "UPDATE users SET marketing_ok=1 WHERE id=$UID_A"
expect "one-click unsubscribe (POST from a mail app)" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST -d "List-Unsubscribe=One-Click" "$URL")" 200
[ "$(sql "SELECT marketing_ok FROM users WHERE id=$UID_A")" = "0" ] && ok "  … news e-mails turned off" || bad "unsubscribe did not work"
expect "delete account" "$(post a account_delete "{\"password\":\"$PW\"}")" 200
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='$U'")$(sql "SELECT COUNT(*) FROM members WHERE user_id=$UID_A")" = "00" ] && ok "  … the account and family are erased" || bad "data left after deletion"
[ "$(sql "SELECT COUNT(*) FROM privacy_requests WHERE user_ref=SHA2('$U',256)")" -ge 1 ] && ok "  … the request record is kept without the account (proof of handling)" || bad "request record lost"

echo "Files that must never be served"
for f in core/config.php core/config.example.php core/data.key core/database/schema.sql core/backups/ .git/HEAD .htaccess .env install.lock; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "$R/$f"); case "$code" in 403|404) ok "/$f is blocked ($code)";; *) bad "/$f is reachable ($code)";; esac; done
curl -s "$R/robots.txt" -o "$T/out"; has "robots.txt keeps search engines out of private areas" "Disallow: /app" "$T/out"

echo "Code and repository checks"
cd "$ROOT"
! grep -rnE "\bunserialize\s*\(|\beval\s*\(" --include=*.php public_html pharmacy-platform >/dev/null && ok "no unserialize() or eval() in the PHP code" || bad "unserialize/eval found"
! grep -rnE "(shell_exec|exec|system|passthru|proc_open|popen)\s*\(\s*\\\$_(GET|POST|REQUEST|COOKIE)" --include=*.php public_html pharmacy-platform >/dev/null && ok "no shell command built from request data" || bad "shell command from request data"
! grep -rnE "display_errors'?,\s*'?(1|on|true)" --include=*.php public_html pharmacy-platform >/dev/null && ok "display_errors is never switched on" || bad "display_errors turned on somewhere"
grep -q "'payments_demo' => false" public_html/core/config.example.php && grep -q "'dev_show_codes' => false" pharmacy-platform/core/config.example.php && ok "demo and debug switches off in the example configs" || bad "debug switch on in an example config"
[ -z "$(git ls-files | grep -E '(^|/)config\.php$|\.env$|data\.key$|\.pem$|backups/')" ] && ok "no secrets or backups tracked by git" || bad "a secret file is tracked by git"
if git log --all -p --no-color 2>/dev/null | grep -qE "rzp_(live|test)_[A-Za-z0-9]{10,}|sk-ant-[A-Za-z0-9_-]{20,}|sk-[A-Za-z0-9]{32,}|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{30,}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY"; then bad "a key-like secret is in the git history"; else ok "no API keys or private keys anywhere in the git history"; fi
[ ! -f package.json ] && [ ! -f composer.json ] && [ ! -f public_html/package.json ] && ok "no runtime package dependencies (no npm/composer supply chain)" || bad "unexpected package manifest"
cmp -s public_html/core/backup.php pharmacy-platform/core/backup.php && ok "both apps use the same backup code" || bad "backup code differs"

echo "Backups"
(cd "$ROOT/public_html" && php -r '
  require "core/bootstrap.php"; require "core/backup.php";
  $d = sys_get_temp_dir() . "/abx-bk-" . getmypid(); $f = backup_make(db(), $d, fam_data_key(), "test", 2);
  $raw = file_get_contents($f); echo str_contains($raw, "CREATE TABLE") ? "PLAIN\n" : "ENC\n";
  $x = $raw; $x[100] = chr(ord($x[100]) ^ 1); file_put_contents("$f.bad", $x);
  try { backup_restore(db(), "$f.bad", fam_data_key()); echo "TAMPER-OK\n"; } catch (RuntimeException $e) { echo "TAMPER-REFUSED\n"; }
  try { backup_restore(db(), $f, random_bytes(32)); echo "KEY-OK\n"; } catch (RuntimeException $e) { echo "KEY-REFUSED\n"; }
  array_map("unlink", glob("$d/*")); @unlink("$d/.htaccess"); @rmdir($d);') > "$T/bk" 2>&1
grep -q "^ENC" "$T/bk" && ok "backups are encrypted" || bad "backup readable: $(head -c 100 "$T/bk")"
grep -q "TAMPER-REFUSED" "$T/bk" && ok "a changed backup is refused" || bad "tampered backup accepted"
grep -q "KEY-REFUSED" "$T/bk" && ok "a backup cannot be read without the key" || bad "backup read with a wrong key"

if curl -s -o /dev/null "$P/api.php?action=session" 2>/dev/null; then
  echo "Pharmacy network"
  PB="$P/api.php"
  pc() { curl -s -b "$T/p$1" -c "$T/p$1" "$PB?action=session" | sed -E 's/.*"csrf":"([a-f0-9]+)".*/\1/'; }
  pp() { local c; c=$(pc "$1"); curl -s -o "$T/out" -w "%{http_code}" -b "$T/p$1" -c "$T/p$1" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -H "Origin: $P" -X POST "$PB?action=$2" -d "$3"; }
  pg() { curl -s -o "$T/out" -w "%{http_code}" -b "$T/p$1" -c "$T/p$1" "$PB?action=$2"; }
  curl -s -D "$T/h6" -o /dev/null "$PB?action=session"
  for h in "content-security-policy:" "x-frame-options: deny" "x-content-type-options: nosniff" "cross-origin-opener-policy"; do has "pharmacy API sends $h" "$h" "$T/h6"; done
  hasnt "pharmacy API does not announce PHP" "x-powered-by" "$T/h6"
  expect "cross-site request refused" "$(c=$(pc x); curl -s -o "$T/out" -w "%{http_code}" -b "$T/px" -c "$T/px" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -H "Origin: https://evil.example" -X POST "$PB?action=login" -d '{"id":"a","password":"b"}')" 403
  expect "pharmacy sign-up bot refused" "$(pp x signup '{"website":"spam","name":"Bot Medicals"}')" 400
  expect "Amman owner signs in" "$(pp am login '{"id":"owner@amman.test","password":"demo1234"}')" 200
  expect "Sri Sai owner signs in" "$(pp ss login '{"id":"owner@srisai.test","password":"demo1234"}')" 200
  # One of Amman's orders, opened by a different business (Sri Sai)
  pg am "orders&filter=all" >/dev/null; OID=$(grep -o '"id":[0-9]*' "$T/out" | head -1 | cut -d: -f2)
  if [ -n "$OID" ]; then expect "a shop cannot open another business's order (#$OID)" "$(pg ss "order_get&id=$OID")" 404; expect "  … while its own shop can" "$(pg am "order_get&id=$OID")" 200
  else SKIP=$((SKIP+1)); echo "  - no order to try"; fi
  pg am staff_list >/dev/null; hasnt "staff list has no password hashes" "password_hash" "$T/out"; hasnt "staff list has no two-factor secrets" "totp_secret" "$T/out"
  pp ca login '{"id":"9876500012","password":"demo1234"}' >/dev/null
  expect "cashier cannot see network fees" "$(pg ca fees)" 403
  for f in core/config.php ../core/config.php backups/ .htaccess .git/HEAD; do code=$(curl -s -o /dev/null -w "%{http_code}" "$P/$f"); case "$code" in 403|404) ok "pharmacy /$f is blocked ($code)";; *) bad "pharmacy /$f reachable ($code)";; esac; done
  curl -s "$P/privacy" -o "$T/out"; has "pharmacy privacy notice is published" "Data Processor" "$T/out"
  curl -s "$P/terms" -o "$T/out"; has "pharmacy terms are published" "never charge above MRP\|Never charge above MRP" "$T/out"
else
  echo "Pharmacy network: not running at $P, skipped"; SKIP=$((SKIP+1))
fi

sql "DELETE FROM users WHERE email IN ('$V','$ADM')" >/dev/null
echo
echo "$PASS passed, $FAIL failed, $SKIP skipped"
rm -rf "$T"
[ "$FAIL" = 0 ]
