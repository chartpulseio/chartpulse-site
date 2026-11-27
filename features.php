<?php
/**
 * Feature landing pages (owner, 28 Nov 2026, SEO phase 2: "each n every function ka heavy seo"):
 * /features/ and /features/<slug>/ -- one deep, server-rendered page per ChartPulse tool, with
 * SoftwareApplication + FAQPage + Breadcrumb schema, how-it-works steps, use cases, related guides and markets.
 * Marketing pages only: they link to the live tools and change no scanner (Day Drive files untouched).
 */
declare(strict_types=1);
require_once __DIR__ . '/torus_seo.php';
require_once __DIR__ . '/pseo.php';

// slug => [title<60, desc<155, H1, intro, [[h2, p], ...], [steps], [key features], [[q, a], ...], [tool name, url], [learn slugs], category]
const FEAT = [
'backtesting' => ['Free Backtesting Tool for Stocks, Forex & Crypto | ChartPulse', 'Backtest any trading strategy free on stocks, NIFTY, forex, gold and crypto: indicators, rules, win rate, drawdown, expectancy and equity curve in seconds.',
  'Pro Backtesting: test any strategy on real history, free', 'ChartPulse Pro Backtesting lets you build a strategy from indicators and price rules, run it on years of real candles and see exactly how it would have traded: every entry, exit, win, loss and the full equity curve. No code, no download, no credit card.',
  [['Why backtest before you trade', 'Most strategies that look good on a few hand-picked charts lose money over hundreds of trades. A backtest replaces opinion with numbers: how often the setup wins, how big the average win is compared with the average loss, and how deep the worst losing streak gets. If the numbers do not hold up on history, they rarely hold up live.'],
   ['What you can test', 'Combine moving averages (EMA, SMA, 200 EMA), RSI, MACD, Bollinger Bands, ATR, VWAP, Supertrend, breakouts of highs and lows, candle patterns and time filters. Set your stop as a fixed percentage, an ATR multiple or a swing level, and targets as a reward-to-risk multiple or a trailing stop. Long, short or both.'],
   ['The numbers that matter', 'Every run reports total trades, win rate, average win and loss in R, expectancy per trade, profit factor, maximum drawdown, longest losing streak and the equity curve. Expectancy is the key figure: a 40% win rate can be very profitable with 2.5R winners, while a 70% win rate can lose money if losses are large.'],
   ['Avoiding curve fitting', 'It is easy to tune a strategy until it fits the past perfectly and fails in the future. Keep rules simple, test on one period and confirm on a different one (walk-forward), and do not trust results with fewer than about 100 trades. ChartPulse shows the trade count next to every statistic so you can judge how much to trust it.'],
   ['Markets and timeframes', 'US stocks and ETFs, NIFTY, BANKNIFTY and Indian stocks, forex majors, Gold, Silver, oil and the main cryptocurrencies, on timeframes from 5 minutes to daily.']],
  ['Pick a market and timeframe, for example NIFTY 15 minutes or BTCUSD 1 hour.', 'Add entry rules from the indicator list, such as "close above 200 EMA and RSI crosses above 50".', 'Choose the stop and target: ATR stop, fixed R target or trailing exit.', 'Run the test and read win rate, expectancy, drawdown and the equity curve.', 'Change one rule at a time and confirm the best version on a second period.'],
  ['No-code strategy builder with 20+ indicators', 'Win rate, expectancy, profit factor, max drawdown', 'Trade list with every entry and exit', 'Equity curve and losing-streak analysis', 'Stocks, NIFTY, forex, gold and crypto', 'AI Backtesting: describe a strategy in plain words'],
  [['Is the backtesting tool really free?', 'Yes. ChartPulse is free during the launch period; after that full access is $99 per month.'], ['Can I backtest NIFTY and BANKNIFTY?', 'Yes, NIFTY, BANKNIFTY and Indian stocks are supported, as well as US, forex, commodities and crypto.'], ['Do I need to write code?', 'No. Rules are chosen from menus. If you prefer, AI Backtesting turns a strategy written in plain English into a test.'], ['How many trades do I need?', 'Aim for at least 100 trades before trusting a result, and confirm it on a period you did not tune on.'], ['Does a good backtest guarantee profits?', 'No. It shows how rules behaved in the past. Slippage, fees and changing markets matter, so trade small at first.']],
  ['Pro Backtesting', '/backtesting/'], ['backtesting-trading-strategy', 'ai-price-forecast'], 'Backtesting'],

'ai-backtesting' => ['AI Backtesting: Test a Strategy Written in Plain English | ChartPulse', 'Describe your trading strategy in plain English and AI turns it into a backtest: rules, entries, exits and results on real data. Free AI strategy tester.',
  'AI Backtesting: describe a strategy, get a backtest', 'Type a strategy the way you would explain it to a friend, for example "buy NIFTY when the 9 EMA crosses above the 21 EMA above the 200 EMA, stop under the last swing low, target 2R", and ChartPulse AI turns it into exact rules and runs the backtest for you.',
  [['From words to rules', 'The AI reads your description, picks out the market, timeframe, indicators, entry conditions, stop and target, and shows the rules it understood before running anything. You can correct any rule in plain words.'],
   ['Real results, not AI guesses', 'The AI only writes the rules. The test itself runs on real historical candles with the same engine as Pro Backtesting, so win rate, expectancy and drawdown are calculated, not invented.'],
   ['Good for idea testing', 'Seen a strategy on YouTube or in a trading group? Paste the description and find out in a minute whether it ever worked. Most do not, which saves real money.'],
   ['Limits', 'Vague descriptions produce vague rules. Mention the timeframe, the exact trigger and where the stop goes. Discretionary ideas ("buy when it looks strong") cannot be tested until they are made precise.']],
  ['Write the strategy in one or two sentences.', 'Check the rules the AI extracted and fix anything it got wrong.', 'Run the backtest on the market and period you care about.', 'Read the statistics and ask the AI to try a variation.'],
  ['Plain-English strategy input', 'Shows the exact rules before testing', 'Real historical data, real statistics', 'Variations in one click', 'Stocks, NIFTY, forex, gold, crypto'],
  [['Is AI Backtesting free?', 'Yes, during the free access period.'], ['Can the AI make up results?', 'No. The AI only converts words into rules; results come from running those rules on real candles.'], ['Which strategies can it test?', 'Any rule-based idea using price, moving averages, oscillators, volatility, time of day and stop/target logic.'], ['Can I test option strategies?', 'Not yet. It tests the underlying market (stocks, indices, forex, crypto, commodities).']],
  ['AI Backtesting', '/ai-backtesting/'], ['backtesting-trading-strategy', 'ai-price-forecast'], 'Backtesting'],

'ai-forecast' => ['AI Stock & Crypto Price Forecast (24 Hours) | ChartPulse', 'Free AI price forecast for the next 24 hours on stocks, NIFTY, gold, forex and Bitcoin, from four AI time-series models combined, with a live accuracy record.',
  'AI Forecast: the next 24 hours from four AI models', 'ChartPulse runs four modern AI time-series models on every market and combines them into one forecast: the likely direction over the next 24 hours, a central path and a range. Every forecast is graded afterwards, so you can see how accurate it really is.',
  [['The models', 'Large pre-trained forecasting models (in the family of TimesFM and Chronos) learned patterns from millions of time series. ChartPulse runs four of them, weights them by recent accuracy and shows where they agree and disagree.'],
   ['Reading the forecast', 'The chart shows the expected path and a shaded range. A narrow range with all models agreeing is a stronger forecast than a wide range with mixed votes. A confidence score summarises both.'],
   ['Honest accuracy', 'Short-term price forecasting is hard. On its own, an AI forecast is only modestly better than chance. ChartPulse logs every forecast and publishes the hit rate, so you never have to take accuracy on trust.'],
   ['How traders use it', 'As a second opinion: a bullish forecast that agrees with the daily trend and a support level is worth more than either alone. Combine it with God-father setups, trend lines and gamma levels.']],
  ['Open AI Forecast and pick a market.', 'Read the direction, path and range for the next 24 hours.', 'Check model agreement and the confidence score.', 'Compare with the trend, levels and the live accuracy record before acting.'],
  ['Four AI models combined', '24-hour path and range', 'Model agreement and confidence', 'Live graded accuracy record', 'Stocks, NIFTY, gold, forex and crypto'],
  [['Is the AI forecast free?', 'Yes, during the free access period.'], ['How accurate is it?', 'Accuracy is published live on the page. Treat it as one input, not a guarantee.'], ['Which markets are covered?', 'US stocks and indices, NIFTY and BANKNIFTY, Gold, Silver, forex majors, Bitcoin, Ethereum and more.'], ['How often is it updated?', 'Forecasts refresh through the day as new candles close.']],
  ['AI Forecast 24h', '/scanners/forecast-24h/'], ['ai-price-forecast', 'trend-line-trading'], 'AI'],

'next-candle' => ['Next Candle Prediction AI (1m to 1h) | ChartPulse', 'Free next candle prediction with AI for gold, forex, crypto, NIFTY and stocks: up or down for the next candle, confidence, gamma label and live win rate.',
  'Next Candle AI: will the next candle be green or red?', 'Next Candle AI predicts the colour of the next candle on short timeframes, from 1 minute to 1 hour, for gold, forex, crypto, indices and stocks. Each prediction shows a confidence level, the gamma/delta context where available, and is graded the moment the candle closes.',
  [['How it predicts', 'Several AI models and a pattern engine read the recent candles, volatility, trend and momentum, then vote. The page shows the vote, the confidence and which way the higher timeframe leans.'],
   ['Gamma and delta label', 'For markets with options data (US indices, gold, NIFTY), each card shows whether dealer gamma and delta favour or oppose the prediction. A live test is measuring whether that label improves the hit rate.'],
   ['Graded live', 'Every prediction is saved and checked against the real candle. Win rate by market, timeframe and confidence band is published, so you can stick to the conditions that actually work.'],
   ['Binary options and scalping', 'Short-term traders use it to filter entries: only trade when confidence is high and the higher timeframe agrees. No prediction tool is right every time; size positions so a losing streak does not hurt.']],
  ['Choose a market and timeframe (1m, 5m, 15m or 1h).', 'Read the predicted direction and confidence.', 'Check the gamma/delta label and higher-timeframe bias.', 'Use the live win-rate table to see which conditions are worth trading.'],
  ['Next candle up/down prediction', 'Confidence score and model vote', 'Gamma/delta favour label', 'Live graded win rate', 'Gold, forex, crypto, NIFTY, stocks'],
  [['Can AI really predict the next candle?', 'Somewhat better than chance in the right conditions. The live record on the page shows exactly how well.'], ['Is it good for binary options?', 'It can help filter trades, but binary options are high risk; trade small.'], ['Which timeframes are supported?', '1 minute to 1 hour.'], ['Is it free?', 'Yes, during the free access period.']],
  ['Next Candle AI', '/scanners/next-candle/'], ['ai-price-forecast', 'gamma-exposure-gex'], 'AI'],

'gex' => ['Free GEX: Gamma Exposure for SPX, NIFTY, Gold, BTC | ChartPulse', 'Live gamma exposure (GEX) free: call wall, put wall, zero gamma and regime for SPX, SPY, QQQ, NIFTY, BANKNIFTY, Gold and Bitcoin, updated every minute.',
  'GEX terminal: live gamma exposure for every major market', 'The ChartPulse GEX terminal shows dealer gamma exposure by strike for US indices and stocks, NIFTY, BANKNIFTY, FINNIFTY, Gold, Silver, Bitcoin and Ethereum: the call wall, put wall, zero gamma and whether the market is in a calm (positive) or fast (negative) gamma regime.',
  [['What gamma exposure tells you', 'Option dealers hedge as price moves. In positive gamma they sell rallies and buy dips, so ranges and reversals work. In negative gamma they chase the move, so breakouts run further. Knowing the regime tells you which kind of setup to favour today.'],
   ['Walls and zero gamma', 'The call wall often caps rallies and the put wall often supports dips. Zero gamma is the flip point between the two regimes. ChartPulse plots them on the chart and lists their distance from price.'],
   ['All-in-one chart', 'Price, gamma bars by strike, volume profile, big-volume bubbles and a delta pane in one professional chart, with layer toggles so you see only what you need. A multi-chart view compares markets side by side.'],
   ['Global coverage', 'SPX and SPY, QQQ, IWM and US mega-caps; NIFTY, BANKNIFTY and FINNIFTY from NSE options; Gold and Silver scaled from ETF options; Bitcoin and Ethereum from crypto options. One terminal for every market you trade.']],
  ['Open the GEX terminal and pick a market.', 'Read the regime: positive (calm) or negative (fast).', 'Mark the call wall, put wall and zero gamma.', 'Favour reversals at walls in positive gamma and breakouts in negative gamma.'],
  ['Live GEX by strike, every minute', 'Call wall, put wall, zero gamma', 'Positive/negative gamma regime', 'Volume profile, bubbles and delta pane', 'US, NIFTY, Gold, Silver, BTC, ETH'],
  [['Is GEX free on ChartPulse?', 'Yes, during the free access period, including NIFTY and Gold GEX.'], ['How is NIFTY GEX calculated?', 'From NSE option chain open interest and implied volatility across expiries.'], ['How is Gold GEX shown in gold prices?', 'Gold ETF option levels are scaled to the XAUUSD price.'], ['Is GEX a buy or sell signal?', 'No, it is context. Combine it with trend lines and order flow.']],
  ['GEX terminal', '/scanners/gex/'], ['gamma-exposure-gex', 'call-wall-put-wall'], 'Options'],

'godfather' => ['God-father: A+ Trade Setups from Every Scanner | ChartPulse', 'God-father combines trend, location, setup, trigger and order flow from every ChartPulse scanner into graded A+ and A trade setups, with a live track record.',
  'God-father: only the A+ and A setups, from every scanner', 'God-father is the top-level ChartPulse scanner. It reads all the other scanners, counts each group of evidence once, and grades every market from A+ down to Watch. By default it shows only A+ and A setups, the ones where trend, location, setup and trigger all agree.',
  [['Five groups, counted once', 'Bias (daily and weekly trend), Location (a confluence zone or Hero trend line, the day high or low, the 1-hour 200 EMA), Setup (a sweep or rejection at that location), Trigger (a 5-minute structure change) and Extras. Similar signals are grouped so the same evidence is never counted twice.'],
   ['Trap-first setups', 'The best reversals often start with a stop run through the level. God-father ranks a sweep that closes back inside above a simple touch, and drops anything that has already broken out.'],
   ['Order flow as a booster', 'Where volume delta, big-volume candles, volume profile or dealer gamma agree with the setup, an A can be lifted to A+. Where that data does not exist, an A+ is not downgraded.'],
   ['Graded and logged', 'Every A+ and A setup is logged with entry, stop and targets and graded when it resolves. Win rate and average R by grade are shown with confidence intervals, so you can see whether A+ really beats A.']],
  ['Open God-father; it shows A+ and A by default.', 'Read the reasons: bias, location, setup and trigger.', 'Check the entry, stop and targets on the card.', 'Use the track record to decide which grades to trade.'],
  ['Combines every ChartPulse scanner', 'A+/A/B grading with reasons', 'Sweep-first reversal logic', 'Order flow and GEX booster', 'Live graded track record'],
  [['What makes a setup A+?', 'Daily and weekly bias agree, price is at a strong location with at least two inputs, a sweep or rejection has formed and the 5-minute trigger has fired.'], ['Does missing order flow lower the grade?', 'No. Order flow can only raise a grade.'], ['Which markets does it scan?', 'US stocks and indices, NIFTY and Indian stocks, forex, gold and crypto.'], ['Are alerts available?', 'Yes, A+ setups can be sent to Telegram.']],
  ['God-father', '/scanners/godfather/'], ['liquidity-sweep-fake-breakout', 'trend-line-confluence'], 'Scanners'],

'confluence-scanner' => ['Multi-Timeframe Trend Line Confluence Scanner | ChartPulse', 'Free confluence scanner: trend lines from 4H, 1H, 30m and 15m in one zone, daily and weekly bias, ATR trap rules and a 5-minute CHoCH entry trigger.',
  'Confluence Scanner: where trend lines from several charts meet', 'The Confluence Scanner finds prices where trend lines from the 4-hour, 1-hour, 30-minute and 15-minute charts sit in one tight zone, in the direction of the daily and weekly trend, and tracks each zone from approach to entry.',
  [['Building the zone', 'Lines from each timeframe are projected to the current candle. When two or more fall within about a third of the daily ATR, they form a confluence zone. More timeframes, and the 4-hour line in particular, make the zone stronger.'],
   ['Trend filter', 'Bearish daily and weekly bias: only resistance zones are traded for shorts. Bullish bias: only support zones for longs. Counter-trend zones are ignored.'],
   ['ATR trap rules', 'A poke through the zone of up to half the daily ATR that comes back inside is a sweep, often the best entry. A close more than half an ATR beyond is a breakout and the zone is dropped.'],
   ['Stage ladder', 'Approaching, at zone, sweep, 15-minute rejection and finally 5-minute CHoCH GO. Each symbol page shows the technical view, order flow where available and a combined chart.']],
  ['Open the Confluence Scanner.', 'Sort by stage; GO rows have a confirmed 5-minute trigger.', 'Open a symbol to see the zone, its lines and order flow.', 'Place the stop beyond the sweep and target 1.5 to 2.5R.'],
  ['4H/1H/30m/15m line clustering', 'Daily + weekly bias filter', 'Sweep vs breakout ATR rule', 'Stage ladder to 5m CHoCH GO', 'Symbol page with order flow'],
  [['How many timeframes make a confluence?', 'At least two; three or four is stronger.'], ['What is a CHoCH?', 'A change of character: the first break of the opposite swing on the 5-minute chart, showing the reversal has started.'], ['Is it free?', 'Yes, during the free access period.'], ['Which markets?', 'US stocks, NIFTY and Indian stocks, forex, gold and crypto.']],
  ['Confluence Scanner', '/scanners/confluence/'], ['trend-line-confluence', 'liquidity-sweep-fake-breakout'], 'Scanners'],

'trendline-scanner' => ['Automatic Trend Line Scanner (All Timeframes) | ChartPulse', 'Free automatic trend line scanner: lines drawn on every timeframe, bounces, breakouts, retests and sweeps ranked for stocks, NIFTY, forex, gold and crypto.',
  'Trendline Hero: automatic trend lines, ranked setups', 'Trendline Hero draws trend lines automatically on every timeframe from 10 minutes to weekly, using 20 years of history, then ranks the markets where price is reacting to a strong line right now: bounces with the trend, sweeps, breakouts and retests.',
  [['How lines are drawn', 'A multi-level swing engine anchors lines on wick extremes, requires several touches, rejects lines cut by candle bodies, and keeps the strongest lines per timeframe. Breaks need a body close or two closes beyond the line.'],
   ['Swing and intraday modes', 'Swing mode ranks daily and 4-hour lines; Intraday mode focuses on 1-hour down to 15-minute lines. A 200 EMA filter keeps only lines that sit on the 1-hour 200 EMA.'],
   ['Break and retest', 'When a line breaks, the scanner watches for price to return and hold on the other side, one of the most reliable continuation setups.'],
   ['Confluence badge', 'Lines that agree with lines on other timeframes get a confluence badge, so you can spot the strongest levels at a glance.']],
  ['Open Trendline Hero and choose Swing or Intraday.', 'Filter by market and setup type.', 'Open a chart to see the line, touches and the reaction.', 'Trade bounces with the daily and weekly trend, or retests after a break.'],
  ['Automatic lines 10m to weekly', '20-year history', 'Bounce, sweep, breakout, retest', '200 EMA and confluence filters', 'Stocks, NIFTY, forex, gold, crypto'],
  [['Are the trend lines drawn automatically?', 'Yes, on every timeframe, using wick anchors and touch counts.'], ['Can I scan NIFTY stocks?', 'Yes, NIFTY, BANKNIFTY and Indian stocks are included.'], ['What is the difference with the Trend Line Scanner?', 'Trendline Hero ranks reversals and retests; the Trend Line Scanner lists breakouts.'], ['Is it free?', 'Yes, during the free access period.']],
  ['Trendline Hero', '/scanners/trendline-hero/'], ['trend-line-trading', 'break-and-retest'], 'Scanners'],

'day-drive' => ['Day Drive: Intraday Reversal Scanner at Trend Lines | ChartPulse', 'Day Drive ranks intraday reversals at the daily trend line: touch or fake-out, rejection candle, 5-minute CHoCH and retest, plus trend setups, for stocks and indices.',
  'Day Drive: intraday reversals at the daily trend line', 'Day Drive is built for one thing: catching the intraday reversal when today\'s high or low reaches a daily trend line. It ranks every reversal above ordinary trend setups and shows how far each one has progressed.',
  [['The daily line', 'For a short, the daily resistance line must sit within one daily ATR of today\'s high; for a long, the daily support line within one ATR of today\'s low. Using daily lines means a signal can stay valid for two to three days.'],
   ['Touch or fake-out', 'A touch means today\'s extreme reached the line. A fake-out means price went through it but is now within half an ATR beyond it, or back inside. More than half an ATR beyond is a breakout and is not ranked.'],
   ['Reversal ladder', 'At the line, then a rejection candle on 5 or 15 minutes, then a 5-minute change of character (BOUNCE GO), then a held retest. Fake-outs with a retest rank highest.'],
   ['Labels, not filters', 'Key zones, weekly lines, RSI divergence, volume spikes and dealer gamma are shown as labels to help you judge, but they never hide a signal.']],
  ['Open Day Drive during the session.', 'Look at the reversal rows at the top first.', 'Check the ladder step: rejection, CHoCH or retest.', 'Use the labels and the chart to confirm, then manage risk beyond the extreme.'],
  ['Daily trend line reversals', 'Touch vs fake-out logic', '4-step reversal ladder', 'Confirmation labels incl. gamma', 'Trend setups listed below reversals'],
  [['What is a BOUNCE GO?', 'A reversal at the daily line where the 5-minute chart has confirmed a change of character.'], ['Are breakouts shown?', 'No. Day Drive focuses on reversals; breakouts are left to other scanners.'], ['Which markets?', 'US stocks and indices and Indian markets.'], ['Is it free?', 'Yes, during the free access period.']],
  ['Day Drive', '/scanners/day-drive/'], ['trend-line-trading', 'liquidity-sweep-fake-breakout'], 'Scanners'],

'smc-scanner' => ['Free SMC Scanner: Order Blocks, FVG, Liquidity Sweeps | ChartPulse', 'Free smart money concepts scanner: liquidity sweeps, order blocks, fair value gaps, power of 3 and OTE for stocks, forex, gold, NIFTY and crypto.',
  'SMC scanners: order blocks, fair value gaps and liquidity sweeps', 'ChartPulse runs smart money concepts (SMC) scanners across hundreds of markets: a liquidity sweep followed by an order block and fair value gap, and the Power of 3 with optimal trade entry. No more scrolling through charts looking for the pattern.',
  [['Liquidity sweep + order block + FVG', 'The scanner waits for price to run the stops above a high or below a low, then looks for the order block and fair value gap left by the move back. The entry zone is the gap or the block, with the stop beyond the sweep.'],
   ['Power of 3 + OTE', 'Accumulation, manipulation and distribution within the session. After the manipulation move, the optimal trade entry is the 62% to 79% retracement.'],
   ['ORB + VWAP', 'An opening range breakout scanner confirmed by VWAP, for traders who prefer the first hour of the session.'],
   ['Inside God-father', 'SMC signals also feed the God-father scanner as part of the Setup group, so an order block at a trend line confluence counts towards an A+ grade.']],
  ['Pick the SMC scanner you trade.', 'Filter by market and direction.', 'Open the chart to see the sweep, block and gap.', 'Enter in the zone with the stop beyond the sweep.'],
  ['Liquidity sweep detection', 'Order blocks and fair value gaps', 'Power of 3 and OTE zone', 'ORB + VWAP', 'Stocks, forex, gold, NIFTY, crypto'],
  [['Is there a free order block scanner?', 'Yes, during the free access period.'], ['Does it work on forex and gold?', 'Yes, along with stocks, indices and crypto.'], ['What is a fair value gap?', 'A three-candle imbalance where the middle candle moved so fast it left a gap between the first and third candles.'], ['Which timeframes?', 'Intraday timeframes from 5 minutes to 1 hour.']],
  ['Liquidity + Order Block + FVG', '/institutional/scanner.php?id=3-liquidity-fvg'], ['smart-money-concepts', 'liquidity-sweep-fake-breakout'], 'Scanners'],

'crypto-scanner' => ['Free Crypto Scanner & Arbitrage Finder | ChartPulse', 'Free crypto scanner: trend lines, AI forecasts, order flow, gamma and live cross-exchange arbitrage spreads for Bitcoin, Ethereum and top altcoins.',
  'Crypto scanners: setups, order flow and arbitrage', 'ChartPulse covers crypto end to end: trend line and confluence setups, AI forecasts, Bitcoin and Ethereum gamma exposure, full order flow and a live arbitrage finder that compares prices across exchanges.',
  [['Crypto arbitrage finder', 'Prices for the same coin differ between exchanges. The arbitrage scanner lists the live spreads, net of typical fees, so you can see where the gap is wide enough to matter.'],
   ['Setups on every coin', 'Bitcoin, Ethereum and the main altcoins run through Trendline Hero, the Confluence Scanner, God-father and the SMC scanners, around the clock.'],
   ['Bitcoin and Ethereum GEX', 'Dealer gamma from crypto options shows the call wall, put wall and regime for BTC and ETH.'],
   ['Order flow', 'Cumulative volume delta, open interest, funding, the order book and liquidations for each coin.']],
  ['Open the crypto arbitrage or setup scanners.', 'Filter by coin and setup.', 'Check order flow and gamma for context.', 'Act only when fees and risk still leave an edge.'],
  ['Cross-exchange arbitrage spreads', 'Trend line and confluence setups 24/7', 'BTC and ETH gamma exposure', 'CVD, open interest, liquidations', 'AI forecast for crypto'],
  [['Is the crypto arbitrage scanner free?', 'Yes, during the free access period.'], ['Are fees included in the spreads?', 'Typical trading fees are estimated; withdrawal costs and timing still matter.'], ['Which coins?', 'Bitcoin, Ethereum and the most traded altcoins.'], ['Is there crypto GEX?', 'Yes, for Bitcoin and Ethereum.']],
  ['Crypto Arbitrage', '/scanners/crypto-arbitrage/'], ['order-flow-volume-delta', 'gamma-exposure-gex'], 'Crypto'],

'order-flow' => ['Free Crypto Order Flow: CVD, Open Interest, Liquidations | ChartPulse', 'Free order flow tools: cumulative volume delta, big-volume bubbles, volume profile, open interest, order book and liquidations, explained simply.',
  'Order Flow: see who is really buying and selling', 'Price shows what happened; order flow shows who pushed it. ChartPulse Order Flow gives cumulative volume delta, big-volume bubbles, volume profile, open interest, the order book and liquidations for each coin, free.',
  [['Volume delta and CVD', 'Delta is buying volume minus selling volume. Cumulative delta (CVD) adds it up, so you can see whether aggressive buyers or sellers are in control, and spot divergences when price and CVD disagree.'],
   ['Absorption at levels', 'Heavy selling into support that fails to push price lower is absorption: buyers are soaking it up. Combined with a trend line or confluence zone it makes a strong reversal clue.'],
   ['Volume profile', 'Point of control and value area show the prices the market accepted. A move back into value often travels to the POC.'],
   ['Open interest and liquidations', 'Rising open interest with price shows new positions; liquidation spikes often mark the end of a move.']],
  ['Open Order Flow and pick a coin.', 'Read CVD against price for divergence.', 'Check volume profile levels and big-volume bubbles.', 'Use open interest and liquidations to judge whether the move is fresh or exhausted.'],
  ['Cumulative volume delta', 'Big-volume bubbles', 'Volume profile POC and value area', 'Open interest and funding', 'Order book and liquidations'],
  [['Is order flow free?', 'Yes, during the free access period.'], ['Is candle-based delta exact?', 'Crypto delta uses trade data; for other markets ChartPulse estimates it from candles, which is a rougher guide.'], ['Does order flow change God-father grades?', 'It can lift an A to A+ when it agrees; missing data never lowers a grade.']],
  ['Crypto Order Flow', '/scanners/order-flow/'], ['order-flow-volume-delta', 'volume-profile-poc'], 'Crypto'],

'news-scanner' => ['Stock News Scanner with Impact Score | ChartPulse', 'Free news scanner: market-moving headlines for stocks, indices, forex, gold and crypto with an impact score and the affected symbols, updated live.',
  'News Scanner: the headlines that move markets', 'The ChartPulse News Scanner collects market news as it breaks, scores each headline for likely impact and tags the symbols it affects, so you know why a market is moving and when to stay out.',
  [['Impact score', 'Each headline gets an impact score based on the source, the event type (earnings, central banks, data, guidance, regulation) and how the market has reacted to similar news.'],
   ['Symbols tagged', 'Headlines are linked to the stocks, indices, currencies, commodities or coins they affect, and appear on those symbols\' bias pages.'],
   ['News risk in other scanners', 'God-father marks setups with high-impact news nearby as News Risk, so you do not walk into a scheduled event without knowing.'],
   ['Independent of bias', 'The News Scanner works on its own; it does not depend on the trend models.']],
  ['Open the News Scanner.', 'Sort by impact or filter by market.', 'Open a symbol to see its trend bias and setups.', 'Avoid new entries right before high-impact events.'],
  ['Live market news', 'Impact score per headline', 'Affected symbols tagged', 'News Risk flag in God-father', 'Stocks, forex, gold, crypto, indices'],
  [['Is the news scanner free?', 'Yes, during the free access period.'], ['Which markets are covered?', 'US and Indian stocks, indices, forex, commodities and crypto.'], ['How fast is it?', 'Headlines appear within minutes of publication.']],
  ['News Scanner', '/scanners/news/'], ['liquidity-sweep-fake-breakout', 'gamma-exposure-gex'], 'Research'],

'ai-scanner' => ['AI Trading Scanner for Stocks, NIFTY, Forex & Crypto | ChartPulse', 'Free AI trading scanner: an ensemble of AI and rule-based models ranks long and short setups with confidence scores across stocks, NIFTY, forex, gold and crypto.',
  'AI Scanner: an ensemble of models ranking every market', 'The ChartPulse AI Scanner runs an ensemble of AI forecasting models and rule-based checks on every market and ranks the strongest long and short candidates, with a confidence score and the reasons behind it.',
  [['Ensemble, not a single model', 'Several AI models and classic technical checks vote. A setup ranks high only when they agree, which filters out the noise any single model produces.'],
   ['Reasons you can read', 'Each row lists why it ranked: trend, momentum, level, forecast agreement. No black box.'],
   ['Tracked results', 'Signals are logged and graded so the hit rate by confidence band is visible.'],
   ['Works with the other tools', 'Open any symbol to see its trend lines, gamma levels, order flow and God-father grade.']],
  ['Open the AI Scanner.', 'Filter by market and direction.', 'Read the confidence and the reasons.', 'Confirm with levels and risk before trading.'],
  ['Ensemble of AI and rule-based models', 'Long and short rankings', 'Confidence score with reasons', 'Logged, graded signals', 'Stocks, NIFTY, forex, gold, crypto'],
  [['Is the AI scanner free?', 'Yes, during the free access period.'], ['How is it different from AI Forecast?', 'AI Forecast shows one market\'s next 24 hours; the AI Scanner ranks all markets at once.'], ['Can I scan NIFTY stocks with AI?', 'Yes, Indian stocks and indices are included.']],
  ['AI Scanner', '/scanner.html'], ['ai-price-forecast', 'backtesting-trading-strategy'], 'AI'],
];

