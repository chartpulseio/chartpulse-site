<?php
// TORUS v9 (owner, 6 Oct 2026): ADR-normalised "near" + Liquidity Sweep + Reclaim. Shared by Tier 1 and Tier 2.
// Server-only (denied by .htaccess like every torus_*.php). Ported 1:1 from near_threshold.py / sweep_reclaim.py.

// ---- config ---------------------------------------------------------------
const TSW_NEAR_PCT = 0.10, TSW_ADR_LOOKBACK = 20, TSW_ADR_MIN = 10, TSW_ADR_FLOOR = 0.5, TSW_ADR_CAP = 1.5, TSW_CUTOFF = 40;
const TSW_CFG = [
    'min_atr' => 0.1, 'max_atr' => 1.0, 'reclaim' => 3, 'or_min' => 30, 'eq_tol' => 0.15, 'eq_touch' => 2,
    'zone_tol' => 0.4, 'max_age' => 60, 'sl_buf' => 0.3, 'max_risk' => 1.5, 'trend_pts' => 10.0, 'swing_n' => 2, 'h1_look' => 60,
    'score_enabled' => true,          // SWEEP_SCORE_ENABLED  (Trend +10)    — ON (owner, 6 Oct 2026 13:12 IST)
    'gate_retrace_enabled' => true,   // SWEEP_GATE_RETRACE_ENABLED         — ON (owner, 6 Oct 2026 13:12 IST)
    'risk_filter' => false,           // owner 6 Oct 19:46: OFF (2-day TP1/SL check: it cut 111 of 163 good sweep trades)
    'sweep_sl' => false,              // owner 6 Oct 19:46: keep the v8 SL on sweep signals (no wider sweep SL)
];

// ---- Fix #1: near -----------------------------------------------------------
/** ADR = median (High-Low) of the last 20 COMPLETED daily bars (today, index $n-1, excluded). null when < 10 sessions. */
function tsw_adr(array $D, int $n): ?float {
    // cheap (<= 40 numbers from the daily bars already in memory); cached per symbol + session day by the caller's frame
    static $cache = [];
    $key = ($D['t'][$n - 1] ?? 0) . ':' . ($D['h'][$n - 2] ?? 0) . ':' . ($D['l'][$n - 2] ?? 0) . ':' . $n;
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (count($cache) > 4000) $cache = [];
    $r = [];
    for ($i = max(0, $n - 1 - 40); $i < $n - 1; $i++) { $x = (float)$D['h'][$i] - (float)$D['l'][$i]; if (is_finite($x) && $x > 0) $r[] = $x; }
    $r = array_slice($r, -TSW_ADR_LOOKBACK);
    if (count($r) < TSW_ADR_MIN) return $cache[$key] = null;
    sort($r); $m = count($r);
    return $cache[$key] = ($m % 2 ? $r[intdiv($m, 2)] : ($r[$m / 2 - 1] + $r[$m / 2]) / 2);
}
/** [near_new, near_old, used_fallback]. near = 0.10 x clamp(DayRange, 0.5 ADR, 1.5 ADR). */
function tsw_near(float $dayHigh, float $dayLow, ?float $adr): array {
    $rg = max($dayHigh - $dayLow, 0.0); $old = TSW_NEAR_PCT * $rg;
    if ($adr === null || $adr <= 0) return [$old, $old, true];
    return [TSW_NEAR_PCT * min(max($rg, TSW_ADR_FLOOR * $adr), TSW_ADR_CAP * $adr), $old, false];
}
function tsw_cutoff_change(float $old, float $new): ?string {
    $a = $old < TSW_CUTOFF; $b = $new < TSW_CUTOFF;
    return ($a && !$b) ? 'UNHIDDEN' : ((!$a && $b) ? 'HIDDEN' : null);
}

