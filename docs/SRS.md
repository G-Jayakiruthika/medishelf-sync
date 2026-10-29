# Software Requirements Specification — AushadhBox v3

| | |
|---|---|
| Product | AushadhBox: family medicine cabinet web app and website |
| Version | 3.0 (October 2026) |
| Audience | Client, developers, testers |

## 1. Purpose and scope
Indian households keep many medicines with tiny, English-only labels and forget expiry dates. AushadhBox lets a family record every medicine (by scanning or typing), see at a glance what is safe, due soon or expired, get dose and expiry reminders, and share one cabinet across family members' phones, in 10 Indian languages. The business earns through a low-cost Premium plan.

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

### 3.8 Adherence: taken, skipped, not confirmed
- FR-A1 Every dose is *taken*, *skipped with a reason* (felt fine, side effects, ran out, doctor stopped it, other) or *not confirmed*.
- FR-A2 Skip reasons act by themselves: side effects → the family is told; ran out → tablet count 0 (shopping list, refill alert); doctor stopped it → reminders end today.
- FR-A3 Halfway to the family alert, the person whose medicine it is (if they have their own login) gets "Did you take your … medicines?" with Taken / Skip buttons. The family is alerted only if there is still no answer.
- FR-A4 Weekly report per person (app, Sunday notification, doctor report): taken / skipped / not confirmed, percentage, most-missed time, skip reasons, doses confirmed by a pillbox.

### 3.9 Smart pillbox (IoT)
- FR-P1 Add up to 6 pillboxes per family (4 lids, or 1 lid), each for one person or everyone; pair with a 6-digit code valid for 30 minutes.
- FR-P2 The box (ESP32 + reed switch per lid) reports lid openings with their time; it keeps up to 64 while offline.
- FR-P3 An opening from 60 minutes before to 3 hours after the dose time ticks the doses in that slot ("by pillbox") and lowers the tablet count; a second opening more than 5 minutes later warns the family; other openings are only recorded.
- FR-P4 The box lights the due lid and beeps at the dose time, +10 and +20 minutes, until it is opened or the button is pressed.
- FR-P5 Alerts: box offline for a day, battery at 15 % or less, "fill the box" on Sunday evening.
- FR-P6 Security: per-box secret token stored only as a SHA-256 hash; a new pairing code revokes the old token; wrong codes and tokens are rate-limited; a box sees only its own family's dose times.
- FR-P7 "Try without hardware" in the app and a command-line simulator behave like a real box. Build guide, wiring and firmware: `hardware/`.

### 3.10 Autopilot (admin automation)
- FR-AP1 Ask-your-data: plain-language questions answered from a fixed catalogue of read-only metrics (no free SQL), with chart and comparison to the previous period; optional AI only chooses the metric and writes the sentence.
- FR-AP2 Anomaly detection every morning on 12 daily metrics (median and MAD of the previous 28 days, robust z ≥ 3.5 and a minimum absolute change); stored as insights with a severity and a suggested action.
- FR-AP3 Daily digest at 9 am to every admin (e-mail and push); growth tips daily.
- FR-AP4 Support autopilot: ticket number, category (billing, privacy, bug, medical, account, feature, other) in English, Hindi and Tamil, priority, sentiment, reply target, acknowledgement e-mail, drafted reply using what the site knows about the sender; urgent medical messages are told to call 112.
- FR-AP5 Health monitor hourly with self-healing: database migrations, cache, old logs, linking medicines to ingredients, Razorpay reconciliation of stuck checkouts; warnings for weak cron key, payment test mode on a live site, installer left on the server, e-mail/push missing, disk space.
- FR-AP6 Family health score and segments; automatic helpful reminders (first medicine, expiring medicines, near the Free limit), at most one a week per family; switchable.
- FR-AP7 IndexNow weekly submission of all sitemap pages; admin audit log; AI usage limit per month.

### 3.11 Medicine safety (families)
- FR-S1 On every change: same ingredient twice, two of the same class, curated risky pairs, take-apart pairs sharing a dose time, allergy matches; shown in 10 languages with "ask your doctor or pharmacist".
- FR-S2 Brand names map to ingredients (200+ common Indian brands).
- FR-S3 Optional AI helper per account (explicit consent), answers only from the family's cabinet and general facts, never changes doses, sends emergencies to 112.

