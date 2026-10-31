<?php
// ---------------------------------------------------------------------------
// TORUS Bias Desk (owner, 4 Oct 2026): one-symbol bias, price scenario, trade plan and evidence panels.
// Built for shared PHP hosting. The owner's prototype named TimesFM / FinBERT / Qdrant; none of them runs here,
// so each has a deterministic, testable stand-in that uses only data the site already has:
//   - scenario cone  : empirical quantiles of this symbol's own forward moves (in ATR units), re-scaled to today's ATR
//   - setup memory   : nearest-neighbour search over this symbol's own history (8 standardised features, no look-ahead)
//   - news sentiment : the News & Impact lexicon score per headline, recency-weighted (12 h half-life, 72 h window)
//   - judge          : ChartPulse engine bias + trend, momentum, candle, volume, memory and news, as a transparent points table
// Results always start from the ChartPulse engine's own bias. Nothing here is advice.
// ---------------------------------------------------------------------------
declare(strict_types=1);
require_once __DIR__ . '/torus_ml.php';

const TD_H = 5;          // horizon of the memory / self-test outcome, in bars
const TD_CONE = 10;      // bars drawn in the scenario cone
const TD_TTL = 600;      // cache (s)

function td_rsi_s(array $c, int $p = 14): array {
    $o = []; $g = 0.0; $l = 0.0; $n = count($c);
    for ($i = 0; $i < $n; $i++) {
        if (!$i) { $o[] = 50.0; continue; }
        $d = $c[$i] - $c[$i - 1]; $g = ($g * ($p - 1) + max($d, 0)) / $p; $l = ($l * ($p - 1) + max(-$d, 0)) / $p;
        $o[] = $l > 0 ? 100 - 100 / (1 + $g / $l) : 100.0;
    }
    return $o;
}
function td_q(array $x, float $q): float {
    sort($x); $n = count($x); if (!$n) return 0.0;
    $p = ($n - 1) * $q; $a = (int)floor($p); $b = min($n - 1, $a + 1);
    return $x[$a] + ($x[$b] - $x[$a]) * ($p - $a);
}
function td_pattern(array $B, int $i): array {
    if ($i < 1) return [0, 'None'];
    $o = $B['o'][$i]; $c = $B['c'][$i]; $h = $B['h'][$i]; $l = $B['l'][$i]; $po = $B['o'][$i - 1]; $pc = $B['c'][$i - 1];
    $bd = abs($c - $o); $rg = max($h - $l, 1e-12); $up = $h - max($o, $c); $dn = min($o, $c) - $l;
    if ($dn > 2 * $bd && $up < $bd * 0.8 && $dn > 0.5 * $rg) return [1, 'Hammer'];
    if ($up > 2 * $bd && $dn < $bd * 0.8 && $up > 0.5 * $rg) return [-1, 'Shooting star'];
    if ($c > $o && $pc < $po && $c >= $po && $o <= $pc) return [1, 'Bullish engulfing'];
    if ($c < $o && $pc > $po && $o >= $pc && $c <= $po) return [-1, 'Bearish engulfing'];
    return [0, $bd < $rg * 0.1 ? 'Doji' : 'None'];
}

