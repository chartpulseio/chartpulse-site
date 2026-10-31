<?php
/**
 * ChartPulse — 200 EMA Scanner engine (daily timeframe).
 *
 * Port of the owner's ema200_scanner.py (v2, "Early entries"), 26 Oct 2026.
 * Strategy base: Weinstein Stage 2 + Minervini trend template + the 200-day rule.
 *
 * Reuses torus_engine.php's data layer only (t_symbols / t_read_frames),
 * so it costs no extra market-data calls: it reads the daily frames the Tier 1 /
 * Tier 2 scanner has already written to the frame cache.
 *
 * Four stages, earliest to safest:
 *   0 WATCH      near the 200 EMA + higher low (long) / lower high (short). NOT an entry.
 *   1 EARLY      near the 200 EMA + a trigger in the last 3 daily bars.
 *   2 CONFIRMED  daily close beyond the 200 EMA by 0.25 ATR within the last 5 bars.
 *   3 BEST       after the cross, price returned to the 200 EMA and held.
 *
 * Direct access is refused; this file is only ever require'd by ema200_api.php.
 */

if (!defined('T_DATA') && PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const E2_MIN_BARS   = 260;   // the strategy's own minimum; fewer bars => symbol is skipped
const E2_BUFFER_ATR = 0.25;
const E2_LOOKBACK   = 5;
const E2_SLOPE_BARS = 20;
const E2_RETEST_ATR = 0.5;
const E2_RETEST_WIN = 60;
const E2_APPROACH   = 1.5;
const E2_SWEEP_LB   = 20;
const E2_TRIG_WIN   = 3;
const E2_STRUCT_BARS = 80;

/** Stage base strength, +15 per agreeing filter, capped at 95 (README v2). */
const E2_BASE = [0 => 20, 1 => 35, 2 => 50, 3 => 65];

const E2_TRIGGER_NAMES = [
    'SWEEP_RECLAIM' => 'Sweep + Reclaim',
    'CHOCH'         => 'CHoCH',
    'EMA_CLOSE'     => 'EMA close',
];

/* ---------------------------------------------------------------------------
   Local indicator copies.
   Deliberately NOT t_ema()/t_atr(): the Python spec is pandas ewm(adjust=False)
   (and Wilder's ATR, alpha=1/n). Seeding differs between EMA implementations and
   a 200-period EMA is sensitive to it, so these are written to match the spec
   exactly rather than inherit whatever the shared helpers seed with.
   --------------------------------------------------------------------------- */

/** pandas: Series.ewm(span=$span, adjust=False).mean() */
function e2_ewm(array $x, int $span): array {
    $n = count($x); if (!$n) return [];
    $a = 2.0 / ($span + 1.0);
    $out = [(float)$x[0]];
    for ($i = 1; $i < $n; $i++) $out[$i] = $a * (float)$x[$i] + (1 - $a) * $out[$i - 1];
    return $out;
}

/** Wilder ATR: true range, then ewm(alpha=1/$n, adjust=False). */
function e2_atr(array $f, int $n = 14): array {
    $c = $f['c']; $h = $f['h']; $l = $f['l'];
    $m = count($c); if (!$m) return [];
    $tr = [(float)$h[0] - (float)$l[0]];
    for ($i = 1; $i < $m; $i++) {
        $pc = (float)$c[$i - 1];
        $tr[$i] = max((float)$h[$i] - (float)$l[$i], abs((float)$h[$i] - $pc), abs((float)$l[$i] - $pc));
    }
    $a = 1.0 / $n;
    $out = [$tr[0]];
    for ($i = 1; $i < $m; $i++) $out[$i] = $a * $tr[$i] + (1 - $a) * $out[$i - 1];
    return $out;
}

/** Rolling mean of the previous $w values (pandas rolling($w).mean().shift(1)); null until filled. */
function e2_roll_mean_prev(array $x, int $w): array {
    $n = count($x); $out = array_fill(0, $n, null); $sum = 0.0;
    for ($i = 0; $i < $n; $i++) {
        if ($i >= $w) { $out[$i] = $sum / $w; $sum -= (float)$x[$i - $w]; }
        $sum += (float)$x[$i];
    }
    // the loop above shifts by one: index $i holds the mean of [$i-$w, $i-1]
    return $out;
}

/**
 * Weekly close series from a daily frame (pandas: close.resample("W").last()).
 * Only the closes are needed, so this does not pull in dd_br_engine's full
 * OHLCV weekly aggregator.
 */
function e2_weekly_close(array $f): array {
    $t = $f['t'] ?? []; $c = $f['c'] ?? [];
    $n = min(count($t), count($c)); if (!$n) return [];
    $out = []; $cur = null; $last = null;
    for ($i = 0; $i < $n; $i++) {
        $wk = (int)gmdate('oW', (int)$t[$i]);
        if ($cur !== null && $wk !== $cur) $out[] = $last;
        $cur = $wk; $last = (float)$c[$i];
    }
    if ($last !== null) $out[] = $last;
    return $out;
}

/** Confirmed pivot indexes: $k bars each side, strict (unique) extreme. */
function e2_pivots(array $vals, int $k, string $kind): array {
    $out = []; $n = count($vals);
    for ($i = $k; $i < $n - $k; $i++) {
        $v = (float)$vals[$i]; $ext = $v; $ties = 0;
        for ($j = $i - $k; $j <= $i + $k; $j++) {
            $w = (float)$vals[$j];
            if ($kind === 'high') { if ($w > $ext) $ext = $w; } else { if ($w < $ext) $ext = $w; }
            if ($w === $v) $ties++;
        }
        if ($v === $ext && $ties === 1) $out[] = $i;
    }
    return $out;
}

/**
 * Triggers that fired within the last E2_TRIG_WIN bars.
 * $d = +1 long-early (price came from below the EMA), -1 short-early (from above).
 */
function e2_early_triggers(array $c, array $h, array $l, array $e, int $d): array {
    $n = count($c); $trig = []; $w = E2_TRIG_WIN;

    // 1) swept the recent extreme and closed back inside it
    for ($i = $n - $w; $i < $n; $i++) {
        if ($i - E2_SWEEP_LB < 0) continue;
        if ($d === 1) {
            $prior = min(array_slice($l, $i - E2_SWEEP_LB, E2_SWEEP_LB));
            if ($l[$i] < $prior && $c[$i] > $prior && $c[$n - 1] > $prior) { $trig[] = 'SWEEP_RECLAIM'; break; }
        } else {
            $prior = max(array_slice($h, $i - E2_SWEEP_LB, E2_SWEEP_LB));
            if ($h[$i] > $prior && $c[$i] < $prior && $c[$n - 1] < $prior) { $trig[] = 'SWEEP_RECLAIM'; break; }
        }
    }

    // 2) CHoCH: first close through the last lower high (long) / higher low (short)
    $off = $n - E2_STRUCT_BARS;
    if ($off >= 0) {
        $src = $d === 1 ? $h : $l;
        $piv = e2_pivots(array_slice($src, $off), 2, $d === 1 ? 'high' : 'low');
        $cnt = count($piv);
        if ($cnt >= 2) {
            $i1 = $piv[$cnt - 1] + $off; $i2 = $piv[$cnt - 2] + $off;
            $structure = $d === 1 ? ($src[$i1] < $src[$i2]) : ($src[$i1] > $src[$i2]);
            if ($structure) {
                $lvl = (float)$src[$i1]; $crossed = false;
                for ($i = $n - $w - 1; $i < $n - 1; $i++) {
                    if ($i < 0) continue;
                    if ($d === 1 ? ($c[$i] <= $lvl) : ($c[$i] >= $lvl)) { $crossed = true; break; }
                }
                if ($crossed && ($d === 1 ? ($c[$n - 1] > $lvl) : ($c[$n - 1] < $lvl))) $trig[] = 'CHOCH';
            }
        }
    }

    // 3) plain daily close back across the 200 EMA, no buffer
    $back = false;
    for ($i = $n - $w - 1; $i < $n - 1; $i++) {
        if ($i < 0) continue;
        if ($d === 1 ? ($c[$i] < $e[$i]) : ($c[$i] > $e[$i])) { $back = true; break; }
    }
    if ($back && ($d === 1 ? ($c[$n - 1] > $e[$n - 1]) : ($c[$n - 1] < $e[$n - 1]))) $trig[] = 'EMA_CLOSE';

    return $trig;
}

/**
 * Scan one symbol's daily frame. Returns null when there is not enough history
 * (caller counts it as skipped), otherwise a row whose 'state' is 'NONE' when
 * no stage qualified.
 */
function e2_scan(array $d, bool $enableEarly = true): ?array {
    $c = $d['c'] ?? null;
    if (!$c || count($c) < E2_MIN_BARS) return null;
    $h = $d['h']; $l = $d['l']; $v = $d['v'] ?? null;
    $n = count($c);

    $e   = e2_ewm($c, 200);
    $atr = e2_atr($d, 14);

    // decisive side, carried forward across the buffer band
    $state = array_fill(0, $n, 0); $last = 0;
    for ($i = 0; $i < $n; $i++) {
        $b = E2_BUFFER_ATR * $atr[$i];
        if ($c[$i] > $e[$i] + $b)      $last = 1;
        elseif ($c[$i] < $e[$i] - $b)  $last = -1;
        $state[$i] = $last;
    }
    $cur = $state[$n - 1];

    $px = (float)$c[$n - 1]; $ex = (float)$e[$n - 1]; $ax = (float)$atr[$n - 1];
    $out = [
        'state' => 'NONE', 'stage' => null, 'triggers' => [],
        'side' => $cur === 1 ? 'UP' : ($cur === -1 ? 'DOWN' : '-'),
        'dist_pct' => $ex != 0 ? round(($px - $ex) / $ex * 100, 2) : 0.0,
        'ema200' => round($ex, 4), 'bars_since_cross' => null, 'score' => 0,
    ];
    if ($cur === 0) return $out;

    // real crosses only: both sides decisive and different
    $flips = [];
    for ($i = 1; $i < $n; $i++) {
        if ($state[$i] !== $state[$i - 1] && $state[$i - 1] !== 0 && $state[$i] !== 0) $flips[] = $i;
    }

    // weekly close series for the higher-timeframe filter
    $wc = e2_weekly_close($d);
    if (count($wc) < 3) return $out;
    $w40 = e2_ewm($wc, 40); $w10 = e2_ewm($wc, 10);
    $wLast = (float)end($wc);

    $vavgPrev = $v ? e2_roll_mean_prev($v, 20) : null;
    $sb = E2_SLOPE_BARS;

    /* ---------------------------------------------- stages 2 and 3 */
    if ($flips) {
        $f = (int)end($flips); $since = $n - 1 - $f;
        $out['bars_since_cross'] = $since;

        $base = (float)$e[$n - 1 - $sb];
        $slope = $base != 0 ? ($ex - $base) / $base * 100 : 0.0;
        $slopeOk = $cur === 1 ? $slope > 0 : $slope < 0;

        $volOk = false;
        if ($vavgPrev !== null && $vavgPrev[$f] !== null && $vavgPrev[$f] > 0) {
            $volOk = (float)$v[$f] > 1.2 * $vavgPrev[$f];
        }

        $weeklyOk = $cur === 1 ? $wLast > (float)end($w40) : $wLast < (float)end($w40);

        // retest: price pulled back into the EMA zone and closed back on the right side
        $retest = false;
        if ($since >= 2 && $since <= E2_RETEST_WIN) {
            for ($i = max(0, $n - 5); $i < $n; $i++) {
                $z = E2_RETEST_ATR * $atr[$i];
                if ($cur === 1 ? ($l[$i] <= $e[$i] + $z) : ($h[$i] >= $e[$i] - $z)) { $retest = true; break; }
            }
            if ($retest) $retest = $cur === 1 ? $px > $ex : $px < $ex;
        }

        $rightSide = $cur === 1 ? $px > $ex : $px < $ex;
        $label = null; $stage = null;
        if ($since <= E2_LOOKBACK && $rightSide) {
            $label = $cur === 1 ? 'FRESH_CROSS_UP' : 'FRESH_CROSS_DOWN'; $stage = 2;
        } elseif ($retest) {
            $label = $cur === 1 ? 'RETEST_HOLD_UP' : 'RETEST_HOLD_DOWN'; $stage = 3;
        }

        if ($stage !== null) {
            $score = (int)$slopeOk + (int)$volOk + (int)$weeklyOk;
            return array_merge($out, [
                'state' => $label, 'stage' => $stage, 'slope_pct_20' => round($slope, 2),
                'slope_ok' => $slopeOk, 'vol_ok' => $volOk, 'weekly_ok' => $weeklyOk, 'score' => $score,
            ]);
        }
    }

    /* ---------------------------------------------- stages 1 and 0 (early) */
    if (!$enableEarly) return $out;
    if ($n - 1 - 2 * $sb < 0) return $out;

    $dir = $cur === -1 ? 1 : -1;   // price below the EMA => looking for a long
    $near = $dir === 1 ? ($px >= $ex - E2_APPROACH * $ax) : ($px <= $ex + E2_APPROACH * $ax);
    if (!$near) return $out;

    $triggers = e2_early_triggers($c, $h, $l, $e, $dir);

    $struct = false;
    if ($n >= 15) {
        if ($dir === 1) $struct = min(array_slice($l, -5)) > min(array_slice($l, -15, 10));
        else            $struct = max(array_slice($h, -5)) < max(array_slice($h, -15, 10));
    }

    if ($triggers)     { $label = $dir === 1 ? 'EARLY_UP' : 'EARLY_DOWN'; $stage = 1; }
    elseif ($struct)   { $label = $dir === 1 ? 'WATCH_UP' : 'WATCH_DOWN'; $stage = 0; }
    else return $out;

    $b1 = (float)$e[$n - 1 - $sb]; $b2 = (float)$e[$n - 1 - 2 * $sb];
    $sNow  = $b1 != 0 ? ($ex - $b1) / $b1 * 100 : 0.0;
    $sPrev = $b2 != 0 ? ($b1 - $b2) / $b2 * 100 : 0.0;
    $slopeOk = $dir === 1 ? $sNow > $sPrev : $sNow < $sPrev;   // the EMA slope is turning our way

    $volOk = false;
    if ($vavgPrev !== null) {
        for ($i = $n - E2_TRIG_WIN; $i < $n; $i++) {
            if ($i < 0 || $vavgPrev[$i] === null || $vavgPrev[$i] <= 0) continue;
            if ((float)$v[$i] > 1.2 * $vavgPrev[$i]) { $volOk = true; break; }
        }
    }

    $weeklyOk = $dir === 1 ? $wLast > (float)end($w10) : $wLast < (float)end($w10);

    return array_merge($out, [
        'state' => $label, 'stage' => $stage, 'triggers' => $triggers,
        'slope_pct_20' => round($sNow, 2),
        'slope_ok' => $slopeOk, 'vol_ok' => $volOk, 'weekly_ok' => $weeklyOk,
        'score' => (int)$slopeOk + (int)$volOk + (int)$weeklyOk,
    ]);
}

/** Public row for the panel / API. Every row carries the "Entry from 200 EMA" badge. */
function e2_row(string $sym, string $market, array $r, float $price, ?string $tv = null): array {
    $stage = (int)$r['stage'];
    $isUp  = str_ends_with($r['state'], '_UP');
    $names = array_map(fn($t) => E2_TRIGGER_NAMES[$t] ?? $t, $r['triggers'] ?? []);
    $entry = [0 => 'watch', 1 => 'early', 2 => 'confirmed', 3 => 'best'][$stage];
    return [
        'symbol' => $sym, 'market' => $market, 'tv' => $tv,
        'side' => $isUp ? 'LONG' : 'SHORT',
        'signal' => $isUp ? 'CALL' : 'PUT',
        'entry_type' => $entry, 'stage' => $stage, 'state' => $r['state'],
        'trigger' => $names ? implode(' + ', $names) : null,
        'triggers' => $r['triggers'] ?? [],
        'is_entry' => $stage > 0,
        'strength' => min(95, E2_BASE[$stage] + 15 * (int)$r['score']),
        'score' => (int)$r['score'],
        'slope_ok' => !empty($r['slope_ok']), 'vol_ok' => !empty($r['vol_ok']),
        'weekly_ok' => !empty($r['weekly_ok']),
        'slope_pct_20' => $r['slope_pct_20'] ?? null,
        'dist_pct' => $r['dist_pct'], 'ema200' => $r['ema200'],
        'bars_since_cross' => $r['bars_since_cross'],
        'price' => round($price, 6),
        'tag' => 'Entry from 200 EMA',
    ];
}
