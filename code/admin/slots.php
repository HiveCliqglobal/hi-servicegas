<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/slot_repo.php';
require_login();

$flash = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $date = (string) ($_POST['delivery_date'] ?? '');
            $block = (string) ($_POST['time_block'] ?? '');
            $cap = max(1, (int) ($_POST['capacity'] ?? 6));
            if ($date === '' || !in_array($block, ['08:00-12:00','13:00-16:30'], true)) {
                throw new RuntimeException('Pick a valid date and time block.');
            }
            $stmt = db()->prepare(
                "INSERT INTO slots (delivery_date, time_block, capacity, is_active)
                 VALUES (:d, :b, :c, 1)
                 ON DUPLICATE KEY UPDATE capacity = VALUES(capacity), is_active = 1"
            );
            $stmt->execute([':d' => $date, ':b' => $block, ':c' => $cap]);
            log_event('admin.slot.added', 'slot', $date . ' ' . $block);
            $flash = "Slot added: " . date('D d M', strtotime($date)) . " · {$block} · cap {$cap}";
        }
        elseif ($action === 'update_capacity') {
            $id = (int) ($_POST['id'] ?? 0);
            $cap = max(0, (int) ($_POST['capacity'] ?? 0));
            db()->prepare("UPDATE slots SET capacity = :c WHERE id = :id")->execute([':c' => $cap, ':id' => $id]);
            log_event('admin.slot.capacity', 'slot', (string) $id, ['capacity' => $cap]);
            $flash = 'Capacity updated.';
        }
        elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare("UPDATE slots SET is_active = 1 - is_active WHERE id = :id")->execute([':id' => $id]);
            $flash = 'Slot toggled.';
        }
        elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            // Don't delete a slot that has bookings against it
            $usage = (int) db()->query("SELECT COUNT(*) FROM orders WHERE slot_id = {$id} AND status IN ('paid','delivered','pending_payment')")->fetchColumn();
            if ($usage > 0) {
                throw new RuntimeException("Can't delete — {$usage} order(s) are using this slot. Deactivate it instead.");
            }
            db()->prepare("DELETE FROM slots WHERE id = :id")->execute([':id' => $id]);
            log_event('admin.slot.deleted', 'slot', (string) $id);
            $flash = 'Slot deleted.';
        }
        elseif ($action === 'bulk_generate') {
            $days = max(1, min(60, (int) ($_POST['days'] ?? 14)));
            $cap  = max(1, (int) ($_POST['capacity'] ?? 6));
            SlotRepo::ensureNextNDays($days, $cap);
            log_event('admin.slot.bulk_generate', null, null, ['days' => $days, 'capacity' => $cap]);
            $flash = "Generated slots for the next {$days} days (capacity {$cap} each).";
        }
        elseif ($action === 'purge_past') {
            $deleted = db()->exec("DELETE FROM slots WHERE delivery_date < CURDATE() AND id NOT IN (SELECT slot_id FROM orders WHERE slot_id IS NOT NULL)");
            log_event('admin.slot.purge_past', null, null, ['rows' => $deleted]);
            $flash = "Cleared {$deleted} past slot(s) with no associated orders.";
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ===== Fetch =====
$slots = db()->query("
  SELECT s.*,
         (SELECT COUNT(*) FROM orders WHERE slot_id = s.id AND status IN ('paid','delivered','pending_payment')) AS active_orders
  FROM slots s
  WHERE s.delivery_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
  ORDER BY s.delivery_date, s.time_block
  LIMIT 200
")->fetchAll();

// Group by date for display
$byDate = [];
foreach ($slots as $s) $byDate[$s['delivery_date']][] = $s;

$csrf = csrf_token();
include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>Delivery Slots</h1>
  <p>Capacity per morning + afternoon time block. Customers pick from these on WhatsApp + the web shop.</p>
</div>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endforeach; ?>

<details class="collapsible">
  <summary><span>➕ Add a single slot</span></summary>
  <div class="collapsible-body">
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="add">
      <div class="form-row form-row-3">
        <label>
          <span>Delivery date <small>*</small></span>
          <input type="date" name="delivery_date" required value="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>">
        </label>
        <label>
          <span>Time block <small>*</small></span>
          <select name="time_block" required>
            <option value="08:00-12:00">🌅 Morning · 08:00 – 12:00</option>
            <option value="13:00-16:30">☀️ Afternoon · 13:00 – 16:30</option>
          </select>
        </label>
        <label>
          <span>Capacity <small>max deliveries</small></span>
          <input type="number" name="capacity" min="1" max="50" value="6">
        </label>
      </div>
      <div class="form-foot">
        <span class="muted small">Existing slots for the same date+time will have their capacity overwritten.</span>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">➕ Add slot</button>
        </div>
      </div>
    </form>
  </div>
</details>

<details class="collapsible">
  <summary><span>📅 Bulk generate slots</span></summary>
  <div class="collapsible-body">
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="bulk_generate">
      <div class="form-row form-row-3">
        <label>
          <span>Days ahead</span>
          <input type="number" name="days" min="1" max="60" value="14">
        </label>
        <label>
          <span>Capacity per block</span>
          <input type="number" name="capacity" min="1" max="50" value="6">
        </label>
        <label>
          <span>Time blocks created</span>
          <input type="text" value="08:00-12:00 + 13:00-16:30" disabled>
        </label>
      </div>
      <div class="form-foot">
        <span class="muted small">Creates morning + afternoon slots for every day. Existing slots are not changed.</span>
        <div class="form-actions">
          <form method="post" style="display:inline" onsubmit="return confirm('Delete all past slots with no orders attached?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="purge_past">
            <button type="submit" class="btn btn-ghost">🗑️ Purge past slots</button>
          </form>
          <button type="submit" class="btn btn-primary">📅 Generate slots</button>
        </div>
      </div>
    </form>
  </div>
</details>

<div class="card" style="padding:0;margin-top:14px">
  <div class="card-head" style="padding:14px 22px;border-bottom:1px solid var(--grey-100)">
    <h2>Upcoming slots</h2>
    <span class="muted small"><?= count($slots) ?> slots · <?= count($byDate) ?> day(s)</span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Time</th>
          <th>Capacity</th>
          <th>Booked</th>
          <th>Fill</th>
          <th>Active</th>
          <th class="td-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($slots as $s):
          $pct = $s['capacity'] > 0 ? round($s['booked_count'] / $s['capacity'] * 100) : 0;
          $tone = $pct >= 80 ? 'danger' : ($pct >= 50 ? 'warn' : 'ok');
        ?>
          <tr style="<?= $s['is_active'] ? '' : 'opacity:.55' ?>">
            <td><b><?= h(date('D, d M Y', strtotime($s['delivery_date']))) ?></b></td>
            <td><?= $s['time_block'] === '08:00-12:00' ? '🌅 Morning' : '☀️ Afternoon' ?> <span class="muted small"><?= h($s['time_block']) ?></span></td>
            <td>
              <form method="post" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="update_capacity">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <input type="number" name="capacity" min="0" max="50" value="<?= (int) $s['capacity'] ?>" style="width:60px;height:28px;padding:0 8px;font-size:13px;border:1px solid var(--grey-200);border-radius:5px">
                <button type="submit" class="btn btn-ghost btn-sm">💾</button>
              </form>
            </td>
            <td><?= (int) $s['booked_count'] ?></td>
            <td style="min-width:140px">
              <div class="slot-fill-row">
                <div class="slot-fill-track-h"><div class="slot-fill-bar-h tone-<?= $tone ?>" style="width:<?= $pct ?>%"></div></div>
                <span class="muted small"><?= $pct ?>%</span>
              </div>
            </td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="toggle-pill <?= $s['is_active'] ? 'on' : 'off' ?>">
                  <?= $s['is_active'] ? 'Active' : 'Disabled' ?>
                </button>
              </form>
            </td>
            <td class="td-actions">
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this slot?')">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm danger" <?= $s['active_orders'] > 0 ? 'disabled title="Has '. $s['active_orders'] .' active order(s)"' : '' ?>>🗑️</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($slots)): ?>
          <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--grey-500)">No slots yet — generate some above.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
