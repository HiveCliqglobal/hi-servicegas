<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/xero.php';
require_once __DIR__ . '/../includes/env_writer.php';
require_login();

$flash = $_SESSION['flash']       ?? null; unset($_SESSION['flash']);
$flashErr = $_SESSION['flash_error'] ?? null; unset($_SESSION['flash_error']);

// ───── Handle POST: save PayFast / Meta / Xero-keys / GHL config ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_payfast') {
        $ok = env_set([
            'PAYFAST_MERCHANT_ID'   => trim((string) ($_POST['merchant_id']   ?? '')),
            'PAYFAST_MERCHANT_KEY'  => trim((string) ($_POST['merchant_key']  ?? '')),
            'PAYFAST_PASSPHRASE'    => (string) ($_POST['passphrase']    ?? ''),
            'PAYFAST_USE_SANDBOX'   => !empty($_POST['sandbox']) ? '1' : '0',
        ]);
        log_event('admin.integration.payfast_saved', null, null, ['sandbox' => !empty($_POST['sandbox'])]);
        $_SESSION[$ok ? 'flash' : 'flash_error'] = $ok ? '✓ PayFast credentials saved.' : 'Could not write to .env — check file permissions.';
        redirect('/admin/connections.php');
    }
    if ($action === 'save_xero_keys') {
        $ok = env_set([
            'XERO_CLIENT_ID'     => trim((string) ($_POST['client_id']     ?? '')),
            'XERO_CLIENT_SECRET' => trim((string) ($_POST['client_secret'] ?? '')),
        ]);
        log_event('admin.integration.xero_keys_saved');
        $_SESSION[$ok ? 'flash' : 'flash_error'] = $ok ? '✓ Xero app keys saved. Now click Connect.' : 'Could not write to .env.';
        redirect('/admin/connections.php');
    }
    if ($action === 'save_meta') {
        $ok = env_set([
            'META_APP_ID'              => trim((string) ($_POST['app_id']           ?? '')),
            'META_APP_SECRET'          => trim((string) ($_POST['app_secret']       ?? '')),
            'META_PHONE_NUMBER_ID'     => trim((string) ($_POST['phone_number_id']  ?? '')),
            'META_WABA_ID'             => trim((string) ($_POST['waba_id']          ?? '')),
            'META_SYSTEM_USER_TOKEN'   => trim((string) ($_POST['system_user_token']?? '')),
            'META_VERIFY_TOKEN'        => trim((string) ($_POST['verify_token']     ?? '')),
        ]);
        log_event('admin.integration.meta_saved');
        $_SESSION[$ok ? 'flash' : 'flash_error'] = $ok ? '✓ Meta WhatsApp credentials saved.' : 'Could not write to .env.';
        redirect('/admin/connections.php');
    }
    if ($action === 'save_ghl') {
        $ok = env_set([
            'GHL_LOCATION_ID'    => trim((string) ($_POST['location_id']   ?? '')),
            'GHL_PRIVATE_TOKEN'  => trim((string) ($_POST['private_token'] ?? '')),
        ]);
        log_event('admin.integration.ghl_saved');
        $_SESSION[$ok ? 'flash' : 'flash_error'] = $ok ? '✓ GoHighLevel credentials saved.' : 'Could not write to .env.';
        redirect('/admin/connections.php');
    }
}

$csrf = csrf_token();

// Pull current values from env (for display, masked where appropriate)
$xeroInfo      = Xero::isConnected() ? Xero::connectionInfo() : null;
$xeroConfigured = Xero::isConfigured();

$payfast = [
    'merchant_id'  => (string) env('PAYFAST_MERCHANT_ID', ''),
    'merchant_key' => (string) env('PAYFAST_MERCHANT_KEY', ''),
    'passphrase'   => (string) env('PAYFAST_PASSPHRASE', ''),
    'sandbox'      => (bool)   env('PAYFAST_USE_SANDBOX', false),
];

$meta = [
    'app_id'             => (string) env('META_APP_ID', ''),
    'app_secret'         => (string) env('META_APP_SECRET', ''),
    'phone_number_id'    => (string) env('META_PHONE_NUMBER_ID', ''),
    'waba_id'            => (string) env('META_WABA_ID', ''),
    'system_user_token'  => (string) env('META_SYSTEM_USER_TOKEN', ''),
    'verify_token'       => (string) env('META_VERIFY_TOKEN', ''),
];

