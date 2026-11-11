<?php
// Free Bias pages (owner, 29 Sep 2026): /bias/ hub and /bias/<slug> per symbol. Server-rendered for search engines.
declare(strict_types=1);
require __DIR__ . '/torus_seo.php';

$map = seo_slug_map();
if (isset($_GET['s'])) {   // /bias/?s=RAW_SYMBOL (links from the app) -> permanent slug URL
    $s = (string)$_GET['s'];
    header('Location: ' . (isset(t_symbols()[$s]) ? seo_url($s) : '/bias/'), true, 301); exit;
}
$slug = strtolower((string)($_GET['slug'] ?? ''));
$rows = seo_rows();
header('Cache-Control: public, max-age=300');

if ($slug === '') {   // ---------------- hub ----------------
    $by = []; foreach (t_symbols() as $s => $m) $by[$m][] = $s;
    ksort($by);
    $n = count(t_symbols());
    seo_head("Free trend bias for $n stocks, indices, forex, crypto & commodities | ChartPulse",
        "Live trend bias from the ChartPulse engine for $n instruments across the US, UK, Europe, Gulf, India, forex, crypto and commodities. Free, no sign-up.",
        '/bias/', [seo_crumbs([['Home', '/'], ['Bias', '/bias/']])]); ?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <span>Bias</span></nav>
  <header class="seo-hero">
    <span class="free-tag">FREE · FOR EVERYONE</span>
    <h1>Trend bias for <?= $n ?> markets</h1>
    <p>Pick any instrument to see its current trend bias from the ChartPulse engine, key stats and the latest news. Updated through the trading day. Information only, not advice.</p>
    <p><a class="btn primary" href="/desk">Open AI Scan</a> <a class="btn ghost" href="/#bias">Check a chart screenshot</a> <a class="btn ghost" href="/movers/">Today's biggest movers</a></p>
  </header>
<?php foreach ($by as $m => $list):
    usort($list, fn($a, $b) => t_rank($a) <=> t_rank($b)); ?>
  <section class="seo-sec">
    <h2><?= seo_e($m) ?> <small><?= count($list) ?></small></h2>
    <ul class="seo-grid">
<?php foreach ($list as $s): $r = $rows[$s] ?? null; [$w, $k] = $r ? seo_bias($r) : ['–', 'neu']; ?>
      <li><a href="<?= seo_e(seo_url($s)) ?>"><b><?= seo_e(t_name($s)) ?></b><small><?= seo_e($s) ?></small><span class="chip <?= $k ?>"><?= $w ?></span></a></li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
</main>
<?php seo_foot(); exit;
}

$sym = $map[$slug] ?? null;
if (!$sym) {   // old or unknown slug: try the ticker part, else 404
    foreach ($map as $k => $s) if (str_ends_with($slug, '-' . seo_slugify($s)) || $slug === seo_slugify($s)) { header('Location: ' . seo_url($s), true, 301); exit; }
    http_response_code(404);
    seo_head('Symbol not found | ChartPulse', 'This symbol is not covered by ChartPulse.', '/bias/', [], 'noindex,follow'); ?>
<main class="page wrap seo" id="main"><h1>We do not cover that symbol</h1><p><a href="/bias/">See all symbols</a> or <a href="/#bias">search with Bias Check</a>.</p></main>
<?php seo_foot(); exit;
}

$r = $rows[$sym] ?? null; $name = t_name($sym); $mkt = t_symbols()[$sym];
[$w, $k, $conv] = $r ? seo_bias($r) : ['–', 'neu', 'Waiting for the first scan'];
$ch = $r['change_pct'] ?? null;
// 11 Nov 2026: worded the way people search (Search Console queries: "crude oil mini trend today",
// "mcx gold mini price", "gold mini live chart").
$title = "$name trend today: $w, live price ($sym) | ChartPulse";
$desc = $r ? sprintf('%s (%s) trend today is %s on the ChartPulse engine (%s). Today %s, 1 month %s, 1 year %s. Live stats and news, free, no sign-up.', $name, $sym, strtolower($w), strtolower($conv), seo_pct($ch), seo_pct($r['ret_1m'] ?? null, 1), seo_pct($r['ret_1y'] ?? null, 1))
    : "$name ($sym) trend bias, stats and news from ChartPulse.";
