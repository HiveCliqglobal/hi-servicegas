<?php
/**
 * wa.php — Meta WhatsApp Cloud API sender.
 *
 * Outbound message helpers for Hi-Service. All replies, confirmations,
 * notifications go through here. Inbound is handled by the webhook at
 * api/webhook/whatsapp.php.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

final class WA
{
    public const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    public static function isConfigured(): bool
    {
        return env('META_SYSTEM_USER_TOKEN') && env('META_PHONE_NUMBER_ID');
    }

    /**
     * Normalize a phone to the international format Meta expects:
     * digits only, country code prefixed, no + or spaces or dashes.
     *
     *   0821234567       → 27821234567   (SA local → international)
     *   +27 82 123 4567  → 27821234567
     *   27821234567      → 27821234567   (already correct)
     */
    public static function normalizePhone(string $raw, string $defaultCountry = '27'): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if ($digits === '' || $digits === null) return '';
        // SA local format (starts with 0) → swap leading 0 for country code
        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return $defaultCountry . substr($digits, 1);
        }
        return $digits;
    }

    /**
     * Send a plain text WhatsApp message.
     *
     *   $to     phone in any format — normalised internally
     *   $body   message body (max 4096 chars, Meta truncates beyond)
     *
     * Returns Meta's response array. Throws on transport or HTTP error.
     */
    public static function sendText(string $to, string $body): array
    {
        $to = self::normalizePhone($to);
        if ($to === '') throw new RuntimeException('WA::sendText — empty recipient phone.');

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['body' => $body],
        ];

        $resp = self::post('/' . env('META_PHONE_NUMBER_ID') . '/messages', $payload);
        self::logOutbound($to, $body, 'text', $resp);
        return $resp;
    }

    /**
     * Send a pre-approved WhatsApp template (used for utility / marketing
     * messages outside the 24-hour customer service window).
     *
     *   $template  name of the approved template (e.g. "order_confirmed")
     *   $lang      language code (e.g. "en_US")
     *   $components see Meta docs — body params, header media, buttons
     */
    public static function sendTemplate(string $to, string $template, string $lang = 'en_US', array $components = []): array
    {
        $to = self::normalizePhone($to);
        if ($to === '') throw new RuntimeException('WA::sendTemplate — empty recipient phone.');

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template' => [
                'name'     => $template,
                'language' => ['code' => $lang],
            ],
        ];
        if (!empty($components)) $payload['template']['components'] = $components;

        $resp = self::post('/' . env('META_PHONE_NUMBER_ID') . '/messages', $payload);
        self::logOutbound($to, "[template:$template]", 'template', $resp);
        return $resp;
    }

    /**
     * Send a media attachment by URL (image, document, video, audio).
     *
     *   $kind    "document" | "image" | "video" | "audio"
     *   $url     publicly reachable HTTPS URL Meta can fetch
     *   $caption optional caption (image/video only — ignored for audio)
     *   $name    filename for documents (recommended for PDF invoices)
     */
    public static function sendMedia(string $to, string $kind, string $url, string $caption = '', string $name = ''): array
    {
        $to = self::normalizePhone($to);
        if ($to === '') throw new RuntimeException('WA::sendMedia — empty recipient phone.');
        if (!in_array($kind, ['document','image','video','audio'], true)) {
            throw new RuntimeException("WA::sendMedia — invalid kind '$kind'");
        }

        $media = ['link' => $url];
        if ($caption !== '' && $kind !== 'audio') $media['caption'] = $caption;
        if ($name !== '' && $kind === 'document') $media['filename'] = $name;

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $kind,
            $kind               => $media,
        ];

        $resp = self::post('/' . env('META_PHONE_NUMBER_ID') . '/messages', $payload);
        self::logOutbound($to, "[$kind:$url]", $kind, $resp);
        return $resp;
    }

    // ═════════ Internals ═════════

    private static function logOutbound(string $to, string $preview, string $type, array $resp): void
    {
        try {
            db()->prepare(
                "INSERT INTO conversations (phone, direction, channel, message_text, payload_json)
                 VALUES (:p, 'out', 'whatsapp', :t, :j)"
            )->execute([
                ':p' => $to,
                ':t' => substr($preview, 0, 4000),
                ':j' => json_encode(['type' => $type, 'meta_response' => $resp], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'outbound log INSERT failed', ['err' => $e->getMessage()]);
        }
        log_event('meta.message.out', null, $to, ['type' => $type, 'preview' => substr($preview, 0, 100)]);
    }

    private static function post(string $path, array $body): array
    {
        $token = (string) env('META_SYSTEM_USER_TOKEN');
        if ($token === '') throw new RuntimeException('META_SYSTEM_USER_TOKEN not set — paste into /admin/connections.php');

        $ch = curl_init(self::GRAPH_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("WA cURL error: {$err}");
        }
        $data = json_decode((string) $raw, true) ?: [];
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? substr((string) $raw, 0, 300);
            log_to_file('whatsapp', "outbound HTTP $code", ['payload' => $body, 'response' => $data]);
            throw new RuntimeException("Meta HTTP {$code}: {$msg}");
        }
        return $data;
    }
}