/** Indicator series for an OHLCV frame. */
function td_prep(array $B): array {
    $c = $B['c']; $n = count($c);
    $I = ['E5' => t_ema($c, 5), 'E20' => t_ema($c, 20), 'E50' => t_ema($c, 50), 'A' => t_atr($B, 14), 'R' => td_rsi_s($c)];
    [$I['SD'], $I['SL']] = t_supertrend($B, 10, 3.0);
    [$m, $u, $lo] = t_bb($c, 20, 2.0); $I['PB'] = [];
    for ($i = 0; $i < $n; $i++) $I['PB'][] = $u[$i] !== null && $u[$i] > $lo[$i] ? ($c[$i] - $lo[$i]) / ($u[$i] - $lo[$i]) : 0.5;
    // features for the setup memory (raw, standardised later)
    $hasV = array_sum(array_slice($B['v'], -60)) > 0;
    $F = [];
    for ($i = 0; $i < $n; $i++) {
        if ($i < 60) { $F[] = null; continue; }
        $a = max($I['A'][$i], 1e-12);
        $hi = max(array_slice($B['h'], $i - 19, 20)); $lw = min(array_slice($B['l'], $i - 19, 20));
        $ap = array_slice($I['A'], max(0, $i - 250), min(251, $i + 1)); $px = array_slice($c, max(0, $i - 250), min(251, $i + 1));
        $cur = $I['A'][$i] / $c[$i]; $rank = 0; foreach ($ap as $k => $x) if ($x / max($px[$k], 1e-12) <= $cur) $rank++;
        $vr = 0.0; if ($hasV) { $av = array_sum(array_slice($B['v'], $i - 19, 20)) / 20; $vr = $av > 0 ? max(-1, min(2, $B['v'][$i] / $av - 1)) : 0.0; }
        $F[] = [($I['R'][$i] - 50) / 25, max(-4, min(4, ($c[$i] - $I['E20'][$i]) / $a)), max(-4, min(4, ($c[$i] - $c[$i - 5]) / $a)),
            $hi > $lw ? (($c[$i] - $lw) / ($hi - $lw) - 0.5) * 2 : 0.0, $I['PB'][$i] - 0.5, (float)$I['SD'][$i],
            $rank / max(count($ap), 1) - 0.5, $vr];
    }
    $I['F'] = $F; $I['hasV'] = $hasV;
    return $I;
}

/** Setup memory: K nearest past bars (only bars whose outcome window has fully closed before bar $i). */
function td_memory(array $B, array $I, int $i, int $dir, int $K = 40): array {
    $F = $I['F']; $n = count($B['c']); if (!$F[$i]) return ['n' => 0];
    $d = count($F[$i]); $mu = array_fill(0, $d, 0.0); $sd = array_fill(0, $d, 0.0); $m = 0;
    for ($j = 60; $j < $i - TD_H; $j++) if ($F[$j]) { $m++; for ($k = 0; $k < $d; $k++) $mu[$k] += $F[$j][$k]; }
    if ($m < 60) return ['n' => 0];
    for ($k = 0; $k < $d; $k++) $mu[$k] /= $m;
    for ($j = 60; $j < $i - TD_H; $j++) if ($F[$j]) for ($k = 0; $k < $d; $k++) $sd[$k] += ($F[$j][$k] - $mu[$k]) ** 2;
    for ($k = 0; $k < $d; $k++) $sd[$k] = sqrt($sd[$k] / $m) ?: 1.0;
    $L = [];
    for ($j = 60; $j < $i - 2 * TD_H; $j++) {
        if (!$F[$j]) continue; $s = 0.0;
        for ($k = 0; $k < $d; $k++) $s += (($F[$i][$k] - $F[$j][$k]) / $sd[$k]) ** 2;
        $L[] = [$s, $j];
    }
    usort($L, fn($a, $b) => $a[0] <=> $b[0]); $L = array_slice($L, 0, $K);
    $mv = []; $mae = []; $mfe = []; $win = 0; $run = []; $hist = [0, 0, 0, 0, 0, 0]; $sg = $dir ?: 1;
    foreach ($L as [$dist, $j]) {
        $a = max($I['A'][$j], 1e-12); $x = ($B['c'][$j + TD_H] - $B['c'][$j]) / $a; $mv[] = $x;
        $hist[$x <= -2 ? 0 : ($x <= -1 ? 1 : ($x <= 0 ? 2 : ($x <= 1 ? 3 : ($x <= 2 ? 4 : 5))))]++;
        $hi = 0.0; $lo = 0.0; $res = 0; $len = 0;
        for ($q = $j + 1; $q <= min($n - 1, $j + 2 * TD_H); $q++) {
            $f = $sg * (($sg > 0 ? $B['h'][$q] : $B['l'][$q]) - $B['c'][$j]) / $a; $b = $sg * ($B['c'][$j] - ($sg > 0 ? $B['l'][$q] : $B['h'][$q])) / $a;
            $hi = max($hi, $f); $lo = max($lo, $b);
            if ($res === 0) { if ($b >= 1.0) $res = -1; elseif ($f >= 1.5) $res = 1; }
            if ($sg * ($B['c'][$q] - $B['c'][$q - 1]) > 0 && $len === $q - $j - 1) $len++;
        }
        $mfe[] = $hi; $mae[] = $lo; $run[] = $len; if ($res === 1) $win++;
    }
    $k = count($L); $up = count(array_filter($mv, fn($x) => $x > 0));
    return ['n' => $k, 'up' => $k ? $up / $k : 0.5, 'avg_move' => $k ? array_sum($mv) / $k : 0.0, 'avg_mfe' => $k ? array_sum($mfe) / $k : 0.0,
        'avg_mae' => $k ? array_sum($mae) / $k : 0.0, 'win' => $k ? $win / $k : 0.0, 'avg_run' => $k ? array_sum($run) / $k : 0.0, 'hist' => $hist, 'pool' => $m];
}

