<?php
/**
 * ChartPulse Signals API v1 (owner, 28 Nov 2026) -- feeds the MT5 auto-entry EA.
 *   GET  /api/v1/signals            header X-API-Key (or ?key=)  -> active setups, live markets only
 *   POST /api/v1/keys               (paid browser session)       -> issues a new API key, shown once
 *   GET  /api/v1/ping               -> {ok, halt}
 * Sources: Confluence (stage >= 4), God-father v2 (A+/A), Day Drive BOUNCE GO/READY (read-only from its own
 * cached rows file -- no Day Drive file is touched), Reversal (TURNING/SNAPPING). Filters: market live,
 * high-impact news in the last 60 min blocks, GEX regime as label. Only derived levels are sent (zone, side),
 * never raw candles. The EA confirms with candle patterns on the broker's own prices and places the order.
 */
declare(strict_types=1);
require_once __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_access.php';

const SA_DIR = T_DATA . '/api';
// owner demo key (28 Nov 2026): only the sha256 hash lives here; the key itself was given to the owner in chat
const SA_SEED = ['af7792c7e1ebe171819959b56a0122a88fb59224f271e8a5405501fb20b40c54' => ['id' => 'owner-demo', 'tier' => 'tier2', 'exp' => 1801958400]];   // valid to 2027-02-07
const SA_MT5 = ['GC=F' => 'XAUUSD', 'SI=F' => 'XAGUSD', 'CL=F' => 'USOIL', 'BZ=F' => 'UKOIL', 'NG=F' => 'NATGAS', '^GSPC' => 'US500', '^NDX' => 'US100', '^DJI' => 'US30',
                '^RUT' => 'US2000', '^GDAXI' => 'DE40', '^FTSE' => 'UK100', '^N225' => 'JP225', 'ES=F' => 'US500', 'NQ=F' => 'US100', 'YM=F' => 'US30'];

function sa_json($d, int $code = 200): void {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
    echo json_encode($d, JSON_UNESCAPED_SLASHES); exit;
}
function sa_keys(): array { $k = t_read_json(SA_DIR . '/keys.json', []); return is_array($k) ? $k : []; }
function sa_auth(): ?array {
    $k = (string)($_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? ''));
    if (strlen($k) < 32) return null;
    $h = hash('sha256', $k); $K = sa_keys() + SA_SEED; $e = $K[$h] ?? null;
    if (!$e || !empty($e['off']) || (int)($e['exp'] ?? 0) < time()) return null;
    return $e + ['h' => $h];
}
function sa_mt5(string $s): string {
    if (isset(SA_MT5[$s])) return SA_MT5[$s];
    if (str_ends_with($s, '=X')) return substr($s, 0, -2);
    if (preg_match('/^([A-Z0-9]+)-USD$/', $s, $m)) return $m[1] . 'USD';
    return ltrim($s, '^');
}
function sa_halt(): bool { return is_file(SA_DIR . '/halt'); }

