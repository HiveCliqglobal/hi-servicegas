<?php
/**
 * api/webhook/whatsapp.php
 *
 * DUAL-MODE WhatsApp webhook receiver — handles inbound from EITHER:
 *   - Meta Cloud API direct  (JSON, nested entry/changes/messages, X-Hub-Signature-256)
 *   - Twilio WhatsApp        (form-encoded From/Body/MessageSid, X-Twilio-Signature)
 *
 * Both providers post to this same URL. The handler auto-detects which one
 * by inspecting the request and dispatches accordingly. Each is signature-
 * verified independently using its own secret. Both end at the same echo
 * stub (and eventually the same state-machine routing).
 *
 *   GET  → Meta verification handshake (Twilio doesn't do GET verification)
 *   POST → Inbound message — provider-detected, signature-checked, logged, replied
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/wa.php';
require_once __DIR__ . '/../../includes/twilio.php';
require_once __DIR__ . '/../../includes/notify.php';
require_once __DIR__ . '/../../includes/conversation.php';
require_once __DIR__ . '/../../includes/agent_supervisor.php';

// ════════════ GET — Meta verification handshake ════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = (string) ($_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '');
    $token     = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '');

    $expected = (string) env('META_VERIFY_TOKEN', '');

    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
        log_to_file('whatsapp', 'webhook verified (Meta GET)', ['challenge' => $challenge]);
        log_event('meta.webhook.verified');
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    log_to_file('whatsapp', 'verification rejected (Meta GET)', ['mode' => $mode, 'token_match' => hash_equals($expected, $token)]);
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ════════════ POST — inbound message ════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = (string) file_get_contents('php://input');

    // ─── Provider detection ───
    // Twilio sends form-encoded with MessageSid + From + Body fields.
    // Meta sends JSON with nested entry/changes structure.
    $isTwilio = !empty($_POST['MessageSid']) || !empty($_POST['SmsMessageSid']);
    $isMeta   = !$isTwilio && str_starts_with(ltrim($rawBody), '{');

    log_to_file('whatsapp', 'POST received', [
        'provider'    => $isTwilio ? 'twilio' : ($isMeta ? 'meta' : 'unknown'),
        'body_preview' => substr($rawBody, 0, 600),
    ]);

    try {
        if ($isTwilio) {
            handle_twilio_inbound();
        } elseif ($isMeta) {
            handle_meta_inbound($rawBody);
        } else {
            log_to_file('whatsapp', 'POST unknown provider — body did not match Meta or Twilio shape');
        }
    } catch (Throwable $e) {
        log_to_file('whatsapp', 'POST processing exception', [
            'err' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
    }

    // Always 200 — neither provider should retry on our errors. We log and move on.
    echo 'OK';
    exit;
}

http_response_code(405);
echo 'Method not allowed';
exit;


// ═══════════════════════════════════════════════════════════════════════════
//  META (direct Cloud API) inbound handler
// ═══════════════════════════════════════════════════════════════════════════
function handle_meta_inbound(string $rawBody): void
{
    // Verify signature (Meta signs with SHA256 HMAC of body using APP_SECRET)
    $appSecret   = (string) env('META_APP_SECRET', '');
    $headerSig   = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $expectedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

    $sigOk = $appSecret !== '' && $headerSig !== '' && hash_equals($expectedSig, $headerSig);
    if (!$sigOk) {
        log_event('meta.webhook.sig_failed');
        return;
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) return;

    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value    = $change['value']    ?? [];
            $messages = $value['messages']  ?? [];

            foreach ($messages as $msg) {
                $from = (string) ($msg['from'] ?? '');
                $type = (string) ($msg['type'] ?? '');
                $text = '';
                if ($type === 'text')        $text = (string) ($msg['text']['body'] ?? '');
                elseif ($type === 'interactive') $text = (string) ($msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? '');

                log_inbound($from, $type, $text, $msg, 'meta');
                handle_inbound_routing($from, $type, $text, 'meta');
            }

            // Log delivery / read statuses too
            foreach (($value['statuses'] ?? []) as $st) {
                log_event('meta.message.status', null, (string) ($st['recipient_id'] ?? ''), [
                    'status' => $st['status'] ?? null,
                    'id'     => $st['id']     ?? null,
                ]);
            }
        }
    }
}


// ═══════════════════════════════════════════════════════════════════════════
//  TWILIO inbound handler
// ═══════════════════════════════════════════════════════════════════════════
function handle_twilio_inbound(): void
{
    // Reconstruct the full URL Twilio hit (for signature validation)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'hiservice.store');
    $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/api/webhook/whatsapp.php');
    $fullUrl = $scheme . '://' . $host . $uri;

    $headerSig = (string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');
    $sigOk     = Twilio::validateSignature($fullUrl, $_POST, $headerSig);

    if (!$sigOk) {
        log_event('twilio.webhook.sig_failed', null, (string) ($_POST['From'] ?? ''), [
            'url'   => $fullUrl,
            'hasSig' => $headerSig !== '',
        ]);
        // Still log it but don't reply — could be a forged request
        return;
    }

    $rawFrom   = (string) ($_POST['From']   ?? '');   // e.g. "whatsapp:+27834603414"
    $rawTo     = (string) ($_POST['To']     ?? '');   // e.g. "whatsapp:+14155238886"
    $body      = (string) ($_POST['Body']   ?? '');
    $msgSid    = (string) ($_POST['MessageSid'] ?? '');
    $numMedia  = (int)    ($_POST['NumMedia'] ?? 0);
    $waName    = (string) ($_POST['ProfileName'] ?? '');

    $from = Twilio::normalizePhone($rawFrom);
    $type = $numMedia > 0 ? 'media' : 'text';

    log_inbound($from, $type, $body, [
        'MessageSid'  => $msgSid,
        'From'        => $rawFrom,
        'To'          => $rawTo,
        'Body'        => $body,
        'NumMedia'    => $numMedia,
        'ProfileName' => $waName,
    ], 'twilio');

    handle_inbound_routing($from, $type, $body, 'twilio');
}


// ═══════════════════════════════════════════════════════════════════════════
//  Shared: log inbound + dispatch routing (provider-agnostic)
// ═══════════════════════════════════════════════════════════════════════════
function log_inbound(string $from, string $type, string $text, array $rawMsg, string $provider): void
{
    if ($from === '') return;

    try {
        db()->prepare(
            "INSERT INTO conversations (phone, direction, channel, message_text, payload_json)
             VALUES (:p, 'in', 'whatsapp', :t, :j)"
        )->execute([
            ':p' => $from,
            ':t' => $text,
            ':j' => json_encode(['provider' => $provider, 'raw' => $rawMsg], JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        log_to_file('whatsapp', 'inbound log INSERT failed', ['err' => $e->getMessage()]);
    }

    log_event("{$provider}.message.in", null, $from, [
        'type'    => $type,
        'preview' => substr($text, 0, 100),
    ]);
}

function handle_inbound_routing(string $from, string $type, string $text, string $provider): void
{
    // Only route text for now. Media handling is queued for a later iteration.
    if ($type !== 'text' || $text === '') return;

    try {
        // AgentSupervisor wraps Conversation — handles loop-break, classifies intent
        // (ORDER_FLOW / GENERAL_HELP / ESCALATE / RESET), then routes accordingly.
        // ORDER_FLOW → existing state machine.
        // GENERAL_HELP → grounded FAQ agent (no hallucinations — uses agent_knowledge.php).
        $reply = AgentSupervisor::handle($from, $text, $provider);

        if ($reply === '') return;

        // Reply via the SAME provider that delivered the inbound — keeps the
        // conversation on one channel even if WA_PROVIDER env is set differently.
        notify_send_text($from, $reply, $provider);
    } catch (Throwable $e) {
        log_to_file('whatsapp', 'conversation handler failed', [
            'provider' => $provider,
            'err'      => $e->getMessage(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'to'       => $from,
        ]);

        // Last-ditch fallback — don't leave the customer hanging
        try {
            notify_send_text($from,
                "Sorry, something went wrong on our side. Please try again, or call 021 492 8515 for urgent orders.",
                $provider
            );
        } catch (Throwable $e2) {
            // Swallow — nothing more we can do
        }
    }
}
