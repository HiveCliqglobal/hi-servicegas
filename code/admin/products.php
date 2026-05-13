<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$flash = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'toggle') {
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

$products = db()->query("SELECT * FROM products ORDER BY sort_order, name")->fetchAll();
$active   = count(array_filter($products, fn($p) => $p['is_active']));

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
    <div class="stat-label">Total products</div>
    <div class="stat-value"><?= count($products) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">On the menu</div>
    <div class="stat-value" style="color:var(--success)"><?= $active ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Hidden</div>
    <div class="stat-value" style="color:var(--grey)"><?= count($products) - $active ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Source</div>
    <div class="stat-value" style="font-size:14px">📦 Synced from Xero</div>
  </div>
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
            <td class="muted small"><?= $p['is_tracked'] ? (int) $p['in_stock_qty'] : '—' ?></td>
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

<div class="card" style="background:#fffaf0;border-left:3px solid var(--warn)">
  <h3 style="margin-top:0">🔌 Sync from Xero</h3>
  <p>Xero is not yet connected. Once we wire it (Stage 3 day 6), a <code>Sync now</code> button appears here that pulls every active Xero item into this table. Approved products stay approved across syncs.</p>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
