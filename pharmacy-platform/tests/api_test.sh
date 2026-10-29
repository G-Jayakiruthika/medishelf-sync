#!/usr/bin/env bash
# API tests for AushadhBox Pharmacy Network. Needs a TEST install with the demo data:
#   php tools/install.php admin@network.test "Admin" 'admin-pass-123' --demo
#   cd public && php -S 127.0.0.1:8090 index.php
#   bash tests/api_test.sh http://127.0.0.1:8090
R="${1:-http://127.0.0.1:8090}"; B="$R/api.php"
T=$(mktemp -d); PASS=0; FAIL=0; S=$RANDOM$RANDOM
csrf() { curl -s -b "$T/$1" -c "$T/$1" "$B?action=session" | sed -E 's/.*"csrf":"([a-f0-9]+)".*/\1/'; }
post() { local c; c=$(csrf "$1"); curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -X POST "$B?action=$2" -d "$3"; }
get() { curl -s -o "$T/out" -w "%{http_code}" -b "$T/$1" -c "$T/$1" "$B?action=$2"; }
expect() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  ✓ $1"; else FAIL=$((FAIL+1)); echo "  ✗ $1 (expected $3, got $2) $(head -c 220 "$T/out")"; fi; }
has() { if grep -q -- "$2" "$T/out"; then PASS=$((PASS+1)); echo "  ✓ $1"; else FAIL=$((FAIL+1)); echo "  ✗ $1: $(head -c 220 "$T/out")"; fi; }
hasnt() { if grep -q -- "$2" "$T/out"; then FAIL=$((FAIL+1)); echo "  ✗ $1: $(head -c 220 "$T/out")"; else PASS=$((PASS+1)); echo "  ✓ $1"; fi; }
val() { sed -E "s/.*\"$1\":\"?([^\",}]*)\"?.*/\1/" "$T/out"; }

echo "Sign-in and roles"
expect "not signed in" "$(get x dashboard)" 401
expect "wrong password" "$(post x login '{"id":"owner@amman.test","password":"nope"}')" 401
expect "owner signs in" "$(post own login '{"id":"owner@amman.test","password":"demo1234"}')" 200
expect "rider signs in with phone" "$(post rid login '{"id":"98765 00011","password":"demo1234"}')" 200
expect "cashier signs in" "$(post cash login '{"id":"9876500012","password":"demo1234"}')" 200
expect "POST without CSRF refused" "$(curl -s -o "$T/out" -w "%{http_code}" -b "$T/own" -H 'Content-Type: application/json' -X POST "$B?action=stock_add" -d '{}')" 419
expect "rider cannot see stock" "$(get rid stock_list)" 403
expect "cashier cannot add stock" "$(post cash stock_add '{"name":"X","qty":1,"exp":"12/30"}')" 403
expect "cashier cannot add staff" "$(post cash staff_add '{"name":"Y","role":"rider","phone":"9123456780"}')" 403
expect "dashboard" "$(get own dashboard)" 200; has "dashboard has open orders" '"open":'
expect "new pharmacy signs up" "$(post new signup "{\"name\":\"Test Medicals $S\",\"licence_retail\":\"TN-T-$S\",\"district\":\"Salem\",\"owner_name\":\"T\",\"email\":\"t$S@test.in\",\"phone\":\"9$(printf %09d $((S % 1000000000)))\",\"password\":\"secret123x\",\"agree\":true}")" 200
expect "unapproved pharmacy cannot take orders" "$(get new orders)" 403; has "says why" 'not_approved'
expect "two-factor setup" "$(post own totp_setup '{}')" 200; has "gives a secret" '"secret":"[A-Z2-7]\{32\}"'

echo "Stock"
expect "add a batch" "$(post own stock_add '{"name":"Dolo 650","generic":"Paracetamol 650 mg","qty":10,"exp":"08/28","batch":"T1","mrp":33.6,"price":32}')" 200
expect "price above MRP refused" "$(post own stock_add '{"name":"Dolo 650","qty":10,"exp":"08/28","batch":"T2","mrp":33.6,"price":40}')" 400
expect "expired batch refused" "$(post own stock_add '{"name":"Dolo 650","qty":10,"exp":"01/20","batch":"T3","mrp":33.6}')" 400
expect "stock list" "$(get own 'stock_list&q=dolo')" 200; has "batch listed" '"batch":"T1"'
expect "CSV import (Marg-style columns)" "$(post own stock_import '{"csv":"Item Name,Batch No,Exp Date,Cl Stock,MRP,Sale Rate\nCalpol 500,CP1,09/2027,25,30.5,29\nZincovit,ZV9,MAR-27,12,105,110\n","full":false}')" 200
has "two rows read" '"rows":2'; has "price above MRP capped" '"priceCapped":1'
expect "bad CSV explained" "$(post own stock_import '{"csv":"a,b\n1,2\n"}')" 400

