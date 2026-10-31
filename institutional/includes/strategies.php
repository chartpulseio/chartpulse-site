<?php
/**
 * Institutional strategies on daily bars. Each returns a signal array (see ta_sig) or null.
 * Daily candles carry no tick or session data, so killzone, footprint and opening-range ideas run as daily proxies.
 */
require_once dirname(__DIR__, 2) . '/ema-strategies/includes/ta.php';

/** Liquidity sweep of the prior 20-bar extreme on bar $j, reclaimed on the close. Returns 1 (bull), -1 (bear) or 0. */
function is_sweep(array $B, int $j): int {
    if ($j < 21) return 0;
    if ($B['l'][$j] < ta_minr($B['l'], $j - 20, $j - 1) && $B['c'][$j] > ta_minr($B['l'], $j - 20, $j - 1)) return 1;
    if ($B['h'][$j] > ta_maxr($B['h'], $j - 20, $j - 1) && $B['c'][$j] < ta_maxr($B['h'], $j - 20, $j - 1)) return -1;
    return 0;
}

/** 1. SMC Power of 3 (daily): sweep -> displacement -> pullback into the 0.62-0.79 OTE zone of the leg. */
function is_1(array $B, array $X): ?array {
    $n = $X['n']; [$c, $o, $h, $l] = [$B['c'], $B['o'], $B['h'], $B['l']]; $atr = $X['atr'][$n - 1];
    for ($j = $n - 2; $j >= $n - 7; $j--) {
        $sw = is_sweep($B, $j); if (!$sw) continue;
        if ($sw == 1) {
            $hh = ta_maxr($h, $j, $n - 1); $r = $hh - $l[$j]; if ($r < 2 * $atr) continue;
            if ($c[$n - 1] <= $hh - 0.62 * $r && $c[$n - 1] >= $hh - 0.79 * $r && $c[$n - 1] > $o[$n - 1]) {
                $s = 62 + ($hh > ta_maxr($h, $j - 10, $j - 1) ? 10 : 0) + (abs($c[$n - 1] - ($hh - 0.705 * $r)) <= 0.3 * $atr ? 10 : 0) + ($X['hasv'] && $B['v'][$j] > 1.2 * $X['avgv'] ? 10 : 0);
                return ta_sig('CALL', $B, $X, $s, 'Sweep + displacement, pullback into OTE (daily)', 'SMC');
            }
        } else {
            $ll = ta_minr($l, $j, $n - 1); $r = $h[$j] - $ll; if ($r < 2 * $atr) continue;
            if ($c[$n - 1] >= $ll + 0.62 * $r && $c[$n - 1] <= $ll + 0.79 * $r && $c[$n - 1] < $o[$n - 1]) {
                $s = 62 + ($ll < ta_minr($l, $j - 10, $j - 1) ? 10 : 0) + (abs($c[$n - 1] - ($ll + 0.705 * $r)) <= 0.3 * $atr ? 10 : 0) + ($X['hasv'] && $B['v'][$j] > 1.2 * $X['avgv'] ? 10 : 0);
                return ta_sig('PUT', $B, $X, $s, 'Sweep + displacement, pullback into OTE (daily)', 'SMC');
            }
        }
    }
    return null;
}

