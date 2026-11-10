<?php
/**
 * Trend-line detector                                           chartpulse.io
 *
 * For one symbol, on each timeframe (10m, 1H, 4H, 1D), finds the trend lines the chart is drawing right
 * now: one resistance line across swing highs and one support line across swing lows.
 *
 * How a line is chosen (this is what makes it better than "join the last two pivots"):
 *   - every pair of swing points is tried as a candidate line
 *   - a candidate is thrown away if candles CLOSED through it between its first anchor and the last few
 *     bars (wicks through it are allowed, that is what a touch looks like)
 *   - what is left is scored: touches first, then how long the line has held, then how recent its last
 *     touch is, then how close it is to price -- so a 3-touch line that price is testing now beats a
 *     2-touch line far away
 *   - the last few bars are then read against the winner: holding / being tested / broken
 * Two lines together give the pattern name (channel, wedge, triangle, range).
 *
 * Reads the frame cache the Tier-1 scanner already keeps; only the 10m view may fetch 5m bars (cached 45 s).
 */
declare(strict_types=1);
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/torus_engine.php';

const TL_BARS = 140;     // bars read and drawn per timeframe
const TL_TAIL = 3;       // the last bars are allowed to be beyond the line (that is the "broken" reading)
const TL_TOL = 0.22;     // x ATR: a close this far through the line breaks it
const TL_TOUCH = 0.35;   // x ATR: a swing point this close to the line is a touch
const TL_FAR = 6.0;      // x ATR: a line further than this from price is not shown
const TL_TFS = ['10m' => 600, '30m' => 1800, '1H' => 3600, '4H' => 14400, '1D' => 86400, '1W' => 604800];   // the symbol panel
const TL_SCAN_TFS = ['1W', '1D', '4H', '1H', '30m'];   // the Trend Line Scanner page (owner, 11 Nov 2026)
const TL_SCAN_W = ['1W' => 5, '1D' => 4, '4H' => 3, '1H' => 2, '30m' => 1];
const TL_PIV_MAX = 22;      // newest swing points tried per side (keeps the whole-universe scan fast)
const TL_ROW_TTL = 600;     // a scanned symbol stays fresh this long
const TL_BUDGET = 18;       // wall clock for one scan step

function tl_dec(float $px): int { $a = abs($px); return $a >= 1000 ? 2 : ($a >= 100 ? 2 : ($a >= 1 ? 4 : ($a >= 0.01 ? 5 : 8))); }
function tl_px(float $v, int $d): string { return number_format($v, $d, '.', ','); }

/** Swing highs / lows: higher (lower) than $k bars on the left, not lower (higher) than $k bars on the right. */
function tl_pivots(array $F, int $n, int $k): array {
    $H = []; $L = [];
    for ($i = $k; $i < $n - $k; $i++) {
        $h = $F['h'][$i]; $l = $F['l'][$i]; $ph = true; $pl = true;
        for ($j = 1; $j <= $k; $j++) {
            if ($F['h'][$i - $j] >= $h || $F['h'][$i + $j] > $h) $ph = false;
            if ($F['l'][$i - $j] <= $l || $F['l'][$i + $j] < $l) $pl = false;
            if (!$ph && !$pl) break;
        }
        if ($ph) $H[] = $i;
        if ($pl) $L[] = $i;
    }
    return [$H, $L];
}

