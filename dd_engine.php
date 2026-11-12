<?php
/**
 * Day Drive Scanner — hybrid engine                            chartpulse.io
 * ---------------------------------------------------------------------------
 * Built on the owner's "Best Low Early Entry — Volume-Free Edition" (the
 * hybrid), which is the authoritative principle set here. Its five steps are
 * implemented as written:
 *
 *   Step 1  Macro structure   Weekly / Daily   — weekly bias, BB wick + RSI,
 *                                                order block, VWAP interaction,
 *                                                5-wave fractal rejection
 *   Step 2  Trend             4H / 1H          — 20 EMA + KAMA, Supertrend,
 *                                                BB compression, delta, Fib
 *   Step 3  Entry trigger     15M / 5M         — 2-bar CHoCH, VWAP touches,
 *                                                footprint, RSI / hidden div,
 *                                                time of day, limit offset
 *   Step 4  Stop & risk                        — 15m swing stop, 1.2x ATR
 *                                                alternative, 50% at 1:1,
 *                                                1.5% max loss
 *   Step 5  Take-profit                        — 1:2 via Fib extension, 1:3
 *                                                extended, structural exit,
 *                                                breakeven then ATR trail
 *
 * The two earlier versions (v4.0 Elite, v3 review) are used ONLY where the
 * hybrid is silent: the step weights that build the score, the per-asset
 * position-size bands, and the regime adjustments.
 *
 * PRINCIPLE 1 OF THE HYBRID IS "no volume dependency", and this file honours
 * it literally: not one gate requires a volume feed. Where the hybrid says to
 * read a footprint delta column or order-book depth, the structural stand-in
 * it endorses is used instead, and it is labelled "estimated" everywhere a
 * visitor can see it:
 *
 *   Delta / footprint   each bar's close location in its range (CLV). This is
 *                       the standard estimator with no tape. NOT tape delta.
 *   Order block         unfilled fair-value gaps (liquidity voids) beyond entry.
 *   Iceberg / L2 depth  genuinely unavailable. Not estimated, not scored, and
 *                       the page says so.
 *
 * The score is a weighted checklist total, nothing more. It is published as a
 * "score", never as a probability or a confidence, because the sum of hand-set
 * weights carries no statistical meaning until it has been measured.
 *
 * Server-side only: direct web access returns 404.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(404);
    exit('Not found');
}

require_once __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/tl_engine.php';   // zones + trend lines for the Bounce mode

const DD_DIR       = T_DATA . '/daydrive';
const DD_CACHE_TTL = 90;     // a built row stays fresh this long
const DD_SCAN_TTL  = 45;     // the whole payload stays fresh this long
const DD_DEEP_MAX  = 10;     // symbols per request allowed to fetch fresh 5m bars
const DD_BUDGET    = 22;     // wall clock for one scan step
const DD_STEP1_MIN = 23.0;   // Step 1 score needed before Step 2 runs
const DD_VA_PCT    = 0.70;
const DD_BUCKETS   = 48;
const DD_EXCLUDE   = ['MKR-USD'];   // owner's standing rule: never shortlist Maker

/** Hybrid thresholds, all from the Volume-Free edition. */
const DD_T = [
    'rsi_daily'   => 50.0,   // Step 1: Daily RSI < 50 (mirrored for CALL)
    'rsi_entry'   => 60.0,   // Step 3: 5M RSI < 60
    'bb_compress' => 25.0,   // Step 2: BB width must sit above the 25th percentile
    'vwap_touch'  => 2,      // Step 1 / Step 3: "2+ touches"
    'choch_pivot' => 2,      // Step 3: 2-bar pivot
    'sl_atr_alt'  => 1.2,    // Step 4: alternative stop
    'partial'     => 50,     // Step 4: 50% off at 1:1
    'max_loss'    => 1.5,    // Step 4: 1.5% of account
    'tp_initial'  => 2.0,    // Step 5: 1:2
    'tp_extended' => 3.0,    // Step 5: 1:3 on clear structural rejection
    'void_atr'    => 0.3,    // a liquidity void counts as significant above this
    'foot_share'  => 0.70,   // "aggressive asks dominating" as a CLV share
];

/** Step weights (the hybrid gives no numbers; these come from the v4.0 matrix shape). */
const DD_W = [
    'weekly' => 12.0, 'daily' => 11.0, 'block' => 5.0, 'vwap' => 4.0, 'fractal' => 3.0,          // Step 1 = 35
    'h4' => 9.0, 'h1_st' => 6.0, 'h1_bb' => 5.0, 'h1_delta' => 3.0, 'h1_fib' => 2.0,             // Step 2 = 25
    'choch' => 18.0, 'sweep' => 10.0, 'foot' => 6.0, 'rsi' => 5.0, 'time' => 1.0,                // Step 3 = 40
];
const DD_STEP1 = ['weekly', 'daily', 'block', 'vwap', 'fractal'];
const DD_STEP2 = ['h4', 'h1_st', 'h1_bb', 'h1_delta', 'h1_fib'];
const DD_STEP3 = ['choch', 'sweep', 'foot', 'rsi', 'time'];

function dd_dir(): void { if (!is_dir(DD_DIR)) @mkdir(DD_DIR, 0755, true); }
function dd_f(?float $v, int $d = 5): ?float { return $v === null || !is_finite($v) ? null : round($v, $d); }
function dd_1(float $x): float { return round($x, 1); }
function dd_log(string $where, string $msg): void {
    @file_put_contents(T_DATA . '/errors.log', gmdate('Y-m-d H:i:s') . " [dd:$where] " . str_replace("\n", ' ', $msg) . "\n", FILE_APPEND | LOCK_EX);
}

function dd_class(string $sym): string {
    $ex = t_exchange($sym);
    if ($ex === 'CRYPTO') return 'crypto';
    if ($ex === 'FX') return 'forex';
    return 'other';
}

/**
 * TradingView symbol code, same mapping the Tier-1 scanner shows.
 * Ported verbatim from t_tv_code() in torus_report.php (itself the PHP port of
 * CP.tvSymbol in cp.js). Kept as a local copy so this page does not have to pull
 * in torus_report.php, which drags torus_tier2 and torus_study with it. If the
 * canonical mapping changes, change it here too.
 */
