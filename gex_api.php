<?php
// GET /gex_api.php?s=SPY&m=all|week|0dte   |  ?r=list  |  ?r=board (all symbols, light)
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/gex_engine.php';

$r = $_GET['r'] ?? '';
try {
    if ($r === 'list') {
        echo json_encode(['ok' => true, 'US' => GEX_US, 'IN' => array_keys(GEX_IN), 'CR' => GEX_CR]); exit;
    }
    if ($r === 'board') {
        $out = [];
        foreach (array_merge(['SPY', 'QQQ', 'IWM'], array_keys(GEX_IN), GEX_CR) as $s) {
            $g = gex_get_symbol($s, 'all');
            if (!empty($g['ok'])) $out[] = array_intersect_key($g, array_flip(['symbol', 'market', 'spot', 'net_gex', 'zero_gamma', 'call_wall', 'put_wall', 'regime', 'expected_move', 'pcr_oi', 'ts']));
        }
        echo json_encode(['ok' => true, 'rows' => $out]); exit;
    }
    $s = preg_replace('/[^A-Z]/', '', strtoupper((string)($_GET['s'] ?? 'SPY')));
    $m = in_array($_GET['m'] ?? 'all', ['all', 'week', '0dte'], true) ? $_GET['m'] ?? 'all' : 'all';
    $g = gex_get_symbol($s, $m);
    if (empty($g['ok'])) { http_response_code(503); echo json_encode(['ok' => false, 'msg' => 'Data is temporarily unavailable. Sorry for the inconvenience.']); exit; }
    echo json_encode($g);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => 'Data is temporarily unavailable. Sorry for the inconvenience.']);
}