/** Best line on one side. $side = +1 resistance (swing highs), -1 support (swing lows). */
function tl_best(array $F, int $n, array $piv, int $side, float $atr): ?array {
    $key = $side > 0 ? 'h' : 'l'; $m = count($piv);
    if ($m < 2 || $atr <= 0) return null;
    $tol = TL_TOL * $atr; $touchTol = TL_TOUCH * $atr; $px = (float)$F['c'][$n - 1];
    $best = null; $minSpan = max(6, (int)round($n * 0.07));
    for ($x = 0; $x < $m - 1; $x++) {
        $a = $piv[$x]; $pa = (float)$F[$key][$a];
        for ($y = $x + 1; $y < $m; $y++) {
            $b = $piv[$y]; if ($b - $a < $minSpan) continue;
            $pb = (float)$F[$key][$b]; $sl = ($pb - $pa) / ($b - $a);
            if (abs($sl) > 0.9 * $atr) continue;                      // too steep to be a trend line
            $now = $pa + $sl * ($n - 1 - $a);
            if (abs($now - $px) > TL_FAR * $atr) continue;            // nowhere near price any more
            $ok = true;
            for ($i = $a + 1; $i < $n - TL_TAIL; $i++) {              // a close through the line kills it
                $v = $pa + $sl * ($i - $a);
                if ($side > 0 ? $F['c'][$i] > $v + $tol : $F['c'][$i] < $v - $tol) { $ok = false; break; }
            }
            if (!$ok) continue;
            $touch = 0; $last = $b; $prev = -99;
            foreach ($piv as $c) {
                if ($c < $a) continue;
                if (abs((float)$F[$key][$c] - ($pa + $sl * ($c - $a))) <= $touchTol && $c - $prev >= 3) { $touch++; $last = $c; $prev = $c; }
            }
            // the latest bars touching the line count as one more (live) touch
            $liveTouch = false;
            for ($i = max($last + 3, $n - 4); $i < $n; $i++) {
                $v = $pa + $sl * ($i - $a);
                if ($side > 0 ? ($F['h'][$i] >= $v - $touchTol && $F['c'][$i] <= $v + $tol) : ($F['l'][$i] <= $v + $touchTol && $F['c'][$i] >= $v - $tol)) { $liveTouch = true; break; }
            }
            if ($liveTouch) { $touch++; $last = $n - 1; }
            if ($touch < 2) continue;
            $score = min($touch, 6) * 3.0 + (($n - 1 - $a) / $n) * 4.0 + ($last / $n) * 3.0 - (abs($now - $px) / $atr) * 0.6;
            if ($best === null || $score > $best['score']) {
                $best = ['a' => $a, 'pa' => $pa, 'b' => $b, 'pb' => $pb, 'slope' => $sl, 'now' => $now,
                         'touches' => $touch, 'score' => $score, 'side' => $side];
            }
        }
    }
    if ($best === null) return null;
    // direction over the window, in ATRs
    $run = $best['slope'] * $n / $atr;
    $best['dir'] = $run > 1.5 ? 'rising' : ($run < -1.5 ? 'falling' : 'flat');
    // state from the last bars
    $c = (float)$F['c'][$n - 1]; $d = ($c - $best['now']) * $side;   // > 0 = price is beyond the line
    $best['state'] = $d > $tol ? 'broken' : (abs($c - $best['now']) <= 0.6 * $atr ? 'testing' : 'holding');
    $best['dist_pct'] = $c > 0 ? round(($best['now'] - $c) / $c * 100, 2) : 0.0;
    return $best;
}

/** Pattern name from the two lines. */
function tl_pattern(?array $R, ?array $S, int $n): array {
    if ($R && $S) {
        $g0 = ($R['pa'] + $R['slope'] * (0 - $R['a'])) - ($S['pa'] + $S['slope'] * (0 - $S['a']));
        $g1 = $R['now'] - $S['now'];
        $conv = $g0 > 0 && $g1 > 0 && $g1 < 0.62 * $g0;
        $r = $R['dir']; $s = $S['dir'];
        if ($r === 'falling' && $s === 'falling') return $conv ? ['Falling wedge', 'down'] : ['Falling channel', 'down'];
        if ($r === 'rising' && $s === 'rising') return $conv ? ['Rising wedge', 'up'] : ['Rising channel', 'up'];
        if ($r === 'falling' && $s === 'rising') return ['Symmetrical triangle', 'squeeze'];
        if ($r === 'flat' && $s === 'rising') return ['Ascending triangle', 'up'];
        if ($r === 'falling' && $s === 'flat') return ['Descending triangle', 'down'];
        if ($r === 'rising' && $s === 'falling') return ['Widening range', 'side'];
        if ($r === 'flat' && $s === 'flat') return ['Sideways range', 'side'];
        if ($r === 'rising') return ['Rising structure', 'up'];
        if ($s === 'falling') return ['Falling structure', 'down'];
        return ['Range', 'side'];
    }
    if ($R) return $R['dir'] === 'falling' ? ['Downtrend line', 'down'] : ($R['dir'] === 'rising' ? ['Rising resistance', 'up'] : ['Flat resistance', 'side']);
    if ($S) return $S['dir'] === 'rising' ? ['Uptrend line', 'up'] : ($S['dir'] === 'falling' ? ['Falling support', 'down'] : ['Flat support', 'side']);
    return ['No clear line', 'none'];
}