/** 2. Order flow proxy: cumulative volume delta (close-location weighted) diverging from price. Needs volume. */
function is_2(array $B, array $X): ?array {
    $n = $X['n']; if (!$X['hasv'] || $n < 30) return null;
    [$c, $o, $h, $l, $v] = [$B['c'], $B['o'], $B['h'], $B['l'], $B['v']]; $cvd = array_fill(0, $n, 0.0); $run = 0.0;
    for ($i = $n - 40; $i < $n; $i++) {
        $rng = $h[$i] - $l[$i]; $run += $rng > 0 ? $v[$i] * (($c[$i] - $l[$i]) - ($h[$i] - $c[$i])) / $rng : 0; $cvd[$i] = $run;
    }
    $ra = $n - 10; $pa = $n - 20; $pb = $n - 11;
    $llow = ta_minr($l, $ra, $n - 1) < ta_minr($l, $pa, $pb); $cHigher = ta_minr($cvd, $ra, $n - 1) > ta_minr($cvd, $pa, $pb);
    $hhigh = ta_maxr($h, $ra, $n - 1) > ta_maxr($h, $pa, $pb); $cLower = ta_maxr($cvd, $ra, $n - 1) < ta_maxr($cvd, $pa, $pb);
    $big = $v[$n - 1] > 1.2 * $X['avgv'];
    if ($llow && $cHigher && $c[$n - 1] > $o[$n - 1]) return ta_sig('CALL', $B, $X, 64 + ($big ? 10 : 0) + ($c[$n - 1] > $c[$n - 2] ? 10 : 0), 'Bullish CVD divergence (proxy)', 'Flow');
    if ($hhigh && $cLower && $c[$n - 1] < $o[$n - 1]) return ta_sig('PUT', $B, $X, 64 + ($big ? 10 : 0) + ($c[$n - 1] < $c[$n - 2] ? 10 : 0), 'Bearish CVD divergence (proxy)', 'Flow');
    return null;
}

/** 3. Liquidity sweep in the last 15 bars, a fair value gap left behind, price now retesting the gap and holding. */
function is_3(array $B, array $X): ?array {
    $n = $X['n']; [$c, $o, $h, $l] = [$B['c'], $B['o'], $B['h'], $B['l']]; $atr = $X['atr'][$n - 1];
    $sw = 0; $sj = 0;
    for ($j = $n - 2; $j >= $n - 15; $j--) { $s = is_sweep($B, $j); if ($s) { $sw = $s; $sj = $j; break; } }
    if (!$sw) return null;
    for ($i = $n - 2; $i >= max($sj, 2); $i--) {
        if ($sw == 1 && $l[$i] > $h[$i - 2] && $l[$n - 1] <= $l[$i] && $c[$n - 1] >= $h[$i - 2] && $c[$n - 1] > $o[$n - 1]) {
            $s = 62 + ($n - 1 - $sj <= 7 ? 10 : 0) + (($l[$i] - $h[$i - 2]) >= 0.5 * $atr ? 10 : 0) + ($c[$n - 1] > $c[$n - 2] ? 10 : 0);
            return ta_sig('CALL', $B, $X, $s, 'Sweep + bullish FVG retest', 'FVG');
        }
        if ($sw == -1 && $h[$i] < $l[$i - 2] && $h[$n - 1] >= $h[$i] && $c[$n - 1] <= $l[$i - 2] && $c[$n - 1] < $o[$n - 1]) {
            $s = 62 + ($n - 1 - $sj <= 7 ? 10 : 0) + (($l[$i - 2] - $h[$i]) >= 0.5 * $atr ? 10 : 0) + ($c[$n - 1] < $c[$n - 2] ? 10 : 0);
            return ta_sig('PUT', $B, $X, $s, 'Sweep + bearish FVG retest', 'FVG');
        }
    }
    return null;
}

