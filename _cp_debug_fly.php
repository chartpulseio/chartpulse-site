<?php
ini_set('display_errors', '1'); error_reporting(E_ALL);
set_exception_handler(function($e){ header('Content-Type: application/json'); echo json_encode(['fatal'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]); exit; });
register_shutdown_function(function(){ $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) { header('Content-Type: application/json'); echo json_encode(['shutdown_fatal'=>$e]); } });
require __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_tier2.php';
header('Content-Type: application/json');
$sym = $_GET['s'] ?? 'SPOT';
$now = t_now();
$row = t_scan_load()['rows'][$sym] ?? null;
$fr = t_load_frames($sym);
$t1 = $fr ? t_apply_status($sym, t_evaluate($sym, $fr), $row, $now) : null;
$f5 = $fr['5m'] ?? null;
$out = [
    'now' => $now,
    'now_ist' => t_ist($now, 'Y-m-d H:i:s'),
    'signal' => $t1['signal'] ?? null,
    'setup' => $t1['setup'] ?? null,
    'flow' => $t1['flow'] ?? null,
    'flow_at' => isset($t1['flow_at']) ? t_ist((int)$t1['flow_at'], 'H:i:s') : null,
    'tk_raw' => $t1['tk'] ?? null,
    'tk_times' => null,
    'v10' => $t1['v10'] ?? null,
    'pw' => $t1['pw'] ?? null,
];
if (is_array($t1['tk'] ?? null)) {
    $out['tk_times'] = array_map(fn($x) => $x ? t_ist((int)$x, 'H:i:s') : null, $t1['tk']);
}
// last few closed 5m candles for context
if ($f5) {
    $n = count($f5['t']);
    $last = [];
    for ($i = max(0, $n - 8); $i < $n; $i++) {
        $last[] = ['t' => t_ist((int)$f5['t'][$i], 'H:i:s'), 'o' => $f5['o'][$i], 'h' => $f5['h'][$i], 'l' => $f5['l'][$i], 'c' => $f5['c'][$i]];
    }
    $out['last_5m'] = $last;
}
echo json_encode($out, JSON_PRETTY_PRINT);
