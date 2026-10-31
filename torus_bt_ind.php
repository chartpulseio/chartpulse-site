<?php
/* ChartPulse Pro Backtesting - indicators + rule engine
 * Server-only (blocked by .htaccess). Ported from the Python/pandas reference engine.
 * Rule: every indicator warms up with NAN exactly where pandas gives NaN, so a
 * strategy can never fire on a half-built indicator.
 */

if (!defined('BT_IND')) { define('BT_IND', 1);

/* Every message in BtError is safe to show the visitor (no server detail). */
class BtError extends Exception {}

/* ---------------------------------------------------------------- helpers */

function bt_nanv($n) { return array_fill(0, $n, NAN); }

function bt_col($d, $src)
{
    if (isset($d[$src])) return $d[$src];
    if ($src === 'hl2') {
        $n = count($d['c']); $o = [];
        for ($i = 0; $i < $n; $i++) $o[$i] = ($d['h'][$i] + $d['l'][$i]) / 2;
        return $o;
    }
    if ($src === 'hlc3') {
        $n = count($d['c']); $o = [];
        for ($i = 0; $i < $n; $i++) $o[$i] = ($d['h'][$i] + $d['l'][$i] + $d['c'][$i]) / 3;
        return $o;
    }
    if ($src === 'ohlc4') {
        $n = count($d['c']); $o = [];
        for ($i = 0; $i < $n; $i++) $o[$i] = ($d['o'][$i] + $d['h'][$i] + $d['l'][$i] + $d['c'][$i]) / 4;
        return $o;
    }
    throw new BtError("This data has no '$src' column");
}

/* pandas: s.ewm(alpha=a, adjust=False, min_periods=mp).mean() over a series
 * whose first $skip values are NaN (NaN = not an observation). */
function bt_ewm($s, $a, $mp, $skip = 0)
{
    $n = count($s); $out = bt_nanv($n); $y = null; $seen = 0;
    for ($i = $skip; $i < $n; $i++) {
        $x = $s[$i];
        if (is_nan($x)) continue;
        $y = ($y === null) ? $x : $y + $a * ($x - $y);
        $seen++;
        if ($seen >= $mp) $out[$i] = $y;
    }
    return $out;
}

function bt_ema($s, $n) { return bt_ewm($s, 2.0 / ($n + 1), $n); }

function bt_sma($s, $n)
{
    $len = count($s); $out = bt_nanv($len); $sum = 0.0;
    for ($i = 0; $i < $len; $i++) {
        $sum += $s[$i];
        if ($i >= $n) $sum -= $s[$i - $n];
        if ($i >= $n - 1) $out[$i] = $sum / $n;
    }
    return $out;
}

function bt_wma($s, $n)
{
    $len = count($s); $out = bt_nanv($len); $w = $n * ($n + 1) / 2;
    for ($i = $n - 1; $i < $len; $i++) {
        $acc = 0.0;
        for ($k = 0; $k < $n; $k++) $acc += $s[$i - $k] * ($n - $k);
        $out[$i] = $acc / $w;
    }
    return $out;
}

function bt_stdev($s, $n)   /* ddof=0, like pandas rolling(n).std(ddof=0) */
{
    $len = count($s); $out = bt_nanv($len); $sum = 0.0; $sq = 0.0;
    for ($i = 0; $i < $len; $i++) {
        $sum += $s[$i]; $sq += $s[$i] * $s[$i];
        if ($i >= $n) { $sum -= $s[$i - $n]; $sq -= $s[$i - $n] * $s[$i - $n]; }
        if ($i >= $n - 1) {
            $v = $sq / $n - ($sum / $n) * ($sum / $n);
            $out[$i] = $v > 0 ? sqrt($v) : 0.0;
        }
    }
    return $out;
}

function bt_rsi($s, $n)
{
    $len = count($s); $up = bt_nanv($len); $dn = bt_nanv($len);
    for ($i = 1; $i < $len; $i++) {
        $d = $s[$i] - $s[$i - 1];
        $up[$i] = $d > 0 ? $d : 0.0;
        $dn[$i] = $d < 0 ? -$d : 0.0;
    }
    $a = 1.0 / $n;
    $mu = bt_ewm($up, $a, $n, 1);
    $md = bt_ewm($dn, $a, $n, 1);
    $out = bt_nanv($len);
    for ($i = 0; $i < $len; $i++) {
        if (is_nan($mu[$i]) || is_nan($md[$i])) continue;
        if ($mu[$i] == 0.0 && $md[$i] == 0.0) { $out[$i] = 50.0; continue; }
        $out[$i] = $md[$i] == 0.0 ? 100.0 : 100 - 100 / (1 + $mu[$i] / $md[$i]);
    }
    return $out;
}

function bt_tr($d)
{
    $n = count($d['c']); $tr = [];
    $tr[0] = $d['h'][0] - $d['l'][0];          /* pandas max(axis=1) skips the NaNs */
    for ($i = 1; $i < $n; $i++) {
        $pc = $d['c'][$i - 1];
        $tr[$i] = max($d['h'][$i] - $d['l'][$i], abs($d['h'][$i] - $pc), abs($d['l'][$i] - $pc));
    }
    return $tr;
}

function bt_atr($d, $n) { return bt_ewm(bt_tr($d), 1.0 / $n, $n); }

function bt_bb($s, $n, $k)
{
    $mid = bt_sma($s, $n); $sd = bt_stdev($s, $n); $len = count($s);
    $u = bt_nanv($len); $lo = bt_nanv($len);
    for ($i = 0; $i < $len; $i++) {
        if (is_nan($mid[$i])) continue;
        $u[$i] = $mid[$i] + $k * $sd[$i];
        $lo[$i] = $mid[$i] - $k * $sd[$i];
    }
    return [$u, $lo, $mid, $sd];
}

/* highest/lowest of the PREVIOUS n bars (current bar excluded) */
function bt_prev_ext($s, $n, $hi)
{
    $len = count($s); $out = bt_nanv($len);
    for ($i = $n; $i < $len; $i++) {
        $v = $s[$i - $n];
        for ($k = $i - $n + 1; $k < $i; $k++) $v = $hi ? max($v, $s[$k]) : min($v, $s[$k]);
        $out[$i] = $v;
    }
    return $out;
}

function bt_macd($d, $fast, $slow, $sig, $src = 'c')
{
    $s = bt_col($d, $src);
    $f = bt_ema($s, $fast); $sl = bt_ema($s, $slow); $len = count($s);
    $line = bt_nanv($len);
    for ($i = 0; $i < $len; $i++) if (!is_nan($f[$i]) && !is_nan($sl[$i])) $line[$i] = $f[$i] - $sl[$i];
    $sigl = bt_ewm($line, 2.0 / ($sig + 1), $sig, 0);
    $hist = bt_nanv($len);
    for ($i = 0; $i < $len; $i++) if (!is_nan($line[$i]) && !is_nan($sigl[$i])) $hist[$i] = $line[$i] - $sigl[$i];
    return [$line, $sigl, $hist];
}

/* Wilder ADX / +DI / -DI */
function bt_adx($d, $n)
{
    $len = count($d['c']); $tr = bt_tr($d);
    $pdm = bt_nanv($len); $ndm = bt_nanv($len);
    for ($i = 1; $i < $len; $i++) {
        $up = $d['h'][$i] - $d['h'][$i - 1];
        $dw = $d['l'][$i - 1] - $d['l'][$i];
        $pdm[$i] = ($up > $dw && $up > 0) ? $up : 0.0;
        $ndm[$i] = ($dw > $up && $dw > 0) ? $dw : 0.0;
    }
    $a = 1.0 / $n;
    $atr = bt_ewm($tr, $a, $n);
    $sp = bt_ewm($pdm, $a, $n, 1);
    $sn = bt_ewm($ndm, $a, $n, 1);
    $pdi = bt_nanv($len); $ndi = bt_nanv($len); $dx = bt_nanv($len);
    for ($i = 0; $i < $len; $i++) {
        if (is_nan($atr[$i]) || is_nan($sp[$i]) || is_nan($sn[$i]) || $atr[$i] == 0.0) continue;
        $pdi[$i] = 100 * $sp[$i] / $atr[$i];
        $ndi[$i] = 100 * $sn[$i] / $atr[$i];
        $sum = $pdi[$i] + $ndi[$i];
        $dx[$i] = $sum > 0 ? 100 * abs($pdi[$i] - $ndi[$i]) / $sum : 0.0;
    }
    $adx = bt_ewm($dx, $a, $n, 0);
    return [$adx, $pdi, $ndi];
}

/* Supertrend (ATR period, factor). Returns [line, dir] where dir = 1 up / -1 down. */
function bt_supertrend($d, $n, $f)
{
    $len = count($d['c']); $atr = bt_atr($d, $n);
    $line = bt_nanv($len); $dir = bt_nanv($len);
    $ub = null; $lb = null; $pd = 1; $started = false;
    for ($i = 0; $i < $len; $i++) {
        if (is_nan($atr[$i])) continue;
        $mid = ($d['h'][$i] + $d['l'][$i]) / 2;
        $u = $mid + $f * $atr[$i];
        $l = $mid - $f * $atr[$i];
        if (!$started) { $ub = $u; $lb = $l; $pd = 1; $started = true; }
        else {
            $pc = $d['c'][$i - 1];
            $ub = ($u < $ub || $pc > $ub) ? $u : $ub;
            $lb = ($l > $lb || $pc < $lb) ? $l : $lb;
            $pd = ($d['c'][$i] > $ub) ? 1 : (($d['c'][$i] < $lb) ? -1 : $pd);
        }
        $dir[$i] = $pd;
        $line[$i] = $pd === 1 ? $lb : $ub;
    }
    return [$line, $dir];
}

/* Stochastic %K (n, smooth_k) and %D (smooth_d of %K) */
function bt_stoch($d, $n, $sk, $sd)
{
    $len = count($d['c']); $raw = bt_nanv($len);
    for ($i = $n - 1; $i < $len; $i++) {
        $hi = $d['h'][$i]; $lo = $d['l'][$i];
        for ($k = $i - $n + 1; $k < $i; $k++) { $hi = max($hi, $d['h'][$k]); $lo = min($lo, $d['l'][$k]); }
        $raw[$i] = ($hi > $lo) ? 100 * ($d['c'][$i] - $lo) / ($hi - $lo) : 50.0;
    }
    $kk = $sk > 1 ? bt_sma_nan($raw, $sk) : $raw;
    $dd = bt_sma_nan($kk, $sd);
    return [$kk, $dd];
}

/* rolling mean that tolerates a NaN warm-up head (pandas min_periods=n) */
function bt_sma_nan($s, $n)
{
    $len = count($s); $out = bt_nanv($len); $sum = 0.0; $cnt = 0;
    for ($i = 0; $i < $len; $i++) {
        if (!is_nan($s[$i])) { $sum += $s[$i]; $cnt++; }
        if ($i >= $n) { if (!is_nan($s[$i - $n])) { $sum -= $s[$i - $n]; $cnt--; } }
        if ($cnt === $n) $out[$i] = $sum / $n;
    }
    return $out;
}

/* rolling VWAP over n bars (deterministic; no session anchoring needed) */
function bt_vwap($d, $n)
{
    if (!isset($d['v'])) throw new BtError("This symbol has no volume data, so VWAP cannot be built");
    $len = count($d['c']); $out = bt_nanv($len); $pv = 0.0; $vv = 0.0;
    $tp = bt_col($d, 'hlc3');
    for ($i = 0; $i < $len; $i++) {
        $pv += $tp[$i] * $d['v'][$i]; $vv += $d['v'][$i];
        if ($i >= $n) { $pv -= $tp[$i - $n] * $d['v'][$i - $n]; $vv -= $d['v'][$i - $n]; }
        if ($i >= $n - 1) $out[$i] = $vv > 0 ? $pv / $vv : $tp[$i];
    }
    return $out;
}

function bt_roc($s, $n)
{
    $len = count($s); $out = bt_nanv($len);
    for ($i = $n; $i < $len; $i++) if ($s[$i - $n] != 0.0) $out[$i] = 100 * ($s[$i] / $s[$i - $n] - 1);
    return $out;
}

/* ------------------------------------------------- indicator dispatcher */

define('BT_INDS', [
    'open', 'high', 'low', 'close', 'volume', 'hl2', 'hlc3', 'ohlc4',
    'sma', 'ema', 'wma', 'rsi', 'atr', 'atr_pct', 'stdev',
    'bb_upper', 'bb_lower', 'bb_mid', 'bb_width',
    'highest', 'lowest', 'macd', 'macd_signal', 'macd_hist',
    'adx', 'plus_di', 'minus_di', 'supertrend', 'supertrend_dir',
    'stoch_k', 'stoch_d', 'vwap', 'roc',
]);
define('BT_NEEDS_PERIOD', [
    'sma', 'ema', 'wma', 'rsi', 'atr', 'atr_pct', 'stdev', 'bb_upper', 'bb_lower', 'bb_mid',
    'bb_width', 'highest', 'lowest', 'macd', 'macd_signal', 'macd_hist', 'adx', 'plus_di',
    'minus_di', 'supertrend', 'supertrend_dir', 'stoch_k', 'stoch_d', 'vwap', 'roc',
]);

function bt_src_name($ind, $spec)
{
    static $map = ['open' => 'o', 'high' => 'h', 'low' => 'l', 'close' => 'c', 'volume' => 'v'];
    if (isset($map[$ind])) return $map[$ind];
    $s = $spec['src'] ?? 'close';
    return $map[$s] ?? $s;
}

function bt_compute($d, $spec)
{
    $ind = $spec['ind'];
    $n = isset($spec['period']) ? (int)$spec['period'] : null;
    $p2 = isset($spec['period2']) ? (int)$spec['period2'] : null;
    $p3 = isset($spec['period3']) ? (int)$spec['period3'] : null;
    $mult = isset($spec['mult']) ? (float)$spec['mult'] : null;
    $len = count($d['c']);

    switch ($ind) {
        case 'open': case 'high': case 'low': case 'close': case 'volume':
        case 'hl2': case 'hlc3': case 'ohlc4':
            return bt_col($d, bt_src_name($ind, $spec));
        case 'sma':   return bt_sma(bt_col($d, bt_src_name($ind, $spec)), $n);
        case 'ema':   return bt_ema(bt_col($d, bt_src_name($ind, $spec)), $n);
        case 'wma':   return bt_wma(bt_col($d, bt_src_name($ind, $spec)), $n);
        case 'rsi':   return bt_rsi(bt_col($d, bt_src_name($ind, $spec)), $n);
        case 'stdev': return bt_stdev(bt_col($d, bt_src_name($ind, $spec)), $n);
        case 'roc':   return bt_roc(bt_col($d, bt_src_name($ind, $spec)), $n);
        case 'atr':   return bt_atr($d, $n);
        case 'atr_pct': {
            $a = bt_atr($d, $n); $o = bt_nanv($len);
            for ($i = 0; $i < $len; $i++) if (!is_nan($a[$i]) && $d['c'][$i] != 0.0) $o[$i] = 100 * $a[$i] / $d['c'][$i];
            return $o;
        }
        case 'bb_upper': case 'bb_lower': case 'bb_mid': case 'bb_width': {
            [$u, $lo, $mid, $sd] = bt_bb(bt_col($d, bt_src_name($ind, $spec)), $n, $mult ?? 2.0);
            if ($ind === 'bb_upper') return $u;
            if ($ind === 'bb_lower') return $lo;
            if ($ind === 'bb_mid') return $mid;
            $o = bt_nanv($len);
            for ($i = 0; $i < $len; $i++) if (!is_nan($mid[$i]) && $mid[$i] != 0.0) $o[$i] = 100 * ($u[$i] - $lo[$i]) / $mid[$i];
            return $o;
        }
        case 'highest': return bt_prev_ext(bt_col($d, isset($spec['src']) ? bt_src_name('x', $spec) : 'h'), $n, true);
        case 'lowest':  return bt_prev_ext(bt_col($d, isset($spec['src']) ? bt_src_name('x', $spec) : 'l'), $n, false);
        case 'macd': case 'macd_signal': case 'macd_hist': {
            [$line, $sig, $hist] = bt_macd($d, $n, $p2 ?? 26, $p3 ?? 9, bt_src_name('x', $spec));
            return $ind === 'macd' ? $line : ($ind === 'macd_signal' ? $sig : $hist);
        }
        case 'adx': case 'plus_di': case 'minus_di': {
            [$adx, $pdi, $ndi] = bt_adx($d, $n);
            return $ind === 'adx' ? $adx : ($ind === 'plus_di' ? $pdi : $ndi);
        }
        case 'supertrend': case 'supertrend_dir': {
            [$line, $dir] = bt_supertrend($d, $n, $mult ?? 3.0);
            return $ind === 'supertrend' ? $line : $dir;
        }
        case 'stoch_k': case 'stoch_d': {
            [$kk, $dd] = bt_stoch($d, $n, $p2 ?? 1, $p3 ?? 3);
            return $ind === 'stoch_k' ? $kk : $dd;
        }
        case 'vwap': return bt_vwap($d, $n);
    }
    throw new BtError("Unknown indicator: $ind");
}

/* ------------------------------------------------------------------ HTF */

define('BT_TF_SEC', ['5min' => 300, '15min' => 900, '30min' => 1800, '1h' => 3600,
                   '4h' => 14400, '1D' => 86400, '1W' => 604800]);

function bt_bucket($t, $step)
{
    if ($step === 604800) return intdiv($t + 345600, 604800) * 604800 - 345600;  /* Sunday anchor */
    return intdiv($t, $step) * $step;
}

/* Higher-timeframe value with no lookahead: a bar only ever sees the value of
 * the PREVIOUS closed HTF candle (pandas: resample(label=left, closed=left) -> shift(1) -> ffill). */
function bt_htf($d, $tf, $spec)
{
    $step = BT_TF_SEC[$tf];
    $len = count($d['c']);
    $b = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => []];
    $hasV = isset($d['v']);
    if ($hasV) $b['v'] = [];
    $cur = null; $j = -1; $map = [];              /* original bar -> htf bucket index */
    for ($i = 0; $i < $len; $i++) {
        $bk = bt_bucket($d['t'][$i], $step);
        if ($bk !== $cur) {
            $cur = $bk; $j++;
            $b['t'][$j] = $bk; $b['o'][$j] = $d['o'][$i];
            $b['h'][$j] = $d['h'][$i]; $b['l'][$j] = $d['l'][$i];
            if ($hasV) $b['v'][$j] = 0.0;
        }
        $b['h'][$j] = max($b['h'][$j], $d['h'][$i]);
        $b['l'][$j] = min($b['l'][$j], $d['l'][$i]);
        $b['c'][$j] = $d['c'][$i];
        if ($hasV) $b['v'][$j] += $d['v'][$i];
        $map[$i] = $j;
    }
    if ($j < 1) return bt_nanv($len);
    $hv = bt_compute($b, $spec);
    $out = bt_nanv($len);
    for ($i = 0; $i < $len; $i++) {
        $k = $map[$i] - 1;                        /* shift(1): previous closed HTF candle */
        if ($k >= 0 && !is_nan($hv[$k])) $out[$i] = $hv[$k];
    }
    return $out;
}

