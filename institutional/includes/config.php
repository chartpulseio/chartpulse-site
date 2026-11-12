<?php
// Institutional scanners - metadata. Owner, 12 Nov 2026: only the most-searched scanners stay active; no win-rate figures are shown (none has been measured).
// Daily candles carry no tick or session data, so killzone, footprint and opening-range ideas run as daily proxies.
$SCANNERS = [
    "1-smc-power-of-3" => ["name"=>"SMC Power of 3 + OTE + Killzone","tag"=>"SMC","desc"=>"Liquidity sweep, displacement, then a pullback into the 0.62-0.79 optimal trade entry zone. Daily version (no intraday killzone).","for"=>"Forex, Gold, Crypto","tf"=>"Daily"],
    "3-liquidity-fvg" => ["name"=>"Liquidity Sweep + Order Block + FVG","tag"=>"Premium","desc"=>"A liquidity sweep leaves a fair value gap; price returns into the gap and holds.","for"=>"Crypto, Gold, Forex","tf"=>"Daily"],
    "4-orb-vwap" => ["name"=>"Opening Range Breakout + VWAP + Volume Spike","tag"=>"Breakout","desc"=>"Daily range breakout (10 days) on 1.8x volume, beyond the 20-day VWAP. Daily proxy for the opening-range idea. Needs volume.","for"=>"Stocks","tf"=>"Daily"],
];
