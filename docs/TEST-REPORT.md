# Test report — AushadhBox v3

| | |
|---|---|
| Date | 28 October 2026 |
| Build | v3.0 |
| Environment | PHP 8.3, MariaDB 11.4, Chromium (Playwright), phone 390 px and desktop 1366 px |
| Result | **All automated suites pass (355 checks).** No open defects. |

## 1. Automated suites (in `tests/`)

| Suite | Command | Checks | Result |
|---|---|---|---|
| SEO and site health | `php tests/seo_check.php <url>` | 271 | ✅ all passed |
| API, security and automations | `bash tests/api_test.sh <url>` | 57 | ✅ all passed |
| End-to-end browser journey | `node tests/e2e.js <url>` | 54 (27 phone + 27 desktop) | ✅ all passed |
| Prescription and bill reader | `node tests/rx_parse_test.js` | 34 | ✅ all passed |

Re-run all four against the live site after every change. `api_test.sh` and `e2e.js` create test accounts; run them on a test copy or delete the accounts afterwards.

### 1.1 SEO checks (26 pages including 8 random medicine pages)
Every page returns 200 with a title ≤ 70 characters, a 50–320 character description, an absolute canonical URL, exactly one `<h1>`, an Open Graph image, valid JSON-LD, `html lang` and alt text on images. Home and pricing in three languages carry 11 hreflang links. robots.txt lists the sitemap and blocks `/app`; `/app` sends `noindex`; unknown pages return 404; `core/config.php` and `schema.sql` are unreachable; `/ta/about` redirects 301 to `/about`. Sitemap: 846 URLs.

### 1.2 API and security checks
Sign-up requires consent; duplicate e-mail refused; wrong password refused. POST without CSRF token → 419. Signed-out → 401. Normal user → admin stats 403. Another user cannot edit or delete your medicine (404). HTML in names stored as text and escaped on display. Analytics refused without the consent cookie; bad consent IDs refused. Free plan blocks the 31st medicine (402) and invites. Another user cannot confirm your order. Demo payment activates Premium. Invite codes work once; a joined member sees the shared cabinet, cannot pay, and loses access after leaving. Export contains the data; delete needs the password; a deleted account cannot sign in.

### 1.3 End-to-end journey (phone and desktop)
Cookie banner on first visit → nothing tracked before consent → Accept closes banner, stores choice, tracks one page view → Tamil home page → medicine search → Start free → sign-up → welcome tour → ends on Scan → sample pack read (name + expiry) → saved, cabinet count 1 → prescription text → review list of 3 with a *usual* schedule filled in → all saved in one tap → one typed sentence adds a medicine → "All taken" ticks the whole morning → shopping list opens → Account plan card → invite on Free opens upgrade dialog → payment → Premium → invite code → switch app to Hindi → no horizontal scrolling → no JavaScript errors.

### 1.4 Automations
Stock count: a ticked dose subtracts the per-dose amount once (a repeated tick does not), un-ticking restores it. Course end dates are stored and returned. Automation settings save. The calendar feed returns valid iCalendar; a wrong calendar address returns 404. Scripted clock tests of the alert engine: refill alert fires in the morning window and repeats every two days until restocked; missed-dose alert skips doses already ticked and finished courses; course-finished alert fires the morning after the last day; monthly clean-up runs on the 1st.

### 1.5 Smart adding
Reader unit tests: prescription vs bill detection; names cleaned of numbering, form words and instructions; 1-0-1, ½-0-½, 1-1-1-1, 0-0-1, OD, BD, TDS, HS, SOS, weekly, "2 tabs at a time"; English, Hinglish, Hindi and Tamil time words; "x 5 days" / "x 2 weeks" / "continue"; instructions on the next line; tablets needed; after/before food; bill rows with batch, expiry (05/27, 08/2027, SEP-26) and quantity; sentences with a family member ("Amma", "Appa's"); pack sizes. API: medicine without expiry allowed, bad expiry refused, matching many names returns usual schedules and course length, a whole prescription saves in one request, empty list refused, one call ticks a whole time slot and lowers stock, unknown slot refused. Clock test: five mornings ticked around 9:30–9:50 with an 8:00 reminder move it to 9:45 once.

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

## 5. Not tested here (needs the live accounts)
- Real Razorpay payments (test mode, then live): follow DEPLOY.md §11 and pay ₹49 once yourself.
- Real e-mail delivery (SMTP), push notifications on a real phone, Bhashini voices (needs API key).
- Google Search Console indexing (takes days to weeks after submitting the sitemap).
