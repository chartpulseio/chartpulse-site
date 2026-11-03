<?php
// TEMPORARY debug script — deleted right after use.
ini_set('display_errors', '1'); error_reporting(E_ALL);
set_exception_handler(function (Throwable $e) { header('Content-Type: application/json'); http_response_code(500); echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()]); exit; });
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { header('Content-Type: application/json'); echo json_encode(['fatal' => $e['message'] . ' @' . basename((string)$e['file']) . ':' . $e['line']]); } });
require __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_tier2.php';
header('Content-Type: application/json');

$sym = $_GET['s'] ?? 'DAL';
$bias = $_GET['bias'] ?? 'CALL';
$d = t2_build($sym, $bias);
$out = ['status' => $d['status'] ?? null, 'ph' => $d['ph'] ?? null, 'flow' => $d['flow'] ?? null,
    'trade' => $d['trade'] ?? null, 'trades' => $d['trades'] ?? null, 'pending' => $d['pending'] ?? null,
    'zone' => $d['zone'] ?? null, 'last_bar' => $d['last_bar'] ?? null];
echo json_encode($out, JSON_PRETTY_PRINT);