// ---- Change #2: sweep + reclaim (CALL logic; PUT mirrors prices) ------------
// candle = [t, o, h, l, c]   (t = candle OPEN time, unix seconds)
function tsw_mirror(array $c): array { return [$c[0], -$c[1], -$c[3], -$c[2], -$c[4]]; }
function tsw_pivots(array $C, int $n): array {
    $o = []; $m = count($C);
    for ($i = $n; $i < $m - $n; $i++) {
        $lf = INF; $rt = INF;
        for ($k = 1; $k <= $n; $k++) { $lf = min($lf, $C[$i - $k][3]); $rt = min($rt, $C[$i + $k][3]); }
        if ($C[$i][3] < $lf && $C[$i][3] <= $rt) $o[] = $i;
    }
    return $o;
}
function tsw_levels(array $C5, array $H1, ?float $pdl, int $open, float $atr, array $cfg): array {
    $L = [];
    if ($pdl !== null) $L[] = ['p' => $pdl, 'k' => 'PDL', 'from' => null];
    $orEnd = $open + $cfg['or_min'] * 60; $orc = array_values(array_filter($C5, fn($c) => $c[0] >= $open && $c[0] < $orEnd));
    if ($orc && max(array_column($orc, 0)) + 300 >= $orEnd) $L[] = ['p' => min(array_column($orc, 3)), 'k' => 'OR_LOW', 'from' => $orEnd];
    $n = $cfg['swing_n']; $pv = [];
    foreach (tsw_pivots($C5, $n) as $i) $pv[] = [$C5[$i][3], $i];
    usort($pv, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    $tol = $cfg['eq_tol'] * $atr; $cl = [];
    foreach ($pv as $x) { $last = count($cl) - 1; if ($cl && $x[0] - $cl[$last][0][0] <= $tol) $cl[$last][] = $x; else $cl[] = [$x]; }
    foreach ($cl as $g) if (count($g) >= $cfg['eq_touch']) {
        $li = max(array_column($g, 1)); $L[] = ['p' => $g[0][0], 'k' => 'EQUAL_LOW', 'from' => $C5[$li + $n][0] + 300];
    }
    $h1 = array_slice($H1, -$cfg['h1_look']);
    foreach (tsw_pivots($h1, $n) as $i) $L[] = ['p' => $h1[$i][3], 'k' => 'SWING_LOW', 'from' => $h1[$i + $n][0] + 3600];
    return $L;
}
function tsw_scan(array $lv, array $C, float $atr, float $dayLow, int $now, array $cfg): ?array {
    $n = count($C); $minD = $cfg['min_atr'] * $atr; $maxD = $cfg['max_atr'] * $atr; $best = null;
    for ($i = 0; $i < $n; $i++) {
        $c = $C[$i];
        if ($lv['from'] !== null && $c[0] < $lv['from']) continue;
        if ($c[3] > $lv['p'] - $minD) continue;
        $above = $i === 0 ? $c[1] >= $lv['p'] : $C[$i - 1][4] >= $lv['p'];
        if (!$above) continue;
        $ext = $c[3]; $rc = null;
        for ($j = $i; $j <= min($n - 1, $i + $cfg['reclaim']); $j++) { $ext = min($ext, $C[$j][3]); if ($C[$j][4] > $lv['p']) { $rc = $j; break; } }
        if ($rc === null) continue;
        if ($lv['p'] - $ext > $maxD) continue;
        $void = false; for ($k = $rc + 1; $k < $n; $k++) if ($C[$k][3] < $ext) { $void = true; break; }
        if ($void) continue;
        if (abs($ext - $dayLow) > $cfg['zone_tol'] * $atr) continue;
        $age = max(($now - ($C[$rc][0] + 300)) / 60.0, 0.0);
        if ($age > $cfg['max_age']) continue;
        if ($best === null || $rc > $best['rc']) $best = ['lv' => $lv, 'si' => $i, 'rc' => $rc, 'ext' => $ext, 'age' => $age];
    }
    return $best;
}
const TSW_PRIO = ['PDL' => 0, 'OR_LOW' => 1, 'EQUAL_LOW' => 2, 'SWING_LOW' => 3];
const TSW_PUT = ['PDL' => 'PDH', 'OR_LOW' => 'OR_HIGH', 'EQUAL_LOW' => 'EQUAL_HIGH', 'SWING_LOW' => 'SWING_HIGH'];
/**
 * $sg = +1 CALL / -1 PUT. $C5 / $H1 = today's CLOSED 5m bars / closed 1H bars as [t,o,h,l,c] (real prices).
 * Returns null (no sweep) or ['kind','level','ext','depth_atr','reclaim_candles','age_min','sweep_ts','reclaim_ts'].
 */
function tsw_detect(int $sg, array $C5, float $atr, float $dayHigh, float $dayLow, int $now, int $open,
                    ?float $pdh = null, ?float $pdl = null, array $H1 = [], array $cfg = TSW_CFG): ?array {
    if (!$C5 || $atr <= 0) return null;
    if ($sg < 0) { $C5 = array_map('tsw_mirror', $C5); $H1 = array_map('tsw_mirror', $H1); $pl = $pdh === null ? null : -$pdh; $dl = -$dayHigh; }
    else { $pl = $pdl; $dl = $dayLow; }
    $hits = [];
    foreach (tsw_levels($C5, $H1, $pl, $open, $atr, $cfg) as $lv) { $h = tsw_scan($lv, $C5, $atr, $dl, $now, $cfg); if ($h) $hits[] = $h; }
    if (!$hits) return null;
    usort($hits, fn($a, $b) => $b['rc'] <=> $a['rc'] ?: TSW_PRIO[$a['lv']['k']] <=> TSW_PRIO[$b['lv']['k']]);
    $h = $hits[0]; $s = $sg < 0 ? -1.0 : 1.0;
    return ['kind' => $sg < 0 ? TSW_PUT[$h['lv']['k']] : $h['lv']['k'], 'level' => $s * $h['lv']['p'], 'ext' => $s * $h['ext'],
        'depth_atr' => round(($h['lv']['p'] - $h['ext']) / $atr, 3), 'reclaim_candles' => $h['rc'] - $h['si'],
        'age_min' => round($h['age'], 1), 'sweep_ts' => $C5[$h['si']][0], 'reclaim_ts' => $C5[$h['rc']][0]];
}
/** SL = the further of the existing SL and (sweep extreme -/+ 0.3 x ATR5). */
function tsw_sl(int $sg, ?float $sl, ?array $sw, float $atr, array $cfg = TSW_CFG): ?float {
    if (!$sw) return $sl;
    $x = $sw['ext'] - $sg * $cfg['sl_buf'] * $atr;
    return $sl === null ? $x : ($sg > 0 ? min($sl, $x) : max($sl, $x));
}
function tsw_risk_ok(int $sg, float $entry, float $sl, float $atr, array $cfg = TSW_CFG): bool {
    if ($sg > 0 ? $sl >= $entry : $sl <= $entry) return false;
    return abs($entry - $sl) <= $cfg['max_risk'] * $atr;
}
/** Retracement gate + Trend points. Flags OFF = nothing changes (shadow). Returns [status, points, gated].
 *  Gate (owner, 6 Oct 2026 13:12 IST): Retracement without a sweep may show RUNWAY, but never FLY: a FLY drops to RUNWAY when it had a
 *  runway ($runway = runway type), else to APPROACHING. */
function tsw_rules(?string $setup, ?string $status, ?array $sw, array $cfg = TSW_CFG, ?string $runway = null): array {
    $st = $status;
    if ($cfg['gate_retrace_enabled'] && $setup === 'retrace' && !$sw && $status === 'FLY') $st = $runway ? 'RUNWAY' : 'APPROACHING';
    $pts = ($cfg['score_enabled'] && $setup === 'trend' && $sw) ? $cfg['trend_pts'] : 0.0;
    return [$st, $pts, $st !== $status];
}
function tsw_log_fields(?string $setup, ?array $sw): array {
    return ['setup' => $setup, 'sweep_present' => (bool)$sw, 'level_type' => $sw['kind'] ?? null, 'sweep_depth_atr' => $sw['depth_atr'] ?? null,
        'reclaim_candles' => $sw['reclaim_candles'] ?? null, 'sweep_age_min' => $sw['age_min'] ?? null];
}

// ---- adapters for the engine's column frames ----------------------------------
/** Column frame (t,o,h,l,c arrays) indices [$a..$b] -> candle list. */
function tsw_rows(array $F, int $a, int $b): array {
    $o = []; for ($i = max(0, $a); $i <= $b; $i++) $o[] = [(int)$F['t'][$i], (float)$F['o'][$i], (float)$F['h'][$i], (float)$F['l'][$i], (float)$F['c'][$i]];
    return $o;
}
/** Previous session's high/low from 5m bars before index $a (contiguous block, gaps < 2 h, max 24 h). */
function tsw_prev_hl(array $F, int $a): array {
    $p = $a - 1; if ($p < 0) return [null, null];
    $h = (float)$F['h'][$p]; $l = (float)$F['l'][$p]; $t0 = (int)$F['t'][$p];
    for ($i = $p - 1; $i >= 0; $i--) {
        if ((int)$F['t'][$i + 1] - (int)$F['t'][$i] > 7200 || $t0 - (int)$F['t'][$i] >= 86400) break;
        $h = max($h, (float)$F['h'][$i]); $l = min($l, (float)$F['l'][$i]);
    }
    return [$h, $l];
}
/** Closed 1H candles built from 5m bars [0..$b] whose hour ended by $now. */
function tsw_h1(array $F, int $b, int $now): array {
    $G = []; for ($i = 0; $i <= $b; $i++) {
        $k = intdiv((int)$F['t'][$i], 3600) * 3600;
        if (!isset($G[$k])) $G[$k] = [$k, (float)$F['o'][$i], (float)$F['h'][$i], (float)$F['l'][$i], (float)$F['c'][$i]];
        else { $G[$k][2] = max($G[$k][2], (float)$F['h'][$i]); $G[$k][3] = min($G[$k][3], (float)$F['l'][$i]); $G[$k][4] = (float)$F['c'][$i]; }
    }
    return array_values(array_filter($G, fn($c) => $c[0] + 3600 <= $now));
}
/** tsw_log once per $key (keys remembered for 3 days in data/<file>.seen). */
function tsw_log_once(string $file, string $key, array $rec): void {
    static $seen = [], $loaded = [];
    $p = __DIR__ . "/data/$file.seen";
    if (!isset($loaded[$file])) { $loaded[$file] = true; $j = @json_decode((string)@file_get_contents($p), true); $seen[$file] = is_array($j) ? $j : []; }
    if (isset($seen[$file][$key])) return;
    $now = time(); $seen[$file] = array_filter($seen[$file], fn($t) => $t > $now - 3 * 86400); $seen[$file][$key] = $now;
    @file_put_contents($p, json_encode($seen[$file]), LOCK_EX);
    tsw_log($file, $rec);
}
/** Append one JSON line to data/<file> (best effort). */
function tsw_log(string $file, array $rec): void {
    $d = __DIR__ . '/data'; if (!is_dir($d)) return;
    $p = "$d/$file"; if (is_file($p) && filesize($p) > 8 * 1024 * 1024) @rename($p, "$p.1");
    @file_put_contents($p, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
