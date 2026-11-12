<?php
require_once __DIR__ . '/includes/config.php';
$id = (string)($_GET['id'] ?? '');
if (!isset($SCANNERS[$id])) $id = array_key_first($SCANNERS);
$cfg = $SCANNERS[$id];
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=$e($cfg['name'])?> Scanner · ChartPulse</title><meta name="description" content="<?=$e($cfg['name'])?> scanner on the daily chart, free. <?=$e($cfg['desc'])?>"><link rel="canonical" href="https://chartpulse.io/ema-strategies/scanner.php?id=<?=$e($id)?>"><link rel="stylesheet" href="assets/css/style.css?v=2"></head><body class="dark">
<div class="header"><a href="index.php" class="back">&larr; EMA Strategies</a><span style="margin-left:12px;font-weight:700"><?=$e($cfg['name'])?></span><span class="live-data" style="margin-left:auto">&#9679; LIVE <small id="updated">Loading...</small></span><button onclick="loadData()" class="rescan">&#8635; Refresh</button></div>
<div style="background:#422006;border:1px solid #f59e0b;padding:12px 16px;border-radius:8px;margin:15px 20px;"><b style="color:#f59e0b"><?=$e($cfg['name'])?></b> - <span style="color:#d1d5db"><?=$e($cfg['desc'])?></span><br><small style="color:#9ca3af">Best for: <?=$e($cfg['for'])?> | Timeframe: <?=$e($cfg['tf'])?> | Entry = last close, SL = 1.5 ATR, TP = 3 ATR unless a nearer target is stated. The win rate is the strategy author's figure, not verified by ChartPulse. Not financial advice.</small></div>
<div class="search-bar"><input id="search" placeholder="Filter symbols: BTC, RELIANCE, Gold, EUR/USD..."><span id="liveCount">0 signals</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Symbol</th><th>Bias</th><th>Entry</th><th>SL</th><th>Target</th><th>Score</th><th>Setup</th><th>Change</th></tr></thead><tbody id="tbody"><tr><td colspan="9" style="text-align:center;padding:40px">Scanning...</td></tr></tbody></table></div>
<script>const STRAT_ID=<?=json_encode($id)?>;</script><script src="assets/js/scanner.js?v=2"></script>
<script src="/static/v2/tg-join.js?v=1" defer></script>
</body></html>