function dd_tv(string $s): string {
    static $I = ['^NSEI' => 'NSE:NIFTY', '^NSEBANK' => 'NSE:BANKNIFTY', '^BSESN' => 'BSE:SENSEX', 'NIFTY_FIN_SERVICE.NS' => 'NSE:CNXFINANCE', 'NIFTY_MID_SELECT.NS' => 'NSE:NIFTY_MID_SELECT', '^CNXIT' => 'NSE:CNXIT', '^CNXAUTO' => 'NSE:CNXAUTO', '^CNXPHARMA' => 'NSE:CNXPHARMA', '^CNXFMCG' => 'NSE:CNXFMCG', '^CNXMETAL' => 'NSE:CNXMETAL', '^CNXREALTY' => 'NSE:CNXREALTY', '^CNXENERGY' => 'NSE:CNXENERGY', '^CNXPSUBANK' => 'NSE:CNXPSUBANK', '^GSPC' => 'SP:SPX', '^NDX' => 'NASDAQ:NDX', '^IXIC' => 'NASDAQ:IXIC', '^DJI' => 'DJ:DJI', '^RUT' => 'TVC:RUT', '^GSPTSE' => 'TSX:TSX', '^FTSE' => 'TVC:UKX', '^GDAXI' => 'XETR:DAX', '^FCHI' => 'EURONEXT:PX1', '^AEX' => 'EURONEXT:AEX', '^SSMI' => 'SIX:SMI', 'FTSEMIB.MI' => 'TVC:FTSEMIB', '^IBEX' => 'BME:IBC', '^STOXX50E' => 'TVC:SX5E', '^N225' => 'TVC:NI225', '^HSI' => 'TVC:HSI', '000001.SS' => 'SSE:000001', '000300.SS' => 'SSE:000300', '399001.SZ' => 'SZSE:399001', '^KS11' => 'KRX:KOSPI', '^TWII' => 'TWSE:TAIEX', '^STI' => 'TVC:STI', '^AXJO' => 'ASX:XJO', '^BVSP' => 'BMFBOVESPA:IBOV', '^MXX' => 'BMV:ME', '^TASI.SR' => 'TADAWUL:TASI', 'DX-Y.NYB' => 'TVC:DXY', 'ES=F' => 'CME_MINI:ES1!', 'NQ=F' => 'CME_MINI:NQ1!', 'YM=F' => 'CBOT_MINI:YM1!', 'RTY=F' => 'CME_MINI:RTY1!', 'NKD=F' => 'CME:NKD1!', 'ZN=F' => 'CBOT:ZN1!', 'ZB=F' => 'CBOT:ZB1!', 'GC=F' => 'COMEX:GC1!', 'SI=F' => 'COMEX:SI1!', 'HG=F' => 'COMEX:HG1!', 'PL=F' => 'NYMEX:PL1!', 'PA=F' => 'NYMEX:PA1!', 'CL=F' => 'NYMEX:CL1!', 'BZ=F' => 'NYMEX:BB1!', 'NG=F' => 'NYMEX:NG1!', 'HO=F' => 'NYMEX:HO1!', 'RB=F' => 'NYMEX:RB1!', 'ZC=F' => 'CBOT:ZC1!', 'ZS=F' => 'CBOT:ZS1!', 'ZW=F' => 'CBOT:ZW1!', 'KC=F' => 'ICEUS:KC1!', 'SB=F' => 'ICEUS:SB1!', 'CT=F' => 'ICEUS:CT1!', 'CC=F' => 'ICEUS:CC1!', 'LE=F' => 'CME:LE1!', 'HE=F' => 'CME:HE1!',
        // PAXG-USD is the site's spot XAUUSD data source (owner, 6 Oct 2026), so its
        // chart is spot gold, not the token. Matches CP.tvSymbol on Tier 1.
        'PAXG-USD' => 'OANDA:XAUUSD'];
    static $X = ['.NS' => 'NSE', '.BO' => 'BSE', '.TO' => 'TSX', '.L' => 'LSE', '.DE' => 'XETR', '.PA' => 'EURONEXT', '.AS' => 'EURONEXT', '.SW' => 'SIX', '.MI' => 'MIL', '.MC' => 'BME', '.CO' => 'OMXCOP', '.ST' => 'OMXSTO', '.T' => 'TSE', '.HK' => 'HKEX', '.SS' => 'SSE', '.SZ' => 'SZSE', '.KS' => 'KRX', '.TW' => 'TWSE', '.SI' => 'SGX', '.AX' => 'ASX', '.SA' => 'BMFBOVESPA', '.MX' => 'BMV', '.SR' => 'TADAWUL', '.JO' => 'JSE'];
    if (isset($I[$s])) return $I[$s];
    if (str_starts_with($s, 'MCX:')) return $s . '1!';
    if (str_ends_with($s, '=X')) return 'FX_IDC:' . substr($s, 0, -2);
    if (str_ends_with($s, '-USD')) return 'CRYPTO:' . preg_replace('/\d+$/', '', substr($s, 0, -4)) . 'USD';
    $i = strrpos($s, '.');
    if ($i && isset($X[substr($s, $i)])) { $base = str_replace('-', '_', substr($s, 0, $i)); if (str_ends_with($s, '.HK')) $base = (string)(int)$base; return $X[substr($s, $i)] . ':' . $base; }
    return preg_replace('/-/', '.', $s, 1);
}

/** One pip / tick for the entry offset in Step 3. */
function dd_tick(string $sym, float $px): array {
    $cls = dd_class($sym);
    if ($cls === 'forex') {
        $pip = (str_contains($sym, 'JPY') || $px > 20) ? 0.01 : 0.0001;
        return [$pip, '1 pip'];
    }
    return [$px * 0.0005, '0.05%'];   // crypto and the rest: a proportional offset
}

// ===========================================================================
// Indicators the hybrid adds on top of what torus_engine already has
// ===========================================================================
/** Kaufman Adaptive Moving Average (Step 2: "4H KAMA bearish"). */
function dd_kama(array $c, int $er = 10, int $fast = 2, int $slow = 30): array {
    $n = count($c);
    if ($n === 0) return [];
    $sf = 2 / ($fast + 1); $ss = 2 / ($slow + 1);
    $k = [(float)$c[0]];
    for ($i = 1; $i < $n; $i++) {
        if ($i < $er) { $k[$i] = (float)$c[$i]; continue; }
        $chg = abs($c[$i] - $c[$i - $er]);
        $vol = 0.0;
        for ($j = $i - $er + 1; $j <= $i; $j++) $vol += abs($c[$j] - $c[$j - 1]);
        $e = $vol > 0 ? $chg / $vol : 0.0;
        $sc = ($e * ($sf - $ss) + $ss) ** 2;
        $k[$i] = $k[$i - 1] + $sc * ($c[$i] - $k[$i - 1]);
    }
    return $k;
}

/** Bollinger width percentile over the last $look bars (Step 2: "not in extreme compression"). */
function dd_bb_width_pct(array $c, int $look = 100): ?float {
    [$m, $u, $l] = t_bb($c, 20, 2.0);
    $w = [];
    $n = count($c);
    for ($i = max(0, $n - $look); $i < $n; $i++) {
        if (!isset($m[$i]) || $m[$i] === null || $m[$i] == 0) continue;
        $w[] = ((float)$u[$i] - (float)$l[$i]) / (float)$m[$i];
    }
    if (count($w) < 20) return null;
    $cur = (float)end($w);
    $s = $w; sort($s);
    $below = 0;
    foreach ($s as $x) if ($x < $cur) $below++;
    return $below / count($s) * 100;
}

/** RSI series (Wilder), matching t_rsi_last's smoothing. */
function dd_rsi_s(array $c, int $p = 14): array {
    $up = [0.0]; $dn = [0.0]; $n = count($c);
    for ($i = 1; $i < $n; $i++) { $d = $c[$i] - $c[$i - 1]; $up[] = $d > 0 ? $d : 0.0; $dn[] = $d < 0 ? -$d : 0.0; }
    $au = t_ewm($up, 1 / $p); $ad = t_ewm($dn, 1 / $p); $o = [];
    for ($i = 0; $i < $n; $i++) $o[] = $ad[$i] == 0 ? ($au[$i] > 0 ? 100.0 : 50.0) : 100 - 100 / (1 + $au[$i] / $ad[$i]);
    return $o;
}

/**
 * 3-bar pivots in [$from,$to): list of [index, price, +1 high | -1 low].
 * Strict on the left, tolerant on the right, so a plateau of equal highs still
 * registers one pivot at its start. Thin and tick-bound symbols tie constantly,
 * and requiring a strict break on both sides loses their structure entirely.
 */
function dd_pivots(array $F, int $from, int $to): array {
    $p = [];
    for ($i = max(1, $from + 1); $i < min($to, count($F['c'])) - 1; $i++) {
        $h = (float)$F['h'][$i]; $l = (float)$F['l'][$i];
        if ($h > (float)$F['h'][$i - 1] && $h >= (float)$F['h'][$i + 1]) $p[] = [$i, $h, 1];
        elseif ($l < (float)$F['l'][$i - 1] && $l <= (float)$F['l'][$i + 1]) $p[] = [$i, $l, -1];
    }
    return $p;
}

/**
 * Step 1 "Fractal Geometry — 5-wave rejection pattern at key levels".
 * Five alternating swings whose final swing is on our side and sits at the
 * window's extreme: a completed push that has just been rejected.
 */
function dd_fractal5(array $F, int $from, int $to, int $sg, float $atr): array {
    // zigzag: two highs (or two lows) in a row keep only the more extreme one, so the swings alternate
    $z = [];
    foreach (dd_pivots($F, $from, $to) as $q) {
        $k = count($z) - 1;
        if ($k >= 0 && $z[$k][2] === $q[2]) { if ($q[2] > 0 ? $q[1] >= $z[$k][1] : $q[1] <= $z[$k][1]) $z[$k] = $q; }
        else $z[] = $q;
    }
    $p = $z;
    if (count($p) < 5) return [false, 'fewer than 5 swings'];
    $last = array_slice($p, -5);
    for ($i = 1; $i < 5; $i++) if ($last[$i][2] === $last[$i - 1][2]) return [false, 'swings not alternating'];
    $want = $sg < 0 ? 1 : -1;                       // PUT wants the last swing to be a high
    if ($last[4][2] !== $want) return [false, '5-wave incomplete'];
    $ext = null;
    for ($i = $from; $i < min($to, count($F['c'])); $i++) {
        $v = $sg < 0 ? (float)$F['h'][$i] : (float)$F['l'][$i];
        $ext = $ext === null ? $v : ($sg < 0 ? max($ext, $v) : min($ext, $v));
    }
    if ($ext === null || $atr <= 0) return [false, 'no reference extreme'];
    if (abs($last[4][1] - $ext) > 0.5 * $atr) return [false, '5-wave away from the extreme'];
    return [true, '5-wave rejection at the extreme'];
}

/**
 * Hidden (reverse) divergence, Step 3.
 * Bearish: price makes a LOWER high while RSI makes a HIGHER high.
 * Bullish: price makes a HIGHER low while RSI makes a LOWER low.
 */
