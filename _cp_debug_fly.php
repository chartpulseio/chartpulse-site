<?php
// TEMPORARY debug script — deleted right after use. Dumps the exact fly/tick trace for one symbol.
require __DIR__ . '/torus_engine.php';
require __DIR__ . '/torus_v10.php';
require __DIR__ . '/torus_tier2.php';
header('Content-Type: application/json');

$sym = $_GET['s'] ?? 'DAL';
$now = t_now();
$md = t2_data($sym);
if (!$md) { echo json_encode(['error' => 'no data']); exit; }
['f5' => $f5, 'f1' => $f1, 'tz' => $tz] = $md;
$f5 = t2_closed($f5, 0, $now);
$ses = t2_sessions($f5, $tz);
[$a, $b] = $ses[count($ses) - 1];

$row = t_scan_load()['rows'][$sym] ?? null;
$fr = t_load_frames($sym);
$t1 = $fr ? t_apply_status($sym, t_evaluate($sym, array_merge($fr, ['5m' => $f5, '1m' => $f1])), $row, $now) : null;

$sg = ($t1['signal'] ?? '') === 'CALL' ? 1 : (($t1['signal'] ?? '') === 'PUT' ? -1 : 0);
$zb = T2_ZB;
$A5 = t_atr($f5, 14);
$M30 = null; // not needed for fly dump
$flyOut = $sg !== 0 ? t_fly_events($f5, $a, $b, $sg, $f1, $zb, $now) : ['events' => [], 'pending' => null];

// also dump the last 12 closed 5m candles for manual cross-check
$n = count($f5['t']);
$last5m = [];
for ($i = max(0, $n - 20); $i < $n; $i++) {
    $last5m[] = ['t' => $f5['t'][$i], 'o' => $f5['o'][$i], 'h' => $f5['h'][$i], 'l' => $f5['l'][$i], 'c' => $f5['c'][$i]];
}

echo json_encode([
    'symbol' => $sym, 'now' => $now, 'sg' => $sg, 'signal' => $t1['signal'] ?? null,
    'row_tk' => $row['tk'] ?? null, 't1_tk' => $t1['tk'] ?? null, 't1_flow' => $t1['flow'] ?? null,
    'fly_events' => $flyOut['events'], 'fly_pending' => $flyOut['pending'],
    'last_5m' => $last5m,
], JSON_PRETTY_PRINT);
