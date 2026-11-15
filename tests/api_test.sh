#!/usr/bin/env bash
# API and security tests. Run against a TEST install (it creates accounts):
#   bash tests/api_test.sh http://127.0.0.1:8080
# Needs payments_demo => true in config.php for the payment test.
R="${1:-http://127.0.0.1:8080}"; B="$R/api.php"
T=$(mktemp -d); PASS=0; FAIL=0; S=$RANDOM$RANDOM
csrf() { curl -s -b "$T/$1" -c "$T/$1" "$B?action=session" | sed -E 's/.*"csrf":"([a-f0-9]+)".*/\1/'; }
post() { local c; c=$(csrf "$1"); curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -X POST "$B?action=$2" -d "$3"; }
get() { curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" "$B?action=$2"; }
expect() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  ✓ $1"; else FAIL=$((FAIL+1)); echo "  ✗ $1 (expected $3, got $2) $(head -c 200 "$T/out")"; fi; }
pcsrf() { curl -s -b "$T/$1" -c "$T/$1" "$R/pharmacy_api.php?action=session" | sed -E 's/.*"csrf":"([a-f0-9]+)".*/\1/'; }
ppost() { local c; c=$(pcsrf "$1"); curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -X POST "$R/pharmacy_api.php?action=$2" -d "$3"; }
body_has() { if grep -q "$2" "$T/out"; then PASS=$((PASS+1)); echo "  ✓ $1"; else FAIL=$((FAIL+1)); echo "  ✗ $1: $(head -c 200 "$T/out")"; fi; }

echo "Accounts"
expect "sign up A" "$(post a signup "{\"name\":\"<img src=x onerror=alert(1)>\",\"email\":\"a$S@test.in\",\"password\":\"secret123\",\"consent\":true,\"adult\":true}")" 200
expect "sign up needs consent" "$(post x signup "{\"name\":\"X\",\"email\":\"x$S@test.in\",\"password\":\"secret123\"}")" 400
expect "duplicate e-mail rejected" "$(post x signup "{\"name\":\"X\",\"email\":\"a$S@test.in\",\"password\":\"secret123\",\"consent\":true,\"adult\":true}")" 409
expect "sign up B" "$(post b signup "{\"name\":\"B\",\"email\":\"b$S@test.in\",\"password\":\"secret123\",\"consent\":true,\"adult\":true}")" 200
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

echo "Safety checks, assistant and autopilot security"
post a item_save '{"name":"Dolo 650","times":["m"]}' >/dev/null; post a item_save '{"name":"Crocin 500","times":["e"]}' >/dev/null
expect "safety check" "$(get a safety_check)" 200; body_has "same medicine in two brands is caught" '"code":"same"'
expect "assistant answers from medicine information" "$(post a assistant_ask '{"q":"what is Dolo 650 for?"}')" 200; body_has "answer mentions the ingredient" 'Paracetamol'
expect "AI helper switch" "$(post a ai_opt_in '{"on":true}')" 200; body_has "consent stored" '"aiOptIn":true'
expect "contact form gives a ticket" "$(post x contact_send '{"name":"Tester","email":"tester@example.in","topic":"billing","message":"I was charged twice, please refund"}')" 200; body_has "ticket number" '"ticket":"AB-[0-9]\{6\}"'
expect "normal user cannot open the autopilot" "$(get a admin_autopilot)" 403
expect "normal user cannot ask the data assistant" "$(post a admin_ask '{"q":"revenue"}')" 403
expect "normal user cannot change autopilot settings" "$(post a admin_settings '{"digest_on":false}')" 403

echo "Health diary, follow-ups, doctor visits, pharmacy (ideas from Japanese medication-notebook apps)"
expect "health reading" "$(post a vital_save '{"kind":"bp","v1":186,"v2":112}')" 200; body_has "very high BP is urgent" '"flag":"urgent"'
VID=$(sed -E 's/.*"id":([0-9]+).*/\1/' "$T/out")
expect "impossible reading refused" "$(post a vital_save '{"kind":"spo2","v1":140}')" 400
expect "blood pressure needs both numbers" "$(post a vital_save '{"kind":"bp","v1":130}')" 400
expect "diary list" "$(get a 'vitals&days=30')" 200; body_has "reading listed" '"kind":"bp"'
post b vital_delete "{\"id\":$VID}" >/dev/null; get a 'vitals&days=30' >/dev/null; body_has "B cannot delete A's reading" "\"id\":$VID"
WHEN=$(TZ=Asia/Kolkata date -d '+2 day' +%Y-%m-%dT10:30)
expect "plan a doctor visit" "$(post a appointment_save "{\"doctor\":\"Dr. Rao\",\"place\":\"Clinic\",\"at\":\"$WHEN\",\"questions\":\"Lower dose?\"}")" 200
expect "bad visit time refused" "$(post a appointment_save '{"doctor":"Dr. X","at":"tomorrow"}')" 400
expect "visit sheet" "$(get a visit_sheet)" 200; body_has "sheet has the questions" 'Lower dose?'; body_has "sheet has the readings" '"kind":"bp"'
get b bootstrap >/dev/null; if grep -q 'Dr. Rao' "$T/out"; then FAIL=$((FAIL+1)); echo "  ✗ B sees A's visits"; else PASS=$((PASS+1)); echo "  ✓ B cannot see A's visits"; fi
expect "save my pharmacy" "$(post a pharmacy_save '{"name":"MedPlus","phone":"+91 98765 43210"}')" 200; body_has "phone cleaned" '"phone":"9876543210"'
expect "bad pharmacy number refused" "$(post a pharmacy_save '{"name":"X","phone":"12345"}')" 400
expect "new medicine on a schedule" "$(post a item_save '{"name":"Glycomet 500","times":["m","e"]}')" 200
get a bootstrap >/dev/null; body_has "bootstrap carries visits and pharmacy" '"pharmacy":{"name":"MedPlus"'
expect "watch-over setting" "$(post a automation_save '{"refill_on":1,"missed_on":1,"course_on":1,"cleanup_on":1,"watch_hours":12}')" 200; body_has "watch-over saved" '"watchHours":12'

echo "Household extras: vaccinations, expenses, doctor links (like EPARK)"
BD=$(date -d '-70 day' +%Y-%m-%d)
expect "add a child" "$(post a member_save '{"name":"Baby","relation":"child"}')" 200; KID=$(sed -E 's/.*"id":([0-9]+).*/\1/' "$T/out")
expect "child's date of birth" "$(post a member_save "{\"id\":$KID,\"name\":\"Baby\",\"relation\":\"child\",\"birth_date\":\"$BD\"}")" 200
get a bootstrap >/dev/null; body_has "vaccination schedule made by itself" '"code":"penta2"'
expect "future date of birth refused" "$(post a member_save "{\"id\":$KID,\"name\":\"Baby\",\"birth_date\":\"2099-01-01\"}")" 400
expect "mark earlier doses as given" "$(post a vaccines_mark_past "{\"member_id\":$KID}")" 200; body_has "earlier doses given" '"given":"20'
expect "B cannot touch A's child" "$(post b vaccines_mark_past "{\"member_id\":$KID}")" 404
expect "add an expense" "$(post a expense_add '{"amount":"1,250","category":"test"}')" 200; body_has "expense counted in the year" '"total":1250'
expect "zero amount refused" "$(post a expense_add '{"amount":"0"}')" 400
expect "expenses CSV for the tax year" "$(get a expenses_csv)" 200; body_has "CSV has a header" 'Date'
get b expenses >/dev/null; body_has "B sees only B's expenses" '"total":0'
expect "share link for the doctor" "$(post a share_create '{"hours":24}')" 200; STOK=$(tr -d '\\' < "$T/out" | grep -o '/share/[A-Za-z0-9_-]*' | head -1 | cut -d/ -f3); SHID=$(grep -o '"shares":\[{"id":[0-9]*' "$T/out" | grep -o '[0-9]*$')
body_has "link is a full address" '"url":"http'
c=$(curl -s -o "$T/out" -w "%{http_code}" "$R/share/$STOK"); expect "doctor opens it without an account" "$c" 200; body_has "shows the medicines" 'Glycomet'
expect "stop sharing" "$(post a share_revoke "{\"id\":$SHID}")" 200
curl -s -o "$T/out" "$R/share/$STOK"; body_has "stopped link shows expired" 'expired'

echo "AushadhBox card and pharmacies (the family decides)"
get a bootstrap >/dev/null; MID=$(grep -o '"members":\[{"id":[0-9]*' "$T/out" | grep -o '[0-9]*$')
expect "cards list" "$(get a cards)" 200; body_has "card is off by default" '"shareAdd":false,"shareView":false'
expect "B cannot switch on A's card" "$(post b card_share "{\"member_id\":$MID,\"add\":true}")" 404
expect "switch on: pharmacies may add" "$(post a card_share "{\"member_id\":$MID,\"add\":true,\"view\":false}")" 200; CODE=$(grep -o '"code":"[A-Z0-9-]*"' "$T/out" | head -1 | cut -d'"' -f4)
[ -n "$CODE" ] && { PASS=$((PASS+1)); echo "  ✓ card code made when switched on"; } || { FAIL=$((FAIL+1)); echo "  ✗ no card code"; }
pcall() { php "$(dirname "$0")/partner_call.php" "$R" "$@" > "$T/raw"; cut -d' ' -f2- "$T/raw" > "$T/out"; cut -d' ' -f1 "$T/raw"; }
BR='{"id":990001,"code":"TESTPH01","name":"Test Pharmacy","city":"Erode","district":"Erode","licence":"TN-T-1","phone":"9876543210","approved":true}'
expect "pharmacy network call without a signature refused" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST "$R/partner_api.php?action=card_patient" -d '{}')" 401
expect "wrong signature refused" "$(pcall card_patient "{\"branch\":$BR,\"code\":\"$CODE\"}" --bad-sig)" 401
expect "replayed call refused" "$(pcall card_patient "{\"branch\":$BR,\"code\":\"$CODE\"}" --replay)" 401
expect "signed card scan (add-only consent)" "$(pcall card_patient "{\"branch\":$BR,\"code\":\"$CODE\"}")" 200; body_has "pharmacy sees a shortened name" '"canView":false'
if grep -q '"medicines"' "$T/out"; then FAIL=$((FAIL+1)); echo "  ✗ medicine list hidden without view consent"; else PASS=$((PASS+1)); echo "  ✓ medicine list hidden without view consent"; fi
expect "unknown card" "$(pcall card_patient "{\"branch\":$BR,\"code\":\"AAAA-BBBB-CCCC\"}")" 404
expect "safety pre-check through the network" "$(pcall card_precheck "{\"branch\":$BR,\"code\":\"$CODE\",\"items\":[{\"name\":\"Dolo 650\",\"times\":[\"m\"]}]}")" 200
expect "notify for an unknown household does nothing" "$(pcall notify '{"customer_ref":"00000000000000000000000000000000","title":"x","body":"y"}')" 200; body_has "nothing sent" '"sent":0'
get a cards >/dev/null; body_has "family sees the pharmacy that scanned" 'Test Pharmacy'
expect "network status for the app" "$(get a net_status)" 200; body_has "status answered" '"on":'
expect "order needs consent to share contact details" "$(post a net_order '{"code":"AMPR4N9X","items":[{"name":"Dolo"}]}')" 400
expect "no refill to a pharmacy the card was never used at" "$(post a refill_request '{"pharmacy_id":1,"items":[{"name":"Glycomet"}]}')" 403
expect "new card replaces the old code" "$(post a card_new "{\"member_id\":$MID}")" 200; NEWCODE=$(grep -o '"code":"[A-Z0-9-]*"' "$T/out" | head -1 | cut -d'"' -f4)
[ "$NEWCODE" != "$CODE" ] && { PASS=$((PASS+1)); echo "  ✓ new card has a new code"; } || { FAIL=$((FAIL+1)); echo "  ✗ code did not change"; }

