<?php
ini_set('display_errors', '1'); error_reporting(E_ALL);
set_exception_handler(function($e){ header('Content-Type: application/json'); echo json_encode(['fatal'=>$e->getMessage()]); exit; });
require __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_tier2.php';
header('Content-Type: application/json');
$sym = $_GET['s'] ?? 'AUDCAD=X';
$now = t_now();
$md = t2_data($sym);
$f5 = $md['f5']; $f5 = t2_closed($f5, 0, $now);
$n = count($f5['t']);

$stPeriod = 7; $stMult = 2.0;
$stEnd = $n - 2;
$tr = [];
for ($i = 1; $i <= $stEnd; $i++) {
    $h = (float)$f5['h'][$i]; $l = (float)$f5['l'][$i]; $pc = (float)$f5['c'][$i - 1];
    $tr[] = max($h - $l, abs($h - $pc), abs($l - $pc));
}
$atr = array_sum(array_slice($tr, 0, $stPeriod)) / $stPeriod;
$atrArr = [$atr];
for ($i = $stPeriod; $i < count($tr); $i++) { $atr = ($atr * ($stPeriod - 1) + $tr[$i]) / $stPeriod; $atrArr[] = $atr; }
$stDir = 1; $stLine = 0.0; $prevUpper = 0.0; $prevLower = 0.0;
$log = [];
for ($i = 0; $i < count($atrArr); $i++) {
    $ci = $i + 1;
    $hl2 = ((float)$f5['h'][$ci] + (float)$f5['l'][$ci]) / 2;
    $upper = $hl2 + $stMult * $atrArr[$i]; $lower = $hl2 - $stMult * $atrArr[$i];
    if ($i > 0) {
        if ($prevLower > 0 && $lower < $prevLower && (float)$f5['c'][$ci - 1] > $prevLower) $lower = $prevLower;
        if ($prevUpper > 0 && $upper > $prevUpper && (float)$f5['c'][$ci - 1] < $prevUpper) $upper = $prevUpper;
    }
    $c = (float)$f5['c'][$ci];
    $flipped = false;
    if ($stDir === 1) { $stLine = $lower; if ($c < $lower) { $stDir = -1; $stLine = $upper; $flipped = true; } }
    else { $stLine = $upper; if ($c > $upper) { $stDir = 1; $stLine = $lower; $flipped = true; } }
    $prevUpper = $upper; $prevLower = $lower;
    $log[] = ['i' => $ci, 't' => t_ist((int)$f5['t'][$ci], 'd-M H:i'), 'c' => round($c,5), 'upper' => round($upper,5), 'lower' => round($lower,5), 'dir' => $stDir, 'flip' => $flipped];
}
echo json_encode([
    'total_bars' => $n, 'first_t' => t_ist((int)$f5['t'][0], 'd-M H:i'), 'last_confirmed_t' => t_ist((int)$f5['t'][$stEnd], 'd-M H:i'),
    'final_dir' => $stDir, 'tail' => array_slice($log, -20),
], JSON_PRETTY_PRINT);
