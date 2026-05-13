<?php
/**
 * claude_agent.php — Anthropic API wrapper for the Hi-Service watchdog.
 *
 * Two-model strategy:
 *  - Routine health checks → Haiku (fast + cheap, ~$0.001/run)
 *  - Intent fallback + complex reasoning → Sonnet
 *
 * Uses prompt caching on system prompts to cut cost by ~90% on repeated runs.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

final class ClaudeAgent
{
    public const MODEL_FAST   = 'claude-haiku-4-5';
    public const MODEL_SMART  = 'claude-sonnet-4-5';

    // Pricing per million tokens (USD) — keep in sync with anthropic.com/pricing
    private const PRICES = [
        'claude-haiku-4-5'  => ['in' => 0.80, 'out' => 4.00],
        'claude-sonnet-4-5' => ['in' => 3.00, 'out' => 15.00],
    ];

    /**
     * Single-turn Claude call.
     *
     * @param string $systemPrompt  Cached system context — kept stable for cache hits
     * @param string $userMessage   The varying input each call
     * @param array  $opts          model, max_tokens, json_only, temperature
     * @return array{text:string, model:string, in_tok:int, out_tok:int, cost:float, raw:array}
     * @throws RuntimeException     On API/network failure
     */
    public static function ask(string $systemPrompt, string $userMessage, array $opts = []): array
    {
        $apiKey = (string) env('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY missing — agent disabled');
        }

        $model    = $opts['model']       ?? self::MODEL_FAST;
        $maxTok   = (int) ($opts['max_tokens'] ?? 1024);
        $temp     = (float) ($opts['temperature'] ?? 0.2);
        $jsonOnly = (bool) ($opts['json_only'] ?? false);

        $body = [
            'model'      => $model,
            'max_tokens' => $maxTok,
            'temperature'=> $temp,
            'system'     => [[
                'type'          => 'text',
                'text'          => $systemPrompt,
                'cache_control' => ['type' => 'ephemeral'], // 90% discount on repeated reads
            ]],
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => $jsonOnly
                        ? $userMessage . "\n\nReply with ONLY valid JSON. No prose, no markdown fences."
                        : $userMessage,
                ],
            ],
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
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException("Claude API curl error: {$err}");
        }
        if ($httpCode >= 400) {
            log_to_file('claude-agent', 'api error', ['http' => $httpCode, 'body' => substr((string)$resp, 0, 600)]);
            throw new RuntimeException("Claude API HTTP {$httpCode}");
        }
        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data['content'])) {
            throw new RuntimeException('Claude API returned no content');
        }

        $text = '';
        foreach ($data['content'] as $blk) {
            if (($blk['type'] ?? '') === 'text') $text .= $blk['text'];
        }

        $usage = $data['usage'] ?? [];
        $inTok  = (int) ($usage['input_tokens']  ?? 0)
                + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                + (int) ($usage['cache_read_input_tokens']     ?? 0);
        $outTok = (int) ($usage['output_tokens'] ?? 0);

        $rates  = self::PRICES[$model] ?? self::PRICES[self::MODEL_FAST];
        $cost   = ($inTok / 1_000_000) * $rates['in'] + ($outTok / 1_000_000) * $rates['out'];

        return [
            'text'    => trim($text),
            'model'   => $model,
            'in_tok'  => $inTok,
            'out_tok' => $outTok,
            'cost'    => round($cost, 6),
            'raw'     => $data,
        ];
    }

    /**
     * Convenience: ask and parse JSON response.
     */
    public static function askJson(string $systemPrompt, string $userMessage, array $opts = []): array
    {
        $opts['json_only'] = true;
        $r = self::ask($systemPrompt, $userMessage, $opts);
        $text = $r['text'];
        // Strip code fences if model added them anyway
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) {
            log_to_file('claude-agent', 'json parse failed', ['raw' => $text]);
            throw new RuntimeException('Claude returned invalid JSON: ' . substr($text, 0, 200));
        }
        $r['json'] = $parsed;
        return $r;
    }

    /**
     * Record an agent observation/action to the agent_activity table.
     */
    public static function logActivity(array $row): int
    {
        $stmt = db()->prepare(
            'INSERT INTO agent_activity
             (kind, severity, title, summary, context_json, action_taken, entity_type, entity_id,
              model, prompt_tokens, completion_tokens, cost_usd)
             VALUES (:k, :sev, :t, :s, :c, :a, :et, :ei, :m, :pt, :ct, :cost)'
        );
        $stmt->execute([
            ':k'    => $row['kind']           ?? 'observation',
            ':sev'  => $row['severity']       ?? 'info',
            ':t'    => $row['title']          ?? '',
            ':s'    => $row['summary']        ?? null,
            ':c'    => isset($row['context']) ? json_encode($row['context'], JSON_UNESCAPED_SLASHES) : null,
            ':a'    => $row['action_taken']   ?? null,
            ':et'   => $row['entity_type']    ?? null,
            ':ei'   => $row['entity_id']      ?? null,
            ':m'    => $row['model']          ?? null,
            ':pt'   => $row['prompt_tokens']  ?? null,
            ':ct'   => $row['completion_tokens'] ?? null,
            ':cost' => $row['cost_usd']       ?? null,
        ]);
        return (int) db()->lastInsertId();
    }
}
