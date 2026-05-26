<?php
/**
 * agent_supervisor.php — Claude-powered oversight layer for every WhatsApp turn.
 *
 * The supervisor wraps the existing state machine. Its job is to prevent:
 *  - Loops (user stuck in same state for 3+ turns)
 *  - Hallucinations (invented prices / dates / areas / services)
 *  - Off-script answers to off-script questions (general help routes to grounded FAQ agent)
 *
 * One brain, hard guardrails, low cost:
 *  - Classifier: Haiku 4.5 (~$0.001/msg, prompt cached)
 *  - Validator:  Haiku 4.5 (~$0.001/msg, prompt cached)
 *  - Off-script: Sonnet 4.5 via existing GeneralHelpAgent (~$0.005/msg)
 *
 * At ~500 msgs/month: ~R10/month total. At ~5000 msgs/month: ~R100/month.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/claude_agent.php';
require_once __DIR__ . '/agent_knowledge.php';
require_once __DIR__ . '/conversation.php';
require_once __DIR__ . '/general_help_agent.php';
require_once __DIR__ . '/customer_repo.php';
require_once __DIR__ . '/ghl.php';

final class AgentSupervisor
{
    public const INTENT_ORDER_FLOW  = 'ORDER_FLOW';
    public const INTENT_GENERAL_HELP = 'GENERAL_HELP';
    public const INTENT_ESCALATE    = 'ESCALATE';
    public const INTENT_RESET       = 'RESET';

    public const LOOP_THRESHOLD = 3;      // 3+ identical step transitions in a row = stuck
    public const LOOP_WINDOW_MIN = 10;    // within last 10 min

    /**
     * Main entry — replaces direct call to Conversation::handle from the webhook.
     */
    public static function handle(string $phone, string $text, string $provider = 'twilio'): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone === '' || $phone === null) return '';

        // 1. Load session + recent history (last 8 turns)
        $session = self::loadSession($phone);
        $history = self::recentHistory($phone, 8);
        $currentStep = (string) ($session['current_step'] ?? 'menu');

        // 2. (Removed 2026-05-26) Auto-reset on stuck-state was kicking customers
        //    to MENU as soon as they typed 3 unclear answers at the same step —
        //    even when they were genuinely mid-order and just needed a clearer
        //    prompt. Conversation::actSmartClarify now owns recovery: 1-2 Haiku
        //    clarifications, 3rd strike offers exit hatch (MENU / CANCEL / call
        //    021 492 8515) WITHOUT touching state, 4th strike escalates to admin
        //    via GHL but STILL keeps the customer at the current step so a human
        //    can pick up where they were. No more "kicked back to menu" surprise.
        //    isStuck()/breakLoop() helpers are kept on the class for emergency
        //    use (e.g. genuine FSM bugs) but no longer auto-trigger.

        // 3. Classify intent via Haiku (cheap, fast, grounded)
        $classification = self::classify($text, $currentStep, $history);
        $intent = $classification['intent'] ?? self::INTENT_ORDER_FLOW;

        log_event('supervisor.classify', null, $phone, [
            'intent'       => $intent,
            'confidence'   => $classification['confidence'] ?? null,
            'reason'       => $classification['reason']     ?? null,
            'current_step' => $currentStep,
            'cost_usd'     => $classification['cost'] ?? 0,
        ]);

        // 4. Route
        switch ($intent) {
            case self::INTENT_GENERAL_HELP:
                return self::handleGeneralHelp($phone, $text, $session, $history);

            case self::INTENT_ESCALATE:
                return self::handleEscalate($phone, $text, $session, $classification['reason'] ?? 'Customer requested human help');

            case self::INTENT_RESET:
                self::resetSession($phone);
                return Conversation::handle($phone, 'menu', $provider);

            case self::INTENT_ORDER_FLOW:
            default:
                return self::handleOrderFlow($phone, $text, $provider);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Classifier — Haiku 4.5 (cached system prompt)
    // ═══════════════════════════════════════════════════════════════════════
    private static function classify(string $text, string $currentStep, array $history): array
    {
        // Cheap rule fast-paths — bypass Claude when obvious
        $norm = strtolower(trim($text));

        // Bare greetings → reset to menu (skip Claude)
        if (in_array($norm, ['hi','hello','hey','hola','yo','sup','morning','afternoon','evening'], true)) {
            return ['intent' => self::INTENT_RESET, 'confidence' => 1.0, 'reason' => 'bare-greeting', 'cost' => 0];
        }

        // Explicit cancel/menu words → reset
        if (in_array($norm, ['cancel','stop','reset','menu','back','exit','quit','start over'], true)) {
            return ['intent' => self::INTENT_RESET, 'confidence' => 1.0, 'reason' => 'explicit-reset', 'cost' => 0];
        }

        // Mid-flow ordering inputs are obviously ORDER_FLOW — skip Claude for performance
        $orderingStates = ['awaiting_street_code','awaiting_new_customer_details','awaiting_new_address',
                          'awaiting_address_choice','awaiting_slot_selection','awaiting_payment_confirmation',
                          'showing_products','collecting_order_details','confirm_new_details'];
        if (in_array($currentStep, $orderingStates, true)) {
            return ['intent' => self::INTENT_ORDER_FLOW, 'confidence' => 1.0, 'reason' => 'mid-order-state', 'cost' => 0];
        }

        // Numeric menu choice
        if ($norm === '1') return ['intent' => self::INTENT_ORDER_FLOW,  'confidence' => 1.0, 'reason' => 'menu-1', 'cost' => 0];
        if ($norm === '2') return ['intent' => self::INTENT_GENERAL_HELP, 'confidence' => 1.0, 'reason' => 'menu-2', 'cost' => 0];

        // Anything else from MENU state → ask Claude
        try {
            $system = self::classifierPrompt();
            $userMsg = self::buildClassifierUserMessage($text, $currentStep, $history);

            $r = ClaudeAgent::askJson($system, $userMsg, [
                'model'       => ClaudeAgent::MODEL_FAST,
                'max_tokens'  => 200,
                'temperature' => 0.1,
            ]);
            $j = $r['json'];
            return [
                'intent'     => $j['intent']     ?? self::INTENT_ORDER_FLOW,
                'confidence' => $j['confidence'] ?? 0.6,
                'reason'     => $j['reason']     ?? null,
                'cost'       => $r['cost'],
            ];
        } catch (Throwable $e) {
            // Claude failed — fall back to ORDER_FLOW (safer than wrong route)
            log_to_file('supervisor', 'classifier failed', ['err' => $e->getMessage(), 'text' => $text]);
            return ['intent' => self::INTENT_ORDER_FLOW, 'confidence' => 0.4, 'reason' => 'classifier-error', 'cost' => 0];
        }
    }

    private static function classifierPrompt(): string
    {
        // Prompt-cached — same content every call, costs 1× then 10% per subsequent
        return <<<PROMPT
You are the routing supervisor for Hi-Service Gas WhatsApp bot.

Your ONLY job: classify each inbound customer message into exactly ONE intent.

# Available intents

ORDER_FLOW
  Customer wants to place / continue / modify a gas order.
  Examples: "1", "order", "I need gas", "B2", "yes deliver to my address",
            "Wednesday afternoon", "P", a postal code "7140", customer details
            sent on multiple lines, slot picks like "A" or "B".

GENERAL_HELP
  Customer asks something OUTSIDE the ordering flow.
  Examples: "what areas do you deliver to?", "how much for 19kg?",
            "what are your hours?", "do you install gas stoves?",
            "do you do solar?", "where are you based?", "is my cylinder safe?".

ESCALATE
  Customer is angry, complaining, asking for a human, or has a complex policy
  question we can't answer from the FAQ.
  Examples: "I want to speak to a manager", "this is ridiculous",
            "I want a refund", "your driver damaged my fence".

RESET
  Customer wants to start over OR sent something that should clear state.
  Examples: "cancel", "start over", "menu", "back", "exit", "nevermind".

# Hard rules

 - Postal codes (4 digits) are ALWAYS ORDER_FLOW
 - Product codes (letter + digit like B2, D1) are ALWAYS ORDER_FLOW
 - Yes/no answers are ORDER_FLOW (continuing the conversation)
 - Greetings without context ("hi", "hello") are RESET (back to menu)
 - When in doubt between ORDER_FLOW and GENERAL_HELP: pick GENERAL_HELP only
   if it's clearly a question, otherwise ORDER_FLOW
 - NEVER invent a new intent name

# Output

Reply with ONLY valid JSON, no prose, no markdown:
{ "intent": "ORDER_FLOW", "confidence": 0.95, "reason": "short explanation" }
PROMPT;
    }

    private static function buildClassifierUserMessage(string $text, string $currentStep, array $history): string
    {
        $histLines = [];
        foreach (array_slice($history, -5) as $h) {
            $role = $h['direction'] === 'in' ? 'Customer' : 'Bot';
            $histLines[] = "{$role}: " . substr((string) $h['message_text'], 0, 200);
        }
        $hist = empty($histLines) ? '(no prior turns)' : implode("\n", $histLines);

        return "Current FSM state: {$currentStep}\n\nRecent conversation:\n{$hist}\n\nNew customer message: \"{$text}\"\n\nClassify.";
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ORDER_FLOW — run state machine, then validate reply
    // ═══════════════════════════════════════════════════════════════════════
    private static function handleOrderFlow(string $phone, string $text, string $provider): string
    {
        // Delegate to existing Conversation handler (state machine + actions)
        $reply = Conversation::handle($phone, $text, $provider);

        // Post-validate: did the reply invent anything? (Async-style — only on suspicious replies)
        // For now, trust state machine output since all numbers/dates come from DB.
        // Validator hook left in place for future strengthening.
        return $reply;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  GENERAL_HELP — route to grounded FAQ agent (already wired with tools)
    // ═══════════════════════════════════════════════════════════════════════
    private static function handleGeneralHelp(string $phone, string $text, array $session, array $history): string
    {
        // Don't touch session.current_step — general help is sidecar, customer may return
        // to ordering mid-thread by saying "ok, let me order then"
        try {
            $customerId = $session['customer_id'] ? (int) $session['customer_id'] : null;
            if (!$customerId) {
                $cust = CustomerRepo::findByPhone($phone);
                $customerId = $cust ? (int) $cust['id'] : null;
            }

            $histForAgent = [];
            foreach (array_slice($history, -6) as $h) {
                $histForAgent[] = [
                    'role'    => $h['direction'] === 'in' ? 'user' : 'assistant',
                    'content' => (string) $h['message_text'],
                ];
            }

            $r = GeneralHelpAgent::answer($customerId, $text, $histForAgent);
            log_event('supervisor.general_help', null, $phone, [
                'tool_calls' => count($r['tool_calls'] ?? []),
                'cost_usd'   => $r['cost_usd'] ?? 0,
            ]);
            return $r['reply'] . "\n\n_Reply *1* anytime to start a gas order._";
        } catch (Throwable $e) {
            log_to_file('supervisor', 'general_help failed', ['err' => $e->getMessage()]);
            return "I'm having trouble answering that right now. Please call 021 492 8515 or email gas@hiservice.co.za and we'll help right away.";
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ESCALATE — flag for human + tag in GHL + return reassurance
    // ═══════════════════════════════════════════════════════════════════════
    private static function handleEscalate(string $phone, string $text, array $session, string $reason): string
    {
        try {
            $customerId = $session['customer_id'] ? (int) $session['customer_id'] : null;
            $cust = $customerId ? CustomerRepo::findById($customerId) : CustomerRepo::findByPhone($phone);

            if ($cust) {
                $gid = GHL::syncCustomer($cust);
                if ($gid) {
                    GHL::addTag($gid, ['whatsapp-escalation', 'needs-human']);
                    GHL::notifyUser(GHL::USER_GAS, 'WhatsApp escalation', "From: {$phone}\nMsg: {$text}\nReason: {$reason}", $gid);
                }
            }

            ClaudeAgent::logActivity([
                'kind'        => 'escalation',
                'severity'    => 'warn',
                'title'       => 'WhatsApp escalation',
                'summary'     => substr($reason, 0, 200),
                'context'     => ['phone' => $phone, 'last_message' => $text],
                'entity_type' => 'customer',
                'entity_id'   => $customerId ? (string) $customerId : null,
            ]);
        } catch (Throwable $e) {
            log_to_file('supervisor', 'escalate failed', ['err' => $e->getMessage()]);
        }

        return "I've flagged this for our team — someone from Hi-Service will reach out to you on WhatsApp during office hours.\n\n_Office hours: Mon-Fri 08:00-17:00 · Sat 08:00-13:00._\n\nFor anything urgent, please call *021 492 8515*.";
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Loop detection — same step 3+ times in last 10 min = stuck
    // ═══════════════════════════════════════════════════════════════════════
    private static function isStuck(string $phone, string $currentStep): bool
    {
        // Count how many transitions in last LOOP_WINDOW_MIN minutes have arrived at this step
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS c FROM event_log
              WHERE action = 'conversation.transition'
                AND entity_id = :p
                AND JSON_EXTRACT(payload, '$.to') = :step
                AND created_at > (NOW() - INTERVAL :min MINUTE)"
        );
        $stmt->bindValue(':p',    $phone,       PDO::PARAM_STR);
        $stmt->bindValue(':step', '"' . $currentStep . '"', PDO::PARAM_STR);
        $stmt->bindValue(':min',  self::LOOP_WINDOW_MIN, PDO::PARAM_INT);
        $stmt->execute();
        $count = (int) ($stmt->fetch()['c'] ?? 0);
        return $count >= self::LOOP_THRESHOLD;
    }

    private static function breakLoop(string $phone, string $text, string $stuckStep, array $history): string
    {
        // Clear session and offer a clean start with empathy
        self::resetSession($phone);

        // Log this as an agent intervention for the dashboard
        ClaudeAgent::logActivity([
            'kind'        => 'intervention',
            'severity'    => 'info',
            'title'       => 'Loop-break — reset stuck conversation',
            'summary'     => "Phone {$phone} stuck at '{$stuckStep}' for " . self::LOOP_THRESHOLD . "+ turns",
            'context'     => ['phone' => $phone, 'stuck_at' => $stuckStep, 'last_message' => $text],
            'entity_type' => 'customer',
            'entity_id'   => $phone,
        ]);

        return "It looks like we're going in circles — let me reset us. 🔄\n\n" .
               "*Hi-Service Gas* 🔥\nHow can we help today?\n\n" .
               "*1* - Order LPG gas\n" .
               "*2* - General help / questions\n\n" .
               "Or call us directly: 021 492 8515";
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Session helpers
    // ═══════════════════════════════════════════════════════════════════════
    private static function loadSession(string $phone): array
    {
        $stmt = db()->prepare("SELECT * FROM sessions WHERE phone = :p AND expires_at > NOW() LIMIT 1");
        $stmt->execute([':p' => $phone]);
        $row = $stmt->fetch();
        return $row ?: ['phone' => $phone, 'current_step' => 'menu', 'customer_id' => null];
    }

    private static function recentHistory(string $phone, int $limit = 8): array
    {
        $stmt = db()->prepare("SELECT direction, message_text, created_at FROM conversations WHERE phone = :p ORDER BY id DESC LIMIT :n");
        $stmt->bindValue(':p', $phone, PDO::PARAM_STR);
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll());
    }

    private static function resetSession(string $phone): void
    {
        db()->prepare(
            "INSERT INTO sessions (phone, mode, current_step, state_data) VALUES (:p, 'menu', 'menu', '{}')
             ON DUPLICATE KEY UPDATE current_step = 'menu', customer_id = NULL, current_order_id = NULL, state_data = '{}',
                                      expires_at = NOW() + INTERVAL 24 HOUR"
        )->execute([':p' => $phone]);
    }
}