function dd_hidden_div(array $F, array $R, int $sg, int $look = 40): bool {
    $n = count($F['c']);
    if ($n < $look + 5 || count($R) < $n) return false;
    $a = $n - $look; $half = (int)($look / 2);
    $pick = function (int $s, int $e) use ($F, $R, $sg) {
        $bi = null;
        for ($i = $s; $i < $e; $i++) {
            if ($bi === null) { $bi = $i; continue; }
            $v = $sg < 0 ? (float)$F['h'][$i] : (float)$F['l'][$i];
            $b = $sg < 0 ? (float)$F['h'][$bi] : (float)$F['l'][$bi];
            if ($sg < 0 ? $v > $b : $v < $b) $bi = $i;
        }
        return $bi === null ? null : [$sg < 0 ? (float)$F['h'][$bi] : (float)$F['l'][$bi], (float)$R[$bi]];
    };
    $x = $pick($a, $a + $half); $y = $pick($a + $half, $n);
    if (!$x || !$y) return false;
    return $sg < 0 ? ($y[0] < $x[0] && $y[1] > $x[1]) : ($y[0] > $x[0] && $y[1] < $x[1]);
}

/** Regular divergence, used by the daily gate. */
function dd_rsi_div(array $F, array $R, int $sg, int $look = 40): bool {
    $n = count($F['c']);
    if ($n < $look + 5 || count($R) < $n) return false;
    $a = $n - $look; $half = (int)($look / 2);
    $pick = function (int $s, int $e) use ($F, $R, $sg) {
        $bi = null;
        for ($i = $s; $i < $e; $i++) {
            if ($bi === null) { $bi = $i; continue; }
            $v = $sg < 0 ? (float)$F['h'][$i] : (float)$F['l'][$i];
            $b = $sg < 0 ? (float)$F['h'][$bi] : (float)$F['l'][$bi];
            if ($sg < 0 ? $v > $b : $v < $b) $bi = $i;
        }
        return $bi === null ? null : [$sg < 0 ? (float)$F['h'][$bi] : (float)$F['l'][$bi], (float)$R[$bi]];
    };
    $x = $pick($a, $a + $half); $y = $pick($a + $half, $n);
    if (!$x || !$y) return false;
    return $sg < 0 ? ($y[0] > $x[0] && $y[1] < $x[1]) : ($y[0] < $x[0] && $y[1] > $x[1]);
}

// ===========================================================================
// Profile, VWAP, flow estimates — none of these require a volume feed
// ===========================================================================
/** POC / VAH / VAL. Volume profile when the feed carries volume, TPO profile when not. */
function dd_profile(array $F, int $from, int $to): ?array {
    $lo = INF; $hi = -INF; $idx = []; $vol = 0.0;
    foreach ($F['t'] as $i => $t) {
        if ($t < $from || $t >= $to) continue;
        $l = (float)$F['l'][$i]; $h = (float)$F['h'][$i];
        if (!is_finite($l) || !is_finite($h) || $h < $l) continue;
        $lo = min($lo, $l); $hi = max($hi, $h); $idx[] = $i; $vol += (float)($F['v'][$i] ?? 0);
    }
    if (count($idx) < 6 || !is_finite($lo) || !is_finite($hi) || $hi <= $lo) return null;
    $mode = $vol > 0 ? 'volume' : 'tpo';
    $n = DD_BUCKETS; $w = ($hi - $lo) / $n; $b = array_fill(0, $n, 0.0);
    foreach ($idx as $i) {
        $a = (int)max(0, min($n - 1, floor(((float)$F['l'][$i] - $lo) / $w)));
        $z = (int)max(0, min($n - 1, floor(((float)$F['h'][$i] - $lo) / $w)));
        $unit = ($mode === 'volume' ? (float)($F['v'][$i] ?? 0) : 1.0) / ($z - $a + 1);
        for ($k = $a; $k <= $z; $k++) $b[$k] += $unit;
    }
    $tot = array_sum($b);
    if ($tot <= 0) return null;
    $pk = 0; for ($k = 1; $k < $n; $k++) if ($b[$k] > $b[$pk]) $pk = $k;
    $acc = $b[$pk]; $a = $pk; $z = $pk; $need = $tot * DD_VA_PCT;
    while ($acc < $need && ($a > 0 || $z < $n - 1)) {
        $up = $z < $n - 1 ? $b[$z + 1] : -1.0;
        $dn = $a > 0 ? $b[$a - 1] : -1.0;
        if ($up >= $dn) { $z++; $acc += $up; } else { $a--; $acc += $dn; }
    }
    $mid = fn(int $k): float => $lo + ($k + 0.5) * $w;
    return ['poc' => $mid($pk), 'vah' => $mid($z), 'val' => $mid($a), 'mode' => $mode];
}

/** Session VWAP; falls back to the time-price average when the feed has no volume. */
function dd_vwap(array $F, int $from, int $to): ?array {
    $pv = 0.0; $v = 0.0; $tp = 0.0; $c = 0;
    foreach ($F['t'] as $i => $t) {
        if ($t < $from || $t >= $to) continue;
        $typ = ((float)$F['h'][$i] + (float)$F['l'][$i] + (float)$F['c'][$i]) / 3;
        $q = (float)($F['v'][$i] ?? 0);
        $pv += $typ * $q; $v += $q; $tp += $typ; $c++;
    }
    if (!$c) return null;
    return $v > 0 ? ['value' => $pv / $v, 'mode' => 'vwap'] : ['value' => $tp / $c, 'mode' => 'tpa'];
}

/** Cumulative delta ESTIMATE from close location, -1..+1. Negative = sellers. */
function dd_delta(array $F, int $from, int $to): ?float {
    $num = 0.0; $den = 0.0; $c = 0;
    foreach ($F['t'] as $i => $t) {
        if ($t < $from || $t >= $to) continue;
        $h = (float)$F['h'][$i]; $l = (float)$F['l'][$i]; $cl = (float)$F['c'][$i];
        $r = $h - $l;
        $clv = $r > 0 ? (2 * ($cl - $l) / $r - 1) : 0.0;
        $w = (float)($F['v'][$i] ?? 0);
        if ($w <= 0) $w = 1.0;                      // no volume: every bar counts the same
        $num += $clv * $w; $den += $w; $c++;
    }
    if ($c < 4 || $den <= 0) return null;
    return max(-1.0, min(1.0, $num / $den));
}

/** Footprint ESTIMATE for one bar: share of the bar on our side, 0..1. */
function dd_foot_share(array $F, int $i, int $sg): float {
    $h = (float)$F['h'][$i]; $l = (float)$F['l'][$i]; $c = (float)$F['c'][$i];
    $r = $h - $l;
    if ($r <= 0) return 0.5;
    $buy = ($c - $l) / $r;
    return $sg > 0 ? $buy : 1.0 - $buy;
}

/** Touches of $level inside [$from,$to). Returns [count, spanSeconds]. */
function dd_touches(array $F, int $from, int $to, float $level, float $tol): array {
    $ts = [];
    foreach ($F['t'] as $i => $t) {
        if ($t < $from || $t >= $to) continue;
        if ((float)$F['l'][$i] <= $level + $tol && (float)$F['h'][$i] >= $level - $tol) $ts[] = (int)$t;
    }
    if (!$ts) return [0, 0];
    return [count($ts), (int)(end($ts) - $ts[0])];
}

/**
 * Step 1 "Order Block Analysis — no significant liquidity voids below entry".
 * Unfilled fair-value gaps beyond entry on our side invite a stop hunt through
 * thin price, so the gate wants NONE larger than DD_T['void_atr'] x ATR.
 */
function dd_voids(array $F, int $from, int $to, int $sg, float $entry, float $atr): array {
    if ($atr <= 0) return [false, 0, 'no ATR'];
    $n = min($to, count($F['c']));
    $worst = 0.0; $cnt = 0;
    for ($i = max($from, 2); $i < $n; $i++) {
        // Three-bar imbalance: the band between bar i-2 and bar i that only bar
        // i-1 passed through. The band alone is not enough — a trend that simply
        // outruns its own bar ranges produces one on every window — so bar i-1
        // must be a genuine displacement candle, much larger than its neighbours.
        $lo = $sg < 0 ? (float)$F['h'][$i] : (float)$F['h'][$i - 2];
        $hi = $sg < 0 ? (float)$F['l'][$i - 2] : (float)$F['l'][$i];
        $band = $hi - $lo;
        if ($band <= 0) continue;

        $a = max(0, $i - 11); $rs = [];
        for ($k = $a; $k < $i - 1; $k++) $rs[] = (float)$F['h'][$k] - (float)$F['l'][$k];
        $avg = $rs ? array_sum($rs) / count($rs) : 0.0;
        $rng = (float)$F['h'][$i - 1] - (float)$F['l'][$i - 1];
        if ($avg <= 0 || $rng < 1.5 * $avg) continue;    // ordinary drift, not displacement

        $mid = ($lo + $hi) / 2;
        if ($sg < 0 ? $mid > $entry : $mid < $entry) continue;   // only voids beyond entry matter
        // traded back through later?
        $back = false;
        for ($j = $i + 1; $j < $n; $j++) {
            if ($sg < 0 ? (float)$F['h'][$j] >= $hi : (float)$F['l'][$j] <= $lo) { $back = true; break; }
        }
        if ($back) continue;
        $cnt++; $worst = max($worst, $band / $atr);
    }
    $ok = $worst < DD_T['void_atr'];
    return [$ok, $cnt, $ok ? ($cnt ? 'voids present but small' : 'no voids') : 'void ' . round($worst, 2) . 'x ATR'];
}

