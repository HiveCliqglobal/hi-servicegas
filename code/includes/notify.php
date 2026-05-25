<?php
/**
 * notify.php — provider-agnostic WhatsApp dispatch layer.
 *
 * Lets the rest of the codebase send WhatsApp messages without caring
 * whether Meta direct or Twilio is in front of the wire.
 *
 *   Provider selection (in priority order):
 *     1. $forceProvider arg                 (per-call override)
 *     2. .env WA_PROVIDER var               ('meta' | 'twilio')
 *     3. Whichever provider is configured   (auto-detect)
 *     4. Throw — neither configured
 *
 * Adding a third provider later (e.g. 360dialog) = add a case here +
 * a new dXXXdialog.php helper. Nothing else changes.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/wa.php';
require_once __DIR__ . '/twilio.php';

/**
 * Send a plain text WhatsApp message via the active provider.
 *
 *   $to              phone in any format (082… / +27… / 27…)
 *   $body            message body
 *   $forceProvider   optional 'meta' | 'twilio' to override env config
 *
 * Returns ['provider' => 'meta'|'twilio', 'response' => array]
 * Throws on configuration error OR transport error.
 */
function notify_send_text(string $to, string $body, ?string $forceProvider = null): array
{
    $provider = notify_resolve_provider($forceProvider);

    switch ($provider) {
        case 'twilio':
            $resp = Twilio::sendText($to, $body);
            return ['provider' => 'twilio', 'response' => $resp];

        case 'meta':
            $resp = WA::sendText($to, $body);
            return ['provider' => 'meta', 'response' => $resp];

        default:
            throw new RuntimeException("Unknown WA provider: {$provider}");
    }
}

/**
 * Send a media attachment via the active provider.
 *   $kind = "document" | "image" | "video" | "audio"
 */
function notify_send_media(string $to, string $kind, string $url, string $caption = '', string $name = '', ?string $forceProvider = null): array
{
    $provider = notify_resolve_provider($forceProvider);

    switch ($provider) {
        case 'twilio':
            $resp = Twilio::sendMedia($to, $kind, $url, $caption, $name);
            return ['provider' => 'twilio', 'response' => $resp];

        case 'meta':
            $resp = WA::sendMedia($to, $kind, $url, $caption, $name);
            return ['provider' => 'meta', 'response' => $resp];

        default:
            throw new RuntimeException("Unknown WA provider: {$provider}");
    }
}

/**
 * Resolve which provider should handle this send.
 * Priority: $force arg > .env WA_PROVIDER > auto-detect configured.
 */
function notify_resolve_provider(?string $force = null): string
{
    if ($force === 'meta' || $force === 'twilio') return $force;

    $envChoice = strtolower((string) env('WA_PROVIDER', ''));
    if ($envChoice === 'meta' || $envChoice === 'twilio') {
        // Sanity-check the explicit choice is actually configured
        if ($envChoice === 'meta' && !WA::isConfigured()) {
            log_to_file('whatsapp', 'WA_PROVIDER=meta but Meta is not configured — falling back');
        } elseif ($envChoice === 'twilio' && !Twilio::isConfigured()) {
            log_to_file('whatsapp', 'WA_PROVIDER=twilio but Twilio is not configured — falling back');
        } else {
            return $envChoice;
        }
    }

    // Auto-detect: prefer Twilio if both configured (TEST phase default during build).
    if (Twilio::isConfigured()) return 'twilio';
    if (WA::isConfigured())     return 'meta';

    throw new RuntimeException('Neither Meta nor Twilio is configured. Visit /admin/connections.php');
}

/** Helper for admin UI — tells the dashboard which provider is currently active. */
function notify_active_provider(): array
{
    try {
        $active = notify_resolve_provider();
        $reason = strtolower((string) env('WA_PROVIDER', '')) === $active
            ? 'set via WA_PROVIDER env flag'
            : 'auto-detected (only configured option)';
        return ['provider' => $active, 'reason' => $reason, 'ok' => true];
    } catch (Throwable $e) {
        return ['provider' => null, 'reason' => $e->getMessage(), 'ok' => false];
    }
}