/** Points table (judge). $eng = ChartPulse engine bias for the symbol (UP / DOWN / NEUTRAL) and its strength 0..1. */
function td_points(array $B, array $I, int $i, string $eng, float $engK, ?array $mem, ?float $news): array {
    $c = $B['c'][$i]; $sg = fn($x) => $x > 0 ? 1 : ($x < 0 ? -1 : 0);
    $hi = max(array_slice($B['h'], max(0, $i - 19), min(20, $i + 1))); $lw = min(array_slice($B['l'], max(0, $i - 19), min(20, $i + 1)));
    $rp = $hi > $lw ? ($c - $lw) / ($hi - $lw) : 0.5; [$pv, $pn] = td_pattern($B, $i);
    $vr = 1.0; if ($I['hasV'] && $i >= 19) { $av = array_sum(array_slice($B['v'], $i - 19, 20)) / 20; $vr = $av > 0 ? $B['v'][$i] / $av : 1.0; }
    $P = [
        ['ChartPulse engine bias', ($eng === 'UP' ? 1 : ($eng === 'DOWN' ? -1 : 0)) * 30 * $engK, 30],
        ['Trend (EMA 5 / 20 / 50)', ($sg($I['E5'][$i] - $I['E20'][$i]) + $sg($I['E20'][$i] - $I['E50'][$i]) + $sg($c - $I['E50'][$i])) / 3 * 15, 15],
        ['Momentum (RSI 14)', max(-1, min(1, ($I['R'][$i] - 50) / 25)) * 10, 10],
        ['Reversal candle', $pv * ($pv > 0 ? ($rp < 0.3 ? 1 : 0.4) : ($rp > 0.7 ? 1 : 0.4)) * 10, 10],
        ['Volume confirmation', $I['hasV'] ? $sg($c - $B['o'][$i]) * max(0, min(1, $vr - 1)) * 5 : 0.0, 5],
        ['Setup memory edge', $mem && $mem['n'] >= 20 ? ($mem['up'] - 0.5) * 2 * 20 : 0.0, 20],
        ['News tone', $news !== null ? max(-1, min(1, $news)) * 10 : 0.0, 10],
    ];
    return ['P' => $P, 'tot' => array_sum(array_column($P, 1)), 'pattern' => $pn, 'rp' => $rp, 'vr' => $vr, 'hi' => $hi, 'lo' => $lw];
}