### 3.12 Ideas from Japanese medication-notebook apps (docs/IDEAS-FROM-JAPAN.md)
- FR-J1 Health diary: blood pressure, sugar (fasting / after a meal / any time), weight, temperature (°F converted), SpO₂, pulse; validated ranges; flags normal / low / high / urgent; urgent readings alert the family within the hour.
- FR-J2 Follow-up check-ins on day 3 and day 10 of every new scheduled medicine (day 3 only for courses under 10 days); answers better / same / worse / side effect with a note; problems alert the family and bring the next check-in to 2 days.
- FR-J3 Doctor visits: reminders the evening before (7 pm) and 2 hours before; visit sheet per person with medicines, tablets left and days covered, 30-day adherence, follow-up answers, skip reasons, reading summaries, safety alerts and questions; print and share.
- FR-J4 Emergency card per person (blood group, allergies, conditions, medicines, contact) with a QR code of the same text; works offline from a copy saved on the phone; home-screen shortcut; save as image.
- FR-J5 My pharmacy (name and WhatsApp number): send the shopping list in one tap.
- FR-J6 Watch-over: no sign of life for 12 / 24 / 48 hours (or off) from someone with their own login or a pillbox → one alert a day to the rest of the family.
- FR-J7 Printable weekly medicine calendar.

### 3.13 Household extras (like EPARK お薬手帳)
- FR-H1 A person's date of birth under 17 years creates India's Universal Immunization Programme schedule (24 doses, birth to 16 years); reminders 3 days before and on the due day; mark given (with undo) or mark all earlier doses given.
- FR-H2 Medical expenses per person and category (medicines, tests, doctor fees, check-up, other); scanned pharmacy bills with a total add an expense by themselves; totals for the Indian financial year (April–March); CSV download with a UTF-8 BOM.
- FR-H3 Read-only doctor link to the visit sheet (1, 24 or 72 hours; default 24), no sign-in, never indexed, view counter, up to 10 active links, revocable at once.
- FR-H4 Bluetooth meters via Web Bluetooth using the standard health services (blood pressure 0x1810, thermometer 0x1809, weight 0x181D, glucose 0x1808, pulse oximeter 0x1822); readings save to the health diary by themselves.
- FR-H5 Lab results in the health diary: HbA1c, cholesterol, haemoglobin, with flags.
- FR-H6 Dose streak per person: days in a row on which every planned dose was taken or deliberately skipped; badges at 7, 30 and 100 days.

### 3.14 AushadhBox card and AushadhBox Pharmacy (like harmo; docs/IDEAS-FROM-JAPAN.md)
- FR-K1 One card code per person (12 characters from an unambiguous alphabet, shown as XXXX-XXXX-XXXX; QR text `AUSHADHBOX:<code>`). The code holds no personal data. "New card" replaces it and the old code stops working.
- FR-K2 Two consents per person, both off by default: pharmacies may add medicines; pharmacies may see medicines, allergies, conditions, blood group and check-in answers. With both off the card is refused (`not_shared`).
- FR-K3 Pharmacy accounts register with name, drug licence number, city, mobile, e-mail and password and must accept the pharmacy terms. They can scan only after an admin approves them; admins can suspend. Pharmacy sign-in is separate from family accounts.
- FR-K4 A scan shows a shortened name (first name and initials) and what the family allowed. Every scan, view, addition and refill update is logged and shown to the family with the pharmacy name and time; the family can block or unblock a pharmacy per person.
- FR-K5 Safety pre-check before dispensing: the new medicines are checked against everything the person takes (same rules as FR-S1) without saving anything; without view consent, the names of the person's other medicines are replaced by "a medicine they already take". A high-level alert needs a second confirmation in the portal.
- FR-K6 Dispensing (needs "may add"): medicines are added with dose times, course end, pack count, batch, expiry and "Given by <pharmacy>"; follow-up check-ins are scheduled; the family's plan limits apply; the household gets a notification.
- FR-K7 Refill requests from the family to a pharmacy the card was used at (not blocked): up to 30 medicines, 10 requests a day; the pharmacy marks Ready (family notified), Collected or Cannot prepare (family notified with the phone number).
- FR-K8 The portal lists check-in answers for medicines it gave, only for people with view consent, worst first.
- FR-K9 Rate limits: pharmacy sign-up 5 an hour per IP, sign-in 8 per 15 minutes per e-mail, scans 120 an hour per pharmacy.

