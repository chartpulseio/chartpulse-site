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
const TL_TFS = ['5m' => 300, '10m' => 600, '15m' => 900, '30m' => 1800, '1H' => 3600, '4H' => 14400, '1D' => 86400, '1W' => 604800];   // the symbol panel
// The Trend Line Scanner page (owner, 11 Nov 2026): two views. 5m is drawn on the symbol page only -- its lines
// break every few minutes and would flood the ranking.
const TL_VIEWS = ['swing' => ['1W', '1D', '4H', '1H'], 'intra' => ['30m', '15m', '10m']];
const TL_MTF = ['30m', '1H', '4H', '1D', '1W'];   // MTF trend strip; the bias needs 75%+ of the available ones (4/5, or 3/4 without 30m)
const TL_TF_PTS = ['1W' => 10, '1D' => 9, '4H' => 8, '1H' => 6, '30m' => 6, '15m' => 5, '10m' => 4, '5m' => 2];
const TL_PIV_MAX = 22;      // newest swing points tried per side (keeps the whole-universe scan fast)
const TL_ROW_TTL = 180;     // a scanned symbol stays fresh this long (short: the list says what is touching NOW)
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
    $best['ag'] = round(abs($c - $best['now']) / $atr, 2);   // distance from the line in ATRs
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
                'dir' => $L['dir'], 'state' => $L['state'], 'dist_pct' => $L['dist_pct'], 'ag' => $L['ag']];
    };
    return ['tf' => $tf, 'pattern' => $name, 'lean' => $lean, 'dec' => $d, 'price' => round($px, $d), 'atr' => $atr,
            'res' => $pub($R), 'sup' => $pub($S), 'text' => tl_text($tf, $name, $R, $S, $px, $d)]
        + ($bars ? ['bars' => ['t' => $G['t'], 'o' => $G['o'], 'h' => $G['h'], 'l' => $G['l'], 'c' => $G['c']]] : []);
}

/** Every timeframe's bars for one symbol. 5m-based ones exist only where intraday bars are cached / fetched. */
function tl_src(array $fr, ?array $F5): array {
    $src = [];
    if (is_array($F5) && count($F5['t'] ?? []) >= 80) {
        $F5 = $F5 + ['v' => array_fill(0, count($F5['t']), 0)];
        $src['5m'] = $F5; $src['10m'] = t_resample($F5, 600); $src['15m'] = t_resample($F5, 900);
        if (count($F5['t']) >= 240) $src['30m'] = t_resample($F5, 1800);
    }
    $src['1H'] = $fr['1H']; $src['4H'] = t_resample($fr['1H'], 14400); $src['1D'] = $fr['1D'];
    $src['1W'] = t_weekly($fr['1D'], (string)($fr['tz'] ?? 'UTC'));
    return $src;
}

/**
 * Key price zones (owner, 11 Nov 2026): today's high / low, previous day high / low / close, weekly and monthly
 * high / low, Fibonacci daily pivots and the 1H Fibonacci retracement levels. Built from daily and 1H bars so every
 * symbol has them. Groups: 'level' (day / PD / W / M), 'pivot', 'fib'.
 */
function tl_zones(array $fr): array {
    $D = $fr['1D'] ?? null; $n = count($D['c'] ?? []); $Z = [];
    if ($n >= 3) {
        $Z[] = ['k' => 'Day High', 'g' => 'level', 'p' => (float)$D['h'][$n - 1]]; $Z[] = ['k' => 'Day Low', 'g' => 'level', 'p' => (float)$D['l'][$n - 1]];
        $ph = (float)$D['h'][$n - 2]; $pl = (float)$D['l'][$n - 2]; $pc = (float)$D['c'][$n - 2];
        $Z[] = ['k' => 'PDH', 'g' => 'level', 'p' => $ph]; $Z[] = ['k' => 'PDL', 'g' => 'level', 'p' => $pl]; $Z[] = ['k' => 'PDC', 'g' => 'level', 'p' => $pc];
        foreach ([['W', 5], ['M', 21]] as [$nm, $len]) {
            $a = max(0, $n - 1 - $len); $hs = array_slice($D['h'], $a, $n - 1 - $a); $ls = array_slice($D['l'], $a, $n - 1 - $a);
            if ($hs) { $Z[] = ['k' => $nm . 'H', 'g' => 'level', 'p' => (float)max($hs)]; $Z[] = ['k' => $nm . 'L', 'g' => 'level', 'p' => (float)min($ls)]; }
        }
        $rg = $ph - $pl; $pp = ($ph + $pl + $pc) / 3;
        if ($rg > 0) {
            $Z[] = ['k' => 'Pivot P', 'g' => 'pivot', 'p' => $pp];
            foreach ([1 => 0.382, 2 => 0.618, 3 => 1.0] as $i => $f) { $Z[] = ['k' => "Pivot R$i", 'g' => 'pivot', 'p' => $pp + $rg * $f]; $Z[] = ['k' => "Pivot S$i", 'g' => 'pivot', 'p' => $pp - $rg * $f]; }
        }
    }
    $H = $fr['1H'] ?? null; $m = count($H['c'] ?? []);
    if ($m >= 62) {
        $hs = array_slice($H['h'], $m - 61, 60); $ls = array_slice($H['l'], $m - 61, 60); $hi = (float)max($hs); $lo = (float)min($ls);
        if ($hi > $lo) foreach ([0.382, 0.5, 0.618, 0.705, 0.786] as $r) $Z[] = ['k' => 'Fib ' . rtrim(rtrim(number_format($r, 3), '0'), '.'), 'g' => 'fib', 'p' => $lo + ($hi - $lo) * $r];
    }
    return $Z;
}
/**
 * Zones sitting on a trend line's current level. 'strong' = two different kinds agree (a level with a pivot, a
 * level with a Fib, a pivot with a Fib) or two different levels (PDL with WL); 'major' = one zone. Two pivots or
 * two Fibs next to each other are still one kind, so they do not make a strong zone.
 */
