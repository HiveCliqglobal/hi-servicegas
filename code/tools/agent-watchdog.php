<?php
/**
 * tools/agent-watchdog.php — cron entry point.
 *
 * Add to cPanel cron, every 15 min:
 *   (every-15-min cron) /opt/alt/php81/usr/bin/php /home/hiserviceshopz/public_html/tools/agent-watchdog.php >/dev/null 2>&1
 *   i.e. minute field is "slash15", hour/day/month/dow are all star
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only.';
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent_watchdog.php';

try {
    $r = AgentWatchdog::run();
    fwrite(STDOUT, "[" . date('c') . "] watchdog #{$r['id']} ok · " .
        "obs=" . count($r['observations']) .
        " act=" . count($r['actions']) .
        " · " . ($r['summary'] ?? '') . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('c') . "] watchdog FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
