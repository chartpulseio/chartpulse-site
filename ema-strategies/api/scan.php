<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../includes/config.php';
$id = (string)($_GET['id'] ?? '');
if (!isset($SCANNERS[$id])) { http_response_code(400); echo json_encode(['error' => 'Unknown scanner.']); exit; }
try {
    require_once __DIR__ . '/../includes/strategies.php';
    echo json_encode(ta_get(dirname(__DIR__) . '/cache', scan_map(), SCAN_MIN_BARS, $id));
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'This scanner is temporarily unavailable. Sorry for the inconvenience. Please check back in a little while.']);
}