/** Nearest higher-timeframe order block beyond entry — Step 5's structural exit. */
function dd_block_exit(array $fr, int $sg, float $entry): ?float {
    $best = null;
    foreach (['4H', '1H'] as $tf) {
        $F = $fr[$tf] ?? null;
        if (!$F || count($F['c']) < 30) continue;
        $n = count($F['c']);
        foreach (dd_pivots($F, max(0, $n - 120), $n) as [$i, $pr, $kind]) {
            if ($kind !== ($sg < 0 ? -1 : 1)) continue;          // a short exits into a prior swing low
            if ($sg < 0 ? $pr >= $entry : $pr <= $entry) continue;
            if ($best === null || ($sg < 0 ? $pr > $best : $pr < $best)) $best = $pr;
        }
    }
    return $best;
}

// ===========================================================================
// Shared context, fetched at most once per 10 minutes
// ===========================================================================
function dd_context(): array {
    dd_dir();
    $p = DD_DIR . '/ctx.json';
    $c = t_read_json($p, null);
    if (is_array($c) && t_now() - (int)($c['at'] ?? 0) < 600) return $c;
    $vix = null;
    try {
        [$f] = t_parse(t_fetch(['v' => t_url('^VIX', '1mo', '1d')])['v'] ?? null);
        if ($f && !empty($f['c'])) $vix = (float)end($f['c']);
    } catch (Throwable $e) { /* optional */ }
    $btc = null;
    $bf = t_load_frames('BTC-USD');
    if ($bf && !empty($bf['1D']['c'])) {
        $A = t_atr($bf['1D'], 14); $px = (float)end($bf['1D']['c']);
        if ($px > 0) $btc = (float)end($A) / $px * 100;
    }
    $c = ['at' => t_now(), 'vix' => $vix === null ? null : round($vix, 2), 'btc_atr_pct' => $btc === null ? null : round($btc, 2)];
    t_write_json($p, $c);
    return $c;
}

// ===========================================================================
// Step 1 — Macro structure (Weekly / Daily)
// ===========================================================================
function dd_step1(array $fr, int $sg, array $F5, int $cs, int $ce, float $px): array {
    $g = [];
    $W = $fr['1W']; $D = $fr['1D'];

    // Weekly: price vs 20 EMA AND the last two weeks closed against the trade
    $ok = false; $why = [];
    if (count($W['c']) >= 22) {
        $e = t_ema($W['c'], 20); $i = count($W['c']) - 1;
        $side = $sg < 0 ? $px < (float)end($e) : $px > (float)end($e);
        $two = $sg < 0
            ? ((float)$W['c'][$i] < (float)$W['o'][$i] && (float)$W['c'][$i - 1] < (float)$W['o'][$i - 1])
            : ((float)$W['c'][$i] > (float)$W['o'][$i] && (float)$W['c'][$i - 1] > (float)$W['o'][$i - 1]);
        $ok = $side && $two;
        if ($side) $why[] = '20 EMA';
        if ($two) $why[] = '2 weeks closed ' . ($sg < 0 ? 'down' : 'up');
        if (!$two) $why[] = 'weeks not aligned';
    } else { $why[] = 'short weekly history'; }
    $g['weekly'] = ['ok' => $ok, 'why' => $why];

    // Daily: BB(20,2) wick rejected AND RSI past 50
    $ok = false; $why = [];
    if (count($D['c']) >= 60) {
        [, $u, $l] = t_bb($D['c'], 20, 2.0);
        $R = dd_rsi_s($D['c'], 14); $rsi = (float)end($R);
        $n = count($D['c']) - 1;
        $wick = false;
        foreach ([$n, $n - 1] as $i) {
            if (!isset($u[$i]) || $u[$i] === null) continue;
            // wick pierced the band and the bar closed back inside it
            if ($sg < 0 && (float)$D['h'][$i] > (float)$u[$i] && (float)$D['c'][$i] < (float)$u[$i]) $wick = true;
            if ($sg > 0 && (float)$D['l'][$i] < (float)$l[$i] && (float)$D['c'][$i] > (float)$l[$i]) $wick = true;
        }
        $rOk = $sg < 0 ? $rsi < DD_T['rsi_daily'] : $rsi > (100 - DD_T['rsi_daily']);
        $div = dd_rsi_div($D, $R, $sg, 40);
        $ok = $wick && ($rOk || $div);
        if ($wick) $why[] = 'BB wick rejected';
        else $why[] = 'no BB wick';
        $why[] = 'RSI ' . round($rsi);
        if ($div) $why[] = 'divergence';
    } else { $why[] = 'short daily history'; }
    $g['daily'] = ['ok' => $ok, 'why' => $why];

    $A5 = !empty($F5['t']) ? t_atr($F5, 14) : [];
    $a5 = $A5 ? (float)end($A5) : 0.0;
    $from = $cs; $to = $ce ?: ($cs + 86400);
    $n5 = count($F5['t'] ?? []);

    // Order block: no significant unfilled liquidity void beyond entry
    $ok = false; $why = [];
    if ($n5 >= 20 && $a5 > 0) {
        [$ok, $cnt, $note] = dd_voids($F5, 0, $n5, $sg, $px, $a5);
        $why[] = $note . ($cnt ? " ($cnt)" : '');
    } else { $why[] = 'no intraday bars'; }
    $g['block'] = ['ok' => $ok, 'why' => $why, 'estimated' => true];

    // VWAP interaction: price the right side of VWAP with 2+ touches
    $ok = false; $why = []; $vw = null;
    if ($n5 >= 20) {
        $vw = dd_vwap($F5, $from, $to);
        if ($vw && $a5 > 0) {
            [$tc] = dd_touches($F5, $from, $to, $vw['value'], 0.15 * $a5);
            $side = $sg < 0 ? $px < $vw['value'] : $px > $vw['value'];
            $ok = $side && $tc >= DD_T['vwap_touch'];
            $why[] = ($side ? ($sg < 0 ? 'below ' : 'above ') : 'wrong side of ') . ($vw['mode'] === 'vwap' ? 'VWAP' : 'time-price avg');
            $why[] = $tc . ' touch' . ($tc === 1 ? '' : 'es');
        } else { $why[] = 'no VWAP'; }
    } else { $why[] = 'no intraday bars'; }
    $g['vwap'] = ['ok' => $ok, 'why' => $why];

    // Fractal geometry: 5-wave rejection at the extreme
    $ok = false; $why = [];
    if ($n5 >= 30 && $a5 > 0) {
        [$ok, $note] = dd_fractal5($F5, 0, $n5, $sg, $a5);
        $why[] = $note;
    } else { $why[] = 'no intraday bars'; }
    $g['fractal'] = ['ok' => $ok, 'why' => $why];

    $score = 0.0;
    foreach (DD_STEP1 as $k) if (!empty($g[$k]['ok'])) $score += DD_W[$k];
    return ['gates' => $g, 'score' => dd_1($score),
            'pass' => !empty($g['weekly']['ok']) && !empty($g['daily']['ok']),
            'vwap' => $vw ? dd_f($vw['value']) : null, 'vwap_mode' => $vw['mode'] ?? null, 'atr5' => dd_f($a5)];
}

