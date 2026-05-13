# Hi-Service Chatbot — n8n Flow Analysis & Architecture Recommendation

**Date:** 2026-05-06
**Source files analysed:**
- `WA Order Json/WhatsApp Gas Ordering - Hybrid State Machine (Complete).json` (122 nodes, 9254 lines)
- `WA Order Json/Sub Workflow_ PayFast Payment Webhook.json` (23 nodes, 1464 lines)

---

## 1. What the existing n8n bot actually does

End-to-end flow:

```
WhatsApp Trigger ─► Filter (text only) ─► Extract phone+text+date
                                                │
                                                ▼
                            session_key = phone:yyyy-MM-dd
                                                │
                                                ▼
                              Look up Conversation State (Data Table LKHw0DXgHXxA5O4S)
                                                │
                                                ▼
                              Detect intent (pure regex/pattern matching, no AI)
                                                │
                                                ▼
                              State Machine Controller
                                                │
                                                ▼
                              Action Router (Switch — 17 actions)
                                                │
       ┌──────┬──────┬──────┬──────┬──────┬─────┴─────┬──────┬──────┬──────┐
       ▼      ▼      ▼      ▼      ▼      ▼           ▼      ▼      ▼      ▼
    cancel  check  recheck confirm aweceus book   update  show  check  process
            cust   details  addr   details slot    cust   prod  slots  payment
                                                  details
       ...                                                       ...

                                                │
                                                ▼
                                      Merge All Responses
                                                │
                                                ▼
                                      Send WhatsApp Response
                                                │
                                                ▼
                                      Update Conversation State (insert/update)
```

### State machine (10 states)

1. `initial`
2. `awaiting_order_choice`              — repeat/new
3. `new_order_clarification`            — keep previous / new
4. `confirm_new_details`                — Y/N keep details
5. `awaiting_new_customer_details`      — name+address+email
6. `awaiting_existing_customer_details` — same, for existing
7. `awaiting_address_choice`            — same/different
8. `awaiting_new_address`               — full address
9. `awaiting_street_code`               — 4-digit postal
10. `collecting_order_details`          — letter+qty tokens (e.g. `B2`)
11. `awaiting_slot_selection`           — A–D or custom date
12. `awaiting_payment_confirmation`     — P/D/Cancel
13. `processing_payment`                — generates PayFast URL
14. `cancelled` (terminal)

Plus a global `cancel`/`stop`/`reset` keyword reset.

### Integrations

| System | Used for | Where |
|---|---|---|
| **Meta WhatsApp Cloud API** | Inbound webhook + send messages | `WhatsApp Trigger1`, 3× `whatsApp` send nodes (main); 3× in PayFast sub |
| **Xero** | Customer create/update/find/get + Items catalog + Invoice creation + Tenant lookup | 7 nodes main, 1 node + 3 HTTP nodes sub |
| **Google Sheets** | Delivery schedule (slot booking), delivery locations, Xero whitelist (recipes) | 9 sheets nodes main, 4 sub. 3 distinct documents. |
| **n8n Data Tables** | Conversation state + product catalog cache | `LKHw0DXgHXxA5O4S` (state), `Cexuiy7nCwVKfVSx` (catalog) |
| **PayFast** | Payment URL generation + ITN verification | Inline JS in 2 Code nodes (MD5 hand-rolled) |

---

## 2. Pain points found (the "issues" you mentioned)

### Critical bugs

1. **Duplicate `confirm_new_details` state definition** — defined twice in the FSM, second overrides first. The "new customer" branch is dead.
2. **PayFast still pointed at sandbox** — `https://sandbox.payfast.co.za/eng/process?…`. If activated as-is, no real charges go through.
3. **PayFast credentials hardcoded** in two Code nodes — `merchant_id: '10043198'`, `merchant_key: '6a0oqxb4gq7rv'`, `passphrase: 'PassPhrase001'`. To rotate, you have to find each node.
4. **Wrong domain in PayFast return/notify URLs** — uses `imappliedseo.com` and `n8n.imappliedseo.com/webhook/payfast-itn`. Should be `hi-servicegas.co.za`.

### Design fragility

5. **Pure regex intent detection** — no fuzzy matching, no AI fallback. Examples that all fail today: "1." "yes please" "buy gas", "i want to order".
6. **n8n Data Tables for state** — proprietary to n8n, not queryable from outside, no admin panel possible.
7. **Google Sheets as scheduling DB** — race conditions (two slots booked simultaneously can both succeed), slow API, daily quota risk.
8. **Hand-rolled MD5 in JavaScript** — 100+ lines duplicated in two Code nodes. PHP's built-in `md5()` is one line.
9. **Daily session reset** — `session_key = phone + ':' + yyyy-MM-dd`. If a customer starts an order at 23:55, the state vanishes at midnight.
10. **No global error handler** — Code nodes throw → execution dies → user gets no reply.
11. **No conversation log** — Data Tables hold state, but past messages aren't retained anywhere queryable.
12. **122 nodes is unmaintainable** — every IF, Set, Code, Merge is in flight. Any node failure breaks the chain. Almost impossible to hand off to another dev.
13. **No tests** — no way to validate changes without sending a real WhatsApp message.