function feat_kw(): array { return ['Backtesting', 'AI', 'Options', 'Scanners', 'Crypto', 'Research']; }

$slug = strtolower((string)($_GET['slug'] ?? ''));
header('Cache-Control: public, max-age=3600');
$learnT = ['gamma-exposure-gex' => 'What is GEX', 'call-wall-put-wall' => 'Call wall and put wall', 'trend-line-confluence' => 'Trend line confluence', 'break-and-retest' => 'Break and retest', 'liquidity-sweep-fake-breakout' => 'Liquidity sweeps', 'order-flow-volume-delta' => 'Order flow and delta', 'volume-profile-poc' => 'Volume profile', 'backtesting-trading-strategy' => 'How to backtest', 'ai-price-forecast' => 'AI price forecasts', 'smart-money-concepts' => 'Smart money concepts', '200-ema-strategy' => '200 EMA strategy', 'trend-line-trading' => 'Trend line trading'];

if ($slug === '' || !isset(FEAT[$slug])) {
    if ($slug !== '') http_response_code(404);
    $items = []; $i = 1; foreach (FEAT as $s => $x) $items[] = ['@type' => 'ListItem', 'position' => $i++, 'url' => SEO_BASE . "/features/$s/", 'name' => $x[2]];
    seo_head('All Trading Tools: Scanners, GEX, AI, Backtesting | ChartPulse', 'Every ChartPulse tool in one place: free backtesting, AI forecasts, next candle AI, GEX, God-father, confluence and trend line scanners, SMC, crypto and order flow.', '/features/',
        [seo_crumbs([['Home', '/'], ['Features', '/features/']]), ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items]]); ?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <span>Features</span></nav>
  <h1>Every ChartPulse trading tool</h1>
  <p class="muted">Professional scanners, gamma exposure, AI forecasts and backtesting for stocks, NIFTY, forex, gold and crypto. Free access for a limited time, then $99/month.</p>
