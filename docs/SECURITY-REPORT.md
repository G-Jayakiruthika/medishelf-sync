# Security and privacy review: AushadhBox

| | |
|---|---|
| Date | 15 November 2026 |
| Scope | Family app and website (`public_html/`), AushadhBox Pharmacy Network (`pharmacy-platform/`), tools, CI, git history |
| Method | Code review of every endpoint and query, automated attack tests, load tests, git-history secret scan, configuration review |
| Result | **All 1,120 automated checks pass** (13 suites), including **167 security checks** that try to break in. 0 errors with 400 users at once. |
| Standard | Privacy work designed to follow ISO/IEC 29100 (privacy principles) and ISO/IEC 27701 (records of processing, consent records, data-subject requests), for India's DPDP Act 2023 and DPDP Rules 2025. This is a design aim, not a certification. |

How to re-run everything: see [Tests](#tests) at the end. `tests/security_test.sh` is the proof for most rows below.

## 1. Summary of what was found and fixed in this review

| # | Finding | Risk | Fix |
|---|---|---|---|
| 1 | Passwords hashed with bcrypt (good, but below the current recommendation) | Low | Argon2id (19 MiB, 2 passes) in both apps; old hashes upgrade at the next sign-in |
| 2 | A password reset or change left other devices signed in, and other reset links usable | Medium | "Sign-out everywhere" counter in the session; other reset links cancelled |
| 3 | Family-app administrators signed in with a password only | High | Two-factor sign-in (TOTP, any authenticator app) required before the admin dashboard opens; secret stored encrypted |
| 4 | Health conditions, emergency contacts and family phone numbers stored in plain text | Medium | Encrypted in the database (libsodium); existing rows encrypted by cron; a keyed hash finds a caller without decrypting |
| 5 | No limit on request size | Medium (memory DoS) | 512 KB per request (7 MB for the admin medicine-list import), JSON only, nesting limited |
| 6 | No database backups or restore | High | Encrypted, compressed, rotated backups (8 weekly copies) in both apps, with a tested restore that refuses changed files and wrong keys |
| 7 | Origin of browser requests not checked (CSRF token only) | Low | Origin must be the site on every change, in both apps |
| 8 | Content-Security-Policy only in the Apache config | Low | Also sent by PHP, so it applies on any web server; a test keeps the two identical |
| 9 | Hidden files (`.git`, `.env`) and several file types not blocked; no Apache config for the pharmacy platform | Medium | Blocked on Apache and in the PHP routers; `pharmacy-platform/public/.htaccess` added |
| 10 | Sign-up forms had no bot protection | Low | Hidden field and fill-time check on both apps' sign-up (the contact form already had one) |
| 11 | User text sent to the AI could carry instructions (prompt injection) | Low (the AI takes no actions) | User text is fenced as untrusted data in every prompt; answers stay plain text or are checked against fixed lists |
| 12 | No security event log or monitoring view | Medium | `security_events` table; Admin → Security shows events and 9 configuration self-checks |
| 13 | Public medicine pages ran full-table searches on every view | Medium (slow under load) | JSON file cache; capacity rose from ~424 to ~704 requests/s |
| 14 | A busy database gave an error page | Low | Connections retried with back-off, then a polite 503 "busy, try again" |
| 15 | No 18+ confirmation; a child's data had no recorded guardian consent | Medium (DPDP s.9) | 18+ box at sign-up; a child's birth date needs the parent's or guardian's consent, recorded |
| 16 | Optional e-mails had no unsubscribe link; re-engagement e-mails went to everyone | Medium | One-click unsubscribe (link and List-Unsubscribe headers); re-engagement only with opt-in |
| 17 | Cookie banner gave "Accept" more weight than "Reject" | Low (dark pattern) | Equal buttons |
| 18 | Status colours (green, amber) below WCAG AA contrast on light backgrounds | Low (accessibility) | Darkened to pass AA (4.6:1 or better) |
| 19 | The pharmacy network had no privacy notice or terms | Medium | Privacy notice (who is fiduciary and who is processor) and terms for pharmacies |
| 20 | A stray entry in the database upgrader's column-change list (ignored at run time) | None | Removed; the upgrader can now also widen columns |
| 21 | GitHub Actions versions behind (Dependabot) | Low | checkout v7, setup-node v7, FTP deploy 4.4.0 |

Nothing was found that exposed one family's data to another, allowed SQL injection, or leaked a secret.

## 2. Your checklist, item by item

✅ in place and tested · ⚙️ in place, needs a setting on the live server · ➖ not applicable (explained)

### Authentication and user security
| Item | Status | Where / how it is proved |
|---|---|---|
| Proper authentication | ✅ | Server-side sessions on every API call (`require_user`), roles checked on the server; tests: signed-out calls get 401 |
| Hash passwords with Argon2id | ✅ | `pw_hash()` in both apps; test reads the stored hash prefix `$argon2id$` |
| Secure sessions and cookies | ✅ | HttpOnly, SameSite (Lax / Strict), Secure on HTTPS, strict session mode, new id at sign-in, sign-out everywhere after a password change; tested |
| Expiring password reset links | ✅ | 1 hour, single use, stored only as a SHA-256 hash, other links cancelled on use; tested (expired, reused, sibling links) |
| Lock admin routes | ✅ | Admin role plus two-factor; tested for 4 admin actions signed out and as a normal user; pharmacy roles (owner, manager, pharmacist, cashier, rider) tested |
| Rate limiting | ✅ | Sign-in, sign-up, reset, contact, two-factor, orders, vouchers, devices, partner calls; tested (6 wrong passwords → 429) |
| Two-factor for staff and admins | ✅ | Family admins (new) and pharmacy staff/admins (existing) |
| Same answer for unknown accounts | ✅ | Same message and similar timing (a dummy Argon2id check); tested |

### API and data protection
| Item | Status | Where / how it is proved |
|---|---|---|
| Hide / store API keys securely | ✅ ⚙️ | Keys only in `core/config.php` (never in git, blocked from the web); browser code holds no secret keys (only the public Razorpay key id) |
| Check environment variables / config | ✅ | Admin → Security self-checks: HTTPS, debug off, demo payments off, installer locked, encryption key set, admin two-factor, database rights, backups, PHP version |
| Parameterised queries (SQL injection) | ✅ | Every query uses PDO prepared statements with real prepares; the few table/column names in SQL come from fixed lists; tested with SQL payloads |
| Validate forms / all input | ✅ | Lengths, formats (dates, phones, e-mails, blood groups), allowed values, size caps; JSON only |
| Restrict file uploads | ✅ | Scanned photos never leave the phone; the only upload is the admin medicine list (text only, ≤ 7 MB, ≤ 20,000 rows, admin with two-factor); tested with a binary file |
| Verify prices on the backend | ✅ | Premium price from server settings; pharmacy prices from the shop's stock, never above MRP; vouchers valued by the family server; tested by sending a fake amount |
| Mass assignment / block field tampering | ✅ | Every write names its fields; tested (sign-up with role/plan, a foreign user_id) |
| Lock record access / tenant isolation | ✅ | Every family query is scoped to the household, every pharmacy query to the staff member's branch/business; tested both ways |
| Trim API responses | ✅ | Responses are built field by field; tested that no password hash, two-factor secret, token hash or encrypted nominee leaves the server |
| Encrypt sensitive data | ✅ | Family: health conditions, emergency contacts, phones, nominee, admin two-factor secret. Pharmacy: customer phones and addresses, two-factor secrets. Backups encrypted |
| Escape user content (XSS) | ✅ | `esc()` / `textContent` in every screen, `htmlspecialchars` in PHP pages, strict CSP (own scripts only); tested with script payloads |
| Insecure deserialisation | ✅ | No `unserialize()` or `eval()` anywhere (tested); caches store JSON |
| Command injection | ✅ | The only shell call (nightly Python forecasts) uses fixed arguments with `escapeshellarg`; tested that no shell command uses request data |
| Prompt injection | ✅ | User text fenced as untrusted; the AI has no tools and changes nothing |
| Unpermissioned AI access | ✅ | AI helper only after the family switches it on (recorded consent); admin AI only for admins with two-factor |

### Network and transport security
| Item | Status | Where / how it is proved |
|---|---|---|
| Enforce HTTPS | ✅ ⚙️ | Redirect and HSTS (1 year, subdomains) in `.htaccess` for both apps; needs an SSL certificate on the host |
| Restrict CORS | ✅ | No cross-origin access at all (no CORS headers); server-to-server calls are signed (HMAC); tested with a foreign Origin and a preflight |
| Security headers | ✅ | CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, COOP, CORP, no X-Powered-By; tested on pages and APIs of both apps |
| CSRF protection | ✅ | Token on every change plus Origin check; tested |

### Monitoring and hardening
| Item | Status | Where / how it is proved |
|---|---|---|
| Monitor activity / log events | ✅ | `security_events` (family) and `audit_log` (pharmacy): failed sign-ins, two-factor failures, blocked requests, bots, admin sign-ins, password changes, privacy answers |
| Security monitoring | ✅ | Admin → Security (events and configuration checks); the daily e-mail digest lists the day's security events and any failing security setting; the autopilot's anomaly detection watches the daily numbers (sign-ups, visits, payments) |
| Check for exposed files | ✅ | `core/`, config, keys, backups, `.git`, `.env`, `.htaccess`, SQL, logs blocked; tested on both apps |
| Scan git history for secrets | ✅ | Full history scanned (CI does it on every push); no keys found, so nothing to purge or revoke |
| Revoke exposed keys / purge git secrets | ➖ | None were exposed. If one ever is: rotate it at the provider first, then remove it from history |
| Disable debug mode | ✅ | `display_errors` off in code and `.htaccess`; demo/debug switches off by default; tested that errors show no stack traces or paths |
| Update dependencies / vulnerable dependencies | ✅ | No npm or Composer packages at run time; GitHub Actions updated; Dependabot watches the workflows |
| Malicious packages / remove unused packages | ✅ | Nothing installed at run time; two vendored libraries (jsQR, qrcode-generator) with their licences |
| Audit third-party SDKs | ✅ | Razorpay (payment page only), Google Analytics (only after Accept), Tesseract OCR from jsDelivr (only when scanning; pinned to 5.1.1), Google Fonts (see residual risks) |
| Excessive DB permissions | ✅ ⚙️ | `docs/db-grants.sql`: one user per app, own database only, no ALL/SUPER/FILE; the tests run with these limited users; Admin → Security warns otherwise |
| Missing audit logs | ✅ | Admin actions, privacy answers, staff actions, logins logged |
| Backups / restore | ✅ | `tools/backup.php`, `tools/restore.php` in both apps, weekly by cron; restore verified (49 tables, data identical); tampered file and wrong key refused |
| Exposed internal dashboards | ✅ | Admin dashboards need an admin session with two-factor; `noindex`; blocked in robots.txt |
| Bot protection | ✅ | Hidden fields and timing on sign-up and contact forms, rate limits everywhere |
| Unreviewed code | ✅ ⚙️ | Every push runs all suites in GitHub Actions; turn on branch protection so `main` only accepts passing changes |
| Misconfigured OAuth | ➖ | No OAuth / social sign-in is used |
| Use public DB key / enable RLS | ➖ | These are Supabase / Firebase settings. Here the database is never reachable from the browser; row-level isolation is enforced in every query and tested (see tenant isolation) |
| Website crash with many users | ✅ | Load test: 0 errors at 10, 50, 100, 200 and 400 users at once; overload answered with 503 "busy" (tested with the database limited to 6 connections) |

### Privacy and legal (family app, pharmacy network, admin)
| Item | Status | Where |
|---|---|---|
| Privacy policy | ✅ | `/privacy` (updated today); pharmacy `/privacy` (new) |
| Terms of service | ✅ | `/terms`; pharmacy `/terms` (new) |
| Refund policy | ✅ | `/refund-policy`: 7-day money-back, no auto-renewal |
| Cookie policy and consent banner | ✅ | `/cookie-policy`; banner in 10 languages, analytics only after Accept, Reject as visible as Accept, choices logged |
| Data privacy tab (ISO structure) | ✅ | App → Account → Privacy: record of processing (14 kinds of data with purpose, basis, recipients, retention, location, protection), choices, rights, requests with reference numbers and due dates, nominee, the 11 ISO/IEC 29100 principles, consent history; Admin → Privacy requests |
| Database for collected information | ✅ | `privacy_register`, `consent_records`, `privacy_requests`; nominee fields (encrypted) |
| Check form consents | ✅ | Sign-up (notice, 18+, optional news), contact form notice, pharmacy sign-up (terms and notice), AI helper switch, per-person pharmacy sharing |
| Collect only needed data | ✅ | Only e-mail, name and password are required; everything else optional |
| Age consent for kids | ✅ | 18+ at sign-up; a child's details need a recorded parent/guardian consent |
| Unsubscribe link | ✅ | One-click, signed, in every optional e-mail |
| Data deletion option | ✅ | Delete account (erases the family; the pharmacy network is told to forget it); each optional item can be switched off |
| Business details | ✅ ⚙️ | Footer: business name, address, GSTIN, registration number, e-mail (fill in `core/config.php`) |
| No dark patterns / hidden fees / fake reviews / unsupported claims | ✅ | Equal cookie buttons; "Prices include GST"; no reviews or testimonials; no "certified" or ranking claims (ISO wording says design aim, not certification) |
| Alt text, colour contrast, keyboard navigation | ✅ | All images have alt text; all text colours pass WCAG AA in light and dark; visible focus; skip-to-content links |
| Licensed images | ✅ | Only the project's own screenshots, generated 3D models and SVG icons |

## 3. Load test

`tests/load_test.py` (Python standard library). Each user signs in, opens the app, ticks doses and browses medicines, with pauses like a person. Local test machine, PHP built-in server with 16 workers, MariaDB 11.4.

| Users at once | Requests/s | Median | 95% under | Errors |
|---|---|---|---|---|
| 10 | 78 | 5 ms | 13 ms | 0 |
| 50 | 325 | 11 ms | 114 ms | 0 |
| 100 | 482 | 68 ms | 205 ms | 0 |
| 200 (after caching) | 704 | 135 ms | 301 ms | 0 |
| 400 (after caching) | 665 | 421 ms | 737 ms | 0 |

Above about 200 users at once, requests wait in line rather than fail. On a real host the numbers depend on the number of PHP workers and the database; repeat the test there with `--users` set to the expected peak.

## 4. Residual risks and what the owner must do on the live server

1. **HTTPS certificate** on both domains (free with Let's Encrypt on most hosts). Until then the HTTPS redirect cannot work.
2. **`core/config.php`**: set `data_key` (and keep a copy offline: without it encrypted fields and backups cannot be read), business details, `privacy_email`; keep `payments_demo` and `dev_show_codes` false.
3. **Database users** from `docs/db-grants.sql`; never connect as root.
4. **Copy backups off the server** every week (the server keeps 8 encrypted copies; a backup on the same server does not survive losing the server).
5. **Turn on two-factor** for every administrator on first sign-in (the dashboard insists).
6. **GitHub**: enable branch protection on `main` (require the Test workflow), secret scanning and private vulnerability reporting (see `SECURITY.md`).
7. **OCR script integrity**: the Tesseract script loads from jsDelivr pinned to version 5.1.1 but without a Subresource Integrity hash (it could not be computed in the build sandbox). Compute it once and add `s.integrity` in `loadScript()`:
   `curl -s https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js | openssl dgst -sha384 -binary | openssl base64 -A`
8. **Google Fonts** are loaded from Google on every page, which shares visitors' IP addresses with Google before consent. Self-hosting the two fonts (Zen Maru Gothic, Noto Sans) removes this.
9. **Independent penetration test** before a large launch. This review was done by the developer's tools, not an outside tester.
10. **Translations of the record of processing**: the Privacy tab's buttons and labels are in 10 languages; the detailed register rows are in English for now.

## Tests

| Suite | Command | Checks |
|---|---|---|
| Security (attacks and protections) | `DB_CMD="mysql -uroot -p… aushadhbox" bash tests/security_test.sh http://127.0.0.1:8080 http://127.0.0.1:8090` | 167 |
| API, security and features | `bash tests/api_test.sh` | 221 |
| Browser journey, phone and desktop | `node tests/e2e.js` | 108 |
| SEO and site health | `php tests/seo_check.php` | 271 |
| Autopilot, safety, Care+ | `php tests/autopilot_test.php` | 90 |
| Prescription reader, Bluetooth meters, health imports | `node tests/rx_parse_test.js` etc. | 68 |
| Pharmacy API and security | `bash pharmacy-platform/tests/api_test.sh` | 132 |
| Pharmacy money rules | `php pharmacy-platform/tests/unit_test.php` | 21 |
| Forecasts (Python), sync agent (Java), fridge sensor (MicroPython) | see `docs/TEST-REPORT.md` | 42 |
| Load | `php tests/load_seed.php 200 && python3 tests/load_test.py --users 10,50,100,200` | 0 errors |
