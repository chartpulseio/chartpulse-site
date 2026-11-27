<?php
/**
 * Learn pages (owner, 27 Nov 2026, SEO: "each feature"): /learn/ and /learn/<topic>/ -- plain-English guides that
 * explain every ChartPulse tool and link to it. Server-rendered with FAQ schema.
 */
declare(strict_types=1);
require __DIR__ . '/torus_seo.php';

// slug => [title (<60), description (<155), H1, intro, [[h2, paragraph], ...], [[q, a], ...], [tool name, tool url]]
const LRN = [
'gamma-exposure-gex' => ['What Is Gamma Exposure (GEX)? Simple Guide | ChartPulse', 'Gamma exposure (GEX) explained simply: how option dealers hedge, positive vs negative gamma, and how traders use GEX levels on SPY, NIFTY, Gold and Bitcoin.',
  'What is gamma exposure (GEX)?', 'Gamma exposure estimates how much option dealers must buy or sell when price moves. It tells you whether the market is likely to stay calm or move fast.',
  [['How dealers hedge', 'When traders buy options, market makers sell them and hedge with the underlying. Gamma is how fast that hedge must change as price moves. Add it up across every strike and expiry and you get GEX: the stock or futures dealers need to trade for a 1% move.'],
   ['Positive gamma vs negative gamma', 'With positive GEX dealers sell into rallies and buy dips, which dampens moves: ranges and reversals work better. With negative GEX dealers trade with the move, which can make it run: breakouts and trends work better.'],
   ['The levels that matter', 'The call wall (largest call gamma above price) often caps rallies, the put wall (largest put gamma below price) often supports dips, and zero gamma is the price where the regime flips.'],
   ['How to use it', 'Use GEX as context, not as a signal on its own: favour reversals at the walls in positive gamma and follow breaks in negative gamma. Combine it with trend lines and order flow.']],
  [['Is GEX a buy or sell signal?', 'No. GEX describes how dealers are likely to hedge. It is best used as context for other setups.'], ['Which markets have GEX on ChartPulse?', 'US indices, ETFs and large stocks, Gold and Silver, NIFTY, BANKNIFTY, FINNIFTY, Bitcoin and Ethereum, all free.'], ['How often is GEX updated?', 'Every minute for the main markets during trading hours.']],
  ['Live GEX levels', '/gex/']],
'call-wall-put-wall' => ['Call Wall and Put Wall Explained | ChartPulse', 'What the call wall and put wall are, why they act like resistance and support, and how to trade them on SPY, NIFTY, Gold and Bitcoin with free live levels.',
  'Call wall and put wall: options support and resistance', 'The call wall and put wall are the strikes where option dealers hold the most gamma. Price often slows down or turns there.',
  [['Call wall', 'The strike above price with the most call gamma. As price rises toward it, dealers who are long those calls sell to stay hedged, which tends to cap the rally.'],
   ['Put wall', 'The strike below price with the most put gamma. As price falls toward it, hedging flows tend to slow the drop, so it often behaves like support.'],
   ['When walls fail', 'Walls are strongest in positive gamma. In negative gamma, or close to expiry when gamma shifts quickly, price can push straight through them.'],
   ['Trading idea', 'Look for a reversal setup, such as a trend line touch with a rejection candle, right at a wall in a positive-gamma market.']],
  [['Do walls change during the day?', 'Yes. Open interest and volume change, so walls can move, especially on expiry days.'], ['Are walls the same for every expiry?', 'ChartPulse shows the walls for all expiries combined and lets you view near expiries in the GEX terminal.']],
  ['Today\'s walls', '/gex/']],
'trend-line-confluence' => ['Trend Line Confluence Trading Strategy | ChartPulse', 'How trend line confluence works: lines from the 4H, 1H, 30m and 15m charts in one zone, with the daily and weekly trend, a trap check and a 5-minute entry.',
  'Trend line confluence: when several charts agree', 'A single trend line can fail. When trend lines from several timeframes meet in one zone, more traders are watching the same price, and the reaction is often stronger.',
  [['The idea', 'Draw the main trend lines on the 4H, 1H, 30m and 15m charts. If two or more of them sit within a small distance of each other (about a third of the daily ATR), that area is a confluence zone.'],
   ['Trade with the big trend', 'Only take reversals in the direction of the daily and weekly trend: sell at a resistance zone in a downtrend, buy at a support zone in an uptrend.'],
   ['Watch for the trap', 'Price often pokes through the zone to run stops before turning. A wick through the zone of up to half the daily ATR that closes back inside is a sweep, which is often the best entry. A close well beyond it is a breakout: skip the trade.'],
   ['Entry, stop and targets', 'Wait for a rejection candle on 15 minutes and a structure change on 5 minutes. Put the stop beyond the sweep and aim for 1.5 to 2.5 times the risk.']],
  [['How many charts make a confluence?', 'At least two; three or four is stronger, and the 4H line counts the most.'], ['Is there a free scanner for this?', 'Yes, the ChartPulse Confluence Scanner finds these zones across hundreds of markets.']],
  ['Confluence Scanner', '/scanners/confluence/']],
'break-and-retest' => ['Break and Retest Trading Strategy (Trend Lines) | ChartPulse', 'The break and retest strategy explained: when a broken trend line flips role, how to spot the retest, and how to avoid fake breakouts. Free scanner included.',
  'Break and retest: when resistance becomes support', 'After price breaks a trend line, it often comes back to test it from the other side. A line that held as resistance can now act as support.',
  [['Why it works', 'Traders who missed the break wait for a pullback, and traders caught on the wrong side exit near their entry. Both create orders around the old line.'],
   ['Spotting the retest', 'Within about 3 to 15 candles of the break, price returns to the line and holds on the new side. A close back through the line cancels the setup.'],
   ['Entry', 'Enter when the retest holds with a rejection candle; place the stop on the other side of the line.']],
  [['What is a failed breakout?', 'When price breaks a line and quickly closes back through it. These are traps and are removed from ChartPulse signals.'], ['Which scanner shows retests?', 'The Trend Line Scanner labels lines that are being retested right now.']],
  ['Trend Line Scanner', '/scanners/trend-lines/']],
'liquidity-sweep-fake-breakout' => ['Liquidity Sweep and Fake Breakout Explained | ChartPulse', 'What a liquidity sweep (stop hunt) is, why fake breakouts happen at obvious levels, and how to trade the reversal with an ATR rule. Simple guide.',
  'Liquidity sweeps and fake breakouts', 'Many traders put stop-loss orders just beyond obvious highs, lows and trend lines. Price often runs those stops first and then reverses.',
  [['Why stops cluster', 'Stops sit just past round numbers and obvious levels. When price touches them, the stops become market orders that push price a little further, after which the move can run out of fuel.'],
   ['An ATR rule for traps', 'Measure how far price went beyond the level in terms of the daily ATR. Up to half an ATR and back inside is usually a sweep; a close more than half an ATR beyond is usually a real breakout.'],
   ['Trading the sweep', 'After the sweep, wait for a rejection candle and a small structure change, then trade back into the range with the stop beyond the sweep high or low.']],
  [['Is every wick a sweep?', 'No. Only wicks beyond a meaningful level that close back inside count.'], ['Where does ChartPulse use this?', 'The Confluence Scanner, Day Drive and God-father rank sweeps as the strongest reversal setups.']],
  ['God-father setups', '/scanners/godfather/']],
'order-flow-volume-delta' => ['Order Flow and Volume Delta for Beginners | ChartPulse', 'Order flow explained simply: volume delta, cumulative delta, big-volume candles and what they say about buyers and sellers. Free crypto order flow tools.',
  'Order flow and volume delta, simply explained', 'Order flow looks at who is more aggressive, buyers or sellers. Volume delta is the difference between buying and selling volume.',
  [['Volume delta', 'Positive delta means more volume traded at the ask (buyers lifting offers); negative means sellers hitting bids. Cumulative delta adds it up over time.'],
   ['Delta at a level', 'At resistance, strong buying that fails to push price higher is often absorption, a sign sellers are in control. The reverse at support.'],
   ['Big-volume candles', 'Candles with unusually high volume at a key level show where large players are active.']],
  [['Is candle-based delta exact?', 'No. True delta needs tick data. Delta estimated from where a candle closed in its range is a rougher guide.'], ['Where can I see order flow free?', 'ChartPulse Crypto Order Flow shows CVD, open interest, the order book and liquidations.']],
  ['Crypto Order Flow', '/scanners/order-flow/']],
'volume-profile-poc' => ['Volume Profile, POC and Value Area Explained | ChartPulse', 'Volume profile explained: point of control (POC), value area high and low, and how traders use them as support and resistance in any market.',
  'Volume profile: POC and value area', 'A volume profile shows how much volume traded at each price instead of each time. It reveals the prices the market accepted and rejected.',
  [['Point of control', 'The price with the most volume in the session. Price often returns to it.'], ['Value area', 'The range around the POC that holds about 70% of the volume. Its high and low often act as support and resistance.'],
   ['Using it', 'A move back into the value area often travels to the POC; acceptance outside the value area can start a trend.']],
  [['Which timeframe?', 'Daily session profiles are the most common for intraday trading.'], ['Does ChartPulse show volume profile?', 'Yes, on the GEX all-in-one chart and on the Confluence Scanner symbol pages.']],
  ['GEX all-in-one chart', '/scanners/gex/']],
'backtesting-trading-strategy' => ['How to Backtest a Trading Strategy (Free) | ChartPulse', 'How to backtest a trading strategy step by step: rules, data, win rate, drawdown, expectancy and avoiding curve fitting. Free backtesting tool included.',
  'How to backtest a trading strategy', 'Backtesting runs your trading rules on past prices to see how they would have performed before you risk money.',
  [['Write exact rules', 'Entry, stop, target and exit must be precise enough for a computer to follow.'], ['Measure the right things', 'Look at win rate, average win and loss in R, expectancy, maximum drawdown and the number of trades.'],
   ['Avoid curve fitting', 'Test on one period, then confirm on another you did not tune on (walk-forward). Fewer than about 100 trades is not enough to trust.']],
  [['Is backtesting free on ChartPulse?', 'Yes, build a strategy from indicators and rules and test it on real history for free.'], ['Can AI backtest for me?', 'Yes, AI Backtesting turns a strategy written in plain words into a test.']],
  ['Backtesting', '/backtesting/']],
'ai-price-forecast' => ['AI Price Forecast: How AI Models Predict Markets | ChartPulse', 'How AI forecasting models such as TimesFM and Chronos predict price paths, how accurate they are, and how to use AI forecasts sensibly. Free AI tools.',
  'AI price forecasts: what they can and cannot do', 'Modern time-series AI models learn patterns from millions of series and produce a likely price path and range.',
  [['The models', 'ChartPulse runs four forecast models and combines them. Each gives a direction and a range for the next candle or the next 24 hours.'], ['Accuracy', 'Short-term forecasts are only slightly better than a coin flip on their own. Agreement between models and a clear trend improve them; every guess is graded live.'],
   ['Using forecasts', 'Treat a forecast as one input: combine it with trend, levels and risk management.']],
  [['Is the AI forecast free?', 'Yes, Next Candle AI, the 24-hour forecast and the AI Scanner are free right now.'], ['Can AI predict the next candle?', 'Sometimes, slightly better than chance; see the live graded record on the Next Candle page.']],
  ['Next Candle AI', '/scanners/next-candle/']],
'smart-money-concepts' => ['Smart Money Concepts (SMC) Explained Simply | ChartPulse', 'Smart money concepts explained: order blocks, fair value gaps, liquidity, power of 3 and OTE. Free SMC scanners for stocks, forex and crypto.',
  'Smart money concepts (SMC) in plain English', 'SMC tries to read where large players accumulate and distribute, using liquidity, order blocks and imbalances.',
  [['Liquidity', 'Clusters of stops above highs and below lows that price often runs before reversing.'], ['Order blocks and fair value gaps', 'The last candle before a strong move (order block) and the gap it leaves (FVG) often attract price again.'],
   ['Power of 3 and OTE', 'Accumulation, manipulation and distribution; the optimal trade entry is a 62-79% retracement after the manipulation move.']],
  [['Are there free SMC scanners?', 'Yes: Liquidity Sweep + Order Block + FVG and SMC Power of 3 + OTE.']],
  ['All scanners', '/scanners/']],
'200-ema-strategy' => ['200 EMA Trading Strategy Explained | ChartPulse', 'The 200 EMA strategy: why the 200-period moving average matters, how to trade pullbacks to it with trend lines, and a free 200 EMA scanner.',
  'The 200 EMA strategy', 'The 200-period exponential moving average is one of the most watched trend filters. Many traders buy above it and sell below it.',
  [['Trend filter', 'Price above a rising 200 EMA favours buying; below a falling one favours selling.'], ['Pullback entries', 'A pullback to the 200 EMA that also sits on a trend line or a day high or low is a stronger level.'],
   ['Combine', 'ChartPulse adds a "1H 200 EMA on the line" filter in Trendline Hero and counts it in God-father.']],
  [['Which timeframe?', 'The 1-hour and daily 200 EMA are the most used.'], ['Is there a 200 EMA scanner?', 'Yes, free.']],
  ['200 EMA scanner', '/scanners/ema-200/']],
'trend-line-trading' => ['Trend Line Trading: How to Draw and Trade Lines | ChartPulse', 'How to draw trend lines correctly (wicks, touches, timeframes), trade bounces, breakouts and retests, and avoid traps. Free automatic trend line scanners.',
  'Trend line trading: draw them right, trade them well', 'A trend line connects swing highs (resistance) or swing lows (support). Good lines have several touches and no candle bodies cutting through them.',
  [['Drawing rules', 'Anchor on clear swing points, prefer wick extremes, require at least three touches, and check the higher timeframes first.'], ['Three ways to trade', 'Bounce at the line with the trend, breakout through it with a close well beyond, or the retest after a break.'],
   ['Automatic lines', 'ChartPulse draws lines on every timeframe from 10 minutes to weekly, marks sweeps and breaks, and ranks the setups.']],
  [['Which scanner should I use?', 'Trendline Hero for swing reversals, the Confluence Scanner for intraday reversals, the Trend Line Scanner for breakouts.']],
  ['Trend line scanners', '/scanners/trendline-hero/']],
];