function tl_match(array $Z, float $lv, float $tol): ?array {
    $hit = []; $grp = []; $lvl = 0;
    foreach ($Z as $z) if (abs($z['p'] - $lv) <= $tol) { $hit[] = [$z['k'], abs($z['p'] - $lv), $z['g']]; $grp[$z['g']] = 1; if ($z['g'] === 'level') $lvl++; }
    if (!$hit) return null;
    usort($hit, fn($a, $b) => $a[1] <=> $b[1]);
    $names = []; $seen = [];   // nearest zone of each kind first
    foreach ($hit as $h) if (!isset($seen[$h[2]])) { $seen[$h[2]] = 1; $names[] = $h[0]; }
    foreach ($hit as $h) if ($h[2] === 'level' && !in_array($h[0], $names, true)) $names[] = $h[0];
    return ['t' => (count($grp) >= 2 || $lvl >= 2) ? 'strong' : 'major', 'n' => array_slice($names, 0, 4), 'g' => count($grp)];
}

/** MTF trend: Supertrend(10,3) direction on 30m / 1H / 4H / 1D / 1W, and the bias when 75%+ of them agree. */
function tl_mtf(array $src): array {
    $d = []; $up = 0; $dn = 0;
    foreach (TL_MTF as $k) {
        $F = $src[$k] ?? null; if (!$F || count($F['c']) < 20) { $d[$k] = 0; continue; }
        [$dir] = t_supertrend($F); $x = (int)end($dir); $d[$k] = $x; if ($x > 0) $up++; elseif ($x < 0) $dn++;
    }
    $n = $up + $dn; $bias = null;
    if ($n >= 4) { if ($up / $n >= 0.75) $bias = 'CALL'; elseif ($dn / $n >= 0.75) $bias = 'PUT'; }
    return ['d' => $d, 'up' => $up, 'down' => $dn, 'n' => $n, 'bias' => $bias];
}

/** How well the pattern's textbook lean fits the signal (0-10). */
function tl_fit(string $pat, string $e): int {
    $bullBreak = ['Falling wedge' => 10, 'Ascending triangle' => 10, 'Symmetrical triangle' => 6, 'Sideways range' => 6, 'Flat resistance' => 6, 'Falling channel' => 5, 'Downtrend line' => 5];
    $bullHold = ['Rising channel' => 10, 'Uptrend line' => 10, 'Ascending triangle' => 10, 'Sideways range' => 6, 'Symmetrical triangle' => 6, 'Flat support' => 6, 'Falling wedge' => 6];
    $bearBreak = ['Rising wedge' => 10, 'Descending triangle' => 10, 'Symmetrical triangle' => 6, 'Sideways range' => 6, 'Flat support' => 6, 'Rising channel' => 5, 'Uptrend line' => 5];
    $bearHold = ['Falling channel' => 10, 'Downtrend line' => 10, 'Descending triangle' => 10, 'Sideways range' => 6, 'Symmetrical triangle' => 6, 'Flat resistance' => 6, 'Rising wedge' => 6];
    $m = ['break_up' => $bullBreak, 'at_sup' => $bullHold, 'break_down' => $bearBreak, 'at_res' => $bearHold][$e] ?? [];
    return $m[$pat] ?? 2;
}