/** Plain-English reading of one timeframe. */
function tl_text(string $tf, string $name, ?array $R, ?array $S, float $px, int $d): array {
    $out = [];
    $one = function (array $L, string $what) use ($tf, $px, $d) {
        $lvl = tl_px($L['now'], $d); $gap = abs($L['dist_pct']);
        $s = ucfirst($L['dir']) . " $what line, touched {$L['touches']} times, now at $lvl";
        if ($L['state'] === 'broken') {
            $s .= $L['side'] > 0 ? ". Price has closed ABOVE it: the line is broken to the upside, and it now acts as support on a pullback."
                                 : ". Price has closed BELOW it: the line is broken to the downside, and it now acts as resistance on a bounce.";
        } elseif ($L['state'] === 'testing') {
            $s .= " and price is testing it right now ($gap% away). " . ($L['side'] > 0
                ? "A $tf close above $lvl breaks it; a rejection here keeps sellers in control."
                : "A $tf close below $lvl breaks it; a bounce here keeps buyers in control.");
        } else {
            $s .= ", $gap% " . ($L['side'] > 0 ? 'above' : 'below') . " price. " . ($L['side'] > 0
                ? "Until a $tf candle closes above it, rallies into this line are resistance."
                : "Until a $tf candle closes below it, dips into this line are support.");
        }
        return $s;
    };
    if ($R) $out[] = $one($R, 'resistance');
    if ($S) $out[] = $one($S, 'support');
    if ($R && $S && $R['state'] !== 'broken' && $S['state'] !== 'broken') {
        $w = $R['now'] - $S['now'];
        if ($w > 0 && $px > 0) {
            $pos = ($px - $S['now']) / $w;
            $where = $pos > 0.66 ? 'in the upper third, near resistance' : ($pos < 0.34 ? 'in the lower third, near support' : 'in the middle');
            $out[] = "$name: price is $where of a " . round($w / $px * 100, 2) . "% wide structure.";
        }
    }
    return $out;
}

/** One timeframe. Returns null when there are not enough bars or no line holds. */
function tl_frame(string $tf, array $F, bool $bars = true): ?array {
    $N = count($F['t'] ?? []); if ($N < 40) return null;
    $n = min(TL_BARS, $N); $o = $N - $n; $G = [];
    foreach (['t', 'o', 'h', 'l', 'c'] as $k) $G[$k] = array_map($k === 't' ? 'intval' : 'floatval', array_slice($F[$k], $o, $n));
    $A = t_atr($F, 14); $atr = (float)$A[$N - 1]; if ($atr <= 0) return null;
    [$PH, $PL] = tl_pivots($G, $n, 3);
    if (count($PH) > TL_PIV_MAX) $PH = array_slice($PH, -TL_PIV_MAX);
    if (count($PL) > TL_PIV_MAX) $PL = array_slice($PL, -TL_PIV_MAX);
    $R = tl_best($G, $n, $PH, 1, $atr); $S = tl_best($G, $n, $PL, -1, $atr);
    // a support line above a resistance line is not a structure -- keep the stronger one
    if ($R && $S && $S['now'] >= $R['now'] && $R['state'] !== 'broken' && $S['state'] !== 'broken') { if ($R['score'] >= $S['score']) $S = null; else $R = null; }
    if (!$R && !$S) return null;
    $px = $G['c'][$n - 1]; $d = tl_dec($px);
    [$name, $lean] = tl_pattern($R, $S, $n);
    $pub = function (?array $L) use ($d) {
        if (!$L) return null;
        return ['i1' => $L['a'], 'p1' => round($L['pa'], $d + 1), 'i2' => $L['b'], 'p2' => round($L['pb'], $d + 1),
                'slope' => $L['slope'], 'now' => round($L['now'], $d), 'touches' => $L['touches'],
                'dir' => $L['dir'], 'state' => $L['state'], 'dist_pct' => $L['dist_pct']];
    };
    return ['tf' => $tf, 'pattern' => $name, 'lean' => $lean, 'dec' => $d, 'price' => round($px, $d),
            'res' => $pub($R), 'sup' => $pub($S), 'text' => tl_text($tf, $name, $R, $S, $px, $d)]
        + ($bars ? ['bars' => ['t' => $G['t'], 'o' => $G['o'], 'h' => $G['h'], 'l' => $G['l'], 'c' => $G['c']]] : []);
}