$ghl = [
    'location_id'   => (string) env('GHL_LOCATION_ID', ''),
    'private_token' => (string) env('GHL_PRIVATE_TOKEN', ''),
];

include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>Connected Accounts</h1>
  <p>Link Xero, PayFast, Meta WhatsApp, and GoHighLevel. Credentials are saved to the server's <code>.env</code> file (chmod 600, outside the docroot).</p>
</div>

<?php if ($flash):    ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-error"><?= h($flashErr) ?></div><?php endif; ?>

<div class="connections-grid">

  <!-- ═══════════ Xero ═══════════ -->
  <div class="conn-card <?= Xero::isConnected() ? 'is-connected' : '' ?>">
    <div class="conn-head">
      <div class="conn-logo" style="background:#13b5ea;color:#fff">𝕏</div>
      <div>
        <h2>Xero</h2>
        <p class="muted small">Accounting · invoices · contacts</p>
      </div>
      <span class="conn-status <?= Xero::isConnected() ? 'on' : 'off' ?>">
        <?= Xero::isConnected() ? '● Connected' : '○ Not connected' ?>
      </span>
    </div>

    <?php if (Xero::isConnected()): ?>
      <div class="conn-body">
        <div class="kv"><span>Organisation</span><b><?= h($xeroInfo['tenant_name']) ?></b></div>
        <div class="kv"><span>Connected</span><b><?= $xeroInfo['connected_at'] ? h(date('d M Y · H:i', strtotime($xeroInfo['connected_at']))) : '—' ?></b></div>
        <div class="kv"><span>Token expires</span><b><?= h(date('d M · H:i', strtotime($xeroInfo['expires_at']))) ?> <span class="muted small">(auto-refresh)</span></b></div>
        <div class="kv"><span>Scopes</span><b class="muted small"><?= h(implode(' · ', $xeroInfo['scopes'])) ?></b></div>
      </div>
      <div class="conn-actions">
        <form method="post" action="/api/xero/disconnect.php" style="display:inline" onsubmit="return confirm('Disconnect Xero? Sync will stop until reconnected.')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <button type="submit" class="btn btn-ghost danger btn-sm">✕ Disconnect</button>
        </form>
        <a href="/api/xero/connect.php" class="btn btn-ghost btn-sm">🔄 Reconnect (re-auth)</a>
      </div>
    <?php elseif (!$xeroConfigured): ?>
      <div class="conn-body">
        <div class="alert alert-warn" style="margin:0">
          <div>
            <strong>Xero app keys needed first.</strong><br>
            Go to <a href="https://developer.xero.com/app/manage" target="_blank">developer.xero.com</a> → New app → Web app.<br>
            Set the OAuth 2.0 redirect URI to:<br>
            <code><?= h(Xero::redirectUri()) ?></code><br>
            Copy the Client ID + Secret below.
          </div>
        </div>
      </div>
      <details class="collapsible" style="margin:14px 0 0">
        <summary><span>🔑 Enter Xero app keys</span></summary>
        <div class="collapsible-body">
          <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save_xero_keys">
            <div class="form-row form-row-2">
              <label><span>Client ID</span><input type="text" name="client_id" required placeholder="from developer.xero.com"></label>
              <label><span>Client Secret</span><input type="password" name="client_secret" required></label>
            </div>
            <div class="form-foot">
              <span class="muted small">Stored in <code>.env</code> (chmod 600). Not visible after saving.</span>
              <button type="submit" class="btn btn-primary">💾 Save keys</button>
            </div>
          </form>
        </div>
      </details>
    <?php else: ?>
      <div class="conn-body">
        <p class="muted">Keys are configured. Click below to grant Hi-Service access to your Xero organisation.</p>
        <p class="muted small">Redirect URI: <code><?= h(Xero::redirectUri()) ?></code></p>
      </div>
      <div class="conn-actions">
        <a href="/api/xero/connect.php" class="btn btn-primary">🔌 Connect to Xero →</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- ═══════════ PayFast ═══════════ -->
  <div class="conn-card <?= !empty($payfast['merchant_id']) ? 'is-connected' : '' ?>">
    <div class="conn-head">
      <div class="conn-logo" style="background:#ff5e00;color:#fff">P</div>
      <div>
        <h2>PayFast <?php if ($payfast['sandbox']): ?><span class="chip" style="background:#fff3c7;color:#92400e;font-size:10px">SANDBOX</span><?php endif; ?></h2>
        <p class="muted small">Payment gateway · ZAR</p>
      </div>
      <span class="conn-status <?= !empty($payfast['merchant_id']) ? 'on' : 'off' ?>">
        <?= !empty($payfast['merchant_id']) ? '● ' . ($payfast['sandbox'] ? 'Sandbox' : 'Live') : '○ Not configured' ?>
      </span>
    </div>

    <div class="conn-body">
      <?php if (!empty($payfast['merchant_id'])): ?>
        <div class="kv"><span>Merchant ID</span><b><?= h($payfast['merchant_id']) ?></b></div>
        <div class="kv"><span>Merchant Key</span><b><?= h(env_mask($payfast['merchant_key'])) ?></b></div>
        <div class="kv"><span>Passphrase</span><b><?= h($payfast['passphrase'] === '' ? '(not set)' : env_mask($payfast['passphrase'])) ?></b></div>
        <div class="kv"><span>Notify URL</span><b class="muted small">https://hiservice.store/api/webhook/payfast-itn.php</b></div>
      <?php else: ?>
        <p class="muted">Not configured. Enter your PayFast merchant credentials below to enable checkout.</p>
      <?php endif; ?>
    </div>

    <details class="collapsible" <?= empty($payfast['merchant_id']) ? 'open' : '' ?> style="margin:14px 0 0">
      <summary><span><?= !empty($payfast['merchant_id']) ? '✏️ Update credentials' : '🔑 Enter credentials' ?></span></summary>
      <div class="collapsible-body">
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="save_payfast">
          <div class="form-row form-row-2">
            <label><span>Merchant ID</span><input type="text" name="merchant_id" required value="<?= h($payfast['merchant_id']) ?>"></label>
            <label><span>Merchant Key</span><input type="text" name="merchant_key" required value="<?= h($payfast['merchant_key']) ?>"></label>
          </div>
          <div class="form-row form-row-2">
            <label><span>Passphrase</span><input type="text" name="passphrase" value="<?= h($payfast['passphrase']) ?>" placeholder="Optional but recommended"></label>
            <label class="inline-check" style="align-self:end">
              <input type="checkbox" name="sandbox" value="1" <?= $payfast['sandbox'] ? 'checked' : '' ?>>
              <span>Use sandbox (testing)</span>
            </label>
          </div>
          <div class="form-foot">
            <span class="muted small">In sandbox mode the pay link redirects to sandbox.payfast.co.za and no real money moves.</span>
            <button type="submit" class="btn btn-primary">💾 Save PayFast</button>
          </div>
        </form>
      </div>
    </details>
  </div>

  <!-- ═══════════ Meta WhatsApp ═══════════ -->
  <div class="conn-card <?= !empty($meta['system_user_token']) ? 'is-connected' : '' ?>">
    <div class="conn-head">
      <div class="conn-logo" style="background:#25d366;color:#fff">💬</div>
      <div>
        <h2>Meta WhatsApp</h2>
        <p class="muted small">Cloud API · inbound webhook + send</p>
      </div>
      <span class="conn-status <?= !empty($meta['system_user_token']) ? 'on' : 'off' ?>">
        <?= !empty($meta['system_user_token']) ? '● Configured' : '○ Not connected' ?>
      </span>
    </div>

    <div class="conn-body">
      <?php if (!empty($meta['system_user_token'])): ?>
        <div class="kv"><span>App ID</span><b><?= h($meta['app_id'] ?: '—') ?></b></div>
        <div class="kv"><span>Phone Number ID</span><b><?= h($meta['phone_number_id'] ?: '—') ?></b></div>
        <div class="kv"><span>WABA ID</span><b><?= h($meta['waba_id'] ?: '—') ?></b></div>
        <div class="kv"><span>System User Token</span><b><?= h(env_mask($meta['system_user_token'])) ?></b></div>
        <div class="kv"><span>Webhook URL</span><b class="muted small">https://hiservice.store/api/webhook/whatsapp.php</b></div>
        <div class="kv"><span>Verify Token</span><b><?= h($meta['verify_token'] ?: '—') ?></b></div>
      <?php else: ?>
        <p class="muted">Configure Meta to enable WhatsApp ordering + delivery notifications.</p>
        <p class="muted small">Webhook URL to register in Meta dashboard:<br><code>https://hiservice.store/api/webhook/whatsapp.php</code></p>
      <?php endif; ?>
    </div>

    <details class="collapsible" <?= empty($meta['system_user_token']) ? 'open' : '' ?> style="margin:14px 0 0">
      <summary><span><?= !empty($meta['system_user_token']) ? '✏️ Update Meta credentials' : '🔑 Enter Meta credentials' ?></span></summary>
      <div class="collapsible-body">
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="save_meta">
          <div class="form-row form-row-2">
            <label><span>App ID</span><input type="text" name="app_id" value="<?= h($meta['app_id']) ?>" placeholder="From business.facebook.com"></label>
            <label><span>App Secret</span><input type="password" name="app_secret" value="<?= h($meta['app_secret']) ?>"></label>
          </div>
          <div class="form-row form-row-2">
            <label><span>Phone Number ID</span><input type="text" name="phone_number_id" value="<?= h($meta['phone_number_id']) ?>"></label>
            <label><span>WABA ID</span><input type="text" name="waba_id" value="<?= h($meta['waba_id']) ?>"></label>
          </div>
          <div class="form-row form-row-2">
            <label><span>System User Token</span><input type="password" name="system_user_token" value="<?= h($meta['system_user_token']) ?>" placeholder="Permanent token"></label>
            <label>
              <span>Verify Token <small>— must match what you paste in Meta</small></span>
              <div style="display:flex;gap:6px">
                <input type="text" name="verify_token" id="meta-verify-token" value="<?= h($meta['verify_token']) ?>" placeholder="Click 🎲 to generate" style="flex:1">
                <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('meta-verify-token').value = 'hs-' + Math.random().toString(36).slice(2,12) + Math.random().toString(36).slice(2,8); event.preventDefault()">🎲 Generate</button>
              </div>
            </label>
          </div>
          <div class="form-foot">
            <span class="muted small">After saving, register the webhook URL + verify token in Meta dashboard.</span>
            <button type="submit" class="btn btn-primary">💾 Save Meta</button>
          </div>
        </form>
      </div>
    </details>
  </div>

  <!-- ═══════════ GoHighLevel ═══════════ -->
  <div class="conn-card <?= !empty($ghl['private_token']) ? 'is-connected' : '' ?>">
    <div class="conn-head">
      <div class="conn-logo" style="background:#0f7a52;color:#fff">G</div>
      <div>
        <h2>GoHighLevel</h2>
        <p class="muted small">CRM destination · contacts · pipelines</p>
      </div>
      <span class="conn-status <?= !empty($ghl['private_token']) ? 'on' : 'off' ?>">
        <?= !empty($ghl['private_token']) ? '● Connected' : '○ Not connected' ?>
      </span>
    </div>

    <div class="conn-body">
      <?php if (!empty($ghl['private_token'])): ?>
        <div class="kv"><span>Location ID</span><b><?= h($ghl['location_id']) ?></b></div>
        <div class="kv"><span>Private Integration Token</span><b><?= h(env_mask($ghl['private_token'])) ?></b></div>
      <?php else: ?>
        <p class="muted">Connect GHL to sync customers + paid orders into your CRM pipeline.</p>
      <?php endif; ?>
    </div>

    <details class="collapsible" style="margin:14px 0 0">
      <summary><span>✏️ Update GHL credentials</span></summary>
      <div class="collapsible-body">
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="save_ghl">
          <div class="form-row form-row-2">
            <label><span>Location ID</span><input type="text" name="location_id" required value="<?= h($ghl['location_id']) ?>"></label>
            <label><span>Private Integration Token</span><input type="password" name="private_token" required value="<?= h($ghl['private_token']) ?>" placeholder="pit-..."></label>
          </div>
          <div class="form-foot">
            <span class="muted small">Get a token at GHL → Settings → Private Integrations.</span>
            <button type="submit" class="btn btn-primary">💾 Save GHL</button>
          </div>
        </form>
      </div>
    </details>
  </div>

</div>

<?php include __DIR__ . '/_footer.php'; ?>