/**
 * Quality of one signal, 0-100 (owner, 11 Nov 2026: best calls / puts on top).
 *   MTF bias agrees      up to 30   (all TFs 30, 80%+ 24, 75% 22; no clear bias but a majority 8; against the bias 0)
 *   zone on the line     up to 25   (strong zone 25, one major zone 15)
 *   event                up to 15   (decisive break 15, test of the line 12)
 *   touches              up to 10   (3 -> 4, 4 -> 7, 5+ -> 10)
 *   timeframe            up to 10
 *   pattern fits         up to 10
 * A signal against the MTF bias keeps 60% of its total. This is ORDER ONLY (owner, 11 Nov 2026 23:24): nothing is
 * hidden and every signal keeps its own CALL / PUT; the MTF trend is shown as a label.
 */
function tl_score(array $sg, array $mtf): array {
    $dir = $sg['dir']; $agree = $dir === 'CALL' ? $mtf['up'] : $mtf['down']; $n = max(1, $mtf['n']); $r = $agree / $n;
    $with = $mtf['bias'] === $dir; $against = $mtf['bias'] !== null && !$with;
    $pM = $with ? ($r >= 0.999 ? 30 : ($r >= 0.8 ? 24 : 22)) : (($mtf['bias'] === null && $r > 0.5) ? 8 : 0);
    $pZ = $sg['zone'] ? ($sg['zone']['t'] === 'strong' ? 25 : 15) : 0;
    $pE = str_starts_with($sg['e'], 'break') ? 15 : 12;
    $pT = $sg['n'] >= 5 ? 10 : ($sg['n'] === 4 ? 7 : ($sg['n'] === 3 ? 4 : 1));
    $pF = TL_TF_PTS[$sg['tf']] ?? 3; $pP = tl_fit($sg['pattern'], $sg['e']);
    $tot = $pM + $pZ + $pE + $pT + $pF + $pP; if (!$with) $tot *= 0.6;
    return ['score' => (int)round($tot), 'with' => $with, 'against' => $against,
            'parts' => ['mtf' => $pM, 'zone' => $pZ, 'event' => $pE, 'touches' => $pT, 'tf' => $pF, 'pattern' => $pP]];
}

/** Lines, zones and signals of one timeframe. Returns [cell for the list, signals[], frame]. */
function tl_read(string $k, array $F, array $Z, float $atrD, array $mtf, bool $bars): ?array {
    try { $x = tl_frame($k, $F, $bars); } catch (Throwable $e) { $x = null; }
    if (!$x) return null;
    $tol = $atrD > 0 ? min(0.25 * $x['atr'], 0.1 * $atrD) : 0.25 * $x['atr'];   // tight: a zone must really sit on the line
    $cell = ['p' => $x['pattern'], 'lean' => $x['lean']]; $sigs = [];
    foreach (['res', 'sup'] as $side) {
        $L = $x[$side]; if (!$L) continue;
        $z = tl_match($Z, (float)$L['now'], $tol); $x[$side]['zone'] = $z;
        $cell[$side] = ['dir' => $L['dir'], 'n' => $L['touches'], 'st' => $L['state'], 'now' => $L['now'], 'gap' => $L['dist_pct']] + ($z ? ['z' => $z['t']] : []);
        // owner, 11 Nov 2026 23:24: no filters -- every broken or tested line is a signal, exactly as on the first
        // build. Touches, zone, MTF trend, pattern are labels on the signal; they rank it but never hide it.
        $e = null;
        if ($L['state'] === 'broken') $e = $side === 'res' ? 'break_up' : 'break_down';
        elseif ($L['state'] === 'testing') $e = $side === 'res' ? 'at_res' : 'at_sup';
        if (!$e) continue;
        $cell[$side]['ev'] = str_starts_with($e, 'break') ? 'break' : 'test';
        $sg = ['tf' => $k, 'e' => $e, 'dir' => ($e === 'break_up' || $e === 'at_sup') ? 'CALL' : 'PUT', 'n' => $L['touches'], 'lv' => $L['now'],
               'zone' => $z, 'pattern' => $x['pattern']];
        $sigs[] = $sg + tl_score($sg, $mtf);
    }
    return [$cell, $sigs, $x];
}

/** Best signal of a set of timeframes, by score. Adds a little for other timeframes pointing the same way. */
function tl_pick(array $sigs, array $tfs): ?array {
    $s = array_values(array_filter($sigs, fn($g) => in_array($g['tf'], $tfs, true)));
    if (!$s) return null;
    usort($s, fn($a, $b) => $b['score'] <=> $a['score']);
    $b = $s[0]; $more = 0; $seen = [$b['tf'] => 1];
    foreach (array_slice($s, 1) as $g) if ($g['dir'] === $b['dir'] && !isset($seen[$g['tf']])) { $seen[$g['tf']] = 1; $more++; }
    $q = min(100, $b['score'] + min(8, 3 * $more));
    return ['q' => $q, 'call' => $b['dir'], 'tf' => $b['tf'], 'e' => $b['e'], 'dir' => $b['dir'], 'n' => $b['n'], 'lv' => $b['lv'],
            'zone' => $b['zone'], 'with' => $b['with'], 'against' => $b['against'], 'more' => $more, 'pattern' => $b['pattern'], 'parts' => $b['parts']];
}

