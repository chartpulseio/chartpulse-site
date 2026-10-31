<?php
/**
 * ChartPulse — Daily Bottom Reversal Scanner Engine (PHP port of engine.py v3)
 * Requires: torus_engine.php (for t_ema, t_atr, t_ewm, t_read_frames, frame helpers)
 *
 * Input per symbol: frames array with '1H' and '1D' keys (standard torus frames).
 * 4H and 1W are aggregated from 1H / 1D.
 *
 * All thresholds are starting hypotheses — tune with backtests. Not financial advice.
 */

const BR_CFG = [
    'tol'          => 0.5,
    'smin'         => 0.1,
    'smax'         => 1.5,
    'vol'          => 1.3,
    'rec_h'        => 12,
    'age'          => 4,
    'ground_min'   => 30,
    'entry_min'    => 25,
    // Bottom Reversal (daily, closed candles only)
    'bottom_pct'   => 0.33,     // price must be in lowest third of 1y range
    'range_bars'   => 252,
    'gate_bars'    => 5,
    'gate_bars_4h' => 18,
    'bb_week_lb'   => 6,
    'liq_lb'       => 20,
    'choch_win'    => 40,
    'cancel_lb'    => 30,
    'fast_4h'      => false,
    'h4_gate'      => true,
];

// ---------------------------------------------------------------------------
//  Helpers: pivots, CHoCH, EMA, ATR, BB
// ---------------------------------------------------------------------------

/** Swing low/high pivots. Returns array of indices. */
function br_pivots(array $v, int $n, bool $low = true): array {
    $out = []; $cnt = count($v);
    for ($i = $n; $i < $cnt - $n; $i++) {
        $win = array_slice($v, $i - $n, 2 * $n + 1);
        $ext = $low ? min($win) : max($win);
        if (abs($v[$i] - $ext) < 1e-12) $out[] = $i;
    }
    return $out;
}

/** Bollinger lower band (SMA - k*std). */
function br_bb_lower(array $c, int $n = 20, float $k = 2.0): array {
    $out = []; $cnt = count($c);
    for ($i = 0; $i < $cnt; $i++) {
        if ($i < $n - 1) { $out[] = null; continue; }
        $sl = array_slice($c, $i - $n + 1, $n);
        $mean = array_sum($sl) / $n;
        $var = 0; foreach ($sl as $x) $var += ($x - $mean) ** 2;
        $out[] = $mean - $k * sqrt($var / $n);
    }
    return $out;
}

/** Bullish CHoCH: after the window's lowest low, a close above the last pivot high before it. */
function br_choch_up(array $f, int $win = 40): bool {
    $n = count($f['c']); if ($n < $win) return false;
    $off = $n - $win;
    $lows = array_slice($f['l'], $off);
    $li = (int)array_keys($lows, min($lows))[0];   // index of lowest low in window

    // pivot highs before the lowest low
    $highs = array_slice($f['h'], $off);
    $ph = br_pivots($highs, 2, false);
    $ph = array_filter($ph, fn($i) => $i < $li);
    if (!$ph) return false;
    $lastPH = $highs[max($ph)];

    // any close after lowest low that exceeds last pivot high?
    $closes = array_slice($f['c'], $off);
    for ($i = $li + 1; $i < $win; $i++) {
        if ($closes[$i] > $lastPH) return true;
    }
    return false;
}

/** Aggregate 1H bars into 4H bars. */
function br_agg_4h(array $f): array {
    $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    $n = count($f['t']); $g = 4;
    for ($i = 0; $i + $g - 1 < $n; $i += $g) {
        $o['t'][] = $f['t'][$i];
        $o['o'][] = $f['o'][$i];
        $h = $f['h'][$i]; $l = $f['l'][$i]; $v = 0;
        for ($j = 0; $j < $g; $j++) { $h = max($h, $f['h'][$i+$j]); $l = min($l, $f['l'][$i+$j]); $v += $f['v'][$i+$j]; }
        $o['h'][] = $h; $o['l'][] = $l; $o['c'][] = $f['c'][$i + $g - 1]; $o['v'][] = $v;
    }
    return $o;
}

