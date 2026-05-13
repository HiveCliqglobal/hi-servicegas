# Hi-Service Chatbot — Build Prompt

> **Use this prompt to kick off (or resume) the Hi-Service Chatbot rebuild in any session.**
> It is self-contained: read this file plus the four reference docs in this folder and you have everything required to start coding.

---

## 0 · Mission (one paragraph)

You are Shawn's senior engineer at HiveCliq, building a replacement for Hi-Service Gas's WhatsApp ordering bot. The current bot lives in n8n on `n8n.imappliedseo.com` (122 nodes, fragile, has bugs, hardcoded credentials, sandbox PayFast still active). We are decommissioning it. The replacement is a single PHP 8.1 + MySQL backend on cPanel, hosted at **`orders.hi-servicegas.co.za`**, that:

- Receives inbound WhatsApp messages (Meta Cloud API)
- Runs a deterministic state machine (ported from the n8n JS, bug-fixed)
- Sells gas via WhatsApp **and** a new public web shop — same backend, two channels
- Generates Xero invoices + PDFs, processes PayFast payments
- Bridges to a GoHighLevel AI bot for "general help" mode (FAQ, calendar booking, admin escalation)
- Provides a branded admin panel for live ops (orders, conversations, take-over button)

The same proven stack as the user's other production systems (Westpeak Capital, Praxis Medical, N24x4 Bakkie Spares).

---

## 1 · Required reading (in this folder)

Before starting any stage, re-read these. They contain context this prompt does NOT duplicate:

| File | Purpose |
|---|---|
| `ANALYSIS.md` | Deep-dive of existing n8n flows · all bugs found · architecture rationale |
| `CONVERSATION-FLOW.md` | Every screen / every message / every option the customer sees · the spec for the FSM |
| `VISUAL-FLOW.md` | Architecture diagrams (ASCII) — useful for explaining to others |
| `roadmap/hiservice-roadmap-2026-05-06.html` | Client-facing roadmap (what was approved) |
| `WA Order Json/WhatsApp Gas Ordering - Hybrid State Machine (Complete).json` | The 122-node n8n source (read individual Code nodes via `jq` to port logic) |
| `WA Order Json/Sub Workflow_ PayFast Payment Webhook.json` | The 23-node PayFast post-payment sub-workflow |
| `.env.local` | Saved credentials (n8n API key, base URL, GHL location ID — populate as more arrive) |

---

## 2 · Architecture summary

```
   ┌─ WhatsApp ─┐                            ┌─ Xero (OAuth) ────┐
   │            │                            ├─ PayFast (md5)    │
   ├─ Web shop ─┼─►  PHP 8.1 + MySQL  ─►─────┼─ Meta Graph ──────┤
   │            │   orders.hi-servicegas     ├─ GHL (PIT) ───────┤
   └────────────┘                            └───────────────────┘
                          │
                          ▼
                  Admin Panel (web)
```

- **Single brain** — one state machine PHP class, one intent detector. Both WhatsApp webhook and the web shop pipe through the same brain.
- **MySQL replaces** n8n Data Tables + Google Sheets (no race conditions, queryable, admin-panel-able).
- **GHL** handles ONLY general-help mode (AI bot, calendar, admin notify). It is NOT in the gas ordering path.
- **Old n8n** stays running on `imappliedseo.com` until the Day 14 cutover. Don't touch it.

---

## 3 · Tech stack & conventions

| Layer | Choice |
|---|---|
| PHP | **8.1.x** (set via cPanel `selectorctl`) |
| DB | **MariaDB 10.6** (utf8mb4, InnoDB, exception PDO mode) |
| Web | LiteSpeed (cPanel default). Cache-bust with `?v=N` on PHP changes. |
| Frontend (admin + shop) | Server-rendered PHP + vanilla JS where needed. Inline CSS or a single `app.css`. **No** React, **no** build step. |
| Auth | Session-based for admin. Webhook tokens for service-to-service. |
| HTTP client | `cURL` directly. No Composer dependencies unless absolutely necessary. |
| Logging | File logs in `logs/` AND a `event_log` MySQL table for admin actions. |
| Tests | **PHPUnit 10** for the state machine + intent detector. Smoke-test scripts in `tests/manual/` for integrations. |
| Secrets | `includes/config.php` reads from `.env` outside docroot. **NEVER** hardcode credentials in code or commit them. |

