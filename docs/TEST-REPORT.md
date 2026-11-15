# Test report — AushadhBox v3

| | |
|---|---|
| Date | 15 November 2026 |
| Build | v3.2 (Care+, villages and basic phones, AushadhBox Pharmacy Network with fees and vouchers, 3D models) |
| Environment | PHP 8.3, MariaDB 11.4, Python 3, Java 21, Chromium (Playwright), phone 390 px and desktop 1366 px |
| Result | **All automated suites pass (948 checks).** No open defects. |

## 1. Automated suites

| Suite | Command | Checks | Result |
|---|---|---|---|
| SEO and site health | `php tests/seo_check.php <url>` | 271 | ✅ all passed |
| API, security, automations and Care+ | `bash tests/api_test.sh <url>` | 220 | ✅ all passed |
| End-to-end browser journey | `node tests/e2e.js <url>` | 104 (49 phone + 49 desktop + 6 portal and watch) | ✅ all passed |
| Prescription and bill reader | `node tests/rx_parse_test.js` | 36 | ✅ all passed |
| Bluetooth meter parsers | `node tests/ble_parse_test.js` | 12 | ✅ all passed |
| Watch and health-app import | `node tests/health_import_test.js` | 20 | ✅ all passed |
| Autopilot, support, safety, pharmacy card, watch, Care+ | `php tests/autopilot_test.php` | 90 | ✅ all passed |
| Pharmacy platform API and security | `bash pharmacy-platform/tests/api_test.sh` | 132 | ✅ all passed |
| Pharmacy network money rules and database upgrade | `php pharmacy-platform/tests/unit_test.php` | 21 | ✅ all passed |
| Demand forecasts, reorder, expiry, transfers, delivery rounds (Python) | `python3 -m unittest discover -s tests -t .` in `pharmacy-platform/analytics` | 19 | ✅ all passed |
| Billing-software sync agent (Java) | `sh pharmacy-platform/sync-agent/build.sh` | 17 | ✅ all passed |
| Fridge thermometer logic (MicroPython) | `python3 -m unittest test_logic` in `pharmacy-platform/iot/coldchain` | 6 | ✅ all passed |
| 3D models | rebuilt from `tools/models3d/spec.py` in CI; viewer checked in Chromium (no script errors, every screen's model drawn) | — | ✅ |

Re-run all of them against the live site after every change. `api_test.sh` and `e2e.js` create test accounts; run them on a test copy or delete the accounts afterwards.

### 1.1 SEO checks (26 pages including 8 random medicine pages)
Every page returns 200 with a title ≤ 70 characters, a 50–320 character description, an absolute canonical URL, exactly one `<h1>`, an Open Graph image, valid JSON-LD, `html lang` and alt text on images. Home and pricing in three languages carry 11 hreflang links. robots.txt lists the sitemap and blocks `/app`; `/app` sends `noindex`; unknown pages return 404; `core/config.php` and `schema.sql` are unreachable; `/ta/about` redirects 301 to `/about`. Sitemap: 846 URLs.

### 1.2 API and security checks
Sign-up requires consent; duplicate e-mail refused; wrong password refused. POST without CSRF token → 419. Signed-out → 401. Normal user → admin stats 403. Another user cannot edit or delete your medicine (404). HTML in names stored as text and escaped on display. Analytics refused without the consent cookie; bad consent IDs refused. Free plan blocks the 31st medicine (402) and invites. Another user cannot confirm your order. Demo payment activates Premium. Invite codes work once; a joined member sees the shared cabinet, cannot pay, and loses access after leaving. Export contains the data; delete needs the password; a deleted account cannot sign in.

### 1.3 End-to-end journey (phone and desktop)
Cookie banner on first visit → nothing tracked before consent → Accept closes banner, stores choice, tracks one page view → Tamil home page → medicine search → Start free → sign-up → welcome tour → ends on Scan → sample pack read (name + expiry) → saved, cabinet count 1 → prescription text → review list of 3 with a *usual* schedule filled in → all saved in one tap → one typed sentence adds a medicine → "All taken" ticks the whole morning → Skip with a reason shows on the dose → last 7 days report shows → shopping list opens → Account plan card → invite on Free opens upgrade dialog → payment → Premium → invite code → switch app to Hindi → no horizontal scrolling → no JavaScript errors.

### 1.4 Automations
Stock count: a ticked dose subtracts the per-dose amount once (a repeated tick does not), un-ticking restores it. Course end dates are stored and returned. Automation settings save. The calendar feed returns valid iCalendar; a wrong calendar address returns 404. Scripted clock tests of the alert engine: refill alert fires in the morning window and repeats every two days until restocked; missed-dose alert skips doses already ticked and finished courses; course-finished alert fires the morning after the last day; monthly clean-up runs on the 1st.

### 1.5 Smart adding
Reader unit tests: prescription vs bill detection; names cleaned of numbering, form words and instructions; 1-0-1, ½-0-½, 1-1-1-1, 0-0-1, OD, BD, TDS, HS, SOS, weekly, "2 tabs at a time"; English, Hinglish, Hindi and Tamil time words; "x 5 days" / "x 2 weeks" / "continue"; instructions on the next line; tablets needed; after/before food; bill rows with batch, expiry (05/27, 08/2027, SEP-26) and quantity; sentences with a family member ("Amma", "Appa's"); pack sizes. API: medicine without expiry allowed, bad expiry refused, matching many names returns usual schedules and course length, a whole prescription saves in one request, empty list refused, one call ticks a whole time slot and lowers stock, unknown slot refused. Clock test: five mornings ticked around 9:30–9:50 with an 8:00 reminder move it to 9:45 once.

### 1.6 Skips, weekly report and smart pillbox
API: a skip needs a valid reason; "ran out" sets the tablet count to 0; "doctor stopped it" ends the course today; another family cannot skip your medicine; "All taken" leaves a deliberate skip as a skip; the weekly report returns each person. Pillbox: a wrong pairing code is refused, the right one pairs once only, no token means no data, the box gets today's schedule, an opening at dose time ticks the dose ("by pillbox") and lowers the stock, the reply tells the box the dose is done, a second opening is flagged, battery is reported, another family cannot use your box, a new pairing code revokes the old token, "Try without hardware" works. Browser: Skip button and reason, report card, pillbox pairing code, test opening listed.
Clock tests of the alert engine: "Did you take it?" goes only to the person whose medicine it is, at half the missed-dose delay; side-effect skips alert the family; a second lid opening alerts the family; the missed-dose alert skips doses that were skipped or opened in the pillbox; the Sunday report lists each person's percentage and most-missed time.
Simulator (`hardware/simulator/pillbox_sim.py`) against the local server: on time → taken, 2 minutes later → already, 35 minutes later → again, before the window → outside, empty lid → no dose, offline queue → sent later.
Firmware: compiled on the build machine against stand-ins for the ESP32 libraries (and the real ArduinoJson 7) with no errors or warnings. It has not been run on a physical ESP32 here; flash it and check the Serial Monitor as in `hardware/README.md`.

### 1.7 Autopilot, support and safety
Unit tests (database, rolled back afterwards): refund request → billing, high; deletion request → privacy; "crashes" is a bug, not the word "rash"; overdose → medical, urgent, and the draft says call 112; a Tamil payment message → billing; happy and angry moods; ticket numbers; data questions find the right metric and period, give a 7-day chart, and explain what can be asked for anything else; without an API key the AI layer returns nothing and every feature falls back to rules; a burst of 14 support messages after 28 quiet days is flagged as a high anomaly and stored; safety finds exactly the same-medicine, allergy, bleeding-pair and take-apart alerts in a test family (brands Dolo, Crocin, Augmentin, Brufen, Clopilet, Ciplox, Shelcal, Thyronorm) and nothing else; weekend misses and late doses are noticed; the assistant answers from the medicine database; health, growth and the digest are built.
API: safety check catches Dolo + Crocin, the assistant answers about Dolo 650, AI consent is stored, the contact form returns a ticket number, normal users get 403 from every autopilot action.
AI path: tested against a stand-in AI server (OpenAI-compatible): routing a question to the doses metric, the written answer, the support draft and usage counting all work. The real Claude API was not called here (no key).

### 1.8 Ideas from Japan
Unit tests: blood pressure, sugar (fasting vs after a meal), oxygen, fever and pulse ranges; °F converted to °C; a new daily medicine gets day-3 and day-10 check-ins and a 5-day course only day 3; a side effect brings the next check-in to 2 days and appears on the visit sheet; watch-over alerts the family about a parent silent for 30 hours, only once a day, and not after she opens the app.
API: urgent reading, impossible values refused, blood pressure needs both numbers, another family cannot delete your reading or see your visits, visit sheet has the questions and readings, pharmacy number cleaned (+91 removed) and bad numbers refused, watch-over setting saved.
Browser: an urgent blood-pressure reading is flagged, a doctor visit is planned, the visit sheet shows the questions, the emergency card shows the blood group and draws a QR code.
Clock test of the alerts: follow-up side effect, urgent blood pressure and the 2-hours-before visit reminder are each sent once.

### 1.9 Household extras, AushadhBox card, pharmacy portal and smartwatch
API: a child's date of birth creates the vaccination schedule; a future date of birth is refused; earlier doses can be marked given; another family cannot touch your child's record; an expense counts in the financial-year total, a zero amount is refused, the CSV downloads, another family's total is 0; the doctor link is a full address, opens without an account and shows the medicines, and shows "expired" once sharing is stopped. Card: off by default, another family cannot switch yours on, switching on makes a code, a new card changes the code. Pharmacy portal: registration needs the terms, a new pharmacy waits for approval and cannot scan, wrong password refused, a family session cannot use the pharmacy API, no refill request to a pharmacy the card was never used at. Watch: status lists today's times; tick link is a full address; opening it does not tick; POST ticks and answers a sentence; the watch button ticks; a stopped link returns 404. Import: good readings added, duplicates and impossible values skipped. Barcodes: unknown at first, learned from one family's save, found by another family.
Unit tests (approved pharmacy, rolled back): a code typed in lower case or read from the QR text is understood; a card with both switches off is refused; add-only consent gives a shortened name and no medicine list; view consent adds allergies and current medicines; the pre-check finds a penicillin allergy and the aspirin + clopidogrel bleeding risk and saves nothing; without view consent the other medicine's name is hidden; dispensing adds times, pack count and course end and schedules check-ins; the family's log shows who added what; view-only consent cannot add; a blocked pharmacy is refused; an old card code stops working; a blocked pharmacy is not offered for refills. Tick link finds its owner and a changed link finds nobody; ticking marks doses once; imports skip reversed blood pressure and very old readings.
Readers: Apple Health export.xml and export.zip (deflate, streamed), Samsung Health heart rate and blood pressure CSV, Google Fit daily CSV, Fitbit JSON; one pulse per hour, % and lb and mmol/L and °F converted, blood-pressure halves paired, readings older than a year dropped. Bluetooth parsers for the five standard health services (SFLOAT, FLOAT, units, flags).
Browser: expense total, HbA1c in the diary, Bluetooth and import buttons, card page with sharing off, switching on makes a code and QR, refills explained until a pharmacy has used the card, tap-to-tick link made; pharmacy portal sign-in and licence field; watch page asks to sign in when signed out. Scripted portal run: register → pending → approved → typed code → pasted bill (3 medicines) → safety check shows the bleeding risk and duplicate → second tap needed → 3 medicines in the family's cabinet. Watch page checked at 240 × 240 px.
Not tested here: a real Bluetooth meter (the parsers are tested with the byte layouts from the Bluetooth specifications), a real Apple Watch / Siri Shortcut and a real NFC sticker (the link they call is tested), a camera scan of a printed card (the same QR reader as the medicine scanner).

All suites also run automatically on GitHub on every push (`.github/workflows/test.yml`).

## 2. Manual and scripted checks

| Area | Check | Result |
|---|---|---|
| Razorpay webhook | Valid signature + matching amount activates Premium; wrong amount ignored; repeated event does not extend twice; bad signature → 400 | ✅ |
| Plan renewal maths | Paying while Premium extends from the current end date; paying after expiry starts from today | ✅ |
| Household cron | Dose reminders and weekly e-mails read the shared cabinet for joined members | ✅ (dry run, mail disabled) |
| Printable report | Premium only; free user redirected to upgrade; per-person filter; print CSS hides navigation | ✅ |
| Contact form | Saved and shown in admin; honeypot silently drops bots; rate-limited 5/hour | ✅ |
| Reject cookies | Banner closes, `_ga` cookies deleted, past statistics for that visitor deleted on the server | ✅ |
| Visual review | Home (light, dark, Tamil, phone), pricing, medicine, ingredient, guide, contact, privacy, 404, app screens, dialogs, tour, report, admin | ✅ after fixes below |
| Mermaid diagrams in DESIGN.md | Rendered with Mermaid CLI | ✅ |

## 3. Performance
| Measure | Value |
|---|---|
| Home page HTML (gzip) | 5.5 KB |
| CSS + JS for the website (gzip) | 13 KB |
| Hero image | 54 KB |
| Server response, home / medicine page (local) | 2 ms / 2 ms |

No framework, no render-blocking JavaScript (all scripts `defer`).

## 4. Defects found and fixed during testing
| # | Defect | Fix |
|---|---|---|
| 1 | Home page scrolled sideways on phones (search box in the "save money" section) | Grid columns given `minmax(0, 1fr)` |
| 2 | Medicine pages showed large gaps between headings on desktop | Article column no longer stretches to the sidebar height |
| 3 | Long medicine names made page titles too long for Google (up to 137 characters) | Titles shortened adaptively to ≤ 70 |
| 4 | Feature card tags did not match their cards | Tags corrected |
| 5 | Hero "Expires in 12 days" chip covered the phone headline | Chip moved; hidden on small phones |
| 6 | Report page person filter used an inline script blocked by the security policy | Replaced with a script-file handler |
| 7 | Going to `/app#account` from inside the app did nothing | Hash navigation handled (also powers `#upgrade` and invite links) |
| 8 | "Printable report" button showed in link blue | Button colour fixed |
| 9 | Home page sample query would fail on MySQL 8 strict mode | Rewritten without loose GROUP BY |
| 10 | Doctor and tap-to-tick links were relative ("/tick/…") when `app_url` was empty, so a printed QR sticker would not open | Links now use the site address (`base_url()`, moved into bootstrap) |
| 11 | Pharmacy saving used its own database transaction, which failed inside another one and was not rolled back on error | Nested-safe transaction (savepoint) with rollback on error |
| 12 | Secret tick and doctor links and the pharmacy portal could be kept in the offline cache | Service worker skips `/tick/`, `/share/` and `/pharmacy` |
| 13 | Long medicine lists pushed the Taken button off small round watch screens | At most 3 names (not yet taken first) and "+N" on screens under 320 px |

## 5. Not tested here (needs the live accounts)
- Real Razorpay payments (test mode, then live): follow DEPLOY.md §11 and pay ₹49 once yourself.
- Real e-mail delivery (SMTP), push notifications on a real phone, Bhashini voices (needs API key).
- Google Search Console indexing (takes days to weeks after submitting the sitemap).