/** Aggregate 1D bars into 1W bars (Mon-Fri grouping). */
function br_agg_1w(array $f, string $tz = 'UTC'): array {
    $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    $n = count($f['t']); if (!$n) return $o;
    $buf = null; $curWeek = -1;
    for ($i = 0; $i < $n; $i++) {
        $ts = $f['t'][$i];
        $wk = (int)date('oW', $ts);   // ISO week number
        if ($wk !== $curWeek) {
            if ($buf !== null) { $o['t'][] = $buf['t']; $o['o'][] = $buf['o']; $o['h'][] = $buf['h']; $o['l'][] = $buf['l']; $o['c'][] = $buf['c']; $o['v'][] = $buf['v']; }
            $buf = ['t' => $ts, 'o' => $f['o'][$i], 'h' => $f['h'][$i], 'l' => $f['l'][$i], 'c' => $f['c'][$i], 'v' => $f['v'][$i]];
            $curWeek = $wk;
        } else {
            $buf['h'] = max($buf['h'], $f['h'][$i]);
            $buf['l'] = min($buf['l'], $f['l'][$i]);
            $buf['c'] = $f['c'][$i];
            $buf['v'] += $f['v'][$i];
        }
    }
    if ($buf !== null) { $o['t'][] = $buf['t']; $o['o'][] = $buf['o']; $o['h'][] = $buf['h']; $o['l'][] = $buf['l']; $o['c'][] = $buf['c']; $o['v'][] = $buf['v']; }
    return $o;
}

/** Slice last $n bars from a frame. */
function br_tail(array $f, int $n): array {
    $cnt = count($f['t']); if ($n >= $cnt) return $f;
    $off = $cnt - $n;
    return ['t' => array_values(array_slice($f['t'], $off)), 'o' => array_values(array_slice($f['o'], $off)),
            'h' => array_values(array_slice($f['h'], $off)), 'l' => array_values(array_slice($f['l'], $off)),
            'c' => array_values(array_slice($f['c'], $off)), 'v' => array_values(array_slice($f['v'], $off))];
}

/** Slice frame [start, end) by index. */
function br_slice(array $f, int $start, ?int $end = null): array {
    $cnt = count($f['t']); if ($end === null) $end = $cnt;
    $len = $end - $start;
    return ['t' => array_values(array_slice($f['t'], $start, $len)), 'o' => array_values(array_slice($f['o'], $start, $len)),
            'h' => array_values(array_slice($f['h'], $start, $len)), 'l' => array_values(array_slice($f['l'], $start, $len)),
            'c' => array_values(array_slice($f['c'], $start, $len)), 'v' => array_values(array_slice($f['v'], $start, $len))];
}

// ---------------------------------------------------------------------------
//  HTF gate: weekly green + 4H bullish
// ---------------------------------------------------------------------------

function br_htf_gate(array $w, array $h4): array {
    $nw = count($w['c']); if ($nw < 2) return [false, 'no'];
    // weekly: last CLOSED candle is green
    $wk = $w['c'][$nw - 2] > $w['o'][$nw - 2];   // second-to-last = last closed

    // 4H: already bullish or flipped (CHoCH)
    $e50 = t_ema($h4['c'], 50);
    $p4 = br_pivots($h4['l'], 2, true);
    $already = count($p4) >= 2
        && $h4['c'][count($h4['c'])-1] > end($e50)
        && $h4['l'][$p4[count($p4)-1]] > $h4['l'][$p4[count($p4)-2]];

    $h4s = $already ? 'already' : (br_choch_up($h4, 30) ? 'flipped' : 'no');
    return [$wk, $h4s];
}

// ---------------------------------------------------------------------------
//  Level families: struct, prior, pivot, fib, round
// ---------------------------------------------------------------------------

