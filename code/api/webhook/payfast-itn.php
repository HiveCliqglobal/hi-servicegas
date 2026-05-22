<?php
/**
 * api/webhook/payfast-itn.php
 *
 * PayFast Instant Transaction Notification (ITN) handler.
 * PayFast POSTs here after a customer completes payment.
 *
 *   1. Log the raw payload
 *   2. Verify the signature against our passphrase
 *   3. Cross-check the amount against orders.total_amount
 *   4. Mark order paid + release slot atomically
 *   5. Sync to GHL (best-effort)
 *   6. Return 200 OK so PayFast doesn't retry
 *
 * Notify URL to register in PayFast dashboard:
 *   https://hiservice.store/api/webhook/payfast-itn.php
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/payfast.php';
require_once __DIR__ . '/../../includes/order_repo.php';
require_once __DIR__ . '/../../includes/customer_repo.php';

// PayFast always POSTs.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$rawBody = file_get_contents('php://input');
log_to_file('payfast-itn', 'received', [
    'remote' => $_SERVER['REMOTE_ADDR'] ?? null,
    'body'   => substr((string) $rawBody, 0, 1200),
]);

// Parse the form-encoded body
$body = $_POST;
if (empty($body) && !empty($rawBody)) parse_str((string) $rawBody, $body);
if (!is_array($body) || empty($body)) {
    log_to_file('payfast-itn', 'empty body');
    http_response_code(400);
    echo 'Empty body';
    exit;
}

try {
    // 1. Signature verification
    if (!payfast_verify_itn($body)) {
        log_to_file('payfast-itn', 'signature mismatch', ['received' => $body['signature'] ?? '(none)']);
        // Still return 200 — never let PayFast retry; we want to investigate via logs
        echo 'OK';
        exit;
    }

    // 2. Order lookup
    $ref = (string) ($body['m_payment_id'] ?? '');
    if ($ref === '') throw new RuntimeException('Missing m_payment_id');

    $order = OrderRepo::findByRef($ref);
    if (!$order) throw new RuntimeException("Order not found: {$ref}");

    // 3. Amount cross-check (within 1 cent tolerance)
    $expected = (float) $order['total_amount'];
    $reported = (float) ($body['amount_gross'] ?? 0);
    if (abs($expected - $reported) > 0.01) {
        log_to_file('payfast-itn', 'amount mismatch', ['expected' => $expected, 'reported' => $reported, 'ref' => $ref]);
        log_event('payfast.itn.amount_mismatch', 'order', $ref, [
            'expected' => $expected, 'reported' => $reported,
        ]);
        echo 'OK';
        exit;
    }

    // 4. Status mapping
    $payment_status = strtolower((string) ($body['payment_status'] ?? ''));
    $payfastId = (string) ($body['pf_payment_id'] ?? '');

    if ($payment_status === 'complete') {
        // Idempotent: if already paid, do nothing
        if (in_array($order['status'], ['paid', 'delivered'], true)) {
            log_to_file('payfast-itn', 'already paid', ['ref' => $ref]);
            echo 'OK';
            exit;
        }
        OrderRepo::markPaid((int) $order['id'], $payfastId);

        // Persist the raw payment record
        db()->prepare(
            "INSERT INTO payments (order_id, payfast_payment_id, amount, status, signature_received, raw_payload, verified_at)
             VALUES (:o, :p, :a, 'complete', :sig, :raw, NOW())"
        )->execute([
            ':o'   => $order['id'],
            ':p'   => $payfastId,
            ':a'   => $reported,
            ':sig' => (string) ($body['signature'] ?? ''),
            ':raw' => json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);

        log_event('payfast.itn.paid', 'order', $ref, ['pf_id' => $payfastId, 'amount' => $reported]);

        // GHL sync (best-effort)
        if (env('GHL_PRIVATE_TOKEN')) {
            try {
                require_once __DIR__ . '/../../includes/ghl.php';
                $cust = CustomerRepo::findById((int) $order['customer_id']);
                GHL::syncPaidOrder($order, $cust);
            } catch (Throwable $e) {
                log_to_file('payfast-itn', 'ghl sync failed', ['err' => $e->getMessage()]);
            }
        }

    } elseif (in_array($payment_status, ['cancelled', 'failed'], true)) {
        OrderRepo::setStatus((int) $order['id'], 'failed');
        if (!empty($order['slot_id'])) {
            require_once __DIR__ . '/../../includes/slot_repo.php';
            SlotRepo::release((int) $order['slot_id']);
        }
        log_event('payfast.itn.failed', 'order', $ref, ['status' => $payment_status]);
    }

    echo 'OK';
    exit;

} catch (Throwable $e) {
    log_to_file('payfast-itn', 'exception', [
        'err'  => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(200);   // Always 200 to PayFast — surface errors via our logs
    echo 'OK';
    exit;
}
