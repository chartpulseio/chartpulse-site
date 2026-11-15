<?php
/** Crypto Order Flow endpoint. GET of_api.php?r=board  |  of_api.php?r=sym&s=BTCUSDT&tf=15m */
declare(strict_types=1);
require __DIR__ . '/of_engine.php';
function of_out($d, int $code = 200): void {
    if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); }
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}
set_exception_handler(function (Throwable $e) {
    @file_put_contents(T_DATA . '/errors.log', gmdate('Y-m-d H:i:s') . ' [of] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
    of_out(['error' => 'Order flow is temporarily unavailable. Sorry for the inconvenience.'], 503);
});
if (!headers_sent() && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
if (!t_rl('of', 60)) { header('Retry-After: 20'); of_out(['error' => 'Too many requests. Please wait a few seconds.'], 429); }
@set_time_limit(60); @ini_set('memory_limit', '256M');
$r = (string)($_GET['r'] ?? 'board');
$d = $r === 'sym' ? of_symbol(strtoupper((string)($_GET['s'] ?? 'BTCUSDT')), (string)($_GET['tf'] ?? '15m')) : of_board();
if (isset($d['error'])) of_out(['error' => 'Order flow is temporarily unavailable. Sorry for the inconvenience.', 'why' => $d['error']], 503);
of_out($d);
