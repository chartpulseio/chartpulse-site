<?php
/** EMA strategies on daily bars. Each returns a signal array (see ta_sig) or null. */
require_once __DIR__ . '/ta.php';

/** 1. EMA 200 trend + liquidity sweep of the prior 20-bar extreme, reclaimed, back near the EMA (order-block zone). */
function es_1(array $B, array $X): ?array {
    $n = $X['n']; $e = $X['e200'][$n - 1]; $e10 = $X['e200'][$n - 11] ?? null;
    if ($e === null || $e10 === null) return null;
    [$c, $o, $h, $l] = [$B['c'], $B['o'], $B['h'], $B['l']]; $atr = $X['atr'][$n - 1];
    for ($k = 0; $k < 3; $k++) {
        $i = $n - 1 - $k; $rng = max($h[$i] - $l[$i], 1e-12);
        $pl = ta_minr($l, $i - 20, $i - 1); $ph = ta_maxr($h, $i - 20, $i - 1);
        $vol = $X['hasv'] && $B['v'][$i] > 1.2 * $X['avgv'];
        if ($l[$i] < $pl && $c[$i] > $pl && $c[$n - 1] > $e && $e > $e10) {
            $s = 60 + (($c[$i] - $l[$i]) / $rng > 0.5 ? 10 : 0) + (abs($c[$n - 1] - $e) <= 1.5 * $atr ? 10 : 0) + ($c[$n - 1] > $c[$i] ? 10 : 0) + ($vol ? 10 : 0);
            return ta_sig('CALL', $B, $X, $s, 'Liquidity sweep + reclaim above EMA 200', 'Sweep');
        }
        if ($h[$i] > $ph && $c[$i] < $ph && $c[$n - 1] < $e && $e < $e10) {
            $s = 60 + (($h[$i] - $c[$i]) / $rng > 0.5 ? 10 : 0) + (abs($c[$n - 1] - $e) <= 1.5 * $atr ? 10 : 0) + ($c[$n - 1] < $c[$i] ? 10 : 0) + ($vol ? 10 : 0);
            return ta_sig('PUT', $B, $X, $s, 'Liquidity sweep + rejection below EMA 200', 'Sweep');
        }
    }
    return null;
}

/** 2. RSI divergence on the last two confirmed swings + engulfing candle, trading with the EMA 200 side. */
function es_2(array $B, array $X): ?array {
    $n = $X['n']; $e = $X['e200'][$n - 1]; if ($e === null) return null;
    [$c, $o, $h, $l] = [$B['c'], $B['o'], $B['h'], $B['l']]; $rsi = $X['rsi']; $atr = $X['atr'][$n - 1];
    $bull = false; $bear = false; $body = 0.0;
    foreach ([$n - 1, $n - 2] as $i) {
        if ($c[$i] > $o[$i] && $c[$i - 1] < $o[$i - 1] && $c[$i] >= $o[$i - 1] && $o[$i] <= $c[$i - 1]) { $bull = true; $body = max($body, $c[$i] - $o[$i]); }
        if ($c[$i] < $o[$i] && $c[$i - 1] > $o[$i - 1] && $c[$i] <= $o[$i - 1] && $o[$i] >= $c[$i - 1]) { $bear = true; $body = max($body, $o[$i] - $c[$i]); }
    }
    if ($bull && $c[$n - 1] > $e) {
        $p = ta_pivots($l, 3, false, $n - 60);
        if (count($p) >= 2) { [$p1, $p2] = array_slice($p, -2);
            if ($p2 >= $n - 15 && $l[$p2] < $l[$p1] && $rsi[$p2] > $rsi[$p1] + 2) {
                $s = 62 + ($rsi[$p2] < 40 ? 10 : 0) + ($body >= 0.8 * $atr ? 10 : 0) + ($rsi[$p2] - $rsi[$p1] > 5 ? 8 : 0);
                return ta_sig('CALL', $B, $X, $s, 'Bullish RSI divergence + engulfing', 'Divergence');
            } }
    }
    if ($bear && $c[$n - 1] < $e) {
        $p = ta_pivots($h, 3, true, $n - 60);
        if (count($p) >= 2) { [$p1, $p2] = array_slice($p, -2);
            if ($p2 >= $n - 15 && $h[$p2] > $h[$p1] && $rsi[$p2] < $rsi[$p1] - 2) {
                $s = 62 + ($rsi[$p2] > 60 ? 10 : 0) + ($body >= 0.8 * $atr ? 10 : 0) + ($rsi[$p1] - $rsi[$p2] > 5 ? 8 : 0);
                return ta_sig('PUT', $B, $X, $s, 'Bearish RSI divergence + engulfing', 'Divergence');
            } }
    }
    return null;
}

