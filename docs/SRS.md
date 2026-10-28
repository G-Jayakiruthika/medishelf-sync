# Software Requirements Specification — MediShelf-Sync v3

| | |
|---|---|
| Product | MediShelf-Sync: family medicine cabinet web app and website |
| Version | 3.0 (October 2026) |
| Audience | Client, developers, testers |

## 1. Purpose and scope
Indian households keep many medicines with tiny, English-only labels and forget expiry dates. MediShelf-Sync lets a family record every medicine (by scanning or typing), see at a glance what is safe, due soon or expired, get dose and expiry reminders, and share one cabinet across family members' phones, in 10 Indian languages. The business earns through a low-cost Premium plan.

Out of scope: selling medicines, prescriptions, medical advice, teleconsultation.

## 2. Users
| User | Goal |
|---|---|
| Visitor | Learns about the product, reads medicine/guide pages from Google, signs up |
| Free user | Tracks up to 4 people and 30 medicines |
| Premium owner | Pays, shares the cabinet with up to 5 more accounts, prints reports, gets weekly e-mails |
| Family member (joined) | Uses the owner's shared cabinet from their own account |
| Administrator | Monitors revenue, traffic, messages; imports medicine lists |

## 3. Functional requirements

### 3.1 Public website
- FR-W1 Home and pricing pages in en, hi, bn, mr, te, ta, gu, kn, ml, pa with language URLs (`/ta/`, `/ta/pricing`) and hreflang.
- FR-W2 About, Help, Contact (form with spam protection), Privacy, Terms, Refund and Cookie policy pages using business details from configuration.
- FR-W3 Medicine directory: search, A–Z browse, pagination; one page per medicine with ingredient, pack size, form, code, storage, expiry guidance and related products.
- FR-W4 Ingredient guides (74) with uses, Rx status, storage, safety note and FAQ.
- FR-W5 Articles section; adding an article requires editing one content file.
- FR-W6 XML sitemap, robots.txt, canonical URLs, Open Graph and structured data on every page; 404 page.

### 3.2 Cookie consent and analytics
- FR-C1 A banner with Accept, Reject and Choose (per-category) appears until the visitor decides; it can be reopened from every page footer.
- FR-C2 Only necessary storage (sign-in session, consent choice, language) is used without consent.
- FR-C3 First-party statistics and optional Google Analytics load only after analytics consent; the server rejects statistics without a matching consent cookie.
- FR-C4 Every choice is logged with a random ID, choice and time. Rejecting or withdrawing deletes that visitor's past statistics.

### 3.3 Accounts
- FR-A1 Sign-up requires name, e-mail, password (8+) and explicit consent to the privacy notice; consent version is recorded.
- FR-A2 Sign-in, sign-out, password change, password reset by e-mail link (1 hour).
- FR-A3 Download all my data (JSON); delete account permanently with password.
- FR-A4 Re-consent prompt when the privacy notice version changes.

### 3.4 Cabinet (app)
- FR-M1 Add medicine by photo, live camera, QR/barcode or typing; read batch, Mfg and Exp from label text; match to the medicine list.
- FR-M2 Status per medicine: Safe, Use soon (≤ 90 days), Expired; filters by person.
- FR-M3 Family members with relation and allergies.
- FR-M4 Dose times (morning, afternoon, evening, bedtime), daily ticks, push reminders.
- FR-M5 Medicine notebook per person with copy-for-doctor; emergency kit checklist.
- FR-M6 Voice assistant in the selected language.
- FR-M7 Welcome tour on first sign-in.

### 3.5 Plans and payments
- FR-P1 Free plan limits: 4 people, 30 medicines, 1 account. Premium: 15 people, unlimited (fair use 2,000) medicines, 6 accounts.
- FR-P2 Hitting a limit shows the upgrade dialog with the reason.
- FR-P3 Owner chooses 1 month or 1 year; payment through Razorpay Checkout; server verifies the signature before activating.
- FR-P4 Webhook activates Premium if the browser closes; amount is checked; activation is idempotent.
- FR-P5 Premium extends from the later of today or the current end date. No auto-renewal; e-mail 3 days before the end; automatic return to Free.
- FR-P6 Receipt e-mail after payment.

### 3.6 Family sharing (Premium)
- FR-F1 Owner creates an 8-character invite code valid 7 days, single use; share link `/app#join=CODE`.
- FR-F2 A joining account sees and edits the owner's cabinet; its own earlier data is kept but hidden.
- FR-F3 Owner can remove members; members can leave. Only the owner can pay.

### 3.7 Premium extras
- FR-X1 Printable report per person or everyone: allergies, medicines, dose times, batch, expiry status, last 30 days of doses.
- FR-X2 Weekly expiry e-mail on Mondays (opt-out in Account).

### 3.8 Administration
- FR-D1 Dashboard: revenue (30 days, all time), Premium families, conversion, users, sign-ups and visitors charts, top pages, sources, button clicks, devices, recent payments, contact messages, service status.
- FR-D2 Import medicine lists (CSV) without duplicates.

## 4. Non-functional requirements
| ID | Requirement |
|---|---|
| NFR-1 Performance | Public pages are server-rendered HTML with no framework; home page under 100 KB of HTML/CSS/JS before images. |
| NFR-2 Mobile | Works from 360 px wide; no horizontal scrolling; installable as an app (PWA); offline shell. |
| NFR-3 Accessibility | Semantic headings, labels on every input, keyboard focus styles, alt text, reduced-motion support, skip link. |
| NFR-4 Security | bcrypt passwords; CSRF token on every change; prepared SQL; household ownership checks on every record; rate limits on sign-in, sign-up, password reset, contact, payments and invites; CSP and security headers; HTTPS only; private code folder. |
| NFR-5 Privacy | DPDP Act 2023 and DPDP Rules 2025: notice, consent, access, erasure, grievance officer, 1-year log retention, breach notice. No ads, no sale of data; scanned photos never leave the phone. |
| NFR-6 Hosting | Runs on shared hosting with PHP 8.1+ and MySQL 8/MariaDB 10.6+; no Node, Composer or build step. |
| NFR-7 Languages | All customer-facing app text and the home/pricing pages in 10 languages; legal pages in English. |

## 5. Data model (summary)
`users` (plan, plan_until, household_id) · `members` · `cabinet_items` · `dose_logs` · `reminder_settings` · `push_subscriptions` · `reminder_log` · `kit_checks` · `medicine_info` · `medicine_catalog` · `invites` · `payments` · `cookie_consents` · `analytics_events` · `contact_messages` · `consent_log` · `login_attempts` · `password_resets`. Full definitions: `public_html/core/database/schema.sql`. Diagram: DESIGN.md.

## 6. Assumptions and constraints
- The owner provides the domain, hosting, Razorpay account (KYC) and business details.
- Medicine information is general and from public sources; the product is not a medical device.
- Payment fees, GST registration and invoicing rules depend on the owner's business status and should be confirmed with a chartered accountant.