/** Walk-forward self-test of the points table on this symbol's own history (engine bias replaced by the daily Supertrend, no memory / news). */
function td_selftest(array $B, array $I): array {
    $n = count($B['c']); $eq = [0.0]; $t = []; $i = 70;
    while ($i < $n - TD_H) {
        $p = td_points($B, $I, $i, $I['SD'][$i] > 0 ? 'UP' : 'DOWN', 0.75, null, null); $s = $p['tot'];
        if (abs($s) >= 35) { $r = ($s > 0 ? 1 : -1) * ($B['c'][$i + TD_H] - $B['c'][$i]) / max($I['A'][$i], 1e-12); $t[] = $r; $eq[] = end($eq) + $r; $i += TD_H; }
        else $i++;
    }
    $w = array_filter($t, fn($x) => $x > 0); $l = array_filter($t, fn($x) => $x <= 0); $sl = abs(array_sum($l));
    $step = max(1, intdiv(count($eq), 120)); $E = []; foreach ($eq as $k => $v) if ($k % $step === 0 || $k === count($eq) - 1) $E[] = round($v, 2);
    return ['trades' => count($t), 'hit' => $t ? round(count($w) / count($t) * 100, 1) : 0.0, 'avg' => $t ? round(array_sum($t) / count($t), 2) : 0.0,
        'pf' => $sl > 0 ? round(array_sum($w) / $sl, 2) : null, 'equity' => $E];
}

/** Scenario cone: empirical quantiles of h-bar forward moves in ATR units, applied to the current ATR. */
function td_cone(array $B, array $I, int $i): array {
    $out = []; $c = $B['c'][$i]; $a = $I['A'][$i]; $from = max(30, $i - 1000);
    for ($h = 1; $h <= TD_CONE; $h++) {
        $x = []; for ($j = $from; $j + $h <= $i; $j++) $x[] = ($B['c'][$j + $h] - $B['c'][$j]) / max($I['A'][$j], 1e-12);
        if (count($x) < 40) return [];
        $out[] = ['h' => $h, 'p10' => t_r($c + td_q($x, .10) * $a), 'p25' => t_r($c + td_q($x, .25) * $a), 'p50' => t_r($c + td_q($x, .50) * $a),
            'p75' => t_r($c + td_q($x, .75) * $a), 'p90' => t_r($c + td_q($x, .90) * $a)];
    }
    return $out;
}

/** News tone for a symbol: recency-weighted lexicon score (half-life 12 h, last 72 h). null when there is no news. */
function td_news(string $sym, int $now): array {
    $nc = t_read_json(T_DATA . '/news.json', ['items' => []]); $top = []; $sw = 0.0; $ws = 0.0; $n = 0; $L = [];
    foreach (($nc['items'] ?? []) as $x) {
        $hit = false; foreach (($x['tags'] ?? []) as $tg) if (($tg['symbol'] ?? '') === $sym) { $hit = true; break; }
        if ($hit && preg_match('~^https?://~i', (string)($x['u'] ?? ''))) $L[] = $x;
    }
    // FinBERT (ProsusAI/finbert on the owner's Space) scores the headlines of the last 72 h; the lexicon score is the fallback
    $fin = ml_sentiment(array_values(array_unique(array_map(fn($x) => (string)$x['t'], array_filter($L, fn($x) => $now - (int)$x['ts'] <= 259200)))));
    $src = $fin ? 'finbert' : 'lexicon';
    foreach ($L as $x) {
        $age = max(0, $now - (int)$x['ts']); $fs = $fin[(string)$x['t']] ?? null;
        if (count($top) < 6) $top[] = ['title' => $x['t'], 'publisher' => $x['src'], 'link' => $x['u'], 'ts' => $x['ts'], 'imp' => $x['imp'],
            'sen' => $fs === null ? $x['sen'] : ($fs > 0.2 ? 'bull' : ($fs < -0.2 ? 'bear' : 'neutral'))];
        if ($age > 259200) continue;
        $w = 0.5 ** ($age / 43200) * (($x['imp'] ?? '') === 'high' ? 1.5 : 1.0);
        $sw += $w * max(-1, min(1, $fs ?? (float)($x['ss'] ?? 0))); $ws += $w; $n++;
    }
    return ['n' => $n, 'tone' => $ws > 0 ? $sw / $ws : null, 'top' => $top, 'src' => $src];
}

