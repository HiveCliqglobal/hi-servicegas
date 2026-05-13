<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/charts.php';
require_login();
$user = current_user();

// =====================================================
// Quick action — wipe all demo data
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'wipe_demo' && csrf_verify($_POST['csrf'] ?? '')) {
    $deleted = db()->exec("DELETE FROM order_lines WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE is_demo = 1) AS t)");
    $orders  = db()->exec("DELETE FROM orders WHERE is_demo = 1");
    log_event('admin.demo.wipe', null, null, ['orders' => $orders, 'lines' => $deleted]);
    $_SESSION['flash'] = "Wiped {$orders} demo order(s) (+ {$deleted} line items).";
    redirect('/admin/?mode=demo');
}

// =====================================================
// Filters: date range + demo mode + channel
// =====================================================
$preset  = (string) ($_GET['preset']  ?? '7d');
$mode    = (string) ($_GET['mode']    ?? 'live'); // live | demo | all
$channel = (string) ($_GET['channel'] ?? '');
$from    = (string) ($_GET['from']    ?? '');
$to      = (string) ($_GET['to']      ?? '');

if ($from === '' || $to === '') {
    $to = date('Y-m-d');
    switch ($preset) {
        case 'today': $from = date('Y-m-d'); break;
        case '30d':   $from = date('Y-m-d', strtotime('-30 days')); break;
        case 'mtd':   $from = date('Y-m-01'); break;
        case 'ytd':   $from = date('Y-01-01'); break;
        case '7d':
        default:      $from = date('Y-m-d', strtotime('-7 days'));
    }
}

$params = [':from' => $from, ':to' => $to];
$where  = ['DATE(o.created_at) BETWEEN :from AND :to'];

if ($mode === 'live') $where[] = 'o.is_demo = 0';
elseif ($mode === 'demo') $where[] = 'o.is_demo = 1';