**File structure** (mirrors the user's other PHP projects):

```
/home/hivecliqapps/public_html/orders.hi-servicegas.co.za/
├── index.php                       (root → /shop or /admin)
├── login.php                       (admin login, branded dark)
├── .htaccess                       (HTTPS, routing, security headers)
├── api/
│   ├── webhook/
│   │   ├── whatsapp.php            (Meta WhatsApp inbound)
│   │   ├── payfast-itn.php         (PayFast ITN)
│   │   └── ghl-outbound.php        (GHL workflow → reply text)
│   ├── shop.php                    (public web shop API)
│   └── admin.php                   (admin panel JSON API)
├── shop/                           (public web order pages)
│   ├── index.php                   (entry / postal code check)
│   ├── identify.php
│   ├── browse.php
│   ├── address.php
│   ├── slot.php
│   └── pay.php
├── admin/                          (gated admin panel)
│   ├── index.php                   (dashboard)
│   ├── orders.php
│   ├── conversations.php
│   ├── slots.php
│   └── products.php
├── includes/
│   ├── bootstrap.php               (loads env, db, auth)
│   ├── config.php                  (env loader, secrets)
│   ├── db.php                      (PDO singleton)
│   ├── auth.php                    (session guard)
│   ├── logger.php                  (file + DB logger)
│   ├── state_machine.php           (FSM class)
│   ├── intent_detector.php         (intent classifier with optional Claude fallback)
│   ├── whatsapp.php                (Meta Graph send + webhook helpers)
│   ├── xero.php                    (OAuth + contacts + invoices)
│   ├── payfast.php                 (signature gen/verify + ITN handler)
│   ├── ghl.php                     (contacts + conversations + workflow webhooks)
│   └── helpers.php                 (sanitisers, formatters)
├── tests/
│   ├── unit/
│   │   ├── StateMachineTest.php
│   │   ├── IntentDetectorTest.php
│   │   ├── PayfastSignatureTest.php
│   │   └── …
│   └── manual/                     (curl smoke tests for each integration)
├── schema.sql
├── migrations/
├── .env                            (one level above docroot — never served)
└── logs/
```

**Coding conventions:**
- PSR-12 spacing, snake_case file names, PascalCase class names
- Every public function has a docblock with `@param`, `@return`, `@throws`
- Every external API call goes through a helper in `includes/<service>.php` — never inline cURL in route files
- Every webhook entrypoint:
  1. logs raw payload to `logs/<service>-YYYY-MM-DD.log`
  2. verifies signature
  3. wraps work in `try/catch` and returns 200 with `{"ok":true}` (or 4xx with reason)
- Every customer-facing reply goes through `whatsapp::send()` — never multiple send paths
- All money is stored as `DECIMAL(12,2)` ZAR. No floats.

---

## 4 · Credentials & access

### Already in `.env.local`
- `N8N_BASE_URL` = `https://n8n.srv1159178.hstgr.cloud`
- `N8N_API_KEY` (read-only access to the user's test n8n — for re-reading the source JSON via API if needed)
- `N8N_WF_MAIN` = `g3I7goFL8gRnsSZK`
- `N8N_WF_PAYFAST` = `2VflxKensRRU0zLs`
- `GHL_LOCATION_ID` = `dzu95Ecw2Cq2nFzf3a0G`

### Needed before each stage (collect as you go — don't block Stage 1 on Stage 3 creds)

| Credential | Stage gated | Source |
|---|---|---|
| cPanel access for `orders.hi-servicegas.co.za` | Stage 1 | Shawn — add subdomain to `hivecliqapps` cPanel (see `~/.claude/.../access_hivecliqapps_cpanel.md`) |
| MySQL DB + user `hiservicegas_*` | Stage 1 | cPanel → MySQL Databases → create |
| `GHL_PRIVATE_TOKEN` for sub-account `dzu95Ecw2Cq2nFzf3a0G` | Stage 3 day 8 | GHL → Settings → Private Integrations → Create. Scopes: `contacts.write`, `contacts.readonly`, `conversations.write`, `conversations.readonly`, `conversations/message.write`, `calendars.readonly`, `workflows.readonly`, `locations.readonly` |
| Meta WhatsApp Cloud API: `META_APP_ID`, `META_APP_SECRET`, `META_PHONE_NUMBER_ID`, `META_WABA_ID`, `META_SYSTEM_USER_TOKEN`, `META_VERIFY_TOKEN` (we generate this) | Stage 3 day 5 | Hi-Service to create a NEW Business app in business.facebook.com — separate from imappliedseo's existing one |
| Xero: `XERO_CLIENT_ID`, `XERO_CLIENT_SECRET`, `XERO_REFRESH_TOKEN`, `XERO_TENANT_ID` | Stage 3 day 6 | Hi-Service Xero org → developer.xero.com → new OAuth2 app |
| PayFast LIVE: `PAYFAST_MERCHANT_ID`, `PAYFAST_MERCHANT_KEY`, `PAYFAST_PASSPHRASE` | Stage 3 day 7 | Hi-Service PayFast dashboard. The current sandbox values in n8n (`10043198` / `6a0oqxb4gq7rv` / `PassPhrase001`) are unusable. |
| Optional: `ANTHROPIC_API_KEY` for Claude intent fallback | Stage 2 (optional) | Use HiveCliq's existing key. |
| Optional: `OPENAI_API_KEY` (alternate fallback) | — | n/a |

### Decisions outstanding (block GHL stage)
1. **GHL AI bot scope** — what FAQ does it know? Hi-Service to provide a knowledge doc covering: areas served, hours, pricing, refund policy, how to swap cylinders, how to track orders, common complaints.
2. **GHL escalation target** — when AI flags, who gets pinged? (Slack channel? GHL user notify? SMS to a phone?)
3. **Calendar use case** — what is the AI booking? Consultations? Installation appointments? Empty-cylinder pickups?
4. **Brand assets** — Hi-Service logo SVG/PNG + brand colours for the web shop styling.

---

## 5 · Database schema (DDL — Stage 1 deliverable)

Save to `schema.sql` and import on Day 1. All tables InnoDB / utf8mb4.

```sql
-- =====================================================
-- Hi-Service Chatbot — schema.sql (v1)
-- =====================================================

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(128),
  role ENUM('admin','operator','viewer') DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  xero_contact_id VARCHAR(64) UNIQUE,
  phone VARCHAR(20) NOT NULL UNIQUE,            -- E.164 e.g. 27848580000
  full_name VARCHAR(255),
  email VARCHAR(255),
  status ENUM('active','archived','blocked') DEFAULT 'active',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE addresses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  type ENUM('street','postal') DEFAULT 'street',
  line1 VARCHAR(255) NOT NULL,
  line2 VARCHAR(255),
  city VARCHAR(128),
  postal_code VARCHAR(10),
  is_default TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  xero_item_id VARCHAR(64) UNIQUE,
  code VARCHAR(64) NOT NULL,                    -- e.g. LPG-9KG
  name VARCHAR(255) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  in_stock_qty INT DEFAULT 0,
  is_tracked TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,               -- whitelist flag (replaces Recipes sheet)
  sort_order INT DEFAULT 100,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_zones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  postal_code VARCHAR(10) NOT NULL UNIQUE,
  suburb VARCHAR(128),
  is_active TINYINT(1) DEFAULT 1,
  notes VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_date DATE NOT NULL,
  time_block ENUM('08:00-12:00','13:00-16:30') NOT NULL,
  capacity INT DEFAULT 6,
  booked_count INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_date_block (delivery_date, time_block),
  INDEX idx_date (delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_reference VARCHAR(32) NOT NULL UNIQUE,  -- ORD-1730987654
  customer_id INT NOT NULL,
  address_id INT,
  slot_id INT,
  channel ENUM('whatsapp','web') NOT NULL,
  status ENUM('cart','pending_payment','paid','delivered','cancelled','failed') DEFAULT 'cart',
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  payfast_payment_id VARCHAR(64),
  xero_invoice_id VARCHAR(64),
  xero_invoice_number VARCHAR(64),
  paid_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (address_id) REFERENCES addresses(id),
  FOREIGN KEY (slot_id) REFERENCES slots(id),
  INDEX idx_status (status),
  INDEX idx_paid_at (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL UNIQUE,
  mode ENUM('menu','ordering','general_help') NULL,
  current_step VARCHAR(64),
  customer_id INT,
  current_order_id INT,
  state_data JSON,
  expires_at TIMESTAMP NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 24 HOUR),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (current_order_id) REFERENCES orders(id) ON DELETE SET NULL,
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  direction ENUM('in','out') NOT NULL,
  channel ENUM('whatsapp','web','ghl') NOT NULL,
  message_text TEXT,
  payload_json JSON,
  mode VARCHAR(32),
  current_step VARCHAR(64),
  intent VARCHAR(64),
  taken_over_by INT,                            -- user_id if a human took control
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_phone_created (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  payfast_payment_id VARCHAR(64),
  amount DECIMAL(12,2),
  status ENUM('pending','complete','failed','cancelled') DEFAULT 'pending',
  signature_received VARCHAR(64),
  signature_computed VARCHAR(64),
  raw_payload JSON,
  verified_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  action VARCHAR(64) NOT NULL,                  -- e.g. "order.cancel", "session.takeover"
  entity_type VARCHAR(32),                      -- e.g. "order", "session"
  entity_id VARCHAR(64),
  payload JSON,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action (action),
  INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: admin user + delivery zones
INSERT INTO users (username, password_hash, display_name) VALUES
  ('shawn', '$2y$10$REPLACE_WITH_PASSWORD_HASH', 'Shawn Lochner');

INSERT INTO delivery_zones (postal_code, suburb) VALUES
  ('7140', 'Strand'),
  ('7150', 'Somerset West'),
  ('7130', 'Gordon's Bay'),
  ('7195', 'Helderberg'),
  ('7975', 'Pringle Bay'),
  ('7195', 'Betty's Bay');
  -- (Hi-Service to confirm full list)
```

---

## 6 · Stage breakdown (each stage is independently demo-able)

---

### 🟥 STAGE 1 — Skeleton & Database (Days 1–2)

**Goal:** Live, branded admin panel + DB with seed data on the new subdomain. No business logic yet.

**Pre-requisites:**
- cPanel access for hivecliqapps account
- Subdomain `orders.hi-servicegas.co.za` created and pointed at `/home/hivecliqapps/public_html/orders.hi-servicegas.co.za/`

**Tasks:**

1. **Provision subdomain + SSL** (cPanel → Subdomains → AutoSSL).
2. **Create MySQL DB + user** in cPanel. Save creds to `.env` (one level above docroot).
3. **Bootstrap repo** locally at `/Users/hivebrain/Claude/Hi-Service Chatbot/code/`. Mirror the file layout from §3. Add `.gitignore` excluding `.env`, `logs/`, `node_modules/` (none expected), `*.bak`.
4. **Implement `includes/config.php`** — reads `.env` via PHP `parse_ini_file`. Exposes `env('KEY', $default)`.
5. **Implement `includes/db.php`** — PDO singleton, exception mode, utf8mb4, fetch assoc.
6. **Implement `includes/auth.php`** — session start, `requireLogin()`, `getCurrentUser()`, `logout()`. Bcrypt password hashing (`PASSWORD_DEFAULT`).
7. **Implement `includes/logger.php`** — file logger (`logs/<channel>-YYYY-MM-DD.log`) + DB logger (writes to `event_log`).
8. **Build `login.php`** — branded dark, Hi-Service logo on top, simple form. Match `westpeak-project/login.php` pattern.
9. **Build `admin/index.php`** (dashboard skeleton) with stats cards (all 0 today): orders today, revenue, pending, active conversations. Placeholders for live data.
10. **Build `index.php`** (root) — redirects logged-in users to `/admin/`, others to `/login.php`. Public shop pages still 404 — that's fine.
11. **Apply `schema.sql`** to the new DB. Replace the `password_hash` in the seed with a real bcrypt hash for Shawn's chosen password.
12. **Smoke test**: log in, see empty dashboard, log out.
13. **Deploy** (rsync or SCP) to `orders.hi-servicegas.co.za`. `chmod 644` on PHP files post-upload (LiteSpeed needs it).

**Files created this stage:**
- `schema.sql`
- `.env.example` (no secrets)
- `index.php`, `login.php`, `.htaccess`
- `includes/config.php`, `db.php`, `auth.php`, `logger.php`, `bootstrap.php`
- `admin/index.php`
- `assets/img/hi-service-logo.svg` (Hi-Service to provide)
- `assets/css/app.css`

**Acceptance criteria (demo):**
- ✅ Visit `https://orders.hi-servicegas.co.za` → redirects to login
- ✅ Log in with seeded user → branded dashboard
- ✅ All 4 stat cards render (with `0` values)
- ✅ Log out works
- ✅ DB has all 12 tables, seed delivery zones, and one admin user

---

### 🟨 STAGE 2 — The Brain (Days 3–4)

**Goal:** Port the n8n state machine to PHP with full PHPUnit coverage. Fix the bugs found in the audit.

**Pre-requisites:** Stage 1 complete.

**Tasks:**

1. **Read** every Code node in the existing JSON via `jq`. Most important nodes:
   - `State Machine Controller` — the FSM definition
   - `Store Detected Intent` — the intent classifier
   - `Format Slots Response` / `Parse Available Slots` — slot logic
   - `Format Product List` — catalogue rendering
   - `Generate Order Summary` — final summary text
   - `Confirm Current Address` — address confirmation prompt
2. **Port the FSM** to `includes/state_machine.php`. Use a class `StateMachine` with:
   - `public function transition(string $currentStep, string $intent, array $extracted): Transition`
   - `public function reset(string $phone): void`
   - States enumerated as constants (NEW: add `STATE_MENU`)
   - **Bugs to fix during the port**:
     - Remove duplicate `confirm_new_details` definition (keep only the existing-customer version)
     - Replace daily session reset with 24h rolling expiry (`sessions.expires_at`)
     - Add `STATE_MENU` as the default entry state
     - Add optional chaining everywhere addresses are read (`$customer['addresses'][0]['postal_code'] ?? null`)
3. **Port the intent detector** to `includes/intent_detector.php`. Class `IntentDetector` with:
   - `public function detect(string $message, string $currentStep, array $context): IntentResult`
   - Same regex patterns as the JS, BUT:
     - Pre-trim + lowercase + collapse whitespace
     - Handle `1.`, `1)`, `1 ` (trailing space) all as `1`
     - Add fuzzy fallbacks: "yes", "yep", "yeah" → `Y`; "no", "nope", "nah" → `N`
   - **Optional Claude fallback**: if intent = `unclear` AND env has `ANTHROPIC_API_KEY`, call Claude with the current step + message + valid intents → returns one of them or stays `unclear`. Cache responses in `event_log` to avoid duplicate calls.
4. **Port the menu intercept** to `includes/state_machine.php` as a special pre-FSM handler:
   - If `session.mode IS NULL` OR `mode = 'menu'`: send menu, set `mode = 'menu'`
   - On reply: `1` → `mode='ordering'`, kick off existing FSM; `2` → `mode='general_help'`, hand to GHL bridge (Stage 3)
   - From any state: `menu` / `0` / `back` → reset to `mode='menu'`
5. **Write PHPUnit tests** in `tests/unit/StateMachineTest.php`:
   - One test per transition (every entry in the FSM definition)
   - Tests for: new customer happy path, returning happy path, archived customer rejection, cancel from any state, menu reset, `Y/N` variants, slot picker letter A–D and date parsing, custom date `tomorrow`/`monday`/`25/01/2026`
   - Target ≥95% line coverage of `state_machine.php`
6. **Write PHPUnit tests** in `tests/unit/IntentDetectorTest.php`:
   - `1`, `1.`, `1)`, `repeat`, `repeat order` → `repeat_order`
   - `B2`, `b2`, `B 2`, multi-line `B2\nD1` → `collecting_order_details_provided`
   - `cancel`, `stop`, `reset` → `cancel_order` (global)
   - `menu`, `0`, `back` → `reset_to_menu` (NEW)
   - Garbage → `unclear`

**Files created this stage:**
- `includes/state_machine.php`
- `includes/intent_detector.php`
- `tests/unit/StateMachineTest.php`
- `tests/unit/IntentDetectorTest.php`
- `tests/bootstrap.php`
- `phpunit.xml`

**Acceptance criteria (demo):**
- ✅ `vendor/bin/phpunit` runs green, ≥95% coverage on the two classes
- ✅ CLI script `tests/manual/simulate.php +27848580000 "hi"` runs a full conversation locally without sending real WhatsApp messages
- ✅ All 5 known bugs from `ANALYSIS.md` are fixed (verified by tests)

---

### 🟩 STAGE 3 — External Integrations (Days 5–8)

**Goal:** Each external service round-trips end-to-end on a test number.

**Pre-requisites:** Stages 1–2 complete. Credentials trickle in throughout this stage.

#### Day 5 — Meta WhatsApp Cloud API

**Pre-requisites:** Meta credentials added to `.env`.

**Tasks:**
1. `includes/whatsapp.php`:
   - `wa_verify_webhook($mode, $token, $challenge)` — Meta GET verification
   - `wa_verify_signature($rawBody, $headerSig)` — HMAC SHA256 against `META_APP_SECRET`
   - `wa_send_text(string $phone, string $body): array`
   - `wa_send_document(string $phone, string $url, string $filename, string $caption): array`
   - All calls log to `logs/whatsapp-YYYY-MM-DD.log`
2. `api/webhook/whatsapp.php`:
   - GET → call `wa_verify_webhook` (Meta hub.challenge)
   - POST → verify signature, parse, log to `conversations`, hand off to state machine, return 200 immediately (Meta retries on non-200)
3. **Smoke tests**:
   - `tests/manual/wa-send.php` — sends "Hello from Hi-Service test" to a phone in arg
   - Webhook test: hit endpoint with a sample Meta payload from `tests/fixtures/wa-inbound.json`

**Done when:** sending a real message to the test number gets a "echo: <text>" reply via the bot.

#### Day 6 — Xero

**Pre-requisites:** Xero OAuth app created, refresh token saved.

**Tasks:**
1. `includes/xero.php`:
   - `xero_get_access_token(): string` — uses refresh token, caches access token in `event_log` (or a dedicated `oauth_tokens` table)
   - `xero_find_contact_by_phone(string $phone): ?array`
   - `xero_create_contact(array $contact): string` — returns ContactID
   - `xero_update_contact(string $id, array $fields): array`
   - `xero_get_items(): array` — list of products
   - `xero_create_invoice(array $invoice): array` — returns InvoiceID + InvoiceNumber
   - `xero_get_invoice_pdf(string $id): string` — returns binary PDF content
2. **Sync job**: `tests/manual/xero-sync-products.php` — pulls items, upserts into `products` table.
3. **Smoke test**: `tests/manual/xero-smoke.php` — finds a contact, creates a draft invoice, fetches its PDF.

**Done when:** product table populated from Xero. A draft invoice can be created and the PDF downloaded.

#### Day 7 — PayFast

**Pre-requisites:** Live PayFast merchant credentials.

**Tasks:**
1. `includes/payfast.php`:
   - `payfast_signature(array $data, string $passphrase): string` — uses PHP `md5()` (not the 100-line JS!). Param order matches PayFast docs (merchant_id, merchant_key, return_url, cancel_url, notify_url, name_first, name_last, email_address, cell_number, m_payment_id, amount, item_name, item_description). URL-encode values with `urlencode` (PHP default already matches PHP-style spaces=`+`).
   - `payfast_build_pay_link(array $order): string` — full URL to `https://www.payfast.co.za/eng/process?…`
   - `payfast_verify_itn(array $body): bool` — recompute signature in original key order, compare. Optional second-stage verification: POST back to PayFast `validate` endpoint.
2. `api/webhook/payfast-itn.php`:
   - Log raw body
   - Verify signature
   - Look up order by `m_payment_id`
   - Cross-check `amount_gross` matches `orders.total_amount` (R cent precision)
   - Mark order paid, increment `slots.booked_count`, kick off Xero invoice creation
   - Send WhatsApp confirmation + PDF
3. **Smoke test**: `tests/unit/PayfastSignatureTest.php` — uses a known signature from PayFast's docs as a fixture.

**Done when:** Real payment on the test merchant lands in `orders` as `paid`, slot booked, Xero invoice generated, WhatsApp PDF delivered.

#### Day 8 — GHL bridge

**Pre-requisites:** GHL Private Integration Token, AI bot trained on FAQ in GHL UI, calendar created, escalation target decided.

**Tasks:**
1. `includes/ghl.php`:
   - `ghl_request(string $method, string $path, ?array $body): array` — wraps cURL with `Authorization: Bearer {token}` and `Version: 2021-07-28`
   - `ghl_upsert_contact(array $contact): string` — find by phone or create, returns contactId
   - `ghl_post_inbound_message(string $contactId, string $text): array` — uses Conversations API to inject the customer's message
   - `ghl_trigger_workflow(string $workflowId, array $payload): array` — alternative path, POST to GHL inbound webhook URL
2. **GHL workflow setup** (in GHL UI, document in `docs/ghl-setup.md`):
   - Workflow trigger: **Inbound Webhook** with custom URL
   - Action 1: **Find/Create Contact** by phone
   - Action 2: **Conversation AI Bot** action — prompt "You are Hi-Service Gas customer support. {{KB}}". Answer questions, offer to book a slot, escalate when stuck.
   - Action 3 (conditional on escalate): **Internal Notification** to user `{{ESCALATION_USER}}` + Slack post
   - Action 4 (conditional on book): **Book Calendar Appointment** on `{{CALENDAR_ID}}`
   - Action 5: **Send Outbound Webhook** → `https://orders.hi-servicegas.co.za/api/webhook/ghl-outbound.php` with body `{phone, reply_text, action_taken, calendar_event_id?}`
3. `api/webhook/ghl-outbound.php`:
   - Verify shared secret header `X-HiService-Token`
   - Log payload to `conversations` (channel=ghl, direction=in)
   - Send `reply_text` to `phone` via `wa_send_text`
   - Log outbound to `conversations` (channel=whatsapp, direction=out)
4. **State machine update**: when `mode='general_help'`, the WhatsApp webhook handler bypasses the FSM and calls `ghl_post_inbound_message` directly. Escape word check (`menu`/`0`/`back`) still happens BEFORE the GHL call.

**Done when:** Send "menu" → reply with menu → send "2" → bot says "ask me anything" → ask "what areas do you deliver to?" → AI answers via Meta WhatsApp.

---

### 🟦 STAGE 4 — Web Shop + Admin Panel (Days 9–12)

**Goal:** Public web ordering shop AND comprehensive admin panel.

#### Days 9–10 — Public web shop

**Tasks:**
1. **Six-step funnel** under `/shop/`:
   - `shop/index.php` — landing + postal code check (queries `delivery_zones`)
   - `shop/identify.php` — phone OR email → look up `customers`/`xero` → create or recognize
   - `shop/browse.php` — product grid with quantity steppers, live total. Pulled from `products` (no per-request Xero call — sync job runs nightly).
   - `shop/address.php` — pre-fill from customer record, allow edit
   - `shop/slot.php` — calendar grid pulling next 7 days from `slots` (with `booked_count < capacity`)
   - `shop/pay.php` — order summary → click "Pay" → redirect to PayFast pay link
2. **Use the same `state_machine.php`** to validate state transitions on the web. Web is just a different UI on the same brain — every step logs to `conversations` (channel=`web`).
3. **Cart persistence** via session cookie + `orders` row with status=`cart`.
4. **PayFast return URLs**:
   - `shop/success.php` — "Payment received, you'll get an invoice on WhatsApp"
   - `shop/cancelled.php` — "Cancelled, your slot is held for 5 min"

**Acceptance:** A complete order placed end-to-end on the web shop, with the same MySQL slot row decremented and a Xero invoice on the same trigger as the WhatsApp path.

#### Days 11–12 — Admin panel

**Tasks:**
1. `admin/index.php` — Dashboard:
   - Today: orders, revenue (paid only), pending payments, active sessions
   - Charts: 7-day revenue, 7-day order count, slot fill rate
2. `admin/orders.php`:
   - List with filters: status, channel, date range
   - Click row → drawer/modal with full order detail, order_lines, payment status, conversation transcript
   - Actions: cancel order (soft, sets status), refund (logs only — actual refund manual), resend invoice, mark delivered
3. `admin/conversations.php`:
   - Live list of recent conversations grouped by phone
   - Click → full transcript with timestamps + intent labels
   - **Take Over button** — sets `conversations.taken_over_by = user_id` on the next inbound, AND sets `sessions.mode = 'human'` (new mode) so the FSM short-circuits and admin types replies in a chat box. Inbound messages still log; outbound goes through `wa_send_text`.
4. `admin/slots.php`:
   - Calendar grid (next 30 days)
   - Per-slot: capacity / booked / orders list
   - Add/remove slots, change capacity, deactivate
5. `admin/products.php`:
   - List from `products` (synced from Xero)
   - Toggle `is_active` (whitelist), edit `sort_order`, override price (locally, not in Xero)
   - Trigger manual Xero sync
6. **Reports** (optional polish): CSV export per query, GA-style drill-down.

**Acceptance:** Operator can run a full day from the admin panel without touching code: see new orders, intervene in stuck chats, manage slots, sync products.

---

### ⬛ STAGE 5 — Cutover (Days 13–14)

**Goal:** Replace `n8n.imappliedseo.com` with the new backend in a controlled, rollback-able switch.

#### Day 13 — Full dress rehearsal

**Tasks:**
1. **End-to-end on test WhatsApp number**:
   - Fresh contact → menu → ordering → full happy path → real PayFast payment → real Xero invoice → real PDF received
   - Same contact → menu → general help → AI answers FAQ → AI books a calendar slot → escalation works
   - Web shop happy path
   - Admin take-over works
   - Cancel + reset works
2. **Concurrency test**: simulate 5 customers booking the same slot simultaneously (via `tests/manual/load-slot.php`). Verify only `capacity` succeed and the rest get "slot full" + are offered alternates.
3. **Failure modes**:
   - PayFast ITN with bad signature → rejected, order stays `pending_payment`
   - Xero down → order stays `paid` but `xero_invoice_id` null. Background retry job handles it.
   - GHL down → general help mode bot replies "I'm having trouble — please call 063 693 5532".
4. **Sign-off** from Hi-Service stakeholder. Lock the codebase. Tag in git.

#### Day 14 — Cutover

**Tasks:**
1. **Switch Meta WhatsApp webhook** from `n8n.imappliedseo.com/...` to `https://orders.hi-servicegas.co.za/api/webhook/whatsapp.php` in business.facebook.com. Verify token: the one we generated.
2. **Switch PayFast notify URL** in PayFast dashboard from old → new.
3. **Update Xero callback** if any (none expected — we use refresh token flow).
4. **Update GHL workflow's outbound webhook** URL to new.
5. **Monitor live traffic** for 4 hours. Watch `logs/whatsapp-*.log`, `event_log`, `conversations`. Compare order count to historical baseline.
6. **Old n8n stays ACTIVE for 7 days** as standby. Toggle off only after 7 days of clean operation.
7. **Document** the cutover in `docs/CUTOVER-2026-XX-XX.md` — what was switched, what monitoring showed, any issues.

**Rollback procedure** (if disaster):
- Switch Meta webhook back to old n8n URL
- Switch PayFast notify URL back
- Re-activate old n8n if it was deactivated
- Diagnose new backend, re-deploy fix, re-cut

---

## 7 · Cross-cutting concerns (apply to every stage)

### Error handling
- Every PHP file starts with `declare(strict_types=1);`
- Set `error_reporting(E_ALL); ini_set('display_errors', '0');` in production
- Webhook entrypoints: catch all `Throwable`, log full trace, return `{"ok":false,"error":"<msg>"}` with 200 (so Meta/PayFast don't retry forever)
- Customer-facing errors via WhatsApp: a single fallback message "Sorry, something went wrong. Please try again or contact 063 693 5532." Never expose stack traces.

### Logging
- All inbound webhooks log raw body BEFORE any processing
- All outbound API calls log: URL, method, status, latency
- Sensitive fields (auth tokens, signatures) get masked in logs (`****`)
- Rotate logs daily (filename has date)

### Security
- All admin routes call `requireLogin()`. Operators cannot delete (only soft-delete via status fields).
- HTTPS enforced via `.htaccess`
- CSRF tokens on every admin POST form
- Bcrypt for passwords (`PASSWORD_DEFAULT`, cost 12)
- SQL via prepared statements only
- Output escaped via `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
- Webhook tokens (`META_VERIFY_TOKEN`, `X-HiService-Token`) generated with `bin2hex(random_bytes(32))`
- `.env` is one level above docroot. `.htaccess` denies any direct request to `*.env*`.

### Performance
- LiteSpeed caches GET responses by default — disable cache on `/admin/*` and `/api/*` via `.htaccess`
- DB indexes per the schema (`phone`, `status`, `delivery_date`, `paid_at`)
- Xero refresh tokens cached for 1800s (Xero's TTL)
- Don't pull product list from Xero on every web-shop view — sync nightly + on-demand from admin

### Observability
- Admin dashboard shows: orders today, revenue today, pending payments, active sessions, error count last hour
- Cron every 15 min: scan `orders` for stuck `pending_payment` > 25 min → mark `failed`, free up the slot
- Cron every 5 min: scan `sessions` for `expires_at < NOW()` → soft-clean (reset mode to NULL)
- Optional: send Slack/email alert on > 5 errors in 5 minutes

### Migration safety
- Old n8n stays running. Never edit it.
- DON'T migrate historical orders — they live in Xero. The new backend starts fresh.
- DO seed the `customers` table from a one-time Xero pull on Day 13 so returning customers are recognized immediately at cutover.

---

## 8 · Done definition (whole project)

The project is "done" when ALL of the following are true:

- [ ] `orders.hi-servicegas.co.za` serves the admin panel + web shop on HTTPS
- [ ] Meta WhatsApp webhook points to the new backend
- [ ] PayFast notify URL points to the new backend
- [ ] A real customer can complete a gas order via WhatsApp end-to-end (menu → catalogue → slot → pay → PDF)
- [ ] A real customer can complete a gas order via the web shop end-to-end
- [ ] A real customer can chat with the GHL AI bot via WhatsApp under "general help" mode and get an answer
- [ ] Admin panel shows live data: orders, conversations, slots, products
- [ ] Admin "take over" interrupts the bot and lets a human reply via the panel
- [ ] PHPUnit suite passes ≥95% on `state_machine.php` and `intent_detector.php`
- [ ] All 5 known n8n bugs (sandbox PayFast, duplicate state, midnight reset, Sheets race, no error handler) are gone
- [ ] Old n8n on imappliedseo.com is deactivated (after 7-day standby)
- [ ] Project README documents how to add a new product, add a new delivery zone, and add a new admin user

---

## 9 · How to use this prompt

To kick off a new session:

```
Read /Users/hivebrain/Claude/Hi-Service Chatbot/PROMPT.md and the four reference docs in that folder.
Confirm Stage <N> as started. List any missing credentials. Then begin coding.
```

To resume a session:

```
Read /Users/hivebrain/Claude/Hi-Service Chatbot/PROMPT.md.
Check git log + last commit. Tell me which Stage we're in and what's left in this stage.
Resume.
```

The agent should always:
1. Re-read the four reference docs first
2. Check `.env.local` for what credentials are populated
3. Use TodoWrite to track stage tasks
4. Never deviate from the file structure in §3
5. Never hardcode secrets
6. Never touch the imappliedseo.com n8n instance
7. Tag progress in git after each stage completes

---

*Prepared by Shawn / HiveCliq · v1 · 2026-05-06*
