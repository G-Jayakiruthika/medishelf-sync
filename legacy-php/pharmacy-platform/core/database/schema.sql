-- AushadhBox Pharmacy Network: database for pharmacies, their branches, stock, the pharmacy network,
-- orders, payments (UPI, cash, khata credit book), loyalty, delivery, rural pickup points and cold-chain devices.
-- A separate database from the family app (AushadhBox). The two talk only through the signed partner API.
-- MySQL 8 / MariaDB 10.6+. utf8mb4 everywhere.

SET NAMES utf8mb4;

-- A pharmacy business (one owner, one or more branches).
CREATE TABLE IF NOT EXISTS orgs (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  legal_name    VARCHAR(160) NULL,
  gstin         VARCHAR(15)  NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending',   -- pending, approved, suspended
  plan          VARCHAR(12)  NOT NULL DEFAULT 'free',      -- free, pro (see docs/BUSINESS.md)
  approved_at   DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A shop. Each has its own drug licence, QR code, UPI ID and delivery rules.
CREATE TABLE IF NOT EXISTS branches (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id          INT UNSIGNED NOT NULL,
  code            CHAR(8)      NOT NULL,                  -- public code in the shop's QR (no personal data)
  name            VARCHAR(120) NOT NULL,
  kind            VARCHAR(12)  NOT NULL DEFAULT 'retail', -- retail, janaushadhi, hospital, wholesale
  licence_retail  VARCHAR(60)  NOT NULL,                  -- Form 20/21
  licence_whole   VARCHAR(60)  NULL,                      -- Form 20B/21B, needed to transfer stock to another business
  pharmacist      VARCHAR(80)  NULL,                      -- registered pharmacist on duty
  address         VARCHAR(220) NULL,
  village         VARCHAR(80)  NULL,
  district        VARCHAR(60)  NOT NULL,
  state           VARCHAR(40)  NOT NULL DEFAULT 'Tamil Nadu',
  pincode         CHAR(6)      NULL,
  lat             DECIMAL(9,6) NULL,
  lng             DECIMAL(9,6) NULL,
  area            VARCHAR(10)  NOT NULL DEFAULT 'urban',  -- urban, semiurban, rural
  phone           VARCHAR(15)  NOT NULL,
  rx_email        VARCHAR(160) NULL,                      -- e-mail registered with the licensing authority for prescriptions
  hours           VARCHAR(200) NULL,                      -- e.g. "Mon-Sat 08:30-21:30; Sun 09:00-13:00"
  upi_vpa         VARCHAR(80)  NULL,                      -- e.g. srisai@okaxis or 9876543210@upi
  upi_name        VARCHAR(80)  NULL,
  accepts_cash    TINYINT(1)   NOT NULL DEFAULT 1,
  fee_exempt      TINYINT(1)   NOT NULL DEFAULT 0,          -- e.g. Jan Aushadhi Kendras: no network commission
  khata_on        TINYINT(1)   NOT NULL DEFAULT 1,
  loyalty_pct     DECIMAL(4,2) NOT NULL DEFAULT 1.00,     -- points (1 point = ₹1) as % of each paid bill
  referral_amt    DECIMAL(7,2) NOT NULL DEFAULT 20.00,    -- reward for bringing a new customer, after their first paid order
  delivery_on     TINYINT(1)   NOT NULL DEFAULT 1,
  radius_km       DECIMAL(5,1) NOT NULL DEFAULT 5.0,      -- normal delivery area
  max_km          DECIMAL(5,1) NOT NULL DEFAULT 25.0,     -- beyond this only with the "worth it" rule or a manual override
  fee_base        DECIMAL(7,2) NOT NULL DEFAULT 20.00,
  fee_per_km      DECIMAL(6,2) NOT NULL DEFAULT 8.00,
  free_above      DECIMAL(8,2) NOT NULL DEFAULT 500.00,
  cost_per_km     DECIMAL(6,2) NOT NULL DEFAULT 6.00,     -- what a km really costs the shop (fuel, time), for the worth-it rule
  status          VARCHAR(12)  NOT NULL DEFAULT 'active',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_branch_code (code),
  KEY idx_branch_geo (district, lat, lng),
  CONSTRAINT fk_branch_org FOREIGN KEY (org_id) REFERENCES orgs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- People who work at a pharmacy. branch_id NULL = all branches of the business.
CREATE TABLE IF NOT EXISTS staff (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id        INT UNSIGNED NOT NULL,
  branch_id     INT UNSIGNED NULL,
  name          VARCHAR(80)  NOT NULL,
  phone         VARCHAR(15)  NULL,
  email         VARCHAR(160) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(12)  NOT NULL,                    -- owner, manager, pharmacist, cashier, rider
  totp_secret   VARCHAR(200) NULL,                        -- encrypted
  totp_on       TINYINT(1)   NOT NULL DEFAULT 0,
  status        VARCHAR(10)  NOT NULL DEFAULT 'active',
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_staff_email (email),
  UNIQUE KEY uq_staff_phone (phone),
  CONSTRAINT fk_staff_org FOREIGN KEY (org_id) REFERENCES orgs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a business sells. catalog_id links to the family app's official medicine list when known.
CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id      INT UNSIGNED NOT NULL,
  name        VARCHAR(160) NOT NULL,
  generic     VARCHAR(160) NULL,
  catalog_id  INT UNSIGNED NULL,
  barcode     VARCHAR(32)  NULL,
  schedule    VARCHAR(5)   NOT NULL DEFAULT 'OTC',        -- OTC, G, H, H1, X, NDPS
  pack        VARCHAR(40)  NULL,                          -- e.g. "10 tablets"
  mrp         DECIMAL(9,2) NULL,
  cold        TINYINT(1)   NOT NULL DEFAULT 0,            -- store at 2-8 °C (insulin, vaccines)
  search      VARCHAR(400) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prod_org (org_id, name),
  KEY idx_prod_cat (catalog_id),
  KEY idx_prod_bar (barcode),
  FULLTEXT KEY ft_prod (search),
  CONSTRAINT fk_prod_org FOREIGN KEY (org_id) REFERENCES orgs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock per branch and batch.
CREATE TABLE IF NOT EXISTS stock (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id   INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  batch       VARCHAR(40)  NOT NULL DEFAULT '',
  exp         DATE         NULL,                          -- last day of the expiry month
  qty         INT          NOT NULL DEFAULT 0,            -- in selling units (strips or bottles)
  price       DECIMAL(9,2) NULL,                          -- selling price (≤ MRP)
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stock (branch_id, product_id, batch),
  KEY idx_stock_prod (product_id, qty),
  KEY idx_stock_exp (branch_id, exp),
  CONSTRAINT fk_stock_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change to stock, so numbers can always be explained (sale, purchase, transfer, sync, adjustment, expiry).
CREATE TABLE IF NOT EXISTS stock_moves (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id   INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  batch       VARCHAR(40)  NOT NULL DEFAULT '',
  delta       INT          NOT NULL,
  reason      VARCHAR(12)  NOT NULL,
  ref         VARCHAR(60)  NULL,
  staff_id    INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_moves (branch_id, product_id, created_at),
  CONSTRAINT fk_moves_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Linked pharmacies: two branches (often different businesses) that agree to help each other.
CREATE TABLE IF NOT EXISTS links (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_branch   INT UNSIGNED NOT NULL,                    -- who asked
  to_branch     INT UNSIGNED NOT NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'pending',  -- pending, active, declined, ended
  share_stock   TINYINT(1)   NOT NULL DEFAULT 1,          -- the other side may see whether a medicine is in stock (not how many)
  referral_pct  DECIMAL(4,2) NOT NULL DEFAULT 2.00,       -- share of the bill paid to the branch that passed on the order
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at  DATETIME     NULL,
  UNIQUE KEY uq_link (from_branch, to_branch),
  CONSTRAINT fk_link_a FOREIGN KEY (from_branch) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_link_b FOREIGN KEY (to_branch) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers of the network. Some come from the family app (customer_ref), many are walk-ins with only a phone.
-- Phone and address are encrypted; phone_hash lets the shop find a customer by number.
CREATE TABLE IF NOT EXISTS customers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_ref  CHAR(32)     NULL,                        -- pseudonymous id from the family app (no e-mail, no user id)
  name          VARCHAR(80)  NOT NULL,
  phone_enc     VARCHAR(200) NULL,
  phone_hash    CHAR(64)     NULL,
  address_enc   VARCHAR(600) NULL,
  village       VARCHAR(80)  NULL,
  district      VARCHAR(60)  NULL,
  lat           DECIMAL(9,6) NULL,
  lng           DECIMAL(9,6) NULL,
  lang          VARCHAR(5)   NOT NULL DEFAULT 'en',
  sms_ok        TINYINT(1)   NOT NULL DEFAULT 0,          -- agreed to SMS (order status, khata reminders)
  ref_code      CHAR(6)      NULL,                        -- their own referral code
  referred_by   INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cust_ref (customer_ref),
  UNIQUE KEY uq_cust_phone (phone_hash),
  UNIQUE KEY uq_cust_refcode (ref_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Village pickup points: a kirana shop, panchayat office, ASHA worker's home or bus stand where riders drop orders.
CREATE TABLE IF NOT EXISTS pickup_points (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  kind        VARCHAR(12)  NOT NULL DEFAULT 'shop',       -- shop, asha, panchayat, school, busstand, post
  village     VARCHAR(80)  NOT NULL,
  contact     VARCHAR(80)  NULL,
  phone       VARCHAR(15)  NULL,
  lat         DECIMAL(9,6) NULL,
  lng         DECIMAL(9,6) NULL,
  days        VARCHAR(40)  NULL,                          -- e.g. "Tue,Fri" (rural rounds)
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_pp_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number          VARCHAR(16)  NOT NULL,                  -- shown to people, e.g. SS-24117
  branch_id       INT UNSIGNED NOT NULL,                  -- the shop that sells and is responsible
  asked_branch_id INT UNSIGNED NULL,                      -- the shop the customer asked first, when passed on through a link
  customer_id     INT UNSIGNED NULL,
  source          VARCHAR(10)  NOT NULL DEFAULT 'app',    -- app, walkin, sms, missedcall, asha, phone, refill
  kind            VARCHAR(10)  NOT NULL DEFAULT 'pickup', -- pickup, delivery, point (village pickup point)
  pickup_point_id INT UNSIGNED NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'new',    -- new, accepted, packed, ready, out, delivered, collected, cancelled
  rx_needed       TINYINT(1)   NOT NULL DEFAULT 0,
  rx_status       VARCHAR(10)  NULL,                      -- awaited, seen, ok, rejected
  rx_date         DATE         NULL,
  subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0,
  delivery_fee    DECIMAL(8,2) NOT NULL DEFAULT 0,
  discount        DECIMAL(8,2) NOT NULL DEFAULT 0,        -- loyalty points used and offers
  total           DECIMAL(10,2) NOT NULL DEFAULT 0,
  paid            DECIMAL(10,2) NOT NULL DEFAULT 0,
  pay_status      VARCHAR(10)  NOT NULL DEFAULT 'unpaid', -- unpaid, partial, paid, khata
  distance_km     DECIMAL(6,2) NULL,
  worth           VARCHAR(400) NULL,                      -- JSON: why a long delivery was allowed
  otp_hash        CHAR(64)     NULL,                      -- delivery OTP the customer gives the rider
  note            VARCHAR(300) NULL,
  dose_pack       TINYINT(1)   NOT NULL DEFAULT 0,          -- pack each dose time in its own pouch
  pickup_at       DATETIME     NULL,                        -- the customer's chosen pickup time
  service         VARCHAR(10)  NULL,                        -- home_visit or review
  address_enc     VARCHAR(600) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_no (number),
  KEY idx_order_branch (branch_id, status, created_at),
  KEY idx_order_cust (customer_id, created_at),
  CONSTRAINT fk_order_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NULL,
  name        VARCHAR(160) NOT NULL,
  qty         INT          NOT NULL DEFAULT 1,
  price       DECIMAL(9,2) NULL,
  schedule    VARCHAR(5)   NOT NULL DEFAULT 'OTC',
  times       VARCHAR(10)  NOT NULL DEFAULT '',            -- dose times from the family app (m,a,e,b), for pouch labels
  batch       VARCHAR(40)  NULL,
  exp         DATE         NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'ok',         -- ok, missing, substituted, removed
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every payment, whatever the method. A payment the customer says they made stays "claimed" until the shop confirms it.
CREATE TABLE IF NOT EXISTS payments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id   INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  order_id    INT UNSIGNED NULL,
  method      VARCHAR(12)  NOT NULL,                      -- upi_qr (app-made QR), upi_phone, other_qr, cash, card, khata (sale on credit), points
  amount      DECIMAL(10,2) NOT NULL,
  utr         VARCHAR(30)  NULL,                          -- UPI reference number, when given
  note        VARCHAR(200) NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'claimed',    -- claimed, confirmed, rejected
  by_whom     VARCHAR(10)  NOT NULL DEFAULT 'staff',      -- staff, customer, rider
  staff_id    INT UNSIGNED NULL,
  confirmed_at DATETIME    NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pay_branch (branch_id, status, created_at),
  KEY idx_pay_order (order_id),
  CONSTRAINT fk_pay_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Khata (credit book): buy now, pay later, per shop and customer.
CREATE TABLE IF NOT EXISTS khata (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id     INT UNSIGNED NOT NULL,
  customer_id   INT UNSIGNED NOT NULL,
  balance       DECIMAL(10,2) NOT NULL DEFAULT 0,        -- what the customer owes
  credit_limit  DECIMAL(10,2) NOT NULL DEFAULT 2000,
  remind_day    TINYINT      NOT NULL DEFAULT 5,          -- day of the month for the reminder (after harvest / salary)
  status        VARCHAR(10)  NOT NULL DEFAULT 'open',
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_khata (branch_id, customer_id),
  CONSTRAINT fk_khata_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_khata_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS khata_entries (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  khata_id    INT UNSIGNED NOT NULL,
  kind        VARCHAR(8)   NOT NULL,                      -- credit (bought on credit), payment, writeoff
  amount      DECIMAL(10,2) NOT NULL,
  order_id    INT UNSIGNED NULL,
  payment_id  INT UNSIGNED NULL,
  note        VARCHAR(200) NULL,
  staff_id    INT UNSIGNED NULL,
  seen_by_customer TINYINT(1) NOT NULL DEFAULT 0,          -- shown in the customer's app/SMS, so both sides see the same book
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ke_khata FOREIGN KEY (khata_id) REFERENCES khata(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Loyalty points (1 point = ₹1 off a later bill at the same business).
CREATE TABLE IF NOT EXISTS points (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id      INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  delta       DECIMAL(8,2) NOT NULL,
  reason      VARCHAR(12)  NOT NULL,                      -- purchase, redeem, referral, refill, expire, bonus
  order_id    INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_points (org_id, customer_id),
  CONSTRAINT fk_points_org FOREIGN KEY (org_id) REFERENCES orgs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Referral commission: a customer who brings a new customer earns money (as points or cash) after that customer's first paid order.
CREATE TABLE IF NOT EXISTS referrals (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id        INT UNSIGNED NOT NULL,
  referrer_id   INT UNSIGNED NOT NULL,
  referred_id   INT UNSIGNED NOT NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'waiting',  -- waiting, earned, paid
  amount        DECIMAL(8,2) NOT NULL DEFAULT 0,
  payout        VARCHAR(10)  NOT NULL DEFAULT 'points',   -- points or cash
  order_id      INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ref (org_id, referred_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deliveries: own rider, local partner (auto, bus parcel, India Post, ASHA worker) or courier company.
CREATE TABLE IF NOT EXISTS deliveries (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  mode          VARCHAR(10)  NOT NULL DEFAULT 'own',      -- own, partner, courier
  rider_id      INT UNSIGNED NULL,
  partner       VARCHAR(80)  NULL,                        -- e.g. "Murugan auto", "KPN bus parcel", "India Post"
  partner_phone VARCHAR(15)  NULL,
  courier       VARCHAR(20)  NULL,                        -- porter, shadowfax, dunzo...
  tracking      VARCHAR(60)  NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'assigned', -- assigned, picked, delivered, failed
  cod_amount    DECIMAL(10,2) NOT NULL DEFAULT 0,
  cod_collected DECIMAL(10,2) NOT NULL DEFAULT 0,
  last_lat      DECIMAL(9,6) NULL,
  last_lng      DECIMAL(9,6) NULL,
  last_at       DATETIME     NULL,
  fail_reason   VARCHAR(120) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  done_at       DATETIME     NULL,
  UNIQUE KEY uq_deliv_order (order_id),
  CONSTRAINT fk_deliv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock moved between shops of the same business, or sold to another licensed pharmacy (needs a wholesale licence).
CREATE TABLE IF NOT EXISTS transfers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number        VARCHAR(16)  NOT NULL,
  from_branch   INT UNSIGNED NOT NULL,
  to_branch     INT UNSIGNED NOT NULL,
  order_id      INT UNSIGNED NULL,
  items         TEXT         NOT NULL,                    -- JSON [{product_id, name, batch, exp, qty, price}]
  value         DECIMAL(10,2) NOT NULL DEFAULT 0,
  reason        VARCHAR(12)  NOT NULL DEFAULT 'shortage', -- shortage, expiry (move before it expires), rebalance
  status        VARCHAR(10)  NOT NULL DEFAULT 'asked',    -- asked, sent, received, declined
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transfer_no (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fridge thermometers for insulin and vaccines (ESP32, see iot/).
CREATE TABLE IF NOT EXISTS devices (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  token_hash  CHAR(64)     NULL,
  pair_code   CHAR(6)      NULL,
  pair_until  DATETIME     NULL,
  min_c       DECIMAL(4,1) NOT NULL DEFAULT 2.0,
  max_c       DECIMAL(4,1) NOT NULL DEFAULT 8.0,
  last_c      DECIMAL(4,1) NULL,
  battery     TINYINT      NULL,
  last_seen   DATETIME     NULL,
  alert_state VARCHAR(10)  NOT NULL DEFAULT 'ok',         -- ok, high, low, offline
  CONSTRAINT fk_dev_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS readings (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  temp_c      DECIMAL(4,1) NOT NULL,
  at          DATETIME     NOT NULL,
  KEY idx_read (device_id, at),
  CONSTRAINT fk_read_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Billing-software sync agents (the Java agent in sync-agent/). One token per branch computer.
CREATE TABLE IF NOT EXISTS sync_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  last_used   DATETIME     NULL,
  last_rows   INT          NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sync_token (token_hash),
  CONSTRAINT fk_sync_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suggestions written by the Python analytics (reorder, move stock before expiry, demand changes).
CREATE TABLE IF NOT EXISTS insights (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id   INT UNSIGNED NOT NULL,
  kind        VARCHAR(12)  NOT NULL,                      -- reorder, expiry, transfer, demand
  product_id  INT UNSIGNED NULL,
  title       VARCHAR(200) NOT NULL,
  data        TEXT         NULL,                          -- JSON
  status      VARCHAR(10)  NOT NULL DEFAULT 'open',       -- open, done, dismissed
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ins (branch_id, status, created_at),
  CONSTRAINT fk_ins_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS in and out (ordering by SMS, missed-call call-backs, status and khata reminders). Phone numbers are hashed.
CREATE TABLE IF NOT EXISTS sms_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  direction   VARCHAR(4)   NOT NULL,                      -- in, out
  phone_hash  CHAR(64)     NOT NULL,
  branch_id   INT UNSIGNED NULL,
  template    VARCHAR(30)  NULL,                          -- DLT template name
  body        VARCHAR(480) NOT NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'queued',     -- queued, sent, failed, received, logged
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sms (phone_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Network administrators (the AushadhBox team): approve pharmacies, handle complaints.
CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL,
  email         VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret   VARCHAR(200) NULL,
  totp_on       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Complaints from customers about a pharmacy (wrong medicine, overcharging above MRP, rude delivery...).
CREATE TABLE IF NOT EXISTS complaints (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id   INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  order_id    INT UNSIGNED NULL,
  topic       VARCHAR(20)  NOT NULL,                      -- overcharge, wrong, late, behaviour, quality, privacy, other
  body        VARCHAR(1000) NOT NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'open',
  reply       VARCHAR(1000) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_compl (branch_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor       VARCHAR(10)  NOT NULL,                      -- staff, admin, partner, system, device, customer
  actor_id    INT UNSIGNED NULL,
  org_id      INT UNSIGNED NULL,
  branch_id   INT UNSIGNED NULL,
  action      VARCHAR(40)  NOT NULL,
  detail      VARCHAR(400) NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit (org_id, created_at),
  KEY idx_audit_branch (branch_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replay protection for signed partner calls, and rate limits.
CREATE TABLE IF NOT EXISTS nonces (
  nonce       CHAR(32)     PRIMARY KEY,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_nonce_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hits (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  k           VARCHAR(80)  NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hits (k, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform fees and credits per business (the network's income, and what it owes shops).
--   commission  on orders that came through the network (the family app, or passed on by a linked pharmacy): shop owes the platform
--   voucher     a customer's AushadhBox health-points voucher the shop accepted: the platform owes the shop
--   settlement  a payment between the shop and the platform that closes the month
CREATE TABLE IF NOT EXISTS fees (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id      INT UNSIGNED NOT NULL,
  branch_id   INT UNSIGNED NULL,
  kind        VARCHAR(10)  NOT NULL,                      -- commission, voucher, settlement, adjustment
  order_id    INT UNSIGNED NULL,
  base        DECIMAL(10,2) NULL,                         -- value the commission was charged on (medicines, without delivery)
  rate        DECIMAL(4,2) NULL,                          -- percent
  amount      DECIMAL(10,2) NOT NULL,                     -- positive: the shop owes the platform; negative: the platform owes the shop
  gst         DECIMAL(10,2) NOT NULL DEFAULT 0,           -- 18% GST on the platform's service (commission)
  period      CHAR(7)      NOT NULL,                      -- YYYY-MM
  ref         VARCHAR(40)  NULL,                          -- voucher code or UPI reference
  note        VARCHAR(200) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fee_order (kind, order_id),
  KEY idx_fee_org (org_id, period),
  CONSTRAINT fk_fee_org FOREIGN KEY (org_id) REFERENCES orgs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
