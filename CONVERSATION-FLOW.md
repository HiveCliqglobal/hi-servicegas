# Hi-Service WhatsApp Bot — Complete Conversation Flow

Extracted directly from the existing n8n JSON — this is the actual user-facing script.
Every screen the customer sees, every option they can pick, what happens next.

---

## A. Gas Ordering Flow (current bot, to be rebuilt)

### Entry — user types anything (e.g. "Hi") to a known number

Bot looks up phone number in Xero. Three paths:

#### A1. **Brand-new customer** (not in Xero)
Bot replies:
```
*Welcome to Hi Service Gas*

Your reliable door-to-door LPG gas delivery service across Helderberg,
Stellenbosch, Kleinmond, Betty's Bay, Pringle Bay, Rooi Els, and
surrounding areas.
Note: Some areas within listed towns may fall outside our delivery routes

*Order, pay, and schedule* your delivery in one simple process.

Important: This WhatsApp service *requires online card payment* at the
time of ordering.

To get started, please enter your *4-digit street postal code*
(e.g. 7140) to confirm service availability.

(Type Cancel to cancel end this chat)

*Customer Support:* 063 693 5532 | gas@hiservice.co.za
```
**User input expected:** 4-digit postal code (e.g. `7140`)

→ if **in delivery zone** → **screen A4** (collect customer details)
→ if **outside zone** → "Your postal code sadly falls outside our current delivery route. 😢 We have noted your contact number…" (END)

---

#### A2. **Returning customer with previous order** (active in Xero, has past invoice)
Bot replies:
```
Welcome back, {first_name}! 👋

Your last order was:
•1 x 9kg LPG Gas Delivered - Incl. Exchange Cylinder
•1 x 5kg LPG Gas Delivered - Incl. Exchange Cylinder

Reply with:
1 - to repeat this order
2 - to place a different order

(Type Cancel to cancel end this chat)
```
**User options:**
- `1` (or "repeat") → **screen A6** (confirm address: same/different)
- `2` (or "different"/"new") → **screen A5** (show product catalog)

---

#### A3. **Returning customer, NO previous order** (active in Xero, no invoices)
Bot replies (confirm details first):
```
Welcome back, {first_name}.

Please confirm that the details we have for you are still correct :

*Name:* {full_name}
*Delivery Address:* {address}
*Email:* {email}

Reply with:
Y - Yes, details are correct
N - No, details are incorrect

(Type Cancel to cancel end this chat)
```
**User options:**
- `Y` → **screen A5** (show product catalog)
- `N` → **screen A4-update** (collect updated details)

#### A3b. **Archived customer** (was in Xero but archived)
```
Welcome to Hi Service Gas, {first_name}

You are currently loaded on our system but your status is ARCHIVED.

Your status will need to be changed to ACTIVE to order here.
Unfortunately, this can only be done by our admin team.

You can contact them at 063 693 5532 or admin@hiservice.co.za.

Alternatively, you could try again from a different phone number.

Hope to have you back and ACTIVE soon! 🙂
```
**No reply expected — END**

---

### A4. New customer details collection

After valid postal code, bot replies:
```
Excellent! Your area code falls within our delivery route. 🚚

Let's get you added to our system so we can process your order.

Please provide the following, 1 below the other:
1. Name and Surname
2. Street address, Suburb, City
3. Email Address

Example:
James Elliot
31 Example Road, Strand, Cape Town
james.elliot@gmail.com

(Type Cancel to cancel end this chat)
```
**User input:** name + address + email (3 lines)

After parsing → creates Xero contact → **A4-confirm**

#### A4-confirm
```
You have been loaded on our system, {FirstName}. We have your details as:

Name: {Name}
Contact Number: {ContactNumber}
Email: {EmailAddress}
Delivery Address: {AddressLine1}, {AddressLine2}, {City}, {PostalCode}

Reply with:
Y - Yes, details are correct
N - No, details are incorrect

(Type Cancel to cancel end this chat)
```
- `Y` → **screen A5**
- `N` → **screen A4-update**

#### A4-update (existing user wants to change details)
```
Let's update your details on our system so we can process your order.

Please provide the following, 1 below the other:
1. Name and Surname
2. Street address, Suburb, City, Street/Postal code
3. Email Address

*Example:*
James Elliot
31 Example Street, Strand, Cape Town, 7140
james.elliot@gmail.com

*Or reply with:*
R - Recheck your current address

(Type Cancel to cancel end this chat)
```
- 3-line input → updates Xero → confirm screen → A5
- `R` → re-shows the current details from A3

