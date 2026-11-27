<?php
/**
 * GEX SEO pages (owner, 27 Nov 2026): /gex/ hub and /gex/<slug>/ for every GEX market -- US (SPY, QQQ, stocks, ETFs),
 * Gold, Silver, India (NIFTY, BANKNIFTY, FINNIFTY) and crypto (Bitcoin, Ethereum). Server-rendered so Google can read
 * today's call wall, put wall, zero gamma and regime. Reads the cached GEX result only (never fetches option chains).
 */
declare(strict_types=1);
require __DIR__ . '/torus_seo.php';
require_once __DIR__ . '/gex_engine.php';

const GXS = [   // slug => [GEX symbol, display name, market, search phrase]
    'spy' => ['SPY', 'SPY (S&P 500 ETF)', 'US', 'SPY'], 'qqq' => ['QQQ', 'QQQ (Nasdaq 100 ETF)', 'US', 'QQQ'], 'iwm' => ['IWM', 'IWM (Russell 2000 ETF)', 'US', 'IWM'],
    'dia' => ['DIA', 'DIA (Dow Jones ETF)', 'US', 'DIA'], 'gold' => ['GLD', 'Gold (XAUUSD via GLD)', 'Metals', 'Gold'], 'silver' => ['SLV', 'Silver (XAGUSD via SLV)', 'Metals', 'Silver'],
    'nifty' => ['NIFTY', 'NIFTY 50', 'India', 'NIFTY'], 'banknifty' => ['BANKNIFTY', 'BANK NIFTY', 'India', 'BANKNIFTY'], 'finnifty' => ['FINNIFTY', 'FIN NIFTY', 'India', 'FINNIFTY'],
    'bitcoin' => ['BTC', 'Bitcoin (BTC)', 'Crypto', 'Bitcoin'], 'ethereum' => ['ETH', 'Ethereum (ETH)', 'Crypto', 'Ethereum'],
    'aapl' => ['AAPL', 'Apple (AAPL)', 'US', 'AAPL'], 'nvda' => ['NVDA', 'NVIDIA (NVDA)', 'US', 'NVDA'], 'tsla' => ['TSLA', 'Tesla (TSLA)', 'US', 'TSLA'],
    'msft' => ['MSFT', 'Microsoft (MSFT)', 'US', 'MSFT'], 'amzn' => ['AMZN', 'Amazon (AMZN)', 'US', 'AMZN'], 'meta' => ['META', 'Meta (META)', 'US', 'META'],
    'googl' => ['GOOGL', 'Alphabet (GOOGL)', 'US', 'GOOGL'], 'amd' => ['AMD', 'AMD', 'US', 'AMD'], 'mstr' => ['MSTR', 'MicroStrategy (MSTR)', 'US', 'MSTR'],
    'coin' => ['COIN', 'Coinbase (COIN)', 'US', 'COIN'], 'pltr' => ['PLTR', 'Palantir (PLTR)', 'US', 'PLTR'], 'avgo' => ['AVGO', 'Broadcom (AVGO)', 'US', 'AVGO'],
    'nflx' => ['NFLX', 'Netflix (NFLX)', 'US', 'NFLX'], 'smci' => ['SMCI', 'Super Micro (SMCI)', 'US', 'SMCI'], 'mu' => ['MU', 'Micron (MU)', 'US', 'MU'],
    'hood' => ['HOOD', 'Robinhood (HOOD)', 'US', 'HOOD'], 'tlt' => ['TLT', 'TLT (20+ Year Treasury ETF)', 'US', 'TLT'], 'smh' => ['SMH', 'SMH (Semiconductor ETF)', 'US', 'SMH'],
    'xle' => ['XLE', 'XLE (Energy ETF)', 'US', 'XLE'], 'uso' => ['USO', 'USO (Oil ETF)', 'US', 'USO'],
];

function gxs_res(string $sym): ?array { $f = GEX_DIR . "/res_{$sym}_all.json"; if (!is_file($f)) return null; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : null; }
function gxs_n($v, int $d = 2): string { return $v === null || $v === '' ? '–' : number_format((float)$v, $d); }
function gxs_big($v): string { $a = abs((float)$v); $s = $v < 0 ? '-' : ''; return $s . ($a >= 1e9 ? number_format($a / 1e9, 2) . 'B' : ($a >= 1e6 ? number_format($a / 1e6, 1) . 'M' : number_format($a, 0))); }

$slug = strtolower((string)($_GET['slug'] ?? ''));
header('Cache-Control: public, max-age=300');