if ($channel !== '' && in_array($channel, ['whatsapp','web'], true)) {
    $where[] = 'o.channel = :channel';
    $params[':channel'] = $channel;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// =====================================================
// Headline numbers
// =====================================================
$stmt = db()->prepare("
  SELECT
    COUNT(*) AS total,
    SUM(o.status IN ('paid','delivered')) AS paid_count,
    COALESCE(SUM(CASE WHEN o.status IN ('paid','delivered') THEN o.total_amount END), 0) AS revenue,
    COALESCE(AVG(CASE WHEN o.status IN ('paid','delivered') THEN o.total_amount END), 0) AS avg_order,
    SUM(o.status = 'pending_payment') AS pending,
    SUM(o.channel = 'whatsapp') AS via_whatsapp,
    SUM(o.channel = 'web') AS via_web
  FROM orders o
  {$whereSql}
");
$stmt->execute($params);
$head = $stmt->fetch();

$activeSessions = (int) db()->query("SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()")->fetchColumn();

// Compare with the previous period (same length, ending the day before $from)
$periodDays = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
$prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($from . ' -' . $periodDays . ' day'));
$prevParams = $params;
$prevParams[':from'] = $prevFrom;
$prevParams[':to']   = $prevTo;
$stmt = db()->prepare("
  SELECT COALESCE(SUM(CASE WHEN o.status IN ('paid','delivered') THEN o.total_amount END), 0) AS revenue,
         COUNT(*) AS total
  FROM orders o
  {$whereSql}
");
$stmt->execute($prevParams);
$prev = $stmt->fetch();

function pct_change($curr, $prev): array {
    $curr = (float) $curr; $prev = (float) $prev;
    if ($prev <= 0) return ['pct' => null, 'dir' => 'flat'];
    $pct = ($curr - $prev) / $prev * 100;
    return ['pct' => $pct, 'dir' => $pct > 1 ? 'up' : ($pct < -1 ? 'down' : 'flat')];
}
$revTrend   = pct_change($head['revenue'], $prev['revenue']);
$ordersTrend= pct_change($head['total'],   $prev['total']);

// =====================================================
// Time-series for the revenue chart (day-level)
// =====================================================
$stmt = db()->prepare("
  SELECT DATE(o.created_at) AS day,
         COALESCE(SUM(CASE WHEN o.status IN ('paid','delivered') THEN o.total_amount END), 0) AS revenue,
         COUNT(*) AS orders
  FROM orders o
  {$whereSql}
  GROUP BY day
  ORDER BY day
");
$stmt->execute($params);
$series = $stmt->fetchAll();
$byDay = []; foreach ($series as $r) $byDay[$r['day']] = $r;

$chartData = [];
$cursor = strtotime($from);
$end = strtotime($to);
while ($cursor <= $end) {
    $d = date('Y-m-d', $cursor);
    $chartData[] = [
        'label' => date('j M', $cursor),
        'value' => (float) ($byDay[$d]['revenue'] ?? 0),
        'orders'=> (int)   ($byDay[$d]['orders']  ?? 0),
    ];
    $cursor += 86400;
}

// =====================================================
// Status donut
// =====================================================
$stmt = db()->prepare("
  SELECT o.status, COUNT(*) AS n
  FROM orders o
  {$whereSql}
  GROUP BY o.status
");
$stmt->execute($params);
$statusRows = $stmt->fetchAll();
$statusColors = [
    'paid'            => '#059669',
    'delivered'       => '#0f766e',
    'pending_payment' => '#d97706',
    'cart'            => '#94a3b8',
    'cancelled'       => '#dc2626',
    'failed'          => '#991b1b',
];
$donutSlices = [];
foreach ($statusRows as $r) {
    $donutSlices[] = [
        'label' => str_replace('_',' ', $r['status']),
        'value' => (int) $r['n'],
        'color' => $statusColors[$r['status']] ?? '#94a3b8',
    ];
}

// =====================================================
// Top products
// =====================================================
$stmt = db()->prepare("
  SELECT ol.product_name, SUM(ol.qty) AS qty, SUM(ol.line_total) AS revenue
  FROM order_lines ol
  JOIN orders o ON o.id = ol.order_id
  {$whereSql}
  GROUP BY ol.product_name
  ORDER BY revenue DESC
  LIMIT 5
");
$stmt->execute($params);
$topProductsRows = $stmt->fetchAll();
$topProducts = [];
foreach ($topProductsRows as $p) {
    $topProducts[] = [
        'label'     => $p['product_name'],
        'value'     => (int) $p['qty'],
        'value_fmt' => (int) $p['qty'] . ' sold',
        'sub'       => money($p['revenue']) . ' revenue',
    ];
}

// =====================================================
// Slot fill (next 7 days)
// =====================================================
$slotRows = db()->query("
  SELECT delivery_date, SUM(capacity) AS cap, SUM(booked_count) AS booked
  FROM slots
  WHERE delivery_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY)
    AND is_active = 1
  GROUP BY delivery_date
  ORDER BY delivery_date
")->fetchAll();

// =====================================================
// Channel breakdown — used for the channels donut tile
// =====================================================
$channelClause = $mode === 'live' ? 'is_demo = 0'
              : ($mode === 'demo' ? 'is_demo = 1' : '1=1');
$stmt = db()->prepare("
  SELECT channel, COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS rev
  FROM orders
  WHERE {$channelClause}
    AND DATE(created_at) BETWEEN :from AND :to
  GROUP BY channel
");
$stmt->execute([':from' => $from, ':to' => $to]);
$channelRows = $stmt->fetchAll();
$channelPalette = [
    'whatsapp' => '#25d366',
    'web'      => '#7c3aed',
    'ghl'      => '#0f766e',
    'admin'    => '#3b82f6',
];
$channelDonut = [];
$channelTotal = 0;
foreach ($channelRows as $r) {
    $channelTotal += (int) $r['n'];
    $channelDonut[] = [
        'label' => $r['channel'],
        'value' => (int) $r['n'],
        'color' => $channelPalette[$r['channel']] ?? '#94a3b8',
    ];
}

// =====================================================
// Demo insights — rich time-based view (only when demo/all)
// =====================================================
$demoInsights = null;
if ($mode === 'demo' || $mode === 'all') {
    $i = [];

    // Live-vs-demo side-by-side comparison (all time)
    $cmp = db()->query("
      SELECT
        SUM(is_demo = 0) AS live_total,
        SUM(is_demo = 0 AND status IN ('paid','delivered')) AS live_paid,
        COALESCE(SUM(CASE WHEN is_demo = 0 AND status IN ('paid','delivered') THEN total_amount END), 0) AS live_revenue,
        SUM(is_demo = 1) AS demo_total,
        SUM(is_demo = 1 AND status IN ('paid','delivered')) AS demo_paid,
        COALESCE(SUM(CASE WHEN is_demo = 1 AND status IN ('paid','delivered') THEN total_amount END), 0) AS demo_revenue
      FROM orders
    ")->fetch();
    $i['cmp'] = $cmp;

    // Demo customers
    $i['demo_customers'] = (int) db()->query(
        "SELECT COUNT(DISTINCT customer_id) FROM orders WHERE is_demo = 1"
    )->fetchColumn();

    // Conversion: paid / total
    $demoConv = ($cmp['demo_total'] ?? 0) > 0 ? ($cmp['demo_paid'] / $cmp['demo_total']) * 100 : 0;
    $liveConv = ($cmp['live_total'] ?? 0) > 0 ? ($cmp['live_paid'] / $cmp['live_total']) * 100 : 0;
    $i['demo_conv'] = $demoConv;
    $i['live_conv'] = $liveConv;

    // Time-bucketed counts: today / this week / this month / this year / all-time
    $tb = db()->query("
      SELECT
        SUM(DATE(created_at) = CURDATE())                                        AS today_n,
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND status IN ('paid','delivered') THEN total_amount END), 0) AS today_rev,
        SUM(YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1))                    AS week_n,
        COALESCE(SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND status IN ('paid','delivered') THEN total_amount END), 0) AS week_rev,
        SUM(YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())) AS month_n,
        COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND status IN ('paid','delivered') THEN total_amount END), 0) AS month_rev,
        SUM(YEAR(created_at) = YEAR(CURDATE()))                                  AS year_n,
        COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND status IN ('paid','delivered') THEN total_amount END), 0) AS year_rev,
        COUNT(*)                                                                 AS all_n,
        COALESCE(SUM(CASE WHEN status IN ('paid','delivered') THEN total_amount END), 0) AS all_rev
      FROM orders WHERE is_demo = 1
    ")->fetch();
    $i['time'] = $tb;

    // First / last demo timestamps
    $bounds = db()->query("SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM orders WHERE is_demo = 1")->fetch();
    $i['first_at'] = $bounds['first_at'];
    $i['last_at']  = $bounds['last_at'];

    // Recent demo runs (last 10)
    $i['recent'] = db()->query("
      SELECT o.id, o.order_reference, o.status, o.total_amount, o.created_at, o.channel,
             c.full_name, c.phone
      FROM orders o
      LEFT JOIN customers c ON c.id = o.customer_id
      WHERE o.is_demo = 1
      ORDER BY o.created_at DESC LIMIT 10
    ")->fetchAll();

    // Demo orders by hour-of-day
    $i['by_hour'] = db()->query("
      SELECT HOUR(created_at) AS h, COUNT(*) AS n
      FROM orders WHERE is_demo = 1
      GROUP BY h ORDER BY h
    ")->fetchAll();

    // Monthly demo activity — last 12 months
    $i['by_month'] = db()->query("
      SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
             COUNT(*) AS n,
             COALESCE(SUM(CASE WHEN status IN ('paid','delivered') THEN total_amount END), 0) AS rev
      FROM orders
      WHERE is_demo = 1 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
      GROUP BY ym ORDER BY ym
    ")->fetchAll();

    // Top demo day (the date with the most demo runs)
    $top = db()->query("
      SELECT DATE(created_at) AS d, COUNT(*) AS n
      FROM orders WHERE is_demo = 1
      GROUP BY d ORDER BY n DESC, d DESC LIMIT 1
    ")->fetch();
    $i['top_day'] = $top;

    // Most-used demo customer
    $cust = db()->query("
      SELECT c.full_name, c.phone, COUNT(*) AS n
      FROM orders o JOIN customers c ON c.id = o.customer_id
      WHERE o.is_demo = 1
      GROUP BY c.id ORDER BY n DESC LIMIT 1
    ")->fetch();
    $i['top_customer'] = $cust;

    // Channel breakdown for demos
    $i['by_channel'] = db()->query("
      SELECT channel, COUNT(*) AS n FROM orders WHERE is_demo = 1 GROUP BY channel
    ")->fetchAll();

    $demoInsights = $i;
}

include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>Dashboard</h1>
  <p>
    <?php if ($mode === 'demo'): ?>
      🧪 <b>Demo mode</b> · showing simulated orders + test data.
    <?php elseif ($mode === 'all'): ?>
      Showing <b>everything</b> — live revenue and demo runs combined.
    <?php else: ?>
      Welcome back, <?= h(explode(' ', $user['display_name'])[0] ?? '') ?>. Here's how Hi-Service is performing.
    <?php endif; ?>
  </p>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- =================== Filter bar =================== -->
<div class="card no-print filter-card" style="padding:14px 18px;margin-bottom:18px">
  <form method="get" class="filter-bar" id="dashFilters">
    <div class="preset-pills">
      <?php foreach (['today'=>'Today','7d'=>'Last 7','30d'=>'Last 30','mtd'=>'MTD','ytd'=>'YTD'] as $k=>$lbl): ?>
        <a href="?preset=<?= $k ?>&mode=<?= h($mode) ?>&channel=<?= h($channel) ?>"
           class="preset <?= $preset === $k && empty($_GET['from']) ? 'active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <input type="date" name="from" value="<?= h($from) ?>">
    <span class="muted small">→</span>
    <input type="date" name="to"   value="<?= h($to) ?>">

    <div class="mode-toggle" role="group" aria-label="Order mode">
      <?php foreach (['live'=>'🟢 Live','demo'=>'🧪 Demo','all'=>'All'] as $k=>$lbl): ?>
        <label class="mode-opt <?= $mode === $k ? 'active' : '' ?>">
          <input type="radio" name="mode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?>>
          <span><?= $lbl ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <select name="channel">
      <option value="">All channels</option>
      <option value="whatsapp" <?= $channel==='whatsapp'?'selected':'' ?>>WhatsApp</option>
      <option value="web"      <?= $channel==='web'?'selected':'' ?>>Web</option>
    </select>

    <button type="submit" class="btn btn-primary btn-sm">🔍 Apply</button>
  </form>
</div>


<!-- =================== Headline stats =================== -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Revenue</div>
    <div class="stat-value"><?= h(money($head['revenue'])) ?></div>
    <?php if ($revTrend['pct'] !== null): ?>
      <div class="stat-trend trend-<?= $revTrend['dir'] ?>">
        <?= $revTrend['dir'] === 'up' ? '▲' : ($revTrend['dir'] === 'down' ? '▼' : '·') ?>
        <?= number_format(abs($revTrend['pct']), 1) ?>% <span class="muted">vs prev period</span>
      </div>
    <?php endif; ?>
  </div>
  <div class="stat-card">
    <div class="stat-label">Orders</div>
    <div class="stat-value"><?= number_format((int) $head['total']) ?></div>
    <?php if ($ordersTrend['pct'] !== null): ?>
      <div class="stat-trend trend-<?= $ordersTrend['dir'] ?>">
        <?= $ordersTrend['dir'] === 'up' ? '▲' : ($ordersTrend['dir'] === 'down' ? '▼' : '·') ?>
        <?= number_format(abs($ordersTrend['pct']), 1) ?>% <span class="muted">vs prev period</span>
      </div>
    <?php endif; ?>
  </div>
  <div class="stat-card">
    <div class="stat-label">Avg order value</div>
    <div class="stat-value"><?= h(money($head['avg_order'])) ?></div>
    <div class="stat-trend muted"><?= (int) $head['paid_count'] ?> paid · <?= (int) $head['pending'] ?> pending</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Active sessions</div>
    <div class="stat-value"><?= $activeSessions ?></div>
    <div class="stat-trend muted"><?= (int) $head['via_whatsapp'] ?> via WhatsApp · <?= (int) $head['via_web'] ?> via Web</div>
  </div>
</div>

<!-- =================== Revenue area chart =================== -->
<div class="card">
  <div class="card-head">
    <h2>Revenue trend</h2>
    <span class="muted small"><?= h(date('d M Y', strtotime($from))) ?> – <?= h(date('d M Y', strtotime($to))) ?> · <?= count($chartData) ?> days</span>
  </div>
  <?= svg_area_chart($chartData, ['width' => 1100, 'height' => 240, 'color' => '#d62828']) ?>
</div>

<!-- =================== Three-up: status donut + channels donut + top products =================== -->
<div class="threecol-cards">
  <div class="card">
    <div class="card-head"><h2>Order status</h2><span class="muted small">All orders</span></div>
    <?= svg_donut($donutSlices, ['size' => 160]) ?>
  </div>
  <div class="card">
    <div class="card-head"><h2>Channels</h2><span class="muted small">WhatsApp vs Web</span></div>
    <?php if ($channelTotal > 0): ?>
      <?= svg_donut($channelDonut, ['size' => 160]) ?>
    <?php else: ?>
      <p class="muted">No orders in range.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h2>Top products</h2><span class="muted small">By units sold</span></div>
    <?= svg_hbar_list($topProducts, ['color' => '#0f172a']) ?>
  </div>
</div>

<!-- =================== Demo insights (only in demo/all) =================== -->
<?php if ($demoInsights): ?>
  <div class="card demo-card">
    <div class="card-head">
      <h2>🧪 Demo Insights</h2>
      <span class="muted small">Test runs only — never affects live revenue</span>
    </div>

    <!-- Side-by-side Live vs Demo -->
    <div class="cmp-grid">
      <div class="cmp-col live">
        <div class="cmp-tag">🟢 Live</div>
        <div class="cmp-num"><?= h(money($demoInsights['cmp']['live_revenue'])) ?></div>
        <div class="cmp-sub"><?= (int) $demoInsights['cmp']['live_total'] ?> orders · <?= (int) $demoInsights['cmp']['live_paid'] ?> paid · <?= number_format($demoInsights['live_conv'], 0) ?>% conv.</div>
      </div>
      <div class="cmp-divider">vs</div>
      <div class="cmp-col demo">
        <div class="cmp-tag">🧪 Demo</div>
        <div class="cmp-num"><?= h(money($demoInsights['cmp']['demo_revenue'])) ?></div>
        <div class="cmp-sub"><?= (int) $demoInsights['cmp']['demo_total'] ?> orders · <?= (int) $demoInsights['cmp']['demo_paid'] ?> paid · <?= number_format($demoInsights['demo_conv'], 0) ?>% conv.</div>
      </div>
    </div>

    <!-- Time-bucket grid: orders + revenue per period -->
    <h3 style="margin:4px 0 10px">Demo activity over time</h3>
    <div class="time-bucket-grid">
      <?php
      $tb = $demoInsights['time'];
      $buckets = [
          ['Today',      (int) $tb['today_n'], (float) $tb['today_rev']],
          ['This week',  (int) $tb['week_n'],  (float) $tb['week_rev']],
          ['This month', (int) $tb['month_n'], (float) $tb['month_rev']],
          ['This year',  (int) $tb['year_n'],  (float) $tb['year_rev']],
          ['All time',   (int) $tb['all_n'],   (float) $tb['all_rev']],
      ];
      foreach ($buckets as [$lbl, $n, $rev]):
      ?>
        <div class="tb-cell">
          <div class="tb-lbl"><?= h($lbl) ?></div>
          <div class="tb-num"><?= number_format($n) ?> <span class="muted small">runs</span></div>
          <div class="tb-rev"><?= h(money($rev)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Monthly demo trend chart -->
    <?php if (!empty($demoInsights['by_month'])): ?>
      <h3 style="margin:18px 0 10px">Monthly trend <span class="muted small">— last 12 months</span></h3>
      <?php
        // Build a 12-month array (zero-fill missing months)
        $monthMap = [];
        foreach ($demoInsights['by_month'] as $r) $monthMap[$r['ym']] = $r;
        $monthSeries = [];
        for ($i = 11; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} month"));
            $monthSeries[] = [
                'label' => date('M y', strtotime($ym . '-01')),
                'value' => (float) ($monthMap[$ym]['rev'] ?? 0),
                'n'     => (int)   ($monthMap[$ym]['n']   ?? 0),
            ];
        }
      ?>
      <?= svg_area_chart($monthSeries, ['width' => 1100, 'height' => 160, 'color' => '#d97706']) ?>
    <?php endif; ?>

    <!-- Aggregate facts row -->
    <div class="demo-stats" style="margin-top:14px">
      <div>
        <div class="demo-stat-label">Demo customers</div>
        <div class="demo-stat-value"><?= (int) $demoInsights['demo_customers'] ?></div>
      </div>
      <div>
        <div class="demo-stat-label">Conversion delta</div>
        <div class="demo-stat-value">
          <?php $delta = $demoInsights['demo_conv'] - $demoInsights['live_conv']; ?>
          <?= ($delta >= 0 ? '+' : '') . number_format($delta, 1) ?>%
        </div>
      </div>
      <div>
        <div class="demo-stat-label">First demo run</div>
        <div class="demo-stat-value" style="font-size:13px">
          <?= $demoInsights['first_at'] ? h(date('d M Y H:i', strtotime($demoInsights['first_at']))) : '—' ?>
        </div>
      </div>
      <div>
        <div class="demo-stat-label">Latest demo run</div>
        <div class="demo-stat-value" style="font-size:13px">
          <?= $demoInsights['last_at'] ? h(date('d M Y H:i', strtotime($demoInsights['last_at']))) : '—' ?>
        </div>
      </div>
      <div>
        <div class="demo-stat-label">Most active day</div>
        <div class="demo-stat-value" style="font-size:13px">
          <?php if ($demoInsights['top_day']): ?>
            <?= h(date('d M Y', strtotime($demoInsights['top_day']['d']))) ?>
            <span class="muted small">· <?= (int) $demoInsights['top_day']['n'] ?> runs</span>
          <?php else: ?>—<?php endif; ?>
        </div>
      </div>
      <div>
        <div class="demo-stat-label">Top demo customer</div>
        <div class="demo-stat-value" style="font-size:13px">
          <?php if ($demoInsights['top_customer']): ?>
            <?= h($demoInsights['top_customer']['full_name']) ?>
            <span class="muted small">· <?= (int) $demoInsights['top_customer']['n'] ?> runs</span>
          <?php else: ?>—<?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!empty($demoInsights['recent'])): ?>
      <h3 style="margin-top:18px">Recent demo runs</h3>
      <div class="table-wrap">
        <table class="data-table thin">
          <thead><tr><th>When</th><th>Ref</th><th>Customer</th><th>Channel</th><th class="num">Total</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($demoInsights['recent'] as $r): ?>
            <tr>
              <td class="muted small" style="white-space:nowrap"><?= h(date('d M H:i', strtotime($r['created_at']))) ?></td>
              <td><code style="font-size:11px"><?= h($r['order_reference']) ?></code></td>
              <td><?= h($r['full_name'] ?? '—') ?> <span class="muted small"><?= h($r['phone'] ?? '') ?></span></td>
              <td><span class="chan chan-<?= h($r['channel']) ?>"><?= h($r['channel']) ?></span></td>
              <td class="num"><b><?= h(money($r['total_amount'])) ?></b></td>
              <td><span class="status-badge status-<?= h($r['status']) ?>"><?= h(str_replace('_',' ',$r['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (!empty($demoInsights['by_hour'])): ?>
      <h3 style="margin-top:18px">Demo activity by hour</h3>
      <div class="hour-grid">
        <?php
          $byHour = [];
          foreach ($demoInsights['by_hour'] as $h) $byHour[(int)$h['h']] = (int)$h['n'];
          $maxH = max($byHour ?: [1]);
          for ($hr = 0; $hr < 24; $hr++):
            $n = $byHour[$hr] ?? 0;
            $pct = $maxH > 0 ? $n / $maxH * 100 : 0;
        ?>
          <div class="hour-cell" title="<?= $hr ?>:00 · <?= $n ?> demo(s)">
            <div class="hour-bar" style="height:<?= max(2, (int) $pct) ?>%"></div>
            <div class="hour-lbl"><?= sprintf('%02d', $hr) ?></div>
          </div>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

    <div class="demo-actions" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--grey-100);display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center">
      <div>
        <span class="muted small">Need a clean slate?</span>
      </div>
      <form method="post" onsubmit="return confirm('Delete ALL <?= (int) $demoInsights['cmp']['demo_total'] ?> demo order(s)? This cannot be undone (live orders untouched).')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="wipe_demo">
        <button type="submit" class="btn btn-ghost danger">🗑️ Wipe all demo data</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- =================== Slot fill calendar =================== -->
<div class="card">
  <div class="card-head"><h2>Delivery slot pressure</h2><span class="muted small">Next 7 days · <a href="/admin/slots.php">Manage slots →</a></span></div>
  <div class="slot-strip">
    <?php
    $today = date('Y-m-d');
    foreach ($slotRows as $r):
        $cap = (int) $r['cap']; $bk = (int) $r['booked'];
        $pct = $cap > 0 ? round($bk / $cap * 100) : 0;
        $tone = $pct >= 80 ? 'danger' : ($pct >= 50 ? 'warn' : 'ok');
        $isToday = $r['delivery_date'] === $today;
    ?>
      <div class="slot-day <?= $isToday ? 'is-today' : '' ?>">
        <div class="slot-day-date"><?= h(date('D', strtotime($r['delivery_date']))) ?></div>
        <div class="slot-day-num"><?= h(date('j', strtotime($r['delivery_date']))) ?></div>
        <div class="slot-day-fill">
          <div class="slot-fill-track"><div class="slot-fill-bar tone-<?= $tone ?>" style="height:<?= $pct ?>%"></div></div>
        </div>
        <div class="slot-day-stat"><b><?= $bk ?></b><span class="muted">/<?= $cap ?></span></div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($slotRows)): ?>
      <p class="muted">No active slots in the next 7 days. <a href="/admin/slots.php">Create some →</a></p>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