function br_levels(array $d, array $wc): array {
    $L = []; $nd = count($d['c']); $nw = count($wc['c']);

    // struct: 20/50-bar lows + pivot lows
    foreach ([20, 50] as $n) {
        $sl = array_slice($d['l'], max(0, $nd - $n));
        $mn = min($sl); $age = count($sl) - 1 - array_search($mn, $sl);
        $L[] = [$mn, 'struct', $age];
    }
    $pv = array_slice($d['l'], max(0, $nd - 150));
    foreach (br_pivots($pv, 5, true) as $i) {
        $L[] = [$pv[$i], 'struct', count($pv) - 1 - $i];
    }

    // prior: PDL, PDC, PWL
    $L[] = [$d['l'][$nd - 1], 'prior', 0];
    $L[] = [$d['c'][$nd - 1], 'prior', 0];
    if ($nw) { $L[] = [$wc['l'][$nw - 1], 'prior', 0]; }

    // monthly resample — approximate by grouping daily by month
    $months = [];
    for ($i = 0; $i < $nd; $i++) {
        $ym = date('Y-m', $d['t'][$i]);
        if (!isset($months[$ym])) $months[$ym] = ['h' => $d['h'][$i], 'l' => $d['l'][$i], 'c' => $d['c'][$i]];
        else { $months[$ym]['h'] = max($months[$ym]['h'], $d['h'][$i]); $months[$ym]['l'] = min($months[$ym]['l'], $d['l'][$i]); $months[$ym]['c'] = $d['c'][$i]; }
    }
    $mArr = array_values($months);
    if (count($mArr) > 1) {
        $pm = $mArr[count($mArr) - 2];   // last completed month
        $L[] = [$pm['l'], 'prior', 0];
    }

    // pivot points: weekly + monthly
    $refs = [];
    if ($nw) $refs[] = ['h' => $wc['h'][$nw - 1], 'l' => $wc['l'][$nw - 1], 'c' => $wc['c'][$nw - 1]];
    if (count($mArr) > 1) $refs[] = $mArr[count($mArr) - 2];
    foreach ($refs as $r) {
        $P = ($r['h'] + $r['l'] + $r['c']) / 3;
        $L[] = [$P, 'pivot', 0];
        $L[] = [2 * $P - $r['h'], 'pivot', 0];
        $L[] = [$P - ($r['h'] - $r['l']), 'pivot', 0];
    }

    // fib: 52-week range
    if ($nw >= 10) {
        $w52 = br_tail($wc, min(52, $nw));
        $hiIdx = array_keys($w52['h'], max($w52['h']))[0];
        $loSlice = array_slice($w52['l'], 0, $hiIdx + 1);
        if ($loSlice) { $loIdx = array_keys($loSlice, min($loSlice))[0]; } else $loIdx = $hiIdx;
        if ($hiIdx - $loIdx >= 4) {
            $hh = $w52['h'][$hiIdx]; $ll = $w52['l'][$loIdx];
            foreach ([0.382, 0.5, 0.618, 0.786] as $r) $L[] = [$hh - $r * ($hh - $ll), 'fib', 0];
        }
    }

    // round numbers
    $p = $d['c'][$nd - 1];
    if ($p > 0) {
        $step = pow(10, floor(log10($p)) - 1) / 2;
        $b = round($p / $step);
        for ($k = -12; $k <= 12; $k++) $L[] = [($b + $k) * $step, 'round', 0];
    }

    return $L;
}

// ---------------------------------------------------------------------------
//  Zone finding: best confluence cluster
// ---------------------------------------------------------------------------

function br_find_zone(array $d, array $wc, float $barLow): ?array {
    $atr = t_atr($d); $a = end($atr); if ($a <= 0) return null;
    $all = br_levels($d, $wc);
    $cands = [];
    foreach ($all as $lv) {
        if ($lv[0] >= $barLow - 0.3 * $a && $lv[0] <= $barLow + BR_CFG['smax'] * $a) $cands[] = $lv;
    }
    usort($cands, fn($a, $b) => $a[0] <=> $b[0]);

    $best = null; $i = 0; $n = count($cands);
    while ($i < $n) {
        $j = $i;
        while ($j + 1 < $n && $cands[$j + 1][0] - $cands[$i][0] <= BR_CFG['tol'] * $a) $j++;
        $grp = array_slice($cands, $i, $j - $i + 1);
        $fam = []; foreach ($grp as $g) $fam[$g[1]] = true;
        if ($best === null || count($fam) > count($best['fam'])) {
            $best = ['lo' => $grp[0][0], 'hi' => $grp[count($grp)-1][0], 'fam' => $fam, 'lv' => $grp, 'atr' => $a];
        }
        $i = $j + 1;
    }
    return $best;
}

