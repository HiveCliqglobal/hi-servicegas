<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/slot_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();
Cart::requireCustomer();

if (Cart::isEmpty() || empty(Cart::get()['address_id'])) {
    redirect('/shop/browse.php');
}

$error = null;
$customDate = null;
$customSlots = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slotId = (int) ($_POST['slot_id'] ?? 0);
    if ($slotId > 0) {
        Cart::set(['slot_id' => $slotId]);
        redirect('/shop/summary.php');
    }
    $customDate = trim((string) ($_POST['custom_date'] ?? ''));
    if ($customDate !== '') {
        require_once __DIR__ . '/../includes/intent_detector.php';
        $iso = IntentDetector::parseRelativeOrDate($customDate);
        if (!$iso) {
            $error = 'Please enter a date like 25/01/2026 or "tomorrow" / "monday".';
        } else {
            $closest = SlotRepo::closestTo($iso);
            $customSlots = $closest ? [['letter' => 'X', 'slot' => $closest, 'display' => SlotRepo::displayLabel($closest)]] : [];
            if (!$customSlots) $error = 'No slots near that date — please try another.';
        }
    } else {
        $error = 'Please pick a slot or type a date.';
    }
}

$slots = SlotRepo::availableSlots();

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner-wide">
    <p class="kicker">Step 6 of 6</p>
    <h1>Pick your delivery slot</h1>
    <p class="lead">Available delivery times for the next 7 days.</p>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <?php if (!empty($customSlots)): ?>
      <h3>Closest available slot for your date</h3>
      <form method="post">
        <?php foreach ($customSlots as $row): ?>
          <button type="submit" name="slot_id" value="<?= (int) $row['slot']['id'] ?>" class="slot-card">
            <strong><?= h($row['display']) ?></strong>
            <span class="muted small"><?= (int) $row['slot']['booked_count'] ?> of <?= (int) $row['slot']['capacity'] ?> booked</span>
          </button>
        <?php endforeach; ?>
      </form>
      <p><a href="/shop/slot.php">← Back to next-7-days view</a></p>
    <?php else: ?>
      <?php if (empty($slots)): ?>
        <div class="alert alert-error">No slots available in the next 7 days. Please WhatsApp <a href="https://wa.me/27636935532">063 693 5532</a>.</div>
      <?php else: ?>
        <form method="post" class="slot-grid">
          <?php foreach ($slots as $row): ?>
            <button type="submit" name="slot_id" value="<?= (int) $row['slot']['id'] ?>" class="slot-card">
              <span class="slot-letter"><?= h($row['letter']) ?></span>
              <strong><?= h($row['display']) ?></strong>
              <span class="muted small"><?= (int) ($row['slot']['capacity'] - $row['slot']['booked_count']) ?> slot<?= ($row['slot']['capacity'] - $row['slot']['booked_count']) === 1 ? '' : 's' ?> left</span>
            </button>
          <?php endforeach; ?>
        </form>
      <?php endif; ?>

      <hr style="margin:32px 0;border:0;border-top:1px solid var(--line)">

      <h3>Or pick a specific date</h3>
      <form method="post" class="step-form" style="max-width:420px">
        <label>
          <span>Type a date (e.g. 25/01/2026 or "monday")</span>
          <input type="text" name="custom_date" placeholder="25/01/2026">
        </label>
        <button type="submit" class="btn btn-ghost">Find closest slot</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>
