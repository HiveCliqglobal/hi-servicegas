<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$status   = (string) ($_GET['status']  ?? '');
$channel  = (string) ($_GET['channel'] ?? '');
$mode     = (string) ($_GET['mode']    ?? 'live'); // live | demo | all
$from     = (string) ($_GET['from']    ?? '');
$to       = (string) ($_GET['to']      ?? '');
$q        = trim((string) ($_GET['q']  ?? ''));

$where  = [];
$params = [];
if ($status !== '' && in_array($status, ['cart','pending_payment','paid','delivered','cancelled','failed'], true)) {
    $where[] = 'status = :status'; $params[':status'] = $status;
}
if ($channel !== '' && in_array($channel, ['whatsapp','web'], true)) {
    $where[] = 'channel = :channel'; $params[':channel'] = $channel;
}
if ($mode === 'live') $where[] = 'is_demo = 0';
elseif ($mode === 'demo') $where[] = 'is_demo = 1';
if ($from !== '') { $where[] = 'DATE(created_at) >= :from'; $params[':from'] = $from; }
if ($to   !== '') { $where[] = 'DATE(created_at) <= :to';   $params[':to']   = $to; }
if ($q !== '') {
    $where[] = '(order_reference LIKE :q OR customer_name LIKE :q OR customer_phone LIKE :q OR customer_email LIKE :q)';
    $params[':q'] = "%{$q}%";
}

$sql = "SELECT * FROM orders_report";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = db()->query("SELECT status, COUNT(*) AS n FROM orders GROUP BY status")->fetchAll();
$countMap = []; foreach ($counts as $r) $countMap[$r['status']] = (int) $r['n'];

include __DIR__ . '/_header.php';
?>

<div class="page-head no-print">
  <h1>CRM · Orders</h1>
  <p class="muted">Every order ever placed, across WhatsApp and web. Click a row for the full record.</p>
</div>

<div class="card no-print" style="padding:14px 18px">
  <form method="get" class="filter-bar">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="🔍 Reference, name, phone, email…" style="flex:1;min-width:220px">
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach (['cart','pending_payment','paid','delivered','cancelled','failed'] as $s): ?>
        <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= h(ucwords(str_replace('_',' ',$s))) ?> (<?= $countMap[$s] ?? 0 ?>)</option>
      <?php endforeach; ?>
    </select>
    <select name="channel">
      <option value="">All channels</option>
      <option value="whatsapp" <?= $channel==='whatsapp'?'selected':'' ?>>WhatsApp</option>
      <option value="web"      <?= $channel==='web'?'selected':'' ?>>Web</option>
    </select>
    <div class="mode-toggle">
      <?php foreach (['live'=>'Live','demo'=>'Demo','all'=>'All'] as $k=>$lbl): ?>
        <label class="mode-opt <?= $mode === $k ? 'active' : '' ?>">
          <input type="radio" name="mode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?>>
          <span><?= $lbl ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <input type="date" name="from" value="<?= h($from) ?>" title="From">
    <input type="date" name="to"   value="<?= h($to)   ?>" title="To">
    <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
    <a href="/admin/orders.php" class="btn btn-ghost btn-sm">✕ Clear</a>
    <button type="button" onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print</button>
  </form>
</div>

<?php if (empty($rows)): ?>
  <div class="card">
    <h2>No orders match</h2>
    <p class="muted">Adjust the filters or place a test order on <a href="/shop/">the public shop</a>.</p>
  </div>
<?php else: ?>
  <div class="card" style="padding:0">
    <div class="table-wrap">
      <table class="data-table crm-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Reference</th>
            <th>Customer</th>
            <th>Delivery</th>
            <th>Items</th>
            <th class="num">Total</th>
            <th>Channel</th>
            <th>Status</th>
            <th class="no-print"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $addr = implode(', ', array_filter([$r['addr_line1'] ?? null, $r['addr_city'] ?? null, $r['addr_postal'] ?? null]));
            $del  = $r['delivery_date']
                    ? date('D d M', strtotime($r['delivery_date'])) . ($r['time_block'] ? ' · ' . $r['time_block'] : '')
                    : '—';
          ?>
            <tr>
              <td class="muted small" style="white-space:nowrap"><?= h(date('d M H:i', strtotime($r['created_at']))) ?></td>
              <td>
                <div class="cell-stack">
                  <code style="font-size:11px"><?= h($r['order_reference']) ?></code>
                  <span class="muted small"><?= $r['paid_at'] ? 'paid ' . h(date('d M', strtotime($r['paid_at']))) : '—' ?></span>
                </div>
              </td>
              <td>
                <div class="cell-stack">
                  <b><?= h($r['customer_name'] ?? '—') ?></b>
                  <span class="muted small"><?= h($r['customer_phone'] ?? '') ?></span>
                  <?php if (!empty($r['customer_email'])): ?>
                    <span class="muted small"><?= h($r['customer_email']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="cell-stack">
                  <span><?= h($del) ?></span>
                  <span class="muted small cell-truncate"><?= h($addr) ?: '—' ?></span>
                </div>
              </td>
              <td>
                <div class="cell-truncate" style="max-width:240px"><?= h($r['items_summary'] ?? '—') ?></div>
              </td>
              <td class="num"><b><?= h(money($r['total_amount'])) ?></b></td>
              <td><span class="chan chan-<?= h($r['channel']) ?>"><?= h($r['channel']) ?></span></td>
              <td><span class="status-badge status-<?= h($r['status']) ?>"><?= h(str_replace('_',' ',$r['status'])) ?></span></td>
              <td class="no-print"><a href="/admin/order-detail.php?id=<?= (int) $r['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:10px 18px;background:#fafafa;border-top:1px solid var(--line);font-size:12px;color:var(--grey)">
      Showing <?= count($rows) ?> orders <?php if ($where): ?>(filtered)<?php endif; ?> · <?= h(money(array_sum(array_column($rows, 'total_amount')))) ?> total
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