echo "Network: find a missing medicine, links, passing an order on"
expect "find Lantus in the network" "$(post own network_find '{"name":"Lantus","qty":1}')" 200
has "found at another shop of the business" '"sameBusiness":true\|"name":"Sri Sai'
expect "network page" "$(get own network)" 200; has "linked village pharmacy" 'Arogya'; has "incoming link request" '"incoming":true'
expect "link to unknown code refused" "$(post own link_request '{"code":"ZZZZZZZZ"}')" 404

echo "Orders, prescriptions and compliance"
expect "walk-in customer" "$(post own customer_add "{\"name\":\"Ravi $S\",\"phone\":\"9$(printf %09d $(((S+7) % 1000000000)))\",\"village\":\"Perundurai\",\"sms_ok\":1}")" 200; CID=$(val id)
expect "counter order" "$(post own order_new "{\"customer_id\":$CID,\"items\":[{\"name\":\"Dolo 650\",\"qty\":2},{\"name\":\"Glycomet 500\",\"qty\":1}],\"kind\":\"pickup\"}")" 200
OID=$(sed -E 's/.*"order":\{"id":([0-9]+).*/\1/' "$T/out"); has "prescription needed for Metformin" '"needed":true'
expect "accept" "$(post own order_status "{\"id\":$OID,\"status\":\"accepted\"}")" 200
expect "cannot pack before the prescription is seen" "$(post own order_status "{\"id\":$OID,\"status\":\"packed\"}")" 422
expect "cashier cannot check prescriptions" "$(post cash order_rx "{\"id\":$OID,\"answer\":\"ok\",\"date\":\"$(date +%F)\"}")" 403
expect "old prescription refused" "$(post own order_rx "{\"id\":$OID,\"answer\":\"ok\",\"date\":\"$(date -d '-40 day' +%F)\",\"chronic\":true}")" 422
expect "pharmacist checks the prescription" "$(post own order_rx "{\"id\":$OID,\"answer\":\"ok\",\"date\":\"$(date -d '-3 day' +%F)\",\"chronic\":true}")" 200
expect "pack (stock taken, first-expiring batch first)" "$(post own order_status "{\"id\":$OID,\"status\":\"packed\"}")" 200; has "batch recorded on the order" '"batch":"[A-Z0-9]'
expect "ready" "$(post own order_status "{\"id\":$OID,\"status\":\"ready\"}")" 200
expect "skipping a step refused" "$(post own order_status "{\"id\":$OID,\"status\":\"new\"}")" 400
get own "order_get&id=$OID" >/dev/null; TOTAL=$(sed -E 's/.*"total":([0-9.]+).*/\1/' "$T/out")
expect "part on the khata (credit book)" "$(post own khata_credit "{\"customer_id\":$CID,\"amount\":20,\"order_id\":$OID}")" 200; has "balance shown" '"balance":20'
expect "rest paid in cash" "$(post own pay_record "{\"order_id\":$OID,\"method\":\"cash\",\"amount\":$(python3 -c "print(round($TOTAL-20,2))")}")" 200; has "staff payment confirmed" '"status":"confirmed"'
expect "collected" "$(post own order_status "{\"id\":$OID,\"status\":\"collected\"}")" 200
expect "customer card" "$(get own "customer_get&id=$CID")" 200; has "loyalty points earned" '"points":[0-9.]*[1-9]'
expect "khata paid by UPI" "$(post own khata_pay "{\"customer_id\":$CID,\"amount\":20,\"method\":\"upi_phone\",\"utr\":\"$S$S\"}")" 200; has "khata cleared" '"balance":0'
expect "same UPI reference twice refused" "$(post own pay_record "{\"method\":\"upi_phone\",\"amount\":5,\"utr\":\"$S$S\"}")" 409
expect "credit limit enforced" "$(post own khata_credit "{\"customer_id\":$CID,\"amount\":99999}")" 422
expect "UPI link for a shop without a printed QR" "$(post own upi_qr '{"amount":250,"note":"bill"}')" 200; has "UPI deep link" 'upi://pay?pa=9842211188%40upi'
expect "Schedule H1 cannot be delivered" "$(post own order_new "{\"customer_id\":$CID,\"items\":[{\"name\":\"Alprax 0.25\"}],\"kind\":\"delivery\"}")" 422; has "explains H1" 'H1'
expect "order for another district refused for delivery" "$(post own order_new "{\"customer\":{\"name\":\"Far\",\"district\":\"Madurai\",\"lat\":9.93,\"lng\":78.12},\"items\":[{\"name\":\"Dolo 650\"}],\"kind\":\"delivery\"}")" 422; has "district rule" 'district'

