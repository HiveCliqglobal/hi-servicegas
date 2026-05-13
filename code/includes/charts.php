<?php
/**
 * charts.php — tiny SVG chart helpers (no dependencies).
 *
 * - svg_area_chart($data, $opts) — area+line trend
 * - svg_donut($slices, $opts)    — donut / pie
 * - svg_hbar_list($rows, $opts)  — horizontal bars
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

/**
 * Area chart with line stroke + gradient fill.
 *
 * @param array $data  list of ['label'=>'12 May', 'value'=>1234.50]
 * @param array $opts  width, height, color, label_formatter, value_formatter
 */
function svg_area_chart(array $data, array $opts = []): string
{
    $w  = (int) ($opts['width']  ?? 720);
    $h  = (int) ($opts['height'] ?? 180);
    $padL = 36; $padR = 14; $padT = 14; $padB = 28;
    $color = $opts['color'] ?? '#d62828';

    if (empty($data)) {
        return '<div style="height:' . $h . 'px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px">No data in range</div>';
    }

    $values = array_map(fn($d) => (float) ($d['value'] ?? 0), $data);
    $max = max($values) ?: 1;
    $count = count($data);

    $innerW = $w - $padL - $padR;
    $innerH = $h - $padT - $padB;
    $stepX = $count > 1 ? $innerW / ($count - 1) : 0;

    $points = [];
    foreach ($values as $i => $v) {
        $x = $padL + $i * $stepX;
        $y = $padT + $innerH - ($v / $max) * $innerH;
        $points[] = [$x, $y];
    }

    // Build smooth path
    $linePath = 'M ' . $points[0][0] . ' ' . $points[0][1];
    for ($i = 1; $i < count($points); $i++) {
        $linePath .= ' L ' . $points[$i][0] . ' ' . $points[$i][1];
    }
    $areaPath = $linePath . ' L ' . $points[count($points) - 1][0] . ' ' . ($padT + $innerH) .
                ' L ' . $points[0][0] . ' ' . ($padT + $innerH) . ' Z';

    // Y axis gridlines (4 lines)
    $grid = '';
    $ytick = '';
    for ($i = 0; $i <= 3; $i++) {
        $y = $padT + ($innerH * $i / 3);
        $val = $max * (1 - $i / 3);
        $grid   .= '<line x1="' . $padL . '" y1="' . $y . '" x2="' . ($padL + $innerW) . '" y2="' . $y . '" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="' . ($i === 3 ? '0' : '2,3') . '"/>';
        $ytick  .= '<text x="' . ($padL - 6) . '" y="' . ($y + 3) . '" text-anchor="end" font-size="10" fill="#94a3b8" font-family="Inter,system-ui,sans-serif">' . _fmtAxis($val) . '</text>';
    }

    // X axis labels (max 7 to avoid overlap)
    $xLabels = '';
    $labelEvery = max(1, (int) ceil($count / 7));
    foreach ($data as $i => $d) {
        if ($i % $labelEvery !== 0 && $i !== $count - 1) continue;
        $x = $padL + $i * $stepX;
        $xLabels .= '<text x="' . $x . '" y="' . ($h - 10) . '" text-anchor="middle" font-size="10" fill="#94a3b8" font-family="Inter,system-ui,sans-serif">' . htmlspecialchars((string) $d['label']) . '</text>';
    }

    // Points (small circles)
    $dots = '';
    foreach ($points as $i => $p) {
        $dots .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="3" fill="#fff" stroke="' . $color . '" stroke-width="2"><title>' . htmlspecialchars((string) $data[$i]['label']) . ': ' . _fmtAxis($values[$i]) . '</title></circle>';
    }

    $gradId = 'g-' . bin2hex(random_bytes(3));
    return <<<SVG
<svg viewBox="0 0 {$w} {$h}" width="100%" height="{$h}" style="display:block">
  <defs>
    <linearGradient id="{$gradId}" x1="0" x2="0" y1="0" y2="1">
      <stop offset="0%"  stop-color="{$color}" stop-opacity="0.18"/>
      <stop offset="100%" stop-color="{$color}" stop-opacity="0"/>
    </linearGradient>
  </defs>
  {$grid}
  <path d="{$areaPath}" fill="url(#{$gradId})"/>
  <path d="{$linePath}" fill="none" stroke="{$color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
  {$dots}
  {$ytick}
  {$xLabels}
</svg>
SVG;
}

