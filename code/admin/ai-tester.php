<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/general_help_agent.php';
require_login();

$apiKeySet = trim((string) env('ANTHROPIC_API_KEY', '')) !== '';
$reply      = null;
$toolCalls  = [];
$cost       = 0.0;
$err        = null;
$customerId = $_POST['customer_id'] ?? null;
$customerId = $customerId ? (int) $customerId : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message']) && csrf_verify($_POST['csrf'] ?? '')) {
    try {
        if (!$apiKeySet) throw new RuntimeException('ANTHROPIC_API_KEY not set — add it to ~/.env on the server.');
        $r = GeneralHelpAgent::answer($customerId, (string) $_POST['message']);
        $reply     = $r['reply'];
        $toolCalls = $r['tool_calls'];
        $cost      = $r['cost_usd'];
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// recent customers for the dropdown
$customers = db()->query("SELECT id, full_name, phone FROM customers ORDER BY id DESC LIMIT 20")->fetchAll();
$csrf = csrf_token();

include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>AI Test Bench</h1>
  <p class="muted">Send the general-help agent a test question to preview the WhatsApp / web reply. Tools (lookup order, check zone, book appointment, escalate) work for real.</p>
</div>

<?php if (!$apiKeySet): ?>
  <div class="card" style="border-left:3px solid var(--danger);background:#fef5f5">
    <h3 style="margin-top:0;color:var(--danger)">Anthropic key not set</h3>
    <p>Drop your API key in <code>/home/hiserviceshopz/.env</code> as <code>ANTHROPIC_API_KEY=...</code> and this page goes live.</p>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Test a question</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label style="display:block;margin-bottom:12px">
      <span class="muted small">Pretend to be customer (optional)</span><br>
      <select name="customer_id" style="padding:8px;width:100%;max-width:380px">
        <option value="">— Anonymous —</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $customerId === (int) $c['id'] ? 'selected' : '' ?>>
            <?= h($c['full_name']) ?> · <?= h($c['phone']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="display:block">
      <span class="muted small">Customer message</span>
      <textarea name="message" rows="3" style="width:100%;padding:11px;border:1px solid var(--line);border-radius:4px;font-family:inherit;font-size:14px" placeholder="e.g. What areas do you deliver to? · How much is a 9kg? · I want to order a 19kg for tomorrow morning"><?= h($_POST['message'] ?? '') ?></textarea>
    </label>
    <button type="submit" class="btn btn-primary" style="margin-top:12px">▶️ Ask the agent</button>
  </form>
</div>

<?php if ($err): ?>
  <div class="card" style="border-left:3px solid var(--danger);background:#fef5f5">
    <h3 style="margin-top:0;color:var(--danger)">Error</h3>
    <pre style="white-space:pre-wrap;font-family:inherit"><?= h($err) ?></pre>
  </div>
<?php endif; ?>

<?php if ($reply !== null): ?>
  <div class="card">
    <h3 style="margin-top:0">🤖 Agent reply</h3>
    <div class="chat-preview" style="margin:8px 0">
      <div class="msg in"><?= nl2br(h($reply)) ?></div>
    </div>
    <p class="muted small">Cost: <b><?= '$' . number_format($cost, 6) ?></b></p>
  </div>

  <?php if (!empty($toolCalls)): ?>
    <div class="card">
      <h3>Tool calls</h3>
      <?php foreach ($toolCalls as $tc): ?>
        <div style="border-left:3px solid var(--red);padding:8px 12px;margin-bottom:8px;background:var(--bg);font-family:'SF Mono',monospace;font-size:12px">
          <b><?= h($tc['name']) ?></b><br>
          <span class="muted small">input:</span> <?= h(json_encode($tc['input'])) ?><br>
          <span class="muted small">result:</span> <?= h(json_encode($tc['result'])) ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card">
  <h3>Try these</h3>
  <ul>
    <li><i>"What areas do you deliver to?"</i> — should answer from knowledge</li>
    <li><i>"Are you in postal code 7140?"</i> — uses <code>check_delivery_zone</code> tool</li>
    <li><i>"What's the status of order ORD-20260511181254-10AFB2?"</i> — uses <code>lookup_order_status</code> (pick a customer first)</li>
    <li><i>"I'd like to book a gas installation for next Tuesday"</i> — uses <code>book_appointment</code></li>
    <li><i>"I want to speak to a human, this is urgent"</i> — uses <code>escalate_to_human</code></li>
  </ul>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