// ===========================================================================
// Step 2 — Trend confirmation (4H / 1H)
// ===========================================================================
function dd_step2(array $fr, int $sg, array $F5, int $cs, int $ce): array {
    $g = [];
    $H4 = $fr['4H']; $H1 = $fr['1H'];

    // 4H: price < 20 EMA AND KAMA turning against price
    $ok = false; $why = [];
    if (count($H4['c']) >= 40) {
        $e = t_ema($H4['c'], 20); $K = dd_kama($H4['c']);
        $p4 = (float)end($H4['c']); $kn = count($K);
        $emaOk = $sg < 0 ? $p4 < (float)end($e) : $p4 > (float)end($e);
        $kamaOk = $kn >= 3 && ($sg < 0 ? ($K[$kn - 1] < $K[$kn - 2] && $p4 < $K[$kn - 1]) : ($K[$kn - 1] > $K[$kn - 2] && $p4 > $K[$kn - 1]));
        $ok = $emaOk && $kamaOk;
        if ($emaOk) $why[] = '20 EMA';
        if ($kamaOk) $why[] = 'KAMA ' . ($sg < 0 ? 'bearish' : 'bullish');
        else $why[] = 'KAMA not aligned';
    } else { $why[] = 'short 4H history'; }
    $g['h4'] = ['ok' => $ok, 'why' => $why];

    // 1H: Supertrend(10,3) against price OR price past the 20 EMA
    $ok = false; $why = [];
    if (count($H1['c']) >= 60) {
        [$dir] = t_supertrend($H1, 10, 3.0);
        $e = t_ema($H1['c'], 20); $p1 = (float)end($H1['c']);
        $stOk = (int)end($dir) === ($sg < 0 ? -1 : 1);
        $emaOk = $sg < 0 ? $p1 < (float)end($e) : $p1 > (float)end($e);
        $ok = $stOk || $emaOk;
        if ($stOk) $why[] = 'Supertrend';
        if ($emaOk) $why[] = '20 EMA';
        if (!$ok) $why[] = 'neither aligned';
    } else { $why[] = 'short 1H history'; }
    $g['h1_st'] = ['ok' => $ok, 'why' => $why];

    // 1H: not in extreme Bollinger compression
    $ok = false; $why = [];
    $bw = count($H1['c']) >= 60 ? dd_bb_width_pct($H1['c'], 100) : null;
    if ($bw !== null) {
        $ok = $bw > DD_T['bb_compress'];
        $why[] = 'BB width ' . round($bw) . 'th pct' . ($ok ? '' : ' — compressed');
    } else { $why[] = 'no BB width'; }
    $g['h1_bb'] = ['ok' => $ok, 'why' => $why];

    // 1H flow: estimated delta on our side
    $ok = false; $why = []; $d1 = null;
    if (!empty($F5['t']) && $cs) {
        $d1 = dd_delta($F5, $cs, $ce ?: ($cs + 86400));
        if ($d1 !== null) {
            $ok = $sg < 0 ? $d1 < -0.2 : $d1 > 0.2;
            $why[] = 'est. delta ' . round($d1, 2) . ($ok ? ' dominant' : ' not dominant');
        } else { $why[] = 'no flow estimate'; }
    } else { $why[] = 'no intraday bars'; }
    $g['h1_delta'] = ['ok' => $ok, 'why' => $why, 'estimated' => true];

    // 1H: Fibonacci extension levels anchored at real swing points
    $ok = false; $why = []; $fibs = [];
    if (count($H1['c']) >= 60) {
        $n = count($H1['c']);
        $pv = dd_pivots($H1, max(0, $n - 90), $n);
        if (count($pv) >= 2) {
            $a = $pv[count($pv) - 2][1]; $b = $pv[count($pv) - 1][1];
            $leg = abs($b - $a);
            if ($leg > 0) {
                $base = $sg < 0 ? min($a, $b) : max($a, $b);
                foreach ([1.272, 1.618] as $x) $fibs[] = $sg < 0 ? $base - ($x - 1) * $leg : $base + ($x - 1) * $leg;
                $ok = true; $why[] = 'extension from the last 2 swings';
            }
        }
        if (!$ok) $why[] = 'no clean swing pair';
    } else { $why[] = 'short 1H history'; }
    $g['h1_fib'] = ['ok' => $ok, 'why' => $why];

    $score = 0.0;
    foreach (DD_STEP2 as $k) if (!empty($g[$k]['ok'])) $score += DD_W[$k];
    return ['gates' => $g, 'score' => dd_1($score),
            'pass' => !empty($g['h4']['ok']) || !empty($g['h1_st']['ok']),
            'delta' => $d1 === null ? null : round($d1, 3), 'bb_width' => $bw === null ? null : round($bw, 1),
            'fibs' => array_map(fn($x) => dd_f($x), $fibs)];
}

// ===========================================================================
// Step 3 — Entry trigger (15M / 5M)
// ===========================================================================
/** "Last 45-90 minutes of the London/NY overlap"; crypto also takes the Asian session. */
function dd_time_ok(string $cls, int $now): array {
    $mins = (int)gmdate('G', $now) * 60 + (int)gmdate('i', $now);
    $prime   = $mins >= 900 && $mins < 990;     // 15:00-16:30 UTC: the overlap's last 90 minutes
    $overlap = $mins >= 750 && $mins < 990;     // 12:30-16:30 UTC
    $asian   = $mins < 480;                     // 00:00-08:00 UTC
    if ($cls === 'crypto') {
        if ($prime) return [true, 'prime window (overlap close)'];
        if ($overlap || $asian) return [true, $overlap ? 'London/NY overlap' : 'Asian session'];
        return [false, 'outside preferred hours'];
    }
    if ($cls === 'forex') {
        if ($prime) return [true, 'prime window (overlap close)'];
        return [$overlap, $overlap ? 'London/NY overlap' : 'outside London/NY overlap'];
    }
    return [true, 'cash session'];
}

/**
 * 2-bar CHoCH: a 2-bar pivot against the trade, then a close through it.
 * Returns [brokenLevel, breakBarIndex, breakClose] for the MOST RECENT break,
 * and only when that break is within $fresh bars of the end.
 *
 * Both of those conditions matter. Taking the first pivot that ever broke
 * returns a level from hours ago, and an entry priced off a stale break sits
 * nowhere near the current stop and target structure — which is exactly how a
 * short ends up with its stop below the entry.
 */
function dd_choch(array $F, int $from, int $to, int $sg, int $fresh = 8): ?array {
    $n = min($to, count($F['c']));
    $piv = [];
    for ($i = $from + 1; $i < $n - 1; $i++) {
        if ($sg < 0) {
            if ((float)$F['l'][$i] < (float)$F['l'][$i - 1] && (float)$F['l'][$i] <= (float)$F['l'][$i + 1]) $piv[] = [$i, (float)$F['l'][$i]];
        } else {
            if ((float)$F['h'][$i] > (float)$F['h'][$i - 1] && (float)$F['h'][$i] >= (float)$F['h'][$i + 1]) $piv[] = [$i, (float)$F['h'][$i]];
        }
    }
    $best = null;
    foreach ($piv as [$pi, $pl]) {
        for ($j = $pi + 2; $j < $n; $j++) {
            $c = (float)$F['c'][$j];
            if ($sg < 0 ? $c < $pl : $c > $pl) {
                if ($best === null || $j > $best[1]) $best = [$pl, $j, $c];
                break;                       // this pivot's break is its first crossing
            }
        }
    }
    if ($best === null) return null;
    if ($best[1] < $n - 1 - $fresh) return null;   // the break has gone stale
    return $best;
}

function dd_step3(string $sym, array $F5, int $cs, int $ce, int $sg, ?float $vwap, float $a5, int $now): array {
    $cls = dd_class($sym);
    $g = [];
    $n5 = count($F5['t']);
    $from = $cs ?: (int)$F5['t'][max(0, $n5 - 78)];
    $to = $ce ?: ($now + 300);
    $ia = 0;
    foreach ($F5['t'] as $i => $t) { if ($t >= $from) { $ia = $i; break; } }

    // CHoCH on the 15m series (the hybrid's entry timeframe), confirmed on 5m
    $G15 = t_resample($F5, 900);
    $ok = false; $why = []; $lvl = null; $entry = null; $offNote = '';
    $ch = (count($G15['c'] ?? []) >= 6) ? dd_choch($G15, 0, count($G15['c']), $sg) : null;
    if ($ch) {
        [$lvl, $bi, $close] = $ch;
        $share = dd_foot_share($G15, $bi, $sg);
        $conf = $share >= 0.5;                        // footprint confirmation, estimated
        $ok = $conf;
        $why[] = '2-bar break of the swing ' . ($sg < 0 ? 'low' : 'high');
        $why[] = 'est. ' . round($share * 100) . '% one-sided';
        [$tick, $offNote] = dd_tick($sym, $close);
        $entry = $close + ($sg < 0 ? $tick : -$tick);  // limit just beyond the CHoCH close
    } else { $why[] = 'no 2-bar break yet'; }
    $g['choch'] = ['ok' => $ok, 'why' => $why, 'level' => dd_f($lvl), 'estimated' => true];

    // Liquidity sweep: 2+ touches at VWAP or the prior swing extreme
    $ok = false; $why = []; $best = 0; $at = null;
    $lv = [];
    if ($vwap !== null) $lv['VWAP'] = $vwap;
    $pv = dd_pivots($F5, $ia, $n5);
    foreach (array_reverse($pv) as [$pi, $pr, $kind]) {
        if ($kind === ($sg < 0 ? 1 : -1)) { $lv['prior swing'] = $pr; break; }
    }
    foreach ($lv as $nm => $x) {
        [$tc, $span] = dd_touches($F5, $from, $to, $x, 0.15 * $a5);
        if ($tc > $best) { $best = $tc; $at = $nm; }
        if ($tc >= DD_T['vwap_touch']) {
            $d = dd_delta($F5, $from, $to);
            if ($d !== null && ($sg < 0 ? $d < 0 : $d > 0)) { $ok = true; $why[] = "$tc touches at $nm, est. delta confirms"; }
            elseif (!$why) $why[] = "$tc touches at $nm, flow not confirming";
        }
    }
    if (!$why) $why[] = $best ? "$best touch at $at" : 'no sweep';
    $g['sweep'] = ['ok' => $ok, 'why' => $why, 'touches' => $best, 'at' => $at, 'estimated' => true];

    // 5M footprint: aggressive asks dominating at the entry level
    $i5 = $n5 - 1;
    $share = dd_foot_share($F5, $i5, $sg);
    $prev = $i5 > 0 ? dd_foot_share($F5, $i5 - 1, $sg) : 0.5;
    $ok = $share >= DD_T['foot_share'] && $prev >= 0.45;
    $g['foot'] = ['ok' => $ok, 'why' => ['est. ' . round($share * 100) . '% one-sided' . ($ok ? '' : ' (needs ' . (int)(DD_T['foot_share'] * 100) . '%)')],
                  'share' => round($share, 3), 'estimated' => true];

    // 5M RSI < 60 (mirrored) OR hidden divergence
    $R5 = dd_rsi_s($F5['c'], 14); $rsi = (float)end($R5);
    $rOk = $sg < 0 ? $rsi < DD_T['rsi_entry'] : $rsi > (100 - DD_T['rsi_entry']);
    $hid = dd_hidden_div($F5, $R5, $sg, 40);
    $g['rsi'] = ['ok' => $rOk || $hid, 'why' => ['RSI ' . round($rsi) . ($hid ? ' + hidden divergence' : ($rOk ? '' : ' — overbought trap'))],
                 'value' => round($rsi, 1)];

    [$tOk, $tWhy] = dd_time_ok($cls, $now);
    $g['time'] = ['ok' => $tOk, 'why' => [$tWhy]];

    $score = 0.0;
    foreach (DD_STEP3 as $k) if (!empty($g[$k]['ok'])) $score += DD_W[$k];
    return ['gates' => $g, 'score' => dd_1($score),
            'pass' => !empty($g['choch']['ok']) || !empty($g['sweep']['ok']),
            'entry' => dd_f($entry), 'entry_note' => $entry === null ? null : 'limit ' . $offNote . ' beyond the CHoCH close',
            'rsi' => round($rsi, 1)];
}

