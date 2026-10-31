<?php
/**
 * Day Drive Scanner — endpoint                                 chartpulse.io
 *
 *   GET ?r=scan&mkt=all|forex|crypto|other
 *   GET ?r=symbol&s=SYMBOL                      (one symbol, full detail)
 *   GET ?r=availability                          (what is real / estimated / missing)
 *   GET ?selftest=1                              (does this server have what the page needs)
 *
 * The page at /day-drive.html is the only caller, same origin, so no CORS.
 * Owner's standing rule: a visitor never sees a backend detail. Anything that
 * goes wrong becomes one polite "unavailable" line; the real reason goes to
 * data/errors.log.
 */
declare(strict_types=1);

require __DIR__ . '/dd_engine.php';

const DD_SORRY = 'The Day Drive Scanner is temporarily unavailable. Sorry for the inconvenience. Please check back in a little while.';

function dd_json($d): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
function dd_sorry(string $where, string $msg, int $code = 503): void {
    dd_log($where, $msg);
    if (!headers_sent()) http_response_code($code);
    dd_json(['error' => DD_SORRY, 'unavailable' => true]);
    exit;
}
function dd_bad(string $msg): void {
    if (!headers_sent()) http_response_code(422);
    dd_json(['error' => $msg]);
    exit;
}

set_exception_handler(function (Throwable $e) {
    dd_sorry('exception', get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) @ob_end_clean();
        dd_sorry('fatal', $e['message'] . ' @' . basename((string)$e['file']) . ':' . $e['line']);
    }
});
@set_time_limit(60);
ignore_user_abort(true);
if (!headers_sent() && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')
    && extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');

// ---- self test -----------------------------------------------------------
if (isset($_GET['selftest'])) {
    $scan = t_scan_load();
    $rows = $scan['rows'] ?? [];
    $withFrames = 0; $with5m = 0; $n = 0;
    foreach (array_slice(array_keys($rows), 0, 40) as $s) {
        $n++;
        $fr = t_read_frames($s);
        if ($fr) { $withFrames++; if (!empty($fr['5m']['t'])) $with5m++; }
    }
    dd_json([
        'ok' => $withFrames > 0,
        'php' => PHP_VERSION,
        'engine' => 'day-drive hybrid (volume-free)',
        'scan_rows' => count($rows),
        'sampled' => $n, 'with_frames' => $withFrames, 'with_cached_5m' => $with5m,
        'data_dir_writable' => is_writable(T_DATA),
        'daydrive_dir' => is_dir(DD_DIR) ? 'present' : 'will be created',
        'note' => $withFrames > 0
            ? 'Ready. The scanner reads the cached frames the Tier-1 scanner already writes, so it adds no market-data calls.'
            : 'No cached frames yet — open the main scanner once so the frame cache fills, then retry.',
    ]);
    exit;
}

// ---- rate limit (page is public; this is the real protection) ------------
$r = (string)($_GET['r'] ?? 'scan');
$r = substr(preg_replace('/[^a-z]/', '', strtolower($r)), 0, 16);
$LIM = ['scan' => 40, 'symbol' => 30, 'availability' => 10];
if (!t_rl('dd', 90) || !t_rl('dd:' . $r, $LIM[$r] ?? 20)) {
    http_response_code(429);
    header('Retry-After: 30');
    dd_json(['error' => 'Too many requests. Please wait a few seconds and try again.']);
    exit;
}

$mkt = (string)($_GET['mkt'] ?? 'all');
if (!in_array($mkt, ['all', 'forex', 'crypto', 'other'], true)) $mkt = 'all';

if ($r === 'availability') {
    dd_json(['availability' => dd_availability()]);
    exit;
}

if ($r === 'symbol') {
    $s = (string)($_GET['s'] ?? '');
    if ($s === '' || strlen($s) > 24) dd_bad('Pick a symbol from the list.');
    if (!isset(t_symbols()[$s])) dd_bad('That symbol is not in the scanner universe.');
    if (in_array($s, DD_EXCLUDE, true)) dd_bad('That symbol is excluded from the scanner.');
    $scan = t_scan_load();
    $row = $scan['rows'][$s] ?? [];
    $out = dd_eval($s, $row, true, dd_context(), t_now());
    if (!$out) dd_sorry('symbol', "no usable history for $s", 200);
    dd_json(['row' => $out, 'availability' => dd_availability()]);
    exit;
}

// default: scan
$force = isset($_GET['force']) && $_GET['force'] === '1' && t_rl('dd:force', 4);
dd_json(dd_scan($mkt, $force));
