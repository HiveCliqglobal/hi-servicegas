<?php
/**
 * logger.php — file + DB logger.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Append a line to logs/<channel>-YYYY-MM-DD.log.
 */
function log_to_file(string $channel, string $message, array $context = []): void
{
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $filename = "{$logDir}/{$channel}-" . date('Y-m-d') . '.log';
    $line = sprintf(
        "[%s] %s %s\n",
        date('c'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Insert an audit event into event_log.
 */
function log_event(
    string  $action,
    ?string $entityType = null,
    ?string $entityId = null,
    ?array  $payload = null,
    ?int    $userId = null
): void {
    try {
        db()->prepare(
            'INSERT INTO event_log (user_id, action, entity_type, entity_id, payload, ip_address)
             VALUES (:uid, :a, :et, :ei, :p, :ip)'
        )->execute([
            ':uid' => $userId ?? ($_SESSION['user_id'] ?? null),
            ':a'   => $action,
            ':et'  => $entityType,
            ':ei'  => $entityId,
            ':p'   => $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        log_to_file('logger-error', 'event_log insert failed', ['err' => $e->getMessage()]);
    }
}

/**
 * Log + return a uniform error response (for webhook entrypoints).
 */
function fail_quiet(string $channel, Throwable $e): array
{
    log_to_file($channel, 'exception', [
        'msg'   => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    return ['ok' => false, 'error' => 'internal_error'];
}
