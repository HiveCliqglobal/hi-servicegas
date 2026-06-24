# WhatsApp Conversation Flow — Handover Document

**System**: Hi-Service Gas WhatsApp ordering bot (cPanel reference implementation)
**Target**: Anyone picking up this flow on a new platform (Next.js + XState rebuild OR another stack)
**Written**: 2026-06-08
**Status**: cPanel state frozen at git tag `demo-2026-06-07` (commit `f52dd91`). This document captures everything that survived 4 rounds of customer testing (Christelle, Karl, Shawn, plus a friend's blind test).
**Repo**: `github.com/HiveCliqglobal/hi-servicegas`

---

## 1. What this document is

A complete, opinionated specification of the WhatsApp ordering conversation flow that ships gas (or any consumable) on a route. It is the result of building the same flow three times — first in n8n, then in PHP, then iterated through customer testing rounds — until every dead end was closed and every footgun was guarded.

If you're rebuilding this flow on a new stack, **do not skip the guardrails section**. Every one of them exists because a real customer hit a real edge case that broke the previous version.

---

## 2. Architecture overview

The flow is a **finite state machine (FSM)** with three cooperating layers:

```
inbound webhook (Twilio sandbox OR Meta Cloud API direct)
        │
        ▼
┌─────────────────────────┐
│   IntentDetector        │  classifies the customer's message
│   (intent_detector.php) │  given the CURRENT state. returns
│                         │  { intent, action, confidence }
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│   StateMachine          │  given (current_state, intent),
│   (state_machine.php)   │  returns { next_step, action,
│                         │  response_template }
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│   Conversation          │  executes the action (DB writes,
│   (conversation.php)    │  external API calls), returns the
│                         │  reply text. may override next_step.
└────────┬────────────────┘
         │
         ▼
notify_send_text(phone, reply)   sends back via Twilio or Meta direct
```

**Key invariants**:
1. The IntentDetector NEVER guesses. If the current state is `S_AWAITING_SLOT_SELECTION`, it only looks for slot-shaped inputs (A/B or a date). Same input in a different state classifies differently.
2. The StateMachine is a **lookup table**, not code. Every (state, intent) pair has exactly one defined transition. Falls back to `_default` which clarifies.
3. The Conversation handler can **override** the FSM's `next_step` after doing real work — e.g. if the DB write fails, it stays in the same state and asks the user to retry.

---

## 3. The full conversation flow (happy path, new customer)

```
USER: "Hi"
BOT:  *Hi-Service Gas* 🔥
      How can we help today?
      *1* - Order LPG gas
      *2* - General help / questions

USER: 1
BOT:  👋 Welcome to Hi-Service Gas!
      [areas served + payment-upfront notice + support contact]
      First, what's your *4-digit postal code*?

USER: 7140
BOT:  Great — we deliver to *7140*! ✅
      Now please send your details, one per line:
      *1.* Name and surname
      *2.* Street address, suburb, city
      *3.* Email (optional)

USER: Christelle Lochner
      54 Jennings Street, Strand, Cape Town
      christelle@example.com

BOT:  Thanks Christelle! You're all set up. ✅
      [readback: name, contact, email, address]
      Reply *Y* if correct, *N* to fix

USER: Y
BOT:  *Our gas cylinders:*
      *A* - 5KG LPG Gas Swap — R 196.52
      *B* - 5KG LPG Gas + Cyl — R 546.52
      *C* - 9 KG LPG Gas Swap — R 321.74
      ...
      *Reply with the letter + quantity.*  Example: A2

USER: A1
BOT:  *Your order:*
      • 1 × 5KG LPG Gas Swap — R 196.52
      *Total: R 196.52*

      Delivering to:
      📍 54 Jennings Street, Strand, Cape Town 7140
      *Reply with:* *S* / *Y* same address | *D* / *N* different

USER: Y
BOT:  *Choose your delivery slot:*
      *A* - Tomorrow Morning (08:00-12:00)
      *B* - Tomorrow Afternoon (13:00-16:30)
      Or type a date (e.g. *28/05/2026*)

USER: A
BOT:  You've chosen:
      🗓 Tomorrow Morning (08:00-12:00)

      *Delivery address:*
      📍 54 Jennings Street, Strand, Cape Town, 7140

      *Your order:*
      • 1 × 5KG LPG Gas Swap

      *Total: R 196.52*

      *Reply with:*  *Y* confirm and pay  |  *N* pick a different time

USER: Y
BOT:  Perfect! Generating your payment link... 💳
      [PayFast link sent]

[customer pays]

BOT:  ✅ Payment confirmed!
      [Xero invoice PDF attached]
      Your delivery is scheduled for Tomorrow Morning (08:00-12:00).
      We'll send tracking when the driver heads your way.
```

**Returning customer differences**:
- Skips identity collection (recognised by phone)
- Goes straight from menu choice 1 → "deliver to your saved address?" → straight to slots OR new address flow

---

## 4. Complete state list

```
S_MENU                              top-level menu
S_AWAITING_ORDER_CHOICE             "repeat last (1) or new order (2)?"
S_NEW_ORDER_CLARIFICATION           recovery after unclear input
S_CONFIRM_NEW_DETAILS               new customer: Y/N on captured details
S_AWAITING_NEW_CUSTOMER_DETAILS     "send 3 lines: name / address / email"
S_AWAITING_EXISTING_CUST_DETAILS    update existing customer details
S_AWAITING_STREET_CODE              4-digit postal code
S_SHOWING_PRODUCTS                  catalogue rendered (transient)
S_COLLECTING_ORDER_DETAILS          parsing A1, B2, etc.
S_AWAITING_ADDRESS_CHOICE           "same (S/Y) or different (D/N)?"
S_AWAITING_NEW_ADDRESS              capture new address
S_AWAITING_ADDRESS_CONFIRM          (NEW from testing) readback Y/N
S_CHECKING_SLOTS                    transient — produces slot picker
S_AWAITING_SLOT_SELECTION           pick A/B or type date
S_CHECKING_CUSTOM_SLOT              transient — looks up date-specific slots
S_BOOKING_SLOT                      transient — reserves the slot
S_AWAITING_SLOT_CONFIRM             (NEW from testing) readback Y/N w/ address
S_AWAITING_PAYMENT_CONFIRMATION     last gate before PayFast hand-off
S_PROCESSING_PAYMENT                transient — generates PayFast link
S_CANCELLED                         terminal
S_GENERAL_HELP                      Claude Sonnet 4.5 conversational
S_OUT_OF_STOCK                      try other / callback / cancel
S_AWAITING_CALLBACK_DETAILS         capture name for callback queue
```

---

## 5. The 5 guardrails (DO NOT REMOVE)

Each of these exists because a real customer hit a real failure. Every new implementation must include all five. Tagged with the bug that proved we need them.

### 5.1 Smart Clarify (the recovery system)

When the IntentDetector returns `unclear` (confidence 0), the conversation does NOT reset to menu. It enters **smart_clarify**, a 4-tier recovery sequence:

| Strike | Action |
|---|---|
| 1 | Claude Haiku interprets the user's message, looks for nearby valid intents in the current state's transitions, and replies with a corrective hint that includes the **literal answer options** (e.g. "Reply *Y* or *N*") |
| 2 | If still unclear AND we're in a CRITICAL state (payment/slot/address confirm), GHL admin ping fires immediately (don't wait for strike 4) |
| 3 | Same as strike 1 but appends the answer options block deterministically — protects against Haiku paraphrasing without options (Bug from Christelle round) |
| 4 | Exit hatch: "I'm sorry, three options: try once more, type MENU, or call 021 492 8515" — state stays put |

