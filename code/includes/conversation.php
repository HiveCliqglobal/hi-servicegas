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
require_once __DIR__ . '/claude_agent.php';

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

        // Reset the in-flow recovery counter whenever the state actually advances —
        // a successful transition means the previous "unclear" history is no longer
        // relevant. (actSmartClarify already resets on its own happy path; this
        // catches the regular-intent path.)
        if ($trans['next_step'] !== $currentStep) {
            unset($stateData['unclear_count'], $stateData['unclear_state'], $stateData['stuck_alert_sent']);
        }

        // Persist updated session
        self::saveSession($phone, $trans['next_step'], $stateData, $session, $trans['should_clear'] ?? false);

        // Universal exit footer — every in-flow reply gets a single-line reminder
        // that the customer can ALWAYS escape with CANCEL, restart with MENU, or
        // talk to a person with HELP. Skipped on terminal/menu replies so the menu
        // itself isn't double-prompted with its own footer.
        $reply = self::withExitFooter($reply, $trans['next_step']);

        return $reply;
    }

    /**
     * Append the universal CANCEL / MENU / HELP exit footer to mid-flow replies.
     * Skipped on menu / cancelled / general_help / terminal states because those
     * messages already carry their own exit guidance or aren't in-flow.
     */
    private static function withExitFooter(string $reply, string $nextStep): string
    {
        // States where adding the footer would be noisy/redundant
        $skip = [
            StateMachine::S_MENU,
            StateMachine::S_CANCELLED,
            StateMachine::S_GENERAL_HELP,
        ];
        if (in_array($nextStep, $skip, true)) return $reply;

        // Don't double-up if the reply already mentions the exit options
        if (stripos($reply, 'CANCEL') !== false && stripos($reply, 'MENU') !== false) return $reply;
        if (str_contains($reply, '💡')) return $reply;

        return $reply . "\n\n_💡 *CANCEL* exit · *MENU* restart · *HELP* talk to a person_";
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
                // Haiku-powered recovery — see actSmartClarify(). Falls back to the static
                // template if Claude is unavailable. May update $trans['next_step'] if it
                // confidently recovers a real intent the regex missed.
                return self::actSmartClarify($phone, $text, $session, $stateData, $trans, $template);

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

            case 'request_callback_details':
                return self::actRequestCallbackDetails($phone, $stateData, $trans);

            case 'log_callback_lead':
                return self::actLogCallbackLead($text, $phone, $session, $stateData, $trans);

            case 'request_human_help':
                return self::actRequestHumanHelp($phone, $session, $text);

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

        $trans['next_step'] = StateMachine::S_COLLECTING_ORDER_DETAILS;
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

        // ─── Stock validation ───
        // For each tracked line, check products.in_stock_qty against the requested qty.
        // If ANY line has insufficient stock → transition to S_OUT_OF_STOCK and don't
        // commit the cart. Customer gets 3 clear options (try other / callback / cancel).
        $issues = self::checkStock($resolved['lines']);
        if (!empty($issues)) {
            $stateData['out_of_stock_lines'] = $issues;
            $stateData['pending_cart']       = $resolved['lines'];  // remember in case they retry
            $trans['next_step'] = StateMachine::S_OUT_OF_STOCK;
            log_event('whatsapp.order.out_of_stock', null, $phone, [
                'requested' => $tokens, 'shortfalls' => $issues,
            ]);

            $msg = "😕 *Sorry — limited stock on your order:*\n\n";
            foreach ($issues as $i) {
                $msg .= "• {$i['product_name']} — you asked for *{$i['requested']}*, we currently have *{$i['available']}*\n";
            }
            $msg .= "\n*What would you like to do?*\n";
            $msg .= "*A* - try a different product\n";
            $msg .= "*B* - leave your name, we'll call as soon as it's back in stock\n";
            $msg .= "*Cancel* - drop this order";
            return $msg;
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
        $slot  = SlotRepo::findById((int) $slotId);
        $label = $slot ? SlotRepo::displayLabel($slot) : 'your selected slot';

        // Park at slot-confirm so the user gets an explicit readback before we
        // generate the PayFast link. The total goes IN this message so we don't
        // need a second payment-preview screen afterwards.
        $trans['next_step'] = StateMachine::S_AWAITING_SLOT_CONFIRM;

        $total = number_format((float) ($stateData['cart_total'] ?? 0), 2);

        // Build a compact cart summary so the customer sees exactly what they're
        // paying for one last time before the PayFast hand-off.
        $cartSummary = '';
        foreach (($stateData['cart'] ?? []) as $line) {
            $cartSummary .= "• {$line['qty']} × {$line['product_name']}\n";
        }
        if ($cartSummary === '') $cartSummary = "• (no items)\n";

        return "You've chosen:\n🗓 *{$label}*\n\n*Your order:*\n{$cartSummary}\n*Total: R {$total}*\n\n*Reply with:*\n*Y* - Confirm slot and pay\n*N* - Pick a different time";
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
            $trans['next_step'] = StateMachine::S_COLLECTING_ORDER_DETAILS;
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
            $trans['next_step'] = StateMachine::S_COLLECTING_ORDER_DETAILS;

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

        // Validate postal code BEFORE saving — same rule the web shop applies,
        // so we don't accept addresses outside the delivery zone.
        $postal = trim((string) ($lines[3] ?? ''));
        if (!preg_match('/^\d{4}$/', $postal)) {
            return "I need a 4-digit postal code on the last line.\n\n*Example:*\n31 Example Rd\nStrand\nCape Town\n7140";
        }
        if (!CustomerRepo::postalCodeInZone($postal)) {
            return "Sorry, *{$postal}* is outside our delivery area. Please send a different address, or call 021 492 8515 to check.";
        }

        try {
            $line1 = trim((string) ($lines[0] ?? ''));
            $line2 = trim((string) ($lines[1] ?? '')) ?: null;
            $city  = trim((string) ($lines[2] ?? ''));

            $addrId = CustomerRepo::addAddress($custId, [
                'line1'       => $line1,
                'line2'       => $line2,
                'city'        => $city,
                'postal_code' => $postal,
                'is_default'  => 1, // promote the new one to default so subsequent orders use it
            ]);
            $stateData['address_id'] = $addrId;
            log_event('whatsapp.address.saved', 'customer', (string) $custId, ['address_id' => $addrId, 'postal' => $postal]);

            // Stop here so the user gets to confirm the captured address before we
            // move them to slot selection. S_AWAITING_ADDRESS_CONFIRM handles Y/N.
            $trans['next_step'] = StateMachine::S_AWAITING_ADDRESS_CONFIRM;

            $readback = "📍 *" . $line1 . "*";
            if ($line2) $readback .= "\n   " . $line2;
            if ($city !== '') $readback .= "\n   " . $city;
            $readback .= "\n   " . $postal;

            return "Got it. We have your delivery address as:\n\n" . $readback .
                   "\n\n*Reply with:*\n*Y* - Address is correct, continue\n*N* - I need to fix it";
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'address save failed', ['err' => $e->getMessage(), 'cust' => $custId, 'phone' => $phone]);
            return "Couldn't save that address. Please try again, or call 021 492 8515.";
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Templates
    // ═══════════════════════════════════════════════════════════════════════

    private static function tplMenu(): string
    {
        return "*Hi-Service Gas* 🔥\n\nHow can we help today?\n\n*1* - Order LPG gas\n*2* - General help / questions\n\nReply with the number above.\n\n_💡 Tip: Type *CANCEL* anytime to exit, or *MENU* to come back here._";
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
        $msg .= "\n\n_💡 Type *CANCEL* to exit, or *MENU* to start over._";
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

    // ═══════════════════════════════════════════════════════════════════════
    //  Stock check + out-of-stock recovery actions
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Thin wrapper over the shared stock-check helper. Locked to ProductRepo
     * so the WhatsApp + web shop use identical rules.
     * @see ProductRepo::checkCartStock()
     */
    private static function checkStock(array $cartLines): array
    {
        return ProductRepo::checkCartStock($cartLines);
    }

    /**
     * Global HELP intent — fires when customer types "help me" / "talk to a person"
     * at ANY state. Pings admin via GHL (Telegram will plug in here later) WITHOUT
     * resetting the customer's state — so the human picks up the conversation thread
     * exactly where they were.
     */
    private static function actRequestHumanHelp(string $phone, array $session, string $text): string
    {
        $currentStep = (string) ($session['current_step'] ?: StateMachine::S_MENU);
        try {
            $custId = (int) ($session['customer_id'] ?? 0);
            if (!$custId) {
                $existing = CustomerRepo::findByPhone($phone);
                if ($existing) $custId = (int) $existing['id'];
            }
            log_event('conversation.help_requested', 'customer', $custId ? (string) $custId : null, [
                'phone'        => $phone,
                'state'        => $currentStep,
                'last_message' => substr($text, 0, 200),
            ]);
            if ($custId) {
                require_once __DIR__ . '/ghl.php';
                $cust = CustomerRepo::findById($custId);
                $gid  = GHL::syncCustomer($cust);
                if ($gid) {
                    GHL::addTag($gid, ['whatsapp-help-requested', 'needs-human-pickup']);
                    $name = trim((string) ($cust['full_name'] ?? '')) ?: $phone;
                    GHL::notifyUser(
                        GHL::USER_GAS,
                        "Customer asked to speak to a person",
                        "Customer: *{$name}* ({$phone})\n" .
                        "Was at step: *{$currentStep}*\n" .
                        "Their message: \"{$text}\"\n\n" .
                        "Reach out via WhatsApp — their conversation is paused at that step, anything you reply picks up the thread."
                    );
                }
            }
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'help-request notify failed', ['err' => $e->getMessage()]);
        }

        return "Got it — I'm letting our team know you'd like to speak to someone. 👍\n\n" .
               "They'll WhatsApp or call you on this number shortly.\n\n" .
               "Office hours: *Mon-Fri 08:00-17:00 · Sat 08:00-13:00*\n" .
               "Or call us directly: *021 492 8515*";
    }

    private static function actRequestCallbackDetails(string $phone, array &$stateData, array &$trans): string
    {
        $trans['next_step'] = StateMachine::S_AWAITING_CALLBACK_DETAILS;
        return "👍 Got it — we'll get back to you as soon as stock arrives.\n\n" .
               "Please send me your *name* so the team knows who to call:\n\n" .
               "*Example:*\nShawn Lochner\n\n" .
               "Or type *Cancel* to drop this.";
    }

    private static function actLogCallbackLead(string $text, string $phone, array $session, array &$stateData, array &$trans): string
    {
        $name = trim($text);
        if ($name === '' || mb_strlen($name) < 2) {
            return "I need at least your name (just the first name is fine). Please send it and our team will call you back.";
        }

        $oosLines = $stateData['out_of_stock_lines'] ?? [];
        $summaryLines = [];
        foreach ($oosLines as $l) {
            $summaryLines[] = "{$l['requested']} × {$l['product_name']} (only {$l['available']} in stock)";
        }
        $oosSummary = implode("\n  - ", $summaryLines) ?: '(no items captured)';

        // Try to find an existing customer record by phone, OR create one for the lead
        $custId = (int) ($stateData['customer_id'] ?? $session['customer_id'] ?? 0);
        try {
            if (!$custId) {
                $existing = CustomerRepo::findByPhone($phone);
                if ($existing) {
                    $custId = (int) $existing['id'];
                    // Update name if blank
                    if (empty($existing['full_name']) || $existing['full_name'] === null) {
                        CustomerRepo::update($custId, ['full_name' => $name]);
                    }
                } else {
                    $custId = CustomerRepo::create([
                        'phone'     => $phone,
                        'full_name' => $name,
                        'email'     => '',
                    ]);
                }
            }
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'callback lead — customer create failed', ['err' => $e->getMessage()]);
        }

        // Log + notify GHL (Telegram alert wires in here later — already in spec)
        log_event('whatsapp.lead.callback_requested', 'customer', $custId ? (string) $custId : null, [
            'phone' => $phone, 'name' => $name, 'out_of_stock' => $oosLines,
        ]);

        try {
            if ($custId) {
                require_once __DIR__ . '/ghl.php';
                $cust = CustomerRepo::findById($custId);
                $gid  = GHL::syncCustomer($cust);
                if ($gid) {
                    GHL::addTag($gid, ['callback-requested', 'out-of-stock']);
                    GHL::notifyUser(
                        GHL::USER_GAS,
                        "Callback requested — stock issue",
                        "Customer: *{$name}* ({$phone})\n\nWanted but out of stock:\n  - {$oosSummary}\n\nReach out as soon as stock lands."
                    );
                }
            }
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'callback lead — GHL notify failed', ['err' => $e->getMessage()]);
        }

        // Clear the order context — customer is now in lead state, not order state
        $stateData = ['customer_id' => $custId];
        $trans['next_step'] = StateMachine::S_MENU;
        $trans['should_clear'] = false;  // we WANT customer_id to stick

        return "✅ Thanks, *{$name}* — our team has been notified.\n\n" .
               "We'll WhatsApp or call you on this number ({$phone}) as soon as stock arrives.\n\n" .
               "In the meantime if there's anything else, just type *MENU* and I'll help.";
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Smart clarify — Haiku-powered in-flow recovery
    //  Fires when IntentDetector regex returned 'unclear' for the current state.
    //  Three jobs:
    //    1. Try to map the user's off-script message to a valid intent for this
    //       state (typos, synonyms, "same please" instead of "S", etc.)
    //    2. If it can't map cleanly, write a context-aware clarification message
    //       that ACKNOWLEDGES what the user said and rephrases the options
    //       differently — so it doesn't feel like a loop.
    //    3. After 3 consecutive unclears at the same state, escalate to "type
    //       CANCEL or call 021 492 8515" instead of churning more Haiku calls.
    // ═══════════════════════════════════════════════════════════════════════
    private static function actSmartClarify(string $phone, string $text, array $session, array &$stateData, array &$trans, ?string $fallbackTemplate): string
    {
        $currentStep = (string) ($session['current_step'] ?: StateMachine::S_MENU);

        // Per-state unclear counter — reset when state changes
        $prevUnclearState = (string) ($stateData['unclear_state'] ?? '');
        $unclearCount    = (int) ($stateData['unclear_count'] ?? 0);
        if ($prevUnclearState !== $currentStep) $unclearCount = 0;
        $unclearCount++;
        $stateData['unclear_state'] = $currentStep;
        $stateData['unclear_count'] = $unclearCount;

        // ─── CRITICAL-STATE accelerated escalation ───
        // At payment-confirm or slot-pick states, we're 1 step away from money in
        // the door. If a customer struggles here we DON'T wait for strike 4 — we
        // ping admin at strike 2 so a human can rescue the sale before the customer
        // drops off. State stays put so the human can pick up the thread instantly.
        $criticalStates = [
            StateMachine::S_AWAITING_PAYMENT_CONFIRMATION,
            StateMachine::S_AWAITING_SLOT_SELECTION,
            StateMachine::S_AWAITING_SLOT_CONFIRM,
            StateMachine::S_AWAITING_ADDRESS_CHOICE,
            StateMachine::S_AWAITING_ADDRESS_CONFIRM,
            StateMachine::S_OUT_OF_STOCK,
        ];
        if ($unclearCount >= 2 && in_array($currentStep, $criticalStates, true) && empty($stateData['stuck_alert_sent'])) {
            self::escalateStuckCustomer($phone, $session, $currentStep, $text, $unclearCount);
            $stateData['stuck_alert_sent'] = true;
            log_event('conversation.critical_state_escalated', null, $phone, [
                'state' => $currentStep, 'strike' => $unclearCount,
            ]);
            // Don't return early — let Haiku still produce a helpful clarification,
            // BUT mention that someone is also being notified. The clarification
            // PLUS the human-incoming reassurance keeps the customer engaged.
        }

        // ─── 3rd strike: exit hatch (state stays put) ───
        // At 3 consecutive unclears, offer clear options — but DO NOT reset state.
        // Customer can still recover into the order with their next reply if they
        // type a valid answer.
        if ($unclearCount === 3) {
            log_event('conversation.smart_clarify.exhausted', null, $phone, [
                'state' => $currentStep, 'last_message' => substr($text, 0, 100),
            ]);
            return "I'm sorry — I'm not quite catching what you mean. 😕\n\n" .
                   "Three options:\n" .
                   "• Try answering one more time using the format above\n" .
                   "• Type *MENU* to start over\n" .
                   "• Or call us on *021 492 8515* — a human can take over right away\n\n" .
                   self::answerOptionsFor($currentStep);
        }

        // ─── 4th strike and beyond: silently ping admin + offer human handoff ───
        // Still don't reset the state. If/when a human responds via WhatsApp the
        // customer's conversation is intact and someone can pick up where they were.
        if ($unclearCount >= 4) {
            // Only ping once per stuck-state-episode (not every subsequent turn)
            if (empty($stateData['stuck_alert_sent'])) {
                self::escalateStuckCustomer($phone, $session, $currentStep, $text, $unclearCount);
                $stateData['stuck_alert_sent'] = true;
            }
            return "I've let our team know you're stuck — someone will WhatsApp or call you shortly.\n\n" .
                   "Office hours: Mon-Fri 08:00-17:00 · Sat 08:00-13:00.\n" .
                   "Direct line: *021 492 8515*\n\n" .
                   "_Type MENU if you'd like to start fresh in the meantime._";
        }

        // Get valid intents for this state. If none, give the customer a clear nudge
        // instead of silently showing the menu (which is what caused the friend's loop
        // bug on 26 May — typing "1" at showing_products dead-ended through clarify →
        // empty validIntents → menu shown → looked like a reset).
        $validIntents = IntentDetector::validIntentsFor($currentStep);
        if (empty($validIntents)) {
            log_to_file('whatsapp', 'smart_clarify — no valid intents for state', [
                'state' => $currentStep, 'phone' => $phone, 'message' => substr($text, 0, 80),
            ]);
            return ($fallbackTemplate ?: "Sorry, I'm not sure what you mean here.") .
                   "\n\n_Type *MENU* to start over or *CANCEL* to exit._";
        }

        // Build a Haiku call. Strict JSON output, low cost.
        try {
            $system = self::smartClarifyPrompt();
            $userMsg = self::buildSmartClarifyUserMessage($currentStep, $text, $validIntents, $stateData, $phone);

            $r = ClaudeAgent::askJson($system, $userMsg, [
                'model'       => ClaudeAgent::MODEL_FAST,
                'max_tokens'  => 250,
                'temperature' => 0.2,
            ]);
            $j = $r['json'];

            $detectedIntent = (string) ($j['intent']     ?? 'unclear');
            $confidence     = (float)  ($j['confidence'] ?? 0);
            $message        = (string) ($j['message']    ?? '');

            log_event('conversation.smart_clarify', null, $phone, [
                'state'      => $currentStep,
                'attempt'    => $unclearCount,
                'detected'   => $detectedIntent,
                'confidence' => $confidence,
                'cost_usd'   => $r['cost'] ?? 0,
            ]);

            // If Haiku confidently mapped the user's message to a valid intent, re-transition
            // through the FSM as if that intent had been detected. Only ONE recovery jump per
            // turn — never recursive (we don't call executeAction from inside actSmartClarify).
            if ($detectedIntent !== 'unclear' && $confidence >= 0.7 && in_array($detectedIntent, $validIntents, true)) {
                $newTrans = StateMachine::transition($currentStep, $detectedIntent);
                $stateData['unclear_count'] = 0;     // reset on successful recovery
                $stateData['unclear_state'] = '';

                // Apply the new transition's next_step + run its action
                $trans['next_step'] = $newTrans['next_step'];
                return self::executeAction(
                    $newTrans['action'],
                    $newTrans['response_template'],
                    $phone,
                    $text,
                    ['intent' => $detectedIntent, 'recovered' => true],
                    $session,
                    $stateData,
                    $trans,
                );
            }

            // Build the final reply: Haiku acknowledgment + literal answer options +
            // (if critical-state escalation just fired) reassurance that admin was
            // pinged. Haiku tends to paraphrase the question without the actual
            // trigger characters — PHP appends them deterministically so customers
            // always see exactly what to type.
            $base = $message !== '' ? $message : ($fallbackTemplate ?: "Sorry, I didn't catch that.");
            $options = self::answerOptionsFor($currentStep);
            if ($options !== '' && stripos($base, $options) === false) {
                $base = rtrim($base) . "\n\n" . $options;
            }

            // If we just escalated this turn at a critical state, reassure the
            // customer that help is on the way — but stay in flow.
            if (!empty($stateData['stuck_alert_sent']) && in_array($currentStep, [
                StateMachine::S_AWAITING_PAYMENT_CONFIRMATION,
                StateMachine::S_AWAITING_SLOT_SELECTION,
                StateMachine::S_AWAITING_SLOT_CONFIRM,
                StateMachine::S_AWAITING_ADDRESS_CHOICE,
                StateMachine::S_AWAITING_ADDRESS_CONFIRM,
                StateMachine::S_OUT_OF_STOCK,
            ], true) && ($unclearCount === 2)) {
                $base .= "\n\n_📞 I've also let our team know — someone will check in shortly. You can keep going with the order or wait for them._";
            }

            return $base;

        } catch (Throwable $e) {
            // Claude unavailable / API error — fall back to the static template.
            // Don't break the conversation just because Haiku is down.
            log_to_file('whatsapp', 'smart_clarify failed — fallback to template', [
                'err' => $e->getMessage(), 'state' => $currentStep,
            ]);
            return $fallbackTemplate ?: self::tplMenu();
        }
    }

    /** Prompt-cached system message for the Haiku in-flow recovery agent. */
    private static function smartClarifyPrompt(): string
    {
        return <<<PROMPT
You are the Hi-Service Gas WhatsApp ordering bot's in-flow recovery agent.

Your job is NARROW. You watch a single customer turn within the ordering flow.
The regex intent detector has just failed to match what the customer said.
You decide ONE of three things:

  1. Map the customer's message to one of the VALID INTENTS for the current
     state (typos, synonyms, paraphrasing of an expected answer). Return the
     intent name + a confidence score 0-1.
  2. If the customer's message is genuinely off-script (unrelated question,
     gibberish, request for help) — return intent="unclear" and write a SHORT
     (1 line, max 15 words) friendly clarification that:
       - Acknowledges what they said briefly ("Got it!" / "Thanks!")
       - Says one short sentence about what we need at this step
       - DO NOT list the answer options — our PHP code appends them after
         your message. If you also list them you'll cause duplication.
       - DO NOT repeat the original prompt verbatim.
  3. NEVER invent new intent names. NEVER claim prices/dates/areas. NEVER
     leak that you are an AI. Stay friendly + professional, SA English, no
     emojis unless one fits naturally.

# Hard rules

 - Confidence >= 0.7 is required to claim a real intent — anything fuzzier
   should be unclear with a clarification.
 - For yes/no questions, "yes", "yeah", "sure", "please do", "yep" all map
   to the affirmative intent. "no", "nope", "nah", "don't" map to the
   negative.
 - For numbered menus, "first one", "the first", "first option" maps to
   intent 1; same for "second"/"third" etc.
 - If the customer's message is a QUESTION about the current step (e.g.
   "what does S mean?", "what's the price?"), answer in 1 line then restate
   the options. Set intent="unclear" + include the answer in your message.
 - If the customer expresses frustration or confusion, be warm but stay
   on-task. Don't apologise repeatedly.