echo "Smartwatch, tap-to-tick, imports and barcodes"
BC=$(printf "89%011d" $((S % 100000000000)))   # a fresh barcode for every run
expect "watch status" "$(get a watch_status)" 200; body_has "has today's dose times" '"slots":\['
expect "make a tap-to-tick link" "$(post a tick_link '{}')" 200; TTOK=$(tr -d '\\' < "$T/out" | grep -o '/tick/[A-Za-z0-9_-]*' | head -1 | cut -d/ -f3); body_has "link is a full address" '"url":"http'
c=$(curl -s -o "$T/out" -w "%{http_code}" "$R/tick/$TTOK"); expect "tick page opens (does not tick on open)" "$c" 200; body_has "has the big button" 'tickBtn'
c=$(curl -s -o "$T/out" -w "%{http_code}" -X POST "$R/tick/$TTOK?format=text"); expect "Siri / NFC tick" "$c" 200
if grep -q 'as taken\|Nothing is due\|already ticked' "$T/out"; then PASS=$((PASS+1)); echo "  ✓ answers with a sentence to read aloud"; else FAIL=$((FAIL+1)); echo "  ✗ tick answer: $(head -c 120 "$T/out")"; fi
expect "watch Taken button" "$(post a watch_tick '{}')" 200
expect "stop the link" "$(post a tick_link '{"revoke":true}')" 200
c=$(curl -s -o "$T/out" -w "%{http_code}" -X POST "$R/tick/$TTOK"); expect "stopped link no longer ticks" "$c" 404
NOW=$(date '+%Y-%m-%d %H:%M:00')
expect "import watch readings" "$(post a vitals_import "{\"readings\":[{\"kind\":\"pulse\",\"v1\":72,\"at\":\"$NOW\"},{\"kind\":\"pulse\",\"v1\":72,\"at\":\"$NOW\"},{\"kind\":\"spo2\",\"v1\":300,\"at\":\"$NOW\"},{\"kind\":\"bp\",\"v1\":120,\"v2\":80,\"at\":\"$NOW\"}]}")" 200
body_has "good readings added" '"added":2'; body_has "duplicates and impossible values skipped" '"skipped":2'
expect "unknown barcode" "$(get a "barcode_lookup&code=$BC")" 200; body_has "not found yet" '"found":false'
post a item_save "{\"name\":\"Dolo 650\",\"barcode\":\"$BC\",\"exp\":\"2027-05\"}" >/dev/null
expect "barcode learned from a scan" "$(get b "barcode_lookup&code=$BC")" 200; body_has "next family gets the name" '"name":"Dolo 650"'

