<?php
/**
 * Trend lines endpoint                                          chartpulse.io
 *   GET ?s=SYMBOL   -> { symbol, name, at, summary, frames: [ {tf, pattern, res, sup, text[], bars} ] }
 * Same-origin only (Tier 2 and the AI Scan page). A visitor never sees a backend detail.
 */
declare(strict_types=1);
require __DIR__ . '/tl_engine.php';

function tl_json($d, int $code = 200): void {
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
    @file_put_contents(T_DATA . '/errors.log', gmdate('Y-m-d H:i:s') . ' [tl] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
    tl_json(['error' => 'Trend lines are temporarily unavailable.'], 503);
});
if (!headers_sent() && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')
    && extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');

if (!t_rl('tl', 40)) { header('Retry-After: 30'); tl_json(['error' => 'Too many requests. Please wait a few seconds.'], 429); }
$s = (string)($_GET['s'] ?? '');
if ($s === '' || strlen($s) > 24 || !isset(t_symbols()[$s])) tl_json(['error' => 'Pick a symbol from the list.'], 422);
$out = tl_build($s);
if (!$out) tl_json(['error' => 'No chart history for this symbol yet.'], 200);
tl_json($out);
