<?php
/**
 * Programmatic SEO (owner, 28 Nov 2026, SEO phase 3): /features/<feature>/<market>/ -- one page per tool x market
 * (7 tools x 47 markets). Each page carries the market's live data (price move, trend bias, 1M/1Y, 52-week range,
 * tagged news), a market-class explainer, how to use the tool on that market, FAQ + Breadcrumb + WebPage schema,
 * and links to the same market in the other tools, the bias page and the GEX page. Display only: no scanner changes.
 */
declare(strict_types=1);
require_once __DIR__ . '/torus_seo.php';

// short slug => [symbol, display name, class, gex slug or '']
const PM = [
'nifty' => ['^NSEI', 'NIFTY 50', 'in_idx', 'nifty'], 'banknifty' => ['^NSEBANK', 'BANK NIFTY', 'in_idx', 'banknifty'], 'finnifty' => ['NIFTY_FIN_SERVICE.NS', 'FIN NIFTY', 'in_idx', 'finnifty'], 'nifty-it' => ['^CNXIT', 'NIFTY IT', 'in_idx', ''],
'reliance' => ['RELIANCE.NS', 'Reliance Industries', 'in_stk', ''], 'hdfcbank' => ['HDFCBANK.NS', 'HDFC Bank', 'in_stk', ''], 'tcs' => ['TCS.NS', 'TCS', 'in_stk', ''], 'infosys' => ['INFY.NS', 'Infosys', 'in_stk', ''],
'icicibank' => ['ICICIBANK.NS', 'ICICI Bank', 'in_stk', ''], 'sbi' => ['SBIN.NS', 'SBI', 'in_stk', ''], 'adani-enterprises' => ['ADANIENT.NS', 'Adani Enterprises', 'in_stk', ''], 'itc' => ['ITC.NS', 'ITC', 'in_stk', ''],
'larsen-toubro' => ['LT.NS', 'Larsen & Toubro', 'in_stk', ''], 'bajaj-finance' => ['BAJFINANCE.NS', 'Bajaj Finance', 'in_stk', ''],
'sp500' => ['^GSPC', 'S&P 500', 'us_idx', 'spy'], 'spy' => ['SPY', 'SPY', 'us_idx', 'spy'], 'qqq' => ['QQQ', 'QQQ', 'us_idx', 'qqq'], 'nasdaq-100' => ['^NDX', 'Nasdaq 100', 'us_idx', 'qqq'], 'dow-jones' => ['^DJI', 'Dow Jones', 'us_idx', ''], 'iwm' => ['IWM', 'Russell 2000 (IWM)', 'us_idx', 'iwm'],
'aapl' => ['AAPL', 'Apple (AAPL)', 'us_stk', 'aapl'], 'nvda' => ['NVDA', 'Nvidia (NVDA)', 'us_stk', 'nvda'], 'tsla' => ['TSLA', 'Tesla (TSLA)', 'us_stk', 'tsla'], 'msft' => ['MSFT', 'Microsoft (MSFT)', 'us_stk', 'msft'],
'amzn' => ['AMZN', 'Amazon (AMZN)', 'us_stk', 'amzn'], 'meta' => ['META', 'Meta (META)', 'us_stk', 'meta'], 'googl' => ['GOOGL', 'Alphabet (GOOGL)', 'us_stk', 'googl'], 'amd' => ['AMD', 'AMD', 'us_stk', 'amd'],
'nflx' => ['NFLX', 'Netflix (NFLX)', 'us_stk', 'nflx'], 'pltr' => ['PLTR', 'Palantir (PLTR)', 'us_stk', 'pltr'], 'mstr' => ['MSTR', 'Strategy (MSTR)', 'us_stk', 'mstr'], 'coin' => ['COIN', 'Coinbase (COIN)', 'us_stk', 'coin'],
'avgo' => ['AVGO', 'Broadcom (AVGO)', 'us_stk', 'avgo'], 'smci' => ['SMCI', 'Super Micro (SMCI)', 'us_stk', 'smci'],
'gold' => ['GC=F', 'Gold (XAUUSD)', 'cmd', 'gold'], 'silver' => ['SI=F', 'Silver (XAGUSD)', 'cmd', 'silver'], 'crude-oil' => ['CL=F', 'Crude Oil WTI', 'cmd', ''], 'natural-gas' => ['NG=F', 'Natural Gas', 'cmd', ''],
'eurusd' => ['EURUSD=X', 'EUR/USD', 'fx', ''], 'gbpusd' => ['GBPUSD=X', 'GBP/USD', 'fx', ''], 'usdjpy' => ['USDJPY=X', 'USD/JPY', 'fx', ''], 'audusd' => ['AUDUSD=X', 'AUD/USD', 'fx', ''],
'bitcoin' => ['BTC-USD', 'Bitcoin (BTC)', 'cry', 'bitcoin'], 'ethereum' => ['ETH-USD', 'Ethereum (ETH)', 'cry', 'ethereum'], 'solana' => ['SOL-USD', 'Solana (SOL)', 'cry', ''], 'xrp' => ['XRP-USD', 'XRP', 'cry', ''], 'dogecoin' => ['DOGE-USD', 'Dogecoin (DOGE)', 'cry', ''],
];
// class => [label, session, timeframes, character, extra data]
const PC = [
'in_idx' => ['Indian index', 'NSE cash session, 9:15 to 15:30 IST, with weekly option expiries', '5-minute, 15-minute and 1-hour', 'Opening gaps from global cues are common, the first 30 minutes are the most volatile, and expiry days often pin price near big option strikes.', 'NSE option-chain gamma (call wall, put wall, zero gamma) is available'],
'in_stk' => ['Indian large-cap stock', 'NSE cash session, 9:15 to 15:30 IST', '15-minute, 1-hour and daily', 'Moves follow the NIFTY and its sector index closely, with sharp reactions to results, block deals and FII flows.', 'Trend bias and news tagging are available'],
'us_idx' => ['US index / ETF', 'NYSE/Nasdaq regular session, 9:30 to 16:00 New York time, plus pre-market and after-hours', '5-minute, 15-minute and 1-hour', 'Dealer option hedging has a large effect: positive gamma days tend to range, negative gamma days tend to trend. CPI, FOMC and jobs data move it most.', 'Live dealer gamma exposure is available'],
'us_stk' => ['US large-cap stock', 'NYSE/Nasdaq regular session, 9:30 to 16:00 New York time', '15-minute, 1-hour and daily', 'Earnings, guidance and sector rotation drive big moves; heavy options activity makes gamma walls relevant around expiry.', 'Live dealer gamma exposure is available'],
'cmd' => ['commodity', 'nearly 24 hours on weekdays, most active in the London and New York sessions', '15-minute, 1-hour and 4-hour', 'Reacts to the US dollar, real yields, inventories and geopolitics, and often sweeps the previous day high or low before turning.', 'Trend bias, news and (for gold and silver) ETF-derived gamma levels are available'],
'fx' => ['forex pair', '24 hours on weekdays, busiest at the London open and the London/New York overlap', '15-minute, 1-hour and 4-hour', 'Driven by interest-rate expectations and central bank news; clean technical levels and frequent stop runs around the session opens.', 'Trend bias and news tagging are available'],
'cry' => ['cryptocurrency', '24/7, every day of the year', '15-minute, 1-hour and 4-hour', 'Trades around the clock with high volatility, liquidation cascades and funding swings; weekends can be thin and jumpy.', 'Order flow (CVD, open interest, liquidations) is available, and options gamma for BTC and ETH'],
];
// tool slug => [tool name, live url, title pattern, intro pattern, [how-to lines], [[q,a]...]]  ({m} market, {c} class label, {tf} timeframes)
const PT = [
'backtesting' => ['Pro Backtesting', '/backtesting/', '{m} Backtesting: Test Trading Strategies Free', 'Backtest any trading strategy on {m} with real historical candles: build rules from indicators, run them in seconds and see win rate, expectancy, drawdown and the equity curve. Free, no code.',
  ['Open Pro Backtesting and pick {m}.', 'Choose a timeframe; {tf} charts suit most {c} strategies.', 'Add entry rules, an ATR stop and a reward-to-risk target.', 'Read win rate, expectancy and drawdown, then confirm the best version on a different period.'],
  [['Can I backtest {m} for free?', 'Yes. Pro Backtesting is free during the launch period, then $99/month.'], ['What is a good {m} strategy to start with?', 'Simple trend-following rules, such as pullbacks to the 200 EMA in the direction of the daily trend, are a sensible first test. Check that the result has at least 100 trades.'], ['Does the backtest include {m} session hours?', 'Yes, it runs on the real {m} candles, so gaps and session breaks are included.']]],
'next-candle' => ['Next Candle AI', '/scanners/next-candle/', '{m} Next Candle Prediction (AI)', 'Next Candle AI predicts whether the next {m} candle will close green or red, from 1 minute to 1 hour, with a confidence score and a live graded win rate.',
  ['Open Next Candle AI and select {m}.', 'Choose 1m, 5m, 15m or 1h.', 'Read the predicted direction, confidence and higher-timeframe bias.', 'Use the live win-rate table to trade only the conditions that work on {m}.'],
  [['Can AI predict the next {m} candle?', 'Somewhat better than chance in the right conditions. The live graded record shows exactly how well.'], ['Which timeframe works best for {m}?', 'Check the live table; higher confidence and agreement with the hourly trend usually help.'], ['Is it free?', 'Yes, during the free access period.']]],
'ai-forecast' => ['AI Forecast 24h', '/scanners/forecast-24h/', '{m} AI Price Forecast (Next 24 Hours)', 'Four AI time-series models forecast the {m} price path for the next 24 hours, with a central path, a range, model agreement and a published accuracy record.',
  ['Open AI Forecast and select {m}.', 'Read the direction, central path and range.', 'Check how many of the four models agree.', 'Compare with the trend bias and key levels before acting.'],
  [['How accurate is the {m} AI forecast?', 'Accuracy is graded and published live. Treat the forecast as one input.'], ['How often is the {m} forecast updated?', 'Through the day as new candles close.'], ['Is the forecast free?', 'Yes, during the free access period.']]],
'trendline-scanner' => ['Trendline Hero', '/scanners/trendline-hero/', '{m} Trend Line Scanner: Automatic Trend Lines', 'Trendline Hero draws {m} trend lines automatically on every timeframe from 10 minutes to weekly and flags bounces, sweeps, breakouts and retests as they happen.',
  ['Open Trendline Hero and search {m}.', 'Choose Swing mode (daily/4H lines) or Intraday mode (1H to 15m).', 'Open the chart to see the line, its touches and the reaction.', 'Trade bounces with the daily and weekly trend, or retests after a confirmed break.'],
  [['Are {m} trend lines drawn automatically?', 'Yes, on every timeframe, anchored on wick extremes with touch counts and 20 years of history.'], ['What counts as a {m} breakout?', 'A body close or two closes beyond the line. Wicks that close back inside are treated as sweeps.'], ['Is it free?', 'Yes, during the free access period.']]],
'confluence-scanner' => ['Confluence Scanner', '/scanners/confluence/', '{m} Trend Line Confluence Scanner (4H/1H/30m/15m)', 'The Confluence Scanner finds {m} zones where trend lines from the 4-hour, 1-hour, 30-minute and 15-minute charts meet, in the direction of the daily and weekly trend, and tracks them to a 5-minute entry.',
  ['Open the Confluence Scanner and find {m}.', 'Check the stage: approaching, at zone, sweep, rejection or GO.', 'Open the symbol page for the zone, its lines and order flow.', 'Place the stop beyond the sweep and target 1.5 to 2.5 times the risk.'],
  [['What is a {m} confluence zone?', 'Two or more trend lines from different timeframes within about a third of the daily ATR.'], ['When is a {m} zone broken?', 'When price closes more than half a daily ATR beyond it.'], ['Is it free?', 'Yes, during the free access period.']]],
'godfather' => ['God-father', '/scanners/godfather/', '{m} A+ Trade Setups: God-father Scanner', 'God-father grades {m} from A+ down to Watch by combining trend bias, location, setup, trigger and order flow from every ChartPulse scanner, and logs every A+ and A setup with its result.',
  ['Open God-father; A+ and A setups show by default.', 'Find {m} and read the reasons for its grade.', 'Check entry, stop and targets on the card.', 'Use the graded track record to decide which grades to trade.'],
  [['When is {m} graded A+?', 'When daily and weekly bias agree, price is at a strong location, a sweep or rejection has formed and the 5-minute trigger has fired.'], ['Can I get {m} alerts?', 'Yes, A+ setups can be sent to Telegram.'], ['Is it free?', 'Yes, during the free access period.']]],
'smc-scanner' => ['SMC Scanner', '/institutional/scanner.php?id=3-liquidity-fvg', '{m} Order Block & FVG Scanner (SMC)', 'Smart money concepts scanners watch {m} for liquidity sweeps followed by an order block and fair value gap, plus Power of 3 with optimal trade entry.',
  ['Open the Liquidity + Order Block + FVG scanner.', 'Filter for {m}.', 'Open the chart to see the sweep, the order block and the gap.', 'Enter in the zone with the stop beyond the sweep.'],
  [['Does the SMC scanner cover {m}?', 'Yes, along with hundreds of other markets.'], ['Which timeframes suit SMC on {m}?', 'Intraday charts from 5 minutes to 1 hour.'], ['Is it free?', 'Yes, during the free access period.']]],
];

