<?php
// EMA Strategies - scanner metadata. "win" is the strategy author's published figure, not verified by ChartPulse.
$SCANNERS = [
    "1-ema-orderblock-liquidity" => ["name"=>"EMA 200 + Order Block + Liquidity Sweep","win"=>"82-85%","tag"=>"Institutional","desc"=>"Price sweeps the prior 20-day high or low, closes back inside, and sits near the EMA 200 in the direction of its slope.","for"=>"Forex, Crypto, Gold, Swing","tf"=>"Daily"],
    "2-ema-rsi-divergence" => ["name"=>"EMA 200 + RSI Divergence + Engulfing","win"=>"78-82%","tag"=>"Triple Confirmation","desc"=>"RSI diverges from price on the last two swings, an engulfing candle confirms, and price is on the trend side of the EMA 200.","for"=>"Crypto, Forex, Stocks","tf"=>"Daily"],
    "3-ema-50-21-alignment" => ["name"=>"EMA 200 + EMA 50 + EMA 21 Alignment","win"=>"75-80%","tag"=>"Trend Rider","desc"=>"EMA 21, 50 and 200 stacked in order; entry on a pullback to the EMA 21 that closes back in trend direction.","for"=>"Trend-following Stocks","tf"=>"Daily"],
    "4-ema-support-volume" => ["name"=>"EMA 200 + Support/Resistance + Volume","win"=>"73-77%","tag"=>"Swing","desc"=>"A swing support or resistance level lines up with the EMA 200 and price reacts there on a 1.4x volume spike. Needs volume data.","for"=>"Stocks, Swing","tf"=>"Daily"],
    "5-vwap-ema-combo" => ["name"=>"VWAP + EMA 200 Combo","win"=>"71-75%","tag"=>"Momentum","desc"=>"Price crosses the rolling 20-day VWAP in the direction of the EMA 200 side. Daily-bar version of the intraday idea.","for"=>"Liquid Stocks","tf"=>"Daily"],
    "6-ichimoku-ema" => ["name"=>"Ichimoku Cloud + EMA 200","win"=>"70-73%","tag"=>"Advanced","desc"=>"Tenkan/Kijun cross in the last 5 days with price outside the cloud on the same side as the EMA 200.","for"=>"Forex, Crypto","tf"=>"Daily"],
    "7-ema-supertrend-rsi" => ["name"=>"EMA 200 + Supertrend + RSI 50","win"=>"68-72%","tag"=>"Momentum","desc"=>"Supertrend (10, 3) flipped in the last 3 days, RSI on the right side of 50, EMA 200 agrees.","for"=>"Crypto, Forex","tf"=>"Daily"],
    "8-ema-fibonacci" => ["name"=>"EMA 200 + Fibonacci 0.618 Golden Zone","win"=>"65-70%","tag"=>"Positional","desc"=>"A 60-day leg of at least 3 ATR; price pulls back into the 0.5-0.705 zone, turns, and the EMA 200 agrees.","for"=>"Positional Stocks","tf"=>"Daily"],
];
