<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../includes/config.php';
if (isset($_GET['full'])) {   // God-father feed: every signal, on 1D / 1H / 15m bars
    ignore_user_abort(true);
    try {
        require_once __DIR__ . '/../includes/strategies.php';
        $tf = in_array($_GET['full'], ['1D', '1H', '15m'], true) ? $_GET['full'] : '1D';
        $r = ta_get_full(dirname(__DIR__) . '/cache', scan_map(), SCAN_MIN_BARS, array_keys($SCANNERS), $tf);
        echo json_encode(['at' => $r['at'] ?? null, 'tf' => $tf, 'scanned' => $r['scanned'] ?? 0, 'counts' => array_map('count', $r['sig'] ?? [])]);
    } catch (Throwable $e) { http_response_code(503); echo json_encode(['error' => 'Temporarily unavailable.']); }
    exit;
}
$id = (string)($_GET['id'] ?? '');
if (!isset($SCANNERS[$id])) { http_response_code(400); echo json_encode(['error' => 'Unknown scanner.']); exit; }
try {
    require_once __DIR__ . '/../includes/strategies.php';
    echo json_encode(ta_get(dirname(__DIR__) . '/cache', scan_map(), SCAN_MIN_BARS, $id));
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'This scanner is temporarily unavailable. Sorry for the inconvenience. Please check back in a little while.']);
}
