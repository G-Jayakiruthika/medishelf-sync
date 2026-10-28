#!/usr/bin/env bash
# API and security tests. Run against a TEST install (it creates accounts):
#   bash tests/api_test.sh http://127.0.0.1:8080
# Needs payments_demo => true in config.php for the payment test.
B="${1:-http://127.0.0.1:8080}/api.php"
T=$(mktemp -d); PASS=0; FAIL=0; S=$RANDOM$RANDOM
csrf() { curl -s -b "$T/$1" -c "$T/$1" "$B?action=session" | sed -E 's/.*"csrf":"([a-f0-9]+)".*/\1/'; }
post() { local c; c=$(csrf "$1"); curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -X POST "$B?action=$2" -d "$3"; }
get() { curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" "$B?action=$2"; }
expect() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  ✓ $1"; else FAIL=$((FAIL+1)); echo "  ✗ $1 (expected $3, got $2) $(head -c 200 "$T/out")"; fi; }
body_has() { if grep -q "$2" "$T/out"; then PASS=$((PASS+1)); echo "  ✓ $1"; else FAIL=$((FAIL+1)); echo "  ✗ $1: $(head -c 200 "$T/out")"; fi; }

echo "Accounts"
expect "sign up A" "$(post a signup "{\"name\":\"<img src=x onerror=alert(1)>\",\"email\":\"a$S@test.in\",\"password\":\"secret123\",\"consent\":true}")" 200
expect "sign up needs consent" "$(post x signup "{\"name\":\"X\",\"email\":\"x$S@test.in\",\"password\":\"secret123\"}")" 400
expect "duplicate e-mail rejected" "$(post x signup "{\"name\":\"X\",\"email\":\"a$S@test.in\",\"password\":\"secret123\",\"consent\":true}")" 409
expect "sign up B" "$(post b signup "{\"name\":\"B\",\"email\":\"b$S@test.in\",\"password\":\"secret123\",\"consent\":true}")" 200
expect "wrong password" "$(post x login "{\"email\":\"a$S@test.in\",\"password\":\"nope\"}")" 401

echo "Security"
c=$(curl -s -o /dev/null -w "%{http_code}" -b "$T/a" -H "Content-Type: application/json" -X POST "$B?action=item_save" -d '{"name":"x","exp":"2027-01"}')
expect "POST without CSRF token is refused" "$c" 419
expect "not signed in" "$(get nobody bootstrap)" 401
expect "normal user cannot see admin stats" "$(get a admin_stats)" 403
post a item_save '{"name":"A private medicine","exp":"2027-01"}' >/dev/null; AID=$(sed -E 's/.*"item":\{"id":([0-9]+).*/\1/' "$T/out")
expect "B cannot edit A's medicine" "$(post b item_save "{\"id\":$AID,\"name\":\"hacked\",\"exp\":\"2027-01\"}")" 404
expect "B cannot delete A's medicine" "$(post b item_delete "{\"id\":$AID}")" 404
get a bootstrap >/dev/null; body_has "HTML in names is stored as text (escaped on display)" '"name":"<img src=x onerror=alert(1)>"'
expect "analytics refused without consent cookie" "$(post a track '{"ref":"0123456789abcdef0123456789abcdef","event":"pageview"}')" 200; body_has "track returns ok:false" '"ok":false'
expect "invalid consent ref rejected" "$(post a consent_log '{"ref":"xyz","analytics":1}')" 400

echo "Plans and payments"
for i in $(seq 2 30); do post a item_save "{\"name\":\"M$i\",\"exp\":\"2027-02\"}" >/dev/null; done
expect "Free plan stops at 30 medicines" "$(post a item_save '{"name":"M31","exp":"2027-02"}')" 402
expect "Invites need Premium" "$(post a invite_create '{}')" 402
expect "create order" "$(post a billing_order '{"period":"month"}')" 200
OID=$(sed -E 's/.*"order_id":"([^"]+)".*/\1/' "$T/out")
expect "B cannot confirm A's order" "$(post b billing_verify "{\"order_id\":\"$OID\"}")" 404
expect "A confirms (demo mode)" "$(post a billing_verify "{\"order_id\":\"$OID\"}")" 200; body_has "A is Premium" '"plan":"premium"'
expect "Premium can add more" "$(post a item_save '{"name":"M31","exp":"2027-02"}')" 200

echo "Family sharing"
expect "A creates invite" "$(post a invite_create '{}')" 200; CODE=$(sed -E 's/.*"code":"([A-Z0-9]+)".*/\1/' "$T/out")
expect "B joins" "$(post b invite_join "{\"code\":\"$CODE\"}")" 200
expect "code works only once" "$(post x invite_join "{\"code\":\"$CODE\"}")" 401
get b bootstrap >/dev/null; body_has "B sees A's medicines" 'A private medicine'
expect "B (member) cannot pay" "$(post b billing_order '{"period":"month"}')" 403
expect "B leaves" "$(post b household_leave '{}')" 200
get b bootstrap >/dev/null; if grep -q 'A private medicine' "$T/out"; then FAIL=$((FAIL+1)); echo "  ✗ B still sees A's data"; else PASS=$((PASS+1)); echo "  ✓ B no longer sees A's data"; fi

echo "Automations"
expect "medicine with stock count" "$(post a item_save '{"name":"Stock test","exp":"2027-05","times":"m,e","stock":10,"per_dose":2}')" 200
SID=$(sed -E 's/.*"item":\{"id":([0-9]+).*/\1/' "$T/out")
post a dose_toggle "{\"item_id\":$SID,\"slot\":\"m\",\"taken\":true}" >/dev/null; body_has "ticking a dose uses 2 tablets" '"stock":8'
post a dose_toggle "{\"item_id\":$SID,\"slot\":\"m\",\"taken\":true}" >/dev/null; body_has "ticking twice does not count twice" '"stock":8'
post a dose_toggle "{\"item_id\":$SID,\"slot\":\"m\",\"taken\":false}" >/dev/null; body_has "un-ticking puts them back" '"stock":10'
expect "course end date saved" "$(post a item_save '{"name":"Course test","exp":"2027-05","times":"m","course_end":"2026-01-31"}')" 200; body_has "course end returned" '"courseEnd":"2026-01-31"'
expect "automation settings" "$(post a automation_save '{"refill_on":1,"refill_days":7,"missed_on":0,"missed_after":30,"course_on":1,"cleanup_on":1}')" 200; body_has "settings stored" '"refillDays":7'
expect "calendar link" "$(post a calendar_link '{}')" 200
CAL=$(sed -E 's/.*"url":"([^"]+)".*/\1/' "$T/out" | sed 's#\\/#/#g')
if curl -s "$CAL" | grep -q "BEGIN:VCALENDAR"; then PASS=$((PASS+1)); echo "  ✓ calendar feed is valid iCalendar"; else FAIL=$((FAIL+1)); echo "  ✗ calendar feed"; fi
c=$(curl -s -o /dev/null -w "%{http_code}" "${CAL%/*}/00000000000000000000000000000000.ics"); expect "wrong calendar link is refused" "$c" 404

echo "Smart adding"
expect "medicine without expiry is allowed" "$(post a item_save '{"name":"No expiry yet","times":["m"]}')" 200; body_has "expiry left empty" '"exp":null'
expect "bad expiry still refused" "$(post a item_save '{"name":"Bad","exp":"2027-13"}')" 400
expect "match many names" "$(post a catalog_match_many '{"names":["Metformin 500 mg","Azithromycin 500"]}')" 200; body_has "usual schedule comes with the match" '"usualTimes":\["m","e"\]'
body_has "short course length comes with the match" '"courseDays":3'
expect "save a whole prescription" "$(post a items_bulk_save '{"items":[{"name":"Rx one","times":["m","e"],"stock":10,"course_end":"2099-01-01"},{"name":"Rx two","times":["m"],"stock":5,"exp":"2027-08","batch":"B12"}]}')" 200; body_has "both saved" '"name":"Rx two"'
expect "empty list refused" "$(post a items_bulk_save '{"items":[]}')" 400
expect "one tap ticks the whole morning" "$(post a dose_slot_taken '{"slot":"m"}')" 200; body_has "several doses ticked" '"ticked":[2-9]'
get a bootstrap >/dev/null; body_has "stock went down" '"name":"Rx one".*"stock":9'
expect "unknown slot refused" "$(post a dose_slot_taken '{"slot":"z"}')" 400

echo "Skip with a reason and weekly report"
expect "medicine for skip test" "$(post a item_save '{"name":"Skip out test","times":["e"],"stock":12}')" 200; K1=$(sed -E 's/.*"item":\{"id":([0-9]+).*/\1/' "$T/out")
expect "medicine for doctor-stop test" "$(post a item_save '{"name":"Skip doctor test","times":["e"]}')" 200; K2=$(sed -E 's/.*"item":\{"id":([0-9]+).*/\1/' "$T/out")
expect "skip needs a valid reason" "$(post a dose_skip "{\"slot\":\"e\",\"item_id\":$K1,\"reason\":\"lazy\"}")" 400
expect "skip: ran out" "$(post a dose_skip "{\"slot\":\"e\",\"item_id\":$K1,\"reason\":\"out\"}")" 200; body_has "ran out sets the tablet count to 0 (shopping list)" '"stock":0'
expect "skip: doctor stopped it" "$(post a dose_skip "{\"slot\":\"e\",\"item_id\":$K2,\"reason\":\"doctor\"}")" 200; body_has "reminders end today" "\"courseEnd\":\"$(TZ=Asia/Kolkata date +%F)\""
expect "B cannot skip A's medicine" "$(post b dose_skip "{\"slot\":\"e\",\"item_id\":$K1,\"reason\":\"fine\"}")" 404
post a dose_slot_taken '{"slot":"e"}' >/dev/null
get a bootstrap >/dev/null; body_has "a skip stays a skip after All taken" "\"$K1:e\":{\"status\":\"skipped\",\"reason\":\"out\""
expect "weekly report" "$(get a 'adherence&days=7')" 200; body_has "report has people" '"people"'

echo "Smart pillbox"
NOW=$(TZ=Asia/Kolkata date +%H%M); if [ "$NOW" -lt 0015 ]; then MT="00:00"; else MT=$(TZ=Asia/Kolkata date -d '-10 min' +%H:%M); fi
post a reminders_save "{\"enabled\":false,\"m\":\"$MT\",\"a\":\"13:30\",\"e\":\"19:30\",\"b\":\"22:00\"}" >/dev/null
expect "medicine in the box" "$(post a item_save '{"name":"Box medicine","times":["m"],"stock":5}')" 200
expect "add a pillbox" "$(post a device_add '{"name":"Test box","compartments":4}')" 200; body_has "pairing code shown" '"pairCode":"[0-9]\{6\}"'
DEV=$(sed -E 's/.*"device":\{"id":([0-9]+).*/\1/' "$T/out"); PC=$(sed -E 's/.*"pairCode":"([0-9]+)".*/\1/' "$T/out")
D="${B%/api.php}/device.php"
dpost() { curl -s -o "$T/out" -w "%{http_code}" -H "Content-Type: application/json" ${2:+-H "Authorization: Bearer $2"} -X POST "$D?action=$1" -d "$3"; }
dget() { curl -s -o "$T/out" -w "%{http_code}" ${2:+-H "Authorization: Bearer $2"} "$D?action=$1"; }
expect "wrong pairing code refused" "$(dpost claim '' '{"code":"000000"}')" 404
expect "box pairs with the code" "$(dpost claim '' "{\"code\":\"$PC\",\"hw\":\"TEST\",\"fw\":\"1.0.0\"}")" 200; TOK=$(sed -E 's/.*"token":"([^"]+)".*/\1/' "$T/out")
expect "a code works only once" "$(dpost claim '' "{\"code\":\"$PC\"}")" 404
expect "no token, no data" "$(dget sync '')" 401
expect "box gets today's schedule" "$(dget sync "$TOK")" 200; body_has "schedule has the morning lid" '"slot":"m","lid":1'
T0=$(( $(date +%s) - 600 )); T1=$(date +%s)
expect "box reports a lid opening" "$(dpost events "$TOK" "{\"battery\":77,\"events\":[{\"type\":\"open\",\"lid\":1,\"t\":$T0},{\"type\":\"close\",\"lid\":1,\"t\":$((T0+5))}]}")" 200; body_has "opening at dose time ticks the dose" '"results":\["taken"'
body_has "the box is told the dose is done" '"slot":"m","lid":1,"time":"[0-9:]*","count":[0-9]*,"done":true'
expect "box reports a second opening" "$(dpost events "$TOK" "{\"events\":[{\"type\":\"open\",\"lid\":1,\"t\":$T1}]}")" 200; body_has "second opening is flagged" '"results":\["again"'
get a bootstrap >/dev/null; body_has "dose shows as by pillbox" '"source":"box"'; body_has "tablet count went down" '"name":"Box medicine".*"stock":4'
expect "pillbox list" "$(get a device_list)" 200; body_has "battery reported" '"battery":77'
expect "B cannot test A's box" "$(post b device_test "{\"id\":$DEV,\"lid\":1}")" 404
expect "new pairing code" "$(post a device_code "{\"id\":$DEV}")" 200
expect "old box token stops working" "$(dget sync "$TOK")" 401
expect "test without hardware" "$(post a device_test "{\"id\":$DEV,\"lid\":2}")" 200; body_has "no dose in that lid now" '"outcome":"\(outside\|nodose\)"'

echo "Your data (DPDP)"
expect "export" "$(get a export)" 200; body_has "export has medicines" 'A private medicine'
expect "delete needs password" "$(post a account_delete '{"password":"wrong"}')" 403
expect "delete account" "$(post a account_delete '{"password":"secret123"}')" 200
expect "deleted account cannot sign in" "$(post a login "{\"email\":\"a$S@test.in\",\"password\":\"secret123\"}")" 401
post b account_delete '{"password":"secret123"}' >/dev/null

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ "$FAIL" = 0 ]