// ===========================================================================
// Steps 4 and 5 — stop, risk, targets
// ===========================================================================
function dd_regime(array $fr): array {
    $D = $fr['1D'];
    [$adx] = t_adx($D, 14);
    $A = t_atr($D, 14);
    $px = (float)end($D['c']);
    $tail = array_slice($A, -60);
    $rel = 1.0;
    if (count($tail) > 10) { $avg = array_sum($tail) / count($tail); if ($avg > 0) $rel = (float)end($A) / $avg; }
    return [
        'trend' => $adx >= 25 ? 'trending' : ($adx < 20 ? 'sideways' : 'mixed'),
        'vol' => $rel >= 1.3 ? 'high' : ($rel <= 0.8 ? 'low' : 'normal'),
        'adx' => round($adx, 1),
        'atr_pct' => $px > 0 ? round((float)end($A) / $px * 100, 2) : null,
        'atr_rel' => round($rel, 2),
    ];
}

function dd_risk(string $sym, array $fr, array $F5, int $sg, float $entry, float $a5, array $reg, array $ctx, array $s2, bool $clean): array {
    $cls = dd_class($sym);

    // Step 4 primary: beyond the 15m swing extreme (a tight structural stop)
    $swing = null;
    $G15 = t_resample($F5, 900);
    if (count($G15['t'] ?? []) >= 4) {
        $w = array_slice($sg < 0 ? $G15['h'] : $G15['l'], -8);
        if ($w) $swing = $sg < 0 ? max($w) : min($w);
    }
    $pri = $swing === null ? null : $swing + ($sg < 0 ? 0.1 * $a5 : -0.1 * $a5);

    // Step 4 alternative: 1.2x ATR from entry, volatility-adjusted
    $mult = DD_T['sl_atr_alt'];
    if ($reg['vol'] === 'high') $mult = 1.6;
    elseif ($reg['vol'] === 'low') $mult = 1.0;
    $alt = $entry - $sg * $mult * $a5;

    // The structural stop leads, but only when it is genuinely a stop: on the far
    // side of entry and not so tight that noise takes it out. Price can run past
    // its own swing, which would leave a short with its stop below the entry —
    // the ATR stop takes over whenever that happens.
    $sl = null; $from = '';
    $priOk = $pri !== null && ($sg < 0 ? $pri > $entry : $pri < $entry) && abs($entry - $pri) >= 0.4 * $a5;
    if ($priOk) { $sl = $pri; $from = '15m swing'; }
    else { $sl = $alt; $from = round($mult, 1) . 'x ATR'; }

    // Hard invariant: a stop never sits on the target side of entry.
    if ($sg < 0 ? $sl <= $entry : $sl >= $entry) {
        $sl = $entry - $sg * max(0.4 * $a5, $entry * 0.0005);
        $from = 'floor';
    }
    $risk = abs($entry - $sl);
    if ($risk <= 0 || !is_finite($risk)) {
        $risk = max(0.4 * $a5, $entry * 0.0005);
        $sl = $entry - $sg * $risk;
        $from = 'floor';
    }

    // Step 5 targets: 1:2 placed on a Fib extension when one sits near it, 1:3 on clean rejection
    $t2 = $entry + $sg * DD_T['tp_initial'] * $risk;
    $snap = null;
    foreach ($s2['fibs'] ?? [] as $f) {
        if ($f === null) continue;
        if ($sg < 0 ? $f > $entry : $f < $entry) continue;
        if (abs($f - $t2) <= 0.8 * $risk) { $snap = (float)$f; break; }
    }
    $tp = [];
    $tp[] = ['label' => 'P', 'price' => dd_f($entry + $sg * $risk), 'rr' => 1.0,
             'note' => DD_T['partial'] . '% off, stop to breakeven'];
    $tp[] = ['label' => 'T1', 'price' => dd_f($snap ?? $t2), 'rr' => round(abs(($snap ?? $t2) - $entry) / $risk, 2),
             'note' => $snap !== null ? 'Fib extension' : '1:2'];
    if ($clean) {
        $tp[] = ['label' => 'T2', 'price' => dd_f($entry + $sg * DD_T['tp_extended'] * $risk), 'rr' => DD_T['tp_extended'],
                 'note' => 'extended — structural rejection'];
    }
    $blk = dd_block_exit($fr, $sg, $entry);

    // Position size: hybrid caps the loss at 1.5%; the band and regime tilt come from v4.0
    $band = $cls === 'crypto' ? [0.2, 0.5] : [0.3, 0.8];
    $f = max(0.0, min(1.0, 1.2 - $reg['atr_rel'] * 0.5));
    $volIdx = $cls === 'crypto' ? ($ctx['btc_atr_pct'] ?? null) : ($ctx['vix'] ?? null);
    if ($volIdx !== null) {
        $hot = $cls === 'crypto' ? ($volIdx >= 4.0) : ($volIdx >= 22.0);
        $calm = $cls === 'crypto' ? ($volIdx <= 2.0) : ($volIdx <= 14.0);
        if ($hot) $f *= 0.7; elseif ($calm) $f = min(1.0, $f * 1.15);
    }
    $size = $band[0] + ($band[1] - $band[0]) * $f;
    if ($reg['trend'] === 'trending') $size *= 1.2;
    elseif ($reg['trend'] === 'sideways') $size *= 0.7;
    $size = min(DD_T['max_loss'], $size);

    $riskPct = $entry > 0 ? $risk / $entry * 100 : null;
    // A day-trade stop this far away is not what the model intends; flag it rather than hide it.
    $wide = $riskPct !== null && $riskPct > ($cls === 'crypto' ? 4.0 : 2.0);

    return [
        'entry' => dd_f($entry), 'sl' => dd_f($sl), 'sl_from' => $from, 'wide' => $wide,
        'sl_alt' => dd_f($alt), 'sl_structural' => dd_f($pri),
        'risk' => dd_f($risk), 'risk_pct' => $riskPct === null ? null : round($riskPct, 2),
        'targets' => $tp, 'block_exit' => dd_f($blk),
        'trail' => 'breakeven after 1:1, then trail with ' . round($mult, 1) . 'x ATR',
        'size_pct' => round($size, 2), 'max_loss' => DD_T['max_loss'],
        'vol_index' => $volIdx, 'vol_index_name' => $cls === 'crypto' ? 'BTC ATR%' : 'VIX',
    ];
}