<?php foreach (feat_kw() as $cat) { ?>  <h2><?= seo_e($cat) ?></h2>
  <ul class="seo-grid">
<?php foreach (FEAT as $s => $x) if ($x[10] === $cat) { ?>    <li><a href="/features/<?= $s ?>/"><b><?= seo_e($x[2]) ?></b><small><?= seo_e($x[1]) ?></small></a></li>
<?php } ?>
  </ul>
<?php } ?>
  <p>New to these tools? Start with the <a href="/learn/">trading guides</a> or see <a href="/today/">today's market report</a>.</p>
</main>
<?php seo_foot(); exit; }

[$t, $d, $h1, $intro, $secs, $steps, $keys, $faq, $tool, $learn, $cat] = FEAT[$slug];
$toolUrl = SEO_BASE . $tool[1];
seo_head($t, $d, "/features/$slug/", [
    seo_crumbs([['Home', '/'], ['Features', '/features/'], [$h1, "/features/$slug/"]]),
    ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'ChartPulse ' . $tool[0], 'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web',
     'description' => $d, 'url' => $toolUrl, 'featureList' => implode(', ', $keys),
     'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD', 'description' => 'Free for a limited time, then $99/month'],
     'publisher' => ['@id' => SEO_BASE . '/#org']],
    ['@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => 'How to use ' . $tool[0], 'step' => array_map(fn($s, $i) => ['@type' => 'HowToStep', 'position' => $i + 1, 'text' => $s], $steps, array_keys($steps))],
    ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
]); ?>
<main class="page wrap seo" id="main">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>/</span> <a href="/features/">Features</a> <span>/</span> <span><?= seo_e($tool[0]) ?></span></nav>
  <h1><?= seo_e($h1) ?></h1>
  <p><?= seo_e($intro) ?></p>
  <p><a class="btn" href="<?= seo_e($tool[1]) ?>">Open <?= seo_e($tool[0]) ?> free →</a> <span class="muted">Free access for a limited time, then $99/month.</span></p>
  <h2>Key features</h2>
  <ul>