/* ------------------------------------------------------- rules -> masks */

function bt_series($d, $spec)
{
    $len = count($d['c']);
    if (isset($spec['value']) && $spec['value'] !== null && $spec['value'] !== '') {
        return array_fill(0, $len, (float)$spec['value']);
    }
    $s = !empty($spec['tf']) ? bt_htf($d, $spec['tf'], $spec) : bt_compute($d, $spec);
    $back = isset($spec['back']) ? (int)$spec['back'] : 0;
    if ($back > 0) {
        $o = bt_nanv($len);
        for ($i = $back; $i < $len; $i++) $o[$i] = $s[$i - $back];
        $s = $o;
    }
    if (isset($spec['offset']) && $spec['offset'] !== null) {        /* multiply, e.g. 1.002 x EMA */
        $f = (float)$spec['offset'];
        for ($i = 0; $i < $len; $i++) if (!is_nan($s[$i])) $s[$i] = $s[$i] * $f;
    }
    return $s;
}

function bt_rule_mask($d, $rule)
{
    $a = bt_series($d, $rule['left']);
    $b = bt_series($d, $rule['right']);
    $len = count($d['c']); $m = array_fill(0, $len, false);
    $op = $rule['op'];
    for ($i = 0; $i < $len; $i++) {
        $x = $a[$i]; $y = $b[$i];
        if (is_nan($x) || is_nan($y)) continue;
        switch ($op) {
            case '>':  $m[$i] = $x > $y; break;
            case '<':  $m[$i] = $x < $y; break;
            case '>=': $m[$i] = $x >= $y; break;
            case '<=': $m[$i] = $x <= $y; break;
            case 'crosses_above':
                if ($i === 0) break;
                $px = $a[$i - 1]; $py = $b[$i - 1];
                if (is_nan($px) || is_nan($py)) break;
                $m[$i] = ($x > $y) && ($px <= $py);
                break;
            case 'crosses_below':
                if ($i === 0) break;
                $px = $a[$i - 1]; $py = $b[$i - 1];
                if (is_nan($px) || is_nan($py)) break;
                $m[$i] = ($x < $y) && ($px >= $py);
                break;
            default: throw new BtError("Unknown operator: $op");
        }
    }
    return $m;
}

/* All rules are ANDed. Empty list = never a signal. */
function bt_all_rules($d, $rules)
{
    $len = count($d['c']);
    if (!$rules) return array_fill(0, $len, false);
    $m = bt_rule_mask($d, $rules[0]);
    for ($k = 1; $k < count($rules); $k++) {
        $x = bt_rule_mask($d, $rules[$k]);
        for ($i = 0; $i < $len; $i++) $m[$i] = $m[$i] && $x[$i];
    }
    return $m;
}

}