function sa_signals(): array {
    $now = t_now(); $ck = SA_DIR . '/_signals4.json'; $c = t_read_json($ck, null);
    if (is_array($c) && $now - (int)($c['at'] ?? 0) < 45) return $c;
    $scan = t_scan_load()['rows'] ?? [];
    $live = fn(string $s) => t_status($s, $scan[$s] ?? null, $now)['live'];
    $S = [];   // sym|side => signal
    $add = function (string $sym, string $side, string $src, ?array $zone, string $stage, ?int $D = null, ?int $W = null) use (&$S, $live) {
        if (!$zone || !$live($sym)) return;
        $lo = (float)min($zone); $hi = (float)max($zone); if ($lo <= 0) return;
        $k = "$sym|$side";
        if (!isset($S[$k])) $S[$k] = ['symbol' => $sym, 'mt5' => sa_mt5($sym), 'side' => $side, 'zone' => [$lo, $hi], 'sources' => [], 'stages' => []];
        $S[$k]['sources'][] = $src; $S[$k]['stages'][$src] = $stage;
        if ($D !== null && $W !== null && !isset($S[$k]['dw'])) $S[$k]['dw'] = [$D, $W];
        if ($src === 'confluence') $S[$k]['zone'] = [$lo, $hi];   // the confluence zone is the most precise one
    };
    $sd = fn($b) => in_array($b, ['PUT', 'DOWN', 'SELL'], true) ? 'SELL' : 'BUY';
    // Confluence: rejection or GO
    try { require_once __DIR__ . '/cf_engine.php'; foreach (cf_scan()['rows'] ?? [] as $r) if ((int)($r['stage'] ?? 0) >= 4 && !empty($r['zone'])) $add($r['symbol'], $sd($r['bias']), 'confluence', $r['zone'], (string)$r['stage_name'], (int)($r['mtf']['d']['1D'] ?? 0), (int)($r['mtf']['d']['1W'] ?? 0)); } catch (Throwable $e) {}
    // God-father v2: A+ / A
    try { require_once __DIR__ . '/gf2_engine.php'; foreach (gf2_scan()['rows'] ?? [] as $r) if (in_array($r['grade'] ?? '', ['A+', 'A'], true)) {
        $z = $r['zone'] ?? null; if (!$z && !empty($r['plan']['entry']) && !empty($r['plan']['stop'])) $z = [(float)$r['plan']['entry'], (float)$r['plan']['stop']];
        $gs = $sd($r['side']) === 'SELL' ? -1 : 1; $add($r['symbol'], $sd($r['side']), 'godfather', $z, (string)$r['grade'], $gs, $gs); } } catch (Throwable $e) {}
    // Day Drive: read-only from its own cache file (the Day Drive engine and its files are not loaded or changed)
    $dd = t_read_json(T_DATA . '/daydrive/rows.json', []);
    if (is_array($dd)) foreach ($dd as $sym => $r) { $b = is_array($r) ? ($r['bounce'] ?? null) : null;
        if (!is_array($b) || !in_array($b['status'] ?? '', ['BOUNCE GO', 'BOUNCE READY'], true) || empty($b['d_line'])) continue;
        if ($now - (int)($r['at'] ?? $now) > 1800) continue;
        $tr = $b['trends'] ?? []; $tv = fn($x) => $x === 'up' ? 1 : ($x === 'down' ? -1 : 0);
        $add((string)$sym, $sd($b['side'] ?? ''), 'daydrive', [(float)$b['d_line'], (float)($b['extreme'] ?? $b['d_line'])], (string)$b['status'], isset($tr['1D']) ? $tv($tr['1D']) : null, isset($tr['1W']) ? $tv($tr['1W']) : null); }
    // Reversal: turning / snapping
    try { require_once __DIR__ . '/rb_engine.php'; foreach (rb_scan()['rows'] ?? [] as $r) if ((int)($r['stage'] ?? 0) >= 2 && !empty($r['ext'])) {
        $px = (float)($r['price'] ?? 0); $add($r['symbol'], $sd($r['side'] ?? ''), 'reversal', [(float)$r['ext'], $px ?: (float)$r['ext']], (string)$r['stage_name'], isset($r['mtf']['1D']) ? (int)$r['mtf']['1D'] : null, isset($r['mtf']['1W']) ? (int)$r['mtf']['1W'] : null); } } catch (Throwable $e) {}
    // owner, 28 Nov 2026, 18:05: three trend-side tools, D+W bias required (same filter as Confluence / God-father)
    $bm = [];   // symbol => [D, W] trend from the trend-line engine and the 200 EMA cache
    try { require_once __DIR__ . '/tl_engine.php'; foreach (tl_scan()['rows'] ?? [] as $r) if (isset($r['mtf']['d'])) $bm[$r['symbol']] = [(int)($r['mtf']['d']['1D'] ?? 0), (int)($r['mtf']['d']['1W'] ?? 0)]; } catch (Throwable $e) {}
    $e2 = json_decode((string)@file_get_contents(T_DATA . '/ema200_signals2.json'), true);
    foreach ((array)($e2['signals'] ?? []) as $r) if (isset($r['mtf']['d']) && !isset($bm[$r['symbol']])) $bm[$r['symbol']] = [(int)($r['mtf']['d']['1D'] ?? 0), (int)($r['mtf']['d']['1W'] ?? 0)];
    $dwOf = fn(string $sym) => $bm[$sym] ?? [null, null];
    // Liquidity Sweep + OB + FVG (1H, plus 15m only next to a 1H setup) and SMC Power of 3 + OTE (1H) -- the retest price is the zone
    try {
        require_once __DIR__ . '/institutional/includes/config.php'; require_once __DIR__ . '/institutional/includes/strategies.php';
        $icache = __DIR__ . '/institutional/cache';
        foreach ([['3-liquidity-fvg', '1H', 'fvg'], ['1-smc-power-of-3', '1H', 'po3'], ['3-liquidity-fvg', '15m', 'fvg15']] as [$sid, $tf, $tag]) {
            foreach (ta_get($icache, scan_map(), SCAN_MIN_BARS, $sid, $tf)['data'] ?? [] as $r) {
                $sym = (string)($r['symbol'] ?? ''); $side = $sd($r['bias'] ?? ''); $px = (float)($r['entry'] ?? 0); if (!$sym || $px <= 0) continue;
                if ($tag === 'fvg15' && !isset($S["$sym|$side"])) continue;   // 15m only alongside a 1H setup
                [$D, $W] = $dwOf($sym); $add($sym, $side, $tag === 'fvg15' ? 'fvg' : $tag, [$px, $px], "$sid $tf", $D, $W);
            }
        }
    } catch (Throwable $e) {}
    // 200 EMA (4H and 1H): price in the ATR zone of the EMA; the side is the D+W trend (support in a bull, resistance in a bear)
    foreach ((array)($e2['signals'] ?? []) as $r) {
        $sym = (string)($r['symbol'] ?? ''); [$D, $W] = $dwOf($sym); if (!$sym || !$D || $D !== $W) continue;
        foreach (['4H', '1H'] as $tf) { $q = $r['tfs'][$tf] ?? null;
            if (is_array($q) && !empty($q['in']) && (float)($q['e'] ?? 0) > 0) { $e = (float)$q['e']; $add($sym, $D > 0 ? 'BUY' : 'SELL', 'ema200', [$e, $e], "200 EMA $tf", $D, $W); break; } }
    }
    // News: high impact in the last 60 minutes blocks new entries on that market
    $block = [];
    try { require_once __DIR__ . '/news_scan.php'; foreach (ns_scan()['rows'] ?? [] as $r) if (($r['imp'] ?? '') === 'high' && $now - (int)($r['last'] ?? 0) <= 3600) $block[$r['symbol']] = true; } catch (Throwable $e) {}
    $gexOk = is_file(__DIR__ . '/gex_engine.php'); if ($gexOk) require_once __DIR__ . '/gex_engine.php';
    $out = [];
    foreach ($S as $x) {
        // owner, 28 Nov 2026: trade only with the HTF bias -- Daily AND Weekly both bearish = SELL only, both bullish = BUY only
        // owner, 28 Nov 2026, 16:03: Day Drive and Reversal are reversal tools -- they skip the D+W filter
        $dw = $x['dw'] ?? null; $want = $x['side'] === 'SELL' ? -1 : 1;
        $rev = (bool)array_intersect($x['sources'], ['daydrive', 'reversal']);
        $withHtf = $dw && (int)$dw[0] === $want && (int)$dw[1] === $want;
        if (!$withHtf && !$rev) continue;
        if (!$withHtf) $x['sources'] = array_values(array_intersect($x['sources'], ['daydrive', 'reversal']));   // trend tools only count when they agree with D+W
        $x['bias'] = $withHtf ? ($want < 0 ? 'BEAR (D+W)' : 'BULL (D+W)') : 'COUNTER-TREND REVERSAL'; unset($x['dw']);
        $x['sources'] = array_values(array_unique($x['sources'])); $x['news_block'] = isset($block[$x['symbol']]);
        $x['gex'] = null; if ($gexOk) { try { $g = gex_label_for($x['symbol']); if ($g) $x['gex'] = ['regime' => ((float)($g['rg'] ?? 0)) > 0 ? 'positive' : 'negative', 'call_wall' => $g['cw'] ?? null, 'put_wall' => $g['pw'] ?? null, 'flip' => $g['flip'] ?? null]; } catch (Throwable $e) {} }
        $x['id'] = substr(sha1($x['symbol'] . '|' . $x['side'] . '|' . gmdate('Y-m-d', $now) . '|' . round($x['zone'][0], 4)), 0, 12);
        $x['score'] = count($x['sources']); $out[] = $x;
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    $p = ['at' => $now, 'halt' => sa_halt(), 'count' => count($out), 'signals' => $out];
    if (!is_dir(SA_DIR)) @mkdir(SA_DIR, 0755, true);
    t_write_json($ck, $p);
    return $p;
}

$r = (string)($_GET['r'] ?? 'signals');
if ($r === 'ping') sa_json(['ok' => true, 'halt' => sa_halt(), 'at' => t_now()]);
if ($r === 'keys') {   // issue a key for a paid browser session (Tier 2 or above)
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sa_json(['error' => 'POST only'], 405);
    $a = ta_current(); if (!$a || !ta_has('tier2')) sa_json(['error' => 'A paid plan is needed for API access.'], 403);
    if (!t_rl('apikey', 3, 3600)) sa_json(['error' => 'Too many keys requested. Try later.'], 429);
    if (!is_dir(SA_DIR)) @mkdir(SA_DIR, 0755, true);
    $key = 'cp_' . bin2hex(random_bytes(24)); $K = sa_keys();
    foreach ($K as $h => $e) if (($e['id'] ?? '') === ($a['id'] ?? '')) $K[$h]['off'] = 1;   // one active key per account
    $K[hash('sha256', $key)] = ['id' => (string)($a['id'] ?? ''), 'tier' => $a['tier'], 'exp' => (int)$a['exp'], 'at' => time()];
    t_write_json(SA_DIR . '/keys.json', $K);
    sa_json(['key' => $key, 'expires' => gmdate('Y-m-d', (int)$a['exp']), 'note' => 'Shown once. Paste it into the ChartPulse EA input ApiKey.']);
}
$u = sa_auth(); if (!$u) sa_json(['error' => 'Invalid or missing API key.'], 401);
if (!t_rl('api_' . substr($u['h'], 0, 16), 30)) sa_json(['error' => 'Rate limit: 30 requests per minute.'], 429);
sa_json(sa_signals());