// ---------------------------------------------------------------------------
//  Ground score (0–50)
// ---------------------------------------------------------------------------

function br_ground(array $d, array $wc, array $z): array {
    $a = $z['atr']; $nf = count($z['fam']);
    $s = ['confluence' => $nf >= 3 ? 15 : 5 * $nf];

    // age/touch
    $age = false;
    foreach ($z['lv'] as $lv) { if ($lv[1] === 'struct' && $lv[2] >= BR_CFG['age']) { $age = true; break; } }
    $nd = count($d['l']); $t = 0;
    for ($i = max(0, $nd - 60); $i < $nd; $i++) {
        if ($d['l'][$i] >= $z['lo'] - 0.25 * $a && $d['l'][$i] <= $z['hi'] + 0.25 * $a) $t++;
    }
    $s['age_touch'] = 5 * (int)$age + 5 * ($t >= 2 ? 1 : 0);

    // context (waterfall check)
    $rg = []; $cl = [];
    for ($i = max(0, $nd - 5); $i < $nd; $i++) {
        $rg[] = $d['h'][$i] - $d['l'][$i];
        $div = $d['h'][$i] - $d['l'][$i]; $cl[] = $div > 0 ? ($d['c'][$i] - $d['l'][$i]) / $div : 0.5;
    }
    $wfCount = 0; foreach ($rg as $ri => $r) { if ($r > $a && $cl[$ri] < 0.3) $wfCount++; }
    $waterfall = $wfCount >= 4 && end($rg) > $rg[0];
    $s['context'] = $waterfall ? 0 : 10;

    // HTF: weekly EMA21 near zone
    $e21 = t_ema($wc['c'], 21);
    $mid = ($z['lo'] + $z['hi']) / 2;
    $s['htf'] = abs(end($e21) - $mid) <= $a ? 10 : 0;

    // volume node
    $volAll = array_slice($d['v'], max(0, $nd - 60));
    $lAll = array_slice($d['l'], max(0, $nd - 60));
    $hAll = array_slice($d['h'], max(0, $nd - 60));
    $ov = []; $vSum = 0; $vCnt = 0;
    for ($i = 0; $i < count($volAll); $i++) {
        $vSum += $volAll[$i]; $vCnt++;
        if ($lAll[$i] <= $z['hi'] && $hAll[$i] >= $z['lo']) $ov[] = $volAll[$i];
    }
    $vMean = $vCnt ? $vSum / $vCnt : 0;
    $ovMean = count($ov) ? array_sum($ov) / count($ov) : 0;
    $s['volume'] = (count($ov) && $ovMean >= 1.2 * $vMean) ? 5 : 0;

    return [array_sum($s), $s, $waterfall];
}

// ---------------------------------------------------------------------------
//  Entry score + stage (zone-sweep path)
// ---------------------------------------------------------------------------