echo "Care+ (round 3: repeat prescriptions, medicine review, food cautions, mother and child, points, share code, FHIR, phones)"
get a bootstrap >/dev/null; SELF=$(grep -o '"members":\[{"id":[0-9]*' "$T/out" | grep -o '[0-9]*$')
UNTIL=$(date -d '+90 day' +%Y-%m-%d); TODAY=$(date +%Y-%m-%d)
expect "save a repeat prescription" "$(post a rx_repeat_save "{\"doctor\":\"Dr. Rao\",\"issued\":\"$TODAY\",\"until\":\"$UNTIL\",\"allowed\":2,\"items\":[\"Glycomet 500\",\"Telma 40\"],\"member_id\":$SELF}")" 200
RXID=$(grep -o '"repeats":\[{"id":[0-9]*' "$T/out" | grep -o '[0-9]*$'); body_has "two refills allowed" '"allowed":2,"used":0'
expect "prescription valid more than a year refused" "$(post a rx_repeat_save "{\"doctor\":\"X\",\"issued\":\"$TODAY\",\"until\":\"2099-01-01\",\"items\":[\"A tab\"]}")" 400
expect "use a refill" "$(post a rx_repeat_use "{\"id\":$RXID}")" 200; body_has "one refill left" '"left":1'
post a rx_repeat_use "{\"id\":$RXID}" >/dev/null
expect "used-up prescription refused" "$(post a rx_repeat_use "{\"id\":$RXID}")" 400; body_has "says ask the doctor" 'used_up'
expect "B cannot use A's prescription" "$(post b rx_repeat_use "{\"id\":$RXID}")" 404
for m in "Cifran 500 (Ciprofloxacin)" "Flagyl 400 (Metronidazole)" "Alprax 0.25 (Alprazolam)" "Amlong 5" "Atorva 10"; do post a item_save "{\"name\":\"$m\",\"times\":[\"m\",\"e\"],\"member_id\":$SELF}" >/dev/null; done
expect "care screen" "$(get a careplus)" 200
body_has "milk caution with ciprofloxacin" '"code":"dairy"'; body_has "no alcohol with metronidazole" '"code":"alcohol_severe"'
body_has "five or more daily medicines suggest a review" '"many":true'
LMP=$(date -d '-140 day' +%Y-%m-%d)
expect "start a pregnancy record" "$(post a pregnancy_start "{\"member_id\":$SELF,\"lmp\":\"$LMP\"}")" 200; body_has "20 weeks" '"weeks":20'; body_has "second trimester" '"trimester":2'
PID=$(grep -o '"pregnancy":{"id":[0-9]*' "$T/out" | grep -o '[0-9]*$')
body_has "check-up 2 is due now" '"n":2,"label":"Second check-up (14–26 weeks)","from":"[0-9-]*","to":"[0-9-]*","done":false,"state":"now"'
expect "future LMP refused" "$(post a pregnancy_start "{\"member_id\":$SELF,\"lmp\":\"2099-01-01\"}")" 400
expect "mark check-up 1 done" "$(post a pregnancy_anc "{\"id\":$PID,\"n\":1,\"done\":true}")" 200; body_has "check-up 1 done" '"n":1,[^}]*"done":true'
expect "points" "$(get a points)" 200; body_has "points earned for the check-up" '"balance":[1-9]'
expect "voucher needs enough points" "$(post a voucher_make '{"points":2000}')" 400
expect "one-time share code" "$(post a share_code "{\"member_id\":$SELF,\"add\":true,\"view\":false}")" 200; body_has "6 digits" '"code":"[0-9]\{6\}"'
expect "share code needs a permission" "$(post a share_code "{\"member_id\":$SELF}")" 400
expect "B cannot make a code for A's family" "$(post b share_code "{\"member_id\":$SELF,\"view\":true}")" 404
expect "FHIR export" "$(get a fhir)" 200; body_has "FHIR bundle" '"resourceType": "Bundle"'; body_has "medicines as MedicationStatement" '"resourceType": "MedicationStatement"'
expect "family member's phone for missed calls" "$(post a member_phone "{\"member_id\":$SELF,\"phone\":\"+91 98765 43210\",\"sms\":true}")" 200; body_has "phone cleaned, not yet verified" '"phone":"9876543210","verified":false'
expect "bad phone refused" "$(post a member_phone "{\"member_id\":$SELF,\"phone\":\"12345\"}")" 400
c=$(curl -s -o "$T/out" -w "%{http_code}" "$R/telephony.php?from=9876543210&to=123"); expect "missed-call webhook needs the secret" "$c" 401
expect "low-data mode and village" "$(post a rural_save '{"low_data":true,"district":"Erode","village":"Chennimalai","health_worker":false}')" 200; body_has "saved" '"lowData":true'
expect "steps in the health diary" "$(post a vital_save '{"kind":"steps","v1":6400}')" 200
expect "a life sensor for watch-over needs a person" "$(post a device_add '{"name":"Door sensor","kind":"life"}')" 400
expect "add a door sensor" "$(post a device_add "{\"name\":\"Amma door\",\"kind\":\"life\",\"member_id\":$SELF}")" 200; body_has "kind life" '"kind":"life"'

echo "Your data (DPDP)"
expect "export" "$(get a export)" 200; body_has "export has medicines" 'A private medicine'
expect "delete needs password" "$(post a account_delete '{"password":"wrong"}')" 403
expect "delete account" "$(post a account_delete '{"password":"secret123"}')" 200
expect "deleted account cannot sign in" "$(post a login "{\"email\":\"a$S@test.in\",\"password\":\"secret123\"}")" 401
post b account_delete '{"password":"secret123"}' >/dev/null

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ "$FAIL" = 0 ]
