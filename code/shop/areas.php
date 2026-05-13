<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$zones = db()->query(
    "SELECT id, suburb, postal_code, po_box_code, municipality, lat, lng
     FROM delivery_zones
     WHERE is_active = 1 AND lat IS NOT NULL AND lng IS NOT NULL
     ORDER BY suburb"
)->fetchAll();

include __DIR__ . '/_header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<section class="areas-hero">
  <div class="container">
    <p class="kicker">Where we deliver</p>
    <h1>Hi-Service delivery areas</h1>
    <p class="lead">We cover <?= count($zones) ?> suburbs across the Helderberg, Overberg and Stellenbosch. Drop your postal code below to confirm.</p>

    <form class="postal-check" id="postal-check" method="get" action="#map">
      <input type="text" name="pc" id="pc-input" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="Type your 4-digit postal code" autocomplete="postal-code">
      <button type="submit" class="btn btn-primary">🔍 Check my area</button>
    </form>
    <div id="pc-result" class="postal-result"></div>
  </div>
</section>

<section class="container" id="map">
  <div class="areas-map-wrap">
    <div id="areas-map"></div>
    <aside class="areas-list">
      <h3>Approved suburbs</h3>
      <ul>
        <?php foreach ($zones as $z): ?>
          <li data-id="<?= (int) $z['id'] ?>" data-lat="<?= h((string) $z['lat']) ?>" data-lng="<?= h((string) $z['lng']) ?>">
            <b><?= h($z['suburb']) ?></b>
            <span class="muted small">
              <?= h($z['postal_code']) ?><?php if (!empty($z['po_box_code']) && $z['po_box_code'] !== $z['postal_code']): ?> / <?= h($z['po_box_code']) ?><?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>
  </div>
  <p class="muted small areas-note">
    🚚 Some areas inside listed towns may fall outside our delivery routes. If your postal code is on the list above, you can order. If not, WhatsApp us on <a href="https://wa.me/27636935532">063 693 5532</a> — we'll often try anyway.
  </p>
</section>

<script>
const ZONES = <?= json_encode(array_map(fn($z) => [
  'id'          => (int) $z['id'],
  'suburb'      => $z['suburb'],
  'postal_code' => $z['postal_code'],
  'po_box_code' => $z['po_box_code'],
  'lat'         => (float) $z['lat'],
  'lng'         => (float) $z['lng'],
], $zones), JSON_UNESCAPED_SLASHES) ?>;

function initAreasMap() {
  if (typeof L === 'undefined') { setTimeout(initAreasMap, 100); return; }
  const map = L.map('areas-map', { scrollWheelZoom: false, zoomControl: true });

  // CartoDB Positron — clean light basemap, gives brand pins more prominence
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap · © CARTO',
    subdomains: 'abcd',
    maxZoom: 19,
  }).addTo(map);

  // Custom SVG pin — Hi-Service green gradient, white stroke, soft drop shadow, hover scale
  const pinSVG = `
    <svg width="32" height="42" viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <radialGradient id="hs-pin-grad" cx="40%" cy="35%" r="65%">
          <stop offset="0%"  stop-color="#22c55e"/>
          <stop offset="60%" stop-color="#16a34a"/>
          <stop offset="100%" stop-color="#14532d"/>
        </radialGradient>
        <filter id="hs-pin-shadow" x="-50%" y="-30%" width="200%" height="160%">
          <feDropShadow dx="0" dy="3" stdDeviation="2" flood-opacity="0.28"/>
        </filter>
      </defs>
      <path d="M16 1 C7.7 1 1 7.7 1 16 c0 11.5 15 24 15 24 s15-12.5 15-24 C31 7.7 24.3 1 16 1 z"
            fill="url(#hs-pin-grad)" stroke="#fff" stroke-width="2" filter="url(#hs-pin-shadow)"/>
      <circle cx="16" cy="15" r="5.5" fill="#fff"/>
      <circle cx="16" cy="15" r="2.5" fill="#14532d"/>
    </svg>`;

  const redIcon = L.divIcon({
    className: 'hs-marker',
    html: pinSVG,
    iconSize: [32, 42],
    iconAnchor: [16, 42],
    popupAnchor: [0, -38],
  });

  const markers = {};
  const bounds = [];

  ZONES.forEach(z => {
    const m = L.marker([z.lat, z.lng], { icon: redIcon }).addTo(map);
    m.bindPopup(`
      <b>${z.suburb}</b><br>
      <small>Postal code ${z.postal_code}${z.po_box_code && z.po_box_code !== z.postal_code ? ' or ' + z.po_box_code : ''}</small><br>
      <a href="/shop/identify.php">Order here →</a>
    `);
    markers[z.id] = m;
    bounds.push([z.lat, z.lng]);
  });

  if (bounds.length) {
    map.fitBounds(bounds, { padding: [40, 40] });
  } else {
    map.setView([-34.1, 18.85], 10);
  }

  // Sidebar click → pan + open popup
  document.querySelectorAll('.areas-list li').forEach(li => {
    li.addEventListener('click', () => {
      const id = li.dataset.id;
      const lat = parseFloat(li.dataset.lat);
      const lng = parseFloat(li.dataset.lng);
      map.setView([lat, lng], 13, { animate: true });
      if (markers[id]) markers[id].openPopup();
      li.classList.add('active');
      setTimeout(() => li.classList.remove('active'), 1500);
    });
  });

  // Postal code checker
  const form  = document.getElementById('postal-check');
  const input = document.getElementById('pc-input');
  const out   = document.getElementById('pc-result');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const pc = input.value.trim();
    if (!/^\d{4}$/.test(pc)) {
      out.innerHTML = '<div class="pc-bad">Please enter a 4-digit postal code.</div>';
      return;
    }
    const match = ZONES.find(z => z.postal_code === pc || z.po_box_code === pc);
    if (match) {
      out.innerHTML = `<div class="pc-ok">✅ <b>Yes!</b> We deliver to <b>${match.suburb}</b> (${pc}). <a href="/shop/identify.php?pc=${pc}">Start your order →</a></div>`;
      map.setView([match.lat, match.lng], 14, { animate: true });
      if (markers[match.id]) markers[match.id].openPopup();
    } else {
      out.innerHTML = `<div class="pc-bad">😢 Sorry, ${pc} isn't on our active delivery route. WhatsApp us on <a href="https://wa.me/27636935532">063 693 5532</a> — we might still be able to help.</div>`;
    }
  });

  // If ?pc= was passed in, run the check on load
  const params = new URLSearchParams(window.location.search);
  if (params.get('pc')) {
    input.value = params.get('pc');
    form.dispatchEvent(new Event('submit'));
  }
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAreasMap);
} else {
  initAreasMap();
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