function pm_fill(string $s, string $m, array $c): string { return strtr($s, ['{m}' => $m, '{c}' => $c[0], '{tf}' => $c[2]]); }
function pm_pages(): array { $o = []; foreach (array_keys(PT) as $t) foreach (array_keys(PM) as $m) $o[] = "/features/$t/$m/"; return $o; }

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'pseo.php') return;   // included as a library (sitemap / IndexNow)

$t = strtolower((string)($_GET['t'] ?? '')); $k = strtolower((string)($_GET['m'] ?? ''));
header('Cache-Control: public, max-age=1800');
if (!isset(PT[$t]) || !isset(PM[$k])) { http_response_code(404); seo_head('Page not found | ChartPulse', 'This page does not exist.', '/features/', [], 'noindex'); echo '<main class="page wrap seo"><h1>Not found</h1><p><a href="/features/">All tools</a></p></main>'; seo_foot(); exit; }

[$tool, $url, $tt, $ti, $how, $faq] = PT[$t]; [$sym, $name, $cl, $gx] = PM[$k]; $C = PC[$cl];
$title = pm_fill($tt, $name, $C) . ' | ChartPulse'; if (mb_strlen($title) > 65) $title = pm_fill($tt, $name, $C);
$intro = pm_fill($ti, $name, $C);
$r = seo_rows()[$sym] ?? null;
$desc = mb_substr($intro, 0, 152);
$path = "/features/$t/$k/";
$faqF = array_map(fn($q) => [pm_fill($q[0], $name, $C), pm_fill($q[1], $name, $C)], $faq);
seo_head($title, $desc, $path, [
    seo_crumbs([['Home', '/'], ['Features', '/features/'], [$tool, "/features/$t/"], [$name, $path]]),
    ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'description' => $desc, 'url' => SEO_BASE . $path, 'dateModified' => gmdate('c'), 'about' => ['@type' => 'Thing', 'name' => $name], 'isPartOf' => ['@id' => SEO_BASE . '/#site']],
    ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faqF)],
]); ?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <a href="/features/">Features</a> <span>/</span> <a href="/features/<?= $t ?>/"><?= seo_e($tool) ?></a> <span>/</span> <span><?= seo_e($name) ?></span></nav>
  <h1><?= seo_e(pm_fill($tt, $name, $C)) ?></h1>
  <p><?= seo_e($intro) ?></p>
  <p><a class="btn" href="<?= seo_e($url) ?>">Open <?= seo_e($tool) ?> for <?= seo_e($name) ?> →</a> <span class="muted">Free for a limited time, then $99/month.</span></p>

  <h2><?= seo_e($name) ?> right now</h2>