/** All timeframes for one symbol + a one-line summary. Cached 60 s. */
function tl_build(string $sym): ?array {
    $dir = T_DATA . '/tl'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $p = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym) . '.json';
    $c = t_read_json($p, null);
    if (is_array($c) && t_now() - (int)($c['at'] ?? 0) < 60) return $c;
    $fr = t_read_frames($sym); if (!$fr) return null;
    $F5 = t_m5($sym) ?: ($fr['5m'] ?? null);
    $src = [];
    if (is_array($F5) && count($F5['t'] ?? []) >= 80) $src['10m'] = t_resample($F5 + ['v' => array_fill(0, count($F5['t']), 0)], 600);
    if (is_array($F5) && count($F5['t'] ?? []) >= 240) $src['30m'] = t_resample($F5 + ['v' => array_fill(0, count($F5['t']), 0)], 1800);
    $src['1H'] = $fr['1H']; $src['4H'] = t_resample($fr['1H'], 14400); $src['1D'] = $fr['1D'];
    $src['1W'] = t_weekly($fr['1D'], (string)($fr['tz'] ?? 'UTC'));
    $out = []; $lean = ['up' => 0, 'down' => 0, 'squeeze' => 0, 'side' => 0];
    foreach (TL_TFS as $tf => $_) {
        if (empty($src[$tf])) continue;
        try { $x = tl_frame($tf, $src[$tf]); } catch (Throwable $e) { $x = null; }
        if ($x) { $out[] = $x; $lean[$x['lean']] = ($lean[$x['lean']] ?? 0) + 1; }
    }
    $k = count($out);
    $sum = $k === 0 ? 'No clean trend line is holding on any timeframe right now.'
        : ($lean['down'] > $lean['up'] && $lean['down'] >= ($k + 1) / 2 ? "{$lean['down']} of $k timeframes are drawing a falling structure: sellers are in control until a resistance line breaks."
        : ($lean['up'] > $lean['down'] && $lean['up'] >= ($k + 1) / 2 ? "{$lean['up']} of $k timeframes are drawing a rising structure: buyers are in control until a support line breaks."
        : "The timeframes disagree ({$lean['up']} rising, {$lean['down']} falling, " . ($lean['squeeze'] + $lean['side']) . " sideways or squeezing): wait for one side's line to break."));
    $res = ['symbol' => $sym, 'name' => t_name($sym), 'at' => t_now(), 'summary' => $sum, 'frames' => $out];
    t_write_json($p, $res);
    return $res;
}

// ===========================================================================
// Trend Line Scanner: every symbol of the Tier-1 universe on 1W / 1D / 4H / 1H / 30m
// ===========================================================================
function tl_class(string $sym): string { $ex = t_exchange($sym); return $ex === 'CRYPTO' ? 'crypto' : ($ex === 'FX' ? 'forex' : 'other'); }