// ===========================================================================
// One symbol
// ===========================================================================
// ===========================================================================
// Bounce mode (owner, 13 Nov 2026): a reversal off today's low / high AGAINST the
// daily trend (CL=F at its support line + 200 EMA while the daily is bearish).
//   BOUNCE GO    = a fresh 5m change of character away from today's extreme (any reversal counts)
//   BOUNCE READY = price is still at today's extreme and that extreme sits on a zone
// When the extreme sits on 2+ zones / lines the card says "reversed from a strong zone".
// One target only: the nearest level in the way (VWAP, pivot, PDH/PDL, Fib, a trend line), else 1.5 x risk.
// ===========================================================================
function dd_tlrows(): array {
    static $m = null; if ($m !== null) return $m;
    $c = t_read_json(T_DATA . '/tl/_scan6.json', null); $m = [];
    foreach ((is_array($c) ? $c['rows'] ?? [] : []) as $r) if (isset($r['symbol'])) $m[$r['symbol']] = $r;
    return $m;
}
function dd_bounce(string $sym, array $fr, array $F5, int $cs, int $ce, float $px, int $now): ?array {
    $tl = dd_tlrows()[$sym] ?? null; $mtf = $tl['mtf']['d'] ?? null;
    if (!is_array($mtf) || empty($mtf['1D'])) return null;
    $sg = -(int)$mtf['1D']; $call = $sg > 0;                         // against the daily trend
    $D = $fr['1D']; $nD = count($D['c']); $A = t_atr($D, 14); $aD = (float)end($A); if ($aD <= 0) return null;
    // today's 5m bars
    $ia = null; $n5 = count($F5['t'] ?? []);
    for ($i = 0; $i < $n5; $i++) if ((int)$F5['t'][$i] >= $cs) { $ia = $i; break; }
    $today = $ia !== null && $n5 - $ia >= 6;
    if ($today) {
        $ix = $ia; for ($i = $ia; $i < $n5; $i++) if ($call ? (float)$F5['l'][$i] < (float)$F5['l'][$ix] : (float)$F5['h'][$i] > (float)$F5['h'][$ix]) $ix = $i;
        $ext = (float)($call ? $F5['l'][$ix] : $F5['h'][$ix]);
        $A5 = t_atr($F5, 14); $a5 = (float)end($A5); if ($a5 <= 0) $a5 = 0.1 * $aD;
    } else { $ix = null; $ext = (float)($call ? $D['l'][$nD - 1] : $D['h'][$nD - 1]); $a5 = 0.1 * $aD; }
    // zones and lines at today's extreme
    $tol = 0.15 * $aD; $hit = [];
    $Z = array_values(array_filter(tl_zones($fr), fn($z) => $z['k'] !== 'Day High' && $z['k'] !== 'Day Low'));
    foreach ($Z as $z) if (abs($z['p'] - $ext) <= $tol) $hit[$z['g'] === 'fib' ? 'Fib' : ($z['g'] === 'pivot' ? 'Pivot' : $z['k'])] = $z['k'];
    foreach (['1W' => 'Weekly', '1D' => 'Daily', '4H' => '4H', '1H' => '1H', '30m' => '30m', '15m' => '15m'] as $k => $nm) {
        $L = $tl['tf'][$k][$call ? 'sup' : 'res'] ?? null;
        if (is_array($L) && (int)$L['n'] >= 2 && abs((float)$L['now'] - $ext) <= $tol) { $hit['line'] = $nm . ' ' . ($call ? 'support' : 'resistance') . ' line'; break; }
    }
    $H = $fr['1H'];
    if (count($H['c']) >= 220) {
        $k2 = 2 / 201; $e = array_sum(array_slice($H['c'], 0, 200)) / 200;
        for ($i = 200, $m = count($H['c']); $i < $m; $i++) $e += ((float)$H['c'][$i] - $e) * $k2;
        if (abs($e - $ext) <= $tol) $hit['ema'] = '200 EMA (1H)';
    }
    $zones = array_values($hit); $strong = count($zones) >= 2;
    $trends = []; foreach (['1W', '1D', '4H', '1H', '30m'] as $k) if (isset($mtf[$k])) $trends[$k] = (int)$mtf[$k] > 0 ? 'up' : 'down';
    $base = ['side' => $call ? 'CALL' : 'PUT', 'extreme' => dd_f($ext), 'zones' => $zones, 'strong' => $strong, 'trends' => $trends];
    if (!$today) return $zones ? $base + ['status' => 'NEED5M'] : null;      // ask the scan for fresh 5m bars
    // has it turned? a fresh 5m change of character after the extreme
    $ch = $ix < $n5 - 2 ? dd_choch($F5, $ix, $n5, $sg, 8) : null;
    $away = ($px - $ext) * $sg;                                        // > 0: price is off the extreme on our side
    if ($ch && $away > 0 && $away <= 3 * $a5) {
        $entry = (float)$ch[2]; $sl = $ext - $sg * 0.15 * $a5; $risk = abs($entry - $sl); if ($risk <= 0) return null;
        $lv = [];
        $vw = dd_vwap($F5, $cs, $ce ?: $cs + 86400); if ($vw) $lv[] = [(float)$vw['value'], 'VWAP'];
        foreach ($Z as $z) $lv[] = [(float)$z['p'], $z['k']];
        foreach ($tl['tf'] ?? [] as $k => $c) { $L = $c[$call ? 'res' : 'sup'] ?? null; if (is_array($L) && (int)$L['n'] >= 2) $lv[] = [(float)$L['now'], $k . ' trend line']; }
        $tp = null;
        foreach ($lv as [$p, $nm]) { $d = ($p - $entry) * $sg; if ($d >= 0.8 * $risk && ($tp === null || $d < ($tp[0] - $entry) * $sg)) $tp = [$p, $nm]; }
        if ($tp === null) $tp = [$entry + $sg * 1.5 * $risk, '1.5 x the risk'];
        return $base + ['status' => 'BOUNCE GO', 'entry' => dd_f($entry), 'sl' => dd_f($sl), 't1' => dd_f($tp[0]), 't1why' => $tp[1],
                        'rr' => round(abs($tp[0] - $entry) / $risk, 1), 'at_bar' => (int)$F5['t'][$ch[1]]];
    }
    if ($zones && abs($px - $ext) <= 1.0 * $a5) return $base + ['status' => 'BOUNCE READY'];
    return null;
}

function dd_status(array $s1, ?array $s2, ?array $s3): string {
    if (!$s1['pass']) return 'WATCH';
    if ($s2 === null || !$s2['pass']) return 'STRUCTURE OK';
    if ($s3 === null) return 'TREND OK';
    if (!$s3['gates']['time']['ok']) return 'OFF-HOURS';
    $full = !empty($s3['gates']['choch']['ok']) && !empty($s3['gates']['foot']['ok']) && !empty($s3['gates']['rsi']['ok']);
    if ($full) return 'DRIVE';
    if ($s3['pass']) return 'ARMED';
    return 'TREND OK';
}

