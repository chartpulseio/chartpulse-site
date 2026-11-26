<?php
/**
 * Trend Lines v2 — "trap-aware", drawn the way a discretionary trader draws them (owner, 26 Nov 2026).
 * Runs NEXT TO the v1 engine (tl_engine.php): it is drawn on the chart panel for comparison and changes no signal.
 *
 *  1. Lines are drawn on candle BODIES (max/min of open, close). Wicks through a line are liquidity grabs ("sweeps"),
 *     not breaks; a sweep that closes back inside counts in the line's favour.
 *  2. A line breaks only when TWO consecutive bodies close beyond it (one close through can be a trap too).
 *  3. Only significant swings: fractal strength 5 bars each side on 1D, 4 on 4H/1H, 3 on 1W and intraday.
 *  4. Longer history: 300 daily bars, 150 weekly, 200 on the rest.
 *  5. The first anchor is one of the most extreme swings in the window (the way a trader starts from the major high/low).
 *  6. Daily and weekly lines are fitted on a log scale.
 *  7. Score: body touches, sweeps (extra credit), age, how recent, how close to price; the top 3 per side are kept.
 */
declare(strict_types=1);
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; }

const TL2_BARS = ['1W' => 150, '1D' => 300];
const TL2_K = ['1W' => 3, '1D' => 5, '4H' => 4, '1H' => 4];
const TL2_TOL = 0.15;    // x ATR: a body this far through the line is "through"
const TL2_TOUCH = 0.30;  // x ATR: a body extreme this close to the line is a touch
const TL2_FAR = 6.0;     // x ATR: lines further than this from price are dropped

function tl2_pivots(array $v, int $n, int $k, int $side): array {
    $P = [];
    for ($i = $k; $i < $n - 1; $i++) {          // the right side may be shorter near the end (recent swings count)
        $ok = true; $kr = min($k, $n - 1 - $i);
        for ($j = 1; $j <= $k && $ok; $j++) if ($side > 0 ? $v[$i - $j] >= $v[$i] : $v[$i - $j] <= $v[$i]) $ok = false;
        for ($j = 1; $j <= $kr && $ok; $j++) if ($side > 0 ? $v[$i + $j] > $v[$i] : $v[$i + $j] < $v[$i]) $ok = false;
        if ($ok && $kr >= 2) $P[] = $i;
    }
    return $P;
}