/** Our own recorded Tier-2 trades for this symbol (honest live record). */
function td_record(string $sym): array {
    $T = t_read_json(T_DATA . '/t2trades.json', ['items' => []]); $r = [];
    foreach (($T['items'] ?? []) as $x) if (($x['symbol'] ?? '') === $sym && ($x['status'] ?? '') === 'closed' && isset($x['r'])) $r[] = (float)$x['r'];
    $w = count(array_filter($r, fn($x) => $x > 0));
    return ['trades' => count($r), 'win' => $r ? round($w / count($r) * 100, 1) : null, 'avg_r' => $r ? round(array_sum($r) / count($r), 2) : null];
}

function td_analyze(string $sym, string $tf = '1D'): array {
    $fr = t_read_frames($sym); if (!$fr) return ['error' => 'This symbol has not been scanned yet. Try again in a few minutes.'];
    $B = $tf === '1H' ? $fr['1H'] : $fr['1D']; $n = count($B['c']);
    if ($n < 130) return ['error' => 'Not enough price history for this symbol yet.'];
    if (count($B['c']) > 1300) foreach ($B as $k => $v) $B[$k] = array_values(array_slice($v, -1300));
    $n = count($B['c']); $i = $n - 1; $now = t_now();
    $I = td_prep($B);
    $row = t_scan_load()['rows'][$sym] ?? null; $eng = $row['bias'] ?? 'NEUTRAL'; $al = (int)($row['alignment'] ?? 2);
    $engK = $eng === 'NEUTRAL' ? 0.0 : ($al >= 4 ? 1.0 : 0.66);
    $news = td_news($sym, $now);
    $dir0 = $eng === 'UP' ? 1 : ($eng === 'DOWN' ? -1 : ($I['SD'][$i] > 0 ? 1 : -1));
    $mem = td_memory($B, $I, $i, $dir0); $memSrc = $mem['n'] ? 'this chart' : 'none';
    $qm = qd_memory($sym, $I, $i, $dir0, (int)$B['t'][$i], $tf);   // Qdrant: nearest setups across the same market
    if ($qm) { $mem = $qm; $memSrc = $qm['scope']; }
    $tone = $news['n'] >= 2 ? $news['tone'] : null;
    $pt = td_points($B, $I, $i, $eng, $engK, $mem, $tone); $tot = $pt['tot'];
    $dir = $tot >= 15 ? 1 : ($tot <= -15 ? -1 : 0); $mag = abs($tot);
    $veto = [];
    if ($dir && $tone !== null && $news['n'] >= 3 && $tone * $dir <= -0.4) $veto[] = 'News tone strongly against the bias';
    if ($dir && $mem['n'] >= 20 && (($dir > 0 && $mem['up'] < 0.4) || ($dir < 0 && $mem['up'] > 0.6))) $veto[] = 'Similar past setups mostly went the other way';
    $tier = $veto ? 'skip' : ($mag >= 55 ? 'full' : ($mag >= 35 ? 'half' : 'skip'));
    $a = $I['A'][$i]; $c = $B['c'][$i]; $plan = null;
    if ($dir) {
        $sw = $dir > 0 ? min(array_slice($B['l'], -5)) : max(array_slice($B['h'], -5));
        $sl = $dir > 0 ? min($sw - 0.2 * $a, $c - 1.2 * $a) : max($sw + 0.2 * $a, $c + 1.2 * $a); $R = abs($c - $sl);
        $plan = ['direction' => $dir > 0 ? 'long' : 'short', 'entry' => t_r($c), 'stop' => t_r($sl), 't1' => t_r($c + $dir * 1.5 * $R), 't2' => t_r($c + $dir * 2.5 * $R), 'risk' => t_r($R)];
    }
    $cone = td_cone($B, $I, $i); $coneSrc = count($cone) ? 'statistical' : 'none';
    $fc = ml_forecast($sym, $tf, $B['c'], (int)$B['t'][$i], TD_CONE);   // TimesFM 2.5 quantiles from the owner's Space
    if ($fc) { $cone = array_map(fn($q) => ['h' => (int)$q['h'], 'p10' => t_r((float)$q['p10']), 'p25' => t_r((float)$q['p25']), 'p50' => t_r((float)$q['p50']),
        'p75' => t_r((float)$q['p75']), 'p90' => t_r((float)$q['p90'])], $fc); $coneSrc = 'timesfm'; }
    $feas = null;
    if ($plan && count($cone) >= TD_H) { $q = $cone[TD_H - 1];
        $in = fn($p) => $dir > 0 ? ($p <= $q['p75'] ? 'inside the 75% band' : ($p <= $q['p90'] ? 'inside the 90% band' : 'outside the 90% band'))
            : ($p >= $q['p25'] ? 'inside the 75% band' : ($p >= $q['p10'] ? 'inside the 90% band' : 'outside the 90% band'));
        $feas = ['t1' => $in($plan['t1']), 't2' => $in($plan['t2'])]; }
    $st = td_selftest($B, $I);
    // ATR percentile and annualised volatility
    $ap = []; for ($j = max(0, $i - 250); $j <= $i; $j++) $ap[] = $I['A'][$j] / $B['c'][$j]; $cur = end($ap);
    $lr = []; for ($j = max(1, $i - 59); $j <= $i; $j++) $lr[] = log($B['c'][$j] / $B['c'][$j - 1]); $mu = array_sum($lr) / count($lr);
    $vol = sqrt(array_sum(array_map(fn($x) => ($x - $mu) ** 2, $lr)) / count($lr)) * sqrt($tf === '1H' ? 252 * 7 : 252) * 100;
    $L = 160; $sl = fn($x) => array_values(array_slice($x, -$L));
    $label = $dir > 0 ? 'BULLISH' : ($dir < 0 ? 'BEARISH' : 'NEUTRAL');
    $top = $pt['P']; usort($top, fn($x, $y) => abs($y[1]) <=> abs($x[1]));
    $why = implode(', ', array_map(fn($x) => $x[0] . ($x[1] > 0 ? ' (bullish)' : ' (bearish)'), array_filter(array_slice($top, 0, 3), fn($x) => abs($x[1]) >= 1)));
    $txt = $dir ? "Bias is " . strtolower($label) . ($why ? ", led by $why." : '.') : 'Signals are mixed, so there is no clear bias.';
    if ($mem['n'] >= 20) $txt .= sprintf(' In %d similar past setups on this chart, price was higher %d bars later %d%% of the time.', $mem['n'], TD_H, round($mem['up'] * 100));
    if ($I['R'][$i] > 70) $txt .= ' RSI is stretched, so chasing is risky.'; elseif ($I['R'][$i] < 30) $txt .= ' RSI is oversold: a bounce and a breakdown are both possible.';
    if ($st['trades'] >= 5 && $st['hit'] < 50) $txt .= ' On this symbol the rule has hit less than half the time in the past, so keep size small.';
    if ($veto) $txt .= ' Size tier is skip: ' . strtolower(implode('; ', $veto)) . '.';
    return [
        'symbol' => $sym, 'name' => t_name($sym), 'market' => t_symbols()[$sym] ?? 'Other', 'tf' => $tf,
        'data' => ['source' => 'Yahoo Finance via the ChartPulse scanner', 'last_bar' => (int)$B['t'][$i], 'bars' => $n, 'fetched_at' => $now],
        'bias' => ['label' => $label, 'score' => round($tot, 1), 'conviction' => round($mag), 'tier' => $tier, 'vetoes' => $veto,
            'engine' => $eng, 'live' => !empty($row) ? (t_status($sym, $row, $now)['live'] ?? false) : false],
        'factors' => array_map(fn($x) => ['name' => $x[0], 'value' => round($x[1], 1), 'max' => $x[2]], $pt['P']),
        'params' => ['price' => t_r($c), 'atr' => t_r($a), 'atr_pct' => round($a / $c * 100, 2), 'rsi' => round($I['R'][$i], 1), 'ema5' => t_r($I['E5'][$i]), 'ema20' => t_r($I['E20'][$i]),
            'ema50' => t_r($I['E50'][$i]), 'trend' => $I['SD'][$i] > 0 ? 'Up' : 'Down', 'candle' => $pt['pattern'], 'range_pos' => round($pt['rp'] * 100), 'vol_ratio' => $I['hasV'] ? round($pt['vr'], 2) : null,
            'ema20_dist' => round(($c - $I['E20'][$i]) / max($a, 1e-12), 2), 'vol_ann' => round($vol, 1),
            'atr_pctile' => round(count(array_filter($ap, fn($x) => $x <= $cur)) / count($ap) * 100), 'hi20' => t_r($pt['hi']), 'lo20' => t_r($pt['lo'])],
        'cone' => $cone, 'feasibility' => $feas, 'plan' => $plan,
        'memory' => ['n' => $mem['n'], 'pool' => $mem['pool'] ?? 0, 'scope' => $memSrc, 'up' => isset($mem['up']) ? round($mem['up'] * 100, 1) : null, 'avg_move' => isset($mem['avg_move']) ? round($mem['avg_move'], 2) : null,
            'avg_mfe' => isset($mem['avg_mfe']) ? round($mem['avg_mfe'], 2) : null, 'avg_mae' => isset($mem['avg_mae']) ? round($mem['avg_mae'], 2) : null,
            'win' => isset($mem['win']) ? round($mem['win'] * 100, 1) : null, 'avg_run' => isset($mem['avg_run']) ? round($mem['avg_run'], 1) : null, 'hist' => $mem['hist'] ?? [], 'h' => TD_H,
            'confidence' => $mem['n'] >= 30 ? 'normal' : 'low'],
        'news' => ['status' => $news['n'] ? 'live' : 'no_data', 'n' => $news['n'], 'tone' => $tone === null ? null : round($tone, 2), 'top' => $news['top']],
        'selftest' => $st, 'record' => td_record($sym),
        'explanation' => $txt,
        'candles' => ['t' => $sl($B['t']), 'o' => array_map('t_r', $sl($B['o'])), 'h' => array_map('t_r', $sl($B['h'])), 'l' => array_map('t_r', $sl($B['l'])), 'c' => array_map('t_r', $sl($B['c'])),
            'v' => $I['hasV'] ? $sl($B['v']) : null, 'e20' => array_map('t_r', $sl($I['E20'])), 'e5' => array_map('t_r', $sl($I['E5'])), 'rsi' => array_map(fn($x) => round($x, 1), $sl($I['R']))],
        'modules' => ['data' => 'live',
            'TimesFM' => $coneSrc === 'timesfm' ? 'live' : ($coneSrc === 'statistical' ? 'fallback' : 'unavailable'),
            'Setup memory' => $mem['n'] >= 30 ? 'live' : ($mem['n'] ? 'low sample' : 'unavailable'),
            'FinBERT' => !$news['n'] ? 'no data' : (($news['src'] ?? '') === 'finbert' ? 'live' : 'fallback'), 'judge' => 'live'],
        'sources' => ['scenario' => $coneSrc, 'memory' => $memSrc, 'news' => $news['src'] ?? 'lexicon'],
    ];
}
function td_cached(string $sym, string $tf): array {
    t_ensure_dirs(); $dir = T_DATA . '/desk'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $p = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym . '_' . $tf) . '.json';
    $c = t_read_json($p, null);
    if (is_array($c) && t_now() - (int)($c['_at'] ?? 0) < TD_TTL) { unset($c['_at']); return $c; }
    $d = td_analyze($sym, $tf);
    if (!isset($d['error'])) t_write_json($p, $d + ['_at' => t_now()]);
    return $d;
}