function br_entry(array $d, array $live, array $h1, array $h4, array $z): array {
    $a = $z['atr'];
    $depth = ($z['lo'] - $live['l']) / max($a, 1e-9);
    $swept = BR_CFG['smin'] <= $depth && $depth <= BR_CFG['smax'];
    $touch = $depth < BR_CFG['smin'] && $live['l'] <= $z['hi'] + 0.25 * $a;

    $rng = $live['h'] - $live['l']; $body = abs($live['c'] - $live['o']);
    $pin = $rng > 0 && (min($live['o'], $live['c']) - $live['l'] >= 2 * $body || $live['c'] >= $live['l'] + 0.5 * $rng);
    $upper = $rng > 0 && $live['c'] >= $live['l'] + 0.5 * $rng;

    // 1H reclaim
    $n1 = count($h1['c']); $tail = br_tail($h1, min(24, $n1));
    $tn = count($tail['c']); $li = array_keys($tail['l'], min($tail['l']))[0];
    $hrs = null;
    for ($i = $li; $i < $tn; $i++) { if ($tail['c'][$i] > $z['lo']) { $hrs = $i - $li; break; } }
    $reclaimed = $hrs !== null && $tail['c'][$tn - 1] > $z['lo'];

    // volume
    $volTail = array_slice($h1['v'], max(0, $n1 - 100));
    $volMean = count($volTail) ? array_sum($volTail) / count($volTail) : 1;
    $vol_ok = $tail['v'][$li] >= BR_CFG['vol'] * $volMean;

    $ch = br_choch_up($h1);

    // BB walk
    $bb = br_bb_lower($d['c'], 20, 2);
    $nd = count($d['c']); $walk = 0;
    for ($i = max(0, $nd - 5); $i < $nd; $i++) { if (isset($bb[$i]) && $d['c'][$i] < $bb[$i]) $walk++; }
    $bbOk = $walk < 3 && isset($bb[$nd - 1]) && $bb[$nd - 1] !== null && $live['l'] < $bb[$nd - 1] && $live['c'] > $bb[$nd - 1];

    $s = [
        'sweep' => 5 * (int)($swept || $touch) + 5 * (int)$pin,
        'absorption' => 8 * (int)$vol_ok + 7 * (int)$upper,
        'reclaim' => ($hrs !== null && $hrs <= BR_CFG['rec_h']) ? 10 : (($hrs !== null && $hrs <= 24) ? 5 : 0),
        'choch' => 10 * (int)$ch,
        'bb_candle' => 3 * (int)$bbOk + 2 * ($live['c'] > $live['o'] ? 1 : 0),
    ];

    $stage = 'WATCH';
    if ($swept && !$reclaimed) $stage = 'SWEPT';
    if (($swept && $reclaimed) || ($touch && !$swept && $live['c'] > $z['lo'])) $stage = 'E0';
    if ($stage === 'E0' && $ch && $s['absorption'] >= 7) $stage = 'E1';
    if ($stage === 'E1') {
        $t4 = br_tail($h4, 6); $nt4 = count($t4['c']);
        $k = array_keys($t4['l'], min($t4['l']))[0]; $post = $k + 1;
        $postN = $nt4 - $post;
        if ($postN >= 3) {
            $postVol = array_sum(array_slice($t4['v'], $post)) / max($postN, 1);
            if ($postVol < $t4['v'][$k] && $t4['c'][$nt4 - 1] > $z['lo']) $stage = 'E2';
        }
    }

    return [array_sum($s), $s, $stage, round($depth, 2), $swept];
}

// ---------------------------------------------------------------------------
//  E3: next-candle confirmation
// ---------------------------------------------------------------------------

function br_e3(array $d, array $wc, bool $closed = false): ?array {
    $n = count($d['c']); if ($n < 4) return null;
    $sig = ['o' => $d['o'][$n-2], 'h' => $d['h'][$n-2], 'l' => $d['l'][$n-2], 'c' => $d['c'][$n-2]];
    $cur = ['o' => $d['o'][$n-1], 'h' => $d['h'][$n-1], 'l' => $d['l'][$n-1], 'c' => $d['c'][$n-1]];
    $prev = br_slice($d, 0, $n - 2);
    $z = br_find_zone($prev, $wc, $sig['l']); if (!$z) return null;
    $a = $z['atr'];
    $ok = (BR_CFG['smin'] <= ($z['lo'] - $sig['l']) / max($a, 1e-9) && ($z['lo'] - $sig['l']) / max($a, 1e-9) <= BR_CFG['smax'] && $sig['c'] > $z['lo'])
        || ($sig['l'] <= $z['hi'] + 0.25 * $a && $sig['l'] >= $z['lo'] - BR_CFG['smin'] * $a);
    if (!$ok) return null;

    $lvl = $closed ? $sig['h'] : ($sig['h'] + $sig['l']) / 2;
    if ($cur['l'] > $sig['l'] && $cur['c'] > $cur['o'] && $cur['c'] > $lvl) {
        [$g, $gs, $wf] = br_ground($prev, $wc, $z);
        return [$z, [$g, $gs, $wf], $closed ? 'strict' : 'soft'];
    }
    return null;
}