### 3.15 Smartwatch and imports
- FR-W1 Dose reminders reach any watch that mirrors phone notifications; setup notes for Wear OS, Galaxy, Apple Watch, Fitbit, Garmin, Amazfit, Noise, boAt, Fire-Boltt and Mi Band.
- FR-W2 Watch page `/watch`: the dose time due now (from 2 hours before to 4 hours after its time, otherwise the latest unfinished one today), its medicines, one Taken button, the day's times; refreshes every minute; 10 languages; usable at 240 × 240 px.
- FR-W3 Tap-to-tick link `/tick/<secret>` per account (stored as a SHA-256 hash; new link replaces the old; can be stopped). GET shows a button and never ticks by itself (`?auto=1` ticks after the page loads, for NFC stickers and QR labels); POST ticks the doses due now and answers JSON or, with `?format=text`, one sentence for Siri / Google / Bixby. 30 POSTs per 10 minutes per IP.
- FR-W4 Import from Apple Health (export.zip or export.xml, read as a stream in the browser), Samsung Health / Google Fit / Mi Fitness CSV and Fitbit JSON: pulse (at most one per hour), SpO₂, weight (lb → kg), blood pressure (paired halves), glucose (mmol/L → mg/dL) and temperature (°F → °C) from the last 365 days; the server re-checks ranges, skips duplicates and accepts up to 2,000 readings per call.
- FR-W5 Barcodes: EAN-8/13 and GS1 GTIN-14 (with check digit) found while scanning are remembered with the medicine name after a save; later scans of the same barcode by any family get the name filled in.

### 3.16 Administration
- FR-D1 Dashboard: revenue (30 days, all time), Premium families, conversion, users, sign-ups and visitors charts, top pages, sources, button clicks, devices, recent payments, contact messages, service status.
- FR-D2 Import medicine lists (CSV) without duplicates.
- FR-D3 Pharmacies panel: list with licence, contact, families linked and medicines added; approve (e-mail to the pharmacy) or suspend; every change in the admin audit log. A new registration appears as an insight and an e-mail to the support address.

## 4. Non-functional requirements
| ID | Requirement |
|---|---|
| NFR-1 Performance | Public pages are server-rendered HTML with no framework; home page under 100 KB of HTML/CSS/JS before images. |
| NFR-2 Mobile | Works from 360 px wide; no horizontal scrolling; installable as an app (PWA); offline shell. |
| NFR-3 Accessibility | Semantic headings, labels on every input, keyboard focus styles, alt text, reduced-motion support, skip link. |
| NFR-4 Security | bcrypt passwords; CSRF token on every change; prepared SQL; household ownership checks on every record; rate limits on sign-in, sign-up, password reset, contact, payments and invites; CSP and security headers; HTTPS only; private code folder. |
| NFR-5 Privacy | DPDP Act 2023 and DPDP Rules 2025: notice, consent, access, erasure, grievance officer, 1-year log retention, breach notice. No ads, no sale of data; scanned photos and watch export files never leave the phone. Sharing with pharmacies is opt-in per person and purpose, logged and revocable. |
| NFR-6 Hosting | Runs on shared hosting with PHP 8.1+ and MySQL 8/MariaDB 10.6+; no Node, Composer or build step. |
| NFR-7 Languages | All customer-facing app text and the home/pricing pages in 10 languages; legal pages in English. |

## 5. Data model (summary)
`users` (plan, plan_until, household_id, tick_hash) · `members` (account_id, birth_date, card_code, share_add, share_view) · `vaccinations` · `expenses` · `share_links` · `pharmacies` · `pharmacy_links` · `pharmacy_access` · `refill_requests` · `barcodes` · `devices` · `device_events` · `vitals` · `followups` · `appointments` · `system_state` · `insights` · `autopilot_log` · `ai_log` · `audit_log` · `cabinet_items` · `dose_logs` (status, reason, source) · `reminder_settings` · `push_subscriptions` · `reminder_log` · `kit_checks` · `medicine_info` · `medicine_catalog` · `invites` · `payments` · `cookie_consents` · `analytics_events` · `contact_messages` · `consent_log` · `login_attempts` · `password_resets`. Full definitions: `public_html/core/database/schema.sql`. Diagram: DESIGN.md.

## 6. Assumptions and constraints
- The owner provides the domain, hosting, Razorpay account (KYC) and business details.
- Medicine information is general and from public sources; the product is not a medical device.
- Payment fees, GST registration and invoicing rules depend on the owner's business status and should be confirmed with a chartered accountant.
