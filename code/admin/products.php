<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/xero.php';
require_once __DIR__ . '/../includes/xero_sync.php';
require_login();

$flash      = null;
$errors     = [];
$xeroResult = null;     // populated when admin clicks the Xero sync button

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'xero_sync') {
            $xeroResult = XeroSync::syncItems();
            if (!empty($xeroResult['ok'])) {
                $flash = sprintf(
                    '✅ Synced from Xero — pulled %d items · %d created · %d updated%s. New items are HIDDEN by default — tick them below to put them on the menu.',
                    $xeroResult['pulled'], $xeroResult['created'], $xeroResult['updated'],
                    $xeroResult['orphans'] > 0 ? ' · ' . $xeroResult['orphans'] . ' local orphan(s)' : ''
                );
            } else {
                $errors[] = '✗ Xero sync failed: ' . implode('; ', $xeroResult['errors'] ?? ['unknown error']);
            }
        }
        elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare("UPDATE products SET is_active = 1 - is_active WHERE id = :id")->execute([':id' => $id]);
            log_event('admin.product.toggled', 'product', (string) $id);
            $flash = 'Whitelist updated.';
        }
        elseif ($action === 'bulk') {
            $approved = array_map('intval', (array) ($_POST['approved'] ?? []));
            $allIds   = array_map(fn($p) => (int) $p['id'], db()->query("SELECT id FROM products")->fetchAll());
            if ($approved) {
                $in = implode(',', array_map('intval', $approved));
                db()->exec("UPDATE products SET is_active = 1 WHERE id IN ({$in})");
                $offIds = array_diff($allIds, $approved);
                if ($offIds) {
                    $off = implode(',', array_map('intval', $offIds));
                    db()->exec("UPDATE products SET is_active = 0 WHERE id IN ({$off})");
                }
            } else {
                db()->exec("UPDATE products SET is_active = 0");
            }
            log_event('admin.product.bulk_updated', null, null, ['approved' => $approved]);
            $flash = 'Whitelist saved (' . count($approved) . ' products approved).';
        }
        elseif ($action === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare(
                "UPDATE products SET name=:n, price=:p, sort_order=:so WHERE id=:id"
            )->execute([
                ':id' => $id,
                ':n'  => trim((string) ($_POST['name'] ?? '')),
                ':p'  => (float) ($_POST['price'] ?? 0),
                ':so' => (int) ($_POST['sort_order'] ?? 100),
            ]);
            log_event('admin.product.edited', 'product', (string) $id);
            $flash = 'Product updated.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Stock filter — ADMIN-ONLY view filter. Does NOT change what customers see.
// Customer catalogue (WhatsApp + web shop) is always `is_active = 1` regardless of stock.
$stockFilter = (string) ($_GET['stock'] ?? 'all');
$where = '1=1';
switch ($stockFilter) {
    case 'in_stock':   $where = 'is_tracked = 1 AND in_stock_qty > 0'; break;
    case 'out_stock':  $where = 'is_tracked = 1 AND in_stock_qty <= 0'; break;
    case 'untracked':  $where = 'is_tracked = 0'; break;
    case 'approved':   $where = 'is_active = 1'; break;
    case 'hidden':     $where = 'is_active = 0'; break;
    case 'risk':       $where = 'is_active = 1 AND is_tracked = 1 AND in_stock_qty <= 0'; break;  // approved-but-out-of-stock
}
$products = db()->query("SELECT * FROM products WHERE {$where} ORDER BY sort_order, name")->fetchAll();
$active   = (int) db()->query("SELECT COUNT(*) c FROM products WHERE is_active = 1")->fetch()['c'];
$totalAll = (int) db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'];
$riskCnt  = (int) db()->query("SELECT COUNT(*) c FROM products WHERE is_active = 1 AND is_tracked = 1 AND in_stock_qty <= 0")->fetch()['c'];

$editing = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$csrf = csrf_token();
include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>Approved Products</h1>
  <p class="muted">The Xero whitelist. Only products with the green toggle appear on WhatsApp + the public web shop. Untoggle a product to hide it from customers without deleting it.</p>
</div>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endforeach; ?>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Total in DB</div>
    <div class="stat-value"><?= $totalAll ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Customer-facing</div>
    <div class="stat-value" style="color:var(--success)"><?= $active ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Hidden</div>
    <div class="stat-value" style="color:var(--grey)"><?= $totalAll - $active ?></div>
  </div>
  <div class="stat-card" style="<?= $riskCnt > 0 ? 'background:#fef2f2;border:1px solid #fecaca' : '' ?>">
    <div class="stat-label">⚠ Approved &amp; out of stock</div>
    <div class="stat-value" style="color:<?= $riskCnt > 0 ? '#dc2626' : 'var(--grey)' ?>"><?= $riskCnt ?></div>
  </div>
</div>

<div class="card" style="padding:14px 18px;margin-bottom:14px">
  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
    <span class="muted small" style="margin-right:6px"><b>Admin view filter:</b></span>
    <?php
      $chips = [
        'all'       => 'All ('       . $totalAll . ')',
        'approved'  => '✓ Approved (' . $active   . ')',
        'hidden'    => '○ Hidden ('   . ($totalAll - $active) . ')',
        'in_stock'  => '📦 In stock',
        'out_stock' => '⊘ Out of stock',
        'risk'      => '⚠ Approved AND out of stock (' . $riskCnt . ')',
        'untracked' => '— Untracked',
      ];
      foreach ($chips as $k => $label):
        $on = $stockFilter === $k;
    ?>
      <a href="?stock=<?= h($k) ?>" class="btn btn-sm <?= $on ? 'btn-primary' : 'btn-ghost' ?>" style="text-decoration:none"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted small" style="margin:8px 0 0">
    These filters change what YOU see in this admin table — they don't affect customers.
    Customer catalogue (WhatsApp + web shop) ALWAYS shows everything you've marked Approved, regardless of stock.
    Use the <b>⚠ Approved AND out of stock</b> filter to find products that need your attention.
  </p>
</div>

<?php if ($editing): ?>
  <details class="collapsible" open>
    <summary>
      <span>✏️ Editing — <?= h($editing['name']) ?></span>
    </summary>
    <div class="collapsible-body">
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

        <div class="form-row form-row-3">
          <label>
            <span>Product name <small>*</small></span>
            <input type="text" name="name" value="<?= h($editing['name']) ?>" required>
          </label>
          <label>
            <span>Price <small>ZAR</small></span>
            <input type="number" name="price" step="0.01" min="0" value="<?= h((string) $editing['price']) ?>" required>
          </label>
          <label>
            <span>Sort order <small>lower = first</small></span>
            <input type="number" name="sort_order" value="<?= h((string) $editing['sort_order']) ?>">
          </label>
        </div>

        <div class="form-foot">
          <div class="muted small">Code: <code><?= h($editing['code']) ?></code></div>
          <div class="form-actions">
            <a href="/admin/products.php" class="btn btn-ghost">✕ Cancel</a>
            <button type="submit" class="btn btn-primary">💾 Save</button>
          </div>
        </div>
      </form>
    </div>
  </details>
<?php endif; ?>

<div class="card" style="padding:0">
  <h2 style="padding:18px 22px 0">Catalogue</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="bulk">
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th></th><th></th><th>Code</th><th>Name</th><th>Price</th><th>Stock</th><th>Sort</th><th>On menu</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr style="<?= $p['is_active'] ? '' : 'opacity:.55' ?>">
            <td><input type="checkbox" name="approved[]" value="<?= (int) $p['id'] ?>" <?= $p['is_active'] ? 'checked' : '' ?>></td>
            <td>
              <?php if (!empty($p['image_url'])): ?>
                <img src="<?= h($p['image_url']) ?>" alt="" style="width:42px;height:42px;object-fit:contain">
              <?php endif; ?>
            </td>
            <td><code><?= h($p['code']) ?></code></td>
            <td><b><?= h($p['name']) ?></b></td>
            <td><b><?= h(money($p['price'])) ?></b></td>
            <td class="muted small">
              <?php if (!$p['is_tracked']): ?>
                <span style="color:var(--grey)">—</span>
              <?php elseif ((int) $p['in_stock_qty'] <= 0): ?>
                <span style="color:#dc2626;font-weight:600"<?= $p['is_active'] ? ' title="⚠ Approved but out of stock — customers can still order this!"' : '' ?>>0</span>
                <?= $p['is_active'] ? ' ⚠' : '' ?>
              <?php elseif ((int) $p['in_stock_qty'] < 5): ?>
                <span style="color:#d97706;font-weight:600"><?= (int) $p['in_stock_qty'] ?></span>
              <?php else: ?>
                <span style="color:#15803d"><?= (int) $p['in_stock_qty'] ?></span>
              <?php endif; ?>
            </td>
            <td class="muted small"><?= (int) $p['sort_order'] ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="toggle-pill <?= $p['is_active'] ? 'on' : 'off' ?>">
                  <?= $p['is_active'] ? 'Approved' : 'Hidden' ?>
                </button>
              </form>
            </td>
            <td class="td-actions"><a href="?edit=<?= (int) $p['id'] ?>" class="btn btn-ghost btn-sm">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div><!-- /.table-wrap -->
    <div style="padding:14px 22px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">
      <span class="muted small">Tick the checkboxes for products you want approved, then save.</span>
      <button type="submit" class="btn btn-primary">💾 Save whitelist</button>
    </div>
  </form>
</div>

<?php
$xeroConn = Xero::isConnected() ? Xero::connectionInfo() : null;
?>
<div class="card" style="<?= $xeroConn ? 'background:#f0fdf4;border-left:3px solid #15803d' : 'background:#fffaf0;border-left:3px solid var(--warn)' ?>">
  <h3 style="margin-top:0">🔌 Sync from Xero</h3>
  <?php if ($xeroConn): ?>
    <p>Connected to <b><?= h($xeroConn['tenant_name']) ?></b>. Pulls every active sale item from Xero into the catalogue above. Existing approval state is preserved — new items default to <b>Hidden</b>, you decide what shows to customers.</p>
    <form method="post" style="display:flex;gap:10px;align-items:center">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="xero_sync">
      <button type="submit" class="btn btn-primary">🔄 Sync items now</button>
      <span class="muted small">Sync also runs daily via cron (when wired). Manual sync is safe to run any time.</span>
    </form>
  <?php else: ?>
    <p>Xero is not yet connected. Visit <a href="/admin/connections.php">Connected Accounts</a> to link it.</p>
  <?php endif; ?>

  <?php if ($xeroResult): ?>
    <details style="margin-top:14px" <?= empty($xeroResult['ok']) ? 'open' : '' ?>>
      <summary><b>Sync details</b></summary>
      <div style="margin-top:8px;font-family:'SF Mono',Menlo,monospace;font-size:12.5px;background:#fff;border:1px solid var(--line);border-radius:6px;padding:10px;white-space:pre-wrap"><?php
        echo h(json_encode($xeroResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      ?></div>
    </details>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