$slug = strtolower((string)($_GET['slug'] ?? ''));
header('Cache-Control: public, max-age=3600');
if ($slug === '' || !isset(LRN[$slug])) {
    if ($slug !== '') http_response_code(404);
    seo_head('Trading Guides: GEX, Trend Lines, Order Flow, AI | ChartPulse', 'Free plain-English trading guides: gamma exposure, call and put walls, trend line confluence, sweeps, order flow, volume profile, backtesting and AI forecasts.', '/learn/', [seo_crumbs([['Home', '/'], ['Learn', '/learn/']])]); ?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <span>Learn</span></nav>
  <h1>Trading guides</h1>
  <p class="muted">Short, plain-English guides to every tool on ChartPulse. Each one links to the free live scanner. See <a href="/features/">all tools</a>.</p>
  <ul class="seo-grid">
<?php foreach (LRN as $s => $x) { ?>    <li><a href="/learn/<?= $s ?>/"><b><?= seo_e($x[2]) ?></b><small><?= seo_e($x[3]) ?></small></a></li>
<?php } ?>
  </ul>
</main>
<?php seo_foot(); exit; }
[$t, $d, $h1, $intro, $secs, $faq, $tool] = LRN[$slug];
seo_head($t, $d, "/learn/$slug/", [seo_crumbs([['Home', '/'], ['Learn', '/learn/'], [$h1, "/learn/$slug/"]]),
    ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
    ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $h1, 'description' => $d, 'author' => ['@type' => 'Organization', 'name' => 'ChartPulse'], 'url' => SEO_BASE . "/learn/$slug/"]]); ?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <a href="/learn/">Learn</a> <span>/</span> <span><?= seo_e($h1) ?></span></nav>
  <h1><?= seo_e($h1) ?></h1>
  <p><?= seo_e($intro) ?></p>
  <p><a class="btn" href="<?= seo_e($tool[1]) ?>">Open <?= seo_e($tool[0]) ?> (free) →</a></p>
<?php foreach ($secs as [$h, $p]) { ?>  <h2><?= seo_e($h) ?></h2>
  <p><?= seo_e($p) ?></p>
<?php } ?>
  <h2>Questions</h2>
<?php foreach ($faq as [$q, $a]) { ?>  <h3><?= seo_e($q) ?></h3><p><?= seo_e($a) ?></p>
<?php } ?>
  <h2>More guides</h2>
  <p><?php $o = []; foreach (LRN as $s => $x) if ($s !== $slug) $o[] = '<a href="/learn/' . $s . '/">' . seo_e($x[2]) . '</a>'; echo implode(' · ', $o); ?></p>
  <p class="muted">For education only, not financial advice.</p>
</main>
<?php seo_foot();