/**
 * Donut chart.
 *
 * @param array $slices  list of ['label'=>'Paid', 'value'=>12, 'color'=>'#10b981']
 */
function svg_donut(array $slices, array $opts = []): string
{
    $size  = (int) ($opts['size'] ?? 160);
    $thick = (int) ($opts['thickness'] ?? 22);
    $r  = $size / 2;
    $rr = $r - $thick / 2;
    $cx = $r; $cy = $r;
    $total = array_sum(array_column($slices, 'value')) ?: 1;
    $start = -90;

    $paths = '';
    $legend = '<ul class="donut-legend">';
    foreach ($slices as $s) {
        $val = (float) $s['value'];
        if ($val <= 0) continue;
        $angle = ($val / $total) * 360;
        $end = $start + $angle;
        $rad1 = deg2rad($start);
        $rad2 = deg2rad($end);
        $x1 = $cx + $rr * cos($rad1);
        $y1 = $cy + $rr * sin($rad1);
        $x2 = $cx + $rr * cos($rad2);
        $y2 = $cy + $rr * sin($rad2);
        $large = $angle > 180 ? 1 : 0;
        $color = htmlspecialchars((string) ($s['color'] ?? '#d62828'));
        $paths .= '<path d="M ' . $x1 . ' ' . $y1 . ' A ' . $rr . ' ' . $rr . ' 0 ' . $large . ' 1 ' . $x2 . ' ' . $y2 . '" fill="none" stroke="' . $color . '" stroke-width="' . $thick . '"><title>' . htmlspecialchars((string) $s['label']) . ': ' . number_format($val, 0) . '</title></path>';
        $start = $end;
        $pct = round(($val / $total) * 100);
        $legend .= '<li><span class="dot" style="background:' . $color . '"></span><span class="lbl">' . htmlspecialchars((string) $s['label']) . '</span><span class="val">' . number_format($val, 0) . ' <small>· ' . $pct . '%</small></span></li>';
    }
    $legend .= '</ul>';

    return '<div class="donut-wrap"><svg viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '">' . $paths .
        '<text x="' . $cx . '" y="' . ($cy - 2) . '" text-anchor="middle" font-size="24" font-weight="700" fill="#0f172a" font-family="Inter,system-ui,sans-serif">' . number_format($total, 0) . '</text>' .
        '<text x="' . $cx . '" y="' . ($cy + 16) . '" text-anchor="middle" font-size="11" fill="#64748b" font-family="Inter,system-ui,sans-serif">total</text>' .
        '</svg>' . $legend . '</div>';
}

/**
 * Horizontal bar list — for top-N rankings.
 *
 * @param array $rows  list of ['label'=>..., 'value'=>..., 'sub'=>...]
 */
function svg_hbar_list(array $rows, array $opts = []): string
{
    if (empty($rows)) return '<p class="muted small">No data.</p>';
    $maxVal = max(array_map(fn($r) => (float) ($r['value'] ?? 0), $rows)) ?: 1;
    $color  = $opts['color'] ?? '#0f172a';

    $html = '<ul class="hbar-list">';
    foreach ($rows as $r) {
        $pct = ($r['value'] / $maxVal) * 100;
        $sub = $r['sub'] ?? '';
        $html .= '<li>'
              .  '<div class="hbar-row"><span class="hbar-label">' . htmlspecialchars((string) $r['label']) . '</span>'
              .  '<span class="hbar-value">' . htmlspecialchars((string) $r['value_fmt'] ?? number_format((float) $r['value'])) . '</span></div>'
              .  '<div class="hbar-track"><div class="hbar-fill" style="width:' . number_format($pct, 1) . '%;background:' . $color . '"></div></div>'
              .  ($sub ? '<div class="hbar-sub">' . htmlspecialchars((string) $sub) . '</div>' : '')
              .  '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/** Internal — format axis numbers compactly (1.2k, 3.4M). */
function _fmtAxis(float $v): string
{
    if ($v >= 1_000_000) return 'R ' . number_format($v / 1_000_000, 1) . 'M';
    if ($v >= 1_000)     return 'R ' . number_format($v / 1_000, 1) . 'k';
    return 'R ' . number_format($v, 0);
}