/** 4. Daily range breakout (10-bar), volume above 1.8x average, price beyond the 20-day VWAP. Needs volume. */
function is_4(array $B, array $X): ?array {
    $n = $X['n']; if (!$X['hasv'] || $n < 30) return null;
    [$c, $h, $l, $v] = [$B['c'], $B['h'], $B['l'], $B['v']]; $e = $X['e200'][$n - 1];
    $avg = array_sum(array_slice($v, $n - 21, 20)) / 20; if ($avg <= 0 || $v[$n - 1] < 1.8 * $avg) return null;
    $vw = ta_vwap($B, $n - 20, $n - 1); $rng = max($h[$n - 1] - $l[$n - 1], 1e-12);
    if ($c[$n - 1] > ta_maxr($h, $n - 11, $n - 2) && $c[$n - 1] > $vw) {
        $s = 62 + ($v[$n - 1] >= 2.5 * $avg ? 10 : 0) + (($c[$n - 1] - $l[$n - 1]) / $rng > 0.7 ? 10 : 0) + ($e !== null && $c[$n - 1] > $e ? 10 : 0);
        return ta_sig('CALL', $B, $X, $s, '10-day range breakout + volume spike (daily)', 'Breakout');
    }
    if ($c[$n - 1] < ta_minr($l, $n - 11, $n - 2) && $c[$n - 1] < $vw) {
        $s = 62 + ($v[$n - 1] >= 2.5 * $avg ? 10 : 0) + (($h[$n - 1] - $c[$n - 1]) / $rng > 0.7 ? 10 : 0) + ($e !== null && $c[$n - 1] < $e ? 10 : 0);
        return ta_sig('PUT', $B, $X, $s, '10-day range breakdown + volume spike (daily)', 'Breakout');
    }
    return null;
}

/** 5. Broken swing level flips polarity; price retests it from the right side and holds; weekly trend agrees. */
function is_5(array $B, array $X): ?array {
    $n = $X['n']; [$c, $o, $h, $l] = [$B['c'], $B['o'], $B['h'], $B['l']]; $atr = $X['atr'][$n - 1];
    $wk = []; for ($i = $n - 1; $i >= 0; $i -= 5) array_unshift($wk, $c[$i]);
    $we = ta_ema_series($wk, 10); $m = count($we); if ($m < 12 || $we[$m - 1] === null) return null;
    $wUp = $c[$n - 1] > $we[$m - 1] && $we[$m - 1] > $we[$m - 3]; $wDn = $c[$n - 1] < $we[$m - 1] && $we[$m - 1] < $we[$m - 3];
    if ($wUp) {
        foreach (array_reverse(ta_pivots($h, 3, true, $n - 60)) as $p) {
            if ($p > $n - 9) continue; $lvl = $h[$p]; $brk = 0;
            for ($j = max($p + 1, $n - 8); $j <= $n - 2; $j++) if ($c[$j] > $lvl + 0.3 * $atr) { $brk = $j; break; }
            if ($brk && $l[$n - 1] <= $lvl + 0.5 * $atr && $c[$n - 1] > $lvl && $c[$n - 1] > $o[$n - 1] && ta_minr($l, $brk, $n - 1) >= $lvl - 0.5 * $atr) {
                $s = 64 + (($c[$brk] - $o[$brk]) >= $atr ? 10 : 0) + (abs($l[$n - 1] - $lvl) <= 0.25 * $atr ? 10 : 0);
                return ta_sig('CALL', $B, $X, $s, 'Resistance flipped to support, retest holds (weekly up)', 'Supply/Demand');
            }
        }
    }
    if ($wDn) {
        foreach (array_reverse(ta_pivots($l, 3, false, $n - 60)) as $p) {
            if ($p > $n - 9) continue; $lvl = $l[$p]; $brk = 0;
            for ($j = max($p + 1, $n - 8); $j <= $n - 2; $j++) if ($c[$j] < $lvl - 0.3 * $atr) { $brk = $j; break; }
            if ($brk && $h[$n - 1] >= $lvl - 0.5 * $atr && $c[$n - 1] < $lvl && $c[$n - 1] < $o[$n - 1] && ta_maxr($h, $brk, $n - 1) <= $lvl + 0.5 * $atr) {
                $s = 64 + (($o[$brk] - $c[$brk]) >= $atr ? 10 : 0) + (abs($h[$n - 1] - $lvl) <= 0.25 * $atr ? 10 : 0);
                return ta_sig('PUT', $B, $X, $s, 'Support flipped to resistance, retest holds (weekly down)', 'Supply/Demand');
            }
        }
    }
    return null;
}