**HARD RULE**: smart_clarify is the SOLE in-flow recovery. There is no supervisor isStuck / breakLoop / auto-reset to menu. Once added one, removed it. Never re-add.

### 5.2 Critical-state escalation at strike 2 (not strike 4)

States where being stuck = lost revenue:
- `S_AWAITING_PAYMENT_CONFIRMATION`
- `S_AWAITING_SLOT_SELECTION`
- `S_AWAITING_SLOT_CONFIRM`
- `S_AWAITING_ADDRESS_CHOICE`
- `S_AWAITING_ADDRESS_CONFIRM`
- `S_OUT_OF_STOCK`

In these states, after **2** unclear strikes (not the default 4), ping admin via GHL with `escalateStuckCustomer(phone, state, last_text, strike)`. State STAYS PUT so the human takes over the thread cleanly. Bot reassures customer: "📞 I've also let our team know — someone will check in shortly."

### 5.3 Universal CANCEL / MENU / HELP footer

Every in-flow reply gets a one-line footer:
```
_💡 *CANCEL* exit · *MENU* restart · *HELP* talk to a person_
```

- `CANCEL` → S_CANCELLED + clear session
- `MENU` → S_MENU + render menu
- `HELP` → at ANY state, escalates to admin immediately, sets handover mode

Skipped on terminal/menu replies so the menu isn't double-footered.

### 5.4 Readback before commit (Karl + Christelle bugs)

**Every captured input gets a readback in the NEXT message before the flow advances.** Three explicit Y/N confirm states exist:

