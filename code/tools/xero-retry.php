<?php
/**
 * tools/xero-retry.php
 *
 * Finds paid orders with no Xero invoice yet and retries the push.
 * Also re-fires WhatsApp PDF delivery if order channel was 'whatsapp'.
 *
 * Run manually any time, or via cron nightly:
 *   30 2 * * *  /opt/alt/php81/usr/bin/php /home/hiserviceshopz/public_html/tools/xero-retry.php > /dev/null 2>&1
 *
 * Catches:
 *   - Orders where Xero was down at ITN time
 *   - Orders affected by the 30 May findOrCreateContact bug
 *   - Any future one-off failures
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_INTERNAL_CRON'])) {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/xero.php';
require_once __DIR__ . '/../includes/xero_sync.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_once __DIR__ . '/../includes/notify.php';

if (!Xero::isConnected()) {
    echo "[" . date('Y-m-d H:i:s') . "] xero-retry: Xero not connected — bailing.\n";
    exit(0);
}

// Find paid orders with no Xero invoice yet, last 30 days
$rows = db()->query("
    SELECT id, order_reference, channel, customer_id, total_amount, paid_at
      FROM orders
     WHERE status IN ('paid', 'delivered')
       AND (xero_invoice_id IS NULL OR xero_invoice_id = '')
       AND paid_at IS NOT NULL
       AND paid_at > NOW() - INTERVAL 30 DAY
     ORDER BY paid_at ASC
")->fetchAll();

if (empty($rows)) {
    echo "[" . date('Y-m-d H:i:s') . "] xero-retry: nothing to retry.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] xero-retry: " . count($rows) . " order(s) pending invoice push\n";

$stats = ['success' => 0, 'pdf_sent' => 0, 'failed' => 0];

$delayMs = 600;   // Xero rate limit = 60 calls/min/tenant + 5000/day. 600ms gap is conservative.
$idx = 0;

foreach ($rows as $r) {
    $ref     = (string) $r['order_reference'];
    $orderId = (int) $r['id'];

    // Throttle to avoid HTTP 429 (Xero rate limit) on bulk backfill
    if ($idx++ > 0) usleep($delayMs * 1000);

    try {
        // 1. Push to Xero
        $xeroResp = XeroSync::pushInvoice($orderId);
        echo "  ✓ {$ref} → invoice {$xeroResp['invoice_number']} (id {$xeroResp['invoice_id']})\n";
        $stats['success']++;

        // 2. Fetch PDF + save
        try {
            $pdfBytes = Xero::getInvoicePdf($xeroResp['invoice_id']);
            $hash      = bin2hex(random_bytes(8));
            $safeRef   = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);
            $filename  = $safeRef . '-' . $hash . '.pdf';
            $uploadDir = __DIR__ . '/../uploads/invoices';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            file_put_contents($uploadDir . '/' . $filename, $pdfBytes);

            $publicPath = '/uploads/invoices/' . $filename;
            db()->prepare("UPDATE orders SET xero_invoice_pdf_url = :p WHERE id = :id")
                ->execute([':p' => $publicPath, ':id' => $orderId]);

            log_event('xero.invoice.retry_success', 'order', $ref, [
                'invoice_id' => $xeroResp['invoice_id'],
                'pdf_url'    => $publicPath,
            ]);

            // 3. WhatsApp delivery for whatsapp-channel orders
            //    BUT only for orders paid in the last 2 hours — protects against
            //    bulk backfills accidentally spamming historical test customers
            //    with "sorry for the delay" messages weeks after they ordered.
            $paidAt    = strtotime((string) $r['paid_at']);
            $isRecent  = $paidAt > (time() - 2 * 3600);
            if ($r['channel'] === 'whatsapp' && $isRecent) {
                try {
                    $cust  = CustomerRepo::findById((int) $r['customer_id']);
                    $phone = (string) ($cust['phone'] ?? '');
                    if ($phone !== '') {
                        $publicUrl = 'https://hiservice.store' . $publicPath;
                        $body = "📄 *VAT invoice ready*\n\n" .
                                "Order ref: *{$ref}*\n" .
                                "Total: *R " . number_format((float) $r['total_amount'], 2) . "*\n\n" .
                                "Your Xero VAT invoice is attached. Apologies for the delay — there was a hiccup in our invoicing pipeline on our side. Order details unchanged.";
                        notify_send_media(
                            $phone,
                            'document',
                            $publicUrl,
                            $body,
                            'Hi-Service-Invoice-' . $ref . '.pdf',
                        );
                        echo "    ↳ WhatsApp PDF sent to {$phone}\n";
                        $stats['pdf_sent']++;
                        log_event('whatsapp.payment.pdf_retry_sent', 'order', $ref, ['url' => $publicUrl]);
                    }
                } catch (Throwable $e) {
                    echo "    ↳ ⚠ WhatsApp delivery failed: " . $e->getMessage() . "\n";
                    log_to_file('xero-retry', 'whatsapp delivery failed', ['err' => $e->getMessage(), 'ref' => $ref]);
                }
            }
        } catch (Throwable $e) {
            echo "    ↳ ⚠ PDF fetch/save failed: " . $e->getMessage() . "\n";
            log_to_file('xero-retry', 'pdf fetch failed', ['err' => $e->getMessage(), 'ref' => $ref]);
        }
    } catch (Throwable $e) {
        echo "  ✗ {$ref} → " . $e->getMessage() . "\n";
        log_to_file('xero-retry', 'invoice push failed', ['err' => $e->getMessage(), 'ref' => $ref]);
        log_event('xero.invoice.retry_failed', 'order', $ref, ['err' => substr($e->getMessage(), 0, 300)]);
        $stats['failed']++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] xero-retry done: " . json_encode($stats) . "\n";
log_event('xero.retry_run', null, null, $stats);