/**
 * Entry / stop / targets for one signal, by rule (owner, 11 Nov 2026). No win rate is attached: these rules have
 * not been measured on this universe, and the page says so.
 *   break : buy / sell the retest of the broken line; stop one ATR back inside it
 *   test  : buy / sell once a candle closes away from the line; stop 0.8 ATR beyond it
 *   T1 = 1.5 x risk; T2 = the pattern's other line or its height projected, never less than 2.5 x risk away
 */
function tl_plan(array $sg, array $x): array {
    $up = $sg['dir'] === 'CALL'; $s = $up ? 1 : -1; $atr = (float)$x['atr']; $lv = (float)$sg['lv']; $d = (int)$x['dec']; $brk = str_starts_with($sg['e'], 'break');
    $entry = $lv + $s * ($brk ? 0.1 : 0.3) * $atr; $sl = $lv - $s * ($brk ? 1.0 : 0.8) * $atr; $risk = abs($entry - $sl);
    $t1 = $entry + $s * 1.5 * $risk; $t2 = $entry + $s * 2.5 * $risk; $t2why = '2.5 x the risk';
    $R = $x['res']['now'] ?? null; $S = $x['sup']['now'] ?? null;
    if ($R !== null && $S !== null && $R > $S) {
        if ($brk) { $mm = $entry + $s * ($R - $S); if (abs($mm - $entry) >= 2.5 * $risk) { $t2 = $mm; $t2why = 'the pattern height projected from the break'; } }
        else { $opp = $up ? $R : $S; if (abs($opp - $entry) >= 2.0 * $risk && ($opp - $entry) * $s > 0) { $t2 = $opp; $t2why = 'the other line of the pattern'; } }
    }
    $tf = $sg['tf']; $lvS = tl_px($lv, $d);
    $READ = ['Falling wedge' => 'A falling wedge usually resolves upward once its resistance breaks.', 'Rising wedge' => 'A rising wedge usually resolves downward once its support breaks.',
        'Ascending triangle' => 'An ascending triangle (flat top, rising lows) leans upward.', 'Descending triangle' => 'A descending triangle (flat bottom, falling highs) leans downward.',
        'Symmetrical triangle' => 'A symmetrical triangle has no lean of its own: the side that breaks decides.', 'Rising channel' => 'A rising channel is an uptrend: dips to its support are with the trend.',
        'Falling channel' => 'A falling channel is a downtrend: rallies to its resistance are with the trend.', 'Sideways range' => 'A sideways range trades between its two lines until one breaks.'];
    $how = $brk
        ? ($up ? "Price closed above the $tf resistance line. Wait for a pullback toward $lvS and buy if it holds as support." : "Price closed below the $tf support line. Wait for a bounce toward $lvS and sell if it holds as resistance.")
        : ($up ? "Price is on the $tf support line. Buy only after a $tf candle closes back up away from the line." : "Price is on the $tf resistance line. Sell only after a $tf candle closes back down away from the line.");
    return ['tf' => $tf, 'dir' => $sg['dir'], 'kind' => $brk ? 'breakout' : 'bounce', 'e' => $sg['e'], 'pattern' => $sg['pattern'], 'q' => $sg['score'], 'with' => $sg['with'], 'against' => $sg['against'], 'n' => $sg['n'],
            'zone' => $sg['zone'], 'entry' => round($entry, $d), 'sl' => round($sl, $d), 't1' => round($t1, $d), 't2' => round($t2, $d), 'dec' => $d,
            'rr2' => round(abs($t2 - $entry) / max($risk, 1e-12), 1), 'risk_pct' => $entry > 0 ? round($risk / $entry * 100, 2) : 0, 't2why' => $t2why,
            'read' => (isset($READ[$sg['pattern']]) && tl_fit($sg['pattern'], $sg['e']) <= 2 ? 'Caution: ' : '') . ($READ[$sg['pattern']] ?? ''), 'how' => $how,
            'exit' => ($up ? "Exit if a $tf candle closes below " : "Exit if a $tf candle closes above ") . tl_px($sl, $d) . ': the line has failed.', 'parts' => $sg['parts']];
}

