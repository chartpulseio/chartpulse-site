<?php
/**
 * Trend lines endpoint                                          chartpulse.io
 *   GET ?r=hybrid   -> the Hybrid Trend Line Scanner list
 *   GET ?r=hero     -> Trendline Hero list
 *   GET ?r=scan     -> the Trend Line Scanner list: every symbol, 1W / 1D / 4H / 1H / 30m
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
if (($_GET['r'] ?? '') === 'tgstatus') { require_once __DIR__ . '/tg_alerts.php'; echo json_encode(tga_status()); exit; }
if (($_GET['r'] ?? '') === 'news') { @set_time_limit(60); require_once __DIR__ . '/news_scan.php'; tl_json(ns_scan()); }   // News Scanner
if (($_GET['r'] ?? '') === 'hybrid') { @set_time_limit(60); tl_json(tlh_scan()); }
if (($_GET['r'] ?? '') === 'hero' && isset($_GET['s'])) {   // Trendline Hero, Tier 2: one market with the 5-minute entry
    $hs = (string)$_GET['s'];
    if ($hs === '' || strlen($hs) > 24 || !isset(t_symbols()[$hs])) tl_json(['error' => 'Unknown market.'], 404);
    $hd = tlh_detail($hs); if (!$hd) tl_json(['error' => 'No data for this market yet.'], 404);
    tl_json($hd);
}
if (($_GET['r'] ?? '') === 'hero200') {   // Hero with 200 EMA: Trendline Hero setups whose line has the 1-hour 200 EMA on it
    @set_time_limit(60); $hp = tlh_scan(true);
    $hp['rows'] = array_values(array_filter($hp['rows'], fn($r) => !empty($r['e200']['ok'])));
    $hc = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0, 'old' => 0];
    foreach ($hp['rows'] as $r) { if ($r['stage'] >= 4 && empty($r['live'])) $hc['old']++; else $hc[$r['stage']]++; }
    $hp['meta']['counts'] = $hc; tl_json($hp);
}
if (($_GET['r'] ?? '') === 'hero') { @set_time_limit(60); tl_json(tlh_scan(true)); }   // Trendline Hero: 1W 1D 4H 1H 30m
if (($_GET['r'] ?? '') === 'scan') { @set_time_limit(60); tl_json(tl_scan()); }
$s = (string)($_GET['s'] ?? '');
if ($s === '' || strlen($s) > 24 || !isset(t_symbols()[$s])) tl_json(['error' => 'Pick a symbol from the list.'], 422);
$out = tl_build($s);
if (!$out) tl_json(['error' => 'No chart history for this symbol yet.'], 200);
tl_json($out);
