<?php
/**
 * general_help_agent.php — Claude-powered FAQ + calendar + escalation agent.
 *
 * Replaces what would have been a GHL AI Bot workflow.
 *
 * Same Claude model that watches the orders also answers customer questions —
 * one brain, one personality, full context (knows the customer's orders).
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/claude_agent.php';
require_once __DIR__ . '/agent_knowledge.php';
require_once __DIR__ . '/customer_repo.php';
require_once __DIR__ . '/order_repo.php';
require_once __DIR__ . '/ghl.php';

final class GeneralHelpAgent
{
    /**
     * Answer a customer's general-help message.
     *
     * @param int|null $customerId  Hi-Service customer id (null if anonymous)
     * @param string   $message     The customer's free-text message
     * @param array    $history     Last N turns of conversation
     * @return array{reply:string, tool_calls:array, cost_usd:float}
     */
    public static function answer(?int $customerId, string $message, array $history = []): array
    {
        $system = AgentKnowledge::systemPrompt();

        // Inject live customer context as a system block (cached together with the FAQ)
        if ($customerId) {
            $context = self::customerContext($customerId);
            $system .= "\n\n# Live customer context\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Build the messages array
        $messages = [];
        foreach ($history as $h) {
            $messages[] = [
                'role'    => $h['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $h['content'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $tools = self::toolDefinitions();

        $reply       = '';
        $toolCalls   = [];
        $totalCost   = 0.0;
        $maxLoops    = 4;
        $loop        = 0;

        while ($loop++ < $maxLoops) {
            $r = self::callClaude($system, $messages, $tools);
            $totalCost += $r['cost'];

            $stopReason = $r['raw']['stop_reason'] ?? 'end_turn';

            if ($stopReason === 'tool_use') {
                // Execute every requested tool, append results, loop again
                $assistantContent = [];
                $toolResults      = [];

                foreach (($r['raw']['content'] ?? []) as $blk) {
                    $assistantContent[] = $blk;
                    if (($blk['type'] ?? '') === 'tool_use') {
                        $result = self::executeTool($blk['name'], $blk['input'] ?? [], $customerId);
                        $toolCalls[] = ['name' => $blk['name'], 'input' => $blk['input'] ?? [], 'result' => $result];
                        $toolResults[] = [
                            'type'        => 'tool_result',
                            'tool_use_id' => $blk['id'],
                            'content'     => is_string($result) ? $result : json_encode($result),
                        ];
                    }
                }
                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                $messages[] = ['role' => 'user',      'content' => $toolResults];
                continue;
            }

            // Plain end_turn — collect text
            foreach (($r['raw']['content'] ?? []) as $blk) {
                if (($blk['type'] ?? '') === 'text') $reply .= $blk['text'];
            }
            break;
        }

        // Log the whole turn
        ClaudeAgent::logActivity([
            'kind'              => 'observation',
            'severity'          => 'info',
            'title'             => 'General-help reply',
            'summary'           => substr($reply, 0, 200),
            'context'           => [
                'customer_id' => $customerId,
                'message'     => $message,
                'tool_calls'  => $toolCalls,
            ],
            'entity_type'       => $customerId ? 'customer' : null,
            'entity_id'         => $customerId ? (string) $customerId : null,
            'model'             => ClaudeAgent::MODEL_SMART,
            'cost_usd'          => $totalCost,
        ]);

        return [
            'reply'      => trim($reply) ?: "Sorry, I didn't catch that. Please WhatsApp us on 063 693 5532 and someone will help you straight away.",
            'tool_calls' => $toolCalls,
            'cost_usd'   => round($totalCost, 6),
        ];
    }

    // ============= internals =============

    private static function customerContext(int $customerId): array
    {
        $c = CustomerRepo::findById($customerId);
        if (!$c) return ['unknown_customer' => true];

        $stmt = db()->prepare(
            "SELECT id, order_reference, status, total_amount, paid_at, created_at
             FROM orders WHERE customer_id = :id
             ORDER BY created_at DESC LIMIT 3"
        );
        $stmt->execute([':id' => $customerId]);
        $recent = $stmt->fetchAll();

        return [
            'customer' => [
                'id'         => $c['id'],
                'name'       => $c['full_name'],
                'phone'      => $c['phone'],
                'email'      => $c['email'],
                'status'     => $c['status'],
            ],
            'recent_orders' => $recent,
        ];
    }

    private static function toolDefinitions(): array
    {
        return [
            [
                'name' => 'lookup_order_status',
                'description' => 'Look up the status of an order by reference number (e.g. ORD-20260511...).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'order_reference' => [
                            'type'        => 'string',
                            'description' => 'The order reference (starts with ORD-).',
                        ],
                    ],
                    'required' => ['order_reference'],
                ],
            ],
            [
                'name' => 'check_delivery_zone',
                'description' => 'Check if a 4-digit South African postal code is in the Hi-Service delivery area.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'postal_code' => ['type' => 'string', 'description' => '4-digit postal code, e.g. 7140'],
                    ],
                    'required' => ['postal_code'],
                ],
            ],
            [
                'name' => 'book_appointment',
                'description' => 'Book a calendar appointment for the customer (installation, site visit, callback). Use only for non-delivery requests.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason'      => ['type' => 'string', 'description' => 'What the appointment is for'],
                        'preferred_date' => ['type' => 'string', 'description' => 'ISO date YYYY-MM-DD'],
                    ],
                    'required' => ['reason', 'preferred_date'],
                ],
            ],
            [
                'name' => 'escalate_to_human',
                'description' => 'Flag this conversation for a human team member. Use when angry/complex/policy questions, or when customer explicitly asks.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason'   => ['type' => 'string'],
                        'urgency'  => ['type' => 'string', 'enum' => ['normal','high']],
                    ],
                    'required' => ['reason'],
                ],
            ],
        ];
    }

    private static function executeTool(string $name, array $input, ?int $customerId): array
    {
        switch ($name) {
            case 'lookup_order_status':
                $ref = (string) ($input['order_reference'] ?? '');
                $o = OrderRepo::findByRef($ref);
                if (!$o) return ['found' => false, 'message' => "No order found with ref {$ref}"];
                return [
                    'found'  => true,
                    'reference' => $o['order_reference'],
                    'status' => $o['status'],
                    'total'  => (float) $o['total_amount'],
                    'paid_at' => $o['paid_at'],
                    'created_at' => $o['created_at'],
                ];

            case 'check_delivery_zone':
                $pc = (string) ($input['postal_code'] ?? '');
                $ok = CustomerRepo::postalCodeInZone($pc);
                return ['postal_code' => $pc, 'in_zone' => $ok];

            case 'book_appointment':
                if (!$customerId) return ['ok' => false, 'message' => 'Customer must be identified first.'];
                $c = CustomerRepo::findById($customerId);
                try {
                    $gid = GHL::syncCustomer($c);
                    if ($gid === '') return ['ok' => false, 'message' => 'Could not sync contact to GHL.'];

                    $iso = (string) ($input['preferred_date'] ?? '');
                    $startISO = $iso . 'T09:00:00+02:00';
                    $result = GHL::bookAppointment(GHL::DEFAULT_CALENDAR_GAS, $gid, $startISO, (string) ($input['reason'] ?? 'Hi-Service inquiry'));
                    return ['ok' => true, 'event' => $result];
                } catch (Throwable $e) {
                    return ['ok' => false, 'message' => $e->getMessage()];
                }

            case 'escalate_to_human':
                try {
                    $reason = (string) ($input['reason'] ?? 'AI flagged for review');
                    if ($customerId) {
                        $c = CustomerRepo::findById($customerId);
                        $gid = GHL::syncCustomer($c);
                        if ($gid) {
                            GHL::addTag($gid, ['escalation', 'urgency-' . ($input['urgency'] ?? 'normal')]);
                            GHL::notifyUser(GHL::USER_GAS, 'Customer needs help', $reason, $gid);
                        }
                    }
                    ClaudeAgent::logActivity([
                        'kind'        => 'escalation',
                        'severity'    => ($input['urgency'] ?? '') === 'high' ? 'warn' : 'info',
                        'title'       => 'Escalation: ' . substr($reason, 0, 60),
                        'summary'     => $reason,
                        'entity_type' => 'customer',
                        'entity_id'   => $customerId ? (string) $customerId : null,
                    ]);
                    return ['ok' => true, 'notified' => 'gas@hiservice.co.za'];
                } catch (Throwable $e) {
                    return ['ok' => false, 'message' => $e->getMessage()];
                }
        }
        return ['ok' => false, 'message' => "Unknown tool: {$name}"];
    }

    private static function callClaude(string $system, array $messages, array $tools): array
    {
        $apiKey = (string) env('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY missing — agent disabled');
        }

        $body = [
            'model'      => ClaudeAgent::MODEL_SMART,
            'max_tokens' => 1024,
            'temperature'=> 0.3,
            'system'     => [[
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'tools'    => $tools,
            'messages' => $messages,
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            log_to_file('general-help-agent', 'API error', ['code' => $code, 'err' => $err, 'resp' => substr((string)$resp, 0, 400)]);
            throw new RuntimeException("Claude API HTTP {$code}");
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) throw new RuntimeException('Bad Claude response');

        $usage = $data['usage'] ?? [];
        $inTok  = (int) ($usage['input_tokens']  ?? 0)
                + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                + (int) ($usage['cache_read_input_tokens']     ?? 0);
        $outTok = (int) ($usage['output_tokens'] ?? 0);
        $cost   = ($inTok / 1_000_000) * 3.00 + ($outTok / 1_000_000) * 15.00;

        return ['raw' => $data, 'cost' => $cost];
    }
}