/** 3. EMA 21 > 50 > 200 stacked, pullback to the 21 EMA, close back above it (mirror for shorts). */
function es_3(array $B, array $X): ?array {
    $n = $X['n']; [$a, $b, $e] = [$X['e21'][$n - 1], $X['e50'][$n - 1], $X['e200'][$n - 1]];
    if ($a === null || $b === null || $e === null || $n < 40) return null;
    [$c, $o, $h, $l] = [$B['c'], $B['o'], $B['h'], $B['l']]; $rsi = $X['rsi'][$n - 1];
    $gap = $X['e21'][$n - 1] - $X['e50'][$n - 1]; $gap10 = $X['e21'][$n - 11] - $X['e50'][$n - 11];
    if ($a > $b && $b > $e && $a > $X['e21'][$n - 6] && ta_minr($l, $n - 3, $n - 1) <= $a * 1.002 && $c[$n - 1] > $a) {
        $s = 60 + ($e > $X['e200'][$n - 21] ? 10 : 0) + ($rsi >= 50 && $rsi <= 70 ? 10 : 0) + ($gap > $gap10 ? 10 : 0) + ($c[$n - 1] > $o[$n - 1] ? 8 : 0);
        return ta_sig('CALL', $B, $X, $s, 'EMA 21/50/200 stacked, pullback to EMA 21', 'Trend');
    }
    if ($a < $b && $b < $e && $a < $X['e21'][$n - 6] && ta_maxr($h, $n - 3, $n - 1) >= $a * 0.998 && $c[$n - 1] < $a) {
        $s = 60 + ($e < $X['e200'][$n - 21] ? 10 : 0) + ($rsi >= 30 && $rsi <= 50 ? 10 : 0) + ($gap < $gap10 ? 10 : 0) + ($c[$n - 1] < $o[$n - 1] ? 8 : 0);
        return ta_sig('PUT', $B, $X, $s, 'EMA 21/50/200 stacked, pullback to EMA 21', 'Trend');
    }
    return null;
}

/** 4. A swing support/resistance level sits on the EMA 200, price is at it, and volume spikes 1.4x. Needs volume data. */
function es_4(array $B, array $X): ?array {
    $n = $X['n']; $e = $X['e200'][$n - 1]; if ($e === null || !$X['hasv']) return null;
    [$c, $o, $h, $l, $v] = [$B['c'], $B['o'], $B['h'], $B['l'], $B['v']]; $atr = $X['atr'][$n - 1];
    if (abs($c[$n - 1] - $e) > $atr || $v[$n - 1] < 1.4 * $X['avgv']) return null;
    $lv = 0;
    foreach (ta_pivots($h, 3, true, $n - 150) as $i) if (abs($h[$i] - $e) <= 0.5 * $atr) $lv++;
    foreach (ta_pivots($l, 3, false, $n - 150) as $i) if (abs($l[$i] - $e) <= 0.5 * $atr) $lv++;
    if ($lv < 1) return null;
    $s = 60 + ($v[$n - 1] >= 2 * $X['avgv'] ? 10 : 0) + ($lv >= 2 ? 10 : 0) + (abs($c[$n - 1] - $o[$n - 1]) >= 0.5 * $atr ? 10 : 0);
    if ($c[$n - 1] > $e && $c[$n - 1] > $o[$n - 1]) return ta_sig('CALL', $B, $X, $s, 'Support on EMA 200 + volume spike', 'S/R + Vol');
    if ($c[$n - 1] < $e && $c[$n - 1] < $o[$n - 1]) return ta_sig('PUT', $B, $X, $s, 'Resistance on EMA 200 + volume spike', 'S/R + Vol');
    return null;
}