---

### A5. Product catalogue

Pulled from Xero Items API + filtered against a Google Sheet whitelist (Recipes tab).
Sorted by kg ascending.

```
============================
🚨 *IMPORTANT – Read Carefully Before Placing an Order*
============================

📝 *How to order:*
Reply with the Letter in front of the item followed by the quantity.

*Example:*
B2
D1
(product B x 2 and product D x 1)

📦 *Available Products:*

*A.* 5kg LPG Gas Delivered - Incl. Exchange Cylinder
   🏷 R223.00

*B.* 9kg LPG Gas Delivered - Incl. Exchange Cylinder
   🏷 R385.00

*C.* 19kg LPG Gas Delivered - Incl. Exchange Cylinder
   🏷 R790.00

*D.* 48kg LPG Gas Delivered - Incl. Exchange Cylinder
   🏷 R1,950.00

…(etc, products with stock indicators (5 left), (12 in stock))
```

**User input expected:** lines of `LETTER + QUANTITY`, e.g.
```
B2
D1
```
or comma-separated `B2, D1`.

→ Order parsed → **screen A5-confirm**

#### A5-confirm
```
Thank you!

To confirm, your order is:

•2 x 9kg LPG Gas Delivered - Incl. Exchange Cylinder
•1 x 48kg LPG Gas Delivered - Incl. Exchange Cylinder

Reply with:
1 - proceed
2 - to place a different order

(Type Cancel to cancel and end this chat)
```
- `1` → **screen A6** (address: same/different)
- `2` → re-shows **A5** (catalogue)

---

### A6. Address choice
```
Great! Your delivery address on file is:

*Address:* 31 Example Road, Strand, Cape Town, 7140

Would you like delivery to:
S - the same address
D - a different address?
```
- `S` (or `Y`) → **screen A7** (slot picker)
- `D` (or `N`) → **A6-new-address**

#### A6-new-address
```
What's your new delivery address?

Please include: Street, Suburb, City, Postal code
```
- valid address (>10 chars) → save → continues to **A7**
- `R` → back to **A6** (current address)

---

### A7. Delivery slot picker

Pulls from Google Sheet "HiServices Gas Delivery Sheet" → Annual tab.
Logic:
- Looks 7 days ahead
- Skips slots where `customer_name` is filled (booked)
- Time-of-day rules:
  - Today before 12:00 → shows today PM + tomorrow AM
  - Today after 12:00 → shows tomorrow AM + tomorrow PM
- Returns max 2 morning + 2 afternoon slots, lettered A–B (or A–D if both available)

```
Next Available delivery slots:

🌅 *Morning Slots (08:00-12:00):*
A - Wednesday, 7 May 2026 - 3 slots available

☀️ *Afternoon Slots (13:00-16:30):*
B - Wednesday, 7 May 2026 - 4 slots available

Reply with A or B for your preferred slot, or type a different date to
find the closest available slots to it.
Example:
25/01/2026 (use this format)
```
**User options:**
- `A` / `B` (etc) → book that slot → **screen A8**
- `25/01/2026` (date string in DD/MM/YYYY) → finds closest slots near that date
- `tomorrow` / `monday` / `today` → relative date parsed
- garbage → "The letter you entered does not match… type a date"

---

### A8. Order summary + payment confirmation
```
✅ Delivery booked!

📋 *Order Summary:*


*Recipient:* James Elliot

*Products:*
• 2 x 9kg LPG Gas Delivered - Incl. Exchange Cylinder
• 1 x 48kg LPG Gas Delivered - Incl. Exchange Cylinder

*Delivery:* 07/05/2026 at 08:00-12:00
*Address:* 31 Example Road, Strand, Cape Town, 7140

*Total:* R2,720.00

Reply:
P - to pay
D - to place a different order
Cancel - to cancel
```
- `P` (or "pay") → generate PayFast link → **screen A9**
- `D` (or "new") → back to **A5** (catalogue)
- `cancel` → reset

---

### A9. Payment link
```
💳 Payment Link Ready!

Complete your payment:

https://sandbox.payfast.co.za/eng/process?merchant_id=…&signature=…

Amount: R2720.00
Order: ORD-1730987654

Payment link expires in 24 hours.
```
**No further input expected on WhatsApp** — user pays in browser.