$path = seo_url($sym);
$news = seo_news($sym, 8);
$rel = array_values(array_filter(array_keys(t_symbols()), fn($s) => t_symbols()[$s] === $mkt && $s !== $sym));
usort($rel, fn($a, $b) => t_rank($a) <=> t_rank($b)); $rel = array_slice($rel, 0, 12);
$upd = $r && !empty($r['ts']) ? gmdate('d M Y, H:i', (int)$r['ts']) . ' UTC' : '';
$ld = [seo_crumbs([['Home', '/'], ['Bias', '/bias/'], [$name, $path]]),
    ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'description' => $desc, 'url' => SEO_BASE . $path,
     'dateModified' => $r && !empty($r['ts']) ? gmdate('c', (int)$r['ts']) : null, 'isPartOf' => ['@type' => 'WebSite', 'name' => 'ChartPulse', 'url' => SEO_BASE . '/']]];
seo_head($title, $desc, $path, $ld);
$st = fn($l, $v, $c = '') => '<div><span>' . $l . '</span><b class="mono ' . $c . '">' . $v . '</b></div>';
?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <a href="/bias/">Bias</a> <span>/</span> <span><?= seo_e($name) ?></span></nav>
  <article class="bc-card seo-card">
    <header class="bc-h">
      <div><h1><?= seo_e($name) ?> (<?= seo_e($sym) ?>) trend bias today</h1><small><?= seo_e($mkt) ?><?= $r && !empty($r['exchange']) ? ' · ' . seo_e($r['exchange']) : '' ?><?= $upd ? ' · updated ' . $upd : '' ?></small></div>
<?php if ($r): ?>
      <div class="bc-px"><b class="mono"><?= seo_e(t_fp((float)$r['price'])) ?></b><span class="mono <?= seo_cls($ch) ?>"><?= seo_pct($ch) ?></span><span class="stbadge <?= seo_e($r['status'] ?? '') ?>"><?= !empty($r['live']) ? 'LIVE' : 'CLOSED' ?></span></div>
<?php endif; ?>
    </header>
    <p class="bc-next" style="margin:10px 0 14px"><a href="/desk/?s=<?= rawurlencode($sym) ?>"><?= seo_e($name) ?> chart with trend lines and AI Scan</a> · <a href="/scanners/day-drive/?s=<?= rawurlencode($sym) ?>">Day Drive checks</a> · <a href="/scanner.html">Live signal scanner</a></p>
<?php if ($r): ?>
    <div class="bc-grid">
      <div class="bc-bias <?= $k ?>">
        <span class="l">ChartPulse bias</span>
        <b><?= $w ?></b>
        <span class="c"><?= seo_e($conv) ?><?= $k !== 'neu' ? ' · Pulse Sync ' . (int)$r['alignment'] . '/4' : '' ?></span>
        <div class="bc-cells"><?php for ($i = 1; $i <= 4; $i++): ?><i class="<?= $k !== 'neu' && $i <= (int)$r['alignment'] ? 'on' : '' ?>"></i><?php endfor; ?></div>
        <p>The current trend state from our engine. It is not a forecast and not a trade recommendation.</p>
      </div>
      <div class="bc-stats">
        <?= $st('Today', seo_pct($ch), seo_cls($ch)) . $st('1 week', seo_pct($r['ret_1w'] ?? null, 1), seo_cls($r['ret_1w'] ?? 0)) . $st('1 month', seo_pct($r['ret_1m'] ?? null, 1), seo_cls($r['ret_1m'] ?? 0)) ?>
        <?= $st('3 months', seo_pct($r['ret_3m'] ?? null, 1), seo_cls($r['ret_3m'] ?? 0)) . $st('1 year', seo_pct($r['ret_1y'] ?? null, 1), seo_cls($r['ret_1y'] ?? 0)) . $st('Volatility (20d, yearly)', ($r['vol_20d'] ?? null) === null ? '–' : number_format((float)$r['vol_20d'], 1) . '%') ?>
        <?= $st('From 52-week high', seo_pct($r['from_hi_pct'] ?? null, 1), 'down') . $st('From 52-week low', seo_pct($r['from_lo_pct'] ?? null, 1), 'up') . $st('Average daily range', ($r['atr_pct'] ?? null) === null ? '–' : number_format((float)$r['atr_pct'], 2) . '%') ?>
        <?= $st("Today's move vs usual", ($r['move_atr'] ?? null) === null ? '–' : number_format((float)$r['move_atr'], 1) . '×') . $st("Today's range", ($r['range_pct'] ?? null) === null ? '–' : number_format((float)$r['range_pct'], 2) . '%') . $st('TradingView', '<span class="bm-tv">' . seo_e(t_tv_code($sym)) . '</span>') ?>
      </div>
    </div>
    <p class="seo-story"><?= seo_e(seo_story($r)) ?></p>