if ($slug === '' || !isset(GXS[$slug])) {
    if ($slug !== '') { http_response_code(404); }
    $title = 'Free GEX Levels Today: SPY, Gold, NIFTY, Bitcoin Gamma | ChartPulse';
    $desc = 'Free gamma exposure (GEX) levels for US stocks and ETFs, Gold, Silver, NIFTY, BANKNIFTY, Bitcoin and Ethereum: call wall, put wall and zero gamma, live.';
    $faq = [['What is GEX (gamma exposure)?', 'GEX estimates how much stock or futures option dealers must buy or sell when price moves 1%. Positive GEX means dealers sell rallies and buy dips, so moves tend to calm down. Negative GEX means dealers chase the move, so moves can run.'],
            ['What are the call wall and the put wall?', 'The call wall is the strike above price with the largest positive call gamma: it often acts like resistance. The put wall is the strike below price with the largest put gamma: it often acts like support.'],
            ['What is zero gamma (the gamma flip)?', 'The price where total dealer gamma changes from positive to negative. Above it markets are usually calmer; below it they are usually more volatile.'],
            ['Is the ChartPulse GEX scanner free?', 'Yes, every GEX market is free right now. The data comes from real option chains: US options via Alpaca, India via Upstox and Bitcoin / Ethereum via Deribit.']];
    seo_head($title, $desc, '/gex/', [seo_crumbs([['Home', '/'], ['GEX levels', '/gex/']]),
        ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]]); ?>
<main class="page wrap seo" id="main">
  <h1>GEX levels today: free gamma exposure for global markets</h1>
  <p class="muted">Live call wall, put wall, zero gamma and dealer regime from real option chains, for the US market, Gold and Silver, India's NIFTY and BANKNIFTY, and Bitcoin and Ethereum. Pick a market, or open the <a href="/scanners/gex/">live GEX terminal</a> with charts and heatmaps.</p>
<?php $by = []; foreach (GXS as $s => $x) $by[$x[2]][$s] = $x;
  foreach (['US' => 'United States: indices, ETFs and stocks', 'Metals' => 'Gold and Silver', 'India' => 'India: NIFTY options', 'Crypto' => 'Crypto options (Deribit)'] as $k => $h) { ?>
  <h2><?= seo_e($h) ?></h2>
  <table class="tbl"><thead><tr><th>Market</th><th>Price</th><th>Regime</th><th>Call wall</th><th>Put wall</th><th>Zero gamma</th></tr></thead><tbody>
<?php foreach ($by[$k] ?? [] as $s => $x) { $r = gxs_res($x[0]); $sp = $r['spot'] ?? null; ?>
    <tr><td><a href="/gex/<?= $s ?>/"><?= seo_e($x[1]) ?> GEX</a></td><td><?= gxs_n($sp) ?></td><td><?= $r ? (($r['regime'] ?? '') === 'positive' ? '+ gamma' : '− gamma') : '–' ?></td>
        <td><?= gxs_n($r['call_wall'] ?? null) ?></td><td><?= gxs_n($r['put_wall'] ?? null) ?></td><td><?= gxs_n($r['zero_gamma'] ?? null) ?></td></tr>
<?php } ?>
  </tbody></table>
<?php } ?>
  <h2>Questions</h2>
<?php foreach ($faq as $q) { ?>  <h3><?= seo_e($q[0]) ?></h3><p><?= seo_e($q[1]) ?></p>
<?php } ?>
</main>
<?php seo_foot(); exit; }

[$sym, $name, $mkt, $q] = GXS[$slug];
$r = gxs_res($sym); $sp = $r['spot'] ?? null; $pos = ($r['regime'] ?? '') === 'positive';
$cw = $r['call_wall'] ?? null; $pw = $r['put_wall'] ?? null; $fl = $r['zero_gamma'] ?? null; $em = $r['expected_move'] ?? null; $mp = $r['max_pain'] ?? null;
$pc = fn($k) => ($k && $sp) ? number_format(($k / $sp - 1) * 100, 2) . '%' : '–';
$day = gmdate('j M Y');
$title = "$q GEX Today: Call Wall, Put Wall & Zero Gamma | ChartPulse";
$desc = "$name gamma exposure today ($day): " . ($r ? ($pos ? 'positive' : 'negative') . " gamma, call wall " . gxs_n($cw) . ", put wall " . gxs_n($pw) . ", zero gamma " . gxs_n($fl) . '. Free, live.' : 'call wall, put wall, zero gamma and dealer regime, free and live.');
$faq = [["What is the $q call wall today?", $cw ? "The $q call wall is at " . gxs_n($cw) . " (" . $pc($cw) . " from price). It is the strike above price with the most call gamma and often acts as resistance." : "The call wall updates during market hours."],
        ["What is the $q put wall today?", $pw ? "The $q put wall is at " . gxs_n($pw) . " (" . $pc($pw) . " from price). It is the strike below price with the most put gamma and often acts as support." : "The put wall updates during market hours."],
        ["Is $q in positive or negative gamma?", $r ? ($pos ? "$q is in positive gamma: dealers tend to sell rallies and buy dips, so moves often fade near the walls." : "$q is in negative gamma: dealers tend to chase moves, so price can run and volatility can expand.") : "The regime updates during market hours."],
        ["Where is $q zero gamma (the gamma flip)?", $fl ? "Zero gamma for $q is near " . gxs_n($fl) . ". Above it trading is usually calmer, below it more volatile." : "Zero gamma updates during market hours."]];
