-- AushadhBox database schema (MySQL 8 / MariaDB 10.6+)
-- Character set utf8mb4 so Tamil, Hindi and all Indian scripts store correctly.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)  NOT NULL,
  email           VARCHAR(160) NOT NULL,
  phone           VARCHAR(15)  NULL,
  password_hash   VARCHAR(255) NOT NULL,
  language        VARCHAR(5)   NOT NULL DEFAULT 'en',
  role            ENUM('user','admin') NOT NULL DEFAULT 'user',
  consent_version VARCHAR(10)  NOT NULL,
  consent_at      DATETIME     NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at   DATETIME     NULL,
  plan            ENUM('free','premium') NOT NULL DEFAULT 'free',
  plan_until      DATE         NULL,                 -- premium is active until the end of this day
  household_id    INT UNSIGNED NULL,                 -- set when this user joined someone else's family cabinet
  weekly_email    TINYINT(1)   NOT NULL DEFAULT 1,
  onboarded       TINYINT(1)   NOT NULL DEFAULT 0,
  last_seen_at    DATETIME     NULL,                 -- last time the app was opened (for activation and win-back nudges)
  ai_opt_in       TINYINT(1)   NOT NULL DEFAULT 0,   -- the person agreed to send questions to the AI assistant
  pharmacy_name   VARCHAR(80)  NULL,                 -- "my pharmacy": prescriptions and shopping lists are sent here on WhatsApp
  pharmacy_phone  VARCHAR(15)  NULL,
  tick_hash       CHAR(64)     NULL,                 -- secret "tap to tick" link (watch, Siri, NFC sticker): SHA-256 of the token
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_household (household_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- People in the household (the account owner is also a member).
CREATE TABLE IF NOT EXISTS members (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(60)  NOT NULL,
  relation   VARCHAR(30)  NULL,
  allergies  VARCHAR(500) NULL,
  is_self    TINYINT(1)   NOT NULL DEFAULT 0,
  birth_date  DATE NULL,                 -- for the vaccination schedule (children)
  blood_group VARCHAR(4)  NULL,          -- emergency card
  conditions  VARCHAR(1600) NULL,        -- e.g. diabetes, high BP (emergency card, doctor report); encrypted (core/security.php)
  emergency_contact VARCHAR(400) NULL,   -- name and phone to call; encrypted
  card_code   VARCHAR(14) NULL,          -- AushadhBox card: random code in the QR (no personal data); "New card" replaces it
  share_add   TINYINT(1)  NOT NULL DEFAULT 0,  -- pharmacies may add the medicines they give (off until the family allows it)
  share_view  TINYINT(1)  NOT NULL DEFAULT 0,  -- pharmacies may see medicines, allergies and check-ins (for interaction checks)
  account_id INT UNSIGNED NULL,          -- this person's own AushadhBox login, if they have one (gets the "did you take it?" check first)
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_members_user (user_id),
  UNIQUE KEY uq_members_card (card_code),
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General information per active ingredient (uses, storage, prescription status).
CREATE TABLE IF NOT EXISTS medicine_info (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingredient  VARCHAR(80)  NOT NULL,
  keywords    VARCHAR(400) NOT NULL,      -- comma separated words that identify it on a label
  use_text    VARCHAR(200) NOT NULL,
  rx          TINYINT(1)   NOT NULL DEFAULT 1,
  storage     VARCHAR(160) NULL,
  note        VARCHAR(300) NULL,
  slug        VARCHAR(100) NULL,
  usual_times VARCHAR(10)  NOT NULL DEFAULT '',   -- common schedule shown as a suggestion (m,a,e,b); '' = when needed / as prescribed
  course_days TINYINT UNSIGNED NULL,              -- common course length for short treatments (antibiotics)
  food        VARCHAR(20)  NULL,                  -- after food / before food / empty stomach
  UNIQUE KEY uq_info_ingredient (ingredient),
  UNIQUE KEY uq_info_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Searchable list of medicines (Jan Aushadhi generics + anything an admin imports).
CREATE TABLE IF NOT EXISTS medicine_catalog (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source       VARCHAR(30)  NOT NULL,     -- e.g. 'janaushadhi-2025-12'
  drug_code    VARCHAR(20)  NULL,
  generic_name VARCHAR(300) NOT NULL,
  unit_size    VARCHAR(80)  NULL,
  dosage_form  VARCHAR(30)  NULL,         -- tablet, capsule, syrup, injection, cream, drops ...
  info_id      INT UNSIGNED NULL,
  search_text  VARCHAR(400) NOT NULL,     -- lower-case normalised name for matching
  slug         VARCHAR(220) NULL,         -- address of the public SEO page
  UNIQUE KEY uq_catalog_source_code (source, drug_code),
  UNIQUE KEY uq_catalog_slug (slug),
  KEY idx_catalog_info (info_id),
  FULLTEXT KEY ft_catalog_search (search_text),
  CONSTRAINT fk_catalog_info FOREIGN KEY (info_id) REFERENCES medicine_info(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Medicines physically in the family's cabinet.
CREATE TABLE IF NOT EXISTS cabinet_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  member_id   INT UNSIGNED NULL,          -- NULL = whole family
  name        VARCHAR(120) NOT NULL,
  catalog_id  INT UNSIGNED NULL,
  info_id     INT UNSIGNED NULL,
  batch_no    VARCHAR(40)  NULL,
  mfg_month   DATE         NULL,          -- stored as first day of the month
  exp_month   DATE         NULL,          -- usable until the END of this month; NULL = not known yet (added from a prescription)
  quantity    VARCHAR(40)  NULL,
  dose_times  VARCHAR(10)  NOT NULL DEFAULT '',   -- any of m,a,e,b
  notes       VARCHAR(300) NULL,
  stock_count SMALLINT UNSIGNED NULL,     -- tablets/doses left; NULL = not counting
  per_dose    TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- how many are used per dose
  course_end  DATE         NULL,          -- last day of a short course (antibiotics); reminders stop after it
  pharmacy_id INT UNSIGNED NULL,              -- added by a pharmacy through the AushadhBox card
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_items_user_exp (user_id, exp_month),
  CONSTRAINT fk_items_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_items_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_items_cat    FOREIGN KEY (catalog_id) REFERENCES medicine_catalog(id) ON DELETE SET NULL,
  CONSTRAINT fk_items_info   FOREIGN KEY (info_id)   REFERENCES medicine_info(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per dose that was taken or deliberately skipped. No row = not confirmed (missed).
CREATE TABLE IF NOT EXISTS dose_logs (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id   INT UNSIGNED NOT NULL,
  item_id   INT UNSIGNED NOT NULL,
  slot      CHAR(1)      NOT NULL,       -- m a e b
  taken_on  DATE         NOT NULL,
  taken_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status    VARCHAR(8)   NOT NULL DEFAULT 'taken',   -- taken | skipped
  reason    VARCHAR(10)  NULL,                       -- skipped: fine, side, out, doctor, other
  source    VARCHAR(6)   NOT NULL DEFAULT 'app',     -- app | notif | box (predicted from the smart pillbox) | test
  UNIQUE KEY uq_dose (item_id, slot, taken_on),
  KEY idx_dose_user_day (user_id, taken_on),
  CONSTRAINT fk_dose_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dose_item FOREIGN KEY (item_id) REFERENCES cabinet_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_settings (
  user_id   INT UNSIGNED PRIMARY KEY,
  enabled   TINYINT(1) NOT NULL DEFAULT 0,
  m_time    CHAR(5) NOT NULL DEFAULT '08:00',
  a_time    CHAR(5) NOT NULL DEFAULT '13:30',
  e_time    CHAR(5) NOT NULL DEFAULT '19:30',
  b_time    CHAR(5) NOT NULL DEFAULT '22:00',
  timezone  VARCHAR(40) NOT NULL DEFAULT 'Asia/Kolkata',
  CONSTRAINT fk_rem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Browser push subscriptions (for reminders when the app is closed).
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  endpoint   VARCHAR(600) NOT NULL,
  p256dh     VARCHAR(120) NOT NULL,
  auth       VARCHAR(40)  NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push_endpoint (endpoint(255)),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remembers which reminders were already sent, so each goes out once.
CREATE TABLE IF NOT EXISTS reminder_log (
  user_id  INT UNSIGNED NOT NULL,
  slot     CHAR(1) NOT NULL,
  sent_on  DATE NOT NULL,
  PRIMARY KEY (user_id, slot, sent_on),
  CONSTRAINT fk_rlog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kit_checks (
  user_id  INT UNSIGNED NOT NULL,
  item_key VARCHAR(20) NOT NULL,
  PRIMARY KEY (user_id, item_key),
  CONSTRAINT fk_kit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force protection for login and sign-up.
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45)  NOT NULL,
  email      VARCHAR(160) NULL,
  success    TINYINT(1)   NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attempts_ip_time (ip, created_at),
  KEY idx_attempts_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  UNIQUE KEY uq_reset_token (token_hash),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record of what each user agreed to (DPDP Act 2023: consent must be provable).
CREATE TABLE IF NOT EXISTS consent_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_ref   CHAR(64)     NOT NULL,       -- SHA-256 of the e-mail, so no readable personal data is kept after deletion
  version    VARCHAR(10)  NOT NULL,
  action     ENUM('given','withdrawn') NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_consent_ref (user_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invite codes for sharing one family cabinet between several accounts (Premium).
CREATE TABLE IF NOT EXISTS invites (
  code       CHAR(8)      PRIMARY KEY,
  owner_id   INT UNSIGNED NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_by    INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Razorpay payments for the Premium plan.
CREATE TABLE IF NOT EXISTS payments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL,
  rp_order_id   VARCHAR(40)  NOT NULL,
  rp_payment_id VARCHAR(40)  NULL,
  period        ENUM('month','year') NOT NULL,
  amount_paise  INT UNSIGNED NOT NULL,
  status        ENUM('created','paid','failed') NOT NULL DEFAULT 'created',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at       DATETIME     NULL,
  UNIQUE KEY uq_pay_order (rp_order_id),
  KEY idx_pay_user (user_id),
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cookie choices (proof of consent). visitor_ref is a random ID, not personal data.
CREATE TABLE IF NOT EXISTS cookie_consents (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visitor_ref CHAR(32)     NOT NULL,
  analytics   TINYINT(1)   NOT NULL,
  marketing   TINYINT(1)   NOT NULL,
  version     VARCHAR(10)  NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cc_visitor (visitor_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- First-party analytics. Rows are written ONLY for visitors who accepted analytics cookies.
CREATE TABLE IF NOT EXISTS analytics_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visitor_ref CHAR(32)     NOT NULL,
  event       VARCHAR(20)  NOT NULL,     -- pageview, signup, checkout, purchase
  path        VARCHAR(200) NOT NULL,
  referrer    VARCHAR(120) NULL,         -- host name only
  utm_source  VARCHAR(60)  NULL,
  utm_medium  VARCHAR(60)  NULL,
  utm_campaign VARCHAR(80) NULL,
  lang        VARCHAR(5)   NULL,
  device      VARCHAR(10)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ae_time (created_at),
  KEY idx_ae_event_time (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80)  NOT NULL,
  email      VARCHAR(160) NOT NULL,
  topic      VARCHAR(30)  NOT NULL,
  message    TEXT         NOT NULL,
  handled    TINYINT(1)   NOT NULL DEFAULT 0,
  category   VARCHAR(12)  NULL,                    -- set by the support autopilot: billing, bug, privacy, medical, feature, account, other
  priority   VARCHAR(6)   NULL,                    -- high, normal, low
  sentiment  VARCHAR(8)   NULL,                    -- negative, neutral, positive
  due_at     DATETIME     NULL,                    -- reply target
  suggested  TEXT         NULL,                    -- drafted reply (template, or AI when switched on)
  reply      TEXT         NULL,
  replied_at DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Automations each person can switch on or off (refill alerts, missed-dose alerts, clean-up, calendar feed).
CREATE TABLE IF NOT EXISTS automation_settings (
  user_id      INT UNSIGNED PRIMARY KEY,
  refill_on    TINYINT(1) NOT NULL DEFAULT 1,
  refill_days  TINYINT UNSIGNED NOT NULL DEFAULT 5,      -- alert when stock lasts this many days or fewer
  missed_on    TINYINT(1) NOT NULL DEFAULT 1,
  missed_after SMALLINT UNSIGNED NOT NULL DEFAULT 60,    -- minutes after the dose time
  course_on    TINYINT(1) NOT NULL DEFAULT 1,
  cleanup_on   TINYINT(1) NOT NULL DEFAULT 1,
  timing_on    TINYINT(1) NOT NULL DEFAULT 1,
  watch_hours  TINYINT UNSIGNED NOT NULL DEFAULT 24,     -- watch-over: tell the family after this many hours with no sign of life (0 = off)                -- move reminder times to match when doses are really ticked
  ics_token    CHAR(32) NULL,                            -- secret address of the calendar feed
  UNIQUE KEY uq_auto_ics (ics_token),
  CONSTRAINT fk_auto_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which automatic alerts were sent, so each goes out once.
CREATE TABLE IF NOT EXISTS automation_log (
  user_id  INT UNSIGNED NOT NULL,
  kind     CHAR(1) NOT NULL,          -- f refill, m missed dose, n "did you take it?" check, c course finished, x clean-up, t timing,
                                      -- s skipped for side effects, w weekly report, d pillbox opened twice, o pillbox offline, r refill the pillbox
  item_id  INT UNSIGNED NOT NULL DEFAULT 0,
  slot     CHAR(1) NOT NULL DEFAULT '',
  sent_on  DATE NOT NULL,
  PRIMARY KEY (user_id, kind, item_id, slot, sent_on),
  CONSTRAINT fk_alog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Smart pillboxes (ESP32 with a lid sensor per compartment). See hardware/README.md.
CREATE TABLE IF NOT EXISTS devices (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,              -- the family cabinet it belongs to
  member_id     INT UNSIGNED NULL,                  -- whose medicines are in it (NULL = everyone's)
  name          VARCHAR(40)  NOT NULL,
  compartments  TINYINT UNSIGNED NOT NULL DEFAULT 4, -- 4 = morning/afternoon/evening/bedtime lids, 1 = one lid for everything
  pair_code     CHAR(6)      NULL,
  pair_expires  DATETIME     NULL,
  token_hash    CHAR(64)     NULL,                  -- SHA-256 of the box's secret; the secret itself is only on the box
  hw_id         VARCHAR(32)  NULL,
  firmware      VARCHAR(16)  NULL,
  battery       TINYINT UNSIGNED NULL,
  last_seen     DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dev_token (token_hash),
  UNIQUE KEY uq_dev_pair (pair_code),
  KEY idx_dev_user (user_id),
  CONSTRAINT fk_dev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dev_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every lid opening the box reports, and what the server concluded from it.
CREATE TABLE IF NOT EXISTS device_events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  kind        VARCHAR(8)   NOT NULL,                -- open, close, button, boot
  compartment TINYINT UNSIGNED NULL,
  happened_at DATETIME     NOT NULL,
  slot        CHAR(1)      NULL,                    -- the dose time it was matched to
  outcome     VARCHAR(10)  NULL,                    -- taken, again, outside, already, nodose
  KEY idx_devev (device_id, happened_at),
  CONSTRAINT fk_devev_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Autopilot (admin automation) ----------
-- Small settings and counters the autopilot keeps (switches, last run times, IndexNow key).
CREATE TABLE IF NOT EXISTS system_state (
  k          VARCHAR(40) PRIMARY KEY,
  v          TEXT        NULL,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the autopilot noticed: unusual numbers, growth tips, health problems.
CREATE TABLE IF NOT EXISTS insights (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day        DATE         NOT NULL,
  kind       VARCHAR(12)  NOT NULL,                -- anomaly, growth, health, support
  metric     VARCHAR(30)  NOT NULL,
  severity   VARCHAR(6)   NOT NULL,                -- high, medium, low, good
  title      VARCHAR(160) NOT NULL,
  body       TEXT         NULL,
  dismissed  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_insight (day, kind, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the autopilot did by itself (fixes, digests, reminders sent), shown in the admin page.
CREATE TABLE IF NOT EXISTS autopilot_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind       VARCHAR(16)  NOT NULL,
  title      VARCHAR(200) NOT NULL,
  detail     TEXT         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_apl_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every AI call (no content, only size and timing), for cost control.
CREATE TABLE IF NOT EXISTS ai_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purpose    VARCHAR(20)  NOT NULL,
  ok         TINYINT(1)   NOT NULL,
  tokens_in  INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
  ms         INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who did what in the admin page.
CREATE TABLE IF NOT EXISTS audit_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(40)  NOT NULL,
  detail     VARCHAR(255) NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Ideas from Japanese medication-notebook apps (お薬手帳) ----------
-- Health diary: blood pressure, sugar, weight, temperature, oxygen, pulse.
CREATE TABLE IF NOT EXISTS vitals (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  member_id  INT UNSIGNED NULL,
  kind       VARCHAR(6)   NOT NULL,          -- bp, sugar, weight, temp, spo2, pulse; lab results: hba1c, chol, hb
  v1         DECIMAL(6,1) NOT NULL,          -- systolic / value
  v2         DECIMAL(6,1) NULL,              -- diastolic (bp only)
  context    VARCHAR(10)  NULL,              -- sugar: fasting, after_meal, random
  flag       VARCHAR(8)   NULL,              -- ok, low, high, urgent
  note       VARCHAR(200) NULL,
  taken_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vitals (user_id, member_id, kind, taken_at),
  CONSTRAINT fk_vit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vit_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Follow-up check-ins after a new medicine (like a pharmacist's 服薬フォローアップ): day 3 and day 10.
CREATE TABLE IF NOT EXISTS followups (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  due_on      DATE         NOT NULL,
  step        TINYINT UNSIGNED NOT NULL,     -- 1 = day 3, 2 = day 10
  answer      VARCHAR(8)   NULL,             -- better, same, worse, side
  note        VARCHAR(300) NULL,
  answered_at DATETIME     NULL,
  UNIQUE KEY uq_fu (item_id, step),
  KEY idx_fu_due (user_id, due_on),
  CONSTRAINT fk_fu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fu_item FOREIGN KEY (item_id) REFERENCES cabinet_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Doctor visits: reminders the day before and 2 hours before, and a prepared visit sheet.
CREATE TABLE IF NOT EXISTS appointments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  member_id  INT UNSIGNED NULL,
  doctor     VARCHAR(80)  NOT NULL,
  place      VARCHAR(120) NULL,
  at         DATETIME     NOT NULL,
  questions  VARCHAR(500) NULL,             -- what to ask the doctor
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_appt (user_id, at),
  CONSTRAINT fk_appt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- What household apps like EPARK お薬手帳 do automatically ----------
-- Vaccination schedule made from a child's date of birth (India's Universal Immunisation Programme).
CREATE TABLE IF NOT EXISTS vaccinations (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  member_id  INT UNSIGNED NOT NULL,
  code       VARCHAR(16)  NOT NULL,
  name       VARCHAR(60)  NOT NULL,
  due_on     DATE         NOT NULL,
  given_on   DATE         NULL,
  UNIQUE KEY uq_vax (member_id, code),
  KEY idx_vax_due (user_id, due_on),
  CONSTRAINT fk_vax_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vax_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Medical expenses: pharmacy bills are added automatically when a bill is scanned; export per financial year for tax filing.
CREATE TABLE IF NOT EXISTS expenses (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  member_id  INT UNSIGNED NULL,
  category   VARCHAR(10)  NOT NULL,          -- medicine, test, consult, checkup, other
  amount     DECIMAL(10,2) NOT NULL,
  spent_on   DATE         NOT NULL,
  note       VARCHAR(160) NULL,
  source     VARCHAR(8)   NOT NULL DEFAULT 'manual',   -- manual, bill
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exp (user_id, spent_on),
  CONSTRAINT fk_exp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_exp_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read-only links for a doctor (like a pharmacy's one-time code): the visit sheet, for 24 or 72 hours.
CREATE TABLE IF NOT EXISTS share_links (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  member_id  INT UNSIGNED NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  views      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_share (token_hash),
  KEY idx_share_user (user_id),
  CONSTRAINT fk_share_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_share_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- AushadhBox card and the pharmacy portal (like harmo and other Japanese pharmacy-linked notebooks) ----------
CREATE TABLE IF NOT EXISTS pharmacies (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  license_no    VARCHAR(40)  NOT NULL,            -- drug licence number (checked by the admin before approval)
  city          VARCHAR(60)  NOT NULL,
  address       VARCHAR(200) NULL,
  phone         VARCHAR(15)  NOT NULL,
  email         VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'pending',   -- pending, approved, suspended
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL,
  UNIQUE KEY uq_ph_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which pharmacies a person's card was used at; the family can block one at any time.
CREATE TABLE IF NOT EXISTS pharmacy_links (
  member_id   INT UNSIGNED NOT NULL,
  pharmacy_id INT UNSIGNED NOT NULL,
  first_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  blocked     TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (member_id, pharmacy_id),
  CONSTRAINT fk_pl_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_ph FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every time a pharmacy looked at or added to a family's record. Shown to the family.
CREATE TABLE IF NOT EXISTS pharmacy_access (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  member_id   INT UNSIGNED NULL,
  action      VARCHAR(10)  NOT NULL,              -- scan, view, add, refill
  detail      VARCHAR(255) NULL,
  at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pa_user (user_id, at),
  KEY idx_pa_ph (pharmacy_id, at),
  CONSTRAINT fk_pa_ph FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refill requests from the shopping list to a linked pharmacy ("keep these ready").
CREATE TABLE IF NOT EXISTS refill_requests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  member_id   INT UNSIGNED NULL,
  pharmacy_id INT UNSIGNED NOT NULL,
  items       TEXT         NOT NULL,              -- JSON list of {name, qty}
  note        VARCHAR(300) NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'sent',    -- sent, ready, collected, cancelled
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rr_ph (pharmacy_id, status),
  KEY idx_rr_user (user_id),
  CONSTRAINT fk_rr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rr_ph FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Barcodes on medicine packs learned from families' scans: the next family scanning the same pack gets it filled in.
CREATE TABLE IF NOT EXISTS barcodes (
  code        VARCHAR(32)  PRIMARY KEY,           -- EAN-13 / GTIN
  name        VARCHAR(120) NOT NULL,
  catalog_id  INT UNSIGNED NULL,
  info_id     INT UNSIGNED NULL,
  uses        INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacies saved by the family (scanned a shop's QR or ordered there). The shop itself lives in the pharmacy network.
CREATE TABLE IF NOT EXISTS my_pharmacies (
  user_id     INT UNSIGNED NOT NULL,
  code        CHAR(8)      NOT NULL,
  name        VARCHAR(120) NOT NULL,
  village     VARCHAR(80)  NULL,
  added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, code),
  CONSTRAINT fk_myph_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Health workers (ASHA, ANM, village health volunteers) who look after families with the family's permission.
CREATE TABLE IF NOT EXISTS caregivers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id  INT UNSIGNED NOT NULL,
  worker_id     INT UNSIGNED NULL,
  code_hash     CHAR(64)     NULL,                 -- one-time code the family gives the worker (7 days)
  code_until    DATETIME     NULL,
  can_order     TINYINT(1)   NOT NULL DEFAULT 1,   -- may order refills for the family
  status        VARCHAR(10)  NOT NULL DEFAULT 'pending',   -- pending, active, ended
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cg_worker (worker_id, status),
  KEY idx_cg_house (household_id),
  CONSTRAINT fk_cg_house FOREIGN KEY (household_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cg_worker FOREIGN KEY (worker_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS reminders to family members without a smartphone (feature phones), through the SMS provider. Phone numbers stay in members.phone.
CREATE TABLE IF NOT EXISTS sms_outbox (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  member_id   INT UNSIGNED NULL,
  template    VARCHAR(30)  NOT NULL,
  body        VARCHAR(400) NOT NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'queued',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sms_user (user_id, created_at),
  CONSTRAINT fk_smso_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders this family placed through the pharmacy network: keeps the 4-digit delivery code (the network stores only a hash).
CREATE TABLE IF NOT EXISTS network_orders (
  user_id     INT UNSIGNED NOT NULL,
  number      VARCHAR(16)  NOT NULL,
  pharmacy    VARCHAR(120) NOT NULL,
  otp         CHAR(4)      NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, number),
  CONSTRAINT fk_neto_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Round 3: ideas from Japanese household medicine apps, and rural features (core/careplus.php) ----------

-- Repeat prescriptions (リフィル処方箋): "continue for 3 months", used up to N times before a new prescription.
CREATE TABLE IF NOT EXISTS rx_repeats (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  member_id        INT UNSIGNED NULL,
  doctor           VARCHAR(80)  NOT NULL,
  issued_on        DATE         NOT NULL,
  valid_until      DATE         NOT NULL,
  repeats_allowed  TINYINT UNSIGNED NOT NULL DEFAULT 3,
  repeats_used     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  items            TEXT         NOT NULL,              -- JSON list of medicine names as written
  note             VARCHAR(200) NULL,
  last_used        DATETIME     NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rxr_user (user_id, valid_until),
  CONSTRAINT fk_rxr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rxr_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mother and child (母子手帳 / India's MCP card): one active pregnancy per person.
CREATE TABLE IF NOT EXISTS pregnancies (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  member_id   INT UNSIGNED NOT NULL,
  lmp         DATE         NOT NULL,                  -- first day of the last menstrual period
  edd         DATE         NOT NULL,                  -- expected date of delivery (LMP + 280 days)
  anc_done    VARCHAR(20)  NOT NULL DEFAULT '',       -- check-ups done, e.g. "1,2"
  status      VARCHAR(8)   NOT NULL DEFAULT 'active', -- active, ended
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preg (user_id, status),
  CONSTRAINT fk_preg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_preg_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Health points (EPARK-style): earned for looking after health, spent as vouchers at network pharmacies.
CREATE TABLE IF NOT EXISTS health_points (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  delta       INT          NOT NULL,
  reason      VARCHAR(12)  NOT NULL,                  -- reading, all_doses, followup, vaccine, anc, steps, voucher, refund
  ref         VARCHAR(40)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hp (user_id, reason, created_at),
  CONSTRAINT fk_hp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS point_vouchers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  code        CHAR(8)      NOT NULL,
  points      INT          NOT NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'open',   -- open, used, cancelled, expired
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  used_by     VARCHAR(120) NULL,                      -- the pharmacy that accepted it
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_voucher (code),
  CONSTRAINT fk_pv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time share codes (6 digits, 10 minutes) for a pharmacy without the card.
CREATE TABLE IF NOT EXISTS share_codes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id   INT UNSIGNED NOT NULL,
  code_hash   CHAR(64)     NOT NULL,
  may_add     TINYINT(1)   NOT NULL DEFAULT 0,
  may_view    TINYINT(1)   NOT NULL DEFAULT 0,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  KEY idx_sc (code_hash),
  CONSTRAINT fk_sc_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS sent to family members without a smartphone (DLT templates). Phone numbers are hashed here.
CREATE TABLE IF NOT EXISTS sms_out (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone_hash  CHAR(64)     NOT NULL,
  template    VARCHAR(20)  NOT NULL,
  ref         VARCHAR(40)  NULL,
  body        VARCHAR(300) NOT NULL,
  status      VARCHAR(8)   NOT NULL DEFAULT 'queued',  -- queued, sent, failed, logged (no provider set)
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_smsout (template, ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security events: failed sign-ins, lockouts, two-factor failures, blocked cross-site requests, bot traps, admin actions.
-- Read by the admin dashboard (Security) and summed in the daily digest. Kept 1 year.
CREATE TABLE IF NOT EXISTS security_events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind       VARCHAR(40)  NOT NULL,
  user_id    INT UNSIGNED NULL,
  ip         VARCHAR(45)  NOT NULL,
  detail     VARCHAR(300) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sec_kind_time (kind, created_at),
  KEY idx_sec_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Privacy (ISO/IEC 27701 record of processing, ISO/IEC 29100 principles, DPDP Act 2023). core/privacy.php
-- What personal data the service keeps, why, on what basis, who receives it, how long and how it is protected.
-- Filled from PRIVACY_REGISTER in core/privacy.php (kept in step by the upgrader); shown in the app's Privacy tab and to admins.
CREATE TABLE IF NOT EXISTS privacy_register (
  id          VARCHAR(40)  NOT NULL PRIMARY KEY,
  category    VARCHAR(80)  NOT NULL,
  items       VARCHAR(400) NOT NULL,
  purpose     VARCHAR(400) NOT NULL,
  basis       VARCHAR(160) NOT NULL,
  needed      VARCHAR(10)  NOT NULL,          -- required | optional
  recipients  VARCHAR(300) NOT NULL,
  retention   VARCHAR(200) NOT NULL,
  location    VARCHAR(120) NOT NULL,
  protection  VARCHAR(300) NOT NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every consent given or withdrawn, with the wording version and where it happened (proof for DPDP s.6).
CREATE TABLE IF NOT EXISTS consent_records (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  purpose    VARCHAR(40)  NOT NULL,
  granted    TINYINT(1)   NOT NULL,
  version    VARCHAR(20)  NULL,
  source     VARCHAR(40)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_consent_user (user_id, purpose, created_at),
  CONSTRAINT fk_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requests from people about their data: access, correction, erasure, nominee, grievance. Due dates follow the DPDP Rules.
CREATE TABLE IF NOT EXISTS privacy_requests (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  user_ref   CHAR(64)     NOT NULL,           -- sha256 of the e-mail: the request record outlives an erased account
  kind       VARCHAR(12)  NOT NULL,           -- access | correction | erasure | nominee | grievance | other
  details    VARCHAR(2000) NULL,
  status     VARCHAR(12)  NOT NULL DEFAULT 'open',   -- open | in_progress | done | declined
  reply      VARCHAR(2000) NULL,
  due_at     DATETIME     NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at  DATETIME     NULL,
  KEY idx_pr_status (status, due_at),
  KEY idx_pr_user (user_id),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