/** All timeframes for one symbol: charts, zones on the lines, MTF trend, trade plans, summary. Cached 60 s. */
function tl_build(string $sym): ?array {
    $dir = T_DATA . '/tl'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $p = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym) . '.v4.json';
    $c = t_read_json($p, null);
    if (is_array($c) && t_now() - (int)($c['at'] ?? 0) < 60) return $c;
    $fr = t_read_frames($sym); if (!$fr) return null;
    $src = tl_src($fr, t_m5($sym) ?: ($fr['5m'] ?? null));
    $Z = tl_zones($fr); $A = t_atr($fr['1D'], 14); $atrD = (float)end($A); $mtf = tl_mtf($src);
    $out = []; $lean = ['up' => 0, 'down' => 0, 'squeeze' => 0, 'side' => 0, 'none' => 0]; $sigs = []; $X = [];
    foreach (TL_TFS as $tf => $_) {
        if (empty($src[$tf])) continue;
        $r = tl_read($tf, $src[$tf], $Z, $atrD, $mtf, true); if (!$r) continue;
        [, $sg, $x] = $r; $X[$tf] = $x; foreach ($sg as $g) $sigs[] = $g;
        foreach (['res', 'sup'] as $side) if (!empty($x[$side]['zone'])) {
            $z = $x[$side]['zone'];
            $x['text'][] = ($z['t'] === 'strong' ? 'STRONG ZONE: ' : 'Major zone: ') . 'the ' . ($side === 'res' ? 'resistance' : 'support') . ' line sits on ' . implode(' + ', $z['n']) . '.';
        }
        unset($x['atr']); $out[] = $x; $lean[$x['lean']]++;
    }
    $plans = [];
    foreach (TL_VIEWS as $vw => $tfs) {
        $s = array_values(array_filter($sigs, fn($g) => in_array($g['tf'], $tfs, true)));
        if (!$s) continue;
        usort($s, fn($a, $b) => $b['score'] <=> $a['score']);
        $plans[] = ['view' => $vw] + tl_plan($s[0], $X[$s[0]['tf']]);
    }
    $k = count($out);
    $sum = $k === 0 ? 'No clean trend line is holding on any timeframe right now.'
        : ($lean['down'] > $lean['up'] && $lean['down'] >= ($k + 1) / 2 ? "{$lean['down']} of $k timeframes are drawing a falling structure: sellers are in control until a resistance line breaks."
        : ($lean['up'] > $lean['down'] && $lean['up'] >= ($k + 1) / 2 ? "{$lean['up']} of $k timeframes are drawing a rising structure: buyers are in control until a support line breaks."
        : "The timeframes disagree ({$lean['up']} rising, {$lean['down']} falling, " . ($lean['squeeze'] + $lean['side']) . " sideways or squeezing): wait for one side's line to break."));
    $res = ['symbol' => $sym, 'name' => t_name($sym), 'at' => t_now(), 'summary' => $sum, 'mtf' => $mtf, 'plans' => $plans,
            'signals' => array_map(fn($g) => array_diff_key($g, ['parts' => 1]), $sigs), 'frames' => $out];
    t_write_json($p, $res);
    return $res;
}

// ===========================================================================
// Trend Line Scanner: every symbol of the Tier-1 universe, swing (1W 1D 4H 1H) and intraday (30m 15m 10m)
// ===========================================================================
function tl_class(string $sym): string { $ex = t_exchange($sym); return $ex === 'CRYPTO' ? 'crypto' : ($ex === 'FX' ? 'forex' : 'other'); }

/** One symbol for the scanner list. */
function tl_row(string $sym, array $row, int $now): ?array {
    $fr = t_read_frames($sym); if (!$fr) return null;
    $src = tl_src($fr, $fr['5m'] ?? null);
    $Z = tl_zones($fr); $A = t_atr($fr['1D'], 14); $atrD = (float)end($A); $mtf = tl_mtf($src);
    $tf = []; $sigs = [];
    foreach (TL_VIEWS as $tfs) foreach ($tfs as $k) {
        if (empty($src[$k])) continue;
        $r = tl_read($k, $src[$k], $Z, $atrD, $mtf, false); if (!$r) continue;
        $tf[$k] = $r[0]; foreach ($r[1] as $g) $sigs[] = $g;
    }
    if (!$tf) return null;
    $px = (float)($row['price'] ?? 0); if ($px <= 0) $px = (float)end($fr['1D']['c']);
    $ev = array_map(fn($g) => ['tf' => $g['tf'], 'e' => $g['e'], 'n' => $g['n'], 'lv' => $g['lv'], 'w' => $g['with'] ? 1 : 0, 'z' => $g['zone']['t'] ?? null, 's' => $g['score']], $sigs);
    $best = []; foreach (TL_VIEWS as $vw => $tfs) $best[$vw] = tl_pick($sigs, $tfs);
    return ['symbol' => $sym, 'name' => t_name($sym), 'market' => $row['market'] ?? (t_symbols()[$sym] ?? ''), 'class' => tl_class($sym),
            'price' => $px, 'dec' => tl_dec($px), 'mtf' => $mtf, 'tf' => $tf, 'ev' => $ev, 'best' => $best, 'at' => $now];
}

