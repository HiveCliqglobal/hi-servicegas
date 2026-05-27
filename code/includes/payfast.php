<?php
/**
 * payfast.php — PayFast signature gen, pay-link builder, ITN verify.
 *
 * Replaces the 100+ line hand-rolled JavaScript MD5 from n8n with
 * PHP's built-in md5().
 *
 * Stage 3 day 7 work — keys arrive then we test live. The functions
 * are usable now in test mode by toggling PAYFAST_USE_SANDBOX=1.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Build the signature string for a PayFast payload.
 *
 * Order is fixed per PayFast docs. Values are URL-encoded PHP-style
 * (spaces become +). The passphrase, if set, is appended last.
 */
function payfast_signature(array $data, string $passphrase = ''): string
{
    // Fixed order — match PayFast docs for "Process" gateway
    $fixedOrder = [
        'merchant_id', 'merchant_key',
        'return_url', 'cancel_url', 'notify_url',
        'name_first', 'name_last', 'email_address', 'cell_number',
        'm_payment_id', 'amount', 'item_name', 'item_description',
        'custom_str1', 'custom_str2', 'custom_str3', 'custom_str4', 'custom_str5',
        'custom_int1', 'custom_int2', 'custom_int3', 'custom_int4', 'custom_int5',
        'email_confirmation', 'confirmation_address',
    ];
    $parts = [];
    foreach ($fixedOrder as $k) {
        if (isset($data[$k]) && trim((string) $data[$k]) !== '') {
            $parts[] = $k . '=' . str_replace('%20', '+', rawurlencode(trim((string) $data[$k])));
        }
    }
    $sigString = implode('&', $parts);
    if ($passphrase !== '') {
        $sigString .= '&passphrase=' . str_replace('%20', '+', rawurlencode($passphrase));
    }
    return md5($sigString);
}

/**
 * Build a full pay-link URL.
 *
 * Expects:  order_reference, order_total, customer_name, customer_email, customer_phone
 */
function payfast_build_pay_link(array $order): string
{
    $sandbox = (bool) env('PAYFAST_USE_SANDBOX', false);
    $base = $sandbox ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process';

    $merchantId  = (string) env('PAYFAST_MERCHANT_ID', '');
    $merchantKey = (string) env('PAYFAST_MERCHANT_KEY', '');
    $passphrase  = (string) env('PAYFAST_PASSPHRASE', '');

    if ($merchantId === '' || $merchantKey === '') {
        throw new RuntimeException('PayFast credentials missing — set PAYFAST_MERCHANT_ID + PAYFAST_MERCHANT_KEY in .env');
    }

    $nameParts = preg_split('/\s+/', trim((string) ($order['customer_name'] ?? ''))) ?: [''];
    $first = $nameParts[0] ?? '';
    $last  = implode(' ', array_slice($nameParts, 1));

    // Build return_url with the order_reference appended so the success page
    // knows which order was just paid (PayFast doesn't echo m_payment_id back
    // on return — we have to encode it ourselves).
    $baseReturn  = rtrim((string) env('PAYFAST_RETURN_URL', 'https://hiservice.store/shop/success.php'), '?&');
    $returnUrl   = $baseReturn . (str_contains($baseReturn, '?') ? '&' : '?')
                                . 'ref=' . rawurlencode((string) $order['order_reference']);

    $data = [
        'merchant_id'      => $merchantId,
        'merchant_key'     => $merchantKey,
        'return_url'       => $returnUrl,
        'cancel_url'       => (string) env('PAYFAST_CANCEL_URL', 'https://hiservice.store/shop/cancelled.php'),
        'notify_url'       => (string) env('PAYFAST_NOTIFY_URL', 'https://hiservice.store/api/webhook/payfast-itn.php'),
        'name_first'       => $first,
        'name_last'        => $last,
        'email_address'    => (string) ($order['customer_email'] ?? ''),
        'cell_number'      => (string) ($order['customer_phone'] ?? ''),
        'm_payment_id'     => (string) $order['order_reference'],
        'amount'           => number_format((float) $order['order_total'], 2, '.', ''),
        'item_name'        => 'Gas Delivery Order',
        'item_description' => 'Order ' . $order['order_reference'],
    ];

    $sig = payfast_signature($data, $passphrase);

    $qs = http_build_query($data, '', '&', PHP_QUERY_RFC1738);
    return $base . '?' . $qs . '&signature=' . $sig;
}

/**
 * Verify a PayFast ITN body.
 * Recomputes signature in the order PayFast sent us, then matches.
 */
function payfast_verify_itn(array $body): bool
{
    $received = (string) ($body['signature'] ?? '');
    if ($received === '') return false;
    $passphrase = (string) env('PAYFAST_PASSPHRASE', '');

    $parts = [];
    foreach ($body as $k => $v) {
        if ($k === 'signature') continue;
        $parts[] = $k . '=' . str_replace('%20', '+', rawurlencode((string) $v));
    }
    $sigString = implode('&', $parts);
    if ($passphrase !== '') {
        $sigString .= '&passphrase=' . str_replace('%20', '+', rawurlencode($passphrase));
    }
    return hash_equals(md5($sigString), $received);
}
