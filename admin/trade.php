<?php
// ChartPulse · one Tier-2 trade of the research report, drawn like Tier 2 (admin only, owner 30 Sep 2026).
//   ?d=YYYY-MM-DD&id=<trade id>   Candles (5 min, IST), the Tier-1 signal, pattern candle, action (entry), stop-loss, T1/T2/T3,
//   when each target was hit, the exit, and the move in pips (forex) or points, with R and %.
declare(strict_types=1);
require dirname(__DIR__) . '/torus_engine.php';
require dirname(__DIR__) . '/torus_report.php';
header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin');

$pw = (string)t_cfg('admin_password', '');
$tok = $pw !== '' ? hash_hmac('sha256', 'cp-admin-v1', $pw) : '';
if ($tok === '' || !hash_equals($tok, (string)($_COOKIE['cp_admin'] ?? ''))) { header('Location: reports.php'); exit; }
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$d = (string)($_GET['d'] ?? ''); $id = preg_replace('/[^a-z0-9]/', '', (string)($_GET['id'] ?? ''));
$R = tr_valid($d) ? tr_load($d) : null; $x = null;
foreach ($R['trades'] ?? [] as $t) if ($t['id'] === $id) { $x = $t; break; }
if (!$x) { http_response_code(404); echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif">Trade not found. <a href="reports.php">Back to reports</a></p>'; exit; }

$now = t_now(); $sym = $x['symbol']; $te = (int)$x['entry_time'];
$md = t2_data_parse(t_fetch(['a' => t_url($sym, $now - $te > 4 * 86400 ? '1mo' : '5d', '5m')])['a'] ?? null, null);
$bars = []; $ema = []; $lv = []; $P = null; $dayTs = $te - 6 * 3600;
if ($md) {
    $f5 = $md['f5'];
    foreach (t2_sessions($f5, $md['tz']) as [$a, $b]) if ((int)$f5['t'][$a] <= $te && (int)$f5['t'][$b] + 300 > $te - 3600) { $dayTs = (int)$f5['t'][$a]; break; }
    $P = t_fib_pivots($f5, $dayTs);
    $E5 = t_ema($f5['c'], 5); $to = $te + TS_HOURS * 3600;
    foreach ($f5['t'] as $i => $t) { $t = (int)$t; if ($t < $dayTs || $t > $to) continue;
        $bars[] = ['time' => $t + 19800, 'open' => (float)$f5['o'][$i], 'high' => (float)$f5['h'][$i], 'low' => (float)$f5['l'][$i], 'close' => (float)$f5['c'][$i]];
        $ema[] = ['time' => $t + 19800, 'value' => round((float)$E5[$i], 8)]; }
}
$sg = $x['sig'] === 'CALL' ? 1 : -1; $pe = (float)$x['entry']; $sl = (float)$x['sl0']; $risk = abs($pe - $sl);
$st = $x['study'] ?? ($md && $P ? ts_trade($x, $md['f5'], $P, $now) : null);
[$unit, $pip] = ts_unit($sym, $pe);
$mv = fn(float $px) => ($px - $pe) * $sg;                                   // move in the trade's favour
$fmtMove = fn(float $m) => ($m >= 0 ? '+' : '−') . abs(ts_move($sym, $pe, $m)) . ' ' . $unit . ' · ' . ($m >= 0 ? '+' : '−') . abs(round($m / $pe * 100, 2)) . '% · ' . ($m >= 0 ? '+' : '−') . abs($risk > 0 ? round($m / $risk, 2) : 0) . 'R';
$bt = fn(?int $t) => $t === null ? null : $t - ($t % 300) + 19800;          // marker on its 5-minute candle, IST
$ev = [];   // [time, label, price, kind]
if (!empty($x['signal_time'])) $ev[] = [(int)$x['signal_time'], 'Tier-1 ' . $x['sig'] . ' signal', $x['signal_price'] ?? null, 'sig'];
if (!empty($x['pattern_bar']['t'])) $ev[] = [(int)$x['pattern_bar']['t'], '10m pattern: ' . ($x['pattern'] ?? ''), $x['pattern_bar']['c'] ?? null, 'pat'];
$ev[] = [$te, 'ACTION: entry', $pe, 'act'];
foreach (($st['lv'] ?? []) as $j => $l) { $tt = $l['t_sl'] ?: $l['t']; if ($tt) $ev[] = [(int)$tt, 'T' . ($j + 1) . ' ' . $l['k'] . ' reached' . ($l['t_sl'] ? '' : ' (after the stop-loss)'), $l['p'], 'tg'];
    $lv[] = ['T' . ($j + 1) . ' ' . $l['k'], (float)$l['p']]; }
if (!empty($st['sl_t'])) $ev[] = [(int)$st['sl_t'], 'Stop-loss hit', $sl, 'sl'];
if (!empty($x['exit_time'])) $ev[] = [(int)$x['exit_time'], 'Our exit: ' . ($x['reason'] ?? ''), $x['exit'], 'ex'];
if (!empty($st['best_sl_t'])) $ev[] = [(int)$st['best_sl_t'], 'Best move before any stop-loss', $pe + $sg * ($st['best_sl_r'] * $risk), 'best'];
usort($ev, fn($a, $b) => $a[0] <=> $b[0]);
$col = ['sig' => '#2962FF', 'pat' => '#b4b4bc', 'act' => '#F4B740', 'tg' => '#22AB94', 'sl' => '#F7525F', 'ex' => '#ececef', 'best' => '#22AB94'];
$up = $sg > 0; $inB = []; foreach ($bars as $b0) $inB[$b0['time']] = 1;
$markers = []; foreach ($ev as [$t, $lb, $px, $k]) { $mt = $bt($t); if (!isset($inB[$mt])) continue;
    $fav = in_array($k, ['tg', 'best'], true); $below = $k === 'act' ? $up : ($k === 'sl' ? $up : ($fav ? !$up : false));
    $markers[] = ['time' => $mt, 'position' => $below ? 'belowBar' : 'aboveBar', 'color' => $col[$k],
        'shape' => $k === 'act' ? ($up ? 'arrowUp' : 'arrowDown') : ($k === 'sl' ? 'square' : 'circle'),
        'text' => ['act' => 'ACTION', 'tg' => explode(' ', $lb)[0], 'sl' => 'SL', 'ex' => 'EXIT', 'sig' => 'Signal', 'pat' => 'Pattern', 'best' => 'Best'][$k]]; }
usort($markers, fn($a, $b) => $a['time'] <=> $b['time']);
$lines = array_merge([['Entry', $pe, '#F4B740', 0], ['Stop-loss', $sl, '#F7525F', 0]], array_map(fn($l) => [$l[0], $l[1], '#22AB94', 2], $lv));
$eg = $unit === 'pips' ? (str_contains($sym, 'JPY') || $pe > 20 ? '0.01' : '0.0001') : null;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $e($x['name']) ?> · trade chart · ChartPulse</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<style>
:root{--bg:#09090b;--panel:#111114;--panel2:#16161a;--line:rgba(255,255,255,.07);--line2:rgba(255,255,255,.12);--ink:#ececef;--ink2:#b4b4bc;--muted:#80808a;--call:#22AB94;--put:#F7525F;--gold:#F4B740;color-scheme:dark}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 "IBM Plex Sans",system-ui,sans-serif;padding:20px 16px 50px}
.wrap{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:16px}a{color:var(--gold)}.mono,td{font-family:"IBM Plex Mono",monospace}
.eyebrow{font:500 11px "IBM Plex Mono",monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--gold)}h1{font-size:24px;margin:4px 0 2px;font-weight:600}
.sub{color:var(--ink2);font-size:13px}.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
#chart{height:520px;border-radius:12px;overflow:hidden;border:1px solid var(--line)}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.tiles div{background:var(--panel);padding:10px 12px}.tiles span{display:block;font-size:11.5px;color:var(--muted)}.tiles b{font:600 16px "IBM Plex Mono",monospace}
table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}th{font:500 10.5px "IBM Plex Mono",monospace;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.tw{overflow-x:auto}.up{color:var(--call)}.down{color:var(--put)}.gold{color:var(--gold)}.dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px}
.note{font-size:13px;color:var(--ink2);line-height:1.6}.note b{color:var(--ink)}
@media (max-width:700px){#chart{height:380px}}
</style></head><body><div class="wrap">
<div><a href="reports.php?d=<?= $e($d) ?>">← Back to the report of <?= $e((new DateTime($d))->format('d M Y')) ?></a></div>
<header><div class="eyebrow">Tier-2 research · trade chart</div>
  <h1><?= $e($x['name']) ?> <span class="<?= $sg > 0 ? 'up' : 'down' ?>"><?= $e($x['sig']) ?></span></h1>
  <div class="sub"><?= $e($sym) ?> · <?= $e(t_tv_code($sym)) ?> · <?= $e($x['market']) ?> · <?= $e(tr_setup($x)) ?> · <?= $e($x['pattern'] ?? '') ?> · entry <?= $e(t_ist($te, 'D d M, H:i')) ?> IST · candles: 5 minutes, IST</div></header>
<div class="tiles">
  <div><span>Action (entry)</span><b class="gold"><?= $e(t_fp($pe)) ?></b></div>
  <div><span>Stop-loss</span><b class="down"><?= $e(t_fp($sl)) ?></b><span><?= $e($fmtMove(-$risk)) ?></span></div>
  <?php foreach (($st['lv'] ?? []) as $j => $l): ?><div><span>T<?= $j + 1 ?> · <?= $e($l['k']) ?></span><b class="up"><?= $e(t_fp((float)$l['p'])) ?></b><span><?= $e('+' . $l['move'] . ' ' . $unit . ' · +' . $l['r'] . 'R') ?> · <?= $l['t_sl'] ? '✓ ' . $e(t_ist((int)$l['t_sl'], 'd M H:i')) : ($l['t'] ? 'after SL' : '✗ not reached') ?></span></div><?php endforeach; ?>
  <div><span>Our exit</span><b class="<?= ($x['r'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $x['exit'] !== null ? $e(t_fp((float)$x['exit'])) : 'open' ?></b><span><?= $x['exit'] !== null ? $e($fmtMove($mv((float)$x['exit'])) . ' · ' . ($x['reason'] ?? '')) : '' ?></span></div>
  <?php if ($st): ?><div><span>Best move before SL (<?= $e($st['hours']) ?> h)</span><b class="up">+<?= $e($st['best_sl_r']) ?>R</b><span><?= $e('+' . $st['best_sl_move'] . ' ' . $unit) ?></span></div><?php endif; ?>
</div>
<div id="chart"><?php if (!$bars): ?><p class="card">Price data for this trade is no longer available from the data provider.</p><?php endif; ?></div>
<section class="card"><div class="tw"><table><thead><tr><th>Time (IST)</th><th>What happened</th><th>Price</th><th>Move from entry</th></tr></thead><tbody>
<?php foreach ($ev as [$t, $lb, $px, $k]): ?><tr><td><?= $e(t_ist((int)$t, 'd M H:i')) ?></td><td><span class="dot" style="background:<?= $col[$k] ?>"></span><?= $e($lb) ?></td><td><?= $px !== null ? $e(t_fp((float)$px)) : '–' ?></td><td class="<?= $px !== null && $mv((float)$px) >= 0 ? 'up' : 'down' ?>"><?= $px !== null && $k !== 'sig' && $k !== 'pat' ? $e($fmtMove($mv((float)$px))) : '' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="card note"><?php if ($unit === 'pips'): ?>
  <b>Pips:</b> for this pair 1 pip = <b><?= $eg ?></b> of price. A move from <?= $e(t_fp($pe)) ?> to <?= $e(t_fp($pe + $sg * 10 * (float)$eg)) ?> is <b>10 pips</b>. So the stop-loss here is <?= $e(abs(ts_move($sym, $pe, $risk))) ?> pips away, and 1R = that distance.
<?php else: ?>
  <b>Points:</b> for stocks, indices, crypto and commodities the move is shown in <b>points = price units</b> (<?= $e($sym) ?> moving from <?= $e(t_fp($pe)) ?> to <?= $e(t_fp($pe + $sg * 1)) ?> is 1 point), with % beside it. The stop-loss here is <?= $e(t_r($risk)) ?> points away, and 1R = that distance.
<?php endif; ?> R compares every move with the risk taken: +2R means the move was twice the stop-loss distance. "Before SL" means reached without the stop-loss being hit first; T1 is the trade's own minimum target, T2 and T3 the next pivots. The yellow line is the 5-minute 5-EMA used for the exit.</section>
</div>
<script src="https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js"></script>
<script>
(() => {
  const bars = <?= json_encode($bars) ?>, ema = <?= json_encode($ema) ?>, markers = <?= json_encode($markers) ?>, lines = <?= json_encode($lines) ?>;
  const box = document.getElementById('chart'); if (!bars.length || !window.LightweightCharts) return;
  const ch = LightweightCharts.createChart(box, { layout: { background: { color: '#0d0d10' }, textColor: '#b4b4bc', fontFamily: 'IBM Plex Mono' },
    grid: { vertLines: { color: 'rgba(255,255,255,.04)' }, horzLines: { color: 'rgba(255,255,255,.04)' } },
    timeScale: { timeVisible: true, secondsVisible: false, borderColor: 'rgba(255,255,255,.1)' }, rightPriceScale: { borderColor: 'rgba(255,255,255,.1)' },
    crosshair: { mode: 0 } });
  const s = ch.addCandlestickSeries({ upColor: '#22AB94', downColor: '#F7525F', borderVisible: false, wickUpColor: '#22AB94', wickDownColor: '#F7525F' });
  s.setData(bars); s.setMarkers(markers);
  lines.forEach(([t, p, c, st]) => s.createPriceLine({ price: p, color: c, lineWidth: 1, lineStyle: st, axisLabelVisible: true, title: t }));
  const e = ch.addLineSeries({ color: 'rgba(244,183,64,.7)', lineWidth: 1, priceLineVisible: false, lastValueVisible: false }); e.setData(ema);
  ch.timeScale().fitContent();
  new ResizeObserver(() => ch.applyOptions({ width: box.clientWidth, height: box.clientHeight })).observe(box);
})();
</script>
</body></html>