/** Whole-universe scan, built in steps inside a time budget and cached; the page polls until progress is 1. */
function tl_scan(): array {
    $dir = T_DATA . '/tl'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $now = t_now(); $ck = $dir . '/_scan6.json';
    $c = t_read_json($ck, null);
    if (is_array($c) && $now - (int)($c['at'] ?? 0) < 45 && !empty($c['rows'])) return $c;
    $scan = t_scan_load(); $syms = array_keys($scan['rows'] ?? []);
    $cp = $dir . '/_rows6.json'; $cache = t_read_json($cp, []); if (!is_array($cache)) $cache = [];
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
    $p = ['at' => $now, 'rows' => $rows, 'meta' => ['universe' => count($syms), 'scanned' => $done, 'with_lines' => count($rows),
          'progress' => count($syms) ? round($done / count($syms), 3) : 1, 'views' => TL_VIEWS, 'mtf' => TL_MTF,
          'took_ms' => (int)round((microtime(true) - $t0) * 1000)]];
    t_write_json($ck, $p);
    return $p;
}

// ===========================================================================
// Hybrid Trend Line Scanner (owner, 11 Nov 2026 23:36)
//
// Trades the day's extreme INTO a trend line, in the direction of the bigger trend only:
//   MTF bear  -> PUT, only where today's Day High is at a RESISTANCE line
//   MTF bull  -> CALL, only where today's Day Low is at a SUPPORT line
// MTF here IS a filter: the Daily Supertrend is the veto and one of 4H / Weekly must confirm it.
// 30m / 1H running the other way is the pullback into the line, which is what the setup wants (label).
// Stages, strongest first:
//   5 FAILED BREAK  a candle closed through the line, then a candle closed back inside   -> alert
//   4 REJECTION     a candle touched the line and closed away from it                    -> alert
//   3 AT THE LINE   price is on the line (within the band either side)                   -> the signal
//   2 BROKEN        price is clearly through the line; waiting for it to come back
//   1 APPROACHING   price is closing in on the line
// Zones on the line (PDH / PDL / PDC, weekly, monthly, pivots, Fib) are labels.
// ===========================================================================
const TLH_TFS = ['1D', '4H', '1H', '30m', '15m'];   // charts whose lines are used
const TLH_NEAR = 0.15;     // x daily ATR: the day's extreme is "at" the line within this
const TLH_OVER = 0.6;      // x daily ATR: the extreme may have run this far through the line
const TLH_APPROACH = 0.3;  // x daily ATR: price this close to the line is approaching
const TLH_WIN = 8;         // entry candles looked back for a close through the line
const TLH_BAND = 0.15;     // x daily ATR: price this close to the line, either side, is AT the line
const TLH_STAGE = [5 => 'FAILED BREAK', 4 => 'REJECTION', 3 => 'AT THE LINE', 2 => 'BROKEN', 1 => 'APPROACHING'];

/** Bias for the hybrid scanner: Daily is the veto, 4H or Weekly must agree with it. */
function tlh_bias(array $mtf): ?string {
    $d = $mtf['d']; $day = (int)($d['1D'] ?? 0);
    if ($day === 0) return null;
    if ((int)($d['4H'] ?? 0) === $day || (int)($d['1W'] ?? 0) === $day) return $day > 0 ? 'CALL' : 'PUT';
    return null;
}

/** One symbol. Returns null when the trend gives no bias or no line is at the day's extreme. */
/** Trendline Hero (owner, 12 Nov 2026): the same rule on 1W 1D 4H 1H 30m lines, the turn read on 30m candles,
    and longer targets when the line comes from a big chart (a line that held for weeks can pay for a long move). */
const TLHERO_TFS = ['1W', '1D', '4H', '1H', '30m'];
const TLHERO_RR = ['1W' => [2.0, 5.0], '1D' => [2.0, 5.0], '4H' => [2.0, 3.5], '1H' => [1.5, 2.5], '30m' => [1.5, 2.5]];

