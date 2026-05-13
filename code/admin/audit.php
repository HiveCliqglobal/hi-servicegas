<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$preset  = (string) ($_GET['preset']  ?? '7d');
$source  = (string) ($_GET['source']  ?? '');
$kind    = (string) ($_GET['kind']    ?? '');
$from    = (string) ($_GET['from']    ?? '');
$to      = (string) ($_GET['to']      ?? '');
$q       = trim((string) ($_GET['q']  ?? ''));

if ($from === '' || $to === '') {
    $to = date('Y-m-d 23:59:59');
    switch ($preset) {
        case 'today': $from = date('Y-m-d 00:00:00'); break;
        case '30d':   $from = date('Y-m-d 00:00:00', strtotime('-30 days')); break;
        case 'mtd':   $from = date('Y-m-01 00:00:00'); break;
        default:      $from = date('Y-m-d 00:00:00', strtotime('-7 days')); $preset = '7d';
    }
} else {
    $from .= ' 00:00:00';
    $to   .= ' 23:59:59';
}

/**
 * Build a unified audit feed from THREE sources:
 *   · agent_activity  (Claude watchdog, AI fallbacks, escalations)
 *   · event_log       (admin actions, shop-customer actions, integrations)
 *   · conversations   (customer messages in/out across channels)
 */
function detectSource(string $action, ?string $existing): string
{
    if ($existing && $existing !== 'unknown') return $existing;
    if (str_starts_with($action, 'admin.'))   return 'admin';
    if (str_starts_with($action, 'shop.'))    return 'web';
    if (str_starts_with($action, 'wa.'))      return 'whatsapp';
    if (str_starts_with($action, 'agent.'))   return 'agent';
    if (str_starts_with($action, 'auth.'))    return 'admin';
    return 'unknown';
}

$entries = [];

// 1. agent_activity
$stmt = db()->prepare(
    "SELECT id, kind, source, severity, title, summary, cost_usd, created_at,
            entity_type, entity_id, model
     FROM agent_activity
     WHERE created_at BETWEEN :from AND :to
     ORDER BY created_at DESC"
);
$stmt->execute([':from' => $from, ':to' => $to]);
foreach ($stmt->fetchAll() as $r) {
    $entries[] = [
        'when'        => $r['created_at'],
        'source'      => $r['source'] ?: 'agent',
        'kind'        => 'agent.' . $r['kind'],
        'severity'    => $r['severity'],
        'title'       => $r['title'],
        'detail'      => $r['summary'],
        'entity'      => $r['entity_type'] ? "{$r['entity_type']}#{$r['entity_id']}" : '',
        'cost'        => (float) $r['cost_usd'],
        'meta'        => $r['model'],
    ];
}

// 2. event_log
$stmt = db()->prepare(
    "SELECT e.id, e.action, e.source, e.entity_type, e.entity_id, e.payload, e.created_at,
            e.ip_address, u.username
     FROM event_log e
     LEFT JOIN users u ON u.id = e.user_id
     WHERE e.created_at BETWEEN :from AND :to
     ORDER BY e.created_at DESC"
);
$stmt->execute([':from' => $from, ':to' => $to]);
foreach ($stmt->fetchAll() as $r) {
    $entries[] = [
        'when'      => $r['created_at'],
        'source'    => detectSource($r['action'], $r['source']),
        'kind'      => $r['action'],
        'severity'  => 'info',
        'title'     => str_replace(['.', '_'], [' › ', ' '], $r['action']),
        'detail'    => $r['username'] ? "by {$r['username']}" : ($r['ip_address'] ?? null),
        'entity'    => $r['entity_type'] ? "{$r['entity_type']}#{$r['entity_id']}" : '',
        'cost'      => 0,
        'meta'      => null,
    ];
}

// 3. conversations
$stmt = db()->prepare(
    "SELECT id, phone, direction, channel, message_text, mode, current_step, created_at, intent
     FROM conversations
     WHERE created_at BETWEEN :from AND :to
     ORDER BY created_at DESC"
);
$stmt->execute([':from' => $from, ':to' => $to]);
foreach ($stmt->fetchAll() as $r) {
    $entries[] = [
        'when'      => $r['created_at'],
        'source'    => $r['channel'],
        'kind'      => 'conversation.' . $r['direction'],
        'severity'  => 'info',
        'title'     => ($r['direction'] === 'in' ? '◀ ' : '▶ ') . substr((string) $r['message_text'], 0, 120),
        'detail'    => "{$r['phone']} · step={$r['current_step']} · intent={$r['intent']}",
        'entity'    => 'phone#' . $r['phone'],
        'cost'      => 0,
        'meta'      => null,
    ];
}

// Sort merged feed
usort($entries, fn($a, $b) => strcmp($b['when'], $a['when']));

// Apply filters in PHP (we already constrained by date in SQL)
if ($source !== '') $entries = array_filter($entries, fn($e) => $e['source'] === $source);
if ($kind   !== '') $entries = array_filter($entries, fn($e) => str_starts_with($e['kind'], $kind));
if ($q      !== '') {
    $needle = mb_strtolower($q);
    $entries = array_filter($entries, fn($e) =>
        str_contains(mb_strtolower($e['title'] . ' ' . $e['detail'] . ' ' . $e['entity']), $needle)
    );
}

