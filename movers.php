<?php
// Market movers (owner, 29 Sep 2026): /movers/ live and /movers/YYYY-MM-DD daily archive. Server-rendered, public data only.
declare(strict_types=1);
require __DIR__ . '/torus_seo.php';

$now = t_now(); $today = gmdate('Y-m-d', $now);
$d = (string)($_GET['d'] ?? '');
$arch = $d !== '' && $d !== $today;
if ($arch) {
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? t_read_json(SEO_MOVERS . '/' . $d . '.json', null) : null;
    if (!$data) { http_response_code(404); seo_head('No movers saved for that day | ChartPulse', 'No archive for that date.', '/movers/', [], 'noindex,follow'); ?>
<main class="page wrap seo" id="main"><h1>No movers saved for that day</h1><p><a href="/movers/">See today's movers</a>.</p></main>
<?php seo_foot(); exit; }
} else {
    $data = seo_movers_lists(seo_rows()); $d = $today;
}
header('Cache-Control: public, max-age=' . ($arch ? 86400 : 300));
$nice = gmdate('j M Y', strtotime($d . ' 00:00:00 UTC'));
$top = $data['gainers'][0] ?? null; $bot = $data['losers'][0] ?? null;
$title = ($arch ? "Biggest market movers on $nice" : "Biggest market movers today ($nice)") . ': stocks, forex, crypto | ChartPulse';
$desc = sprintf('%s: %d up, %d down across %d stocks, indices, forex, crypto and commodities.%s%s Free, updated live.',
    $arch ? "Market movers on $nice" : 'Today', $data['up'], $data['down'], $data['count'],
    $top ? sprintf(' Top gainer %s %s.', $top['name'], seo_pct($top['change_pct'])) : '', $bot ? sprintf(' Top loser %s %s.', $bot['name'], seo_pct($bot['change_pct'])) : '');
$path = $arch ? "/movers/$d" : '/movers/';
seo_head($title, $desc, $path, [seo_crumbs(array_merge([['Home', '/'], ['Movers', '/movers/']], $arch ? [[$nice, $path]] : []))]);
$table = function (string $h, array $L, string $note) { ?>
  <section class="seo-sec">
    <h2><?= seo_e($h) ?></h2><p class="muted"><?= seo_e($note) ?></p>
    <div class="card" style="padding:0;overflow:hidden"><div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>#</th><th>Instrument</th><th>Market</th><th class="num">Price</th><th class="num">Change</th><th class="num">vs usual range</th><th>Bias</th></tr></thead>
      <tbody><?php foreach ($L as $i => $x): [$w, $k] = seo_bias($x); ?>
        <tr><td><?= $i + 1 ?></td><td><a href="<?= seo_e(seo_url($x['symbol'])) ?>"><b><?= seo_e($x['name']) ?></b></a> <small><?= seo_e($x['symbol']) ?></small></td><td><?= seo_e($x['market']) ?></td>
          <td class="num"><?= seo_e(t_fp((float)$x['price'])) ?></td><td class="num <?= seo_cls($x['change_pct']) ?>"><?= seo_pct($x['change_pct']) ?></td>
          <td class="num"><?= $x['move_atr'] === null ? '–' : number_format((float)$x['move_atr'], 1) . '×' ?></td><td><span class="chip <?= $k ?>"><?= $w ?></span></td></tr>
      <?php endforeach; ?></tbody></table></div></div>
  </section>
<?php };
?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <a href="/movers/">Movers</a><?= $arch ? ' <span>/</span> <span>' . seo_e($nice) . '</span>' : '' ?></nav>
  <header class="seo-hero">
    <span class="free-tag">FREE · <?= $arch ? 'ARCHIVE' : 'LIVE' ?></span>
    <h1><?= $arch ? 'Biggest market movers on ' . seo_e($nice) : 'Biggest market movers today' ?></h1>
    <p><?= seo_e(sprintf('%d instruments tracked: %d up, %d down.', $data['count'], $data['up'], $data['down'])) ?> "vs usual range" compares the day's change with the instrument's average daily range (1.0× is a normal day). Each market's own session counts as its day. Information only: a big move can keep going or reverse.</p>
    <p><a class="btn" href="/scanners/reversal/">Rubber Band Reversal Scanner: which movers are snapping back</a> <a class="btn ghost" href="/#free">Live Big Movers panel</a> <a class="btn ghost" href="/bias/">Trend bias for every symbol</a></p>
  </header>
<?php
$table('Biggest gainers', $data['gainers'], 'Largest % rise in the current or last session.');
$table('Biggest losers', $data['losers'], 'Largest % fall in the current or last session.');
$table('Most stretched moves', $data['stretched'], 'Largest moves measured against each instrument\'s average daily range.');
$table('Top mover in each market', $data['markets'], 'The biggest move, up or down, in every market we cover.');
$dates = array_values(array_filter(seo_movers_dates(), fn($x) => $x !== $today)); ?>
<?php if ($dates): ?>
  <section class="seo-sec"><h2>Past days</h2><ul class="seo-days"><?php foreach ($dates as $x): ?><li><a href="/movers/<?= $x ?>"><?= seo_e(gmdate('D, j M Y', strtotime($x . ' 00:00:00 UTC'))) ?></a></li><?php endforeach; ?></ul></section>
<?php endif; ?>
</main>
<?php seo_foot();