echo "Delivery: own rider, worth-it rule, OTP"
expect "delivery quote nearby" "$(post own delivery_quote '{"lat":11.29,"lng":77.60,"subtotal":300}')" 200; has "normal area" 'normal delivery area'
expect "far delivery not worth it" "$(post own delivery_quote '{"lat":11.80,"lng":77.90,"subtotal":40}')" 200; has "too far" '"possible":false'
expect "far but essential (insulin)" "$(post own delivery_quote '{"lat":11.80,"lng":77.90,"subtotal":160,"items":[{"name":"Human Mixtard 30/70","cold":true}]}')" 200; has "worth it for insulin" '"possible":true'
expect "far, customer pays full trip" "$(post own delivery_quote '{"lat":11.80,"lng":77.90,"subtotal":40,"customer_pays_full":true}')" 200; has "allowed when paid" '"possible":true'
expect "delivery order" "$(post own order_new "{\"customer\":{\"name\":\"Deliver $S\",\"phone\":\"9$(printf %09d $(((S+11) % 1000000000)))\",\"district\":\"Erode\",\"lat\":11.29,\"lng\":77.60,\"address\":\"5 Gandhi St\"},\"items\":[{\"name\":\"Dolo 650\",\"qty\":1}],\"kind\":\"delivery\"}")" 200
DID=$(sed -E 's/.*"order":\{"id":([0-9]+).*/\1/' "$T/out"); OTP=$(val otp); has "delivery code made" '"otp":"[0-9]\{4\}"'
expect "accept delivery order" "$(post own order_status "{\"id\":$DID,\"status\":\"accepted\"}")" 200; expect "pack delivery order" "$(post own order_status "{\"id\":$DID,\"status\":\"packed\"}")" 200
get own delivery_setup >/dev/null; RIDER=$(sed -E 's/.*"riders":\[\{"id":([0-9]+).*/\1/' "$T/out")
expect "assign own rider" "$(post own delivery_assign "{\"order_id\":$DID,\"mode\":\"own\",\"rider_id\":$RIDER}")" 200
expect "rider sees the ride" "$(get rid rides)" 200; has "ride with map link" 'google.com/maps'
expect "rider location" "$(post rid ride_ping "{\"order_id\":$DID,\"lat\":11.28,\"lng\":77.59}")" 200
expect "wrong code refused" "$(post rid ride_done "{\"order_id\":$DID,\"otp\":\"0000\",\"cash\":0}")" 422
expect "delivered with the customer's code and cash" "$(post rid ride_done "{\"order_id\":$DID,\"otp\":\"$OTP\",\"cash\":40}")" 200; has "delivered" '"status":"delivered"'
expect "cash waits for the shop to confirm" "$(get own pay_claims)" 200; has "rider cash claim" '"by_whom":"rider"'
CL=$(sed -E 's/.*"claims":\[\{"id":([0-9]+).*/\1/' "$T/out")
expect "shop confirms the cash" "$(post own pay_decide "{\"id\":$CL,\"ok\":true}")" 200
expect "local partner (bus parcel)" "$(post own order_new "{\"customer_id\":$CID,\"items\":[{\"name\":\"Electral ORS\",\"qty\":2}],\"kind\":\"pickup\"}")" 200