<?php else: ?>
    <p>This instrument has not been scanned yet. Try again in a few minutes.</p>
<?php endif; ?>
    <div class="bc-news"><h2 class="h4">Latest <?= seo_e($name) ?> news</h2>
<?php if ($news): ?>
      <ul><?php foreach ($news as $x): ?><li><a href="<?= seo_e($x['u']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= seo_e($x['t']) ?></a><small><?= seo_e($x['src']) ?> · <?= seo_ago((int)$x['ts']) ?></small></li><?php endforeach; ?></ul>
<?php else: ?>
      <p class="muted">No recent headlines matched to <?= seo_e($name) ?>. See the <a href="/news.html">News page</a> for the full market feed.</p>
<?php endif; ?>
    </div>
    <footer class="bc-f"><a href="/desk?s=<?= rawurlencode($sym) ?>">Open in AI Scan</a><a href="/scanner.html#analysis=<?= rawurlencode($sym) ?>">Tier-1 view →</a><a href="https://www.tradingview.com/chart/?symbol=<?= rawurlencode(t_tv_code($sym)) ?>" target="_blank" rel="noopener nofollow">Chart on TradingView ↗</a></footer>
    <p class="bc-disc">Information only, generated by software from third-party data. Not investment advice. Trading involves substantial risk of loss.</p>
  </article>

  <section class="seo-sec">
    <h2>More in <?= seo_e($mkt) ?></h2>
    <ul class="seo-grid">
<?php foreach ($rel as $s): $x = $rows[$s] ?? null; [$ww, $kk] = $x ? seo_bias($x) : ['–', 'neu']; ?>
      <li><a href="<?= seo_e(seo_url($s)) ?>"><b><?= seo_e(t_name($s)) ?></b><small><?= seo_e($s) ?><?= $x ? ' · ' . seo_pct($x['change_pct'] ?? null) : '' ?></small><span class="chip <?= $kk ?>"><?= $ww ?></span></a></li>
<?php endforeach; ?>
    </ul>
    <p><a href="/bias/">All symbols →</a> · <a href="/movers/">Today's biggest movers →</a></p>
  </section>

  <section class="seo-sec seo-faq">
    <h2>About this page</h2>
    <details><summary>What does the ChartPulse bias mean?</summary><p>It is the trend state our engine reads from several trend layers, from fast to slow. Bullish or Bearish means the layers mostly agree; Mixed means they are split. Pulse Sync shows how many of the four layers agree.</p></details>
    <details><summary>Is this a buy or sell signal?</summary><p>No. The bias describes the current trend only. It is not a forecast, not a recommendation and not investment advice. Markets can reverse at any time.</p></details>
    <details><summary>How often is it updated?</summary><p>Live markets are rescanned every few minutes during their trading session. Closed markets show the last session.</p></details>
  </section>
</main>
<?php seo_foot();