/** 6. Bollinger squeeze (bandwidth in the lowest 20% of 120 bars within the last 5 bars) + RSI(2) extreme. */
function is_6(array $B, array $X): ?array {
    $n = $X['n']; $c = $B['c']; if ($n < 140) return null;
    $bw = [];
    for ($i = $n - 124; $i < $n; $i++) {
        $w = array_slice($c, $i - 19, 20); $m = array_sum($w) / 20;
        $sd = sqrt(array_sum(array_map(fn($x) => ($x - $m) ** 2, $w)) / 20);
        $bw[$i] = $m > 0 ? 4 * $sd / $m : 0;
    }
    $hist = array_slice($bw, -120); sort($hist); $p20 = $hist[(int)floor(0.2 * 119)];
    if (min(array_slice($bw, -5)) > $p20) return null;
    $r2 = ta_rsi_series($c, 2)[$n - 1]; if ($r2 === null) return null;
    $r14 = $X['rsi'][$n - 1];
    if ($r2 < 10) return ta_sig('CALL', $B, $X, 62 + ($r2 < 5 ? 10 : 0) + ($r14 < 40 ? 10 : 0) + ($c[$n - 1] < $c[$n - 2] ? 8 : 0), 'BB squeeze + RSI(2) oversold', 'Mean Rev');
    if ($r2 > 90) return ta_sig('PUT', $B, $X, 62 + ($r2 > 95 ? 10 : 0) + ($r14 > 60 ? 10 : 0) + ($c[$n - 1] > $c[$n - 2] ? 8 : 0), 'BB squeeze + RSI(2) overbought', 'Mean Rev');
    return null;
}

/** 7. Price stretched 1.2 ATR from the 20-day VWAP while still on the trend side of the 60-bar anchored VWAP; target = VWAP. */
function is_7(array $B, array $X): ?array {
    $n = $X['n']; [$c, $h, $l] = [$B['c'], $B['h'], $B['l']]; $atr = $X['atr'][$n - 1];
    $vw = ta_vwap($B, $n - 20, $n - 1); if ($vw === null) return null;
    $aLow = ta_argmin($l, $n - 60, $n - 1); $aHigh = ta_argmax($h, $n - 60, $n - 1);
    $avL = ta_vwap($B, $aLow, $n - 1); $avH = ta_vwap($B, $aHigh, $n - 1);
    if ($c[$n - 1] < $vw - 1.2 * $atr && $c[$n - 1] > $avL) {
        $s = 62 + ($c[$n - 1] < $vw - 2 * $atr ? 10 : 0) + ($X['rsi'][$n - 1] < 40 ? 10 : 0) + ($c[$n - 1] > $c[$n - 2] ? 8 : 0);
        return ta_sig('CALL', $B, $X, $s, 'Stretched below VWAP(20), above anchored VWAP', 'VWAP', ta_r($vw));
    }
    if ($c[$n - 1] > $vw + 1.2 * $atr && $c[$n - 1] < $avH) {
        $s = 62 + ($c[$n - 1] > $vw + 2 * $atr ? 10 : 0) + ($X['rsi'][$n - 1] > 60 ? 10 : 0) + ($c[$n - 1] < $c[$n - 2] ? 8 : 0);
        return ta_sig('PUT', $B, $X, $s, 'Stretched above VWAP(20), below anchored VWAP', 'VWAP', ta_r($vw));
    }
    return null;
}

const SCAN_MIN_BARS = 80;
function scan_map(): array {
    return [
        '1-smc-power-of-3' => 'is_1', '2-order-flow-cvd' => 'is_2', '3-liquidity-fvg' => 'is_3', '4-orb-vwap' => 'is_4',
        '5-supply-demand' => 'is_5', '6-mean-reversion' => 'is_6', '7-vwap-anchored' => 'is_7',
    ];
}
