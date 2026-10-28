# Going live: domain, hosting, payments

This guide takes you from the zip file to a live site where customers can sign up and pay.
No coding is needed. Budget **2 hours** for the site, plus **2–5 working days** for Razorpay to approve your account.

You only need to do three things yourself (they need your identity and money): **buy the domain + hosting**, **complete Razorpay KYC**, and **fill in your details in one settings file**.

---

## 1. Buy hosting and a domain (Hostinger recommended)

Any host with **PHP 8.1+**, **MySQL 8 / MariaDB 10.6+**, **cron jobs** and **free SSL** works. Hostinger's *Premium Web Hosting* plan (about ₹150–250/month on a 12–48 month term) includes all of these plus a free domain for the first year and free e-mail accounts.

1. On hostinger.in, buy **Premium Web Hosting** (or higher). Choose a 12-month or longer term for the free domain.
2. Pick your domain, e.g. `aushadhbox.in`. Short `.in` or `.com` names are best. Avoid trademarks of other companies.
3. In **hPanel → Websites → Add website**, choose *Empty PHP/HTML website* for your domain.

> Prefer another host? Everything below uses standard cPanel/hPanel features, so the steps are the same with different menu names.

## 2. Create the database

hPanel → **Databases → MySQL Databases**:
1. Database name `aushadhbox`, user `aushadhbox`, a strong password. Hostinger adds a prefix like `u123456789_`.
2. Write down the full **database name**, **user name** and **password**. The host is `localhost` on Hostinger.

## 3. Fill in your settings

1. Unzip `AushadhBox-v3.zip` on your computer.
2. In `public_html/core/`, copy `config.example.php` to **`config.php`** and open it in Notepad.
3. Fill in:

| Setting | What to put |
|---|---|
| `db_name`, `db_user`, `db_pass` | From step 2 |
| `app_url` | `https://your-domain.in` (no slash at the end) |
| `site_name` | The brand name shown everywhere (default AushadhBox) |
| `business_name`, `business_address`, `support_email`, `support_phone`, `grievance_officer` | Your real legal name and address. These appear on the Contact, Privacy, Terms and Refund pages, which Razorpay checks and Indian law requires. |
| `price_month`, `price_year` | Premium prices in rupees (default 49 and 399) |
| `cron_key` | A long random password (40+ characters). It is also the setup key for the installer. |
| `consent_version` | Leave as is. Change it only when you change the privacy policy; users are asked to agree again. |

Leave Razorpay, SMTP and the others empty for now.

## 4. Upload

hPanel → **Files → File Manager** → open `public_html` → **Upload** → upload the zip → right-click → **Extract**. Then move the **contents** of the extracted `public_html` folder into your site's `public_html` (so that `index.php` sits directly inside it).

Check that the hidden files `.htaccess` and `core/.htaccess` are there (File Manager → Settings → *Show hidden files*).

Do **not** upload `docs/`, `tools/` or `tests/`. They are for you.

## 5. Turn on HTTPS

hPanel → **Security → SSL** → install the free SSL for your domain and wait until it says *Active* (a few minutes). The `.htaccess` file then sends every visitor to `https://` automatically. Camera scanning, installing the app and notifications only work on HTTPS.

## 6. Run the installer

1. Open `https://your-domain.in/install.php`.
2. Enter the **setup key** (your `cron_key`), your name, admin e-mail and a strong admin password.
3. It creates 18 tables, loads 74 ingredient guides and the 2,111-medicine Jan Aushadhi list, and makes your admin account.
4. **Delete `install.php`** in File Manager straight after.

## 7. Check it works (10 minutes)

- [ ] `https://your-domain.in` shows the home page, and the cookie banner appears.
- [ ] Switch to தமிழ் in the language menu: the home page changes language.
- [ ] `https://your-domain.in/medicines/` lists medicines; open one.
- [ ] Click **Start free**, create a test account, scan the sample pack, save it.
- [ ] Sign in on your phone: the same cabinet appears.
- [ ] `https://your-domain.in/admin.html` with the admin account shows the dashboard.
- [ ] `https://your-domain.in/core/config.php` shows **403 Forbidden**.
- [ ] Run the automatic check from your computer (needs PHP): `php tests/seo_check.php https://your-domain.in` → *all passed*.

## 8. Cron job (reminders, e-mails, clean-up)

hPanel → **Advanced → Cron Jobs** → *Custom*, every **5 minutes**:
```
/usr/bin/php /home/u123456789/domains/your-domain.in/public_html/cron.php YOUR_CRON_KEY
```
(hPanel shows your exact home path.) This also runs the automations (refill, missed-dose, course-finished and clean-up alerts) and, after you upload a new version, adds any new database tables or columns by itself. It sends dose reminders and Premium weekly e-mails, sends the "Premium ends in 3 days" e-mail, ends expired Premium plans, and deletes old logs as the DPDP Rules require.

