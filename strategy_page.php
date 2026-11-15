<?php
/**
 * Strategy scanner pages (owner, 15 Nov 2026): one template for /institutional/ and /ema-strategies/, in the same look as
 * God-father, 200 EMA and Day Drive. The scan engines and their API (api/scan.php) are unchanged; this file is only the page.
 * Used by <dir>/scanner.php and <dir>/index.php. Opening this file directly prints nothing.
 */
declare(strict_types=1);

/** Plain-words explanation of each strategy: one line, three steps, and what a call means. */
function strat_easy(): array {
    return [
        '3-liquidity-fvg' => ['short' => 'Liquidity Sweep + FVG', 'plain' => 'Price pokes past an obvious high or low to trigger stop orders, snaps back and leaves a gap between candles. When price returns to that gap and holds, this scanner lists it.',
            'steps' => [['STOP HUNT', 'Price takes out a high or low', 'A quick push beyond a recent high or low, where many stop orders sit.'], ['GAP LEFT BEHIND', 'Fast move back', 'Price reverses so quickly that it leaves a gap between candles (a fair value gap).'], ['RETURN TO THE GAP', 'The entry', 'Price comes back into that gap and holds. That is the call.']]],
        '1-smc-power-of-3' => ['short' => 'SMC Power of 3 + OTE', 'plain' => 'Big players first push price the wrong way to collect orders, then drive it the real way. This scanner waits for the pullback into the best-price zone of that drive.',
            'steps' => [['STOP HUNT', 'The fake move', 'Price sweeps a recent high or low and traps traders on the wrong side.'], ['THE REAL MOVE', 'Strong push back', 'A large candle in the opposite direction shows where the big money is going.'], ['BEST-PRICE ZONE', 'The entry', 'Price pulls back 62% to 79% of that push (the OTE zone). That is the call.']]],
        '4-orb-vwap' => ['short' => 'Range Breakout + VWAP + Volume', 'plain' => 'Price breaks out of the range it has held for 10 days, on much heavier volume than usual, and sits on the right side of its 20-day average price.',
            'steps' => [['THE RANGE', '10 quiet days', 'Price has stayed between a clear high and low for ten sessions.'], ['THE BREAK', 'Out with volume', 'Price closes outside the range on about 1.8 times normal volume.'], ['VWAP AGREES', 'The call', 'Price is also beyond its 20-day volume-weighted average price, on the breakout side.']]],
        '3-ema-50-21-alignment' => ['short' => 'EMA 200 + 50 + 21', 'plain' => 'The 21, 50 and 200-day averages are stacked in order, which means a clean trend. The call comes when price dips to the 21-day average and closes back with the trend.',
            'steps' => [['CLEAN TREND', 'Averages in order', 'EMA 21 above 50 above 200 for an uptrend (the reverse for a downtrend).'], ['THE DIP', 'Back to the 21', 'Price pulls back to the 21-day average, the trend\'s nearest support.'], ['THE CLOSE', 'The call', 'The candle closes back in the trend direction. That is the entry.']]],
        '7-ema-supertrend-rsi' => ['short' => 'EMA 200 + Supertrend + RSI', 'plain' => 'Three trend tools agree: Supertrend has just flipped, RSI is on the same side of 50, and price is on the same side of the 200-day average.',
            'steps' => [['SUPERTREND', 'A fresh flip', 'Supertrend (10, 3) changed direction within the last 3 days.'], ['RSI', 'Right side of 50', 'Momentum agrees: RSI above 50 for a CALL, below 50 for a PUT.'], ['EMA 200', 'The call', 'Price is on the same side of the 200-day average. All three agree.']]],
        '5-vwap-ema-combo' => ['short' => 'VWAP + EMA 200', 'plain' => 'Price crosses its 20-day average price (VWAP) in the same direction as the long trend shown by the 200-day average.',
            'steps' => [['LONG TREND', 'Side of the 200', 'Above the 200-day average only CALLs count, below it only PUTs.'], ['THE CROSS', 'Through VWAP', 'Price crosses its 20-day volume-weighted average price.'], ['SAME DIRECTION', 'The call', 'The cross points the same way as the long trend.']]],
    ];
}
/** Every strategy scanner on the site: [dir, id, cfg]. */
function strat_all(): array {
    $out = [];
    foreach (['institutional' => 'Smart money', 'ema-strategies' => 'EMA strategy'] as $dir => $grp) {
        $SCANNERS = []; include __DIR__ . "/$dir/includes/config.php";
        foreach ($SCANNERS as $id => $c) $out[] = ['dir' => $dir, 'id' => $id, 'grp' => $grp, 'cfg' => $c];
    }
    return $out;
}
function strat_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
function strat_head(string $title, string $desc, string $canon): void { ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= strat_e($title) ?> · ChartPulse</title>
<meta name="description" content="<?= strat_e($desc) ?>">
<meta name="theme-color" content="#050506">
<link rel="canonical" href="<?= strat_e($canon) ?>">
<meta property="og:site_name" content="ChartPulse"><meta property="og:type" content="website">
<meta property="og:title" content="<?= strat_e($title) ?> · ChartPulse"><meta property="og:description" content="<?= strat_e($desc) ?>">
<meta property="og:url" content="<?= strat_e($canon) ?>"><meta property="og:image" content="https://chartpulse.io/static/og.jpg">
<meta name="twitter:card" content="summary_large_image">

<link rel="stylesheet" href="/static/v2/fonts.css?v=1">
<link rel="stylesheet" href="/static/v2/sitehead.css?v=8">
<script defer src="/static/v2/sitehead.js?v=23"></script>
<style>
:root{--bg:#050506;--card:#0E0F13;--card2:#15171D;--line:rgba(255,255,255,.08);--line2:rgba(255,255,255,.15);
  --ink:#EDEDEF;--dim:#A3A5AF;--mute:#6B6D78;--gold:#E9C46A;--gold2:#F6DE9A;--up:#22AB94;--dn:#F7525F;--blue:#6EA8FE;
  --mono:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  --sans:"IBM Plex Sans",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
*{box-sizing:border-box}html,body{margin:0}
body{background:var(--bg);color:var(--ink);font:15px/1.55 var(--sans);-webkit-font-smoothing:antialiased}
a{color:inherit}
.gf{max-width:1260px;margin:0 auto;padding:24px 20px 70px}
.num{font-family:var(--mono);font-variant-numeric:tabular-nums}
button:focus-visible,a:focus-visible,input:focus-visible{outline:2px solid var(--gold);outline-offset:2px}

/* hero */
.gf-hero{position:relative;overflow:hidden;padding:34px 30px 30px;border:1px solid rgba(233,196,106,.28);border-radius:22px;
  background:radial-gradient(120% 140% at 100% 0%,rgba(233,196,106,.16),transparent 55%),radial-gradient(90% 120% at 0% 100%,rgba(34,171,148,.10),transparent 60%),#0A0B0E}
.gf-hero::after{content:"";position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(255,255,255,.025) 0 1px,transparent 1px 64px);pointer-events:none}
.gf-eye{display:inline-flex;align-items:center;gap:8px;font:600 12px/1 var(--mono);letter-spacing:.18em;color:var(--gold);text-transform:uppercase}
.gf-eye i{width:8px;height:8px;border-radius:50%;background:var(--up);box-shadow:0 0 0 0 rgba(34,171,148,.6);animation:gfp 2s infinite}
@keyframes gfp{70%{box-shadow:0 0 0 10px rgba(34,171,148,0)}100%{box-shadow:0 0 0 0 rgba(34,171,148,0)}}
.gf-hero h1{margin:12px 0 10px;font-size:clamp(30px,5vw,54px);line-height:1.04;letter-spacing:-.03em}
.gf-hero h1 span{background:linear-gradient(100deg,var(--gold2),var(--gold) 45%,#B8893A);-webkit-background-clip:text;background-clip:text;color:transparent}
.gf-lede{max-width:68ch;margin:0;color:var(--dim);font-size:17px}
.gf-flow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:24px 0 0;padding:0;list-style:none;position:relative;z-index:1}
.gf-flow li{padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:rgba(14,15,19,.85)}
.gf-flow span{font:600 11px/1 var(--mono);letter-spacing:.12em;color:var(--gold)}
.gf-flow b{display:block;margin:6px 0 3px;font-size:14.5px}
.gf-flow p{margin:0;color:var(--dim);font-size:13px;line-height:1.45}

/* tiles */
.gf-tiles{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin:18px 0 0}
.gf-tile{padding:14px 16px;border:1px solid var(--line);border-radius:14px;background:var(--card);cursor:pointer;text-align:left;color:inherit;font:inherit}
.gf-tile:hover{border-color:var(--line2)}.gf-tile.on{border-color:var(--gold);background:rgba(233,196,106,.07)}
.gf-tile .v{font:600 28px/1.05 var(--mono)}.gf-tile .k{margin-top:4px;color:var(--dim);font-size:13px}
.gf-tile.ap .v{color:var(--gold)}.gf-tile.a .v{color:var(--up)}.gf-tile.b .v{color:var(--blue)}.gf-tile.nr .v{color:var(--dn)}

/* bar */
.gf-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:20px 0 12px}
.gf-tabs{display:flex;flex-wrap:wrap;gap:6px}
.gf-tabs button,.gf-btn{appearance:none;border:1px solid var(--line2);background:transparent;color:var(--dim);padding:8px 14px;border-radius:999px;font:500 14px/1 var(--sans);cursor:pointer}
.gf-tabs button.on{background:var(--ink);color:#000;border-color:var(--ink)}
.st-tf::before{content:"Timeframe";align-self:center;margin-right:4px;color:var(--mute);font:600 11px/1 var(--mono);letter-spacing:.1em;text-transform:uppercase}
.st-tf button.on{background:var(--gold);border-color:var(--gold);color:#1a1300}.st-tf button.on:hover{color:#1a1300}
.gf-tabs button:hover,.gf-btn:hover{color:var(--ink);border-color:var(--ink)}.gf-tabs button.on:hover{color:#000}
.gf-q{flex:1;min-width:180px;max-width:300px;margin-left:auto;padding:9px 14px;border:1px solid var(--line2);border-radius:999px;background:var(--card);color:var(--ink);font:14px var(--sans)}
.gf-upd{color:var(--mute);font-size:13px;margin:0 0 10px}
.gf-prog{height:3px;border-radius:2px;background:rgba(255,255,255,.08);overflow:hidden;margin:12px 0 0}.gf-prog i{display:block;height:100%;background:var(--gold);width:0;transition:width .4s}

/* cards */
.gf-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.gc{position:relative;display:flex;flex-direction:column;padding:16px 18px 14px;border:1px solid var(--line);border-radius:16px;background:var(--card);min-width:0}
.gc.g-ap{border-color:rgba(233,196,106,.7);background:linear-gradient(180deg,rgba(233,196,106,.08),transparent 40%),var(--card);box-shadow:0 0 0 1px rgba(233,196,106,.15),0 10px 40px -18px rgba(233,196,106,.45)}
.gc.g-a{border-color:rgba(34,171,148,.55)}.gc.g-b{border-color:rgba(110,168,254,.45)}.gc.g-nr{border-color:rgba(247,82,95,.5)}
.gc-top{display:flex;gap:12px;align-items:flex-start}
.gc-grade{flex:none;display:grid;place-items:center;min-width:56px;height:56px;padding:0 8px;border-radius:14px;font:700 22px/1 var(--mono);border:1px solid var(--line2);color:var(--dim)}
.g-ap .gc-grade{background:linear-gradient(135deg,var(--gold2),var(--gold));color:#1a1300;border:0}
.g-a .gc-grade{background:var(--up);color:#00130f;border:0}.g-b .gc-grade{border-color:var(--blue);color:var(--blue)}
.g-rd .gc-grade{border-color:var(--gold);color:var(--gold);font-size:12px;text-align:center;line-height:1.15}
.g-nr .gc-grade{border-color:var(--dn);color:var(--dn);font-size:12px;text-align:center;line-height:1.15}.g-w .gc-grade{font-size:12px}
.gc-id{min-width:0;flex:1}
.gc-id h3{margin:0;font-size:18px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gc-id small{color:var(--mute);font:12px var(--mono)}
.gc-side{display:inline-block;margin-right:8px;padding:2px 9px;border-radius:6px;font:700 12.5px/1.5 var(--mono);vertical-align:2px}
.gc-side.CALL{background:var(--up);color:#00130f}.gc-side.PUT{background:var(--dn);color:#1c0003}
.gc-px{text-align:right;flex:none}.gc-px b{display:block;font:600 15px var(--mono)}.gc-px small{font:12px var(--mono)}
.up{color:var(--up)}.dn{color:var(--dn)}
.gc-setup{margin:10px 0 6px;font-weight:600;font-size:14.5px}
.gc-setup em{font-style:normal;color:var(--mute);font-weight:400}
.gc-lb{display:flex;flex-wrap:wrap;gap:5px;margin:0 0 8px}
.gc-lb i{font-style:normal;font-size:11.5px;line-height:1.5;padding:1px 8px;border-radius:999px;border:1px solid var(--line2);color:var(--dim);white-space:nowrap}
.gc-lb i.pz{border-color:var(--gold);color:var(--gold);background:rgba(233,196,106,.08)}
.gc-lb i.line,.gc-lb i.mtf{border-color:rgba(110,168,254,.55);color:var(--blue)}
.gc-lb i.mom,.gc-lb i.news,.gc-lb i.vol,.gc-lb i.ema,.gc-lb i.smc{border-color:rgba(34,171,148,.5);color:var(--up)}.gc-lb i.risk{border-color:var(--dn);color:var(--dn)}
.gc-say{margin:0 0 10px;color:var(--dim);font-size:14px;line-height:1.5}
.gc-chk{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:4px;margin:0 0 10px}
.gc-chk span{display:grid;place-items:center;height:26px;border-radius:7px;font:600 10.5px/1 var(--mono);letter-spacing:.04em;background:rgba(255,255,255,.05);color:var(--mute);cursor:help}
.gc-chk span.ok{background:rgba(34,171,148,.16);color:var(--up)}.gc-chk span.no{background:rgba(247,82,95,.08);color:#b86870}
.gc-plan{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px}
.gc-plan div{padding:7px 9px;border:1px solid var(--line);border-radius:10px;background:#0A0B0E;min-width:0}
.gc-plan span{display:block;color:var(--mute);font-size:11px}
.gc-plan b{font:600 13px var(--mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
.gc-plan .sl b{color:var(--dn)}.gc-plan .tg b{color:var(--up)}
.gc-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
.gc-mtf{display:flex;gap:3px;align-items:center;margin-right:auto;color:var(--mute);font:11px var(--mono)}
.gc-mtf i{display:inline-grid;place-items:center;width:30px;height:18px;border-radius:4px;font-style:normal;font-size:10px;background:rgba(255,255,255,.06)}
.gc-mtf i.u{background:rgba(34,171,148,.22);color:var(--up)}.gc-mtf i.d{background:rgba(247,82,95,.18);color:var(--dn)}
.gc-foot a,.gc-foot button{padding:7px 12px;border-radius:999px;border:1px solid var(--line2);font:500 13px/1 var(--sans);text-decoration:none;color:var(--ink);background:transparent;cursor:pointer}
.gc-foot a.pri{background:var(--gold);border-color:var(--gold);color:#1a1300;font-weight:600}
.gc-foot a:hover,.gc-foot button:hover{border-color:var(--ink)}
.gc-more{display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)}
.gc.open .gc-more{display:block}
.gc-more ul{list-style:none;margin:0;padding:0;display:grid;gap:7px}
.gc-more li{display:grid;grid-template-columns:20px 1fr;gap:8px;font-size:13.5px}
.gc-more li i{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;font:700 11px/1 var(--sans);font-style:normal;background:rgba(255,255,255,.07);color:var(--mute)}
.gc-more li.ok i{background:var(--up);color:#00130f}
.gc-more li b{font-weight:600}.gc-more li p{margin:1px 0 0;color:var(--dim);font-size:12.5px}
.gc-news{margin:10px 0 0;font-size:12.5px;color:var(--dim)}.gc-news a{color:var(--blue)}
.gf-empty{grid-column:1/-1;padding:30px 16px;border:1px dashed var(--line2);border-radius:16px;color:var(--dim);text-align:center}
.gf-more{display:block;margin:16px auto 0}
.gf-note{margin:16px 0 0;padding:12px 16px;border:1px solid rgba(233,196,106,.35);border-radius:12px;background:rgba(233,196,106,.06);color:var(--dim);font-size:14px}.gf-note b{color:var(--ink)}

/* learn */
.gf-learn{margin-top:34px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.gf-learn h2{grid-column:1/-1;margin:0;font-size:20px}
.gf-learn dl{margin:0;padding:16px;border:1px solid var(--line);border-radius:14px;background:var(--card)}
.gf-learn dt{font-weight:600;margin-top:10px}.gf-learn dt:first-child{margin-top:0}
.gf-learn dd{margin:2px 0 0;color:var(--dim);font-size:14px}
.gf-foot{margin-top:22px;color:var(--mute);font-size:13px}
.gf-ftr{max-width:1260px;margin:0 auto;padding:0 20px 40px}.gf-ftr nav{display:flex;flex-wrap:wrap;gap:8px 18px;padding-top:18px;border-top:1px solid var(--line)}.gf-ftr a{color:var(--mute);font-size:13px;text-decoration:none}.gf-ftr a:hover{color:var(--ink)}

@media (max-width:1020px){.gf-flow{grid-template-columns:repeat(2,1fr)}.gf-tiles{grid-template-columns:repeat(3,1fr)}.gf-list{grid-template-columns:1fr}.gf-learn{grid-template-columns:1fr}}
@media (max-width:560px){.gf{padding:16px 12px 50px}.gf-hero{padding:24px 18px}.gf-flow{grid-template-columns:1fr}.gf-tiles{grid-template-columns:repeat(2,1fr)}
  .gc-plan{grid-template-columns:repeat(3,minmax(0,1fr))}.gc-chk span{font-size:9px}.gf-q{max-width:none;margin-left:0;flex-basis:100%}}
@media (prefers-reduced-motion:reduce){.gf-eye i{animation:none}}
.gc.g-sq3{border-color:rgba(233,196,106,.7);box-shadow:0 0 0 1px rgba(233,196,106,.15),0 10px 40px -18px rgba(233,196,106,.45)}.gc.g-sq2{border-color:rgba(34,171,148,.55)}.gc.g-sq1{border-color:rgba(110,168,254,.45)}
.g-sq3 .gc-grade{background:linear-gradient(135deg,var(--gold2),var(--gold));color:#1a1300;border:0;font-size:12px;text-align:center;line-height:1.15}.g-sq2 .gc-grade{background:var(--up);color:#00130f;border:0;font-size:12px;text-align:center;line-height:1.15}.g-sq1 .gc-grade,.g-sq0 .gc-grade{font-size:12px;text-align:center;line-height:1.15}
.kv{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin:0 0 6px}.kv div{padding:7px 9px;border:1px solid var(--line);border-radius:10px;background:#0A0B0E}.kv span{display:block;color:var(--mute);font-size:11px}.kv b{font:600 13px var(--mono)}
.why{margin:0 0 8px;padding:0;list-style:none;display:grid;gap:3px;font-size:13px;color:var(--dim)}.why li::before{content:"✓ ";color:var(--up)}.why li.x::before{content:"✗ ";color:var(--dn)}
.st-tbl{width:100%;border-collapse:collapse;font-size:14px}.st-tbl td,.st-tbl th{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left}.st-tbl th{color:var(--mute);font-weight:500;font-size:12px}
@media (max-width:560px){.kv{grid-template-columns:repeat(2,minmax(0,1fr))}}
.gf-more[hidden]{display:none}
.st-pills{display:flex;flex-wrap:wrap;gap:6px;margin:16px 0 0}.st-pills a{padding:7px 12px;border-radius:999px;border:1px solid var(--line2);color:var(--dim);font-size:13px;text-decoration:none;white-space:nowrap}
.st-pills a:hover{color:var(--ink);border-color:var(--ink)}.st-pills a.on{background:var(--gold);border-color:var(--gold);color:#1a1300;font-weight:600}
.st-say{margin:0 0 8px;padding:9px 12px;border-radius:10px;background:rgba(255,255,255,.035);color:var(--dim);font-size:13.5px;line-height:1.5}.st-say b{color:var(--ink);font-weight:600}
.st-bar{height:6px;border-radius:3px;background:rgba(255,255,255,.08);overflow:hidden;margin:2px 0 10px}.st-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--gold),var(--gold2))}
.st-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;margin:18px 0 0}
.st-card{display:flex;flex-direction:column;gap:6px;padding:18px;border:1px solid var(--line);border-radius:16px;background:var(--card);text-decoration:none;transition:border-color .15s,transform .15s}
.st-card:hover{border-color:rgba(233,196,106,.6);transform:translateY(-2px)}.st-card small{color:var(--gold);font:600 11px/1 var(--mono);letter-spacing:.1em;text-transform:uppercase}
.st-card h3{margin:4px 0 2px;font-size:17px;line-height:1.3}.st-card p{margin:0;color:var(--dim);font-size:13.5px;line-height:1.5}.st-card span{margin-top:auto;padding-top:10px;color:var(--gold);font-weight:600;font-size:13.5px}
@media (max-width:760px){.gf-flow{grid-template-columns:1fr!important}.gf-tiles{grid-template-columns:repeat(2,minmax(0,1fr))!important}.gf-q{width:100%}.gf{overflow-x:clip}}
@media (prefers-reduced-motion:reduce){.st-card{transition:none}}
</style>
</head>
<body>
<?php }
function strat_pills(string $dir, string $id): void {
    $E = strat_easy(); echo '<nav class="st-pills" aria-label="Strategy scanners">';
    foreach (strat_all() as $s) echo '<a href="/' . $s['dir'] . '/scanner.php?id=' . strat_e($s['id']) . '"' . ($s['dir'] === $dir && $s['id'] === $id ? ' class="on" aria-current="page"' : '') . '>' . strat_e($E[$s['id']]['short'] ?? $s['cfg']['name']) . '</a>';
    echo '</nav>';
}
function strat_foot(): void { ?>
<footer class="gf-ftr"><nav><a href="/scanners/">All scanners</a><a href="/institutional/">Smart money scanners</a><a href="/ema-strategies/">EMA strategy scanners</a><a href="/scanners/godfather/">God-father Scanner</a><a href="/scanners/ema-200/">200 EMA Scanner</a><a href="/scanners/day-drive/">Day Drive</a><a href="/legal.php">Legal</a></nav></footer>
<script src="/static/v2/tg-join.js?v=1" defer></script>
</body>
</html>
<?php }

/** One strategy scanner. */
function strat_scanner(string $dir, array $SCANNERS, string $id): void {
    if (!isset($SCANNERS[$id])) $id = (string)array_key_first($SCANNERS);
    $c = $SCANNERS[$id]; $E = strat_easy()[$id] ?? ['short' => $c['name'], 'plain' => $c['desc'], 'steps' => []]; $grp = $dir === 'institutional' ? 'Smart money' : 'EMA strategy';
    strat_head($c['name'] . ' Scanner', $c['name'] . ' scanner on the daily chart, free. ' . $E['plain'], "https://chartpulse.io/$dir/scanner.php?id=$id"); ?>
<main class="gf">
  <section class="gf-hero">
    <div class="gf-eye"><i></i><?= strat_e($grp) ?> · <span id="stTfEye">daily chart</span></div>
    <h1><?= strat_e($c['name']) ?></h1>
    <p class="gf-lede"><?= strat_e($E['plain']) ?></p>
    <?php if ($E['steps']): ?><ol class="gf-flow" style="grid-template-columns:repeat(3,minmax(0,1fr))">
      <?php foreach ($E['steps'] as $i => $s): ?><li><span><?= $i + 1 ?> · <?= strat_e($s[0]) ?></span><b><?= strat_e($s[1]) ?></b><p><?= strat_e($s[2]) ?></p></li><?php endforeach; ?>
    </ol><?php endif; ?>
    <?php strat_pills($dir, $id); ?>
  </section>
  <div class="gf-tiles" id="stTiles" style="grid-template-columns:repeat(4,minmax(0,1fr))"></div>
  <div class="gf-bar">
    <?php $tfs = $dir === 'ema-strategies' ? ['1H' => '1 hour', '4H' => '4 hour', '1D' => 'Daily'] : ['15m' => '15 min', '1H' => '1 hour', '4H' => '4 hour', '1D' => 'Daily', '1W' => 'Weekly']; ?>
    <div class="gf-tabs st-tf" id="stTf" role="group" aria-label="Timeframe"><?php foreach ($tfs as $k => $v): ?><button type="button" data-v="<?= $k ?>"<?= $k === '1D' ? ' class="on"' : '' ?>><?= $v ?></button><?php endforeach; ?></div>
  </div>
  <?php if ($dir === 'ema-strategies'): ?><p class="gf-upd" style="margin:-4px 0 10px">15 min and weekly are not offered here: the 200 EMA needs more candles than we keep on those charts, so the result would not be reliable.</p><?php endif; ?>
  <div class="gf-bar" style="margin-top:0">
    <div class="gf-tabs" id="stSide" role="group" aria-label="Side"><button type="button" data-v="all" class="on">CALL + PUT</button><button type="button" data-v="CALL">CALL</button><button type="button" data-v="PUT">PUT</button></div>
    <div class="gf-tabs" id="stMkt" role="group" aria-label="Market"></div>
    <input class="gf-q" id="stQ" type="search" placeholder="Find a symbol…" aria-label="Find a symbol">
  </div>
  <p class="gf-upd" id="stUpd">Scanning…</p>
  <div class="gf-list" id="stList"><p class="gf-empty">Scanning…</p></div>
  <button type="button" class="gf-btn gf-more" id="stMore" hidden>Show more</button>
  <section class="gf-learn"><h2>How to read a card</h2>
    <dl><dt>CALL / PUT</dt><dd>CALL means the setup points up, PUT means it points down.</dd><dt>The number in the box</dt><dd>How cleanly the setup formed, out of 100. 85 or more is marked strong.</dd></dl>
    <dl><dt>Entry</dt><dd>The close of the last finished candle on the timeframe you picked, where the setup was confirmed.</dd><dt>Stop-loss</dt><dd>1.5 times the average candle range (ATR) of that timeframe against the trade. If price reaches it, the idea is wrong.</dd></dl>
    <dl><dt>Which timeframe?</dt><dd><b>15 min / 1 hour</b>: intraday trades, levels close together, many signals. <b>4 hour</b>: trades over a few days. <b>Daily / Weekly</b>: swing and position trades, wider stops, fewer signals.</dd><dt>Same strategy, every timeframe</dt><dd>The rules do not change, only the candle size. "10-day range" becomes "10-candle range" on smaller charts.</dd></dl>
    <dl><dt>Target</dt><dd>3 times the ATR in the trade direction, unless a nearer level is stated in the setup.</dd><dt>Best for</dt><dd><?= strat_e($c['for']) ?>. Built for the <?= strat_e(strtolower($c['tf'])) ?> chart; the other timeframes use the same rules.</dd></dl>
  </section>
  <p class="gf-note"><b>Not measured.</b> No win rate has been measured for this scanner, so none is claimed. It lists setups; it does not manage the trade. Educational, not financial advice.</p>
  <p class="gf-foot">For education, not financial advice.</p>
</main>
<script>
(() => {
"use strict";
const $ = s => document.querySelector(s), $$ = s => [...document.querySelectorAll(s)];
const ID = <?= json_encode($id) ?>, TFS = {"15m": "15 min", "1H": "1 hour", "4H": "4 hour", "1D": "Daily", "1W": "Weekly"};
const OK = $$("#stTf button").map(b => b.dataset.v);
let TF = (new URLSearchParams(location.search).get("tf") || "").toUpperCase().replace("15M", "15m"); if (!OK.includes(TF)) { try { TF = localStorage.getItem("cp_strat_tf") || "1D"; } catch (e) { TF = "1D"; } } if (!OK.includes(TF)) TF = "1D";
const API = () => "api/scan.php?id=" + encodeURIComponent(ID) + "&tf=" + encodeURIComponent(TF);
const BAR = () => ({"15m": "15-minute", "1H": "1-hour", "4H": "4-hour", "1D": "daily", "1W": "weekly"})[TF];
const words = s => TF === "1D" ? s : String(s || "").replace(/\s*\(daily\)/gi, "").replace(/(\d+)-day/g, "$1-candle").replace(/\bdaily\b/gi, BAR());
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const px = v => v == null || isNaN(v) ? "–" : Math.abs(v) >= 100 ? Number(v).toLocaleString("en-US", {maximumFractionDigits: 2}) : Math.abs(v) >= 1 ? Number(v).toFixed(Math.abs(v) >= 10 ? 2 : 4) : Number(v).toPrecision(4);
const nice = s => String(s).replace(/^\^/, "").replace(/(=X|=F|-USD)$/, "").replace(/\.[A-Z]{1,3}$/, "");
const F = {side: "all", mkt: "all", q: ""}; let D = null, shown = 24, timer = 0;
function card(r) { const call = r.bias === "CALL", sc = +r.score || 0, g = sc >= 85 ? "ap" : sc >= 75 ? "a" : "b", risk = Math.abs(r.entry - r.sl), rr = risk > 0 ? Math.abs(r.tp - r.entry) / risk : null;
  return `<article class="gc g-${g}"><div class="gc-top"><div class="gc-grade num" title="Setup quality out of 100">${sc}</div>
    <div class="gc-id"><h3><span class="gc-side ${call ? "CALL" : "PUT"}">${call ? "CALL ▲" : "PUT ▼"}</span>${esc(nice(r.symbol))}</h3><small>${esc(r.symbol)} · ${esc(r.market || "")} · ${TFS[TF].toLowerCase()}${sc >= 85 ? " · strong setup" : ""}</small></div>
    <div class="gc-px"><b>${px(r.price ?? r.entry)}</b><small class="${(r.change || 0) >= 0 ? "up" : "dn"}">${(r.change || 0) >= 0 ? "+" : ""}${esc(r.change ?? 0)}% ${TF === "1D" ? "today" : "last candle"}</small></div></div>
    <div class="gc-setup">${esc(words(r.setup))} <em>· ${esc(r.tag || "")}</em></div>
    <div class="st-bar" title="Setup quality ${sc} of 100"><i style="width:${Math.min(100, sc)}%"></i></div>
    <div class="kv"><div><span>Entry</span><b>${px(r.entry)}</b></div><div><span>Stop-loss</span><b class="dn">${px(r.sl)}</b></div><div><span>Target</span><b class="up">${px(r.tp)}</b></div><div><span>Reward for 1 risked</span><b>${rr ? rr.toFixed(1) : "–"}</b></div></div>
    <p class="st-say"><b>In simple words:</b> the setup points ${call ? "up" : "down"}. ${call ? "Buy" : "Sell"} idea near <b>${px(r.entry)}</b>, wrong if price ${call ? "falls" : "rises"} to <b>${px(r.sl)}</b>, aim for <b>${px(r.tp)}</b>.</p>
    <div class="gc-foot"><span class="gc-mtf"></span><a href="/desk/?s=${encodeURIComponent(r.symbol)}">AI Scan</a></div></article>`; }
function render() { if (!D) return; const R = D.data || [];
  $("#stTiles").innerHTML = [[R.length, "Setups now", "of " + (D.scanned || "–") + " scanned"], [R.filter(r => r.bias === "CALL").length, "CALL", "pointing up"], [R.filter(r => r.bias === "PUT").length, "PUT", "pointing down"], [R.filter(r => +r.score >= 85).length, "Strong", "quality 85 or more"]]
    .map(([v, l, s], i) => `<div class="gf-tile ${i === 3 ? "ap" : i === 0 ? "a" : ""}" style="cursor:default"><div class="v">${esc(v)}</div><div class="k"><b style="color:var(--ink)">${l}</b> · ${esc(s)}</div></div>`).join("");
  const mk = [...new Set(R.map(r => r.market).filter(Boolean))];
  $("#stMkt").innerHTML = mk.length > 1 ? ["all"].concat(mk).map(m => `<button type="button" data-v="${esc(m)}" class="${F.mkt === m ? "on" : ""}">${m === "all" ? "All markets" : esc(m)}</button>`).join("") : "";
  $$("#stMkt button").forEach(b => b.onclick = () => { F.mkt = b.dataset.v; shown = 24; render(); });
  const rows = R.filter(r => (F.side === "all" || r.bias === F.side) && (F.mkt === "all" || r.market === F.mkt) && (!F.q || (r.symbol + " " + (r.market || "")).toLowerCase().includes(F.q)));
  $("#stList").innerHTML = rows.slice(0, shown).map(card).join("") || `<p class="gf-empty">${R.length ? "Nothing matches these filters. Clear the search or pick All markets." : "No setup right now. This scanner refreshes on its own; clean setups do not form every day."}</p>`;
  const more = $("#stMore"); more.hidden = rows.length <= shown; more.textContent = `Show more (${rows.length - shown} left)`; }
function setTf(v, push) { TF = v; D = null; shown = 24; $$("#stTf button").forEach(x => x.classList.toggle("on", x.dataset.v === TF)); $("#stTfEye").textContent = TFS[TF].toLowerCase() + (TF === "1D" || TF === "1W" ? " chart" : " chart · intraday");
  try { localStorage.setItem("cp_strat_tf", TF); } catch (e) {} if (push) { const u = new URL(location.href); if (TF === "1D") u.searchParams.delete("tf"); else u.searchParams.set("tf", TF); history.replaceState(null, "", u); }
  $("#stTiles").innerHTML = ""; $("#stList").innerHTML = `<p class="gf-empty">Scanning the ${TFS[TF].toLowerCase()} chart…</p>`; }
$$("#stTf button").forEach(b => b.onclick = () => { if (b.dataset.v === TF) return; setTf(b.dataset.v, true); load(); });
setTf(TF, false);
$$("#stSide button").forEach(b => b.onclick = () => { F.side = b.dataset.v; shown = 24; $$("#stSide button").forEach(x => x.classList.toggle("on", x === b)); render(); });
$("#stQ").oninput = e => { F.q = e.target.value.trim().toLowerCase(); shown = 24; render(); };
$("#stMore").onclick = () => { shown += 24; render(); };
async function load() { clearTimeout(timer); let nx = TF === "15m" ? 60 : 120;
  const want = TF;
  try { const j = await fetch(API(), {cache: "no-store"}).then(r => r.json()); if (want !== TF) return;
    if (j.error) throw new Error(j.error);
    if (j.status === "updating") { $("#stUpd").textContent = `Building the ${TFS[TF].toLowerCase()} scan for every market. The first one takes up to two minutes…`; nx = 8; }
    else { D = j; $("#stUpd").textContent = `${(j.data || []).length} setups · ${j.scanned || "–"} symbols scanned · ${TFS[TF].toLowerCase()} chart · updated ${j.updated || ""} · refreshes on its own`; render(); }
  } catch (e) { $("#stUpd").textContent = "This scanner is temporarily unavailable. Sorry for the inconvenience. Retrying…"; if (!D) $("#stList").innerHTML = '<p class="gf-empty">This scanner is temporarily unavailable. Please check back in a little while.</p>'; nx = 30; }
  timer = setTimeout(load, (document.hidden ? 300 : nx) * 1000); }
load();
})();
</script>
<?php strat_foot(); }

/** Group page: /institutional/ or /ema-strategies/. */
function strat_index(string $dir, array $SCANNERS): void {
    $inst = $dir === 'institutional'; $E = strat_easy();
    $title = $inst ? 'Smart Money Scanners: Liquidity Sweep, FVG, Power of 3, Range Breakout' : 'EMA Strategy Scanners: EMA 200, 50, 21, Supertrend, VWAP';
    $desc = $inst ? 'Free smart-money scanners on the daily chart: liquidity sweep with fair value gap, SMC Power of 3 with OTE, and range breakout with VWAP and volume.' : 'Free EMA strategy scanners on the daily chart: EMA 200 + 50 + 21 alignment, EMA 200 with Supertrend and RSI, and VWAP with EMA 200.';
    strat_head($title, $desc, "https://chartpulse.io/$dir/"); ?>
<main class="gf">
  <section class="gf-hero">
    <div class="gf-eye"><i></i>Strategy scanners · daily chart</div>
    <h1><?= $inst ? 'Smart Money <span>Scanners</span>' : 'EMA Strategy <span>Scanners</span>' ?></h1>
    <p class="gf-lede"><?= $inst ? 'Three well-known smart-money setups, each scanned across every ChartPulse market every few minutes. Pick one to see today\'s CALL and PUT setups with entry, stop-loss and target.' : 'Three popular moving-average setups, each scanned across every ChartPulse market every few minutes. Pick one to see today\'s CALL and PUT setups with entry, stop-loss and target.' ?></p>
  </section>
  <div class="st-grid">
    <?php foreach ($SCANNERS as $id => $c): $x = $E[$id] ?? null; ?><a class="st-card" href="scanner.php?id=<?= strat_e($id) ?>"><small><?= strat_e($c['tag']) ?> · <?= strat_e($c['tf']) ?></small><h3><?= strat_e($c['name']) ?></h3><p><?= strat_e($x['plain'] ?? $c['desc']) ?></p><p style="color:var(--mute);font-size:12.5px">Best for: <?= strat_e($c['for']) ?></p><span>Open scanner →</span></a><?php endforeach; ?>
  </div>
  <section class="gf-learn" style="grid-template-columns:1fr"><h2><?= $inst ? 'Also see the EMA strategy scanners' : 'Also see the smart money scanners' ?></h2>
    <?php strat_pills($dir, ''); ?></section>
  <p class="gf-note"><b>Not measured.</b> No win rate has been measured for these scanners, so none is claimed. Educational, not financial advice.</p>
</main>
<?php strat_foot(); }
