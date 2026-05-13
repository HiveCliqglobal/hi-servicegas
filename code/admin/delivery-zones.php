<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$flash = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $stmt = db()->prepare(
                "INSERT INTO delivery_zones (suburb, postal_code, po_box_code, city, municipality, is_active, notes)
                 VALUES (:s, :p, :po, :c, :m, :a, :n)"
            );
            $stmt->execute([
                ':s'  => trim((string) ($_POST['suburb'] ?? '')),
                ':p'  => trim((string) ($_POST['postal_code'] ?? '')),
                ':po' => trim((string) ($_POST['po_box_code'] ?? '')) ?: null,
                ':c'  => trim((string) ($_POST['city'] ?? '')) ?: null,
                ':m'  => trim((string) ($_POST['municipality'] ?? '')) ?: null,
                ':a'  => isset($_POST['is_active']) ? 1 : 0,
                ':n'  => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);
            log_event('admin.zone.added', 'zone', (string) db()->lastInsertId(), null, current_user()['id'] ?? null);
            $flash = 'Zone added.';
        }
        elseif ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = db()->prepare(
                "UPDATE delivery_zones SET suburb=:s, postal_code=:p, po_box_code=:po, city=:c, municipality=:m, is_active=:a, notes=:n WHERE id=:id"
            );
            $stmt->execute([
                ':id' => $id,
                ':s'  => trim((string) ($_POST['suburb'] ?? '')),
                ':p'  => trim((string) ($_POST['postal_code'] ?? '')),
                ':po' => trim((string) ($_POST['po_box_code'] ?? '')) ?: null,
                ':c'  => trim((string) ($_POST['city'] ?? '')) ?: null,
                ':m'  => trim((string) ($_POST['municipality'] ?? '')) ?: null,
                ':a'  => isset($_POST['is_active']) ? 1 : 0,
                ':n'  => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);
            log_event('admin.zone.updated', 'zone', (string) $id);
            $flash = 'Zone updated.';
        }
        elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare("UPDATE delivery_zones SET is_active = 1 - is_active WHERE id = :id")->execute([':id' => $id]);
            log_event('admin.zone.toggled', 'zone', (string) $id);
            $flash = 'Zone toggled.';
        }
        elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare("DELETE FROM delivery_zones WHERE id = :id")->execute([':id' => $id]);
            log_event('admin.zone.deleted', 'zone', (string) $id);
            $flash = 'Zone deleted.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$zones = db()->query("SELECT * FROM delivery_zones ORDER BY suburb, postal_code")->fetchAll();

// Optional edit
$editing = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT * FROM delivery_zones WHERE id = :id");
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$csrf = csrf_token();
include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>Delivery Zones</h1>
  <p class="muted">Approved delivery suburbs. Customers ordering on the web/WhatsApp can only check out if their postal code matches one of these.</p>
</div>

<?php if ($flash): ?>
  <div class="alert alert-success"><?= h($flash) ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= h($err) ?></div>
<?php endforeach; ?>

<details class="collapsible" <?= $editing ? 'open' : '' ?>>
  <summary>
    <span><?= $editing ? '✏️ Edit zone — ' . h($editing['suburb']) : '➕ Add a new delivery zone' ?></span>
  </summary>
  <div class="collapsible-body">
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

      <div class="form-row form-row-3">
        <label>
          <span>Suburb <small>*</small></span>
          <input type="text" name="suburb" required placeholder="Strand" value="<?= h($editing['suburb'] ?? '') ?>">
        </label>
        <label>
          <span>Street code <small>*</small></span>
          <input type="text" name="postal_code" maxlength="10" required placeholder="7140" value="<?= h($editing['postal_code'] ?? '') ?>">
        </label>
        <label>
          <span>PO Box code <small>optional</small></span>
          <input type="text" name="po_box_code" maxlength="10" placeholder="7139" value="<?= h($editing['po_box_code'] ?? '') ?>">
        </label>
      </div>

      <div class="form-row form-row-3">
        <label>
          <span>City</span>
          <input type="text" name="city" value="<?= h($editing['city'] ?? 'Cape Town') ?>">
        </label>
        <label>
          <span>Municipality</span>
          <input type="text" name="municipality" placeholder="City of Cape Town" value="<?= h($editing['municipality'] ?? '') ?>">
        </label>
        <label>
          <span>Notes <small>internal</small></span>
          <input type="text" name="notes" value="<?= h($editing['notes'] ?? '') ?>">
        </label>
      </div>

      <div class="form-foot">
        <label class="inline-check">
          <input type="checkbox" name="is_active" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
          <span>Active — customers in this zone can order</span>
        </label>
        <div class="form-actions">
          <?php if ($editing): ?>
            <a href="/admin/delivery-zones.php" class="btn btn-ghost">✕ Cancel</a>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Save changes' : '➕ Add zone' ?></button>
        </div>
      </div>
    </form>
  </div>
</details>

<div class="card" style="padding:0">
  <h2 style="padding:18px 22px 0">All zones (<?= count($zones) ?>)</h2>
  <div class="table-wrap">
  <table class="data-table">
    <thead>
      <tr><th>Suburb</th><th>Street code</th><th>PO Box</th><th>Municipality</th><th>Active</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($zones as $z): ?>
        <tr style="<?= $z['is_active'] ? '' : 'opacity:.5' ?>">
          <td><b><?= h($z['suburb']) ?></b></td>
          <td><?= h($z['postal_code']) ?></td>
          <td><?= h($z['po_box_code'] ?? '—') ?></td>
          <td class="muted small"><?= h($z['municipality'] ?? $z['city'] ?? '') ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
              <button type="submit" class="toggle-pill <?= $z['is_active'] ? 'on' : 'off' ?>">
                <?= $z['is_active'] ? 'Active' : 'Inactive' ?>
              </button>
            </form>
          </td>
          <td class="td-actions">
            <a href="?edit=<?= (int) $z['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete zone <?= h($z['suburb']) ?>?')">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm danger">×</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div><!-- /.table-wrap -->
</div>

<?php include __DIR__ . '/_footer.php'; ?>
