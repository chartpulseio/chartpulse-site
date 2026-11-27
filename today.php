<?php
/**
 * /today/ (owner, 27 Nov 2026, SEO daily page): today's market report built from the live caches -- God-father A+ / A
 * setups, Confluence GO setups, GEX regime for the main markets and the biggest movers. Changes every day.
 */
declare(strict_types=1);
require __DIR__ . '/torus_seo.php';
require_once __DIR__ . '/gex_engine.php';
header('Cache-Control: public, max-age=300');
$J = fn(string $f) => is_file(T_DATA . $f) ? (json_decode((string)file_get_contents(T_DATA . $f), true) ?: []) : [];
$day = gmdate('l, j F Y');
$gf = array_values(array_filter($J('/gf2/_board1.json')['rows'] ?? [], fn($r) => in_array($r['grade'] ?? '', ['A+', 'A'], true)));
$cf = array_values(array_filter($J('/cf/_scan1.json')['rows'] ?? [], fn($r) => ($r['stage'] ?? 0) >= 4));
$GX = ['SPY' => ['spy', 'SPY'], 'QQQ' => ['qqq', 'QQQ'], 'GLD' => ['gold', 'Gold'], 'NIFTY' => ['nifty', 'NIFTY'], 'BANKNIFTY' => ['banknifty', 'BANKNIFTY'], 'BTC' => ['bitcoin', 'Bitcoin'], 'ETH' => ['ethereum', 'Ethereum']];
$n = fn($v) => $v === null ? '–' : number_format((float)$v, (float)$v >= 100 ? 2 : 4);
$title = 'Market Report Today: Best Setups, GEX & Movers | ChartPulse';
$desc = "Today's market report ($day): A+ trade setups, trend line confluence reversals, gamma regime for SPY, Gold, NIFTY and Bitcoin, and the biggest movers.";
seo_head($title, $desc, '/today/', [seo_crumbs([['Home', '/'], ['Today', '/today/']])]); ?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <span>Today</span></nav>
  <h1>Market report for today</h1>
  <p class="muted"><?= seo_e($day) ?> · built live from the ChartPulse scanners · for education, not financial advice.</p>

  <h2>Best setups today (God-father A+ and A)</h2>
<?php if ($gf) { ?>
  <table class="tbl"><thead><tr><th>Market</th><th>Side</th><th>Grade</th><th>Why</th><th>Entry</th><th>Stop</th><th>Target</th></tr></thead><tbody>
<?php foreach (array_slice($gf, 0, 15) as $r) { $p = $r['plan'] ?? []; ?>
    <tr><td><a href="<?= seo_e(seo_url($r['symbol'])) ?>"><?= seo_e($r['name']) ?></a></td><td class="<?= $r['side'] === 'CALL' ? 'up' : 'down' ?>"><?= $r['side'] ?></td><td><?= seo_e($r['grade']) ?></td>
        <td><?= seo_e($r['loc_from'] . ' · ' . $r['setup_name']) ?></td><td><?= $n($p['entry'] ?? null) ?></td><td><?= $n($p['sl'] ?? null) ?></td><td><?= $n($p['t1'] ?? null) ?></td></tr>
<?php } ?>
  </tbody></table>
  <p><a href="/scanners/godfather/">All graded setups and the live track record →</a></p>
<?php } else { ?>  <p>No A+ or A setup right now. <a href="/scanners/godfather/">Open God-father</a>.</p>
<?php } ?>

  <h2>Trend line confluence reversals</h2>
<?php if ($cf) { ?>
  <ul class="seo-grid">
<?php foreach (array_slice($cf, 0, 12) as $r) { ?>    <li><a href="/scanners/confluence/?s=<?= rawurlencode($r['symbol']) ?>"><b><?= seo_e($r['name']) ?></b><small><?= seo_e($r['bias'] . ' · zone ' . $n($r['zone'][0]) . '–' . $n($r['zone'][1]) . ' · ' . count($r['tfs']) . ' charts') ?></small><span class="chip <?= $r['bias'] === 'CALL' ? 'up' : 'down' ?>"><?= seo_e($r['stage_name']) ?></span></a></li>
<?php } ?>
  </ul>
<?php } else { ?>  <p>No confluence reversal has turned yet. <a href="/scanners/confluence/">Open the Confluence Scanner</a>.</p>
<?php } ?>

  <h2>Gamma regime today</h2>
  <table class="tbl"><thead><tr><th>Market</th><th>Regime</th><th>Call wall</th><th>Put wall</th><th>Zero gamma</th></tr></thead><tbody>
<?php foreach ($GX as $s => [$slug, $nm]) { $f = GEX_DIR . "/res_{$s}_all.json"; $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; if (!$r) continue;
  $k = 1.0; if (isset(GEX_METAL[$s])) { $m = gex_metal_proxy($s, (float)$r['spot']); if ($m) $k = (float)$m['ratio']; } ?>
    <tr><td><a href="/gex/<?= $slug ?>/"><?= seo_e($nm) ?> GEX</a></td><td class="<?= ($r['regime'] ?? '') === 'positive' ? 'up' : 'down' ?>"><?= ($r['regime'] ?? '') === 'positive' ? '+ gamma' : '− gamma' ?></td>
        <td><?= $n(($r['call_wall'] ?? 0) * $k ?: null) ?></td><td><?= $n(($r['put_wall'] ?? 0) * $k ?: null) ?></td><td><?= $n(($r['zero_gamma'] ?? 0) * $k ?: null) ?></td></tr>
<?php } ?>
  </tbody></table>

  <h2>Biggest movers today</h2>
<?php $ML = seo_movers_lists(seo_rows()); $up = array_slice($ML['gainers'] ?? [], 0, 6); $dn = array_slice($ML['losers'] ?? [], 0, 6); ?>
  <ul class="seo-grid">
<?php foreach (array_slice(array_merge((array)$up, (array)$dn), 0, 12) as $r) { if (!is_array($r) || empty($r['symbol'])) continue; ?>    <li><a href="<?= seo_e(seo_url($r['symbol'])) ?>"><b><?= seo_e(t_name($r['symbol'])) ?></b><small><?= seo_e($r['symbol']) ?></small><span class="chip <?= seo_cls($r['change_pct'] ?? 0) ?>"><?= seo_pct($r['change_pct'] ?? null) ?></span></a></li>
<?php } ?>
  </ul>
  <p><a href="/movers/">All movers and the reversal scanner →</a> · <a href="/learn/">Learn how to use these tools →</a></p>
</main>
<?php seo_foot();