/** One symbol for the scanner list: per timeframe the pattern and both lines (no bars), plus the events and a rank. */
function tl_row(string $sym, array $row, int $now): ?array {
    $fr = t_read_frames($sym); if (!$fr) return null;
    $src = ['1H' => $fr['1H'], '4H' => t_resample($fr['1H'], 14400), '1D' => $fr['1D'],
            '1W' => t_weekly($fr['1D'], (string)($fr['tz'] ?? 'UTC'))];
    $F5 = $fr['5m'] ?? null;
    if (is_array($F5) && count($F5['t'] ?? []) >= 240) $src['30m'] = t_resample($F5 + ['v' => array_fill(0, count($F5['t']), 0)], 1800);
    $tf = []; $ev = []; $rank = 0.0; $up = 0; $dn = 0;
    foreach (TL_SCAN_TFS as $k) {
        if (empty($src[$k])) continue;
        try { $x = tl_frame($k, $src[$k], false); } catch (Throwable $e) { $x = null; }
        if (!$x) continue;
        $cell = ['p' => $x['pattern'], 'lean' => $x['lean']];
        foreach (['res', 'sup'] as $side) {
            $L = $x[$side]; if (!$L) continue;
            $cell[$side] = ['dir' => $L['dir'], 'n' => $L['touches'], 'st' => $L['state'], 'now' => $L['now'], 'gap' => $L['dist_pct']];
            $w = TL_SCAN_W[$k] * (1 + min(4, $L['touches'] - 2) * 0.25);
            if ($L['touches'] < 3) continue;   // a break or test only counts on a line with 3+ touches (2 points always make a line)
            if ($L['state'] === 'broken') { $ev[] = ['tf' => $k, 'e' => $side === 'res' ? 'break_up' : 'break_down', 'n' => $L['touches'], 'lv' => $L['now']]; $rank += 3 * $w; }
            elseif ($L['state'] === 'testing') { $ev[] = ['tf' => $k, 'e' => $side === 'res' ? 'at_res' : 'at_sup', 'n' => $L['touches'], 'lv' => $L['now']]; $rank += 1.5 * $w; }
        }
        if ($x['lean'] === 'up') $up++; elseif ($x['lean'] === 'down') $dn++;
        $rank += 0.2 * TL_SCAN_W[$k];
        $tf[$k] = $cell;
    }
    if (!$tf) return null;
    $px = (float)($row['price'] ?? 0); if ($px <= 0) $px = (float)end($fr['1D']['c']);
    return ['symbol' => $sym, 'name' => t_name($sym), 'market' => $row['market'] ?? (t_symbols()[$sym] ?? ''), 'class' => tl_class($sym),
            'price' => $px, 'dec' => tl_dec($px), 'tf' => $tf, 'ev' => $ev, 'up' => $up, 'down' => $dn, 'rank' => round($rank, 2), 'at' => $now];
}

/** Whole-universe scan, built in steps inside a time budget and cached; the page polls until progress is 1. */
function tl_scan(): array {
    $dir = T_DATA . '/tl'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $now = t_now(); $ck = $dir . '/_scan2.json';
    $c = t_read_json($ck, null);
    if (is_array($c) && $now - (int)($c['at'] ?? 0) < 45 && !empty($c['rows'])) return $c;
    $scan = t_scan_load(); $syms = array_keys($scan['rows'] ?? []);
    $cp = $dir . '/_rows2.json'; $cache = t_read_json($cp, []); if (!is_array($cache)) $cache = [];
    $t0 = microtime(true); $done = 0; $fresh = 0;
    foreach ($syms as $sym) {
        $old = $cache[$sym] ?? null;
        if (is_array($old) && $now - (int)($old['at'] ?? 0) < TL_ROW_TTL) { $done++; continue; }
        if (microtime(true) - $t0 > TL_BUDGET) continue;
        try { $r = tl_row($sym, $scan['rows'][$sym], $now); } catch (Throwable $e) { $r = null; }
        $cache[$sym] = $r ?: ['at' => $now, 'none' => 1]; $done++; $fresh++;
    }
    foreach ($cache as $s => $_) if (!isset($scan['rows'][$s])) unset($cache[$s]);
    if ($fresh) t_write_json($cp, $cache);
    $rows = array_values(array_filter($cache, fn($r) => is_array($r) && empty($r['none'])));
    usort($rows, fn($a, $b) => $b['rank'] <=> $a['rank']);
    $cnt = ['break_up' => 0, 'break_down' => 0, 'at_res' => 0, 'at_sup' => 0];
    foreach ($rows as $r) { $seen = []; foreach ($r['ev'] as $e) if (!isset($seen[$e['e']])) { $seen[$e['e']] = 1; $cnt[$e['e']]++; } }
    $p = ['at' => $now, 'rows' => $rows, 'meta' => ['universe' => count($syms), 'scanned' => $done, 'with_lines' => count($rows),
          'progress' => count($syms) ? round($done / count($syms), 3) : 1, 'counts' => $cnt, 'tfs' => TL_SCAN_TFS,
          'took_ms' => (int)round((microtime(true) - $t0) * 1000)]];
    t_write_json($ck, $p);
    return $p;
}