### Operational

14. **No web order channel** — WhatsApp only. Desktop / non-WhatsApp customers can't buy.
15. **n8n upgrade risk** — n8n breaks workflows on major version bumps (the `dataTable` node alone is relatively new and shifts often).
16. **Two n8n instances** — `n8n.srv1159178.hstgr.cloud` (this one) and `n8n.imappliedseo.com` (live). Deployment story unclear.

---

## 3. Architecture options

### Option A — Stay on n8n, just add the GHL bridge
**Effort:** ~2 days
**Outcome:** Adds the menu + general help, does nothing about the underlying issues. You still own a 122-node flow with all the bugs above.

### Option B — Full PHP rebuild, drop n8n entirely
**Effort:** ~9–13 dev days (~2–3 weeks)
**Outcome:** Clean codebase, web ordering, real DB, admin panel, AI bot via GHL. Stable.

### Option C — Hybrid: PHP backend handles everything, GHL handles AI/calendar/notifications
**Effort:** ~9–13 dev days (same as B)
**Outcome:** Same as B, but explicit split: PHP owns the deterministic order flow, GHL owns the conversational AI + scheduling. Best of both. **This is what I'd recommend.**

---

## 4. Recommended architecture (Option C)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                  orders.hi-servicegas.co.za (PHP 8.1 + MySQL)           │
│                                                                          │
│   Web ordering UI            Admin Panel                                 │
│   (browse → cart → pay)     (orders, conversations, intervene)           │
│                                                                          │
│   ┌──────────────────────────────────────────────────────────────────┐  │
│   │             Single backend                                        │  │
│   │  ┌──────────────────┐  ┌──────────────────┐  ┌────────────────┐ │  │
│   │  │ WhatsApp webhook │  │ Web order API    │  │ Admin API      │ │  │
│   │  └────────┬─────────┘  └────────┬─────────┘  └────────────────┘ │  │
│   │           │                     │                                 │  │
│   │           └─────────┬───────────┘                                 │  │
│   │                     ▼                                             │  │
│   │          State machine + intent detection                         │  │
│   │                     │                                             │  │
│   │                     ▼                                             │  │
│   │          MySQL: customers, orders, conversations,                 │  │
│   │                 products, slots, sessions                         │  │
│   │                     │                                             │  │
│   │     ┌───────────────┼───────────────┬─────────────┬─────────────┐ │  │
│   │     ▼               ▼               ▼             ▼             ▼ │  │
│   │  Xero API      PayFast API     Meta Graph     GHL API       Logs  │  │
│   │  (OAuth)       (md5 sig)       (WhatsApp)    (private        ↓    │  │
│   │  contacts +    pay link +      send message  integration)   File +│  │
│   │  invoices      ITN verify                    ↓              MySQL │  │
│   └──────────────────────────────────────────────│──────────────────┘ │  │
└────────────────────────────────────────────────────│─────────────────────┘
                                                    │
                                            ┌───────┼─────────┐
                                            ▼       ▼         ▼
                                       AI bot   Calendar   Admin notify
                                       (GHL)    booking    (GHL workflow)
```

### How the WhatsApp menu + GHL bridge works

```
WhatsApp inbound ─► PHP webhook
                         │
                         ▼
                    Lookup session in MySQL
                         │
        ┌────────────────┼─────────────────────┐
        │                │                     │
        ▼ no session     ▼ mode=ordering       ▼ mode=general_help
        Send menu:       PHP state machine     Forward message to GHL Conv API
        1️⃣ Order Gas     handles order         GHL AI bot replies
        2️⃣ General Help  (existing logic       n8n-style outbound webhook to PHP
                          ported from JS)      PHP sends to WhatsApp
                         │                     │
                         ▼                     ▼
                    Reply via WhatsApp    Reply via WhatsApp