<?php foreach ($keys as $k) { ?>    <li><?= seo_e($k) ?></li>
<?php } ?>
  </ul>
<?php foreach ($secs as [$h, $p]) { ?>  <h2><?= seo_e($h) ?></h2>
  <p><?= seo_e($p) ?></p>
<?php } ?>
  <h2>How to use <?= seo_e($tool[0]) ?></h2>
  <ol>
<?php foreach ($steps as $s) { ?>    <li><?= seo_e($s) ?></li>
<?php } ?>
  </ol>
<?php if (isset(PT[$slug])) { ?>  <h2><?= seo_e($tool[0]) ?> by market</h2>
  <p><?php $o = []; foreach (PM as $s => $x) $o[] = '<a href="/features/' . $slug . '/' . $s . '/">' . seo_e($x[1]) . '</a>'; echo implode(' · ', $o); ?></p>
<?php } ?>
  <h2>Markets covered</h2>
  <p>US stocks and ETFs (SPY, QQQ, AAPL, NVDA, TSLA), Indian markets (NIFTY, BANKNIFTY, FINNIFTY, NSE stocks), forex majors, Gold (XAUUSD), Silver, oil, Bitcoin, Ethereum and leading altcoins. See live bias pages in the <a href="/bias/">market directory</a> and gamma levels on the <a href="/gex/">GEX hub</a>.</p>
  <h2>Frequently asked questions</h2>
<?php foreach ($faq as [$q, $a]) { ?>  <h3><?= seo_e($q) ?></h3><p><?= seo_e($a) ?></p>
<?php } ?>
  <h2>Learn more</h2>
  <p><?php $o = []; foreach ($learn as $l) if (isset($learnT[$l])) $o[] = '<a href="/learn/' . $l . '/">' . seo_e($learnT[$l]) . '</a>'; echo implode(' · ', $o); ?></p>
  <h2>Other ChartPulse tools</h2>
  <p><?php $o = []; foreach (FEAT as $s => $x) if ($s !== $slug) $o[] = '<a href="/features/' . $s . '/">' . seo_e($x[8][0]) . '</a>'; echo implode(' · ', $o); ?></p>
  <p><a class="btn" href="<?= seo_e($tool[1]) ?>">Try <?= seo_e($tool[0]) ?> now →</a></p>
  <p class="muted">For education only, not financial advice. Trading involves risk.</p>
</main>
<?php seo_foot();
