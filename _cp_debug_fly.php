<?php
ini_set('display_errors', '1'); error_reporting(E_ALL);
set_exception_handler(function($e){ header('Content-Type: application/json'); echo json_encode(['fatal'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]); exit; });
register_shutdown_function(function(){ $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) { header('Content-Type: application/json'); echo json_encode(['shutdown_fatal'=>$e]); } });
require __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_tier2.php';
header('Content-Type: application/json');
$sym = $_GET['s'] ?? 'DAL';
$now = t_now();
$row = t_scan_load()['rows'][$sym] ?? null;
$fr = t_load_frames($sym);
$t1 = $fr ? t_apply_status($sym, t_evaluate($sym, $fr), $row, $now) : null;
echo json_encode([
    'ok' => $t1 !== null,
    'signal' => $t1['signal'] ?? null,
    'v10' => $t1['v10'] ?? null,
    'strength' => $t1['strength'] ?? null,
    'parts_v10' => $t1['components']['v10'] ?? null,
    'parts_rt' => $t1['components']['rt'] ?? null,
    'mzp' => $t1['mzp'] ?? null, 'mzl' => $t1['mzl'] ?? null,
], JSON_PRETTY_PRINT);
