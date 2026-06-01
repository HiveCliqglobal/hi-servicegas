<?php
/**
 * xero-sync-products.php
 *
 * Cron-driven pull of Xero items into the local `products` table.
 * Runs every 15 min (see crontab). Keeps price, name, and stock counts fresh.
 *
 * Locked behaviour (from includes/xero_sync.php → XeroSync::syncItems):
 *   - New items default is_active=0 (admin must approve before customers see them)
 *   - Existing items: price, name, in_stock_qty, is_tracked refreshed
 *   - is_active flag PRESERVED across syncs (admin curation is canonical)
 *   - Items removed from Xero get flagged not deleted
 *
 * Net effect: stock counts shown to customers + used by ProductRepo::checkCartStock
 * are at most 15 minutes stale, but the admin's catalogue curation choices survive
 * every sync untouched.
 *
 * Log: /home/hiserviceshopz/public_html/logs/xero-sync-YYYY-MM-DD.log
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/xero.php';
require_once __DIR__ . '/../includes/xero_sync.php';

$startedAt = date('Y-m-d H:i:s');

try {
    if (!Xero::isConnected()) {
        log_to_file('xero-sync', 'skip — not connected', ['started_at' => $startedAt]);
        exit(0);
    }

    $result = XeroSync::syncItems();

    log_to_file('xero-sync', 'cron run', [
        'started_at' => $startedAt,
        'ok'         => $result['ok']      ?? false,
        'pulled'     => $result['pulled']  ?? 0,
        'created'    => $result['created'] ?? 0,
        'updated'    => $result['updated'] ?? 0,
        'orphans'    => $result['orphans'] ?? 0,
        'errors'     => $result['errors']  ?? [],
    ]);

    // Also record in event_log so the admin can audit recent syncs from the dashboard
    log_event(
        'cron.xero.products.synced',
        'system',
        null,
        [
            'pulled'   => $result['pulled']  ?? 0,
            'created'  => $result['created'] ?? 0,
            'updated'  => $result['updated'] ?? 0,
            'orphans'  => $result['orphans'] ?? 0,
            'ok'       => (bool) ($result['ok'] ?? false),
        ]
    );

    if (!($result['ok'] ?? false) || !empty($result['errors'])) {
        fwrite(STDERR, "xero-sync errors: " . implode('; ', $result['errors']) . "\n");
        exit(1);
    }

    exit(0);

} catch (Throwable $e) {
    log_to_file('xero-sync', 'fatal', [
        'started_at' => $startedAt,
        'err'        => $e->getMessage(),
        'file'       => $e->getFile(),
        'line'       => $e->getLine(),
    ]);
    fwrite(STDERR, "xero-sync fatal: " . $e->getMessage() . "\n");
    exit(2);
}
