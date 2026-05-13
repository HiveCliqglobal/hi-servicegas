<?php
/**
 * ghl.php — GoHighLevel sync layer.
 *
 * GHL is the DESTINATION (CRM), not the brain.
 * Used for: contacts, tags, custom fields, calendar bookings, internal notifications.
 *
 * All Claude reasoning happens in our backend; we only PUSH facts to GHL.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

final class GHL
{
    private const BASE = 'https://services.leadconnectorhq.com';

    /** Hi-Service known IDs (discovered 2026-05-11). */
    public const PIPELINE_GAS_INSTALLATIONS = 'pt0XYbLz1Cd4TuDSKP5W';

    public const USER_FREDDIE  = 'xAG8PvKZ1xRUrkb7u7PC'; // owner
    public const USER_ADMIN    = '614nq8a1C00RyWduKOtR'; // Shirley · admin@hiservice.co.za
    public const USER_GAS      = '2Kiq3VLWCsyoASK7Kg2e'; // Eldin · gas@hiservice.co.za
    public const USER_ACCOUNTS = 'dKgJs3c5bdtCoqGxIbt8'; // Sunelle · accounts@hiservice.co.za

    /** Whichever user/team calendar the AI books into by default. */
    public const DEFAULT_CALENDAR_GAS = '36O7GC9xxxG326nFyDnb'; // Andre Van Dyk (placeholder)

    // ====================================================
    // Low-level HTTP wrapper
    // ====================================================

    private static function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $token = (string) env('GHL_PRIVATE_TOKEN', '');
        if ($token === '') {
            throw new RuntimeException('GHL_PRIVATE_TOKEN missing — set in .env');
        }
        $version = (string) env('GHL_API_VERSION', '2021-07-28');

        $url = self::BASE . $path;
        if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Version: ' . $version,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException("GHL curl error: {$err}");
        }
        $data = json_decode($resp, true);

        log_to_file('ghl', "$method $path → $code", ['body_keys' => is_array($body) ? array_keys($body) : null]);

        if ($code >= 400) {
            log_to_file('ghl-error', "$method $path → $code", ['resp' => substr((string)$resp, 0, 600)]);
            throw new RuntimeException("GHL HTTP {$code}: " . substr((string)$resp, 0, 300));
        }
        return is_array($data) ? $data : [];
    }

    // ====================================================
    // Contacts
    // ====================================================

    /**
     * Find a contact by phone (E.164, no plus).
     * Returns the first match or null.
     */
    public static function findContactByPhone(string $phone): ?array
    {
        try {
            $r = self::request('GET', '/contacts/search/duplicate', null, [
                'locationId' => (string) env('GHL_LOCATION_ID'),
                'phone'      => '+' . ltrim($phone, '+'),
            ]);
            return $r['contact'] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Find-or-create a contact. Returns the contact ID.
     *
     * @param array $data  phone, firstName, lastName, email, address1, city, postalCode, customFields[]
     */
    public static function upsertContact(array $data): string
    {
        $phone = '+' . ltrim((string) ($data['phone'] ?? ''), '+');
        $body = [
            'locationId' => (string) env('GHL_LOCATION_ID'),
            'phone'      => $phone,
            'firstName'  => $data['firstName']  ?? null,
            'lastName'   => $data['lastName']   ?? null,
            'name'       => $data['name']       ?? null,
            'email'      => $data['email']      ?? null,
            'address1'   => $data['address1']   ?? null,
            'city'       => $data['city']       ?? null,
            'postalCode' => $data['postalCode'] ?? null,
            'country'    => 'ZA',
            'source'     => 'hiservice.store',
        ];
        // POST /contacts/upsert returns the contact record + isNew flag
        $r = self::request('POST', '/contacts/upsert', array_filter($body, fn($v) => $v !== null));
        return (string) ($r['contact']['id'] ?? '');
    }

    public static function addTag(string $contactId, string|array $tags): void
    {
        $tagsArr = is_array($tags) ? $tags : [$tags];
        self::request('POST', "/contacts/{$contactId}/tags", ['tags' => $tagsArr]);
    }

    public static function getContact(string $contactId): array
    {
        $r = self::request('GET', "/contacts/{$contactId}");
        return $r['contact'] ?? $r;
    }

    // ====================================================
    // Conversations — log the customer interaction in GHL
    // ====================================================

    /**
     * Add a message to a contact's conversation history (informational only —
     * does not send anything through GHL channels).
     */
    public static function logConversation(string $contactId, string $direction, string $body): void
    {
        try {
            self::request('POST', '/conversations/messages', [
                'type'        => 'Custom',           // free-form note
                'contactId'   => $contactId,
                'message'     => $body,
                'direction'   => $direction === 'in' ? 'inbound' : 'outbound',
                'locationId'  => (string) env('GHL_LOCATION_ID'),
            ]);
        } catch (Throwable $e) {
            // Non-critical — log and move on
            log_to_file('ghl', 'logConversation failed', ['err' => $e->getMessage()]);
        }
    }

    // ====================================================
    // Calendar booking
    // ====================================================

    /**
     * Get available slots for a calendar between two ISO dates.
     */
    public static function getCalendarSlots(string $calendarId, string $startDate, string $endDate): array
    {
        $r = self::request('GET', "/calendars/{$calendarId}/free-slots", null, [
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ]);
        return $r['slots'] ?? $r;
    }

    /**
     * Book an appointment.
     */
    public static function bookAppointment(string $calendarId, string $contactId, string $startTimeISO, ?string $title = null): array
    {
        return self::request('POST', '/calendars/events/appointments', [
            'calendarId'       => $calendarId,
            'locationId'       => (string) env('GHL_LOCATION_ID'),
            'contactId'        => $contactId,
            'startTime'        => $startTimeISO,
            'title'            => $title ?? 'Hi-Service inquiry',
            'appointmentStatus'=> 'confirmed',
        ]);
    }

    // ====================================================
    // Opportunities (pipeline tracking)
    // ====================================================

    public static function createOpportunity(string $contactId, string $pipelineId, string $stageId, string $title, float $value = 0): array
    {
        return self::request('POST', '/opportunities/', [
            'pipelineId'      => $pipelineId,
            'locationId'      => (string) env('GHL_LOCATION_ID'),
            'name'            => $title,
            'pipelineStageId' => $stageId,
            'status'          => 'open',
            'contactId'       => $contactId,
            'monetaryValue'   => $value,
        ]);
    }

    // ====================================================
    // Notifications / escalations
    // ====================================================

    /**
     * Send an internal notification to a GHL user (appears in their notification feed).
     * We use this for escalations from the AI.
     */
    public static function notifyUser(string $userId, string $title, string $body, ?string $contactId = null): void
    {
        // GHL doesn't expose a public "notify user" API; the standard pattern is:
        //   - Create a task on the user
        //   - OR drop a note on the contact (visible in their inbox)
        try {
            if ($contactId) {
                self::request('POST', "/contacts/{$contactId}/notes", [
                    'body'   => "🤖 AI escalation: {$title}\n\n{$body}",
                    'userId' => $userId,
                ]);
            }
        } catch (Throwable $e) {
            log_to_file('ghl', 'notifyUser failed', ['err' => $e->getMessage()]);
        }
    }

    // ====================================================
    // High-level sync — called when our DB changes
    // ====================================================

    /**
     * Sync a Hi-Service customer to GHL. Idempotent — safe to call repeatedly.
     * Returns the GHL contact ID.
     */
    public static function syncCustomer(array $customer, ?array $address = null): string
    {
        $nameParts = preg_split('/\s+/', trim((string) ($customer['full_name'] ?? ''))) ?: [''];
        $first = $nameParts[0] ?? '';
        $last  = implode(' ', array_slice($nameParts, 1));

        $payload = [
            'phone'      => $customer['phone'],
            'firstName'  => $first,
            'lastName'   => $last,
            'name'       => $customer['full_name'] ?? null,
            'email'      => $customer['email'] ?? null,
        ];
        if ($address) {
            $payload['address1']   = trim(($address['line1'] ?? '') . (empty($address['line2']) ? '' : ', ' . $address['line2']));
            $payload['city']       = $address['city'] ?? null;
            $payload['postalCode'] = $address['postal_code'] ?? null;
        }
        $gid = self::upsertContact($payload);

        // Auto-tag customer source
        if ($gid !== '') {
            self::addTag($gid, ['hi-service-customer', 'source-web']);
        }
        return $gid;
    }

    /**
     * Called when an order moves to "paid". Pushes a tag + opportunity into GHL.
     */
    public static function syncPaidOrder(array $order, array $customer): void
    {
        try {
            $gid = self::syncCustomer($customer);
            if ($gid === '') return;
            self::addTag($gid, ['order-paid', 'channel-' . ($order['channel'] ?? 'web')]);

            // Optional: also create an opportunity in the gas pipeline
            // self::createOpportunity($gid, self::PIPELINE_GAS_INSTALLATIONS, '<stageId>', "Order {$order['order_reference']}", (float) $order['total_amount']);
        } catch (Throwable $e) {
            log_to_file('ghl', 'syncPaidOrder failed', ['err' => $e->getMessage()]);
        }
    }
}