| State | What's shown | Y → | N → |
|---|---|---|---|
| `S_CONFIRM_NEW_DETAILS` | Name + contact + email + address | Show product catalogue | Re-collect details |
| `S_AWAITING_ADDRESS_CONFIRM` | Captured address (line1, line2, city, postal) | Show slot picker | Re-collect address |
| `S_AWAITING_SLOT_CONFIRM` | Chosen slot + delivery address + cart + total | Generate PayFast link | Re-show slot picker |

The slot confirm specifically shows ALL of: date/time, delivery address, line items, total. The customer never gets to PayFast without seeing PERSON + ADDRESS + TIME + ORDER + AMOUNT in one screen.

### 5.5 Shared stock validation at cart-commit

`ProductRepo::checkCartStock(lines)` is ONE function called by BOTH the WhatsApp handler (`actCollectOrderDetails`) AND the web shop (`shop/browse.php`). Rules:

- Customer catalogue filter is `is_active = 1` ONLY. Stock count is NEVER used to hide products from browsing.
- Stock gate fires ONLY at cart-commit time, never during browsing.
- Untracked products (`is_tracked = 0`) — services, levies, deposits — are always allowed.
- Tracked products that fail stock → transition to `S_OUT_OF_STOCK` with 3 options: try another product / leave name for callback / cancel.

This prevents the "browse-it-then-can't-buy-it" trap AND the "stock-changed-while-typing" race.

---

## 6. Bug fixes that became design rules

Every one of these was a real bug shipped to production at some point. Carrying these forward as rules saves the next implementation weeks.

### 6.1 Token resolver must accept BOTH shapes

```php
// User types "C2 B1"
// Parser builds an ASSOCIATIVE array:  ['C' => 2, 'B' => 1]
// Older callers may pass a FLAT array: ['C2', 'B1']
// resolveTokens MUST handle both. strict_types=1 + a single-shape
// resolver = TypeError, caught by webhook, customer sees "Sorry, something
// went wrong" (Bug 4, Karl's test on 2026-06-01).
```

### 6.2 Address parser must accept BOTH formats

The prompt template said "Street, Suburb, City, Postal code" (reads as comma-separated). The parser only accepted newlines. Customer (Karl) typed comma-separated, got rejected, got contradicted. **Fix**: parser tries `\n` split first, falls back to comma split. Also: find the 4-digit postal anywhere in input via regex, not by line index. Customer can write "Cape Town 7140" instead of postal alone on its own line.

### 6.3 Past-date in slot picker must reject cleanly

IntentDetector parses any date the user types and routes to action `check_custom_slot`. If that action handler doesn't exist in the dispatcher (Karl's bug — it was missing), the session parks at `S_CHECKING_CUSTOM_SLOT` forever (he was stuck 3+ hours). 

**Fix**: every action referenced in the FSM MUST have a handler. Past dates reject with a clear message AND re-show the A/B picker. Future dates look up real slots for that ISO date, fall through to next available days if nothing seeded.

### 6.4 Validate address zone BEFORE DB save, never after