/** Best lines on one side. Returns up to 3, best first. $side +1 resistance (body highs), -1 support (body lows). */
function tl2_side(array $G, int $n, int $side, float $atr, bool $log, int $k): array {
    $bh = []; $bl = [];
    for ($i = 0; $i < $n; $i++) { $bh[] = max($G['o'][$i], $G['c'][$i]); $bl[] = min($G['o'][$i], $G['c'][$i]); }
    $body = $side > 0 ? $bh : $bl;
    $Y = $log ? fn($p) => log(max($p, 1e-12)) : fn($p) => $p;
    $P = tl2_pivots($body, $n, $k, $side); if (count($P) < 2) return [];
    // major anchors: the 4 most extreme swings in the window
    $ex = $P; usort($ex, fn($a, $b) => $side > 0 ? $body[$b] <=> $body[$a] : $body[$a] <=> $body[$b]); $ex = array_slice($ex, 0, 4);
    $px = (float)$G['c'][$n - 1]; $minSpan = max(5, (int)round($n * 0.05)); $C = [];
    foreach ($ex as $a) foreach ($P as $b) {
        if ($b - $a < $minSpan) continue;
        $ya = $Y($body[$a]); $sl = ($Y($body[$b]) - $ya) / ($b - $a);
        $at = fn($i) => $log ? exp($ya + $sl * ($i - $a)) : $ya + $sl * ($i - $a);
        $now = $at($n - 1);
        if (abs($at($n - 1) - $at($n - 2)) > 0.9 * $atr) continue;          // too steep
        if (abs($now - $px) > TL2_FAR * $atr) continue;                        // far from price
        // trap-aware break: two consecutive bodies closing through the line (the last 2 bars may be breaking live)
        $bad = false; $through = 0; $traps = 0;
        for ($i = $a + 1; $i < $n - 2; $i++) {
            $v = $at($i); $beyond = $side > 0 ? $G['c'][$i] > $v + TL2_TOL * $atr : $G['c'][$i] < $v - TL2_TOL * $atr;
            if ($beyond) { $through++; if ($through >= 2) { $bad = true; break; } } else { if ($through === 1) $traps++; $through = 0; }
        }
        if ($bad) continue;
        $touch = 0; $sweep = 0; $last = $b; $prev = -99;
        for ($i = $a; $i < $n; $i++) {
            $v = $at($i);
            $bodyNear = abs($body[$i] - $v) <= TL2_TOUCH * $atr;
            $wick = $side > 0 ? $G['h'][$i] > $v + 0.05 * $atr : $G['l'][$i] < $v - 0.05 * $atr;
            $inside = $side > 0 ? $G['c'][$i] <= $v + TL2_TOL * $atr : $G['c'][$i] >= $v - TL2_TOL * $atr;
            if ($i - $prev < 3) continue;
            if ($wick && $inside && !$bodyNear) { $sweep++; $last = $i; $prev = $i; }
            elseif ($bodyNear) { $touch++; $last = $i; $prev = $i; }
        }
        if ($touch < 2) continue;
        $score = min($touch, 6) * 3.0 + min($sweep + $traps, 4) * 1.5 + (($n - 1 - $a) / $n) * 4.0 + ($last / $n) * 3.0 - (abs($now - $px) / $atr) * 0.6 + 2.0;
        $C[] = ['a' => $a, 'b' => $b, 'pa' => $body[$a], 'pb' => $body[$b], 'ya' => $ya, 'sl' => $sl, 'now' => $now, 'touches' => $touch, 'sweeps' => $sweep + $traps, 'score' => $score];
    }
    usort($C, fn($x, $y) => $y['score'] <=> $x['score']);
    $out = [];
    foreach ($C as $c) {   // keep distinct lines only (not the same line through another anchor pair)
        $dup = false; foreach ($out as $o) if (abs($o['now'] - $c['now']) < 0.4 * $atr && abs($o['a'] - $c['a']) < $minSpan) $dup = true;
        if (!$dup) $out[] = $c; if (count($out) >= 3) break;
    }
    foreach ($out as &$c) {
        $c1 = (float)$G['c'][$n - 1]; $c2 = (float)$G['c'][$n - 2];
        $at = fn($i) => $log ? exp($c['ya'] + $c['sl'] * ($i - $c['a'])) : $c['ya'] + $c['sl'] * ($i - $c['a']);
        $b1 = ($c1 - $at($n - 1)) * $side > TL2_TOL * $atr; $b2 = ($c2 - $at($n - 2)) * $side > TL2_TOL * $atr;
        $c['state'] = ($b1 && $b2) ? 'broken' : (abs($c1 - $c['now']) <= 0.6 * $atr ? 'testing' : 'holding');
        $run = ($at($n - 1) - $at(0)) / $atr; $c['dir'] = $run > 1.5 ? 'rising' : ($run < -1.5 ? 'falling' : 'flat');
    }
    return $out;
}

/** v2 lines for one timeframe, with indices relative to the v1 panel's window of $panelN bars. */
function tl2_frame(string $tf, array $F, int $panelN): ?array {
    $N = count($F['t'] ?? []); if ($N < 40) return null;
    $n = min(TL2_BARS[$tf] ?? 200, $N); $o = $N - $n; $G = [];
    foreach (['t', 'o', 'h', 'l', 'c'] as $k) $G[$k] = array_map('floatval', array_slice($F[$k], $o, $n));
    $A = t_atr($F, 14); $atr = (float)$A[$N - 1]; if ($atr <= 0) return null;
    $log = in_array($tf, ['1D', '1W'], true); $k = TL2_K[$tf] ?? 3;
    $off = $n - min($panelN, $n);   // v2 index -> panel index
    $d = tl_dec((float)$G['c'][$n - 1]);
    $pub = fn(array $c) => ['i1' => $c['a'] - $off, 'p1' => round($c['pa'], $d + 1), 'i2' => $c['b'] - $off, 'p2' => round($c['pb'], $d + 1),
        'log' => $log, 'ly' => $c['ya'], 'ls' => $c['sl'], 'slope' => $log ? 0 : $c['sl'], 'now' => round($c['now'], $d), 'touches' => $c['touches'], 'sweeps' => $c['sweeps'],
        'state' => $c['state'], 'dir' => $c['dir'], 't1' => (int)$G['t'][$c['a']], 't2' => (int)$G['t'][$c['b']]];
    $R = array_map($pub, tl2_side($G, $n, 1, $atr, $log, $k)); $S = array_map($pub, tl2_side($G, $n, -1, $atr, $log, $k));
    return ['res' => $R, 'sup' => $S];
}
