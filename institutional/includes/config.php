<?php
// Institutional scanners - metadata. "win" is the strategy author's published figure, not verified by ChartPulse.
// Daily candles carry no tick or session data, so killzone, footprint and opening-range ideas run as daily proxies.
$SCANNERS = [
    "1-smc-power-of-3" => ["name"=>"SMC Power of 3 + OTE + Killzone","win"=>"84-88%","tag"=>"SMC","desc"=>"Liquidity sweep, displacement, then a pullback into the 0.62-0.79 optimal trade entry zone. Daily version (no intraday killzone).","for"=>"Forex, Gold, Crypto","tf"=>"Daily"],
    "2-order-flow-cvd" => ["name"=>"Order Flow + CVD Divergence + Footprint","win"=>"83-87%","tag"=>"Order Flow","desc"=>"Cumulative volume delta, estimated from where each candle closes in its range, diverging from price. Proxy for footprint data. Needs volume.","for"=>"Futures, Gold, Crypto","tf"=>"Daily"],
    "3-liquidity-fvg" => ["name"=>"Liquidity Sweep + Order Block + FVG","win"=>"82-86%","tag"=>"Premium","desc"=>"A liquidity sweep leaves a fair value gap; price returns into the gap and holds.","for"=>"Crypto, Gold, Forex","tf"=>"Daily"],
    "4-orb-vwap" => ["name"=>"Opening Range Breakout + VWAP + Volume Spike","win"=>"77-82%","tag"=>"Breakout","desc"=>"Daily range breakout (10 days) on 1.8x volume, beyond the 20-day VWAP. Daily proxy for the opening-range idea. Needs volume.","for"=>"Stocks","tf"=>"Daily"],
    "5-supply-demand" => ["name"=>"Supply/Demand Flip + Breaker Block + HTF Filter","win"=>"76-81%","tag"=>"Swing","desc"=>"A broken swing level flips polarity and is retested from the other side, in the direction of the weekly trend.","for"=>"Stocks, Crypto","tf"=>"Daily"],
    "6-mean-reversion" => ["name"=>"Statistical Mean Reversion (BB Squeeze + RSI 2)","win"=>"75-80%","tag"=>"Mean Reversion","desc"=>"Bollinger bandwidth in its lowest 20% within the last 5 days, with RSI(2) below 10 or above 90.","for"=>"All markets","tf"=>"Daily"],
    "7-vwap-anchored" => ["name"=>"VWAP Reversion + Anchored VWAP","win"=>"71-75%","tag"=>"VWAP","desc"=>"Price stretched 1.2 ATR from the 20-day VWAP while holding the right side of the 60-day anchored VWAP. Target is the VWAP.","for"=>"Liquid Stocks","tf"=>"Daily"],
];