function tlh_row(string $sym, array $row, int $now, array $tfs = TLH_TFS, string $ePref = '15m', bool $hero = false): ?array {
    $fr = t_read_frames($sym); if (!$fr) return null;
    $D = $fr['1D'] ?? null; $nD = count($D['c'] ?? []); if ($nD < 30) return null;
    $src = tl_src($fr, $fr['5m'] ?? null); $mtf = tl_mtf($src); $bias = tlh_bias($mtf); if (!$bias) return null;
    $A = t_atr($D, 14); $atrD = (float)end($A); if ($atrD <= 0) return null;
    $put = $bias === 'PUT'; $s = $put ? 1 : -1;                       // +1: the line is above (resistance), -1: below (support)
    $dayX = $put ? (float)$D['h'][$nD - 1] : (float)$D['l'][$nD - 1];
    // entry candles: 15m where intraday bars are cached, else 1H; the last bar is still forming
    $etf = !empty($src[$ePref]) && count($src[$ePref]['c']) >= 20 ? $ePref : '1H'; $E = $src[$etf]; $ne = count($E['c']);
    if ($ne < TLH_WIN + 3) return null;
    $px = (float)$E['c'][$ne - 1]; $lc = $ne - 2;                     // live price, last closed candle
    $live = $now - (int)$E['t'][$ne - 1] <= 2 * (['10m' => 600, '15m' => 900, '30m' => 1800][$etf] ?? 3600) ? 1 : 0;   // is the market trading right now?
    $Z = array_values(array_filter(tl_zones($fr), fn($z) => $z['k'] !== 'Day High' && $z['k'] !== 'Day Low'));
    $best = null; $hits = 0; $all = [];
    foreach ($tfs as $k) {
        if (empty($src[$k])) continue;
        try { $x = tl_frame($k, $src[$k], false); } catch (Throwable $e) { $x = null; }
        $L = $x ? $x[$put ? 'res' : 'sup'] : null; if (!$L) continue;
        if (($k === '10m' || $k === '15m' || $k === '30m') && $L['touches'] < 3) continue;   // a 2-touch line on a small chart is noise
        $lv = (float)$L['now']; $over = ($dayX - $lv) * $s;           // > 0: the day's extreme went through the line
        if (!($over >= -TLH_NEAR * $atrD && $over <= TLH_OVER * $atrD)) continue;
        $thr = 0.05 * $atrD; $tol = 0.05 * $atrD; $stage = 0; $brk = null;
        for ($i = max(0, $lc - TLH_WIN + 1); $i <= $lc; $i++) if (((float)$E['c'][$i] - $lv) * $s > $thr) $brk = $i;
        $o = (float)$E['o'][$lc]; $h = (float)$E['h'][$lc]; $l = (float)$E['l'][$lc]; $c = (float)$E['c'][$lc]; $rg = max($h - $l, 1e-12);
        $inside = ($c - $lv) * $s < 0 && ($px - $lv) * $s < 0;        // last close and live price are back on the trade's side
        $reach = $put ? $h >= $lv - $tol : $l <= $lv + $tol;          // the candle got to the line
        $wick = $put ? ($h - max($o, $c)) / $rg : (min($o, $c) - $l) / $rg;   // the tail left at the line
        $turn = $wick >= 0.6 || (($put ? $c < $o : $c > $o) && $wick >= 0.3);
        // owner, 11 Nov 2026 23:58 (NZD/JPY sat on its 4H resistance and was shown as "waiting"): price hugging the
        // line, a little under or a little over, is AT THE LINE and that is the signal. "Broken" now means clearly
        // through it, by more than the band.
        $dist = ($px - $lv) * $s; $band = TLH_BAND * $atrD;          // > 0: price is beyond the line
        if ($brk !== null && $brk < $lc && $inside) $stage = 5;
        elseif ($brk === null && $reach && $inside && $turn) $stage = 4;
        elseif (abs($dist) <= $band) $stage = 3;
        elseif ($dist > $band) $stage = 2;
        elseif (-$dist <= TLH_APPROACH * $atrD) $stage = 1;
        if (!$stage) continue;
        $hits++; $all[] = ['tf' => $k, 'stage' => $stage];   // every chart whose line is in play, for the remark on the list
        $zone = tl_match($Z, $lv, 0.06 * $atrD);   // tight, so the label means the zone really sits on the line
        $cand = ['tf' => $k, 'stage' => $stage, 'lv' => $lv, 'n' => (int)$L['touches'], 'dir' => $L['dir'], 'pattern' => $x['pattern'],
                 'zone' => $zone ? ['t' => $zone['t'], 'n' => $zone['n']] : null, 'opp' => $x[$put ? 'sup' : 'res']['now'] ?? null, 'brk' => $brk,
                 'key' => [$stage >= 4 ? $live : 1, $stage, $zone ? ($zone['t'] === 'strong' ? 2 : 1) : 0, TL_TF_PTS[$k] ?? 0, (int)$L['touches']]];
        if ($best === null || $cand['key'] > $best['key']) $best = $cand;
    }
    if (!$best) return null;
    $d = tl_dec($px); $pull = [];
    foreach (['30m', '1H'] as $k) if ((int)($mtf['d'][$k] ?? 0) === ($put ? 1 : -1)) $pull[] = $k;
    $plan = null;
    if ($best['stage'] >= 3) {
        // stop beyond the furthest point of the run through the line (or the day's extreme), a little room added
        $ext = $dayX; for ($i = max(0, $lc - TLH_WIN + 1); $i < $ne; $i++) $ext = $put ? max($ext, (float)$E['h'][$i]) : min($ext, (float)$E['l'][$i]);
        $sl = $ext + $s * 0.1 * $atrD; $risk = abs($sl - $px);
        if ($risk > 0) {
            [$r1, $r2] = $hero ? (TLHERO_RR[$best['tf']] ?? [1.5, 2.5]) : [1.5, 2.5];
            $t1 = $px - $s * $r1 * $risk; $t2 = $px - $s * $r2 * $risk; $t2why = rtrim(rtrim(number_format($r2, 1), '0'), '.') . ' x the risk';
            $opp = $best['opp'];
            if ($opp !== null && ($px - $opp) * $s >= ($hero ? $r2 : 2.0) * $risk) { $t2 = (float)$opp; $t2why = 'the other line of the pattern'; }
            $plan = ['entry' => round($px, $d), 'sl' => round($sl, $d), 't1' => round($t1, $d), 't2' => round($t2, $d), 't2why' => $t2why, 't1x' => $r1,
                     'risk_pct' => round($risk / $px * 100, 2)];
        }
    }
    return ['symbol' => $sym, 'name' => t_name($sym), 'market' => $row['market'] ?? (t_symbols()[$sym] ?? ''), 'class' => tl_class($sym),
            'price' => round($px, $d), 'dec' => $d, 'bias' => $bias, 'mtf' => $mtf, 'pull' => $pull,
            'with4h' => (int)($mtf['d']['4H'] ?? 0) === (int)$mtf['d']['1D'], 'with1w' => (int)($mtf['d']['1W'] ?? 0) === (int)$mtf['d']['1D'],
            'stage' => $best['stage'], 'stage_name' => TLH_STAGE[$best['stage']], 'tf' => $best['tf'], 'etf' => $etf,
            'lv' => round($best['lv'], $d), 'touches' => $best['n'], 'line_dir' => $best['dir'], 'pattern' => $best['pattern'],
            'day_x' => round($dayX, $d), 'gap_atr' => round(abs($dayX - $best['lv']) / $atrD, 2), 'through' => ($dayX - $best['lv']) * $s > 0,
            'zone' => $best['zone'], 'lines' => $hits, 'all' => $all, 'plan' => $plan, 'live' => $live,
            'bar_t' => (int)$E['t'][$lc], 'key' => array_merge($best['key'], [count($pull)]), 'at' => $now];
}

