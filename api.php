<?php
// TORUS API router: api.php?r=scan | symbol&sym=..&tf=.. | bias&s=.. | refresh (POST) | export | news | plans | access | t2 | t2unlock | t2tg | trades
declare(strict_types=1);
require __DIR__ . '/torus_engine.php';
const T_SORRY = 'This view is temporarily unavailable. Sorry for the inconvenience. Please check back in a little while.';
/** Owner (5 Oct 2026): never show backend / provider details to visitors. Real reason goes to data/errors.log. */
function t_err_log(string $where, string $msg): void {
    @file_put_contents(T_DATA . '/errors.log', gmdate('Y-m-d H:i:s') . " [$where] " . str_replace("\n", ' ', $msg) . "\n", FILE_APPEND | LOCK_EX);
}
function t_sorry(string $where, string $msg, int $code = 503): void {
    t_err_log($where, $msg);
    if (!headers_sent()) http_response_code($code);
    t_json(['error' => T_SORRY, 'unavailable' => true]);
}
set_exception_handler(function (Throwable $e) { t_sorry('exception', get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); });
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) @ob_end_clean();
        t_sorry('fatal', $e['message'] . ' @' . basename((string)$e['file']) . ':' . $e['line']);
    }
});
@set_time_limit(60);
ignore_user_abort(true);
if (!headers_sent() && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');

$r = $_GET['r'] ?? 'scan';
$TFS = ['10M', '1H', '4H', '1D', '1W'];

// Rate limits per visitor per minute (owner, 29 Sep 2026). Pages poll every 3-20 s, so normal use stays far below these.
$r = is_string($r) ? substr(preg_replace('/[^a-z0-9]/', '', strtolower($r)), 0, 16) : 'scan';
$RL = ['scan' => 120, 'symbol' => 60, 'bias' => 40, 't2' => 60, 'trades' => 40, 'news' => 30, 'refresh' => 6, 'export' => 6, 'plans' => 30, 'access' => 60, 'health' => 20, 'desk' => 30, 'deskai' => 3, 'desklive' => 12, 'mlfeed' => 20, 'mlpush' => 20];
if (!t_rl('api', 300) || !t_rl('r:' . $r, $RL[$r] ?? 30)) {
    http_response_code(429); header('Retry-After: 30'); t_json(['error' => 'Too many requests. Please wait a few seconds and try again.']); exit;
}

if ($r === 'scan') {
    t_scan_step();
    $now = t_now(); $scan = t_scan_load(); $rows = t_view_rows($scan, $now);
    t_json(['meta' => t_meta($scan, $rows, $now), 'rows' => $rows, 'brief' => t_market_brief($rows), 'recent' => t_siglog_view($now)]);
} elseif ($r === 'symbol') {
    $sym = (string)($_GET['sym'] ?? '');
    $tf = strtoupper((string)($_GET['tf'] ?? '1D')); if (!in_array($tf, $TFS, true)) $tf = '1D';
    $fr = isset(t_symbols()[$sym]) ? t_load_frames($sym) : null;
    if (!$fr) { t_sorry('symbol', 'frames not ready'); exit; }
    $row = t_scan_load()['rows'][$sym] ?? null; $now = t_now(); $zone = null;
    if ($tf === '10M') { $m5 = t_m5($sym); if ($m5) $fr['5m'] = $m5; }
    $res = t_apply_status($sym, t_evaluate($sym, $fr), $row, $now); $st = t_stats($fr);
    if ($tf === '10M' && !empty($fr['5m']['t'])) {   // 10-minute view: candles + today's Day High / Low, reversal bands and Fibonacci map
        $D = $fr['1D']; $dayTs = (int)$D['t'][count($D['t']) - 1];
        $g = t_ten($fr['5m'], $dayTs); $L = 260; $sl = fn($a) => array_values(array_slice($a, -$L));
        $c = ['t' => $sl($g['t']), 'o' => $sl($g['o']), 'h' => $sl($g['h']), 'l' => $sl($g['l']), 'c' => $sl($g['c']), 'v' => $sl($g['v'])];
        $zone = t_zone_view($fr['5m'], $dayTs, (float)$res['zone_band_px'], (string)$res['zone_near'], (float)$res['price']);
    } else {
        if ($tf === '10M') $tf = '1H';   // no intraday bars: fall back to hourly
        $c = t_candles($fr, $tf); unset($c['st'], $c['dir'], $c['ema']);   // indicator lines stay on the server
    }
    unset($st['st_flips_1y']);
    t_json(['result' => t_public($res), 'stats' => $st, 'brief' => t_symbol_brief($res, $st), 'candles' => $c, 'tf' => $tf, 'zone' => $zone]);
} elseif ($r === 'bias') {   // free Bias Check: trend state + stats + news, no signal / zone / score (owner, 29 Sep 2026)
    $sym = (string)($_GET['s'] ?? '');
    if (!isset(t_symbols()[$sym])) { http_response_code(404); t_json(['error' => 'Unknown symbol']); exit; }
    $scan = t_scan_load(); $now = t_now();
    $row = t_view_rows(['rows' => array_intersect_key($scan['rows'] ?? [], [$sym => 1])], $now)[0] ?? null;
    if (!$row) { http_response_code(404); t_json(['error' => 'This symbol has not been scanned yet. Try again in a few minutes.']); exit; }
    $o = ['symbol' => $sym, 'name' => $row['name'], 'market' => $row['market'], 'exchange' => $row['exchange'] ?? null, 'status' => $row['status'], 'live' => $row['live'],
        'price' => $row['price'], 'change_pct' => $row['change_pct'], 'bias' => $row['bias'], 'sync' => $row['alignment'], 'ts' => $row['ts'] ?? null];
    foreach (['ret_1w', 'ret_1m', 'ret_3m', 'ret_1y', 'vol_20d', 'from_hi_pct', 'from_lo_pct', 'atr_pct', 'move_atr', 'range_pct', 'range_pos', 'spark'] as $k) $o[$k] = $row[$k] ?? null;
    $news = []; $nc = t_read_json(T_DATA . '/news.json', ['items' => []]);
    foreach (($nc['items'] ?? []) as $x) {
        foreach (($x['tags'] ?? []) as $tg) if (($tg['symbol'] ?? '') === $sym && preg_match('~^https?://~i', (string)$x['u'])) { $news[] = ['title' => $x['t'], 'publisher' => $x['src'], 'link' => $x['u'], 'ts' => $x['ts'], 'imp' => $x['imp'], 'sen' => $x['sen']]; break; }
        if (count($news) >= 5) break;
    }
    $o['news'] = $news;
    require_once __DIR__ . '/torus_seo.php'; $o['url'] = SEO_BASE . seo_url($sym);   // shareable page for this symbol
    t_json($o);
} elseif ($r === 'desk') {   // Bias Desk (owner, 4 Oct 2026): bias, scenario cone, setup memory, self-test, news tone
    require __DIR__ . '/torus_desk.php';
    $sym = (string)($_GET['s'] ?? ''); $tf = strtoupper((string)($_GET['tf'] ?? '1D')); if ($tf !== '1H') $tf = '1D';
    if (!isset(t_symbols()[$sym])) { http_response_code(404); t_json(['error' => 'Unknown symbol. Pick one from the list.']); exit; }
    ml_note_request($sym);
    $d = td_cached($sym, $tf);
    if (isset($d['error'])) { t_sorry('desk ' . $sym, (string)$d['error']); exit; }
    $a = ta_get($sym);   // TradingAgents committee report (refreshed by cron, not cached with the desk)
    require_once __DIR__ . '/torus_live.php'; $lr = lc_get($sym);
    $d['live'] = ['on' => lc_on(), 'report' => ($lr && ($lr['status'] ?? '') === 'done' && t_now() - (int)$lr['at'] < LC_TTL) ? $lr : null];
    $d['agents'] = ['on' => ml_on(), 'status' => $a['status'] ?? null, 'date' => $a['date'] ?? null, 'at' => $a['at'] ?? null, 'result' => $a['result'] ?? null, 'error' => isset($a['error']) ? 'The last run did not finish.' : null];
    t_json($d);
} elseif ($r === 'mlfeed' || $r === 'mlpush') {   // GitHub Actions ML job (owner, 4 Oct 2026): work list out, results in. Shared key in header X-CP-Key.
    require __DIR__ . '/torus_desk.php';
    if (!ml_push_auth()) { http_response_code(403); t_json(['error' => 'forbidden']); exit; }
    if ($r === 'mlfeed') { t_json(ml_feed()); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); t_json(['error' => 'POST only']); exit; }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024), true);
    if (!is_array($in)) { http_response_code(400); t_json(['error' => 'bad json']); exit; }
    t_json(['ok' => true, 'stored' => ml_store_push($in)]);
} elseif ($r === 'desklive') {   // Live AI view (owner, 5 Oct 2026): 4 Gemini analysts + judge in about 30 s, shared per symbol for 1 hour
    require __DIR__ . '/torus_desk.php'; require_once __DIR__ . '/torus_live.php';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); t_json(['error' => 'POST only']); exit; }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 512), true) ?: [];
    $sym = (string)($in['s'] ?? '');
    if (!isset(t_symbols()[$sym])) { http_response_code(400); t_json(['error' => 'Unknown symbol']); exit; }
    t_json(lc_run($sym));
} elseif ($r === 'deskai') {   // queue a TradingAgents run for one symbol (small daily quota)
    require __DIR__ . '/torus_desk.php';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); t_json(['error' => 'POST only']); exit; }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 512), true) ?: [];
    $sym = (string)($in['s'] ?? '');
    if (!isset(t_symbols()[$sym])) { http_response_code(400); t_json(['error' => 'Unknown symbol']); exit; }
    t_json(['status' => ta_queue($sym, true)]);
} elseif ($r === 'lab') {
    http_response_code(410); t_json(['error' => 'Not available']);
} elseif ($r === 'refresh') {
    t_scan_step(true);
    t_json(['ok' => true]);
} elseif ($r === 'export') {
    $now = t_now(); $rows = t_view_rows(t_scan_load(), $now);
    usort($rows, 't_row_cmp');   // v8: signal > queue > out; inside signals FLY A+ > A > B > RUNWAY > APPROACHING; then score
    $T1 = ['signal' => 'SIGNAL', 'queue' => 'UNDER PROCESS', 'out' => 'NOT IN SCANNER'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=torus_signals.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Symbol','Name','Market','Exchange','Status','Tier-1','Signal','Strength','Bias','Pulse Sync','Price','Change %','ATR %','Pulse zone','Pulse level','Updated IST'], ',', '"', '\\');
    foreach ($rows as $x) fputcsv($out, [$x['symbol'],$x['name'],$x['market'],$x['exchange'],$x['status'],$T1[$x['tier1']] ?? '',$x['signal'],$x['strength'],$x['bias'],$x['alignment'] . '/4',
        $x['price'],$x['change_pct'],$x['atr_pct'],$x['zone_near'], round((float)$x['zone'], 6), t_ist((int)$x['ts'], 'd M Y H:i')], ',', '"', '\\');
} elseif ($r === 'plans') {
    require __DIR__ . '/torus_pricing.php';
    $kid = (string)t_cfg('razorpay_key_id', '');
    t_json(['pricing' => tp_table(), 'payments' => $kid !== '' && (string)t_cfg('razorpay_key_secret', '') !== '', 'mode' => str_starts_with($kid, 'rzp_live_') ? 'live' : 'test',
        'business' => (string)t_cfg('business_name', 'ChartPulse'), 'crypto' => (function () { require_once __DIR__ . '/torus_usdt.php'; return tu_on(); })()]);
} elseif ($r === 'access') {
    require __DIR__ . '/torus_access.php';
    $a = ta_current();
    t_json(['tier' => $a['tier'] ?? null, 'until' => isset($a['exp']) ? gmdate('Y-m-d', (int)$a['exp']) : null]);
} elseif ($r === 't2unlock') {
    require __DIR__ . '/torus_access.php';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); t_json(['error' => 'POST only']); exit; }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true) ?: [];
    t_ensure_dirs(); $lk = T_DATA . '/.unlock_' . substr(hash('sha256', t_client_ip()), 0, 16);
    $tries = t_read_json($lk, ['n' => 0, 'at' => 0]); if (time() - $tries['at'] > 900) $tries = ['n' => 0, 'at' => time()];
    if ($tries['n'] >= 8) { http_response_code(429); t_json(['error' => 'Too many attempts. Try again in 15 minutes.']); exit; }
    $g = ta_redeem((string)($in['code'] ?? ''));
    if (!$g) { $tries['n']++; t_write_json($lk, $tries); http_response_code(400); t_json(['error' => 'That code is not valid or has expired.']); exit; }
    ta_grant($g['tier'], $g['exp'], $g['id']);
    t_json(['ok' => true, 'tier' => $g['tier'], 'until' => gmdate('Y-m-d', $g['exp'])]);
} elseif ($r === 't2') {
    require __DIR__ . '/torus_access.php'; require __DIR__ . '/torus_tier2.php';
    $sym = (string)($_GET['s'] ?? ''); $bias = strtoupper((string)($_GET['bias'] ?? ''));
    if (!isset(t_symbols()[$sym])) { http_response_code(404); t_json(['error' => 'Unknown symbol']); exit; }
    if (t2_paywall() && !ta_has('tier2')) {
        $row = t_view_rows(['rows' => array_intersect_key(t_scan_load()['rows'] ?? [], [$sym => 1])], t_now())[0] ?? null;
        http_response_code(402);
        t_json(['locked' => true, 'symbol' => $sym, 'name' => t_name($sym), 'market' => t_symbols()[$sym],
            'tier1' => $row ? ['tier' => $row['tier1'], 'signal' => $row['signal'], 'bias' => $row['bias'], 'alignment' => $row['alignment'], 'live' => $row['live'], 'price' => $row['price'], 'chg' => $row['change_pct']] : null]);
        exit;
    }
    $d = t2_cached($sym, $bias);
    if (isset($d['error'])) { t_sorry('t2 ' . $sym, (string)$d['error']); exit; }
    t_json(t2_public($d));
} elseif ($r === 't2tg') {
    require __DIR__ . '/torus_access.php'; require __DIR__ . '/torus_tier2.php';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); t_json(['ok' => false, 'error' => 'POST only']); exit; }
    if (t2_paywall() && !ta_has('tier2')) { http_response_code(402); t_json(['ok' => false, 'error' => 'Tier 2 access required']); exit; }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true) ?: [];
    $sym = (string)($in['s'] ?? ''); $bias = strtoupper((string)($in['bias'] ?? '')); $chat = preg_replace('/[^0-9@A-Za-z_-]/', '', (string)($in['chat'] ?? ''));
    if (!isset(t_symbols()[$sym])) { http_response_code(400); t_json(['ok' => false, 'error' => 'Unknown symbol']); exit; }
    $a = ta_current(); $lk = T_DATA . '/.tg_' . substr(hash('sha256', (string)($a['id'] ?? ('ip:' . t_client_ip()))), 0, 16);
    if (time() - (int)@filemtime($lk) < 20) { http_response_code(429); t_json(['ok' => false, 'error' => 'Please wait a few seconds between messages.']); exit; }
    @touch($lk);
    $d = t2_cached($sym, $bias);
    if (isset($d['error'])) { t_err_log('t2tg', (string)$d['error']); http_response_code(503); t_json(['ok' => false, 'error' => T_SORRY]); exit; }
    $sent = null;
    if (!empty($in['auto'])) {   // automatic alert when the action fires: once per chat, symbol and action
        if (($d['status'] ?? '') !== 'ACTION_TRIGGERED' || empty($d['action_time'])) { t_json(['ok' => false, 'info' => 'No action yet.']); exit; }
        $dir = T_DATA . '/tg_sent'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $sent = $dir . '/' . substr(hash('sha256', $chat . '|' . $sym . '|' . $d['bias'] . '|' . $d['action_time']), 0, 24);
        if (is_file($sent)) { t_json(['ok' => true, 'info' => 'Alert already sent for this action']); exit; }
    }
    [$ok, $info] = t2_tg_send(t2_tg_text($d), $chat);
    if ($ok && $sent) @touch($sent);
    t_json(['ok' => $ok, 'info' => $info]);
} elseif ($r === 'trades') {   // Recent trades: every Tier-2 trade replayed from price history, wins and losses
    require __DIR__ . '/torus_tier2.php';
    t2_trades_refresh(3);
    t_json(t2_trades_public(t2_trades_view()));
} elseif ($r === 'news') {
    require __DIR__ . '/torus_news.php';
    t_json(t_news_view());
} elseif ($r === 'health') {
    $now = t_now(); $scan = t_scan_load(); $st = t_up_state(); $map = t_up_map(); $exp = t_up_token_exp();
    $srcs = array_count_values(array_map(fn($x) => $x['src'] ?? 'yahoo', $scan['rows']));
    t_json(['ok' => true, 'net' => ['xff_hops' => count(array_filter(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')), 'strlen')), 'remote_public' => (bool)filter_var((string)($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)], 'now_ist' => t_ist($now, 'd M Y H:i'), 'universe' => count(t_symbols()), 'rows' => count($scan['rows']), 'sources' => $srcs,
        'upstox' => ['configured' => t_up_on(), 'token_expires' => $exp ? t_ist($exp, 'd M Y') : null, 'token_expired' => $exp ? $exp < $now : null,
            'auth_error' => !empty($st['auth_error']) && $now - (int)$st['auth_error'] < 3600, 'instrument_map' => ['built' => !empty($map['built']) ? t_ist((int)$map['built'], 'd M H:i') : null,
            'nse' => count($map['nse'] ?? []), 'mcx' => array_map(fn($x) => $x['contract'], $map['mcx'] ?? []), 'missing' => $map['missing'] ?? []]],
        'razorpay' => ['configured' => t_cfg('razorpay_key_id', '') !== '' && t_cfg('razorpay_key_secret', '') !== '', 'mode' => str_starts_with((string)t_cfg('razorpay_key_id', ''), 'rzp_live_') ? 'live' : 'test'],
        'failed' => array_map(fn($f) => $f['why'], $scan['fail'])]);
} else {
    http_response_code(400); t_json(['error' => 'Unknown route']);
}