$lv = [seo_crumbs([['Home', '/'], ['GEX levels', '/gex/'], ["$q GEX", "/gex/$slug/"]]),
       ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faq)]];
seo_head($title, $desc, "/gex/$slug/", $lv); ?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <a href="/gex/">GEX levels</a> <span>/</span> <span><?= seo_e($name) ?></span></nav>
  <h1><?= seo_e($q) ?> GEX today: gamma exposure levels</h1>
  <p class="muted"><?= seo_e($name) ?> dealer gamma from the real option chain<?= $r ? ' · updated ' . seo_ago((int)($r['ts'] ?? $r['at'] ?? time())) : '' ?>. <a href="/scanners/gex/#<?= seo_e($sym) ?>">Open the live <?= seo_e($q) ?> GEX chart →</a></p>
<?php if ($r) { ?>
  <table class="tbl"><tbody>
    <tr><th>Price</th><td><?= gxs_n($sp) ?></td></tr>
    <tr><th>Regime</th><td><?= $pos ? '<b class="up">Positive gamma</b>: dealers dampen moves' : '<b class="down">Negative gamma</b>: dealers chase moves' ?></td></tr>
    <tr><th>Net GEX (per 1% move)</th><td><?= gxs_big($r['net_gex'] ?? 0) ?></td></tr>
    <tr><th>Call wall</th><td><?= gxs_n($cw) ?> <small>(<?= $pc($cw) ?>)</small></td></tr>
    <tr><th>Put wall</th><td><?= gxs_n($pw) ?> <small>(<?= $pc($pw) ?>)</small></td></tr>
    <tr><th>Zero gamma (flip)</th><td><?= gxs_n($fl) ?> <small>(<?= $pc($fl) ?>)</small></td></tr>
    <tr><th>Max pain</th><td><?= gxs_n($mp) ?></td></tr>
    <tr><th>Expected move</th><td><?= $em ? '±' . gxs_n(is_array($em) ? ($em['day'] ?? reset($em)) : $em) : '–' ?></td></tr>
  </tbody></table>
  <h2>What this means for <?= seo_e($q) ?> today</h2>
  <p><?= seo_e($q) ?> is <?= $pos ? 'above its gamma flip, in <b>positive gamma</b>. Option dealers hedge against the move, so rallies into the call wall at ' . gxs_n($cw) . ' and drops into the put wall at ' . gxs_n($pw) . ' often slow down or reverse. Range and reversal setups usually work better.' : 'in <b>negative gamma</b>. Dealers hedge with the move, so breaks can travel further and faster than usual. A move back above zero gamma near ' . gxs_n($fl) . ' would usually calm the tape; until then trend and breakout setups usually work better.' ?></p>
<?php } else { ?>
  <p>Levels for <?= seo_e($q) ?> appear here during market hours. <a href="/scanners/gex/#<?= seo_e($sym) ?>">Open the live GEX terminal</a>.</p>
<?php } ?>
  <h2>Trade <?= seo_e($q) ?> with more than gamma</h2>
  <ul>
    <li><a href="/scanners/confluence/">Confluence Scanner</a>: trend lines of four charts meeting in one zone, with a 5-minute entry.</li>
    <li><a href="/scanners/godfather/">God-father</a>: the strongest A+ setups from every scanner, with a live track record.</li>
    <li><a href="/scanners/trend-lines/">Trend Line Scanner</a> and <a href="/scanners/day-drive/">Day Drive</a> for breakouts and daily reversals.</li>
  </ul>
  <h2>Questions about <?= seo_e($q) ?> GEX</h2>
<?php foreach ($faq as $x) { ?>  <h3><?= seo_e($x[0]) ?></h3><p><?= seo_e($x[1]) ?></p>
<?php } ?>
  <h2>Other GEX markets</h2>
  <p><?php $o = []; foreach (GXS as $s => $x) if ($s !== $slug) $o[] = '<a href="/gex/' . $s . '/">' . seo_e($x[3]) . ' GEX</a>'; echo implode(' · ', $o); ?></p>
  <p class="muted">For education and research, not financial advice. Data: US options via Alpaca, India via Upstox, crypto via Deribit; Gold and Silver from the GLD and SLV option chains.</p>
</main>
<?php seo_foot();
