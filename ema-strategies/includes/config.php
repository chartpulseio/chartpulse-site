<?php
// EMA Strategies - scanner metadata. Owner, 12 Nov 2026: only the most-searched scanners stay active; no win-rate figures are shown (none has been measured).
$SCANNERS = [
    "3-ema-50-21-alignment" => ["name"=>"EMA 200 + EMA 50 + EMA 21 Alignment","tag"=>"Trend Rider","desc"=>"EMA 21, 50 and 200 stacked in order; entry on a pullback to the EMA 21 that closes back in trend direction.","for"=>"Trend-following Stocks","tf"=>"Daily"],
    "5-vwap-ema-combo" => ["name"=>"VWAP + EMA 200 Combo","tag"=>"Momentum","desc"=>"Price crosses the rolling 20-day VWAP in the direction of the EMA 200 side. Daily-bar version of the intraday idea.","for"=>"Liquid Stocks","tf"=>"Daily"],
    "7-ema-supertrend-rsi" => ["name"=>"EMA 200 + Supertrend + RSI 50","tag"=>"Momentum","desc"=>"Supertrend (10, 3) flipped in the last 3 days, RSI on the right side of 50, EMA 200 agrees.","for"=>"Crypto, Forex","tf"=>"Daily"],
];