/** 5. Fresh cross of the rolling 20-day VWAP in the direction of the EMA 200 side. */
function es_5(array $B, array $X): ?array {
    $n = $X['n']; $e = $X['e200'][$n - 1]; if ($e === null) return null;
    [$c, $atr, $rsi] = [$B['c'], $X['atr'][$n - 1], $X['rsi'][$n - 1]]; $vw = [];
    for ($i = $n - 4; $i < $n; $i++) $vw[$i] = ta_vwap($B, $i - 19, $i);
    $up = false; $dn = false;
    for ($j = $n - 3; $j < $n; $j++) {
        if ($c[$j - 1] <= $vw[$j - 1] && $c[$j] > $vw[$j]) $up = true;
        if ($c[$j - 1] >= $vw[$j - 1] && $c[$j] < $vw[$j]) $dn = true;
    }
    $rising = $e > $X['e200'][$n - 11];
    if ($up && $c[$n - 1] > $vw[$n - 1] && $c[$n - 1] > $e) {
        $s = 60 + ($rising ? 10 : 0) + ($rsi > 50 ? 10 : 0) + (($c[$n - 1] - $vw[$n - 1]) < $atr ? 10 : 0);
        return ta_sig('CALL', $B, $X, $s, 'Price crossed above VWAP(20), above EMA 200', 'VWAP');
    }
    if ($dn && $c[$n - 1] < $vw[$n - 1] && $c[$n - 1] < $e) {
        $s = 60 + (!$rising ? 10 : 0) + ($rsi < 50 ? 10 : 0) + (($vw[$n - 1] - $c[$n - 1]) < $atr ? 10 : 0);
        return ta_sig('PUT', $B, $X, $s, 'Price crossed below VWAP(20), below EMA 200', 'VWAP');
    }
    return null;
}

/** 6. Ichimoku: Tenkan/Kijun cross in the last 5 bars, price outside the cloud on the same side, EMA 200 agrees. */
function es_6(array $B, array $X): ?array {
    $n = $X['n']; $e = $X['e200'][$n - 1]; if ($e === null || $n < 80) return null;
    [$c, $h, $l] = [$B['c'], $B['h'], $B['l']];
    $tk = fn(int $i, int $p) => (ta_maxr($h, $i - $p + 1, $i) + ta_minr($l, $i - $p + 1, $i)) / 2;
    $t = []; $k = [];
    for ($i = $n - 7; $i < $n; $i++) { $t[$i] = $tk($i, 9); $k[$i] = $tk($i, 26); }
    $j = $n - 27; $A = ($tk($j, 9) + $tk($j, 26)) / 2; $Bs = $tk($j, 52);
    $top = max($A, $Bs); $bot = min($A, $Bs);
    $crossUp = false; $crossDn = false;
    for ($i = $n - 5; $i < $n; $i++) {
        if ($t[$i - 1] <= $k[$i - 1] && $t[$i] > $k[$i]) $crossUp = true;
        if ($t[$i - 1] >= $k[$i - 1] && $t[$i] < $k[$i]) $crossDn = true;
    }
    $chikouUp = $c[$n - 1] > $c[$n - 27];
    if ($crossUp && $t[$n - 1] > $k[$n - 1] && $c[$n - 1] > $top && $c[$n - 1] > $e) {
        $s = 62 + ($chikouUp ? 10 : 0) + ($A > $Bs ? 10 : 0) + ($c[$n - 1] > $t[$n - 1] ? 10 : 0);
        return ta_sig('CALL', $B, $X, $s, 'Ichimoku TK cross above cloud, above EMA 200', 'Ichimoku');
    }
    if ($crossDn && $t[$n - 1] < $k[$n - 1] && $c[$n - 1] < $bot && $c[$n - 1] < $e) {
        $s = 62 + (!$chikouUp ? 10 : 0) + ($A < $Bs ? 10 : 0) + ($c[$n - 1] < $t[$n - 1] ? 10 : 0);
        return ta_sig('PUT', $B, $X, $s, 'Ichimoku TK cross below cloud, below EMA 200', 'Ichimoku');
    }
    return null;
}

