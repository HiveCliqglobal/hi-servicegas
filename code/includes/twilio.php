<?php
/**
 * twilio.php — Twilio WhatsApp API sender + inbound parser.
 *
 * Mirrors the shape of wa.php (Meta) so the dispatcher can route through
 * either provider without the rest of the code caring.
 *
 * Twilio API reference:
 *   - Send:  POST https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json
 *            Auth: HTTP Basic (sid : auth_token)
 *            Body (form-encoded): To, From, Body
 *            To/From use the format whatsapp:+E164  (e.g. whatsapp:+27821234567)
 *   - Inbound webhook: Twilio POSTs form-encoded data with X-Twilio-Signature
 *            header. Validate with HMAC-SHA1 of (full URL + sorted POST params)
 *            using Auth Token as key, base64 encoded.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

final class Twilio
{
    public const API_BASE = 'https://api.twilio.com/2010-04-01';

    public static function isConfigured(): bool
    {
        return env('TWILIO_ACCOUNT_SID') && env('TWILIO_AUTH_TOKEN') && env('TWILIO_WHATSAPP_FROM');
    }

    /**
     * Normalise a phone to digits only — internal storage format.
     *   0821234567       → 27821234567
     *   +27 82 123 4567  → 27821234567
     *   whatsapp:+27821234567 → 27821234567
     */
    public static function normalizePhone(string $raw, string $defaultCountry = '27'): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if ($digits === '' || $digits === null) return '';
        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return $defaultCountry . substr($digits, 1);
        }
        return $digits;
    }

    /** Twilio's WhatsApp address format: "whatsapp:+E164" */
    public static function toWhatsAppAddress(string $rawPhone): string
    {
        $digits = self::normalizePhone($rawPhone);
        return 'whatsapp:+' . $digits;
    }

    /**
     * Send a plain text WhatsApp message via Twilio.
     *
     *   $to     phone in any format — normalised + wrapped internally
     *   $body   message body (max 1600 chars on WhatsApp; Twilio splits longer)
     *
     * Returns Twilio's response array. Throws on transport or HTTP error.
     */
    public static function sendText(string $to, string $body): array
    {
        $toAddr = self::toWhatsAppAddress($to);
        if ($toAddr === 'whatsapp:+') {
            throw new RuntimeException('Twilio::sendText — empty recipient phone.');
        }

        $resp = self::post('Messages.json', [
            'To'   => $toAddr,
            'From' => (string) env('TWILIO_WHATSAPP_FROM'),
            'Body' => $body,
        ]);

        self::logOutbound(self::normalizePhone($to), $body, 'text', $resp);
        return $resp;
    }

    /**
     * Send a media WhatsApp message (image / document / video / audio).
     *
     *   $kind    "document" | "image" | "video" | "audio"  (Twilio handles via MediaUrl)
     *   $url     publicly reachable HTTPS URL Twilio can fetch
     *   $caption optional caption text
     *
     * Twilio uses a single MediaUrl parameter regardless of media type.
     */
    public static function sendMedia(string $to, string $kind, string $url, string $caption = '', string $name = ''): array
    {
        $toAddr = self::toWhatsAppAddress($to);
        if ($toAddr === 'whatsapp:+') {
            throw new RuntimeException('Twilio::sendMedia — empty recipient phone.');
        }

        $params = [
            'To'       => $toAddr,
            'From'     => (string) env('TWILIO_WHATSAPP_FROM'),
            'MediaUrl' => $url,
        ];
        if ($caption !== '') $params['Body'] = $caption;

        $resp = self::post('Messages.json', $params);
        self::logOutbound(self::normalizePhone($to), "[$kind:$url]", $kind, $resp);
        return $resp;
    }

    /**
     * Validate the X-Twilio-Signature header on inbound webhook requests.
     *
     * Algorithm:
     *   1. Take the full URL the request hit (including query string)
     *   2. Sort POST params alphabetically by key
     *   3. Append each "keyvalue" (concatenated) to the URL
     *   4. HMAC-SHA1 the result with Auth Token as key
     *   5. Base64-encode the binary digest
     *   6. Compare with the X-Twilio-Signature header (hash_equals)
     *
     * Ref: https://www.twilio.com/docs/usage/security#validating-requests
     */
    public static function validateSignature(string $fullUrl, array $postParams, string $headerSignature): bool
    {
        $authToken = (string) env('TWILIO_AUTH_TOKEN');
        if ($authToken === '' || $headerSignature === '') return false;

        ksort($postParams);
        $data = $fullUrl;
        foreach ($postParams as $k => $v) {
            $data .= $k . $v;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
        return hash_equals($expected, $headerSignature);
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
                ':j' => json_encode(['provider' => 'twilio', 'type' => $type, 'twilio_response' => $resp], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            log_to_file('whatsapp', 'twilio outbound log INSERT failed', ['err' => $e->getMessage()]);
        }
        log_event('twilio.message.out', null, $to, ['type' => $type, 'preview' => substr($preview, 0, 100), 'sid' => $resp['sid'] ?? null]);
    }

    private static function post(string $path, array $body): array
    {
        $sid   = (string) env('TWILIO_ACCOUNT_SID');
        $token = (string) env('TWILIO_AUTH_TOKEN');
        if ($sid === '' || $token === '') {
            throw new RuntimeException('Twilio credentials missing — paste into /admin/connections.php');
        }

        $url = self::API_BASE . '/Accounts/' . $sid . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_USERPWD        => $sid . ':' . $token,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Twilio cURL error: {$err}");
        }
        $data = json_decode((string) $raw, true) ?: [];
        if ($code >= 400) {
            $msg = $data['message'] ?? substr((string) $raw, 0, 300);
            log_to_file('whatsapp', "twilio HTTP $code", ['payload' => $body, 'response' => $data]);
            throw new RuntimeException("Twilio HTTP {$code}: {$msg}");
        }
        return $data;
    }
}