The old `actCaptureNewAddress` inserted the row, THEN called `actShowSlots($stateData)` with the wrong arity. ArgumentCountError caught, customer saw "Couldn't save that address" — but the row WAS saved. They retried. Now they have duplicate addresses, neither is default. (Christelle's bug.)

**Fix order**:
1. Validate input (4-digit postal, in delivery zone)
2. Insert with `is_default = 1` (otherwise new address doesn't take effect)
3. ONLY THEN advance the state

If the catch fires, the row should NOT have been written. Always validate first.

### 6.5 Catalogue must show BOTH variants per size

Hi-Service has two product shapes per cylinder size:
- **Swap** (customer returns empty cylinder, cheaper, e.g. 5KG R 196.52)
- **+ Cyl** (gas + new cylinder, e.g. 5KG R 546.52)

Customer needs to see both. The original seed data only had one variant per size + a duplicate row. Karl flagged this immediately. 

**Fix**: 10 active products (5 sizes × 2 variants). `letteredCatalogue()` tie-break sorts by `sort_order` so Swap appears before +Cyl within each kg band.

### 6.6 Xero (or any sync) preserves admin curation

The UPDATE statement in `XeroSync::syncItems` ONLY touches columns Xero authoritatively owns: `xero_item_id`, `code`, `name`, `description`, `price`, `in_stock_qty`, `is_tracked`, `updated_at`. It does NOT touch `is_active`, `sort_order`, `image_url`, or any other admin-curated column. This rule MUST be preserved across all integrations (Sage, QuickBooks, Wave) on any rebuild.

### 6.7 Driver app filter (Bug from Shawn's test)

The driver "today" query was `ORDER BY delivery_date ASC LIMIT 50` with no `is_demo` filter. 69 seeded demo orders from May 2025 swamped the queue. Real current orders never appeared.

**Rule**: driver queue filter MUST be:
```sql
status = 'paid'
  AND is_demo = 0
  AND (assigned_driver_id IS NULL OR assigned_driver_id = ?)
  AND (slot.delivery_date >= CURDATE() - INTERVAL 1 DAY
       OR (slot.delivery_date IS NULL AND paid_at >= NOW() - INTERVAL 7 DAY))
ORDER BY slot.delivery_date ASC, slot.time_block ASC, paid_at ASC
```

### 6.8 Every async job emits structured event log + queued retry

Xero PDF delivery was silently broken for 8 days before we noticed. The webhook caught the exception, the customer got a text-only confirmation (no PDF attached), nobody logged or alerted on the failure.

**Rule**: every job (cron, webhook handler, background task) writes to `event_log` with status + duration + last_error. The platform health endpoint surfaces last-successful-run per integration. Failed jobs are queued for retry with backoff, not silently abandoned.

### 6.9 Welcome message must include the upfront-payment notice

First-time customer welcome should include:
- Areas served (e.g. "Helderberg, Stellenbosch, Kleinmond, Betty's Bay, Pringle Bay, Rooi Els")
- "Important: This WhatsApp service requires online card payment at the time of ordering"
- Support phone + email
- 4-digit postal code prompt with example

Don't skip the payment-upfront line. It sets expectation, prevents "but I want COD" friction.

### 6.10 Cart recovery: WhatsApp first, email second

When an order goes idle in `S_AWAITING_PAYMENT_CONFIRMATION` or `S_AWAITING_SLOT_CONFIRM`:
- T+15 min: WhatsApp recovery message #1 ("your gas is one tap away, finish here: <magic_link>")
- T+24h: WhatsApp recovery message #2 (with R 10 off as carrot)
- T+24h + no click: email fallback (only if email captured)
- T+72h: stop (don't spam)
- Magic link cart restore = `/shop/r/<token>` single-tap resume

Industry data: WhatsApp recovery converts cart 3-5x better than email (18-30% vs 5-10%). Don't lead with email when phone is already captured at step 1.

---

## 7. Testing methodology (what every new build needs to verify)

These are the test scenarios that actually surfaced the bugs above. Run all of them against every new implementation BEFORE shipping:

1. **C2 token test**: type `C2` (product C, qty 2) and confirm the system handles it without "Sorry, something went wrong"
2. **Address comma test**: type address with commas on ONE line, confirm parser accepts
3. **Address newline test**: type address on 4 separate lines, confirm parser accepts
4. **Past-date test**: at slot picker, type `01/05/2024`, confirm clean rejection + re-show A/B
5. **Address-change test**: returning customer types `D` (different address), confirm save succeeds AND new address becomes default AND no duplicate row created
6. **Address-readback test**: after entering new address, confirm the system reads it back + waits for Y/N before advancing to slots
7. **Slot-confirm content test**: at slot confirm, verify the message shows delivery ADDRESS in addition to slot + cart + total
8. **Critical-state escalation test**: at payment confirm, type two unclear messages, confirm admin gets pinged (don't wait for strike 4)
9. **Smart_clarify literal options test**: at any state, type something off-script, confirm Haiku's response INCLUDES the literal answer options (e.g. "Reply Y or N")
10. **HELP intent test**: type `HELP` at ANY state, confirm immediate admin escalation + state preserved
11. **CANCEL test**: type `CANCEL` mid-order, confirm clean exit + session cleared
12. **Stock OOS test**: order quantity exceeding stock, confirm `S_OUT_OF_STOCK` with 3 options (try other / callback / cancel) — NOT a hard error
13. **Repeat-order test**: returning customer types `1` at menu, confirm last order's items load into cart + skip to address confirm
14. **Xero invoice PDF test**: complete a paid order, confirm PDF arrives on WhatsApp within ~30 sec
15. **Driver app filter test**: confirm driver "today" view shows only relevant non-demo orders with sane slot dates

---

## 8. Anti-patterns to avoid

Things we tried and removed because they caused worse problems than they solved:

1. **Supervisor isStuck / breakLoop auto-reset.** Added once. It detected 3 same-state repeats and forced the customer back to menu. Real customers were getting kicked to menu when they were thinking. Removed. Smart_clarify owns recovery, period.

2. **Aggressive abandonment crons (every 30 sec).** Created notification spam for admins on legitimate slow typing. Tightened to every 2 minutes with dedup per session.

3. **Default seed products as canonical catalogue.** Seed rows have no `xero_item_id`, no stock tracking, no admin curation path. Always treat the accounting system (Xero / Sage / QB / Wave) as canonical for price + stock + name. Admin curates `is_active` + `sort_order` + `image_url` locally.

4. **Mailto links on public marketing pages.** Spam bots scrape them within hours. Route all customer contact through a sign-up form that writes to a queue.

5. **Single token resolver shape.** strict_types caught us. Always tolerate at least two input shapes for any parser that crosses module boundaries.

6. **Catching exceptions and showing generic "something went wrong"** when a DB write may have already succeeded. Either the write committed (don't lie to the user) or it rolled back (specific error helps them retry).

---

## 9. Implementation checklist for a new platform build

Copy-paste into the issue tracker. Every box must be ticked before customer testing.

### Schema
- [ ] Multi-tenant from day 1: every table has `tenant_id`, RLS scopes everything
- [ ] `products` includes `is_active`, `sort_order`, `image_url` as admin-curated columns that sync NEVER overrides
- [ ] `orders` includes `is_demo` flag for staging data
- [ ] `addresses` includes `is_default`, `customer_id`, `postal_code` (4-digit indexed)
- [ ] `slots` includes `delivery_date`, `time_block`, `capacity`, blackout dates per tenant
- [ ] `event_log` table with structured payload for every job + admin action + webhook

### Conversation flow
- [ ] FSM defined as a lookup table, not a switch statement
- [ ] Exhaustive-switch enforcement: every action referenced in the FSM must have a handler (TypeScript exhaustive switch + CI check)
- [ ] All 5 guardrails implemented (smart_clarify, critical-state escalation, universal footer, 3 readback states, shared stock check)
- [ ] All 10 bug fixes from section 6 baked into the parsers and handlers
- [ ] All 15 test scenarios in section 7 pass before customer testing

### Operations
- [ ] Every async job queued via Trigger.dev / equivalent, never raw cron
- [ ] Every job emits to `event_log` with status + duration + last_error
- [ ] Health endpoint surfaces last-successful-run per integration
- [ ] Xero / Sage / QB / Wave UPDATE statements explicitly preserve admin curation columns

### Recovery + retention
- [ ] Cart recovery: WhatsApp first (T+15m, T+24h), email second after no-click
- [ ] Magic link cart restore at `/shop/r/<token>`
- [ ] Abandonment detection runs every 2 minutes, dedups per session

### Customer experience
- [ ] Welcome message includes areas served + payment-upfront notice + support contact
- [ ] Every captured input gets a Y/N readback before advancing
- [ ] HELP intent at any state escalates to admin and preserves state
- [ ] CANCEL at any state cleanly exits and clears session

---

## 10. Reference files in the cPanel codebase

If you need to read the actual reference implementation:

| File | Purpose |
|---|---|
| `code/api/webhook/whatsapp.php` | Inbound webhook entrypoint, provider-agnostic (Twilio + Meta) |
| `code/includes/intent_detector.php` | IntentDetector — classifies message given current state |
| `code/includes/state_machine.php` | StateMachine — FSM lookup table |
| `code/includes/conversation.php` | Conversation — action handlers + smart_clarify + readbacks |
| `code/includes/product_repo.php` | Catalogue + shared stock check + token resolver |
| `code/includes/customer_repo.php` | Customer + addresses + postal zone lookup |
| `code/includes/slot_repo.php` | Delivery slot management |
| `code/includes/order_repo.php` | Orders + line items + state transitions |
| `code/includes/xero_sync.php` | Xero pull + push with admin-curation preservation |
| `code/includes/payfast.php` | PayFast IPN handler + signature verify |
| `code/tools/abandonment-watcher.php` | Cron: detect 4 abandonment patterns |
| `code/tools/xero-retry.php` | Cron: retry failed Xero pushes |
| `code/tools/xero-sync-products.php` | Cron: pull Xero items, preserve admin curation |
| `code/driver/today.php` | Driver PWA today's jobs (with the correct filter) |

---

## Contact

This document was assembled from 4 weeks of testing iterations against real customers (Christelle Lochner, Karl Marais, Shawn Lochner) on the Hi-Service Gas WhatsApp number in Helderberg, South Africa. If you're picking this up cold and something doesn't make sense, look in the git log for the commits referenced in section 6 — every bug fix has a tagged commit explaining the failure mode.

**Built by**: HiveCliq (`hivecliq.com`)
**Maintained by**: Shawn Lochner (`shawn@hivecliq.online`)
**Rebuild target**: Tappa SaaS (`tappa.chat`)
