<?php
/**
 * api/webhook/whatsapp.php
 *
 * Meta WhatsApp Cloud API webhook receiver.
 *
 *   GET  → Meta verification handshake during webhook setup.
 *   POST → Inbound messages, statuses, etc. Signature-verified.
 *
 * Inbound message flow (POST):
 *   1. Verify X-Hub-Signature-256 against META_APP_SECRET
 *   2. Log raw payload to conversations table
 *   3. For each text message: route to state machine + send reply
 *   4. Return 200 immediately (Meta retries on non-200)
 *
 * Stage 3 day 5 — initial verifier in place. Routing logic added once
 * a verified test message round-trips.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

// ════════════ GET — Meta verification handshake ════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = (string) ($_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '');
    $token     = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '');

    $expected = (string) env('META_VERIFY_TOKEN', '');

    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
        log_to_file('whatsapp', 'webhook verified', ['challenge' => $challenge]);
        log_event('meta.webhook.verified');
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    log_to_file('whatsapp', 'verification rejected', ['mode' => $mode, 'token_match' => hash_equals($expected, $token)]);
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ════════════ POST — inbound message / status ════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = (string) file_get_contents('php://input');

    // Verify signature (Meta signs with SHA256 HMAC of body using APP_SECRET)
    $appSecret = (string) env('META_APP_SECRET', '');
    $headerSig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $expectedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

    $sigOk = $appSecret !== '' && $headerSig !== '' && hash_equals($expectedSig, $headerSig);

    log_to_file('whatsapp', 'POST received', [
        'sig_ok' => $sigOk,
        'body'   => substr($rawBody, 0, 1200),
    ]);

    // Even on signature failure we return 200 to Meta — never let it retry.
    // Log + alert via watchdog later.
    if (!$sigOk) {
        log_event('meta.webhook.sig_failed');
        echo 'OK';
        exit;
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        echo 'OK';
        exit;
    }

    try {
        // Walk Meta's nested payload structure
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                foreach ($messages as $msg) {
                    $from   = (string) ($msg['from'] ?? '');
                    $type   = (string) ($msg['type'] ?? '');
                    $text   = '';
                    if ($type === 'text') $text = (string) ($msg['text']['body'] ?? '');
                    elseif ($type === 'interactive') $text = (string) ($msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? '');

                    // Log every inbound message
                    db()->prepare(
                        "INSERT INTO conversations (phone, direction, channel, message_text, payload_json)
                         VALUES (:p, 'in', 'whatsapp', :t, :j)"
                    )->execute([
                        ':p' => $from,
                        ':t' => $text,
                        ':j' => json_encode($msg, JSON_UNESCAPED_SLASHES),
                    ]);

                    log_event('meta.message.in', null, $from, ['type' => $type, 'preview' => substr($text, 0, 100)]);

                    // TODO Stage 3 routing: feed into state_machine + send reply via wa_send_text()
                    // For now, this just logs — full routing once a test message round-trips successfully.
                }

                // Also log statuses (delivered / read) for outbound messages
                foreach (($value['statuses'] ?? []) as $st) {
                    log_event('meta.message.status', null, (string) ($st['recipient_id'] ?? ''), [
                        'status' => $st['status']   ?? null,
                        'id'     => $st['id']       ?? null,
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        log_to_file('whatsapp', 'POST processing exception', [
            'err' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
    }

    echo 'OK';
    exit;
}

http_response_code(405);
echo 'Method not allowed';