<?php if ($r) { [$w, $css, $conv] = seo_bias($r); ?>
  <ul>
    <li>Today: <b class="<?= seo_cls($r['change_pct'] ?? 0) ?>"><?= seo_pct($r['change_pct'] ?? null) ?></b><?php if (isset($r['price'])) { ?> at <?= seo_e(number_format((float)$r['price'], (float)$r['price'] < 10 ? 4 : 2)) ?><?php } ?></li>
    <li>Trend bias: <b class="<?= $css ?>"><?= seo_e($w) ?></b> (<?= seo_e($conv) ?>)</li>
<?php if (($r['ret_1m'] ?? null) !== null) { ?>    <li>1 month: <?= seo_pct($r['ret_1m'], 1) ?> · 1 year: <?= seo_pct($r['ret_1y'] ?? null, 1) ?></li>
<?php } ?>
  </ul>
  <p><?= seo_e(seo_story($r)) ?></p>
<?php } else { ?>
  <p>Live data for <?= seo_e($name) ?> loads during market hours. Open the <a href="<?= seo_e(seo_url($sym)) ?>"><?= seo_e($name) ?> bias page</a> for the latest trend reading.</p>
<?php } ?>
  <p>Full detail: <a href="<?= seo_e(seo_url($sym)) ?>"><?= seo_e($name) ?> trend bias and news</a><?php if ($gx) { ?> · <a href="/gex/<?= $gx ?>/"><?= seo_e($name) ?> gamma exposure (GEX)</a><?php } ?></p>

  <h2>Trading <?= seo_e($name) ?>: what to know</h2>
  <p><?= seo_e($name) ?> is <?= preg_match("/^[aeiou]/i", $C[0]) ? "an" : "a" ?> <?= seo_e($C[0]) ?> that trades <?= seo_e($C[1]) ?>. <?= seo_e($C[3]) ?> For most traders, <?= seo_e($C[2]) ?> charts give the best balance of signal and noise. On ChartPulse: <?= seo_e($C[4]) ?>.</p>

  <h2>How to use <?= seo_e($tool) ?> on <?= seo_e($name) ?></h2>
  <ol>