// ---------------------------------------------------------------------------
//  Pattern detection: hammer, engulfing, piercing, morning star
// ---------------------------------------------------------------------------

function br_pattern_at(array $O, array $H, array $L, array $C, int $p): ?string {
    $o = $O[$p]; $h = $H[$p]; $l = $L[$p]; $c = $C[$p]; $rng = $h - $l;
    if ($rng <= 0) return null;
    $body = abs($c - $o); $lower = min($o, $c) - $l; $upper = $h - max($o, $c);
    if ($lower >= 2 * $body && $lower >= 0.5 * $rng && $upper <= 0.25 * $rng) return 'hammer';
    if ($p >= 1 && $C[$p-1] < $O[$p-1] && $c > $o) {
        $po = $O[$p-1]; $pc = $C[$p-1]; $pb = $po - $pc; $tol = 0.1 * $pb;
        if ($o <= $pc + $tol && $c >= $po) return 'engulfing';
        if ($pb >= 0.4 * ($H[$p-1] - $L[$p-1]) && $o <= $pc + $tol && ($po + $pc) / 2 < $c && $c < $po) return 'piercing';
    }
    if ($p >= 2 && $C[$p-2] < $O[$p-2] && $c > $o) {
        $o1 = $O[$p-2]; $c1 = $C[$p-2]; $b1 = $o1 - $c1;
        if ($b1 >= 0.5 * ($H[$p-2] - $L[$p-2]) && abs($O[$p-1] - $C[$p-1]) <= 0.3 * $b1 && $c > ($o1 + $c1) / 2) return 'morning_star';
    }
    return null;
}

// ---------------------------------------------------------------------------
//  Gate events: 2 of 3 {liquidity sweep, BB sweep, CHoCH}
// ---------------------------------------------------------------------------

function br_gate_events(array $x, array $wc, int $nb): ?array {
    $m = count($x['c']); if ($m < $nb + 5) return null;
    $bb = br_bb_lower($x['c']);

    // pre-compute rolling min of shifted lows for liquidity sweep
    $first = [];

    // liquidity sweep: low < prior-low rolling min, close > that min
    $pm = []; // prior-low rolling min(liq_lb)
    for ($i = 0; $i < $m; $i++) {
        $start = max(1, $i - BR_CFG['liq_lb'] + 1);
        $mn = PHP_FLOAT_MAX;
        for ($j = $start; $j < $i; $j++) $mn = min($mn, $x['l'][$j]);
        $pm[$i] = $mn;
    }
    $liq = [];
    for ($i = $m - $nb; $i < $m; $i++) {
        $liq[] = ($x['l'][$i] < $pm[$i] && $x['c'][$i] > $pm[$i]);
    }

    // BB sweep: low < BB lower, close > BB lower, no walk (walk = 3+ closes below BB in last 5)
    $bbArr = [];
    for ($i = $m - $nb; $i < $m; $i++) {
        if ($bb[$i] === null) { $bbArr[] = false; continue; }
        $walk = 0;
        for ($j = max(0, $i - 4); $j <= $i; $j++) { if ($bb[$j] !== null && $x['c'][$j] < $bb[$j]) $walk++; }
        $bbArr[] = ($walk < 3 && $x['l'][$i] < $bb[$i] && $x['c'][$i] > $bb[$i]);
    }
    // weekly BB wick counts
    $nw = count($wc['c']); $wBB = br_bb_lower($wc['c']);
    $wHit = false;
    for ($i = max(0, $nw - BR_CFG['bb_week_lb']); $i < $nw; $i++) {
        if ($wBB[$i] !== null && $wc['l'][$i] < $wBB[$i] && $wc['c'][$i] > $wBB[$i]) { $wHit = true; break; }
    }
    if ($wHit && count($bbArr)) $bbArr[0] = true;

    // CHoCH events: CHoCH flips from false to true inside the window
    $ch = [];
    for ($j = $nb; $j >= 0; $j--) {
        $sub = br_slice($x, 0, $m - $j);
        $ch[] = br_choch_up($sub, BR_CFG['choch_win']);
    }
    $chev = [];
    for ($i = 1; $i <= $nb; $i++) $chev[] = ($ch[$i] && !$ch[$i - 1]);

    // find first occurrence of each
    $firstOf = [];
    foreach (['liq' => $liq, 'bb' => $bbArr, 'choch' => $chev] as $k => $arr) {
        foreach ($arr as $idx => $v) { if ($v) { $firstOf[$k] = $idx; break; } }
    }
    if (count($firstOf) < 2) return null;

    $sorted = $firstOf; asort($sorted); $vals = array_values($sorted);
    return ['events' => array_keys($sorted), 'gate_abs' => $m - $nb + $vals[1]];
}

