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
  account_id INT UNSIGNED NULL,          -- this person's own AushadhBox login, if they have one (gets the "did you take it?" check first)
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_members_user (user_id),
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
  timing_on    TINYINT(1) NOT NULL DEFAULT 1,                -- move reminder times to match when doses are really ticked
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
