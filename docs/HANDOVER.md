# Project handover — MediShelf-Sync v3

## 1. Deliverables
| # | Deliverable | Where |
|---|---|---|
| 1 | Public website (10 languages), SEO pages, legal pages | `public_html/` |
| 2 | Web app with Premium, family sharing, report, welcome tour | `public_html/app.html`, `app.js` |
| 3 | Razorpay payments + webhook | `public_html/core/plans.php`, `webhook.php` |
| 4 | Cookie consent + first-party analytics | `public_html/consent.js`, `api.php` |
| 5 | Admin dashboard | `public_html/admin.html` |
| 6 | Database schema (18 tables) + 2,111 medicines + 74 guides | `public_html/core/database/` |
| 7 | Requirements (SRS) | `docs/SRS.md` |
| 8 | Design document, user flows, architecture, data model | `docs/DESIGN.md` |
| 9 | Design system (open in browser) | `docs/design-system.html` |
| 10 | Test report + automated tests | `docs/TEST-REPORT.md`, `tests/` |
| 11 | Deployment guide (Hostinger, Razorpay, Google) | `docs/DEPLOY.md` |
| 12 | User manual | `docs/USER-MANUAL.md` (also on the site at /help) |
| 13 | SEO and growth plan | `docs/SEO-GROWTH.md` |

## 2. What the owner must do (needs your identity, money or legal decisions)
1. **Domain and hosting**: buy them (DEPLOY.md §1).
2. **Business details** in `core/config.php`: legal name, address, support e-mail, grievance officer. They appear on legal pages and are required by Razorpay and the DPDP Act.
3. **Razorpay KYC** and keys (DEPLOY.md §11).
4. **Tax**: ask a chartered accountant whether you need GST registration (generally required once turnover crosses the threshold, and for some online services earlier) and how to issue invoices. Prices are shown as "including taxes".
5. **Legal review**: the Privacy, Terms, Refund and Cookie pages are solid templates written for Indian law (DPDP Act 2023, DPDP Rules 2025, Consumer Protection (E-Commerce) Rules) but are not a lawyer's opinion. Get them reviewed before you scale.
6. **Brand name**: check that "MediShelf-Sync" (or your chosen name, set via `site_name`) is not someone else's trademark on ipindia.gov.in before printing posters.

## 3. Launch checklist
- [ ] HTTPS active; http and www redirect to https://your-domain
- [ ] `install.php` deleted; `/core/config.php` shows 403
- [ ] Business details correct on /contact, /privacy, /terms, /refund-policy
- [ ] `payments_demo` is **false**; Razorpay live keys and live webhook set
- [ ] Paid ₹49 once yourself, received receipt e-mail, refunded it
- [ ] Cron job runs every 5 minutes (Admin shows push ✅ once keys are set)
- [ ] SMTP works: "Forgot password?" e-mail arrives
- [ ] `php tests/seo_check.php https://your-domain` → all passed
- [ ] Sitemap submitted in Google Search Console
- [ ] Admin password is long and unique; only you know it
- [ ] Backups enabled; one database export downloaded

## 4. Running the business: weekly routine (30 minutes)
- Admin dashboard: answer contact messages (Mark as handled), check payments and sign-ups.
- Razorpay dashboard: settlements, refunds requested.
- Publish one article (SEO-GROWTH.md §3).

## 5. Common changes without a developer
| Change | How |
|---|---|
| Prices | `core/config.php` → `price_month`, `price_year` |
| Brand name | `core/config.php` → `site_name` (logo text everywhere) |
| Home/pricing text in any language | `core/i18n_site.php`; then run `php tools/build_assets.php` if you changed the cookie banner text |
| Add an article | Copy a block in `core/content/guides.php`, change slug, title, text |
| App text | `public_html/i18n.js`, `i18n-account.js`, `i18n-premium.js` |
| Free plan limits | `core/plans.php` → `FREE_LIMITS` (also update the pricing page table in `core/pages/pricing.php`) |
| New medicine list | Admin → Import a medicine list |
| Privacy policy changed | Edit `core/pages/static.php`, then raise `consent_version` in config so users agree again |

After editing a `.css` or `.js` file, raise the `?v=` number where it is included (layout.php, app.html, sw.js) so phones load the new version.

## 6. Security notes
- Never share `core/config.php`; it holds database and Razorpay secrets.
- Keep PHP updated in hPanel (8.2 or 8.3).
- If you suspect a data breach: change all passwords and keys, then inform affected users and the Data Protection Board of India without delay (within 72 hours). Privacy policy §7 describes this.

## 7. Known limits
- Legal pages, medicine pages and articles are in English; the home page, pricing, cookie banner and the whole app are in 10 languages.
- Label reading (OCR) works best on clear, flat, well-lit labels; users can always correct or type.
- Bhashini voices need your free API key; until then the phone's own voices are used.
- iPhone push notifications need iOS 16.4+ and the app added to the home screen.
