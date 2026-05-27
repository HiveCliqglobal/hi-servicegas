<?php
/**
 * intent_detector.php
 *
 * Pattern-matching intent classifier for WhatsApp messages.
 * Ported from n8n "Store Detected Intent" node with fuzzy improvements:
 *  - Strips trailing punctuation: "1." "1)" → "1"
 *  - Fuzzy yes/no: "yep", "yeah", "nope", "nah"
 *  - Date parsing for slot picker: "tomorrow", "monday", "25/01/2026"
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/state_machine.php';

final class IntentDetector
{
    /**
     * Detect intent based on message + current FSM step.
     *
     * If regex matching returns 'unclear' and the ANTHROPIC_API_KEY is set,
     * the Claude Agent is consulted as a fallback. The agent's interpretation
     * is logged in agent_activity so we can audit accuracy over time.
     *
     * @return array{intent:string, action:?string, extracted:array, confidence:float}
     */
    public static function detect(string $message, string $currentStep, array $context = []): array
    {
        // NOTE: Claude disambiguation fallback was REMOVED on 25 May 2026.
        // It was over-eagerly classifying greetings ("Hi") as 'general_help', trapping users
        // in the terminal handoff state. AgentSupervisor now handles classification at a higher
        // level (it routes ORDER_FLOW vs GENERAL_HELP vs RESET vs ESCALATE before this regex
        // detector even runs). This detector now only runs WITHIN order-flow turns and stays
        // intentionally simple.
        $result = self::detectByRegex($message, $currentStep, $context);
        return $result;
    }

    /** List the intents that are valid for a given step (for Claude prompts + smart-recovery). */
    public static function validIntentsFor(string $step): array
    {
        $defs = StateMachine::definition()[$step] ?? null;
        if (!$defs) return [];
        return array_values(array_filter(array_keys($defs), fn($k) => $k !== '_default'));
    }

    /** The original regex-only classifier, refactored for clarity. */
    private static function detectByRegex(string $message, string $currentStep, array $context = []): array
    {
        $raw   = $message;
        $msg   = self::normalize($message);
        $extracted = [];

        // ========== GLOBAL: cancel/stop/reset (works any state) ==========
        if (preg_match('/\b(cancel|stop|reset)\b/i', $msg)) {
            return ['intent' => 'cancel_order', 'action' => 'cancel_order', 'extracted' => $extracted, 'confidence' => 1.0];
        }

        // ========== GLOBAL: return to menu ==========
        if (in_array($msg, ['menu', '0', 'back', 'home'], true)) {
            return ['intent' => 'reset_to_menu', 'action' => 'show_menu', 'extracted' => $extracted, 'confidence' => 1.0];
        }

        // ========== GLOBAL: talk to a person ==========
        // Customer typed "help me", "speak to someone", "talk to a person", "human" etc.
        // at ANY state → AgentSupervisor's ESCALATE branch handles the routing + admin
        // notification. We return the cancel_order intent here only as a safety hatch —
        // supervisor classifies "help me" → ESCALATE via Haiku before we get here.
        if (preg_match('/\b(help me|talk to (a |the )?(person|human|someone|agent|staff)|speak to|need help|real person|live agent)\b/i', $msg)) {
            return ['intent' => 'request_human_help', 'action' => 'request_human_help', 'extracted' => $extracted, 'confidence' => 1.0];
        }

        // ========== Per-state pattern matching ==========
        switch ($currentStep) {

            case StateMachine::S_MENU:
                if ($msg === '1' || self::containsAny($msg, ['order', 'buy', 'gas'])) {
                    return ['intent' => 'order_gas', 'action' => 'order_gas', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                if ($msg === '2' || self::containsAny($msg, ['help', 'question', 'info', 'chat', 'speak'])) {
                    return ['intent' => 'general_help', 'action' => 'general_help', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => null, 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_INITIAL:
                return ['intent' => 'greeting', 'action' => null, 'extracted' => $extracted, 'confidence' => 1.0];

            case StateMachine::S_AWAITING_ORDER_CHOICE:
                if ($msg === '1' || str_contains($msg, 'repeat')) {
                    return ['intent' => 'repeat_order', 'action' => 'confirm_current_address', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                if ($msg === '2' || self::containsAny($msg, ['different', 'new'])) {
                    return ['intent' => 'new_order', 'action' => 'get_product_catalog', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => 'clarify', 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_NEW_ORDER_CLARIFICATION:
                if ($msg === '1' || str_contains($msg, 'keep')) {
                    return ['intent' => 'keep_order', 'action' => 'confirm_current_address', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                if ($msg === '2' || str_contains($msg, 'new')) {
                    return ['intent' => 'new_order', 'action' => 'get_product_catalog', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => 'clarify', 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_CONFIRM_NEW_DETAILS:
                if (self::isYes($msg)) {
                    return ['intent' => 'current_details', 'action' => 'get_product_catalog', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                if (self::isNo($msg)) {
                    return ['intent' => 'new_details', 'action' => 'awaiting_new_customer_details', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => null, 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_COLLECTING_ORDER_DETAILS:
                $tokens = self::parseOrderTokens($raw);
                if (!empty($tokens)) {
                    $extracted['raw_collecting_order_details'] = $raw;
                    $extracted['order_tokens'] = $tokens;
                    return ['intent' => 'collecting_order_details_provided', 'action' => 'collecting_order_details', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => 'clarify', 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_AWAITING_ADDRESS_CHOICE:
                $token = preg_replace('/[^a-z]/', '', $msg) ?? '';
                if ($token === 's' || $token === 'y') {
                    return ['intent' => 'same_address', 'action' => null, 'extracted' => $extracted, 'confidence' => 1.0];
                }
                if ($token === 'd' || $token === 'n') {
                    return ['intent' => 'different_address', 'action' => null, 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => null, 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_AWAITING_STREET_CODE:
                if (preg_match('/^\d{4}$/', $msg)) {
                    $extracted['raw_street_code'] = $msg;
                    return ['intent' => 'street_code_provided', 'action' => null, 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => null, 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_AWAITING_NEW_ADDRESS:
                $trim = trim($raw);
                if (strcasecmp($trim, 'R') === 0) {
                    return ['intent' => 'confirm_current_address', 'action' => 'confirm_current_address', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                if (strlen($trim) > 10) {
                    $extracted['raw_address'] = $raw;
                    return ['intent' => 'address_provided', 'action' => 'update_customer_address', 'extracted' => $extracted, 'confidence' => 0.9];
                }
                return ['intent' => 'unclear', 'action' => 'clarify_address', 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_AWAITING_NEW_CUSTOMER_DETAILS:
            case StateMachine::S_AWAITING_EXISTING_CUST_DETAILS:
                $trim = trim($raw);
                if (strcasecmp($trim, 'R') === 0) {
                    return ['intent' => 'recheck_current_details', 'action' => 'recheck_current_details', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                $lines = array_values(array_filter(array_map('trim', explode("\n", $trim))));
                $hasName    = count($lines) >= 1 && count(array_filter(explode(' ', $lines[0]))) >= 2;
                $hasAddress = isset($lines[1]) && strlen($lines[1]) >= 8;
                if ($hasName && $hasAddress) {
                    $extracted['raw_new_customer_details'] = $raw;
                    $extracted['parsed_name']    = $lines[0];
                    $extracted['parsed_address'] = $lines[1];
                    $extracted['parsed_email']   = $lines[2] ?? null;
                    $intent = ($currentStep === StateMachine::S_AWAITING_NEW_CUSTOMER_DETAILS)
                              ? 'new_customer_details_provided'
                              : 'existing_customer_details_provided';
                    return ['intent' => $intent, 'action' => 'update_customer_details', 'extracted' => $extracted, 'confidence' => 0.95];
                }
                return ['intent' => 'unclear', 'action' => null, 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_AWAITING_SLOT_SELECTION:
                // Letter A–Z first
                if (preg_match('/^[a-z]$/i', $msg)) {
                    $extracted['slot_letter'] = strtoupper($msg);
                    return ['intent' => 'awaiting_slot_selection_provided', 'action' => 'book_delivery_slot', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                // Date parsing
                $iso = self::parseRelativeOrDate($raw);
                if ($iso) {
                    $extracted['raw_date'] = $iso;
                    return ['intent' => 'awaiting_custom_slot_selection_provided', 'action' => 'check_custom_slot', 'extracted' => $extracted, 'confidence' => 0.9];
                }
                return ['intent' => 'unclear', 'action' => 'clarify', 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_AWAITING_PAYMENT_CONFIRMATION:
                if ($msg === 'p' || str_contains($msg, 'pay')) {
                    return ['intent' => 'confirm_payment', 'action' => null, 'extracted' => $extracted, 'confidence' => 1.0];
                }
                if ($msg === 'd' || str_contains($msg, 'new')) {
                    return ['intent' => 'new_order', 'action' => 'get_product_catalog', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => null, 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_OUT_OF_STOCK:
                // A or "different" / "other" / "another" / "try" → swap product
                if ($msg === 'a' || $msg === '1' || self::containsAny($msg, ['different', 'other', 'another', 'try', 'swap', 'change'])) {
                    return ['intent' => 'try_other_product', 'action' => 'get_product_catalog', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                // B or "callback" / "call me" / "let me know" → request callback
                if ($msg === 'b' || $msg === '2' || self::containsAny($msg, ['callback', 'call me', 'call back', 'let me know', 'phone me', 'notify me', 'leave', 'wait'])) {
                    return ['intent' => 'request_callback', 'action' => 'request_callback_details', 'extracted' => $extracted, 'confidence' => 1.0];
                }
                return ['intent' => 'unclear', 'action' => 'clarify', 'extracted' => $extracted, 'confidence' => 0.0];

            case StateMachine::S_AWAITING_CALLBACK_DETAILS:
                // Anything that looks like a name (letters + at least one space, OR 3+ chars)
                $trim = trim($raw);
                if ($trim !== '' && preg_match('/[A-Za-z]/', $trim) && mb_strlen($trim) >= 2) {
                    $extracted['raw_name'] = $trim;
                    return ['intent' => 'callback_details_provided', 'action' => 'log_callback_lead', 'extracted' => $extracted, 'confidence' => 0.95];
                }
                return ['intent' => 'unclear', 'action' => 'clarify', 'extracted' => $extracted, 'confidence' => 0.0];
        }

        return ['intent' => 'unclear', 'action' => null, 'extracted' => $extracted, 'confidence' => 0.0];
    }

    // ============= Helpers =============

    /** Lowercase, trim, collapse whitespace, strip trailing punctuation. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[.,!?)]+$/', '', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }

    public static function isYes(string $s): bool
    {
        return in_array(self::normalize($s), ['y', 'yes', 'yep', 'yeah', 'yup', 'ok', 'okay', 'sure', 'correct'], true);
    }

    public static function isNo(string $s): bool
    {
        return in_array(self::normalize($s), ['n', 'no', 'nope', 'nah', 'incorrect', 'wrong'], true);
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) return true;
        }
        return false;
    }

    /**
     * Parse "B2 D1", "B2,D1", "B 2\nD 1" → ['B2','D1'].
     * Returns empty array if any token is malformed.
     */
    public static function parseOrderTokens(string $raw): array
    {
        $parts = preg_split('/[\n,;]+/', trim($raw)) ?: [];
        $tokens = [];
        foreach ($parts as $p) {
            $p = preg_replace('/\s+/', '', $p) ?? '';
            if ($p === '') continue;
            if (!preg_match('/^[A-Za-z]\d+$/', $p)) return [];
            $tokens[] = strtoupper($p);
        }
        return $tokens;
    }

    /**
     * Accept "today", "tomorrow", weekday names, or DD/MM/YYYY / YYYY-MM-DD.
     * Returns ISO date YYYY-MM-DD or null.
     */
    public static function parseRelativeOrDate(string $raw): ?string
    {
        $clean = strtoupper(preg_replace('/[,@]/', ' ', trim($raw)) ?? '');
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        if (str_contains($clean, 'TOMORROW')) {
            return date('Y-m-d', strtotime('+1 day'));
        }
        if (str_contains($clean, 'TODAY')) {
            return date('Y-m-d');
        }

        $weekdays = ['SUNDAY' => 0, 'MONDAY' => 1, 'TUESDAY' => 2, 'WEDNESDAY' => 3, 'THURSDAY' => 4, 'FRIDAY' => 5, 'SATURDAY' => 6];
        $short    = ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6];
        foreach ($weekdays as $name => $idx) {
            if (str_contains($clean, $name)) {
                $diff = ($idx + 7 - (int) date('w')) % 7 ?: 7;
                return date('Y-m-d', strtotime("+{$diff} day"));
            }
        }
        foreach ($short as $name => $idx) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $clean)) {
                $diff = ($idx + 7 - (int) date('w')) % 7 ?: 7;
                return date('Y-m-d', strtotime("+{$diff} day"));
            }
        }

        // DD/MM/YYYY or YYYY-MM-DD (with - / . separators)
        if (preg_match('#\b(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})\b#', $clean, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('#\b(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})\b#', $clean, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        return null;
    }
}