function dd_eval(string $sym, array $row, bool $deep, array $ctx, int $now): ?array {
    if (in_array($sym, DD_EXCLUDE, true)) return null;
    $fr = t_load_frames($sym);
    if (!$fr || count($fr['1D']['c']) < 60 || count($fr['1W']['c']) < 22) return null;

    $cs = (int)($row['cs'] ?? 0); $ce = (int)($row['ce'] ?? 0);
    if (!$cs) { $cs = (int)$fr['1D']['t'][count($fr['1D']['t']) - 1]; $ce = $cs + 86400; }
    $px = (float)($row['price'] ?? 0);
    if ($px <= 0) $px = (float)end($fr['1D']['c']);
    if ($px <= 0) return null;

    $F5 = $fr['5m'] ?? null;
    if ($deep) { $f = t_m5($sym); if ($f && count($f['t']) >= 20) $F5 = $f; }
    if (!is_array($F5) || empty($F5['t'])) $F5 = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];

    // Both sides are scored; the stronger Step 1 side is the one reported.
    $best = null;
    foreach ([-1, 1] as $sg) {
        $s1 = dd_step1($fr, $sg, $F5, $cs, $ce, $px);
        if ($best === null || $s1['score'] > $best['s1']['score']) $best = ['sg' => $sg, 's1' => $s1];
    }
    $sg = $best['sg']; $s1 = $best['s1'];
    $a5 = (float)($s1['atr5'] ?: 0);
    if ($a5 <= 0) $a5 = max($px * 0.002, 1e-9);

    $s2 = null; $s3 = null; $risk = null;
    if ($s1['pass'] && $s1['score'] >= DD_STEP1_MIN) {
        $s2 = dd_step2($fr, $sg, $F5, $cs, $ce);
        if ($s2['pass'] && count($F5['t']) >= 20) {
            $s3 = dd_step3($sym, $F5, $cs, $ce, $sg, $s1['vwap'] === null ? null : (float)$s1['vwap'], $a5, $now);
        }
    }
    $reg = dd_regime($fr);
    if ($s3 !== null) {
        $entry = $s3['entry'] !== null ? (float)$s3['entry'] : $px;
        $clean = !empty($s1['gates']['fractal']['ok']) || !empty($s1['gates']['block']['ok']);
        $risk = dd_risk($sym, $fr, $F5, $sg, $entry, $a5, $reg, $ctx, $s2, $clean);
    }

    $score = $s1['score'] + ($s2['score'] ?? 0) + ($s3['score'] ?? 0);
    $gates = $s1['gates'] + ($s2['gates'] ?? []) + ($s3['gates'] ?? []);
    $status = dd_status($s1, $s2, $s3); $mode = 'trend'; $bo = null;
    try { $bo = dd_bounce($sym, $fr, $F5, $cs, $ce, $px, $now); } catch (Throwable $e) { dd_log('bounce', $sym . ': ' . $e->getMessage()); }
    if ($bo && !in_array($status, ['DRIVE', 'ARMED'], true) && in_array($bo['status'], ['BOUNCE GO', 'BOUNCE READY'], true)) {
        $status = $bo['status']; $mode = 'bounce'; $sg = $bo['side'] === 'CALL' ? 1 : -1;
    }

    return [
        'symbol' => $sym, 'name' => t_name($sym),
        'market' => $row['market'] ?? (t_symbols()[$sym] ?? ''),
        'class' => dd_class($sym), 'tv' => $row['tv'] ?? dd_tv($sym),
        'side' => $sg < 0 ? 'PUT' : 'CALL',
        'price' => dd_f($px),
        'status' => $status, 'mode' => $mode, 'bounce' => $bo,
        'score' => dd_1($score),
        's1' => $s1['score'], 's2' => $s2['score'] ?? null, 's3' => $s3['score'] ?? null,
        'gates' => $gates,
        'vwap' => $s1['vwap'], 'vwap_mode' => $s1['vwap_mode'],
        'delta' => $s2['delta'] ?? null, 'bb_width' => $s2['bb_width'] ?? null,
        'rsi' => $s3['rsi'] ?? null,
        'entry_note' => $s3['entry_note'] ?? null,
        'regime' => $reg, 'risk' => $risk,
        'at' => $now, 'deep' => $deep,
    ];
}

// ===========================================================================
// Scan
// ===========================================================================
function dd_candidates(array $scan, int $now): array {
    $out = [];
    foreach ($scan['rows'] as $sym => $r) {
        if (in_array($sym, DD_EXCLUDE, true)) continue;
        if (!empty($r['choppy'])) continue;
        $st = t_status($sym, $r, $now);
        $live = !empty($st['live']);
        $out[$sym] = ['r' => $r, 'live' => $live,
                      'pri' => ($live ? 1000 : 0) + (int)($r['alignment'] ?? 0) * 50 + (float)($r['strength'] ?? 0)];
    }
    uasort($out, fn($a, $b) => $b['pri'] <=> $a['pri']);
    return $out;
}

function dd_scan(string $mkt = 'all', bool $force = false): array {
    dd_dir();
    $now = t_now();
    $ck = DD_DIR . '/scan.json';
    $c = t_read_json($ck, null);
    if (!$force && is_array($c) && $now - (int)($c['at'] ?? 0) < DD_SCAN_TTL && !empty($c['rows'])) return dd_filter($c, $mkt);

    $ctx = dd_context();
    $scan = t_scan_load();
    $cands = dd_candidates($scan, $now);
    $cacheP = DD_DIR . '/rows.json';
    $cache = t_read_json($cacheP, []);
    if (!is_array($cache)) $cache = [];

    $rows = []; $deep = 0; $seen = 0; $t0 = microtime(true);
    foreach ($cands as $sym => $cd) {
        if (microtime(true) - $t0 > DD_BUDGET) break;
        $seen++;
        $old = $cache[$sym] ?? null;
        if (is_array($old) && $now - (int)($old['at'] ?? 0) < DD_CACHE_TTL) { $rows[$sym] = $old; continue; }
        try {
            // Step 1 runs off cached frames, so it costs nothing. Fresh 5m bars are
            // fetched only for symbols already through Step 1, and only a few per request.
            $r = dd_eval($sym, $cd['r'], false, $ctx, $now);
            if ($r && ($r['status'] !== 'WATCH' || ($r['bounce']['status'] ?? '') === 'NEED5M') && $deep < DD_DEEP_MAX && $cd['live']) {
                $d = dd_eval($sym, $cd['r'], true, $ctx, $now);
                if ($d) { $r = $d; $deep++; }
            }
        } catch (Throwable $e) {
            dd_log('eval', $sym . ': ' . $e->getMessage() . ' @' . $e->getLine());
            continue;
        }
        if ($r) { $rows[$sym] = $r; $cache[$sym] = $r; }
    }
    foreach ($cache as $sym => $r) {
        if (!isset($rows[$sym]) && is_array($r) && $now - (int)($r['at'] ?? 0) < 900) $rows[$sym] = $r;
        if (!is_array($r) || $now - (int)($r['at'] ?? 0) > 3600) unset($cache[$sym]);
    }
    t_write_json($cacheP, $cache);

    $list = array_values($rows);
    usort($list, function ($a, $b) {
        $rank = ['DRIVE' => 0, 'BOUNCE GO' => 1, 'ARMED' => 2, 'BOUNCE READY' => 3, 'TREND OK' => 4, 'STRUCTURE OK' => 5, 'OFF-HOURS' => 6, 'WATCH' => 7];
        $x = $rank[$a['status']] ?? 9; $y = $rank[$b['status']] ?? 9;
        return $x !== $y ? $x <=> $y : (float)$b['score'] <=> (float)$a['score'];
    });

    $cnt = fn(string $s) => count(array_filter($list, fn($r) => $r['status'] === $s));
    $p = [
        'at' => $now, 'rows' => $list,
        'meta' => ['scanned' => $seen, 'deep' => $deep, 'universe' => count($cands),
                   'drive' => $cnt('DRIVE'), 'armed' => $cnt('ARMED'), 'bounce_go' => $cnt('BOUNCE GO'), 'bounce_ready' => $cnt('BOUNCE READY'),
                   'structure' => count(array_filter($list, fn($r) => !in_array($r['status'], ['WATCH', 'BOUNCE GO', 'BOUNCE READY'], true))),
                   'vix' => $ctx['vix'], 'btc_atr_pct' => $ctx['btc_atr_pct'],
                   'took_ms' => (int)round((microtime(true) - $t0) * 1000)],
        'availability' => dd_availability(),
    ];
    t_write_json($ck, $p);
    return dd_filter($p, $mkt);
}

function dd_filter(array $p, string $mkt): array {
    if ($mkt === 'all' || $mkt === '') return $p;
    $p['rows'] = array_values(array_filter($p['rows'], fn($r) => ($r['class'] ?? '') === $mkt));
    return $p;
}

/** What backs every requirement in the hybrid. Shown on the page, verbatim. */
function dd_availability(): array {
    return [
        ['group' => 'Step 1 · Macro', 'item' => 'Weekly 20 EMA and weekly closes, daily Bollinger wick, daily RSI and divergence', 'state' => 'real'],
        ['group' => 'Step 1 · Macro', 'item' => 'VWAP interaction and touch count; session VWAP, or the time-price average where the feed has no volume', 'state' => 'real'],
        ['group' => 'Step 1 · Macro', 'item' => '5-wave fractal rejection, from alternating swing pivots', 'state' => 'real'],
        ['group' => 'Step 1 · Macro', 'item' => 'Order block — read as unfilled liquidity voids (fair-value gaps) beyond entry, not a footprint chart', 'state' => 'estimated'],
        ['group' => 'Step 2 · Trend', 'item' => '4H 20 EMA and KAMA, 1H Supertrend (10, 3), 1H Bollinger compression percentile, Fib extensions from real swings', 'state' => 'real'],
        ['group' => 'Step 2 · Trend', 'item' => '1H delta dominance — estimated from each bar\'s close location, not a footprint delta column', 'state' => 'estimated'],
        ['group' => 'Step 3 · Entry', 'item' => '2-bar CHoCH on 15m, liquidity-sweep touch counts, 5M RSI, hidden divergence, session timing, limit offset', 'state' => 'real'],
        ['group' => 'Step 3 · Entry', 'item' => 'Footprint "aggressive asks dominating" — same close-location estimate', 'state' => 'estimated'],
        ['group' => 'Steps 4-5 · Risk', 'item' => '15m structural stop, 1.2x ATR alternative, 50% at 1:1, 1.5% max loss, Fib-extension targets, higher-timeframe block exit', 'state' => 'real'],
        ['group' => 'Not available', 'item' => 'Iceberg orders and L2 order-book depth need a paid depth feed. They are not estimated and carry no score.', 'state' => 'unavailable'],
    ];
}
