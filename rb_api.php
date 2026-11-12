<?php
/** Rubber Band Reversal Scanner endpoint: GET rb_api.php -> { at, rows, meta } */
declare(strict_types=1);
require __DIR__ . '/rb_engine.php';
function rb_json($d, int $code = 200): void {
    if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); }
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}
set_exception_handler(function (Throwable $e) {
    @file_put_contents(T_DATA . '/errors.log', gmdate('Y-m-d H:i:s') . ' [rb] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
    rb_json(['error' => 'This scanner is temporarily unavailable. Sorry for the inconvenience.'], 503);
});
if (!headers_sent() && str_contains((string)($_GET['gz'] ?? '1'), '1') && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
if (!t_rl('rb', 40)) { header('Retry-After: 30'); rb_json(['error' => 'Too many requests. Please wait a few seconds.'], 429); }
@set_time_limit(60);
try { t_scan_step(); } catch (Throwable $e) {}
rb_json(rb_scan());