Escape word `menu` from any mode → reset to mode=null → menu shown again.
```

### Why this is better than current n8n

| Concern | n8n today | Proposed |
|---|---|---|
| WhatsApp + web both work | ❌ WhatsApp only | ✅ Same backend serves both |
| State storage | n8n Data Tables (proprietary) | MySQL (queryable, admin panel possible) |
| Slot booking | Google Sheets (race conditions) | MySQL row lock, atomic |
| AI fallback | None — pattern matching only | GHL AI bot for general help mode |
| FAQ / company info | None | GHL AI bot trained on company knowledge |
| Calendar booking | None | GHL calendars |
| Admin notifications | None | GHL workflow → Slack/SMS/internal |
| Error handling | None — node crashes drop replies | Global try/catch, fallback message |
| Logs | n8n execution log only | MySQL conversation table + structured PHP logs |
| Credentials | Some hardcoded in JS | All in `.env` outside docroot |
| Tests | None | PHPUnit on state machine + intent classes |
| Cost | n8n hosting + Hostinger | Just cPanel (already paid) |
| Familiarity for you | n8n graph editing | PHP/MySQL — same stack as Westpeak / N24x4 / Praxis |

### File layout (mirrors your other PHP projects)

```
/home/hivecliqapps/public_html/orders.hi-servicegas.co.za/
├── index.php                   (web ordering landing)
├── api/
│   ├── webhook/
│   │   ├── whatsapp.php        (Meta WhatsApp webhook receiver)
│   │   ├── payfast-itn.php     (PayFast ITN verification + invoice)
│   │   └── ghl-outbound.php    (GHL AI bot reply → forward to WhatsApp)
│   ├── orders.php              (web order API)
│   └── admin.php               (admin panel API)
├── admin/                       (admin panel UI)
├── shop/                        (web ordering pages)
├── includes/
│   ├── config.php              (env loader, DB, secrets)
│   ├── db.php                  (PDO singleton)
│   ├── auth.php                (admin session)
│   ├── state-machine.php       (port of FSM logic)
│   ├── intent-detector.php     (port of regex matching + AI fallback hook)
│   ├── whatsapp.php            (Meta Graph send)
│   ├── xero.php                (OAuth + contact + invoice helpers)
│   ├── payfast.php             (PayFast signature + ITN verify — uses md5())
│   └── ghl.php                 (GHL conv + workflow webhook)
├── schema.sql
└── logs/
```

### Migration path (zero-downtime)

1. **Build PHP backend** alongside existing n8n. Existing n8n keeps running on `imappliedseo.com` uninterrupted.
2. **DB schema + admin panel** first — gives you visibility into orders.
3. **Port state machine** to PHP, with PHPUnit tests for each state transition.
4. **Wire Xero + PayFast + WhatsApp send** — test end-to-end with a single test number pointing at the new webhook.
5. **Build GHL bridge** — test general help mode in isolation.
6. **Build web ordering UI** — same backend, just different entry point.
7. **Cutover**: change Meta WhatsApp webhook URL from n8n to `orders.hi-servicegas.co.za/api/webhook/whatsapp.php`. PayFast ITN URL too.
8. **Run both for a week** — keep n8n on standby in case of rollback.
9. **Decommission n8n** — keep the JSON exports as reference.

### Effort estimate

| Phase | Days |
|---|---|
| Backend skeleton (config, DB, auth, .env) | 1 |
| State machine port + tests | 2 |
| WhatsApp gateway (Meta Graph in/out) | 1 |
| Xero contacts + invoices | 1 |
| PayFast pay link + ITN | 1 |
| GHL bridge (AI bot, calendar, notify) | 1 |
| Web ordering UI (browse → cart → pay) | 2 |
| Admin panel (orders + conversations + intervene) | 2 |
| Testing + cutover | 1–2 |
| **Total** | **~12 days (2.5 weeks calendar)** |

This matches the budget for similar HiveCliq builds (Westpeak ~3 weeks, N24x4 ~2 weeks).

---

## 5. What I need from you to start

1. **GHL Private Integration Token** for sub-account `dzu95Ecw2Cq2nFzf3a0G` (scopes: Conversations, Contacts, Calendars, Workflows, Custom Fields)
2. **Xero credentials** — the existing OAuth app or a new one for HiService
3. **PayFast live merchant credentials** (current ones are sandbox — `10043198` / `6a0oqxb4gq7rv` / `PassPhrase001`)
4. **Meta WhatsApp Cloud API token** + Phone Number ID + Business Account ID (separate from n8n's stored credential)
5. **cPanel access for `orders.hi-servicegas.co.za`** — either add subdomain to existing `hivecliqapps` cPanel or set it up under hi-servicegas.co.za hosting
6. **Confirmation** that we're decommissioning the imappliedseo.com n8n, not running both forever
7. **GHL AI bot** — does one already exist trained on company FAQ? Or do we set that up too?
8. **Calendar** — which GHL calendar should the AI book?
9. **Admin escalation** — when AI can't help, who/where gets notified?
