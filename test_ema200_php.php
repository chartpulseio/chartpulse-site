<?php
/** Self-check for ema200_engine.php. Run: php test_ema200_php.php  (no network, no torus_engine) */
require_once __DIR__ . '/ema200_engine.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $what) {
    global $pass, $fail;
    if ($c) { $pass++; } else { $fail++; echo "FAIL: $what\n"; }
}

/** Daily frame from a close series; h/l give a ~1.0 true range so ATR is sane. */
function mk(array $closes, float $spread = 0.5): array {
    $f = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    $t = strtotime('2024-01-01 00:00:00 UTC');
    foreach ($closes as $i => $c) {
        $f['t'][] = $t + $i * 86400;
        $f['o'][] = $c; $f['h'][] = $c + $spread; $f['l'][] = $c - $spread;
        $f['c'][] = $c; $f['v'][] = 1000.0;
    }
    return $f;
}

// ---- indicators -----------------------------------------------------------
$e = e2_ewm(array_fill(0, 300, 100.0), 200);
ok(abs(end($e) - 100.0) < 1e-9, 'ewm of a constant series is the constant');

$e2 = e2_ewm([0.0, 1.0], 10);
ok(abs($e2[1] - (2 / 11)) < 1e-12, 'ewm uses alpha=2/(span+1), adjust=False, seeded at x[0]');

$atr = e2_atr(mk(array_fill(0, 100, 50.0)), 14);
ok(abs(end($atr) - 1.0) < 1e-6, 'ATR of a flat 1.0-range series is 1.0');

ok(e2_pivots([1, 2, 3, 2, 1], 2, 'high') === [2], 'pivot high found at the unique extreme');
ok(e2_pivots([3, 2, 1, 2, 3], 2, 'low') === [2], 'pivot low found at the unique extreme');
ok(e2_pivots([1, 2, 3, 3, 1], 2, 'high') === [], 'tied extremes are not pivots');

$rm = e2_roll_mean_prev([1, 2, 3, 4, 5], 2);
ok($rm[0] === null && $rm[1] === null, 'rolling mean is null until the window is filled');
ok(abs($rm[2] - 1.5) < 1e-9 && abs($rm[4] - 3.5) < 1e-9, 'rolling mean uses the PREVIOUS window');

$wc = e2_weekly_close(mk(range(1, 21)));
ok($wc === [7.0, 14.0, 21.0], 'weekly closes: 21 bars from a Monday -> 3 ISO weeks, Friday-to-Sunday close kept');

// ---- not enough history ---------------------------------------------------
ok(e2_scan(mk(array_fill(0, 259, 100.0))) === null, 'fewer than 260 bars is skipped (null)');

// ---- no signal: flat market sitting on its own EMA ------------------------
$flat = e2_scan(mk(array_fill(0, 300, 100.0)));
ok($flat !== null && $flat['state'] === 'NONE', 'flat market produces no stage');

// ---- stage 2: fresh cross up ---------------------------------------------
$c = array_merge(
    array_fill(0, 230, 100.0),   // EMA converges to 100
    array_fill(0, 30, 90.0),     // decisively below -> state -1
    [104.0, 108.0, 112.0, 115.0] // rally back through the EMA
);
$r = e2_scan(mk($c));
ok($r !== null && $r['stage'] === 2, 'rally through the 200 EMA is CONFIRMED (stage 2)');
ok($r['state'] === 'FRESH_CROSS_UP' && $r['side'] === 'UP', 'fresh cross is labelled UP');
ok($r['bars_since_cross'] !== null && $r['bars_since_cross'] <= E2_LOOKBACK, 'cross is inside the 5-bar window');
ok($r['dist_pct'] > 0, 'price above the EMA gives a positive distance');

// ---- stage 1: early, EMA_CLOSE trigger -----------------------------------
// Build the series, read the EMA the engine would see, then close just ABOVE
// the raw EMA but still inside the 0.25-ATR buffer, so the side stays -1.
$base = array_merge(array_fill(0, 240, 100.0), array_fill(0, 24, 98.0));
$emaB = e2_ewm($base, 200);
$last = end($emaB) + 0.05;                       // above the EMA, inside the buffer
$early = e2_scan(mk(array_merge($base, [$last])));
ok($early !== null && $early['stage'] === 1, 'close back across the EMA inside the buffer is EARLY (stage 1)');
ok(in_array('EMA_CLOSE', $early['triggers'], true), 'EMA_CLOSE trigger is reported');
ok($early['state'] === 'EARLY_UP', 'early long is labelled EARLY_UP');

// ---- row shape and strength ----------------------------------------------
$row = e2_row('AAPL', 'stocks', $r, 115.0, 'NASDAQ:AAPL');
ok($row['tag'] === 'Entry from 200 EMA', 'every row carries the 200 EMA badge');
ok($row['entry_type'] === 'confirmed' && $row['is_entry'] === true, 'stage 2 maps to confirmed and is an entry');
ok($row['signal'] === 'CALL' && $row['side'] === 'LONG', 'UP maps to LONG / CALL');
ok($row['strength'] === min(95, 50 + 15 * $row['score']), 'strength = stage base + 15 per filter, capped 95');

$watchRow = e2_row('X', 'crypto', ['state' => 'WATCH_DOWN', 'stage' => 0, 'score' => 3, 'triggers' => [],
    'dist_pct' => -1.0, 'ema200' => 10.0, 'bars_since_cross' => null], 9.9);
ok($watchRow['is_entry'] === false, 'WATCH is not an entry');
ok($watchRow['strength'] === 65, 'watch base 20 + 3 filters = 65');
ok($watchRow['side'] === 'SHORT' && $watchRow['signal'] === 'PUT', 'DOWN maps to SHORT / PUT');

ok(json_encode($row) !== false, 'row survives JSON encoding');
foreach ($row as $k => $v) ok(!is_float($v) || is_finite($v), "row field $k is finite");

echo $fail === 0 ? "OK — $pass checks passed\n" : "\n$fail FAILED, $pass passed\n";
exit($fail === 0 ? 0 : 1);