// ---------------------------------------------------------------------------
//  Strong tag
// ---------------------------------------------------------------------------

function br_strong_tag(array $dc, array $h4): bool {
    $nd = count($dc['c']); $nh = count($h4['c']);
    if ($nd < 5 || $nh < 21) return false;
    $e5 = t_ema($dc['c'], 5);
    $e9 = t_ema($h4['c'], 9); $e21 = t_ema($h4['c'], 21);
    $cross = end($e9) > end($e21);
    if (!$cross) return false;
    // check if e9 was <= e21 in the last 12 bars
    $hadBelow = false;
    for ($i = max(0, $nh - 13); $i < $nh - 1; $i++) {
        if ($e9[$i] <= $e21[$i]) { $hadBelow = true; break; }
    }
    return $dc['c'][$nd - 1] > end($e5) && $hadBelow;
}

// ---------------------------------------------------------------------------
//  Bottom Reversal core
// ---------------------------------------------------------------------------

function br_core(array $x, array $ref, array $wc, bool $wk, int $nb, string $tf): ?array {
    $m = count($x['c']); $nr = count($ref['c']);
    if ($m < 60 || count($wc['c']) < 25 || $nr < 60) return null;

    $rangeBars = min(BR_CFG['range_bars'], $nr);
    $rSlice = br_tail($ref, $rangeBars);
    $lo = min($rSlice['l']); $hi = max($rSlice['h']);

    $O = $x['o']; $H = $x['h']; $L = $x['l']; $C = $x['c'];

    $buildFn = function(string $stage, array $g, ?string $pat) use ($C, $x, $m, $lo, $hi, $wk, $tf) {
        $ga = $g['gate_abs'];
        $pos = ($hi > $lo) ? ($C[$ga] - $lo) / ($hi - $lo) : 1.0;
        if ($pos > BR_CFG['bottom_pct']) return null;    // not at the bottom

        $start = max(0, $ga + 1 - BR_CFG['cancel_lb']);
        $bl = PHP_FLOAT_MAX;
        for ($i = $start; $i <= $ga; $i++) $bl = min($bl, $x['l'][$i]);
        // cancel: close below the bottom
        for ($i = $ga + 1; $i < $m; $i++) { if ($C[$i] < $bl) return null; }

        if ($stage !== 'GATE' && !$wk) $stage = 'GATE';
        return ['setup' => 'bottom_reversal', 'tf' => $tf, 'stage' => $stage, 'pattern' => $pat,
                'gate' => $g['events'], 'bottom_pos' => (int)round($pos * 100), 'cancel_below' => $bl,
                'alert' => $stage === 'ENTRY'];
    };

    // try ENTRY: gate as of pattern candle, next candle confirms
    $out = null;
    $xPrev = br_slice($x, 0, $m - 1);
    $g = br_gate_events($xPrev, $wc, $nb);
    $pp = br_pattern_at($O, $H, $L, $C, $m - 2);
    if ($g && $pp && $C[$m-1] > $O[$m-1] && $C[$m-1] > $H[$m-2]) {
        $out = $buildFn('ENTRY', $g, $pp);
    }

    if ($out === null) {
        $g = br_gate_events($x, $wc, $nb);
        if ($g) {
            $pl = br_pattern_at($O, $H, $L, $C, $m - 1);
            $out = $buildFn($pl ? 'PATTERN' : 'GATE', $g, $pl);
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
//  Main scan function
// ---------------------------------------------------------------------------

/** Scan one symbol. Returns array of signal dicts. */
function br_scan(string $sym, array $frames, string $market = 'crypto'): array {
    $h1 = $frames['1H'] ?? null;
    $d  = $frames['1D'] ?? null;
    if (!$h1 || !$d || count($h1['c']) < 100 || count($d['c']) < 60) return [];

    $h4 = br_agg_4h($h1);
    $tz = $frames['tz'] ?? 'UTC';
    $w  = br_agg_1w($d, $tz);

    $nw = count($w['c']); $nd = count($d['c']); $nh1 = count($h1['c']);
    if ($nw < 5) return [];

    [$wk, $h4s] = br_htf_gate($w, $h4);

    // closed bars
    $wc = br_slice($w, 0, max(0, $nw - 1));
    $dc = br_slice($d, 0, max(0, $nd - 1));
    // live bar
    $live = ['o' => $d['o'][$nd-1], 'h' => $d['h'][$nd-1], 'l' => $d['l'][$nd-1], 'c' => $d['c'][$nd-1]];

    $price = $h1['c'][$nh1 - 1];
    $base = ['symbol' => $sym, 'market' => $market, 'weekly' => $wk ? 'bullish' : 'red', 'h4' => $h4s, 'price' => round($price, 6)];

    $res = [];

    // -- Bottom Reversal (closed candles, no repaint) --
    $runs = [[$dc, '1d', BR_CFG['gate_bars']]];
    if (BR_CFG['fast_4h']) $runs[] = [$h4, '4h', BR_CFG['gate_bars_4h']];

    foreach ($runs as [$xf, $tf, $n]) {
        $r = br_core($xf, $dc, $wc, $wk, $n, $tf);
        if (!$r) continue;
        $r['ground'] = 0; $r['zone'] = null; $r['families'] = []; $r['strong'] = br_strong_tag($dc, $h4);
        try {
            $z = br_find_zone($dc, $wc, $r['cancel_below']);
            if ($z) {
                [$g, $gs, $wf] = br_ground($dc, $wc, $z);
                $r['ground'] = $g; $r['zone'] = [$z['lo'], $z['hi']]; $r['families'] = array_keys($z['fam']); $r['waterfall'] = $wf;
            }
        } catch (\Throwable $e) {}
        $r['rank'] = ['ENTRY' => 100, 'PATTERN' => 60, 'GATE' => 30][$r['stage']] + $r['ground'] + 10 * (int)$r['strong'];
        $res[] = array_merge($base, $r);
    }

    // -- Zone-sweep path (uses live daily bar) --
    if (!$wk || (BR_CFG['h4_gate'] && $h4s === 'no')) return $res;

    $out = array_merge($base, ['setup' => 'zone_sweep']);

    // E3 check
    $r3 = br_e3($d, $wc, true);
    if ($r3 && $r3[1][0] >= BR_CFG['ground_min']) {
        [$zs, [$g, $gs, $wf], $mode] = $r3;
        $res[] = array_merge($out, ['stage' => 'E3', 'e3_mode' => $mode, 'ground' => $g, 'entry' => null,
            'zone' => [$zs['lo'], $zs['hi']], 'families' => array_keys($zs['fam']), 'alert' => !$wf, 'rank' => $g + 25]);
        return $res;
    }

    $z = br_find_zone($dc, $wc, $live['l']); if (!$z) return $res;
    [$g, $gs, $wf] = br_ground($dc, $wc, $z);
    [$e, $es, $stage, $depth, $swept] = br_entry($dc, $live, $h1, $h4, $z);
    $res[] = array_merge($out, ['stage' => $stage, 'ground' => $g, 'entry' => $e, 'zone' => [$z['lo'], $z['hi']],
        'families' => array_keys($z['fam']), 'depth_atr' => $depth, 'swept' => $swept, 'waterfall' => $wf,
        'rank' => $g + $e, 'alert' => $g >= BR_CFG['ground_min'] && $e >= BR_CFG['entry_min'] && in_array($stage, ['E1','E2']) && !$wf]);

    return $res;
}