PayFast then ITN-pings the **PayFast sub-workflow** webhook.

---

### A10. Post-payment (sub-workflow fires)

Sub-workflow:
1. Verifies PayFast signature (MD5 + passphrase)
2. Looks up the order in Data Table
3. Creates Xero invoice with line items
4. Confirms payment in Xero
5. Downloads invoice PDF from Xero
6. Sends PDF via WhatsApp:

```
🏁 All done.

Your order has been finalised, and our dispatch team has been notified
of your delivery booking.

Please find attached your invoice.

Thank you for your support. 👍

*For customer support or enquiries, contact us at:*

063 693 5532

gas@hiservice.co.za
```

7. Updates Google Sheet with invoice number on the booked slot
8. Sends accounts notification (separate WhatsApp message to admin)
9. Waits 5s, then sends:
```
This session has ended. No further action is required.
Type 'Hi' into the chat to start a new order.
```
10. Cleans up Data Tables (delete state + catalog cache for this phone)

---

### Global escape hatches (work from any state)

| User types | Action |
|---|---|
| `cancel` / `stop` / `reset` | Wipes conversation state + catalog → "✅ Your session has been reset! Reply Hi to start a fresh order." |

### Bug warning (current bot, to fix on rebuild)

- New customer flow asks for postal code FIRST, then full address. After collection, the customer is created in Xero. But the FSM has `confirm_new_details` defined twice — the second definition overrides the first, so the new-customer branch never reaches `awaiting_existing_customer_details`. Probably explains some of the "issues" you're seeing.
- "Recheck Current Address" branch references `customer_data.addresses[0].PostalCode` without optional chaining — crashes if missing.
- PayFast URL hardcoded as **sandbox** (`sandbox.payfast.co.za`) — would not charge real money.
- Daily session reset at midnight (`session_key = phone:yyyy-MM-dd`) — orders started at 23:55 vanish.

---

## B. NEW: General Help / GHL AI Bot Flow (to be built)

### Entry — first thing every user sees on a fresh chat

After Hi-Service backend looks up phone, if `mode IS NULL` (no active session), bot sends a **menu first** (replaces direct welcome):

```
👋 Welcome to Hi Service Gas

How can we help you today?

*1* — 🛒 Order Gas
*2* — 💬 General Help (chat with our team)

Reply with 1 or 2.

(Type Cancel anytime to end this chat)
```

**User options:**
- `1` (or "order", "buy", "gas") → set `mode = ordering` → kicks off **flow A**
- `2` (or "help", "question", "info") → set `mode = general_help` → forwards next message + all subsequent messages to GHL AI bot
- garbage → re-shows menu

### B1. General Help mode (active)

