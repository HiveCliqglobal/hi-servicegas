<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$preset  = (string) ($_GET['preset']  ?? '7d');
$groupBy = (string) ($_GET['group']   ?? 'day');
$from    = (string) ($_GET['from']    ?? '');
$to      = (string) ($_GET['to']      ?? '');
$channel = (string) ($_GET['channel'] ?? '');

if ($from === '' || $to === '') {
    $to = date('Y-m-d');
    switch ($preset) {
        case 'today':  $from = date('Y-m-d'); break;
        case '30d':    $from = date('Y-m-d', strtotime('-30 days')); break;
        case 'mtd':    $from = date('Y-m-01'); break;
        case 'qtd':    $month = (int) date('n'); $qStart = $month - (($month - 1) % 3); $from = date('Y-' . sprintf('%02d', $qStart) . '-01'); break;
        case 'ytd':    $from = date('Y-01-01'); break;
        case '7d':
        default:       $from = date('Y-m-d', strtotime('-7 days'));
    }
}

$groupExpr = match ($groupBy) {
    'week'  => "DATE_FORMAT(paid_at, '%x-W%v')",
    'month' => "DATE_FORMAT(paid_at, '%Y-%m')",
    default => "DATE(paid_at)",
};

$channelClause = '';
$params = [':from' => $from, ':to' => $to];
if ($channel !== '' && in_array($channel, ['whatsapp','web'], true)) {
    $channelClause = ' AND channel = :ch';
    $params[':ch'] = $channel;
}