## 9. E-mail (password reset, receipts, weekly e-mails)

hPanel → **Emails** → create `support@your-domain.in` and `no-reply@your-domain.in`. Then in `config.php`:
```
'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465,
'smtp_user' => 'no-reply@your-domain.in', 'smtp_pass' => '…',
'mail_from' => 'no-reply@your-domain.in', 'mail_from_name' => 'AushadhBox',
```
Contact-form messages are forwarded to `support_email` and also kept in the admin dashboard.

## 10. Phone notifications

On your computer run `php tools/make_vapid_keys.php` and paste the two values into `vapid_public` / `vapid_private`. Set `vapid_subject` to `mailto:support@your-domain.in`.

## 11. Taking payments (Razorpay)

Razorpay reviews your website before approving, so do this **after** the site is live with your real details on the Contact, Terms, Privacy and Refund pages (they are already built; they read your details from `config.php`).

1. Sign up at **razorpay.com** and complete **KYC**: PAN, bank account, and business details. Individuals can apply as a sole proprietor; Razorpay will tell you which documents it needs for your case.
2. Website details for the form:
   - Website: `https://your-domain.in`
   - Pricing page: `/pricing` · Contact: `/contact` · Terms: `/terms` · Privacy: `/privacy` · Refund: `/refund-policy`
   - Business category: *Software / SaaS* (subscription software). You do **not** sell medicines, so no drug licence is involved.
3. **Test first.** Dashboard → switch to **Test Mode** → *Account & Settings → API Keys* → generate. Put the test `key_id` and `key_secret` in `config.php`. Pay in the app with Razorpay's test card or test UPI ID `success@razorpay`.
4. **Webhook.** Dashboard → *Webhooks* → Add: URL `https://your-domain.in/webhook.php`, a secret of your choice (copy it into `razorpay_webhook_secret`), events **payment.captured**, **order.paid**, **payment.failed**. This makes Premium start even if a customer closes the browser right after paying.
5. **Go live.** After approval, switch to Live Mode, generate live keys, replace the test keys in `config.php`, and create the same webhook in Live Mode.
6. Pay ₹49 yourself once, check the receipt e-mail, then refund yourself from the Razorpay dashboard.

Money lands in your bank account on Razorpay's settlement schedule (usually T+2 working days). Razorpay charges a fee per payment (about 2%; check their current pricing).

`payments_demo` must stay **false** on the live site.

## 12. Google (search and statistics)

1. **Search Console** (search.google.com/search-console): add your domain → choose *URL prefix* → *HTML tag* → copy the `content="…"` code into `google_site_verification` → Verify. Then **Sitemaps** → submit `sitemap.xml`.
2. **Optional Google Analytics 4**: create a property, copy the `G-XXXX` ID into `ga4_id`. It loads **only** for visitors who click *Accept* on the cookie banner. The built-in dashboard (Admin) already counts visits without Google.

## 13. Backups

hPanel → **Files → Backups** (weekly backups are included on Premium plans). Also download a database copy monthly: hPanel → Databases → phpMyAdmin → Export.

## 14. Updating the medicine list

PMBJP publishes updated lists. Either upload a CSV (`drug_code,generic_name,unit_size`) in **Admin → Import a medicine list**, or convert the official PDF on your computer: `php tools/import_list.php new-list.pdf janaushadhi-2026-06 --out=rows.csv` (needs `pdftotext`), then upload `rows.csv`.

## 15. Automatic deployment from GitHub (optional)
The repository has three GitHub workflows (in `.github/workflows/`):
- **Test** runs every check on each push. A red ✗ on GitHub means something broke.
- **Deploy** uploads changed files to Hostinger after the tests pass. To switch it on: GitHub repo → Settings → Secrets and variables → Actions:
  - Secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` (hPanel → Files → FTP Accounts)
  - Variables: `DEPLOY_ENABLED` = `true`, `SITE_URL` = `https://aushadhbox.in`, and `FTP_SERVER_DIR` if your site folder is not `public_html/`
  Your `core/config.php` on the server is never overwritten.
- **Live site health** checks the live site every morning at 8:00 and e-mails you if something is wrong (site down, broken page, certificate expiring, installer open).

## Troubleshooting

| Problem | Fix |
|---|---|
| Blank page or "Something went wrong" | hPanel → Advanced → PHP Configuration: choose PHP 8.2 or 8.3. Check `config.php` database details. |
| "Not installed" message | `core/config.php` is missing; repeat step 3. |
| Pages other than home show 404 | `.htaccess` was not uploaded (hidden file). |
| Camera does not open | The site must be on `https://`. |
| Premium did not start after paying | Check Admin → Recent payments. If the payment shows *created*, the webhook is not set up (step 11.4). |
| Many sign-ups blocked from one network | Normal limit is 5 sign-ups per hour per network. Behind Cloudflare set `ip_header` to `HTTP_CF_CONNECTING_IP`. |
