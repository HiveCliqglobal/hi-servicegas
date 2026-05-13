<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/agent_watchdog.php';
require_login();

$apiKeySet = trim((string) env('ANTHROPIC_API_KEY', '')) !== '';

// Manual run trigger
$result = null;
$ranNow = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'run_now') {
    try {
        $result = AgentWatchdog::run();
        $ranNow = true;
    } catch (Throwable $e) {
        $result = ['error' => $e->getMessage()];
    }
}

// Recent activity
try {
    $rows = db()->query(
        "SELECT * FROM agent_activity ORDER BY created_at DESC LIMIT 50"
    )->fetchAll();
} catch (Throwable $e) {
    $rows = [];
    $missingTable = true;
}

// Cost summary (last 7 days)
try {
    $cost7d = (float) db()->query(
        "SELECT COALESCE(SUM(cost_usd),0)
         FROM agent_activity
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();
    $calls7d = (int) db()->query(
        "SELECT COUNT(*) FROM agent_activity WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();
} catch (Throwable $e) {
    $cost7d  = 0.0;
    $calls7d = 0;
}

$csrf = csrf_token();
include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>Claude Agent</h1>
  <p class="muted">A Claude watchdog that monitors orders, auto-recovers stuck states, and disambiguates fuzzy customer messages.</p>
</div>

<?php if (!$apiKeySet): ?>
  <div class="card" style="border-left:3px solid var(--danger);background:#fef5f5">
    <h3 style="margin-top:0;color:var(--danger)">⚠️ Anthropic API key not set</h3>
    <p>The agent runs in <b>local-fallback mode</b> right now — it still does the auto-recovery, but you'll get generic summaries instead of Claude-written ones.</p>
    <p>To activate Claude:</p>
    <pre style="background:#fff;border:1px solid var(--line);padding:10px;border-radius:4px;overflow-x:auto"><code>ssh -p 22000 hiserviceshopz@rs53.cphost.co.za
nano ~/.env
# Set: ANTHROPIC_API_KEY=sk-ant-api03-...
# Save (Ctrl+O, Enter, Ctrl+X)</code></pre>
  </div>
<?php endif; ?>

<?php if (!empty($missingTable)): ?>
  <div class="card" style="border-left:3px solid var(--warn);background:#fffaf0">
    <h3 style="margin-top:0">⚙️ One-time migration needed</h3>
    <p>The <code>agent_activity</code> table doesn't exist yet. Run on the server:</p>
    <pre style="background:#fff;border:1px solid var(--line);padding:10px;border-radius:4px"><code>mysql -u <?= h(env('DB_USER', '')) ?> -p <?= h(env('DB_NAME', '')) ?> &lt; ~/public_html/migrations/002_agent.sql</code></pre>
  </div>
<?php endif; ?>

<?php if ($ranNow && $result): ?>
  <div class="card" style="border-left:3px solid var(--success);background:#f5fbf5">
    <h3 style="margin-top:0">✅ Run complete</h3>
    <?php if (!empty($result['error'])): ?>
      <p style="color:var(--danger)"><b>Error:</b> <?= h($result['error']) ?></p>
    <?php else: ?>
      <p><b><?= h($result['summary'] ?? 'OK') ?></b></p>
      <p class="muted small">
        <?= count($result['observations'] ?? []) ?> observation(s) ·
        <?= count($result['actions'] ?? []) ?> auto-action(s) ·
        <?= h((string)($result['duration_ms'] ?? 0)) ?>ms
      </p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Mode</div>
    <div class="stat-value" style="font-size:18px;"><?= $apiKeySet ? '🤖 Claude active' : '⚪ Local fallback' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Calls (7d)</div>
    <div class="stat-value"><?= h((string) $calls7d) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Cost (7d)</div>
    <div class="stat-value"><?= '$' . number_format($cost7d, 4) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Last activity</div>
    <div class="stat-value" style="font-size:14px;">
      <?= $rows ? h(date('d M · H:i', strtotime($rows[0]['created_at']))) : '—' ?>
    </div>
  </div>
</div>

<div class="card">
  <h2>Manual run</h2>
  <p class="muted">Trigger a health-check now. Normally runs every 15 min via cron.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="run_now">
    <button type="submit" class="btn btn-primary">▶️ Run watchdog now</button>
  </form>
</div>

<div class="card" style="padding:0">
  <h2 style="padding:18px 22px 0;margin:0">Recent activity</h2>
  <?php if (empty($rows)): ?>
    <p class="muted" style="padding:0 22px 18px">No activity yet — run the watchdog above to generate the first health check.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>When</th>
          <th>Kind</th>
          <th>Severity</th>
          <th>Title</th>
          <th>Summary</th>
          <th>Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="muted small" style="white-space:nowrap"><?= h(date('d M · H:i', strtotime($r['created_at']))) ?></td>
            <td><span class="chip"><?= h(str_replace('_', ' ', $r['kind'])) ?></span></td>
            <td><span class="severity sev-<?= h($r['severity']) ?>"><?= h($r['severity']) ?></span></td>
            <td><b><?= h($r['title']) ?></b></td>
            <td class="muted small"><?= h($r['summary'] ?? '—') ?></td>
            <td class="muted small" style="white-space:nowrap"><?= $r['cost_usd'] ? '$' . number_format((float)$r['cost_usd'], 4) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