echo "Village pickup points and SMS"
expect "pickup point added" "$(post own point_save '{"name":"Lakshmi (ASHA)","kind":"asha","village":"Thingalur","days":"Mon,Thu"}')" 200
SMS=$(curl -s -o "$T/out" -w "%{http_code}" -X POST "$R/sms.php?key=devsmskey123&type=sms" -d "from=+91 98765 $((S % 10000 + 10000))&text=AB AMPR4N9X dolo 650 2, ors 3")
expect "order by SMS (feature phone)" "$SMS" 200; has "SMS order made" '"order":"'
expect "SMS webhook needs the key" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST "$R/sms.php?key=bad&type=sms" -d 'from=9876543210&text=hi')" 401
expect "missed call becomes a call-back task" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST "$R/sms.php?key=devsmskey123&type=missed&shop=AMPR4N9X" -d "from=98764$((S % 10000 + 10000))")" 200
get own dashboard >/dev/null; has "call-back on the dashboard" '"kind":"callback"'

echo "Transfers between shops of one business"
expect "ask the Chennimalai shop for stock" "$(post own transfer_ask "{\"from_branch\":$(/tmp/mariadb-src/bld/client/mariadb -S /tmp/mdb.sock -uroot ab_pharmacy -N -e "SELECT id FROM branches WHERE code='AMCH7T3W'"),\"items\":[{\"name\":\"Telma 40\",\"qty\":1}]}")" 200
expect "transfer to another business without a wholesale licence refused" "$(post own transfer_ask "{\"from_branch\":$(/tmp/mariadb-src/bld/client/mariadb -S /tmp/mdb.sock -uroot ab_pharmacy -N -e "SELECT id FROM branches WHERE code='ARKV8M2P'"),\"items\":[{\"name\":\"Dolo 650\"}]}")" 422; has "suggests passing the order" 'Pass the order'

echo "Cold chain, sync agent, public pages"
expect "fridge thermometer pairing code" "$(post own device_add '{"name":"Vaccine fridge"}')" 200; PC=$(val pairCode)
expect "device claims a token" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST "$R/device.php?action=claim" -d "{\"code\":\"$PC\"}")" 200; DT=$(val token)
expect "warm readings reported" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST -H "Authorization: Bearer $DT" "$R/device.php?action=report" -d "{\"readings\":[{\"t\":$(($(date +%s)-600)),\"c\":11.2},{\"t\":$(($(date +%s)-300)),\"c\":11.8},{\"t\":$(date +%s),\"c\":12.1}],\"battery\":80}")" 200
has "too warm: buzz" '"state":"high","buzz":true'
expect "unknown device refused" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST -H "Authorization: Bearer $(printf 'a%.0s' {1..48})" "$R/device.php?action=report" -d '{}')" 401
expect "sync token for the billing computer" "$(post own sync_token_new '{"name":"Counter PC"}')" 200; ST=$(val token)
CSV=$(printf 'Item Name,Batch,Expiry,Qty,MRP\nDolo 650,SYNC1,12/2027,50,33.6\n'); BODY=$(python3 -c "import json,hashlib,sys; c=sys.argv[1]; print(json.dumps({'csv':c,'full':False,'sha256':hashlib.sha256(c.encode()).hexdigest(),'file':'stock.csv'}))" "$CSV")
expect "sync agent uploads stock" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST -H "Authorization: Bearer $ST" -H 'Content-Type: application/json' "$R/sync.php?action=stock" -d "$BODY")" 200; has "one row" '"rows":1'
expect "damaged upload refused" "$(curl -s -o "$T/out" -w "%{http_code}" -X POST -H "Authorization: Bearer $ST" "$R/sync.php?action=stock" -d '{"csv":"x","sha256":"00"}')" 422
expect "shop page from the QR" "$(curl -s -o "$T/out" -w "%{http_code}" "$R/s/AMPR4N9X")" 200; has "UPI pay box" 'data-vpa="9842211188@upi"'
expect "printable poster" "$(curl -s -o "$T/out" -w "%{http_code}" "$R/poster/AMPR4N9X")" 200; has "SMS instructions on the poster" 'AB AMPR4N9X'
expect "unknown shop" "$(curl -s -o "$T/out" -w "%{http_code}" "$R/s/ZZZZZZZZ")" 404
H=$(curl -s -D - -o /dev/null "$R/app"); echo "$H" > "$T/out"; has "security policy header" "Content-Security-Policy"; has "no framing" "X-Frame-Options: DENY"
expect "audit log" "$(get own audit)" 200; has "actions are logged" '"action":"order_packed"'
expect "cashier cannot read the audit log" "$(get cash audit)" 403

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ "$FAIL" = 0 ]