While `mode = general_help`, every inbound WhatsApp message is forwarded to GHL.
GHL AI bot handles:
- FAQ (services, pricing, delivery zones, hours)
- Calendar booking (appointment requests)
- Escalation to human admin (when AI can't answer)

GHL workflow shape:
```
Inbound Webhook (from Hi-Service backend)
        │
        ▼
   Find or Create Contact (by phone)
        │
        ▼
   Add inbound message to Conversation
        │
        ▼
   AI Bot Action (responds, books, escalates)
        │
        ▼
   Outbound Webhook (POST to Hi-Service backend with bot's reply)
```

Hi-Service backend receives the GHL webhook → sends the reply via WhatsApp.

### B2. Returning to ordering from general help

User can type `menu` / `0` / `back` from anywhere in flow B → resets to mode = NULL → menu shown.

### B3. Admin escalation (when AI flags)

GHL workflow can:
- Send an internal notification to a GHL user
- Trigger a Slack message via GHL's Slack integration
- Send the contact + last 5 messages to a phone via SMS

Hi-Service backend doesn't need to know — GHL handles internally.

---

## C. Setup plan (from scratch — nothing exists yet)

You confirmed: nothing is wired up on the new side. The current bot lives on `imappliedseo.com` n8n. Here's the build order.

### C1. Infrastructure prerequisites

Before any code, you (or I, with credentials) need:

1. **Subdomain** — `orders.hi-servicegas.co.za` pointed at hivecliqapps cPanel (or hi-servicegas's own hosting)
2. **SSL** — AutoSSL via cPanel (free)
3. **Database** — new MySQL DB on the same cPanel: `hiservicegas_main`
4. **Meta WhatsApp Business app** — a NEW app (separate from imappliedseo's) in business.facebook.com:
   - Phone Number: 063 693 5532 (or whichever number Hi-Service owns)
   - System User Token (permanent, scoped to this WABA)
   - Phone Number ID
   - WhatsApp Business Account ID
   - Webhook verify token (we generate)
5. **GHL sub-account** `dzu95Ecw2Cq2nFzf3a0G`:
   - Private Integration Token (scopes: Conversations, Contacts, Calendars, Workflows, Custom Fields, Locations)
   - One Calendar created for "General Help / Bookings"
   - One Conversation AI Bot created and trained on:
     - Hi-Service company info (areas served, hours, contact)
     - Services + pricing
     - FAQ doc (we can draft together)
6. **Xero** — connect Hi-Service's Xero org to a new OAuth app:
   - Client ID + Client Secret
   - Refresh token (one-time auth)
   - Tenant ID (Hi-Service's Xero org)
7. **PayFast LIVE** — get the actual production credentials from Hi-Service:
   - Merchant ID
   - Merchant Key
   - Passphrase

### C2. Build order (who does what)

```
DAY 1-2  | Backend skeleton: PHP files + DB schema + admin login
         | Tables: customers, products, slots, orders, order_lines,
         |         conversations (full message log), sessions
         |
DAY 3-4  | State machine + intent detector ported from JS to PHP
         | PHPUnit tests — every state transition covered
         |
DAY 5    | WhatsApp gateway (Meta Cloud API send + webhook verify)
         | One real message round-trip tested
         |
DAY 6    | Xero contact + invoice helpers
         |
DAY 7    | PayFast pay link + ITN verify (PHP md5() = trivial)
         |
DAY 8    | GHL bridge: forward to GHL AI + receive outbound webhook
         |
DAY 9-10 | Web ordering UI on orders.hi-servicegas.co.za
         |   - Browse products
         |   - Address picker
         |   - Slot picker (same data source as WhatsApp)
         |   - Pay → PayFast → confirmation
         |
DAY 11-12| Admin panel:
         |   - Live orders dashboard
         |   - Live conversations (read every chat)
         |   - "Pause AI / take over" button per conversation
         |   - Slot calendar editor
         |   - Product catalogue editor (vs. Google Sheet today)
         |
DAY 13   | End-to-end test: real WhatsApp number, real PayFast, real Xero
         |
DAY 14   | Cutover: switch Meta WhatsApp webhook to new backend
         | Old n8n on imappliedseo.com stays standby for 7 days
```

### C3. What you (Shawn) need to do upfront

| Task | Owner | Blocks |
|---|---|---|
| Add subdomain to cPanel | You | Day 1 |
| Generate GHL Private Integration Token | You | Day 8 |
| Create GHL AI Bot + Calendar | You (I'll guide) | Day 8 |
| Pull Xero credentials from Hi-Service | You | Day 6 |
| Pull PayFast LIVE creds from Hi-Service | You | Day 7 |
| Create Meta WhatsApp Cloud API app | You (I'll guide) | Day 5 |
| Provide existing FAQ / company info doc for AI bot training | You | Day 8 |

### C4. Decisions still needed

1. **AI Bot scope** — what should the GHL AI bot KNOW? (areas served, pricing, delivery hours, holidays, refund policy, common questions)
2. **AI Bot escape** — when AI says "I'll get someone to help" — who? (Slack channel? GHL user notification? SMS to a phone?)
3. **Calendar use** — what kinds of bookings? (consultation calls? installation appointments? the AI books WHAT exactly?)
4. **Web ordering UI design** — match Hi-Service brand colours/logo (do they have a website + brand kit we can pull from?)
5. **Multi-tenant later?** — is this Hi-Service-only forever, or do you want this to be reusable for other clients (i.e. "Service Gas Bot SaaS")?

---

## Summary

- The current n8n bot has a **complete, working conversation script** — I traced every message above
- There's only **one path** today (gas ordering) — adding the GHL bridge means **adding a menu intercept** at the start
- **Nothing is built** on the new side — full setup as listed in C2
- **First thing** I need from you: the credentials in C3 + answers in C4, then I cut Day 1 code

Tell me when ready. I'll start with the cPanel + DB + skeleton (Day 1-2) which doesn't need GHL/Meta/Xero/PayFast yet — those come in stages.
