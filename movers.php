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
    // Live /movers/ IS the Movers Reversal Scanner (owner, 13 Nov 2026: one movers scanner on the site).
    // The scanner app (reversal.html) is served here with today's gainers / losers rendered server-side under it,
    // so search engines keep reading the movers lists this URL has ranked for. Archive days stay below.
    require_once __DIR__ . '/tl_engine.php';
    $rows = array_values(array_filter(seo_rows(), fn($r) => ($r['change_pct'] ?? null) !== null && ($r['price'] ?? 0) > 0));
    $cls = fn($r) => tl_class((string)$r['symbol']);
    $mk = function (array $L, string $by) use ($cls) {   // overall top 15 + top 10 per market class, de-duplicated
        usort($L, fn($a, $b) => $by === 'g' ? $b['change_pct'] <=> $a['change_pct'] : ($by === 'l' ? $a['change_pct'] <=> $b['change_pct'] : ($b['move_atr'] ?? 0) <=> ($a['move_atr'] ?? 0)));
        $out = []; $per = []; $i = 0;
        foreach ($L as $r) { $c = $cls($r); $all = $i++ < 15; $per[$c] = ($per[$c] ?? 0) + 1;
            if ($all || $per[$c] <= 10) $out[] = $r + ['_c' => $c, '_a' => $all]; }
        return $out;
    };
    $up = count(array_filter($rows, fn($r) => $r['change_pct'] > 0)); $dn = count(array_filter($rows, fn($r) => $r['change_pct'] < 0));
    $tbl = function (string $h, string $note, array $L) {
        $o = '<section class="mv-sec"><h2>' . seo_e($h) . '</h2><p>' . seo_e($note) . '</p><div class="mv-wrap"><table class="mv-t"><thead><tr><th>Market</th><th>Group</th><th class="n">Price</th><th class="n">Change</th><th class="n">vs usual range</th></tr></thead><tbody>';
        foreach ($L as $x) { $ch = (float)$x['change_pct'];
            $o .= '<tr data-c="' . seo_e($x['_c']) . '"' . ($x['_a'] ? ' data-a="1"' : ' hidden') . '><td><a href="' . seo_e(seo_url($x['symbol'])) . '"><b>' . seo_e($x['name']) . '</b></a><small>' . seo_e($x['symbol']) . '</small></td><td>' . seo_e($x['market']) . '</td>'
                . '<td class="n">' . seo_e(t_fp((float)$x['price'])) . '</td><td class="n" style="color:var(--' . ($ch >= 0 ? 'up' : 'dn') . ')">' . seo_pct($ch) . '</td><td class="n">' . (($x['move_atr'] ?? null) === null ? '–' : number_format((float)$x['move_atr'], 1) . '×') . '</td></tr>'; }
        return $o . '</tbody></table></div></section>';
    };
    $ssr = '<section class="mv-sec"><h2>Today\'s market movers</h2><p>' . seo_e(sprintf('%d markets tracked: %d up, %d down. "vs usual range" compares today\'s move with the market\'s average daily range (1.0× is a normal day). The market filter above applies here too.', count($rows), $up, $dn)) . '</p></section>'
        . $tbl('Biggest gainers', 'Largest % rise in the current or last session.', $mk($rows, 'g'))
        . $tbl('Biggest losers', 'Largest % fall in the current or last session.', $mk($rows, 'l'))
        . $tbl('Most stretched moves', 'Largest moves against each market\'s average daily range: the ones the scanner above watches for a snap-back.', $mk(array_map(fn($r) => $r + ['move_atr' => $r['move_atr'] ?? null], $rows), 's'));
    $dates = array_values(array_filter(seo_movers_dates(), fn($x) => $x !== $today));
    if ($dates) { $ssr .= '<section class="mv-sec"><h2>Past days</h2><p>The movers list saved at the end of each day.</p><ul class="mv-days">';
        foreach ($dates as $x) $ssr .= '<li><a href="/movers/' . $x . '">' . seo_e(gmdate('D, j M Y', strtotime($x . ' 00:00:00 UTC'))) . '</a></li>';
        $ssr .= '</ul></section>'; }
    $page = (string)file_get_contents(__DIR__ . '/reversal.html');
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: public, max-age=300');
    echo str_replace('<!--MOVERS_SSR-->', $ssr, $page);
    exit;
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
    <p><a class="btn" href="/movers/">Movers Reversal Scanner: today's movers and which are snapping back</a> <a class="btn ghost" href="/#free">Live Big Movers panel</a> <a class="btn ghost" href="/bias/">Trend bias for every symbol</a></p>
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
