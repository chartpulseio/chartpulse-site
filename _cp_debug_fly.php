<?php
// TEMPORARY debug script — deleted right after use.
ini_set('display_errors', '1'); error_reporting(E_ALL);
set_exception_handler(function (Throwable $e) { header('Content-Type: application/json'); http_response_code(500); echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()]); exit; });
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { header('Content-Type: application/json'); echo json_encode(['fatal' => $e['message'] . ' @' . basename((string)$e['file']) . ':' . $e['line']]); } });
require __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_tier2.php';
header('Content-Type: application/json');

$sym = $_GET['s'] ?? 'MA';
$sig = $_GET['sig'] ?? 'CALL';   // which direction to test t_reversal in
$sg = $sig === 'CALL' ? 1 : -1;
$now = t_now();

$md = t2_data($sym);
if (!$md) { echo json_encode(['error' => 'no data']); exit; }
['f5' => $f5, 'f1' => $f1, 'tz' => $tz] = $md;
$f5 = t2_closed($f5, 0, $now);
$n = count($f5['t']);

// Build a 5m-bar array shaped like t_reversal expects ($G with t/o/h/l/c) and run t_reversal on each of the
// last 30 bars, reporting which index (if any) returns a pattern, same call the engine itself makes.
$G = ['t' => $f5['t'], 'o' => $f5['o'], 'h' => $f5['h'], 'l' => $f5['l'], 'c' => $f5['c']];
$hits = [];
for ($i = max(4, $n - 30); $i < $n; $i++) {
    $pat = t_reversal($G, $i, $sg);
    if ($pat !== null) {
        $next = $i + 1 < $n ? ['t' => $f5['t'][$i + 1], 'o' => $f5['o'][$i + 1], 'h' => $f5['h'][$i + 1], 'l' => $f5['l'][$i + 1], 'c' => $f5['c'][$i + 1]] : null;
        $confirmed = false;
        if ($next) {
            $nc = (float)$next['c'];
            $confirmed = $sg > 0 ? ($nc > (float)$f5['c'][$i] && $nc > (float)$next['o']) : ($nc < (float)$f5['c'][$i] && $nc < (float)$next['o']);
        }
        $hits[] = ['i' => $i, 'pattern' => $pat, 'candle' => ['t' => $f5['t'][$i], 'o' => $f5['o'][$i], 'h' => $f5['h'][$i], 'l' => $f5['l'][$i], 'c' => $f5['c'][$i]],
            'next_candle' => $next, 'confirmed_next_candle' => $confirmed];
    }
}

// last 15 raw 5m candles for manual eyeballing
$last = [];
for ($i = max(0, $n - 15); $i < $n; $i++) $last[] = ['i' => $i, 't' => $f5['t'][$i], 'o' => $f5['o'][$i], 'h' => $f5['h'][$i], 'l' => $f5['l'][$i], 'c' => $f5['c'][$i]];

echo json_encode(['symbol' => $sym, 'sig_tested' => $sig, 'now' => $now, 'last_bar_t' => $f5['t'][$n - 1], 'pattern_hits' => $hits, 'last_15_5m' => $last], JSON_PRETTY_PRINT);
