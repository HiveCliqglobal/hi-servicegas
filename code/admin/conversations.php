<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

// Latest conversations grouped by phone
$rows = db()->query(
    "SELECT phone, MAX(created_at) AS last_at, COUNT(*) AS msgs, MAX(mode) AS mode, MAX(current_step) AS step
     FROM conversations GROUP BY phone ORDER BY last_at DESC LIMIT 50"
)->fetchAll();

include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>Conversations</h1>
  <p class="muted">Live WhatsApp + web chats. Once Meta is wired, every customer message flows through here in real time.</p>
</div>

<div class="card" style="background:#fff8e6;border-left:3px solid #f0a500">
  <h3 style="margin-top:0">⚠️ WhatsApp not yet connected</h3>
  <p>The state machine and intent detector are live. Once the Meta WhatsApp Cloud API credentials are added (Stage 3 day 5), every inbound message will route through the same brain that powers the web shop — and appear here.</p>
</div>

<?php if (empty($rows)): ?>
  <div class="card">
    <h2>The WhatsApp script — preview</h2>
    <p class="muted">Here's exactly what customers will see on WhatsApp once the gateway is wired. Same flow as the web shop — different surface.</p>

    <div class="chat-preview">
      <div class="msg out">Hi</div>
      <div class="msg in">
        <b>Welcome to Hi-Service Gas 👋</b><br>
        How can we help you today?<br><br>
        <b>1</b> — 🛒 Order Gas<br>
        <b>2</b> — 💬 General Help (chat with our team)<br><br>
        <span class="muted">(Type Cancel anytime to end this chat)</span>
      </div>
      <div class="msg out">1</div>
      <div class="msg in">
        Welcome back, James! 👋<br>
        Your last order was:<br>
        • 2 × 9kg LPG Gas Delivered<br>
        • 1 × 48kg LPG Gas Delivered<br><br>
        Reply:<br>
        <b>1</b> — to repeat this order<br>
        <b>2</b> — to place a different order
      </div>
      <div class="msg out">1</div>
      <div class="msg in">
        Got it. Your delivery address on file is:<br>
        <i>31 Example Rd, Strand, 7140</i><br><br>
        <b>S</b> — same address<br>
        <b>D</b> — different address
      </div>
      <div class="msg out">S</div>
      <div class="msg in">
        🌅 <b>A</b> · Wed 13 May · Morning (08:00-12:00)<br>
        ☀️ <b>B</b> · Wed 13 May · Afternoon (13:00-16:30)<br><br>
        Reply A or B, or type a date.
      </div>
      <div class="msg out">A</div>
      <div class="msg in">
        ✅ Delivery booked!<br><br>
        <b>Recipient:</b> James Elliot<br>
        <b>Products:</b> 2 × 9kg, 1 × 48kg<br>
        <b>Delivery:</b> 13/05/2026 · 08:00-12:00<br>
        <b>Address:</b> 31 Example Rd, Strand, 7140<br>
        <b>Total:</b> R 2,720.00<br><br>
        Reply <b>P</b> to pay · <b>D</b> for a different order · Cancel
      </div>
      <div class="msg out">P</div>
      <div class="msg in">
        💳 Payment Link Ready!<br>
        <a href="#">https://payfast.co.za/eng/process?…</a><br><br>
        Amount: R 2 720.00<br>
        Order: ORD-2026XXXX<br>
        Payment link expires in 24 hours.
      </div>
    </div>

    <p class="muted small" style="margin-top:18px">Every state, prompt and option above lives in <code>includes/state_machine.php</code>. The web shop and WhatsApp share this brain — change one, update both.</p>
  </div>
<?php else: ?>
  <div class="card" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Phone</th><th>Last activity</th><th>Mode</th><th>Step</th><th>Messages</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><b><?= h($r['phone']) ?></b></td>
            <td class="muted small"><?= h(date('d M Y · H:i', strtotime($r['last_at']))) ?></td>
            <td><span class="chan chan-<?= h($r['mode'] ?? 'menu') ?>"><?= h($r['mode'] ?? '—') ?></span></td>
            <td class="muted small"><?= h($r['step'] ?? '—') ?></td>
            <td><?= (int) $r['msgs'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
