<?php
/**
 * conversation.php — WhatsApp ordering conversation orchestrator.
 *
 * The brain that sits between the webhook (api/webhook/whatsapp.php) and
 * the state machine + repos. One public function: handle($phone, $text).
 *
 *   1. Load/create session for this phone
 *   2. Detect intent via IntentDetector (regex + Claude fallback)
 *   3. Transition state via StateMachine
 *   4. Execute the resulting action (the big switch below)
 *   5. Persist new state to sessions table
 *   6. Return the response text to send back via notify_send_text()
 *
 * Ported behaviour from the old n8n 122-node flow:
 *   greeting → check customer → (returning: confirm address)
 *                              (new: collect details → confirm)
 *           → product browse → enter codes (B2 D1) → confirm order
 *           → slot pick → confirm address → P for payment → PayFast link
 *
 * Falls back to "clarify" with the FSM's response_template whenever
 * intent is unclear. Cancel/menu work from any state.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/state_machine.php';
require_once __DIR__ . '/intent_detector.php';
require_once __DIR__ . '/customer_repo.php';
require_once __DIR__ . '/product_repo.php';
require_once __DIR__ . '/slot_repo.php';
require_once __DIR__ . '/order_repo.php';
require_once __DIR__ . '/payfast.php';

final class Conversation
{
    /**
     * Main entry point. Returns the reply text the webhook should send.
     *
     *   $phone  Normalised digits-only phone (e.g. "27834603414")
     *   $text   Raw inbound message
     *   $provider 'meta' | 'twilio' (used for logging only — reply still via provider)
     */
    public static function handle(string $phone, string $text, string $provider = 'twilio'): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone === '' || $phone === null) return '';

        $session = self::loadOrCreateSession($phone);
        $currentStep = (string) ($session['current_step'] ?: StateMachine::S_MENU);
        $stateData   = self::decodeJson($session['state_data'] ?? null) ?: [];

        // ─── Pre-intent interceptors — fix common dead-ends ───
        $norm = strtolower(trim($text));

        // (a) Bare greetings ALWAYS reset to menu, never get interpreted as 'general_help'.
        //     Catches "hi", "hello", "hey", "hola", "yo", "morning" etc. before Claude fallback misreads them.
        if (in_array($norm, ['hi','hello','hey','hola','yo','sup','morning','afternoon','evening','hi there','hey there','hello there'], true)) {
            $currentStep = StateMachine::S_MENU;
            $stateData = [];
            self::saveSession($phone, StateMachine::S_MENU, [], $session, true);
            return self::tplMenu();
        }

        // (b) ANY input while stuck in general_help / cancelled / terminal states → kick back to menu.
        //     general_help has no exit transitions in the FSM — without this, users get stuck forever.
        if (in_array($currentStep, [StateMachine::S_GENERAL_HELP, StateMachine::S_CANCELLED], true)) {
            $currentStep = StateMachine::S_MENU;
            $stateData = [];
            self::saveSession($phone, StateMachine::S_MENU, [], $session, true);
            // Re-load the session so subsequent code sees the reset
            $session = self::loadOrCreateSession($phone);
        }

        $intent  = IntentDetector::detect($text, $currentStep, $stateData);
        $trans   = StateMachine::transition($currentStep, $intent['intent']);

        log_event('conversation.transition', null, $phone, [
            'from'   => $currentStep,
            'intent' => $intent['intent'],
            'to'     => $trans['next_step'],
            'action' => $trans['action'],
        ]);

        // Execute the action — may modify $stateData + may override $trans['next_step']
        $reply = self::executeAction(
            $trans['action'],
            $trans['response_template'],
            $phone,
            $text,
            $intent,
            $session,
            $stateData,
            $trans,                    // pass by ref so action can override next_step
        );

        // Persist updated session
        self::saveSession($phone, $trans['next_step'], $stateData, $session, $trans['should_clear'] ?? false);

        return $reply;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Action dispatcher
    // ═══════════════════════════════════════════════════════════════════════
    private static function executeAction(
        string $action,
        ?string $template,
        string $phone,
        string $text,
        array $intent,
        array $session,
        array &$stateData,
        array &$trans,
    ): string {
        switch ($action) {

            case 'show_menu':
                return self::tplMenu();

            case 'clarify':
                return $template ?: self::tplMenu();

            case 'cancel_and_clear':
                $stateData = [];
                $trans['next_step'] = StateMachine::S_MENU;
                return ($template ?: 'Order cancelled. Reply 1 to start a new order anytime. 👋') .
                       "\n\n" . self::tplMenu();

            case 'check_customer':
                return self::actCheckCustomer($phone, $session, $stateData, $trans);

            case 'confirm_current_address':
                return self::actConfirmCurrentAddress($phone, $session, $stateData, $trans);

            case 'get_product_catalog':
                return self::actShowProductCatalogue($stateData);

            case 'collecting_order_details':
                return self::actCollectOrderDetails($text, $phone, $session, $stateData, $trans);

            case 'check_delivery_slots':
                return self::actShowSlots($stateData, $trans);

            case 'ask_for_address':
                return $template ?: "What's your new delivery address?";

            case 'awaiting_new_address':
                return self::actCaptureNewAddress($text, $phone, $stateData, $trans);

            case 'book_delivery_slot':
                return self::actBookSelectedSlot($text, $session, $stateData, $trans);

            case 'process_payment':
                return self::actProcessPayment($phone, $session, $stateData, $trans);

            case 'awaiting_new_customer_details':
                return self::actCaptureNewCustomer($text, $phone, $stateData, $trans);

            case 'awaiting_street_code':
                return self::actCaptureStreetCode($text, $phone, $stateData, $trans);

            case 'bridge_to_ghl':
                return self::tplGeneralHelp();

            default:
                // Unknown action — surface the template if present, else menu
                return $template ?: self::tplMenu();
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Action implementations
    // ═══════════════════════════════════════════════════════════════════════

    private static function actCheckCustomer(string $phone, array $session, array &$stateData, array &$trans): string
    {
        $cust = CustomerRepo::findByPhone($phone);

        if (!$cust) {
            $trans['next_step'] = StateMachine::S_AWAITING_STREET_CODE;
            return "👋 Welcome to Hi-Service Gas!\n\nLooks like this is your first time ordering — let's get you set up.\n\nFirst, what's your *4-digit postal code*? (so we can check we deliver to your area)\n\n*Example:* 7140";
        }

        $stateData['customer_id'] = (int) $cust['id'];
        $firstName = self::firstName((string) ($cust['full_name'] ?? ''));
        $lastOrder = self::lastOrderFor((int) $cust['id']);

        if ($lastOrder) {
            $trans['next_step'] = StateMachine::S_AWAITING_ORDER_CHOICE;
            $summary = self::summariseOrder($lastOrder);
            return "Welcome back" . ($firstName ? ", {$firstName}" : '') . "! 👋\n\nYour last order:\n{$summary}\n\n*Reply with:*\n*1* - to repeat this order\n*2* - to place a different order";
        }

        $trans['next_step'] = StateMachine::S_SHOWING_PRODUCTS;
        return "Welcome back" . ($firstName ? ", {$firstName}" : '') . "! 👋\n\n" . self::tplProductCatalogue();
    }

    private static function actConfirmCurrentAddress(string $phone, array $session, array &$stateData, array &$trans): string
    {
        $custId = (int) ($stateData['customer_id'] ?? $session['customer_id'] ?? 0);
        if (!$custId) {
            $trans['next_step'] = StateMachine::S_MENU;
            return "Something went wrong — let's start over.\n\n" . self::tplMenu();
        }

        // ─── Repeat-order auto-load ───
        // If the cart is empty BUT the customer has a previous paid order, copy its lines
        // into $stateData['cart']. This handles the "1 = repeat last order" flow — the
        // FSM jumps straight here from S_AWAITING_ORDER_CHOICE without going through
        // product selection, so the cart needs to come from history. Without this, the
        // payment step would send R 0.00 to PayFast and crash with a 400.
        if (empty($stateData['cart']) || (float) ($stateData['cart_total'] ?? 0) <= 0) {
            $last = self::lastOrderFor($custId);
            if ($last) {
                $lines = OrderRepo::linesFor((int) $last['id']);
                if (!empty($lines)) {
                    $cart  = [];
                    $total = 0.0;
                    foreach ($lines as $l) {
                        $cart[] = [
                            'product_id'   => (int) ($l['product_id']   ?? 0),
                            'product_name' => (string) ($l['product_name'] ?? 'Item'),
                            'qty'          => (int) ($l['qty']          ?? $l['quantity'] ?? 1),
                            'unit_price'   => (float) ($l['unit_price'] ?? 0),
                            'line_total'   => (float) ($l['line_total'] ?? 0),
                        ];
                        $total += (float) ($l['line_total'] ?? 0);
                    }
                    $stateData['cart']       = $cart;
                    $stateData['cart_total'] = $total;
                    log_event('conversation.repeat_order_loaded', null, $phone, [
                        'source_order' => $last['id'],
                        'line_count'   => count($cart),
                        'total'        => $total,
                    ]);
                }
            }
        }

        $addr = CustomerRepo::defaultAddress($custId);
        if (!$addr) {
            $trans['next_step'] = StateMachine::S_AWAITING_NEW_ADDRESS;
            return "I don't have a delivery address on file. What's your address?\n\nPlease include each on its own line:\n*Street + number*\n*Suburb*\n*City*\n*4-digit postal code*";
        }

        $stateData['address_id'] = (int) $addr['id'];
        $addrLine = self::formatAddress($addr);

        // Add cart preview if this is a repeat — gives user a final visual confirm of what they're paying for
        $cartPreview = '';
        if (!empty($stateData['cart'])) {
            $cartPreview = "\n\n*Repeating your order:*\n";
            foreach ($stateData['cart'] as $line) {
                $cartPreview .= "• {$line['qty']} × {$line['product_name']} — R " . number_format($line['line_total'], 2) . "\n";
            }
            $cartPreview .= "*Total: R " . number_format((float) $stateData['cart_total'], 2) . "*\n";
        }

        return "Delivering to:\n📍 *{$addrLine}*{$cartPreview}\n\n*Reply with:*\n*S* / *Y* - same address\n*D* / *N* - different address";
    }

    /** Format an addresses-table row as a one-line readable string. */
    private static function formatAddress(array $addr): string
    {
        $parts = [];
        foreach (['line1', 'line2', 'city'] as $k) {
            $v = trim((string) ($addr[$k] ?? ''));
            if ($v !== '') $parts[] = $v;
        }
        $line = implode(', ', $parts);
        $postal = trim((string) ($addr['postal_code'] ?? ''));
        if ($postal !== '') $line = ($line === '' ? $postal : $line . ' ' . $postal);
        return $line !== '' ? $line : '(incomplete address on file — please send a new one)';
    }

    private static function actShowProductCatalogue(array &$stateData): string
    {
        return self::tplProductCatalogue();
    }

    private static function actCollectOrderDetails(string $text, string $phone, array $session, array &$stateData, array &$trans): string
    {
        // Parse tokens like "B2 D1" → [B=>2, D=>1]
        $tokens = [];
        if (preg_match_all('/([A-Za-z])\s*(\d+)/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $tokens[strtoupper($match[1])] = (int) $match[2];
            }
        }

        if (empty($tokens)) {
            return "I didn't catch that. Reply with the letter followed by quantity.\n\n*Example:* B2  (= 2 × 5kg LPG)\n\n" . self::tplProductCatalogue();
        }

        $resolved = ProductRepo::resolveTokens($tokens);
        if (empty($resolved['lines'])) {
            return "None of those product codes matched. Please reply with codes from this list:\n\n" . self::tplProductCatalogue();
        }

        $stateData['cart'] = $resolved['lines'];
        $stateData['cart_total'] = $resolved['total'];

        $summary = "*Your order:*\n";
        foreach ($resolved['lines'] as $line) {
            $summary .= "• {$line['qty']} × {$line['product_name']} — R " . number_format($line['line_total'], 2) . "\n";
        }
        $summary .= "\n*Total: R " . number_format($resolved['total'], 2) . "*";

        // Confirmed → next is address choice
        $trans['next_step'] = StateMachine::S_AWAITING_ADDRESS_CHOICE;

        return "{$summary}\n\nGreat! Now let's confirm delivery.\n\n" . self::actConfirmCurrentAddress($phone, $session, $stateData, $trans);
    }

    private static function actShowSlots(array &$stateData, array &$trans): string
    {
        // Auto-seed slots for the next 14 days if the table has nothing upcoming.
        // ensureNextNDays is idempotent — re-running just no-ops if already present.
        try { SlotRepo::ensureNextNDays(14); } catch (Throwable $e) { /* non-fatal */ }

        // SlotRepo::availableSlots() returns wrapped rows: [['letter'=>'A','slot'=>[...row...],'display'=>'...'], ...]
        // NOT flat slot rows. The 'display' string is already computed.
        $wrapped = SlotRepo::availableSlots();

        // Always advance to AWAITING_SLOT_SELECTION so the user's next reply (A/B/date)
        // is recognised by IntentDetector. If we leave state as S_CHECKING_SLOTS the user
        // gets stuck because the FSM has no transitions out of that intermediate state.
        $trans['next_step'] = StateMachine::S_AWAITING_SLOT_SELECTION;

        if (empty($wrapped)) {
            return "Hmm, I can't see any open delivery slots in the next 7 days.\n\n" .
                   "You can:\n" .
                   "• Type a specific date (e.g. *28/05/2026*) and I'll check that day\n" .
                   "• Call us on *021 492 8515* to book directly";
        }

        // Keep only the first 2 for the A/B prompt
        $shown = array_slice($wrapped, 0, 2);
        $stateData['slot_options'] = array_map(fn($w) => (int) ($w['slot']['id'] ?? 0), $shown);

        $msg = "*Choose your delivery slot:*\n\n";
        $labels = ['A', 'B'];
        foreach ($shown as $idx => $w) {
            $msg .= "*{$labels[$idx]}* - " . ($w['display'] ?? '(slot)') . "\n";
        }
        $msg .= "\nOr type a date (e.g. *28/05/2026*) for a different day.";

        return $msg;
    }

    private static function actBookSelectedSlot(string $text, array $session, array &$stateData, array &$trans): string
    {
        $msg = strtoupper(trim($text));
        $options = $stateData['slot_options'] ?? [];
        $slotId = null;

        if ($msg === 'A' || $msg === '1') $slotId = $options[0] ?? null;
        if ($msg === 'B' || $msg === '2') $slotId = $options[1] ?? null;

        if (!$slotId) {
            // Try date parsing
            $iso = self::parseDate($text);
            if ($iso) {
                $closest = SlotRepo::closestTo($iso);
                if ($closest) $slotId = (int) $closest['id'];
            }
        }

        if (!$slotId) {
            return "Please reply *A* or *B*, or type a date (e.g. 23/02/2026).";
        }

        $stateData['slot_id'] = (int) $slotId;
        $slot = SlotRepo::findById((int) $slotId);
        $label = $slot ? SlotRepo::displayLabel($slot) : 'your selected slot';

        $trans['next_step'] = StateMachine::S_AWAITING_PAYMENT_CONFIRMATION;

        $total = number_format((float) ($stateData['cart_total'] ?? 0), 2);

        return "✅ Slot reserved: *{$label}*\n\n*Order total: R {$total}*\n\n*Reply with:*\n*P* - to continue to payment\n*D* - to place a different order\n*Cancel* - to cancel this order";
    }

    private static function actProcessPayment(string $phone, array $session, array &$stateData, array &$trans): string
    {
        $custId = (int) ($stateData['customer_id'] ?? $session['customer_id'] ?? 0);
        if (!$custId) {
            $trans['next_step'] = StateMachine::S_MENU;
            return "I lost track of who you are — let's start over.\n\n" . self::tplMenu();
        }

        // Defensive: never send R 0 to PayFast. If cart is somehow empty at this point
        // (lost session, race condition, etc.) route the user back to products instead
        // of crashing PayFast with "Amount must be a valid payment amount".
        $cart      = $stateData['cart']       ?? [];
        $cartTotal = (float) ($stateData['cart_total'] ?? 0);
        if (empty($cart) || $cartTotal <= 0) {
            $trans['next_step'] = StateMachine::S_SHOWING_PRODUCTS;
            log_to_file('whatsapp', 'payment aborted — empty cart', ['phone' => $phone, 'state' => $stateData]);
            return "Hmm, your cart is empty — let's pick your products.\n\n" . self::tplProductCatalogue();
        }

        try {
            // Create cart-style order, attach lines, address, slot
            $orderId = OrderRepo::createCart($custId, 'whatsapp', false);
            $stateData['current_order_id'] = $orderId;

            OrderRepo::replaceLines($orderId, $stateData['cart'] ?? []);

            if (!empty($stateData['address_id'])) {
                OrderRepo::setAddress($orderId, (int) $stateData['address_id']);
            }
            if (!empty($stateData['slot_id'])) {
                OrderRepo::setSlot($orderId, (int) $stateData['slot_id']);
            }
            OrderRepo::setStatus($orderId, 'pending_payment');

            $order = OrderRepo::findById($orderId);
            $cust  = CustomerRepo::findById($custId) ?: [];

            // PayFast helper expects an explicit dict with these exact keys —
            // it does NOT read directly from the orders DB row. Mirroring the
            // shape used by shop/pay.php.
            $payLink = payfast_build_pay_link([
                'order_reference' => (string) ($order['order_reference'] ?? ''),
                'order_total'     => (float)  ($order['total_amount']   ?? $stateData['cart_total'] ?? 0),
                'customer_name'   => trim((string) ($cust['full_name'] ?? '')),
                'customer_email'  => (string) ($cust['email'] ?? ''),
                'customer_phone'  => (string) ($cust['phone'] ?? $phone),
            ]);

            $ref   = (string) ($order['order_reference'] ?? '');
            $total = number_format((float) ($order['total_amount'] ?? $stateData['cart_total'] ?? 0), 2);

            log_event('whatsapp.payment.link_sent', null, $phone, [
                'order_id' => $orderId, 'ref' => $ref, 'amount' => $total,
            ]);

            return "💳 *Pay securely:*\n{$payLink}\n\nOrder ref: *{$ref}*\nTotal: *R {$total}*\n\nOnce paid, you'll get a confirmation here. 🙌";
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'payment link generation failed', ['err' => $e->getMessage(), 'phone' => $phone]);
            return "Something went wrong building your payment link. Please reply *Cancel* and try again, or call 021 492 8515.";
        }
    }

    private static function actCaptureNewCustomer(string $text, string $phone, array &$stateData, array &$trans): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
        if (count($lines) < 2) {
            return "I need at least your name and address. Please send them on separate lines:\n\nJohn Doe\n31 Example Rd, Strand, Cape Town, 7140\njohn@example.com";
        }

        $fullName    = trim($lines[0] ?? '');
        $addressLine = trim($lines[1] ?? '');
        $email       = trim($lines[2] ?? '');

        try {
            $custId = CustomerRepo::create([
                'phone'     => $phone,
                'full_name' => $fullName,
                'email'     => $email,
            ]);

            // Split the comma-separated address line into the real schema columns
            $parts      = array_map('trim', explode(',', $addressLine));
            $line1      = $parts[0] ?? '';
            $line2      = $parts[1] ?? '';
            $city       = $parts[2] ?? '';
            $postalCode = $parts[3] ?? ($stateData['postal_code'] ?? '');

            $addrId = CustomerRepo::addAddress($custId, [
                'line1'       => $line1,
                'line2'       => $line2,
                'city'        => $city,
                'postal_code' => $postalCode,
                'is_default'  => 1,
            ]);

            $stateData['customer_id'] = $custId;
            $stateData['address_id']  = $addrId;
            $trans['next_step'] = StateMachine::S_SHOWING_PRODUCTS;

            $firstName = self::firstName($fullName);
            return "Thanks" . ($firstName ? " {$firstName}" : '') . "! You're all set up. ✅\n\n" . self::tplProductCatalogue();
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'new customer create failed', ['err' => $e->getMessage()]);
            return "Something went wrong saving your details. Please try again, or call 021 492 8515.";
        }
    }

    private static function actCaptureStreetCode(string $text, string $phone, array &$stateData, array &$trans): string
    {
        if (!preg_match('/(\d{4})/', $text, $m)) {
            return "Please send your 4-digit postal code.\n\n*Example:* 7140";
        }
        $postal = $m[1];
        $stateData['postal_code'] = $postal;

        // Check if we deliver to that postal code
        $inZone = CustomerRepo::postalCodeInZone($postal);
        if (!$inZone) {
            $trans['next_step'] = StateMachine::S_MENU;
            $stateData = [];
            return "Sorry, we don't deliver to *{$postal}* yet. 😞\n\nOur current coverage: Helderberg, Stellenbosch, Overberg.\nFull list: https://hiservice.store/shop/areas.php\n\nReply *MENU* if you want to start over.";
        }

        $trans['next_step'] = StateMachine::S_AWAITING_NEW_CUSTOMER_DETAILS;
        return "Great — we deliver to *{$postal}*! ✅\n\nNow please send your details, one per line:\n\n*1.* Name and surname\n*2.* Street address, suburb, city\n*3.* Email (optional)\n\n*Example:*\nJames Elliot\n31 Example Road, Strand, Cape Town\njames@gmail.com";
    }

    private static function actCaptureNewAddress(string $text, string $phone, array &$stateData, array &$trans): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
        if (count($lines) < 2) {
            return "Please send your address on separate lines:\n\n31 Example Rd\nStrand\nCape Town\n7140";
        }

        $custId = (int) ($stateData['customer_id'] ?? 0);
        if (!$custId) {
            $trans['next_step'] = StateMachine::S_MENU;
            return "I lost track of who you are — let's start over.\n\n" . self::tplMenu();
        }

        try {
            $addrId = CustomerRepo::addAddress($custId, [
                'line1'       => $lines[0] ?? '',
                'line2'       => $lines[1] ?? '',
                'city'        => $lines[2] ?? '',
                'postal_code' => $lines[3] ?? '',
                'is_default'  => 0,
            ]);
            $stateData['address_id'] = $addrId;
            $trans['next_step'] = StateMachine::S_CHECKING_SLOTS;
            return "Got it. Checking delivery times...\n\n" . self::actShowSlots($stateData);
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'address save failed', ['err' => $e->getMessage()]);
            return "Couldn't save that address. Please try again, or call 021 492 8515.";
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Templates
    // ═══════════════════════════════════════════════════════════════════════

    private static function tplMenu(): string
    {
        return "*Hi-Service Gas* 🔥\n\nHow can we help today?\n\n*1* - Order LPG gas\n*2* - General help / questions\n\nReply with the number above.";
    }

    private static function tplGeneralHelp(): string
    {
        return "Sure — happy to help. What's your question?\n\n(Or call 021 492 8515 to speak to someone.)";
    }

    private static function tplProductCatalogue(): string
    {
        $items = ProductRepo::letteredCatalogue();
        if (empty($items)) {
            return "Our product catalogue isn't available right now. Please call 021 492 8515.";
        }
        $msg = "*Our gas cylinders:*\n\n";
        foreach ($items as $it) {
            $p = $it['product'] ?? [];
            $name  = $p['name']  ?? '(unnamed)';
            $price = (float) ($p['price'] ?? 0);
            $msg .= "*{$it['letter']}* - {$name} — R " . number_format($price, 2) . "\n";
        }
        $msg .= "\n*Reply with the letter + quantity.*\n*Example:* B2  (= 2 × 5kg LPG)";
        return $msg;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Session + utility helpers
    // ═══════════════════════════════════════════════════════════════════════

    private static function loadOrCreateSession(string $phone): array
    {
        $stmt = db()->prepare("SELECT * FROM sessions WHERE phone = :p AND expires_at > NOW() LIMIT 1");
        $stmt->execute([':p' => $phone]);
        $row = $stmt->fetch();
        if ($row) return $row;

        db()->prepare("INSERT INTO sessions (phone, mode, current_step, state_data) VALUES (:p, 'menu', :s, '{}')")
            ->execute([':p' => $phone, ':s' => StateMachine::S_MENU]);

        return [
            'phone'        => $phone,
            'mode'         => 'menu',
            'current_step' => StateMachine::S_MENU,
            'customer_id'  => null,
            'state_data'   => '{}',
        ];
    }

    private static function saveSession(string $phone, string $nextStep, array $stateData, array $prevSession, bool $clear): void
    {
        if ($clear) {
            db()->prepare("UPDATE sessions SET current_step = :s, customer_id = NULL, current_order_id = NULL, state_data = '{}', expires_at = NOW() + INTERVAL 24 HOUR WHERE phone = :p")
                ->execute([':s' => $nextStep, ':p' => $phone]);
            return;
        }

        db()->prepare(
            "UPDATE sessions
                SET current_step = :s,
                    customer_id  = :c,
                    current_order_id = :o,
                    state_data   = :d,
                    expires_at   = NOW() + INTERVAL 24 HOUR
              WHERE phone = :p"
        )->execute([
            ':s' => $nextStep,
            ':c' => $stateData['customer_id']      ?? $prevSession['customer_id']      ?? null,
            ':o' => $stateData['current_order_id'] ?? $prevSession['current_order_id'] ?? null,
            ':d' => json_encode($stateData, JSON_UNESCAPED_SLASHES),
            ':p' => $phone,
        ]);
    }

    private static function lastOrderFor(int $customerId): ?array
    {
        $stmt = db()->prepare("SELECT * FROM orders WHERE customer_id = :c AND status IN ('paid','delivered') ORDER BY id DESC LIMIT 1");
        $stmt->execute([':c' => $customerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function summariseOrder(array $order): string
    {
        $lines = OrderRepo::linesFor((int) $order['id']);
        $summary = '';
        foreach ($lines as $l) {
            $qty  = $l['qty']          ?? $l['quantity']    ?? '?';
            $name = $l['product_name'] ?? $l['name']        ?? 'Item';
            $summary .= "• {$qty} × {$name}\n";
        }
        if ($summary === '') $summary = "• (line items unavailable)\n";
        $summary .= "Total: R " . number_format((float) ($order['total_amount'] ?? 0), 2);
        return $summary;
    }

    /** Extract a friendly first name from a full name. Returns '' if blank/unset. */
    private static function firstName(string $fullName): string
    {
        $fullName = trim($fullName);
        if ($fullName === '') return '';
        $parts = preg_split('/\s+/', $fullName);
        return ucfirst(strtolower((string) ($parts[0] ?? '')));
    }

    private static function decodeJson($maybe): ?array
    {
        if (!$maybe) return [];
        if (is_array($maybe)) return $maybe;
        $d = json_decode((string) $maybe, true);
        return is_array($d) ? $d : [];
    }

    private static function parseDate(string $text): ?string
    {
        $t = trim(strtolower($text));
        if ($t === 'today')    return date('Y-m-d');
        if ($t === 'tomorrow') return date('Y-m-d', strtotime('+1 day'));

        // DD/MM/YYYY or DD-MM-YYYY
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $t, $m)) {
            $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
        }
        return null;
    }
}
