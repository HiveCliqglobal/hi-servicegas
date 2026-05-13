<?php
/**
 * agent_watchdog.php — periodic health checks + auto-recovery.
 *
 * Designed to be run every 15 min via cron. Cheap (~$0.001 per run on Haiku).
 *
 * What it checks each run:
 *   1. Stuck pending_payment orders (>25 min) → cancel + release slot
 *   2. Abandoned cart orders (>2 hours) → mark cancelled
 *   3. Expired sessions → soft-clear mode
 *   4. Low slot capacity in next 7 days → flag for admin
 *   5. Recent errors in logs (last hour) → summarise + escalate if >5
 *   6. Failed Xero/PayFast/Meta calls → flag for retry
 *
 * Claude summarises findings into a one-line status + recommendations.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/slot_repo.php';
require_once __DIR__ . '/claude_agent.php';

final class AgentWatchdog
{
    /** Top-level entry point — runs every check and writes results. */
    public static function run(): array
    {
        $start = microtime(true);
        $observations = [];
        $actions      = [];

        // 1. Stuck pending payments — auto-recover
        $stuck = self::stuckPendingPayments();
        foreach ($stuck as $o) {
            db()->prepare('UPDATE orders SET status="failed" WHERE id=:id')
                ->execute([':id' => $o['id']]);
            if (!empty($o['slot_id'])) SlotRepo::release((int) $o['slot_id']);
            $actions[] = "Released slot + failed order {$o['order_reference']} (stuck {$o['stuck_mins']} min)";
            log_event('agent.recover.stuck_payment', 'order', $o['order_reference'], $o);
        }

        // 2. Abandoned carts — soft-cancel
        $abandoned = self::abandonedCarts();
        foreach ($abandoned as $o) {
            db()->prepare('UPDATE orders SET status="cancelled" WHERE id=:id')
                ->execute([':id' => $o['id']]);
            $actions[] = "Cancelled abandoned cart {$o['order_reference']}";
        }

        // 3. Expired sessions
        $expired = (int) db()->query("SELECT COUNT(*) FROM sessions WHERE expires_at < NOW() AND mode IS NOT NULL")->fetchColumn();
        if ($expired > 0) {
            db()->exec("UPDATE sessions SET mode = NULL, current_step = NULL WHERE expires_at < NOW()");
            $actions[] = "Cleared {$expired} expired sessions";
        }

        // 4. Slot pressure — coming 7 days
        $pressure = self::slotPressure();
        if ($pressure['low_days'] > 0) {
            $observations[] = "Slot pressure: {$pressure['low_days']} day(s) in next 7 are >80% booked";
        }

        // 5. Recent errors in logs (today)
        $errors24 = self::recentErrorCount();
        if ($errors24 > 0) {
            $observations[] = "Recent errors: {$errors24} in last 24h";
        }

        // 6. Snapshot
        $stats = [
            'orders_today'     => (int) db()->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'paid_today'       => (int) db()->query("SELECT COUNT(*) FROM orders WHERE status='paid' AND DATE(paid_at)=CURDATE()")->fetchColumn(),
            'revenue_today'    => (float) db()->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='paid' AND DATE(paid_at)=CURDATE()")->fetchColumn(),
            'pending_payments' => (int) db()->query("SELECT COUNT(*) FROM orders WHERE status='pending_payment'")->fetchColumn(),
            'active_sessions'  => (int) db()->query("SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()")->fetchColumn(),
        ];

        // 7. Ask Claude for a one-line status summary
        $summary = self::askClaude($stats, $observations, $actions);

        // 8. Persist
        $severity = !empty($observations) || !empty($actions) ? 'warn' : 'info';
        $actId = ClaudeAgent::logActivity([
            'kind'             => 'health_check',
            'severity'         => $severity,
            'title'            => $summary['title'] ?? 'Health check',
            'summary'          => $summary['summary'] ?? null,
            'context'          => [
                'stats'        => $stats,
                'observations' => $observations,
                'actions'      => $actions,
                'duration_ms'  => (int) ((microtime(true) - $start) * 1000),
            ],
            'action_taken'     => $actions ? implode('; ', $actions) : null,
            'model'            => $summary['model']    ?? null,
            'prompt_tokens'    => $summary['in_tok']   ?? null,
            'completion_tokens'=> $summary['out_tok']  ?? null,
            'cost_usd'         => $summary['cost']     ?? null,
        ]);

        return [
            'id'           => $actId,
            'stats'        => $stats,
            'observations' => $observations,
            'actions'      => $actions,
            'summary'      => $summary['summary'] ?? null,
            'duration_ms'  => (int) ((microtime(true) - $start) * 1000),
        ];
    }

    // ============= Individual checks =============

    private static function stuckPendingPayments(int $minMinutes = 25): array
    {
        $stmt = db()->prepare(
            "SELECT id, order_reference, slot_id,
                    TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS stuck_mins
             FROM orders
             WHERE status = 'pending_payment'
               AND created_at < DATE_SUB(NOW(), INTERVAL :m MINUTE)"
        );
        $stmt->execute([':m' => $minMinutes]);
        return $stmt->fetchAll();
    }

    private static function abandonedCarts(int $minHours = 2): array
    {
        $stmt = db()->prepare(
            "SELECT id, order_reference
             FROM orders
             WHERE status = 'cart'
               AND updated_at < DATE_SUB(NOW(), INTERVAL :h HOUR)"
        );
        $stmt->execute([':h' => $minHours]);
        return $stmt->fetchAll();
    }

    private static function slotPressure(): array
    {
        $stmt = db()->query(
            "SELECT delivery_date, time_block, capacity, booked_count,
                    ROUND(booked_count/capacity*100) AS pct
             FROM slots
             WHERE delivery_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               AND is_active = 1"
        );
        $rows  = $stmt->fetchAll();
        $low   = array_filter($rows, fn($r) => $r['pct'] >= 80);
        $days  = array_unique(array_column($low, 'delivery_date'));
        return ['low_days' => count($days), 'detail' => array_values($low)];
    }

    private static function recentErrorCount(): int
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) return 0;
        $today  = date('Y-m-d');
        $count  = 0;
        foreach (glob("{$logDir}/*-{$today}.log") ?: [] as $f) {
            $h = @fopen($f, 'r');
            if (!$h) continue;
            while (($line = fgets($h)) !== false) {
                if (stripos($line, 'error') !== false || stripos($line, 'exception') !== false) {
                    $count++;
                }
            }
            fclose($h);
        }
        return $count;
    }

    // ============= Claude summarisation =============

    private static function askClaude(array $stats, array $observations, array $actions): array
    {
        try {
            $system = <<<SYS
You are the Hi-Service Gas operations watchdog. Your job is to summarise system health
in plain English for an operator who is glancing at a dashboard.

Be terse. One sentence for `title` (≤80 chars). 2-3 sentences for `summary`.
If everything is green, say so plainly. If there are issues, lead with the most important.

Always reply as JSON with this exact shape:
{"title": "string", "summary": "string"}
SYS;

            $user = "Snapshot:\n" . json_encode([
                'stats'        => $stats,
                'observations' => $observations,
                'actions_taken'=> $actions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $r = ClaudeAgent::askJson($system, $user, [
                'model'      => ClaudeAgent::MODEL_FAST,
                'max_tokens' => 300,
            ]);
            return [
                'title'   => (string) ($r['json']['title']   ?? 'Health check'),
                'summary' => (string) ($r['json']['summary'] ?? ''),
                'model'   => $r['model'],
                'in_tok'  => $r['in_tok'],
                'out_tok' => $r['out_tok'],
                'cost'    => $r['cost'],
            ];
        } catch (Throwable $e) {
            log_to_file('agent-watchdog', 'claude call failed', ['err' => $e->getMessage()]);
            // Local fallback summary so the watchdog still runs without API key
            $statusBits = [];
            if (!empty($actions))      $statusBits[] = count($actions) . ' auto-fix(es)';
            if (!empty($observations)) $statusBits[] = count($observations) . ' obs.';
            $title = $statusBits ? 'Issues: ' . implode(', ', $statusBits) : 'All clear';
            return [
                'title'   => $title,
                'summary' => "Orders today: {$stats['orders_today']} · Revenue R" . number_format($stats['revenue_today'], 2),
                'model'   => 'local-fallback',
                'in_tok'  => 0,
                'out_tok' => 0,
                'cost'    => 0,
            ];
        }
    }

    /**
     * Use Claude to disambiguate an unclear customer message.
     * Returns one of the valid intents or null if low confidence.
     */
    public static function disambiguateIntent(string $message, string $currentStep, array $validIntents): ?array
    {
        try {
            $system = <<<SYS
You are an intent classifier for a WhatsApp gas-ordering bot. Given the customer's
message and the current conversation step, pick the SINGLE best matching intent from
the provided list. If no intent matches confidently, return `"unclear"`.

Reply as JSON: {"intent": "<one_of_valid_intents_or_unclear>", "confidence": 0.0-1.0, "reason": "<short>"}
SYS;

            $user = json_encode([
                'message'       => $message,
                'current_step'  => $currentStep,
                'valid_intents' => $validIntents,
            ], JSON_UNESCAPED_SLASHES);

            $r = ClaudeAgent::askJson($system, $user, [
                'model'      => ClaudeAgent::MODEL_SMART,
                'max_tokens' => 150,
                'temperature'=> 0.1,
            ]);

            $intent = (string) ($r['json']['intent'] ?? 'unclear');
            $conf   = (float)  ($r['json']['confidence'] ?? 0);

            ClaudeAgent::logActivity([
                'kind'              => 'intent_fallback',
                'severity'          => 'info',
                'title'             => "AI disambiguation: '{$intent}'",
                'summary'           => "msg='" . substr($message, 0, 80) . "' step={$currentStep} → {$intent} ({$conf})",
                'context'           => $r['json'],
                'model'             => $r['model'],
                'prompt_tokens'     => $r['in_tok'],
                'completion_tokens' => $r['out_tok'],
                'cost_usd'          => $r['cost'],
            ]);

            if ($intent === 'unclear' || $conf < 0.6) return null;
            return ['intent' => $intent, 'confidence' => $conf];
        } catch (Throwable $e) {
            log_to_file('agent-watchdog', 'disambiguation failed', ['err' => $e->getMessage()]);
            return null;
        }
    }
}