# Output

Reply with ONLY valid JSON, no prose, no markdown:
  { "intent": "exact_intent_name", "confidence": 0.85, "message": "" }
OR
  { "intent": "unclear", "confidence": 0.0, "message": "Your 1-2 line clarification..." }
PROMPT;
    }

    /**
     * Ping admin (GHL tag + user notify) when a customer is genuinely stuck —
     * 4+ unclear replies at the same FSM state. Does NOT reset the customer's
     * state — when a human responds via WhatsApp, the conversation is intact
     * and they can take over from exactly where the customer was.
     *
     * Telegram hook will plug in here once Stage 4 alerts are wired.
     */
    private static function escalateStuckCustomer(string $phone, array $session, string $stuckAt, string $lastMessage, int $strikes): void
    {
        try {
            $custId = (int) ($session['customer_id'] ?? 0);
            if (!$custId) {
                $existing = CustomerRepo::findByPhone($phone);
                if ($existing) $custId = (int) $existing['id'];
            }

            log_event('conversation.stuck_escalated', 'customer', $custId ? (string) $custId : null, [
                'phone'        => $phone,
                'stuck_at'     => $stuckAt,
                'strikes'      => $strikes,
                'last_message' => substr($lastMessage, 0, 200),
            ]);

            if ($custId) {
                require_once __DIR__ . '/ghl.php';
                $cust = CustomerRepo::findById($custId);
                $gid  = GHL::syncCustomer($cust);
                if ($gid) {
                    GHL::addTag($gid, ['whatsapp-stuck', 'needs-human-pickup']);
                    $name = trim((string) ($cust['full_name'] ?? '')) ?: $phone;
                    GHL::notifyUser(
                        GHL::USER_GAS,
                        "Customer stuck mid-order — needs human pickup",
                        "Customer: *{$name}* ({$phone})\n" .
                        "Stuck at step: *{$stuckAt}*\n" .
                        "Their last message: \"{$lastMessage}\"\n" .
                        "Strikes: {$strikes}\n\n" .
                        "Reach out via WhatsApp — their conversation is paused exactly where they were, so anything you reply will pick up the thread."
                    );
                }
            }
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'escalateStuckCustomer failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * Literal answer-format options for each state — appended to every
     * smart_clarify response so customers always see EXACTLY what to type.
     * Haiku tends to paraphrase ("repeat or different one?") without the
     * actual trigger characters; this block guarantees the trigger chars
     * are always visible.
     *
     * Returns '' for states where there isn't a clean option set (open-text
     * states like name/address collection have their own templates).
     */
    private static function answerOptionsFor(string $currentStep): string
    {
        return match ($currentStep) {
            StateMachine::S_MENU,
            StateMachine::S_AWAITING_ORDER_CHOICE,
            StateMachine::S_NEW_ORDER_CLARIFICATION
                => "*Reply with:*\n*1* - to repeat your last order\n*2* - to place a different order",

            StateMachine::S_AWAITING_ADDRESS_CHOICE
                => "*Reply with:*\n*S* or *Y* - keep my saved address\n*D* or *N* - different address",

            StateMachine::S_AWAITING_SLOT_SELECTION
                => "*Reply with:*\n*A* - the morning slot shown\n*B* - the afternoon slot shown\nOr type a date like *28/05/2026*",

            StateMachine::S_AWAITING_PAYMENT_CONFIRMATION
                => "*Reply with:*\n*P* - continue to payment\n*D* - place a different order\n*Cancel* - cancel this order",

            StateMachine::S_CONFIRM_NEW_DETAILS
                => "*Reply with:*\n*Y* - keep my current details\n*N* - update my details",

            StateMachine::S_OUT_OF_STOCK
                => "*Reply with:*\n*A* - try a different product\n*B* - leave your number, we'll call when it's back\n*Cancel* - cancel this order",

            StateMachine::S_COLLECTING_ORDER_DETAILS
                => "*Reply with the product letter + quantity.*\n*Example:* B2  (= 2 × 5kg LPG)",

            StateMachine::S_AWAITING_STREET_CODE
                => "*Reply with your 4-digit postal code.*\n*Example:* 7140",

            StateMachine::S_AWAITING_NEW_CUSTOMER_DETAILS
                => "*Reply with 3 lines:*\n1. Your full name\n2. Street, suburb, city, postal code\n3. Email (optional)",

            StateMachine::S_AWAITING_NEW_ADDRESS
                => "*Reply with each on its own line:*\n1. Street + number\n2. Suburb\n3. City\n4. Postal code",

            StateMachine::S_AWAITING_ADDRESS_CONFIRM
                => "*Reply with:*\n*Y* - address is correct, continue\n*N* - I need to fix it",

            StateMachine::S_AWAITING_SLOT_CONFIRM
                => "*Reply with:*\n*Y* - confirm this slot and pay\n*N* - pick a different time",

            StateMachine::S_AWAITING_CALLBACK_DETAILS
                => "*Reply with your name* so our team knows who to call.",

            default => '',
        };
    }

    private static function buildSmartClarifyUserMessage(string $currentStep, string $text, array $validIntents, array $stateData, string $phone): string
    {
        $intentList = '  - ' . implode("\n  - ", $validIntents);

        // Brief state context — what they're being asked
        $stateBlurb = match ($currentStep) {
            StateMachine::S_AWAITING_ORDER_CHOICE    => "Customer asked: repeat last order (1) OR place a different order (2)",
            StateMachine::S_AWAITING_ADDRESS_CHOICE  => "Customer asked: deliver to saved address (S/Y) OR enter a different address (D/N)",
            StateMachine::S_AWAITING_SLOT_SELECTION  => "Customer asked: pick slot A or B, or type a specific date (e.g. 28/05/2026)",
            StateMachine::S_AWAITING_PAYMENT_CONFIRMATION => "Customer asked: P to pay, D for different order, Cancel to cancel",
            StateMachine::S_SHOWING_PRODUCTS         => "Customer asked: reply with letter + quantity (e.g. B2 = 2 × 5kg LPG)",
            StateMachine::S_COLLECTING_ORDER_DETAILS => "Customer asked: send product code(s) like B2 or D1",
            StateMachine::S_AWAITING_STREET_CODE     => "Customer asked: 4-digit postal code",
            StateMachine::S_AWAITING_NEW_CUSTOMER_DETAILS => "Customer asked: 3 lines — name, address, email",
            StateMachine::S_AWAITING_NEW_ADDRESS     => "Customer asked: address on separate lines (street, suburb, city, postal code)",
            StateMachine::S_AWAITING_ADDRESS_CONFIRM => "Customer asked: Y to confirm captured address, N to re-enter",
            StateMachine::S_AWAITING_SLOT_CONFIRM    => "Customer asked: Y to confirm slot and pay, N to pick a different time",
            StateMachine::S_CONFIRM_NEW_DETAILS      => "Customer asked: Y to keep current details, N to update",
            StateMachine::S_MENU                     => "Customer asked: 1 to order gas, 2 for general help",
            StateMachine::S_OUT_OF_STOCK             => "Product they wanted is out of stock. Customer asked: A to try a different product, B to leave their name for a callback, or Cancel",
            StateMachine::S_AWAITING_CALLBACK_DETAILS=> "Customer asked: send their name so the team can call back when stock arrives",
            default                                  => "Customer is mid-ordering at state: {$currentStep}",
        };

        return <<<MSG
Current FSM state: {$currentStep}
Context: {$stateBlurb}

Valid intents the regex matcher accepts here:
{$intentList}

Customer just sent: "{$text}"

Did they likely mean one of the valid intents? Or are they off-script?
Reply with JSON.
MSG;
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
