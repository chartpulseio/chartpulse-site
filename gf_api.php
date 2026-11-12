<?php
/**
 * God-father Scanner endpoint                                     chartpulse.io
 *   GET gf_api.php -> { at, rows, meta }
 * The grading rules stay here on the server; the page only receives labels.
 */
declare(strict_types=1);
require __DIR__ . '/gf_engine.php';

function gf_json($d, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
    }
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
set_exception_handler(function (Throwable $e) {
    @file_put_contents(T_DATA . '/errors.log', gmdate('Y-m-d H:i:s') . ' [gf] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
    gf_json(['error' => 'The God-father Scanner is temporarily unavailable. Sorry for the inconvenience.'], 503);
});
if (!headers_sent() && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')
    && extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
if (!t_rl('gf', 40)) { header('Retry-After: 30'); gf_json(['error' => 'Too many requests. Please wait a few seconds.'], 429); }
@set_time_limit(90);
gf_json(gf_scan());