$entries = array_values(array_slice($entries, 0, 500));

// Source counts (for chips)
$srcCounts = ['admin'=>0,'whatsapp'=>0,'web'=>0,'agent'=>0,'ghl'=>0,'cron'=>0,'unknown'=>0];
foreach ($entries as $e) { $srcCounts[$e['source']] = ($srcCounts[$e['source']] ?? 0) + 1; }

include __DIR__ . '/_header.php';
?>

<div class="page-head no-print">
  <h1>Audit Trail</h1>
  <p class="muted">Every move tracked across the system — admin clicks, AI decisions, customer messages. Filter, then print.</p>
</div>

<div class="card no-print" style="padding:14px 18px">
  <form method="get" class="filter-bar">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="🔍 Search title, entity, detail…" style="flex:1;min-width:220px">
    <select name="source">
      <option value="">All sources</option>
      <?php foreach (['admin','whatsapp','web','agent','ghl','cron','unknown'] as $s): ?>
        <option value="<?= $s ?>" <?= $source===$s?'selected':'' ?>>
          <?= h(ucfirst($s)) ?><?= isset($srcCounts[$s]) ? ' ('.$srcCounts[$s].')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="kind">
      <option value="">All kinds</option>
      <option value="agent."        <?= $kind==='agent.'?'selected':'' ?>>Agent actions</option>
      <option value="admin."        <?= $kind==='admin.'?'selected':'' ?>>Admin actions</option>
      <option value="shop."         <?= $kind==='shop.'?'selected':'' ?>>Shop / orders</option>
      <option value="conversation." <?= $kind==='conversation.'?'selected':'' ?>>Conversations</option>
      <option value="auth."         <?= $kind==='auth.'?'selected':'' ?>>Auth</option>
    </select>
    <div class="preset-pills">
      <?php foreach (['today'=>'Today','7d'=>'7d','30d'=>'30d','mtd'=>'MTD'] as $k=>$lbl): ?>
        <a href="?preset=<?= $k ?>" class="preset <?= $preset===$k && empty($_GET['from']) ? 'active':'' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <input type="date" name="from" value="<?= h(date('Y-m-d', strtotime($from))) ?>">
    <input type="date" name="to"   value="<?= h(date('Y-m-d', strtotime($to))) ?>">
    <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
    <a href="/admin/audit.php" class="btn btn-ghost btn-sm">✕ Clear</a>
    <button type="button" onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / PDF</button>
  </form>
</div>

<div class="print-only print-head">
  <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid var(--red);padding-bottom:14px;margin-bottom:18px">
    <div><img src="/assets/img/hi-service-logo.png" alt="" style="height:42px"></div>
    <div style="text-align:right">
      <h1 style="margin:0">Audit Trail</h1>
      <p class="muted small" style="margin:4px 0 0">
        <?= h(date('d M Y', strtotime($from))) ?> – <?= h(date('d M Y', strtotime($to))) ?> · <?= count($entries) ?> entries
      </p>
    </div>
  </div>
</div>

<?php if (empty($entries)): ?>
  <div class="card"><p class="muted">No audit entries match.</p></div>
<?php else: ?>
  <div class="card" style="padding:0">
    <div class="table-wrap">
    <table class="data-table audit-table">
      <thead>
        <tr>
          <th>When</th>
          <th>Source</th>
          <th>Kind</th>
          <th>Severity</th>
          <th>Detail</th>
          <th>Entity</th>
          <th class="num">Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $e): ?>
          <tr>
            <td class="muted small" style="white-space:nowrap"><?= h(date('d M H:i:s', strtotime($e['when']))) ?></td>
            <td><span class="chan chan-<?= h($e['source']) ?>"><?= h($e['source']) ?></span></td>
            <td class="muted small"><code><?= h($e['kind']) ?></code></td>
            <td><span class="severity sev-<?= h($e['severity']) ?>"><?= h($e['severity']) ?></span></td>
            <td>
              <b><?= h($e['title']) ?></b>
              <?php if ($e['detail']): ?><div class="muted small"><?= h($e['detail']) ?></div><?php endif; ?>
            </td>
            <td class="muted small"><?= h($e['entity']) ?></td>
            <td class="num muted small"><?= $e['cost'] > 0 ? '$' . number_format($e['cost'], 6) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div><!-- /.table-wrap -->
    <div style="padding:10px 18px;background:#fafafa;border-top:1px solid var(--line);font-size:12px;color:var(--grey)">
      <?= count($entries) ?> entries shown · total agent cost in range: <b>$<?= number_format(array_sum(array_column($entries, 'cost')), 4) ?></b>
    </div>
  </div>
<?php endif; ?>

<div class="print-only print-foot">
  <p class="muted small" style="text-align:center;margin-top:18px">
    Hi-Service Gas · Audit Trail · Generated <?= h(date('d M Y H:i')) ?>
  </p>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
