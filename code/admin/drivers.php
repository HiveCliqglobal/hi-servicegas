<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$flash = null; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $name  = trim((string) $_POST['name']);
            $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
            if (strlen($phone) >= 9 && substr($phone, 0, 1) === '0') $phone = '27' . substr($phone, 1);
            $pin   = (string) ($_POST['pin'] ?? '');
            if (!preg_match('/^\d{4}$/', $pin)) throw new RuntimeException('PIN must be 4 digits.');
            if ($name === '' || strlen($phone) < 9) throw new RuntimeException('Name and valid phone required.');
            $color = $_POST['color'] ?: '#0f7a52';
            db()->prepare(
                "INSERT INTO drivers (name, phone, email, vehicle_reg, pin_hash, avatar_color)
                 VALUES (:n, :p, :e, :v, :h, :c)"
            )->execute([
                ':n' => $name, ':p' => $phone, ':e' => $_POST['email'] ?: null,
                ':v' => $_POST['vehicle_reg'] ?: null,
                ':h' => password_hash($pin, PASSWORD_BCRYPT, ['cost' => 11]),
                ':c' => $color,
            ]);
            log_event('admin.driver.added', 'driver', (string) db()->lastInsertId(), ['name' => $name]);
            $flash = "Driver '{$name}' added with PIN {$pin}.";
        }
        elseif ($action === 'reset_pin') {
            $id = (int) $_POST['id'];
            $pin = (string) ($_POST['pin'] ?? '');
            if (!preg_match('/^\d{4}$/', $pin)) throw new RuntimeException('PIN must be 4 digits.');
            db()->prepare("UPDATE drivers SET pin_hash = :h WHERE id = :id")
                ->execute([':h' => password_hash($pin, PASSWORD_BCRYPT, ['cost' => 11]), ':id' => $id]);
            log_event('admin.driver.pin_reset', 'driver', (string) $id);
            $flash = "PIN reset to {$pin}.";
        }
        elseif ($action === 'toggle') {
            $id = (int) $_POST['id'];
            db()->prepare("UPDATE drivers SET is_active = 1 - is_active WHERE id = :id")->execute([':id' => $id]);
            $flash = 'Driver toggled.';
        }
        elseif ($action === 'delete') {
            $id = (int) $_POST['id'];
            db()->prepare("DELETE FROM drivers WHERE id = :id")->execute([':id' => $id]);
            log_event('admin.driver.deleted', 'driver', (string) $id);
            $flash = 'Driver deleted.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$drivers = db()->query(
    "SELECT d.*,
            (SELECT COUNT(*) FROM orders WHERE assigned_driver_id = d.id AND status = 'delivered') AS total_delivered,
            (SELECT COUNT(*) FROM orders WHERE assigned_driver_id = d.id AND status = 'delivered' AND DATE(delivered_at) = CURDATE()) AS today_count
     FROM drivers d ORDER BY d.is_active DESC, d.name"
)->fetchAll();

$csrf = csrf_token();
include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <h1>Drivers</h1>
  <p>Accounts that can sign into the Hi-Service driver app at <code>/driver/</code>.</p>
</div>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endforeach; ?>

<details class="collapsible">
  <summary><span>➕ Add a new driver</span></summary>
  <div class="collapsible-body">
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="add">
      <div class="form-row form-row-3">
        <label><span>Name <small>*</small></span><input type="text" name="name" required placeholder="Daniel Mokoena"></label>
        <label><span>Phone <small>*</small></span><input type="tel" name="phone" required placeholder="084 123 4567"></label>
        <label><span>4-digit PIN <small>*</small></span><input type="text" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" required placeholder="1234"></label>
      </div>
      <div class="form-row form-row-3">
        <label><span>Vehicle reg <small>optional</small></span><input type="text" name="vehicle_reg" placeholder="CA 123-456"></label>
        <label><span>Email <small>optional</small></span><input type="email" name="email" placeholder="driver@hiservice.co.za"></label>
        <label><span>Avatar colour</span><input type="color" name="color" value="#0f7a52" style="height:38px;padding:3px"></label>
      </div>
      <div class="form-foot">
        <span class="muted small">PIN is bcrypt-hashed before storage. Share it with the driver out-of-band.</span>
        <button type="submit" class="btn btn-primary">➕ Add driver</button>
      </div>
    </form>
  </div>
</details>

<div class="card" style="padding:0">
  <div class="card-head" style="padding:14px 22px;border-bottom:1px solid var(--grey-100)">
    <h2>All drivers</h2>
    <span class="muted small"><?= count($drivers) ?> total · <?= count(array_filter($drivers, fn($d) => $d['is_active'])) ?> active</span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th></th><th>Name</th><th>Phone</th><th>Vehicle</th><th>Today</th><th>Lifetime</th><th>Active</th><th class="td-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($drivers as $d): ?>
        <tr style="<?= $d['is_active'] ? '' : 'opacity:.5' ?>">
          <td>
            <div style="width:32px;height:32px;border-radius:50%;background:<?= h($d['avatar_color']) ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px">
              <?php
                $parts = preg_split('/\s+/', $d['name']);
                $f = mb_substr($parts[0] ?? '', 0, 1);
                $l = mb_substr(end($parts) ?: '', 0, 1);
                echo strtoupper($f . ($l !== $f ? $l : ''));
              ?>
            </div>
          </td>
          <td><b><?= h($d['name']) ?></b><div class="muted small"><?= h($d['email'] ?? '') ?></div></td>
          <td><?= h($d['phone']) ?></td>
          <td class="muted small"><?= h($d['vehicle_reg'] ?? '—') ?></td>
          <td><?= (int) $d['today_count'] ?></td>
          <td><?= (int) $d['total_delivered'] ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <button type="submit" class="toggle-pill <?= $d['is_active'] ? 'on' : 'off' ?>">
                <?= $d['is_active'] ? 'Active' : 'Disabled' ?>
              </button>
            </form>
          </td>
          <td class="td-actions">
            <form method="post" style="display:inline" onsubmit="const p = prompt('New 4-digit PIN for <?= h($d['name']) ?>?'); if (!p || !/^\d{4}$/.test(p)) return false; this.pin.value = p; return true;">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="reset_pin">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <input type="hidden" name="pin" value="">
              <button type="submit" class="btn btn-ghost btn-sm">🔑 PIN</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete <?= h($d['name']) ?>?')">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm danger">🗑️</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="background:#eff6ff;border-color:#bfdbfe;margin-top:14px">
  <h3 style="margin-top:0;color:#1e40af">📲 How drivers use it</h3>
  <ol style="margin:6px 0 0;padding-left:20px;font-size:13.5px">
    <li>Driver opens <code>https://hiservice.store/driver/</code> in Chrome on their phone.</li>
    <li>Selects their name + types their PIN.</li>
    <li>Chrome offers to "Add to Home Screen" — once installed it looks/feels like a native app.</li>
    <li>Tap a pending order → tap <b>✅ Mark delivered</b> with a note → it moves to Delivered.</li>
    <li>Admin sees the order flip to <code>status=delivered</code> in real time on the CRM dashboard.</li>
  </ol>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
