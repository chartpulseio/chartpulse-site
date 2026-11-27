<?php
/**
 * Confluence Scanner endpoint                                   chartpulse.io
 *   GET (no args)   -> ranked list
 *   GET ?s=SYMBOL   -> one symbol with frames, lines, zone, ladder, plan and the mixture chart data
 */
declare(strict_types=1);
require __DIR__ . '/cf_engine.php';
function cf_json($d, int $code = 200): void {
    if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); }
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
}
set_exception_handler(function (Throwable $e) {
    @file_put_contents(T_DATA . '/errors.log', gmdate('Y-m-d H:i:s') . ' [cf] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND);
    cf_json(['error' => 'The Confluence Scanner is temporarily unavailable.'], 503);
});
if (!headers_sent() && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
@set_time_limit(60);
if (isset($_GET['s'])) { $s = substr(preg_replace('/[^A-Za-z0-9.^=_-]/', '', (string)$_GET['s']), 0, 30); $r = cf_detail($s); $r ? cf_json($r) : cf_json(['error' => 'No data for this market yet.'], 404); }
cf_json(cf_scan());