/** Whole-universe hybrid scan (same stepping and caching as tl_scan, shorter row life because stages move fast). */
function tlh_scan(bool $hero = false): array {
    $dir = T_DATA . '/tl'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $now = t_now(); $ck = $dir . ($hero ? '/_heroscan2.json' : '/_hscan5.json');
    $c = t_read_json($ck, null);
    if (is_array($c) && $now - (int)($c['at'] ?? 0) < 30 && isset($c['rows'])) return $c;
    $scan = t_scan_load(); $syms = array_keys($scan['rows'] ?? []);
    $cp = $dir . ($hero ? '/_herorows2.json' : '/_hrows5.json'); $cache = t_read_json($cp, []); if (!is_array($cache)) $cache = [];
    $t0 = microtime(true); $done = 0; $fresh = 0; $nobias = 0;
    foreach ($syms as $sym) {
        $old = $cache[$sym] ?? null;
        if (is_array($old) && $now - (int)($old['at'] ?? 0) < 240) { $done++; continue; }
        if (microtime(true) - $t0 > TL_BUDGET) continue;
        try { $r = $hero ? tlh_row($sym, $scan['rows'][$sym], $now, TLHERO_TFS, '30m', true) : tlh_row($sym, $scan['rows'][$sym], $now); } catch (Throwable $e) { $r = null; }
        $cache[$sym] = $r ?: ['at' => $now, 'none' => 1]; $done++; $fresh++;
    }
    foreach ($cache as $s => $_) if (!isset($scan['rows'][$s])) unset($cache[$s]);
    if ($fresh) t_write_json($cp, $cache);
    $rows = array_values(array_filter($cache, fn($r) => is_array($r) && empty($r['none'])));
    usort($rows, fn($a, $b) => $b['key'] <=> $a['key']);
    $cnt = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0, 'old' => 0];
    foreach ($rows as $r) { if ($r['stage'] >= 4 && empty($r['live'])) $cnt['old']++; else $cnt[$r['stage']]++; }
    $p = ['at' => $now, 'rows' => $rows, 'meta' => ['universe' => count($syms), 'scanned' => $done, 'progress' => count($syms) ? round($done / count($syms), 3) : 1,
          'counts' => $cnt, 'took_ms' => (int)round((microtime(true) - $t0) * 1000)]];
    t_write_json($ck, $p);
    return $p;
}