// Headline numbers
$stmt = db()->prepare("
  SELECT
    COUNT(*) AS total_orders,
    COALESCE(SUM(total_amount), 0) AS total_revenue,
    COALESCE(AVG(total_amount), 0) AS avg_order,
    SUM(channel='whatsapp') AS via_whatsapp,
    SUM(channel='web') AS via_web
  FROM orders
  WHERE status IN ('paid','delivered')
    AND DATE(paid_at) BETWEEN :from AND :to
    {$channelClause}
");
$stmt->execute($params);
$headline = $stmt->fetch();

// Time-series
$stmt = db()->prepare("
  SELECT {$groupExpr} AS bucket,
         COUNT(*) AS orders,
         COALESCE(SUM(total_amount), 0) AS revenue
  FROM orders
  WHERE status IN ('paid','delivered')
    AND DATE(paid_at) BETWEEN :from AND :to
    {$channelClause}
  GROUP BY bucket
  ORDER BY bucket
");
$stmt->execute($params);
$series = $stmt->fetchAll();

$maxRevenue = max(array_column($series, 'revenue') ?: [0]);

// Top products
$stmt = db()->prepare("
  SELECT ol.product_name, SUM(ol.qty) AS qty_sold, SUM(ol.line_total) AS revenue
  FROM order_lines ol
  JOIN orders o ON o.id = ol.order_id
  WHERE o.status IN ('paid','delivered')
    AND DATE(o.paid_at) BETWEEN :from AND :to
    {$channelClause}
  GROUP BY ol.product_name
  ORDER BY revenue DESC
");
$stmt->execute($params);
$topProducts = $stmt->fetchAll();

// Source breakdown
$stmt = db()->prepare("
  SELECT channel, COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS rev
  FROM orders
  WHERE status IN ('paid','delivered')
    AND DATE(paid_at) BETWEEN :from AND :to
  GROUP BY channel
");
$stmt->execute([':from' => $from, ':to' => $to]);
$sources = $stmt->fetchAll();

include __DIR__ . '/_header.php';
?>

<div class="page-head no-print">
  <h1>Reports</h1>
  <p class="muted">Sales performance across WhatsApp + web. Filter, then click print to save a PDF.</p>
</div>

<div class="card no-print" style="padding:14px 18px">
  <form method="get" class="filter-bar">
    <div class="preset-pills">
      <?php foreach (['today'=>'Today','7d'=>'Last 7','30d'=>'Last 30','mtd'=>'MTD','qtd'=>'QTD','ytd'=>'YTD'] as $k=>$lbl): ?>
        <a href="?preset=<?= $k ?>&group=<?= h($groupBy) ?>&channel=<?= h($channel) ?>"
           class="preset <?= $preset === $k && $from === '' ? 'active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <input type="date" name="from" value="<?= h($from) ?>">
    <input type="date" name="to"   value="<?= h($to)   ?>">
    <select name="group">
      <option value="day"   <?= $groupBy==='day'?'selected':'' ?>>Group by day</option>
      <option value="week"  <?= $groupBy==='week'?'selected':'' ?>>Group by week</option>
      <option value="month" <?= $groupBy==='month'?'selected':'' ?>>Group by month</option>
    </select>
    <select name="channel">
      <option value="">All channels</option>
      <option value="whatsapp" <?= $channel==='whatsapp'?'selected':'' ?>>WhatsApp</option>
      <option value="web"      <?= $channel==='web'?'selected':'' ?>>Web</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">🔍 Apply</button>
    <button type="button" onclick="window.print()" class="btn btn-ghost btn-sm">🖨️ Print / Save PDF</button>
  </form>
</div>

<div class="print-only print-head">
  <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid var(--red);padding-bottom:14px;margin-bottom:18px">
    <div><img src="/assets/img/hi-service-logo.png" alt="" style="height:42px"></div>
    <div style="text-align:right">
      <h1 style="margin:0">Sales Report</h1>
      <p class="muted small" style="margin:4px 0 0"><?= h(date('d M Y', strtotime($from))) ?> – <?= h(date('d M Y', strtotime($to))) ?> · grouped by <?= h($groupBy) ?> <?= $channel ? '· '.h($channel) : '' ?></p>
    </div>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Orders</div>
    <div class="stat-value"><?= h((string) $headline['total_orders']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Revenue</div>
    <div class="stat-value"><?= h(money($headline['total_revenue'])) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Avg order value</div>
    <div class="stat-value"><?= h(money($headline['avg_order'])) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">WhatsApp / Web</div>
    <div class="stat-value" style="font-size:20px"><?= (int) $headline['via_whatsapp'] ?> / <?= (int) $headline['via_web'] ?></div>
  </div>
</div>

<div class="card">
  <h2>Revenue by <?= h($groupBy) ?></h2>
  <?php if (empty($series)): ?>
    <p class="muted">No data in range.</p>
  <?php else: ?>
    <div class="bar-chart">
      <?php foreach ($series as $row):
        $pct = $maxRevenue > 0 ? (float) $row['revenue'] / $maxRevenue * 100 : 0;
        $label = $row['bucket'];
        if ($groupBy === 'day' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $label)) {
          $label = date('d M', strtotime($label));
        }
      ?>
        <div class="bar-row">
          <span class="bar-label"><?= h($label) ?></span>
          <div class="bar-track">
            <div class="bar-fill" style="width:<?= number_format($pct, 1) ?>%"></div>
          </div>
          <span class="bar-value"><b><?= h(money($row['revenue'])) ?></b> <span class="muted small"><?= (int) $row['orders'] ?> orders</span></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="twocol-cards">
  <div class="card">
    <h2>Top products</h2>
    <?php if (empty($topProducts)): ?>
      <p class="muted">No data.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data-table thin">
        <thead><tr><th>Product</th><th class="num">Qty</th><th class="num">Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($topProducts as $p): ?>
            <tr>
              <td><?= h($p['product_name']) ?></td>
              <td class="num"><?= (int) $p['qty_sold'] ?></td>
              <td class="num"><b><?= h(money($p['revenue'])) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div><!-- /.table-wrap -->
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Channel breakdown</h2>
    <?php if (empty($sources)): ?>
      <p class="muted">No data.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data-table thin">
        <thead><tr><th>Channel</th><th class="num">Orders</th><th class="num">Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($sources as $s): ?>
            <tr>
              <td><span class="chan chan-<?= h($s['channel']) ?>"><?= h($s['channel']) ?></span></td>
              <td class="num"><?= (int) $s['n'] ?></td>
              <td class="num"><b><?= h(money($s['rev'])) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div><!-- /.table-wrap -->
    <?php endif; ?>
  </div>
</div>

<div class="print-only print-foot">
  <p class="muted small" style="text-align:center;margin-top:18px">
    Hi-Service Gas · Generated <?= h(date('d M Y H:i')) ?> · <?= h(env('APP_URL', '')) ?>
  </p>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
