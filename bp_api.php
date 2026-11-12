<?php
/** bp_engine endpoint: GET bp_api.php -> { at, rows, meta } */
declare(strict_types=1);
require __DIR__ . '/bp_engine.php';
function bp_json($d, int $code = 200): void {
    if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); }
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}
set_exception_handler(function (Throwable $e) {
    @file_put_contents(T_DATA . '/errors.log', gmdate('Y-m-d H:i:s') . ' [bp] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
    bp_json(['error' => 'This scanner is temporarily unavailable. Sorry for the inconvenience.'], 503);
});
if (!headers_sent() && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
if (!t_rl('bp', 40)) { header('Retry-After: 30'); bp_json(['error' => 'Too many requests. Please wait a few seconds.'], 429); }
@set_time_limit(60);
bp_json(bp_scan());