<?php foreach ($how as $h) { ?>    <li><?= seo_e(pm_fill($h, $name, $C)) ?></li>
<?php } ?>
  </ol>
  <p>Read the full guide to <a href="/features/<?= $t ?>/"><?= seo_e($tool) ?></a>.</p>
<?php $nw = $r ? seo_news($sym, 5) : []; if ($nw) { ?>
  <h2>Latest <?= seo_e($name) ?> news</h2>
  <ul>
<?php foreach ($nw as $n) { ?>    <li><a href="<?= seo_e($n['u']) ?>" rel="nofollow noopener" target="_blank"><?= seo_e($n['t'] ?? '') ?></a><?php if (!empty($n['ts'])) { ?> <small class="muted"><?= seo_ago((int)$n['ts']) ?></small><?php } ?></li>
<?php } ?>
  </ul>
<?php } ?>
  <h2>Frequently asked questions</h2>
<?php foreach ($faqF as [$q, $a]) { ?>  <h3><?= seo_e($q) ?></h3><p><?= seo_e($a) ?></p>
<?php } ?>
  <h2>More tools for <?= seo_e($name) ?></h2>
  <p><?php $o = []; foreach (PT as $s => $x) if ($s !== $t) $o[] = '<a href="/features/' . $s . '/' . $k . '/">' . seo_e(pm_fill($x[2], $name, $C)) . '</a>'; echo implode(' · ', $o); ?></p>
  <h2><?= seo_e($tool) ?> on other markets</h2>
  <p><?php $o = []; foreach (PM as $s => $x) if ($s !== $k && $x[2] === $cl) $o[] = '<a href="/features/' . $t . '/' . $s . '/">' . seo_e($x[1]) . '</a>'; foreach (PM as $s => $x) if ($x[2] !== $cl && count($o) < 16) $o[] = '<a href="/features/' . $t . '/' . $s . '/">' . seo_e($x[1]) . '</a>'; echo implode(' · ', $o); ?></p>
  <p class="muted">For education only, not financial advice. Trading involves risk.</p>
</main>
<?php seo_foot();