/** 7. Supertrend(10,3) flipped in the last 3 bars, RSI on the right side of 50, EMA 200 agrees. */
function es_7(array $B, array $X): ?array {
    $n = $X['n']; $e = $X['e200'][$n - 1]; if ($e === null) return null;
    $d = ta_supertrend($B['h'], $B['l'], $B['c']); $rsi = $X['rsi'][$n - 1]; $c = $B['c'];
    $rising = $e > $X['e200'][$n - 11];
    $flipUp = $d[$n - 1] == 1 && in_array(-1, array_slice($d, $n - 4, 3), true);
    $flipDn = $d[$n - 1] == -1 && in_array(1, array_slice($d, $n - 4, 3), true);
    if ($flipUp && $rsi > 50 && $c[$n - 1] > $e) {
        $s = 60 + ($rsi > 55 ? 10 : 0) + ($rising ? 10 : 0) + ($d[$n - 2] == -1 ? 10 : 0) + ($c[$n - 1] > $B['o'][$n - 1] ? 8 : 0);
        return ta_sig('CALL', $B, $X, $s, 'Supertrend flip up + RSI > 50, above EMA 200', 'Supertrend');
    }
    if ($flipDn && $rsi < 50 && $c[$n - 1] < $e) {
        $s = 60 + ($rsi < 45 ? 10 : 0) + (!$rising ? 10 : 0) + ($d[$n - 2] == 1 ? 10 : 0) + ($c[$n - 1] < $B['o'][$n - 1] ? 8 : 0);
        return ta_sig('PUT', $B, $X, $s, 'Supertrend flip down + RSI < 50, below EMA 200', 'Supertrend');
    }
    return null;
}

/** 8. Last 60-bar leg of at least 3 ATR; price pulls back into the 0.5-0.705 zone and turns, EMA 200 agrees. */
function es_8(array $B, array $X): ?array {
    $n = $X['n']; $e = $X['e200'][$n - 1]; if ($e === null) return null;
    [$c, $o, $h, $l] = [$B['c'], $B['o'], $B['h'], $B['l']]; $atr = $X['atr'][$n - 1]; $rsi = $X['rsi'];
    $ih = ta_argmax($h, $n - 60, $n - 1); $il = ta_argmin($l, $n - 60, $n - 1);
    $hi = $h[$ih]; $lo = $l[$il]; $r = $hi - $lo; if ($r < 3 * $atr) return null;
    $rising = $e > $X['e200'][$n - 21];
    if ($il < $ih && $c[$n - 1] > $e) {
        $f618 = $hi - 0.618 * $r;
        if ($l[$n - 1] <= $hi - 0.5 * $r && $c[$n - 1] >= $hi - 0.705 * $r && $c[$n - 1] > $o[$n - 1]) {
            $s = 60 + (abs($c[$n - 1] - $f618) <= 0.5 * $atr ? 10 : 0) + ($rising ? 10 : 0) + ($rsi[$n - 1] > $rsi[$n - 2] ? 10 : 0);
            return ta_sig('CALL', $B, $X, $s, 'Pullback into Fib 0.5-0.705 zone, above EMA 200', 'Fib');
        }
    }
    if ($ih < $il && $c[$n - 1] < $e) {
        $f618 = $lo + 0.618 * $r;
        if ($h[$n - 1] >= $lo + 0.5 * $r && $c[$n - 1] <= $lo + 0.705 * $r && $c[$n - 1] < $o[$n - 1]) {
            $s = 60 + (abs($c[$n - 1] - $f618) <= 0.5 * $atr ? 10 : 0) + (!$rising ? 10 : 0) + ($rsi[$n - 1] < $rsi[$n - 2] ? 10 : 0);
            return ta_sig('PUT', $B, $X, $s, 'Pullback into Fib 0.5-0.705 zone, below EMA 200', 'Fib');
        }
    }
    return null;
}

const SCAN_MIN_BARS = 215;   // EMA 200 plus its 10-bar slope
function scan_map(): array {
    return [
        '1-ema-orderblock-liquidity' => 'es_1', '2-ema-rsi-divergence' => 'es_2', '3-ema-50-21-alignment' => 'es_3',
        '4-ema-support-volume' => 'es_4', '5-vwap-ema-combo' => 'es_5', '6-ichimoku-ema' => 'es_6',
        '7-ema-supertrend-rsi' => 'es_7', '8-ema-fibonacci' => 'es_8',
    ];
}
