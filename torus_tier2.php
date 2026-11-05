<?php
// ---------------------------------------------------------------------------
// TORUS Tier 2: the execution view for one symbol, opened from a Tier-1 row.
// Rules stay on the server: the page only receives candles, the entry / target / SL lines and the Day zone.
// Strategy v8 (owner, 5 Oct 2026), BOTH setups (trend / long target and retracement / short target):
//   - zone = today's live Day Low (CALL) / Day High (PUT); a candle is "at" the zone within 0.4 x ATR(14, 5m)
//   - only around a Tier-1 signal: 20 min before it appears to 30 min after it ends; no pattern in the first 60 min of the session
//   - FLY: a shared reversal pattern (t_reversal) on a closed 10-minute candle (t_ten_session, anchored to the session's first
//     5m bar) at the zone, and the next closed 10-minute candle confirms. Detected by t_fly_events, the same code Tier 1 uses.
//   - RUNWAY (not required): closed 5m wick through BB(20, 2) ("bb") or closed 3m close across the 5-EMA ("ema"), near the zone.
//     Grade A+ = bb runway before the FLY, A = ema runway only, B = no runway.
//   - signal price = close of the FLY confirmation bar (internal backtest record only; never published)
//   - SL: A+ = bb runway wick -/+ 0.3 x ATR5, else the pattern candle low (CALL) / high (PUT) -/+ 0.3 x ATR5 (ATR at signal time);
//     the setup is void when the confirmation bar already breaks that SL
//   - TP1: the pivot point, or the nearest major 1W Fibonacci level when the pivot sits in the entry range (< 1R or inside the zone band);
//     retracement has TP1 only, trend adds TP2-TP3 = the next major 1W Fibonacci levels.
//     After TP1 the trade runs until a 5-minute candle closes on the wrong side of the 5-minute 5-EMA. The stop is not trailed.
//   - one trade at a time per symbol; session end closes an open trade
//   - public output (t2_public / t2_pub_trade / Telegram): FLY / RUNWAY state, grade, SL and target levels only. No entry price,
//     entry time, exit or result on the Tier-2 page; the Recent trades list keeps R / outcome for the honest record.
// Every trade is replayed from price history, so the page, Telegram and the Recent trades list always agree.
// Open to everyone until config 'tier2_paywall' => true.
// ---------------------------------------------------------------------------
declare(strict_types=1);

const T2_TTL = 20;
function t2_paywall(): bool { return (bool)t_cfg('tier2_paywall', false); }

/* ---------------- indicator helpers (all computed from the fetched bars) ---------------- */
function t2_last(array $a) { return $a ? $a[count($a) - 1] : null; }
function t2_sma_last(array $x, int $p): ?float { $n = count($x); if ($n < $p) return null; return array_sum(array_slice($x, -$p)) / $p; }
function t2_macd_hist(array $c): float {
    $f = t_ema($c, 12); $s = t_ema($c, 26); $m = []; foreach ($c as $i => $_) $m[] = $f[$i] - $s[$i];
    $sig = t_ema($m, 9); return (float)(t2_last($m) - t2_last($sig));
}
function t2_pctb(array $c, int $p = 20, float $k = 2.0): ?float {
    $n = count($c); if ($n < $p) return null; $w = array_slice($c, -$p); $mu = array_sum($w) / $p;
    $sd = sqrt(array_sum(array_map(fn($x) => ($x - $mu) ** 2, $w)) / ($p - 1)); if ($sd == 0) return 0.5;
    return (end($c) - ($mu - $k * $sd)) / (2 * $k * $sd);
}
function t2_hl_window(array $f, int $p): array { $h = array_slice($f['h'], -$p); $l = array_slice($f['l'], -$p); return [max($h), min($l)]; }
function t2_stoch(array $f, int $p = 14): ?float { if (count($f['c']) < $p) return null; [$h, $l] = t2_hl_window($f, $p); return $h > $l ? 100 * (end($f['c']) - $l) / ($h - $l) : 50.0; }
function t2_willr(array $f, int $p = 14): ?float { if (count($f['c']) < $p) return null; [$h, $l] = t2_hl_window($f, $p); return $h > $l ? -100 * ($h - end($f['c'])) / ($h - $l) : -50.0; }
function t2_cci(array $f, int $p = 20): ?float {
    $n = count($f['c']); if ($n < $p) return null; $tp = [];
    for ($i = $n - $p; $i < $n; $i++) $tp[] = ($f['h'][$i] + $f['l'][$i] + $f['c'][$i]) / 3;
    $mu = array_sum($tp) / $p; $md = array_sum(array_map(fn($x) => abs($x - $mu), $tp)) / $p;
    return $md > 0 ? (end($tp) - $mu) / (0.015 * $md) : 0.0;
}
function t2_mfi(array $f, int $p = 14): ?float {
    $n = count($f['c']); if ($n <= $p) return null; $pos = 0.0; $neg = 0.0;
    for ($i = $n - $p; $i < $n; $i++) {
        $tp = ($f['h'][$i] + $f['l'][$i] + $f['c'][$i]) / 3; $tp0 = ($f['h'][$i - 1] + $f['l'][$i - 1] + $f['c'][$i - 1]) / 3; $mf = $tp * $f['v'][$i];
        if ($tp > $tp0) $pos += $mf; elseif ($tp < $tp0) $neg += $mf;
    }
    if ($pos + $neg <= 0) return null;
    return $neg == 0 ? 100.0 : 100 - 100 / (1 + $pos / $neg);
}
function t2_obv_trend(array $f): ?string {
    $n = count($f['c']); if ($n < 11) return null; $o = [0.0];
    for ($i = 1; $i < $n; $i++) $o[] = $o[$i - 1] + ($f['c'][$i] <=> $f['c'][$i - 1]) * $f['v'][$i];
    return $o[$n - 1] > $o[$n - 11] ? 'UP' : ($o[$n - 1] < $o[$n - 11] ? 'DOWN' : 'FLAT');
}

/* ---------------- data ---------------- */
function t2_sessions(array $f, string $tz): array {   // index ranges of each exchange-local trading day
    $key = t_local_day($tz ?: 'UTC'); $out = []; $cur = null;
    foreach ($f['t'] as $i => $t) { $k = $key($t); if ($k !== $cur) { $out[] = [$i, $i]; $cur = $k; } else $out[count($out) - 1][1] = $i; }
    return $out;
}
function t2_slice(array $f, int $a, int $b): array { $o = []; foreach ($f as $k => $v) $o[$k] = array_slice($v, $a, $b - $a + 1); return $o; }
function t2_fibs(float $lo, float $hi): array {
    $d = $hi - $lo;
    return ['Fib 23.6' => $hi - $d * 0.236, 'Fib 38.2' => $hi - $d * 0.382, 'Fib 50.0' => $hi - $d * 0.5, 'Fib 61.8' => $hi - $d * 0.618,
        'Fib 78.6' => $hi - $d * 0.786, 'Prev Low' => $lo, 'Prev High' => $hi, 'Ext 127.2' => $hi + $d * 0.272, 'Ext 161.8' => $hi + $d * 0.618];
}
/** Major Fibonacci levels of the previous completed week (owner, 5 Oct 2026): set per symbol before a replay. */
function t2_wfib(?array $set = null): array { static $W = []; if ($set !== null) $W = $set; return $W; }
function t2_wfib_calc(string $sym): array {
    $fr = t_load_frames($sym); $w = $fr['1W'] ?? null; $n = $w ? count($w['h']) : 0;
    if ($n < 2) return [];
    $hi = (float)$w['h'][$n - 2]; $lo = (float)$w['l'][$n - 2]; $d = $hi - $lo; if ($d <= 0) return [];
    return ['W Low' => $lo, 'W 38.2' => $lo + $d * 0.382, 'W 50' => $lo + $d * 0.5, 'W 61.8' => $lo + $d * 0.618, 'W High' => $hi,
        'W Ext 127.2' => $hi + $d * 0.272, 'W Ext 161.8' => $hi + $d * 0.618, 'W Ext -27.2' => $lo - $d * 0.272, 'W Ext -61.8' => $lo - $d * 0.618];
}
/** Nearest level beyond the action in the trade direction, at least $min away. */
function t2_next_target(array $levels, float $act, string $bias, float $min): ?float {
    $best = null;
    foreach ($levels as $l) {
        if ($l === null) continue; $l = (float)$l; $d = $bias === 'CALL' ? $l - $act : $act - $l;
        if ($d >= $min && ($best === null || $d < ($bias === 'CALL' ? $best - $act : $act - $best))) $best = $l;
    }
    return $best;
}

/* ---------------- trade rules (see header) ---------------- */
const T2_ZB = 0.4;
/** 5-minute and 1-minute bars (Yahoo, 5 days) plus the exchange time zone. */
function t2_data(string $sym): ?array {
    if (str_starts_with($sym, 'MCX:')) return t2_data_mcx($sym);
    $b = t_fetch(['m5' => t_url($sym, '5d', '5m'), 'm1' => t_url($sym, '5d', '1m')]);
    $md = t2_data_parse($b['m5'] ?? null, $b['m1'] ?? null);
    if ($md) t_m1_put($sym, $md['f1']);   // v8: the Tier-1 scanner reuses these 1m bars for "ema" runways
    return $md;
}
/** MCX futures: 5m and 1m bars from Upstox (previous days + today), owner 5 Oct 2026. */
function t2_data_mcx(string $sym): ?array {
    if (!function_exists('t_up_on') || !t_up_on()) return null;
    $k = t_up_key($sym); if (!$k) return null;
    $now = t_now(); $y = t_up_ymd($now - 86400); $from = t_up_ymd($now - 8 * 86400);
    $b = t_fetch(['h5' => t_up_req("/v3/historical-candle/$k/minutes/5/$y/$from"), 'i5' => t_up_req("/v3/historical-candle/intraday/$k/minutes/5"),
        'h1' => t_up_req("/v3/historical-candle/$k/minutes/1/$y/" . t_up_ymd($now - 4 * 86400)), 'i1' => t_up_req("/v3/historical-candle/intraday/$k/minutes/1")]);
    $f5 = t2_fmerge(t_up_parse($b['h5'] ?? null), t_up_parse($b['i5'] ?? null));
    $f1 = t2_fmerge(t_up_parse($b['h1'] ?? null), t_up_parse($b['i1'] ?? null));
    if (!$f5 || count($f5['t']) < 20) return null;
    $f1 = ($f1 && count($f1['t']) > 30) ? $f1 : null;
    if ($f1) t_m1_put($sym, $f1);
    return ['f5' => $f5, 'f1' => $f1, 'tz' => 'Asia/Kolkata'];
}
/** Merge two bar frames by timestamp (later frame wins on overlap). */
function t2_fmerge(?array $a, ?array $b): ?array {
    if (!$a) return $b; if (!$b) return $a;
    $rows = [];
    foreach ([$a, $b] as $f) foreach ($f['t'] as $i => $t) $rows[(int)$t] = [$f['o'][$i], $f['h'][$i], $f['l'][$i], $f['c'][$i], $f['v'][$i] ?? 0];
    ksort($rows);
    $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    foreach ($rows as $t => [$op, $h, $l, $c, $v]) { $o['t'][] = $t; $o['o'][] = $op; $o['h'][] = $h; $o['l'][] = $l; $o['c'][] = $c; $o['v'][] = $v; }
    return $o;
}
function t2_data_parse(?string $m5, ?string $m1): ?array {
    [$f5, $meta] = t_parse($m5); [$f1] = t_parse($m1);
    if (!$f5 || count($f5['t']) < 20) return null;
    return ['f5' => $f5, 'f1' => ($f1 && count($f1['t']) > 30) ? $f1 : null, 'tz' => (string)($meta['tz'] ?? 'UTC')];
}
/** Bars that have closed by $now. */
function t2_closed(array $f, int $sec, int $now): array {
    $n = count($f['t']); while ($n > 0 && (int)$f['t'][$n - 1] + $sec > $now) $n--;
    return $n === count($f['t']) ? $f : t2_slice($f, 0, $n - 1);
}
/** This symbol's Tier-1 CALL / PUT episodes since $from (from the Recent signals log), plus the live one. */
function t2_episodes(string $sym, int $from, int $now, ?string $live): array {
    $L = &t_siglog(); $eps = [];
    foreach ($L['items'] as $e) {
        if ($e['symbol'] !== $sym) continue;
        $end = !empty($e['end']) ? (int)$e['end'] : ($now - (int)$e['last'] > 1800 ? (int)$e['last'] + 300 : $now);
        if ($end < $from - 1800) continue;
        $eps[] = ['sig' => $e['signal'], 's' => (int)$e['start'], 'e' => $end, 'p' => $e['price'] ?? null, 'al' => $e['alignment'] ?? null, 'setup' => $e['setup'] ?? null, 'remark' => $e['remark'] ?? null];
    }
    if ($live && !array_filter($eps, fn($x) => $x['sig'] === $live && $x['e'] >= $now - 60)) $eps[] = ['sig' => $live, 's' => $now - 300, 'e' => $now];
    return $eps;
}
/**
 * Replay one session [$a..$b] of 5-minute bars with the v8 rules (both setups). $F = 5m bars, $M1 = 1m bars (trade path + 3m EMA runways).
 * FLY events come from the shared engine (t_fly_events: same 10m builder, patterns, gates and SL as Tier 1); here they are kept only
 * around this symbol's Tier-1 signals (20 min before to 30 min after), one trade at a time. Signal price = close of the FLY
 * confirmation bar. $running = the session is still trading (an unfinished trade stays open instead of closing at the session end).
 * Returns ['trades' => [...], 'pending' => null | ['stage' => 'pattern', ...]]. Internal record only: the public view strips prices / times.
 */
function t2_replay_session(array $F, ?array $M1, int $a, int $b, array $eps, ?array $P, int $now, bool $running): array {
    $out = ['trades' => [], 'pending' => null];
    if (!$eps || !$M1 || !$P) return $out;
    $zb = T2_ZB; $F = t2_closed($F, 300, $now); $b = min($b, count($F['t']) - 1); if ($b - $a < 3) return $out;
    $M1 = t2_closed($M1, 60, $now); $n1 = count($M1['t']); if ($n1 < 30) return $out;
    $E5 = t_ema($F['c'], 5);
    $dayTs = (int)$F['t'][$a]; $endTs = (int)$F['t'][$b] + 300;
    $first = function (array $t, int $x): int { $lo = 0; $hi = count($t) - 1; $r = count($t); while ($lo <= $hi) { $m = intdiv($lo + $hi, 2); if ($t[$m] >= $x) { $r = $m; $hi = $m - 1; } else $lo = $m + 1; } return $r; };
    $epFor = function (string $sig, int $t) use ($eps): ?array { foreach ($eps as $e) if ($e['sig'] === $sig && $t >= $e['s'] - 1200 && $t <= $e['e'] + 1800) return $e; return null; };
    $ev = []; $pend = [];
    foreach (['CALL' => 1, 'PUT' => -1] as $sig => $sg) {
        if (!array_filter($eps, fn($e) => $e['sig'] === $sig)) continue;
        $X = t_fly_events($F, $a, $b, $sg, $M1, $zb, $now);
        foreach ($X['events'] as $e) $ev[] = $e + ['sig' => $sig, 'sg' => $sg];
        if ($X['pending']) $pend[] = $X['pending'] + ['sig' => $sig];
    }
    usort($ev, fn($x, $y) => $x['time'] <=> $y['time']);
    $busyT = 0;
    foreach ($ev as $E) {
        if ($E['pattern_time'] <= $busyT) continue;   // one trade at a time per symbol
        $sig = $E['sig']; $sg = $E['sg']; $ep = $epFor($sig, $E['pattern_time']); if (!$ep) continue;
        $isTrend = ($ep['setup'] ?? null) === 'trend';
        // owner, 19 Oct 2026: retracement moved onto the v10 ticks too, so BOTH setups are now replayed by
        // t10_replay below and this older event-based path no longer produces trades.
        if (T10_ON) continue;
        if (false && TSW_CFG['gate_retrace_enabled'] && ($ep['setup'] ?? null) === 'retrace' && empty($E['sweep'])) continue;   // owner, 13 Oct 2026: sweep no longer mandatory for retrace   // v9 (owner, 6 Oct): retracement FLY needs a sweep
        $te = (int)$E['time']; $pe = (float)$E['price']; $a5 = (float)$E['atr']; $sl0 = (float)$E['sl']; $risk = abs($pe - $sl0);
        // Targets (owner, 5 Oct 2026 15:18 IST): TP1 = the pivot point (PP) unless it sits in the same range as entry
        // (closer than 1R or inside the zone band 0.4 x ATR5); then TP1 = the nearest major 1W Fibonacci level beyond that.
        // Retracement / short target: TP1 only. Trend / long target: TP2 and TP3 = the next major 1W Fibonacci levels beyond TP1.
        $tg = null; $tl = null; $minD = max($risk, T2_ZB * $a5); $WF = t2_wfib();
        if (isset($P['PP']) && ($P['PP'] - $pe) * $sg >= $minD) { $tg = ($P['PP'] - $pe) * $sg; $tl = 'PP'; }
        else foreach ($WF as $lb => $pv) { $dd = ($pv - $pe) * $sg; if ($dd >= $minD && ($tg === null || $dd < $tg)) { $tg = $dd; $tl = $lb; } }
        if ($tg === null) foreach ($P as $lb => $pv) { $dd = ($pv - $pe) * $sg; if ($dd >= $minD && ($tg === null || $dd < $tg)) { $tg = $dd; $tl = $lb; } }
        if ($tg === null) { $tg = $risk; $tl = '1R'; }
        $more = [];
        if ($isTrend) { $lv = []; $gap = max(0.25 * $risk, 0.2 * $a5);
            foreach ($WF as $lb => $pv) { $dd = ($pv - $pe) * $sg; if ($dd > $tg + $gap) $lv[$lb] = $dd; }
            if (count($lv) < 2) foreach ($P as $lb => $pv) { $dd = ($pv - $pe) * $sg; if ($dd > $tg + $gap) $lv[$lb] = $dd; }
            asort($lv); $last = $tg;
            foreach ($lv as $lb => $dd) { if (count($more) >= 2) break; if ($dd - $last < $gap) continue; $more[] = ['label' => $lb, 'price' => t_r($pe + $sg * $dd)]; $last = $dd; } }
        // outcome (unchanged rules): stop-loss, else after TP1 a 5m close on the wrong side of the 5m 5-EMA, else session end
        $pq = $first($M1['t'], $te);
        // owner, 13 Oct 2026 ("short settings"): retrace exit = 8m pattern reversal (next 8m candle confirm) or 8m Supertrend
        // reversal against the trade, replacing the old "touched + 5-EMA reversal" rule. TP1 touch is still tracked for reporting.
        $sl = $sl0; $touched = false; $tgT = null; $ex = null; $exT = null; $why = null;
        $x8rt = t_rt_exit_8m($F, $te, $sg, $now);
        for ($x = $pq; $x < $n1 && $M1['t'][$x] < $endTs; $x++) {
            $l = (float)$M1['l'][$x]; $h = (float)$M1['h'][$x]; $tx = (int)$M1['t'][$x] + 60;
            if ($x8rt && $tx > $x8rt[0]) { $ex = $x8rt[1]; $exT = $x8rt[0]; $why = $x8rt[2]; break; }
            if ($sg > 0 ? $l <= $sl : $h >= $sl) { $ex = $sl; $exT = $tx; $why = 'Stop-loss'; break; }
            if (!$touched && ($sg > 0 ? $h - $pe : $pe - $l) >= $tg) { $touched = true; $tgT = $tx; }
        }
        if ($ex === null && $x8rt && $x8rt[0] <= $now) { $ex = $x8rt[1]; $exT = $x8rt[0]; $why = $x8rt[2]; }
        if ($ex === null && !$running) { $ex = (float)$F['c'][$b]; $exT = $endTs; $why = 'Session end'; }
        $px = $ex ?? (float)$M1['c'][$n1 - 1];
        // research: how far price went (to the exit and to the session end), and what happened after a stop-loss
        $mfe = 0.0; $mae = 0.0; $run = 0.0; $runT = $te; $aft = null; $aftTg = false;
        for ($x = $pq; $x < $n1 && $M1['t'][$x] < $endTs; $x++) {
            $tx = (int)$M1['t'][$x] + 60; $fv = $sg > 0 ? (float)$M1['h'][$x] - $pe : $pe - (float)$M1['l'][$x]; $av = $sg > 0 ? $pe - (float)$M1['l'][$x] : (float)$M1['h'][$x] - $pe;
            if ($exT === null || $tx <= $exT) { $mfe = max($mfe, $fv); $mae = max($mae, $av); }
            if ($fv > $run) { $run = $fv; $runT = $tx; }
            if ($why === 'Stop-loss' && $tx > $exT) { $aft = max($aft ?? 0.0, $fv); if ($fv >= $tg) $aftTg = true; }
        }
        $crossed = []; foreach ($P as $lb => $pv) { $dd = ($pv - $pe) * $sg; if ($dd > 0 && $dd <= $run) $crossed[$lb] = $dd; } asort($crossed);
        $c5 = null; for ($q = max($a + 1, (int)$E['k'] - 3); $q <= $b; $q++) { $u = $F['c'][$q] > $E5[$q]; $w = $F['c'][$q - 1] > $E5[$q - 1]; if ($sg > 0 ? ($u && !$w) : (!$u && $w)) { $c5 = [(int)$F['t'][$q] + 300, t_r((float)$F['c'][$q])]; break; } }
        $R = fn($v) => $risk > 1e-12 ? round($v / $risk, 2) : null; $P2 = fn($v) => round($v / $pe * 100, 4); $pb = $E['bar'];
        $research = ['signal_time' => $ep['s'] ?? null, 'signal_price' => $ep['p'] ?? null, 'alignment' => $ep['al'] ?? null, 'setup' => $ep['setup'] ?? null, 'remark' => $ep['remark'] ?? null,
            'pattern_bar' => ['t' => $pb['t'], 'o' => t_r($pb['o']), 'h' => t_r($pb['h']), 'l' => t_r($pb['l']), 'c' => t_r($pb['c'])],
            'confirm_time' => $te, 'confirm_close' => t_r($pe), 'ema5_cross' => $c5, 'day_ext' => t_r((float)$E['day_ext']), 'atr5' => t_r($a5),
            'grade' => $E['grade'], 'runway_type' => $E['runway_type'], 'runway_time' => $E['runway_time'], 'rules' => 8,
            'target_time' => $tgT, 'mfe_pct' => $P2($mfe), 'mfe_r' => $R($mfe), 'mae_pct' => $P2($mae), 'mae_r' => $R($mae),
            'run_pct' => $P2($run), 'run_r' => $R($run), 'run_time' => $runT, 'pivots_crossed' => array_keys($crossed),
            'after_sl_r' => $aft === null ? null : $R($aft), 'target_after_sl' => $why === 'Stop-loss' ? $aftTg : null];
        $out['trades'][] = $research + ['sig' => $sig, 'pattern' => $E['pattern'], 'pattern_time' => $E['pattern_time'], 'entry_time' => $te, 'entry' => t_r($pe), 'sl0' => t_r($sl0), 'sl' => t_r($sl),
            'target' => t_r($pe + $sg * $tg), 'target_label' => $tl, 'targets_more' => $more, 'touched' => $touched, 'status' => $ex === null ? 'open' : 'closed',
            'exit_time' => $exT, 'exit' => $ex === null ? null : t_r($ex), 'reason' => $why, 'last' => t_r($px),
            'pct' => round(($px - $pe) * $sg / $pe * 100, 4), 'r' => $risk > 1e-12 ? round(($px - $pe) * $sg / $risk, 2) : null];
        if ($ex === null) { $busyT = PHP_INT_MAX; break; }
        $busyT = $exT;
    }
    if (T10_ON) {   // v10 trades, both setups: entry at the first tick, SL 1.5%, exit = SL / 8m flip / 8m 5-EMA (retracement also Fib TP1)
        foreach (t10_replay($F, $M1, $a, $b, $epFor, $P, $now, $running) as $tt) $out['trades'][] = $tt;
        usort($out['trades'], fn($x, $y) => $x['entry_time'] <=> $y['entry_time']);
    }
    if ($running && $busyT !== PHP_INT_MAX) foreach ($pend as $p)   // a pattern on the last closed 10m bar, waiting for its confirmation
        if ($p['time'] > $busyT && $epFor($p['sig'], $p['time'])) { $out['pending'] = ['stage' => 'pattern', 'sig' => $p['sig'], 'pattern' => $p['pattern'], 'time' => $p['time']]; break; }
    return $out;
}

/* ---------------- Recent trades (data/t2trades.json) ---------------- */
function t2_pip(string $sym, float $px): ?float {
    if (t_symbols()[$sym] !== 'Forex' && !str_ends_with($sym, '=X')) return null;
    return (str_contains($sym, 'JPY') || $px > 20) ? 0.01 : 0.0001;
}
function t2_trades_merge(string $sym, array $trades, ?array $t1): void {
    if (!$trades) return;
    $p = T_DATA . '/t2trades.json'; $S = t_read_json($p, ['items' => []]); $it = []; foreach ($S['items'] ?? [] as $x) $it[$x['id']] = $x;
    $ch = false; $mkt = t_symbols()[$sym] ?? '';
    foreach ($trades as $t) {
        $id = substr(md5($sym . '|' . $t['sig'] . '|' . $t['entry_time']), 0, 12);
        $old = $it[$id] ?? null; if ($old && $old['status'] === 'closed' && array_key_exists('run_r', $old)) continue;   // closed trades are final (older rows get the research fields once)
        $pip = t2_pip($sym, (float)$t['entry']); $mv = ((float)$t['last'] - (float)$t['entry']) * ($t['sig'] === 'CALL' ? 1 : -1);
        $row = ['id' => $id, 'symbol' => $sym, 'name' => t_name($sym), 'market' => $mkt, 'setup' => $t['setup'] ?? $old['setup'] ?? ($t1['setup'] ?? null), 'remark' => $t['remark'] ?? $old['remark'] ?? ($t1['remark'] ?? null),
            'pips' => $pip ? round($mv / $pip, 1) : null, 'points' => t_r($mv)] + $t;
        if ($old != $row) { $it[$id] = $row; $ch = true; }
    }
    if (!$ch) return;
    $cut = t_now() - 45 * 86400; $it = array_values(array_filter($it, fn($x) => (int)$x['entry_time'] >= $cut));
    usort($it, fn($x, $y) => $x['entry_time'] <=> $y['entry_time']); if (count($it) > 800) $it = array_slice($it, -800);
    t_write_json($p, ['items' => $it]);
}
/** Closes stored trades that a finished session's replay no longer produces (owner, 30 Sep 2026).
    A trade opened while the session was live can drop out of the final replay when the data provider revises bars; it then stayed
    "open" for good. Here it is played out on its own entry, stop and target with the same rules: stop-loss, 5-EMA exit after the
    target was touched, else session end. Marked 'orphan' => true for the research report. $made = ids the replay produced. */
function t2_settle_orphans(string $sym, array $F, ?array $M1, int $a, int $b, array $made): int {
    if (!$M1 || !$F['t']) return 0;
    $dayTs = (int)$F['t'][$a]; $endTs = (int)$F['t'][$b] + 300;
    $p = T_DATA . '/t2trades.json'; $S = t_read_json($p, ['items' => []]); $n = 0; $E5 = null;
    $first = function (array $t, int $x): int { $lo = 0; $hi = count($t) - 1; $r = count($t); while ($lo <= $hi) { $m = intdiv($lo + $hi, 2); if ($t[$m] >= $x) { $r = $m; $hi = $m - 1; } else $lo = $m + 1; } return $r; };
    foreach ($S['items'] as &$x) {
        if (($x['symbol'] ?? '') !== $sym || ($x['status'] ?? '') !== 'open' || isset($made[$x['id']])) continue;
        $te = (int)$x['entry_time']; if ($te < $dayTs || $te >= $endTs) continue;
        $E5 ??= t_ema($F['c'], 5);
        $sg = ($x['sig'] ?? '') === 'CALL' ? 1 : -1; $pe = (float)$x['entry']; $sl = (float)($x['sl0'] ?? $x['sl']); $tg = ((float)$x['target'] - $pe) * $sg; $risk = abs($pe - $sl);
        $touched = false; $ex = null; $exT = null; $why = null; $k5p = $first($F['t'], $te - 299); $n1 = count($M1['t']);
        for ($i = $first($M1['t'], $te); $i < $n1 && $M1['t'][$i] < $endTs; $i++) {
            $l = (float)$M1['l'][$i]; $h = (float)$M1['h'][$i]; $tx = (int)$M1['t'][$i] + 60;
            if ($sg > 0 ? $l <= $sl : $h >= $sl) { $ex = $sl; $exT = $tx; $why = 'Stop-loss'; break; }
            if (!$touched && ($sg > 0 ? $h - $pe : $pe - $l) >= $tg) $touched = true;
            while ($k5p <= $b && (int)$F['t'][$k5p] + 300 <= $tx) {
                if ((int)($x['rules'] ?? 0) < 10 && $touched && ($sg > 0 ? $F['c'][$k5p] < $E5[$k5p] : $F['c'][$k5p] > $E5[$k5p])) { $ex = (float)$F['c'][$k5p]; $exT = (int)$F['t'][$k5p] + 300; $why = '5-EMA reversal'; break 2; }
                $k5p++;
            }
        }
        if ($ex === null) { $ex = (float)$F['c'][$b]; $exT = $endTs; $why = 'Session end'; }
        $mv = ($ex - $pe) * $sg; $pip = t2_pip($sym, $pe);
        $x = array_merge($x, ['status' => 'closed', 'exit' => t_r($ex), 'exit_time' => $exT, 'reason' => $why, 'last' => t_r($ex), 'touched' => $touched || !empty($x['touched']),
            'pct' => round($mv / $pe * 100, 4), 'r' => $risk > 1e-12 ? round($mv / $risk, 2) : null, 'points' => t_r($mv), 'pips' => $pip ? round($mv / $pip, 1) : null, 'orphan' => true]);
        $n++;
    }
    unset($x);
    if ($n) t_write_json($p, $S);
    return $n;
}
/** Symbols with trades still open, and the oldest open entry time of each (owner, 30 Sep 2026). */
function t2_open_syms(int $maxAge): array {
    $now = t_now(); $o = [];
    foreach (t_read_json(T_DATA . '/t2trades.json', ['items' => []])['items'] ?? [] as $x) {
        if (($x['status'] ?? '') !== 'open' || $now - (int)$x['entry_time'] > $maxAge || str_starts_with((string)$x['symbol'], 'MCX:') || !isset(t_symbols()[$x['symbol']])) continue;
        $o[$x['symbol']] = min($o[$x['symbol']] ?? PHP_INT_MAX, (int)$x['entry_time']);
    }
    return $o;
}
/** Replay the symbols with recent Tier-1 signals (a few per call, oldest first) so the list fills without anyone opening Tier 2.
    Symbols with a trade still open are replayed too, even after their signal ended, until the trade closes (stop, exit or session end). */
function t2_trades_refresh(int $budget = 3): void {
    $now = t_now(); $L = &t_siglog(); $st = t_read_json(T_DATA . '/t2state.json', []); $want = [];
    foreach ($L['items'] as $e) {
        $s = $e['symbol']; if (str_starts_with($s, 'MCX:') || !isset(t_symbols()[$s])) continue;
        $end = !empty($e['end']) ? (int)$e['end'] : $now;
        if ($now - $end > 8 * 3600) continue;                        // finished long ago: already replayed
        $at = (int)($st[$s] ?? 0); if ($now - $at < 90) continue;
        $want[$s] = min($want[$s] ?? PHP_INT_MAX, $at);
    }
    $open = t2_open_syms(4 * 86400);
    foreach ($open as $s => $e0) { $at = (int)($st[$s] ?? 0); if ($now - $at >= 300) $want[$s] = min($want[$s] ?? PHP_INT_MAX, $at); }
    if (!$want) return;
    asort($want); $pick = array_slice(array_keys($want), 0, $budget); $urls = [];
    foreach ($pick as $s) { $urls["$s|5"] = t_url($s, '5d', '5m'); $urls["$s|1"] = t_url($s, '5d', '1m'); $st[$s] = $now; }
    t_write_json(T_DATA . '/t2state.json', array_filter($st, fn($v) => $now - $v < 86400 * 2));
    $B = t_fetch($urls);
    foreach ($pick as $s) {
        $md = t2_data_parse($B["$s|5"] ?? null, $B["$s|1"] ?? null); if (!$md) continue;
        t_m1_put($s, $md['f1']);
        $f5 = $md['f5']; $ses = t2_sessions($f5, $md['tz']); $nS = count($ses); $o0 = $open[$s] ?? PHP_INT_MAX;
        for ($k = 1; $k < $nS; $k++) {
            if ($k < $nS - 2 && (int)$f5['t'][$ses[$k][1]] + 300 < $o0 - 3600) continue;   // older days only if a trade from them is still open
            [$a, $b] = $ses[$k]; $dayTs = (int)$f5['t'][$a];
            $eps = array_values(array_filter(t2_episodes($s, $dayTs, $now, null), fn($x) => $x['s'] < (int)$f5['t'][$b] + 300 + 1800));
            if (!$eps) { if (!($k === $nS - 1 && $now - (int)$f5['t'][$b] < 5400)) t2_settle_orphans($s, $f5, $md['f1'], $a, $b, []); continue; }
            $run = $k === $nS - 1 && $now - (int)$f5['t'][$b] < 5400;
            t2_wfib(t2_wfib_calc($s));
            $R = t2_replay_session($f5, $md['f1'], $a, $b, $eps, t_fib_pivots($f5, $dayTs), $now, $run);
            $row = t_scan_load()['rows'][$s] ?? null;
            t2_trades_merge($s, $R['trades'], $row);
            if (!$run) t2_settle_orphans($s, $f5, $md['f1'], $a, $b, array_flip(array_map(fn($t) => substr(md5($s . '|' . $t['sig'] . '|' . $t['entry_time']), 0, 12), $R['trades'])));
        }
    }
}
/** Recent trades for the pages: newest first, with the running record. */
function t2_trades_view(int $max = 120): array {
    $it = t_read_json(T_DATA . '/t2trades.json', ['items' => []])['items'] ?? [];
    usort($it, fn($x, $y) => $y['entry_time'] <=> $x['entry_time']);
    $closed = array_values(array_filter($it, fn($x) => $x['status'] === 'closed' && $x['r'] !== null));
    $w = array_filter($closed, fn($x) => $x['r'] > 0); $l = array_filter($closed, fn($x) => $x['r'] <= 0);
    $gw = array_sum(array_column($w, 'r')); $gl = -array_sum(array_column($l, 'r'));
    $week = array_filter($closed, fn($x) => t_now() - (int)$x['entry_time'] < 7 * 86400); usort($week, fn($x, $y) => $y['r'] <=> $x['r']);
    return ['items' => array_slice($it, 0, $max), 'top' => array_slice(array_values(array_filter($week, fn($x) => $x['r'] > 0)), 0, 5),
        'stats' => ['closed' => count($closed), 'open' => count($it) - count($closed), 'wins' => count($w), 'win_rate' => $closed ? round(count($w) / count($closed) * 100, 1) : null,
            'pf' => $gl > 0 ? round($gw / $gl, 2) : null, 'total_r' => round($gw - $gl, 2), 'avg_r' => $closed ? round(($gw - $gl) / count($closed), 2) : null,
            'since' => $it ? (int)min(array_column($it, 'entry_time')) : null]];
}

/** Build the Tier-2 payload. Returns ['error' => ...] when data is missing. */
function t2_build(string $sym, string $bias): array {
    if (!isset(t_symbols()[$sym])) return ['error' => 'Unknown symbol'];
    $now = t_now();
    $md = t2_data($sym);
    if (!$md) return ['error' => 'No intraday data available for this symbol right now.'];
    ['f5' => $f5, 'f1' => $f1, 'tz' => $tz] = $md; $f5 = t2_closed($f5, 0, $now); if ($f1) $f1 = t2_closed($f1, 0, $now);
    if (count($f5['t']) < 20) return ['error' => 'No intraday data available for this symbol right now.'];
    $ses = t2_sessions($f5, $tz);
    if (count($ses) < 2) return ['error' => 'Not enough sessions of intraday data yet.'];
    [$a, $b] = $ses[count($ses) - 1];

    // Tier-1 context (bias, ATR day zone) from the engine's own frames
    $row = t_scan_load()['rows'][$sym] ?? null; $t1 = null;
    $fr = t_load_frames($sym);
    if ($fr) { $fr['5m'] = $f5; $fr['1m'] = $f1; $t1 = t_apply_status($sym, t_evaluate($sym, $fr), $row, $now); }
    $t1sig = ($t1 && !empty($t1['live']) && in_array($t1['signal'] ?? '', ['CALL', 'PUT'], true)) ? $t1['signal'] : null;
    if ($t1sig) $bias = $t1sig;   // Tier 2 always follows the live Tier-1 signal
    elseif ($bias !== 'CALL' && $bias !== 'PUT') $bias = ($t1['zone_near'] ?? '') === 'ATR High' ? 'PUT' : (($t1['bias'] ?? 'UP') === 'DOWN' ? 'PUT' : 'CALL');
    $PV = t_fib_pivots($f5, (int)$f5['t'][$a]);
    // owner, 4 Nov 2026: Previous Day Close, the one major zone PDH/PDL/Fib-Pivot didn't already cover -- for
    // drawing every major key zone on the Tier-2 chart (same set the score's location bonus already uses).
    // Owner, 4 Nov 2026 fix: reading this off $fr (the Tier-1 frame cache) made it null whenever that cache
    // was momentarily stale for this symbol (same reason tier1 itself comes back null sometimes) -- PDH/PDL
    // never had this problem because they're read straight off Tier-2's own $f5/$ses, so PDC now is too:
    // the previous session's last close.
    $pdc = count($ses) >= 2 ? (float)$f5['c'][$ses[count($ses) - 2][1]] : null;
    $atrH = isset($t1['atr_high']) ? (float)$t1['atr_high'] : null; $atrL = isset($t1['atr_low']) ? (float)$t1['atr_low'] : null;
    $off = (int)$f5['t'][$a] % 600; $g10 = t_group($f5, fn($t) => intdiv($t - $off, 600)); $a5 = t_atr($f5, 14);

    // setup: with the Tier-1 trend (long target) or a retracement against it (small target)
    $tb = $t1['bias'] ?? 'NEUTRAL';
    $setup = in_array($tb, ['UP', 'DOWN'], true) && (($bias === 'CALL') === ($tb === 'UP')) ? 'trend' : 'retrace';
    $zb = T2_ZB; $tolNow = $zb * (float)t2_last($a5); $dayL = min(array_slice($f5['l'], $a, $b - $a + 1)); $dayH = max(array_slice($f5['h'], $a, $b - $a + 1));
    $px = (float)end($f5['c']);

    // trades of today's session, replayed with the live rules around this symbol's Tier-1 signals
    $eps = t2_episodes($sym, (int)$f5['t'][$a], $now, $t1sig);
    t2_wfib(t2_wfib_calc($sym));
    $R = t2_replay_session($f5, $f1, $a, $b, $eps, $PV, $now, $b >= count($f5['t']) - 1 && $now - (int)$f5['t'][$b] < 5400);
    t2_trades_merge($sym, $R['trades'], $t1);
    $last = $R['trades'] ? $R['trades'][count($R['trades']) - 1] : null;
    $open = $last && $last['status'] === 'open' ? $last : null;
    $status = $open ? 'ACTION_TRIGGERED' : ($R['pending'] ? 'PATTERN' : 'WAITING_FOR_TRIGGER');
    if ($open) $bias = $open['sig'];
    $act = $open['entry'] ?? null; $actT = $open['entry_time'] ?? null; $sl = $open['sl'] ?? null; $tgt = $open['target'] ?? null; $rr = null; $targets = [];
    if ($open) {
        $risk = abs($open['entry'] - $open['sl0']);
        $targets[] = ['label' => 'T1', 'price' => $open['target'], 'fib' => $open['target_label'], 'rr' => $risk > 1e-12 ? round(abs($open['target'] - $open['entry']) / $risk, 2) : null];
        $rr = $targets[0]['rr'];
        foreach (($open['targets_more'] ?? []) as $q => $x)
            $targets[] = ['label' => 'T' . ($q + 2), 'price' => $x['price'], 'fib' => $x['label'], 'rr' => $risk > 1e-12 ? round(abs($x['price'] - $open['entry']) / $risk, 2) : null];
    }
    // --- pb5: Pullback+5EMA combined tick (only for trend/retrace setup, not mid-trend) ---
    // Detects: structure shift on 5m (break of prev small high/low) → pullback to 5EMA → rejection candle
    $pb5 = 0;
    if ($setup !== 'mid') {
        $n5 = count($f5['t']); $atrNow = (float)t2_last($a5);
        $ema5 = $atrNow > 0 ? t_ema(['c' => $f5['c']], 5) : [];
        // Look at last 20 5m bars of today's session only
        $sStart = max($a, $n5 - 20); $sEnd = $n5 - 2; // -2: skip the live (unconfirmed) bar
        if ($sEnd - $sStart >= 5 && count($ema5) >= $n5) {
            if ($bias === 'CALL') {
                // Bullish: find structure shift (bar broke prev high), then pullback to EMA, then rejection
                $shiftIdx = null;
                for ($i = $sStart + 1; $i <= $sEnd - 2; $i++) {
                    if ((float)$f5['h'][$i] > (float)$f5['h'][$i - 1]) { $shiftIdx = $i; } // took out prev high = bullish shift
                }
                if ($shiftIdx !== null) {
                    // After shift: look for a candle that pulled back to 5 EMA (low <= ema5, close >= ema5 * 0.9985)
                    $pullIdx = null;
                    for ($i = $shiftIdx + 1; $i <= $sEnd - 1; $i++) {
                        $e = (float)$ema5[$i]; if ($e <= 0) continue;
                        if ((float)$f5['l'][$i] <= $e * 1.0003 && (float)$f5['c'][$i] >= $e * 0.9985) { $pullIdx = $i; }
                    }
                    if ($pullIdx !== null) {
                        // Last candle after pullback: bullish rejection = lower wick >= body, close near high, close > open
                        $ri = $sEnd; $ro = (float)$f5['o'][$ri]; $rh = (float)$f5['h'][$ri]; $rl = (float)$f5['l'][$ri]; $rc = (float)$f5['c'][$ri];
                        $body = abs($rc - $ro); $lwick = min($ro, $rc) - $rl; $uwick = $rh - max($ro, $rc);
                        if ($rc > $ro && $lwick >= $body * 0.6 && $uwick <= $body * 1.5 && $rc >= $rh - ($rh - $rl) * 0.35) $pb5 = 1;
                    }
                }
            } else { // PUT
                // Bearish: find structure shift (bar broke prev low), then pullback to EMA, then rejection
                $shiftIdx = null;
                for ($i = $sStart + 1; $i <= $sEnd - 2; $i++) {
                    if ((float)$f5['l'][$i] < (float)$f5['l'][$i - 1]) { $shiftIdx = $i; }
                }
                if ($shiftIdx !== null) {
                    $pullIdx = null;
                    for ($i = $shiftIdx + 1; $i <= $sEnd - 1; $i++) {
                        $e = (float)$ema5[$i]; if ($e <= 0) continue;
                        if ((float)$f5['h'][$i] >= $e * 0.9997 && (float)$f5['c'][$i] <= $e * 1.0015) { $pullIdx = $i; }
                    }
                    if ($pullIdx !== null) {
                        $ri = $sEnd; $ro = (float)$f5['o'][$ri]; $rh = (float)$f5['h'][$ri]; $rl = (float)$f5['l'][$ri]; $rc = (float)$f5['c'][$ri];
                        $body = abs($rc - $ro); $uwick = $rh - max($ro, $rc); $lwick = min($ro, $rc) - $rl;
                        if ($rc < $ro && $uwick >= $body * 0.6 && $lwick <= $body * 1.5 && $rc <= $rl + ($rh - $rl) * 0.35) $pb5 = 1;
                    }
                }
            }
        }
    }
    // --- end pb5 ---

    // --- ema5tick: plain 5-minute 5 EMA tick (separate from the pb5 compound pullback+rejection check) ---
    // Fires when the latest confirmed 5m candle's close is beyond its own 5 EMA in the bias direction.
    $ema5tick = 0;
    {
        $e5n = count($f5['t']);
        $e5i = $e5n - 2; // last confirmed closed bar (skip the still-forming live one, same convention as st5)
        if ($e5i >= 5) {
            $e5arr = t_ema(array_map('floatval', array_slice($f5['c'], 0, $e5i + 1)), 5);
            $ev = end($e5arr);
            if ($ev !== false && $ev !== null) {
                $c = (float)$f5['c'][$e5i];
                if ($bias === 'CALL' && $c > $ev) $ema5tick = 1;
                if ($bias === 'PUT' && $c < $ev) $ema5tick = 1;
            }
        }
    }
    // --- end ema5tick ---

    // --- st5: 5-minute Supertrend tick (period=7, multiplier=2.0) ---
    // Fires when the latest confirmed 5m bar's Supertrend direction matches the bias:
    //   CALL → ST bullish (close > ST upper band → price above ST line)
    //   PUT  → ST bearish (close < ST lower band → price below ST line)
    $st5 = 0;
    {
        $stN = count($f5['t']);
        $stPeriod = 7; $stMult = 2.0;
        // Need at least period+2 bars (skip live unconfirmed bar at end)
        $stEnd = $stN - 2; // last confirmed bar index
        if ($stEnd >= $stPeriod) {
            // Compute ATR(7) using Wilder's smoothing on True Range
            // True Range = max(H-L, |H-prevC|, |L-prevC|)
            $tr = [];
            for ($i = 1; $i <= $stEnd; $i++) {
                $h = (float)$f5['h'][$i]; $l = (float)$f5['l'][$i]; $pc = (float)$f5['c'][$i - 1];
                $tr[] = max($h - $l, abs($h - $pc), abs($l - $pc));
            }
            // Wilder smooth: first ATR = simple avg of first $stPeriod TRs
            $atr = array_sum(array_slice($tr, 0, $stPeriod)) / $stPeriod;
            $atrArr = [$atr];
            for ($i = $stPeriod; $i < count($tr); $i++) {
                $atr = ($atr * ($stPeriod - 1) + $tr[$i]) / $stPeriod;
                $atrArr[] = $atr;
            }
            // $atrArr[k] corresponds to candle index k+1 (since TR starts at index 1)
            // Supertrend: upper = (H+L)/2 + mult*ATR, lower = (H+L)/2 - mult*ATR
            // Direction tracks using previous direction:
            $stDir = 1; // 1=bullish, -1=bearish
            $stLine = 0.0;
            $prevUpper = 0.0; $prevLower = 0.0;
            for ($i = 0; $i < count($atrArr); $i++) {
                $ci = $i + 1; // candle index in f5 arrays
                $hl2 = ((float)$f5['h'][$ci] + (float)$f5['l'][$ci]) / 2;
                $upper = $hl2 + $stMult * $atrArr[$i];
                $lower = $hl2 - $stMult * $atrArr[$i];
                // Adjust bands: lower never decreases in bullish run, upper never increases in bearish run
                if ($i > 0) {
                    if ($prevLower > 0 && $lower < $prevLower && (float)$f5['c'][$ci - 1] > $prevLower) $lower = $prevLower;
                    if ($prevUpper > 0 && $upper > $prevUpper && (float)$f5['c'][$ci - 1] < $prevUpper) $upper = $prevUpper;
                }
                $c = (float)$f5['c'][$ci];
                if ($stDir === 1) {
                    $stLine = $lower;
                    if ($c < $lower) { $stDir = -1; $stLine = $upper; }
                } else {
                    $stLine = $upper;
                    if ($c > $upper) { $stDir = 1; $stLine = $lower; }
                }
                $prevUpper = $upper; $prevLower = $lower;
            }
            // $stDir now reflects the confirmed bar at $stEnd
            if ($bias === 'CALL' && $stDir === 1) $st5 = 1;
            if ($bias === 'PUT'  && $stDir === -1) $st5 = 1;
        }
    }
    // --- end st5 ---

    // 10-minute chart
    $f10 = $g10; $n10 = count($f10['t']); $s0 = max(0, $n10 - 150);
    $candles = [];
    for ($i = $s0; $i < $n10; $i++) {
        $candles[] = ['time' => $f10['t'][$i], 'open' => $f10['o'][$i], 'high' => $f10['h'][$i], 'low' => $f10['l'][$i], 'close' => $f10['c'][$i]];
    }

    // v7: other chart timeframes for the full-screen chart (3m from 1-minute bars; 5m, 15m and 1H from 5-minute bars)
    $pack = function (array $f, int $L) { $n = count($f['t']); $o = [];
        for ($i = max(0, $n - $L); $i < $n; $i++) $o[] = ['time' => (int)$f['t'][$i], 'open' => t_r((float)$f['o'][$i]), 'high' => t_r((float)$f['h'][$i]), 'low' => t_r((float)$f['l'][$i]), 'close' => t_r((float)$f['c'][$i])];
        return $o; };
    $cset = ['5' => $pack($f5, 240), '15' => $pack(t_resample($f5, 900), 160), '60' => $pack(t_resample($f5, 3600), 120)];
    if ($f1) $cset['3'] = $pack(t_resample($f1, 180), 240);
    // confluence engine on 10-minute bars
    $c = $f10['c']; $hasVol = array_sum(array_slice($f10['v'], -40)) > 0;
    $rsi = t_rsi_last($c); $mh = t2_macd_hist($c); $pb = t2_pctb($c); $atrp = (float)t2_last(t_atr($f10)) / $px * 100;
    $vr = null; if ($hasVol) { $avg = t2_sma_last($f10['v'], 20); $vr = $avg > 0 ? end($f10['v']) / $avg : null; }
    $st = t2_stoch($f10); $wr = t2_willr($f10); $cci = t2_cci($f10); $mfi = $hasVol ? t2_mfi($f10) : null; $obv = $hasVol ? t2_obv_trend($f10) : null;
    $vw = null;
    if ($hasVol) { $tv = 0.0; $vv = 0.0; foreach ($f10['t'] as $i => $t) { if ($t < $f5['t'][$a] - 599) continue; $tp = ($f10['h'][$i] + $f10['l'][$i] + $f10['c'][$i]) / 3; $tv += $tp * $f10['v'][$i]; $vv += $f10['v'][$i]; } if ($vv > 0) $vw = ($px - $tv / $vv) / ($tv / $vv) * 100; }
    [$adx] = t_adx($f10);
    $tag = fn(?bool $bull, ?bool $bear) => $bull ? 'bullish' : ($bear ? 'bearish' : 'neutral');
    $conf = [
        ['RSI (14)', $rsi, '%.1f', fn($v) => $tag($v > 55, $v < 45), 'Momentum'],
        ['MACD histogram', $mh, '%+.4g', fn($v) => $tag($v > 0, $v < 0), 'Momentum'],
        ['Bollinger %B', $pb, '%.2f', fn($v) => $tag($v > 0.6, $v < 0.4), 'Volatility'],
        ['ATR volatility', $atrp, '%.2f%%', fn($v) => 'neutral', 'Volatility'],
        ['Volume surge', $vr, '%.2fx', fn($v) => $tag($v > 1.2, $v < 0.8), 'Volume'],
        ['Stochastic %K', $st, '%.1f', fn($v) => $tag($v > 55, $v < 45), 'Momentum'],
        ['Williams %R', $wr, '%.1f', fn($v) => $tag($v > -45, $v < -55), 'Momentum'],
        ['CCI (20)', $cci, '%.1f', fn($v) => $tag($v > 50, $v < -50), 'Momentum'],
        ['Money Flow Index', $mfi, '%.1f', fn($v) => $tag($v > 55, $v < 45), 'Volume'],
        ['OBV trend', $obv, '%s', fn($v) => $tag($v === 'UP', $v === 'DOWN'), 'Volume'],
        ['VWAP distance', $vw, '%+.2f%%', fn($v) => $tag($v > 0, $v < 0), 'Trend'],
        ['ADX trend strength', $adx, '%.1f', fn($v) => $v < 20 ? 'neutral' : ($bias === 'CALL' ? 'bullish' : 'bearish'), 'Trend'],
    ];
    // indicators without data (no volume on indices / forex) are left out, never shown as n/a
    $conf = array_values(array_map(fn($x) => ['name' => $x[0], 'value' => sprintf($x[2], $x[1]), 'tag' => $x[3]($x[1]), 'group' => $x[4]],
        array_filter($conf, fn($x) => $x[1] !== null && (!is_float($x[1]) || is_finite($x[1])))));

    // news for this symbol from the News & Impact cache (no network call here)
    $news = [];
    if (is_file(__DIR__ . '/torus_news.php')) {
        $nc = t_read_json(T_DATA . '/news.json', ['items' => []]);
        foreach (($nc['items'] ?? []) as $x) {
            foreach (($x['tags'] ?? []) as $tg) if (($tg['symbol'] ?? '') === $sym) { $news[] = ['title' => $x['t'], 'publisher' => $x['src'], 'link' => $x['u'], 'ts' => $x['ts'], 'imp' => $x['imp'], 'sen' => $x['sen']]; break; }
            if (count($news) >= 6) break;
        }
    }
    $r6 = fn($v) => $v === null ? null : t_r((float)$v);
    return [
        'symbol' => $sym, 'name' => t_name($sym), 'market' => t_symbols()[$sym], 'bias' => $bias, 'status' => $status,
        'current_price' => $r6($px), 'atr_high' => $r6($atrH), 'atr_low' => $r6($atrL),
        'action_price' => $r6($act), 'action_time' => $actT, 'target' => $r6($tgt), 'sl' => $r6($sl), 'rr_ratio' => $rr, 'last_bar' => (int)end($f5['t']),
        'setup' => $setup, 'remark' => $setup === 'trend' ? 'Long target' : 'Small target',
        'targets' => array_map(fn($x) => ['label' => $x['label'], 'price' => $r6($x['price']), 'fib' => $x['fib'], 'rr' => $x['rr']], $targets),
        'zone' => ['from' => (int)$f5['t'][$a], 'day_low' => $r6($dayL), 'day_high' => $r6($dayH), 'pdc' => $r6($pdc),
            'low_band' => [$r6($dayL), $r6($dayL + $tolNow)], 'high_band' => [$r6($dayH - $tolNow), $r6($dayH)],
            'pivots' => $PV ? t_pivot_view($PV, $dayL, $dayH) : []],
        'tier1' => $t1 ? ['tier' => $t1['tier1'] ?? 'out', 'signal' => $t1['signal'] ?? 'WAIT', 'bias' => $t1['bias'] ?? null, 'alignment' => $t1['alignment'] ?? null,
            'strength' => $t1['strength'] ?? null, 'live' => $t1['live'] ?? false, 'status' => $t1['status'] ?? null,
            'setup' => $t1['setup'] ?? null, 'remark' => $t1['remark'] ?? null, 'zone_near' => $t1['zone_near'] ?? null] : null,
        'flow' => ($t1 && !empty($t1['live'])) ? ($t1['flow'] ?? null) : null,
        // bug fix, 13 Oct 2026: Tier 2 used to carry none of the entry-tick data at all (runbook rule "Tier 1 and
        // Tier 2 must always show the same signals" was broken for the tick display). Built the same way t_public
        // does it, from the same $t1 row, so Tier 1 and Tier 2 can never disagree on what ticks are showing.
        'ph' => $t1 ? t_ph_build($t1) : null, 'pht' => $t1 ? t_ph_times($t1) : null, 'lad' => ($t1 && !empty($t1['v10'])) ? 10 : 8,
        'pw' => ($t1 && !empty($t1['live'])) ? (int)($t1['pw'] ?? 0) : 0,
        'pw_n' => ($t1 && !empty($t1['live'])) ? (int)($t1['pw_n'] ?? 0) : 0,
        'go' => ($t1 && !empty($t1['live'])) ? (int)($t1['go'] ?? 0) : 0,
        // owner, 19 Oct 2026: the tick-0 remark and the Strong Zone group come from the SAME Tier-1 row, so the
        // two pages can never show different remarks for the same symbol.
        't0' => ($t1 && !empty($t1['live'])) ? ($t1['t0'] ?? null) : null,
        'zg' => ($t1 && !empty($t1['live'])) ? (int)($t1['zg'] ?? 2) : 2,
        'zlv' => ($t1 && !empty($t1['live'])) ? ($t1['zlv'] ?? null) : null,
        'mzp' => ($t1 && !empty($t1['live'])) ? ($t1['mzp'] ?? null) : null,
        'mzl' => ($t1 && !empty($t1['live'])) ? ($t1['mzl'] ?? null) : null,
        'hc' => ($t1 && !empty($t1['live']) && ($t1['flow'] ?? null) === 'FLY' && !empty($t1['hc'])) ? 1 : 0,
        'grade' => $open['grade'] ?? (($t1 && !empty($t1['live']) && ($t1['flow'] ?? null) === 'FLY') ? ($t1['grade'] ?? null) : null),
        'trade' => $open ?: $last, 'trades' => $R['trades'], 'pending' => $R['pending'], 'pip' => t2_pip($sym, $px),
        'pb5' => $pb5,
        'st5' => $st5,
        'ema5' => $ema5tick,
        'candles' => $candles, 'cset' => $cset, 'confluence' => $conf, 'news' => $news, 'generated_at' => $now,
    ];
}
function t2_cached(string $sym, string $bias): array {
    t_ensure_dirs(); $dir = T_DATA . '/t2'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $p = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym . '_' . $bias) . '.json';
    $c = t_read_json($p, null);
    if (is_array($c) && t_now() - (int)($c['_at'] ?? 0) < T2_TTL) return $c;
    $d = t2_build($sym, $bias); $d['_at'] = t_now();
    if (!isset($d['error'])) t_write_json($p, $d);
    return $d;
}

/* ---------------- public view (v8, owner 5 Oct 2026: entry / exit stay on the server, internal backtest record only) ---------------- */
const T2_PUB_STATUS = ['ACTION_TRIGGERED' => 'LIVE', 'PATTERN' => 'SETUP', 'WAITING_FOR_TRIGGER' => 'WATCH'];
const T2_PUB_EXIT = ['Stop-loss' => 'Stop-loss', '5-EMA reversal' => 'Momentum exit', 'Session end' => 'Session end', '10m trend flip' => 'Trend flip exit', '10m reverse pattern' => 'Reversal exit', '8m supertrend reversal' => 'Trend flip exit', '8m reverse pattern' => 'Reversal exit'];
/** One trade as the pages see it. Signal side, grade, SL and target levels only: never the entry price / time, exit price / time,
 *  pattern, confirmation or research fields. $withResult (Recent trades list only): adds the R / outcome for the honest record,
 *  plus the signal day (date, no time). */
function t2_pub_trade(?array $t, bool $withResult = false): ?array {
    if (!$t) return null;
    $o = [];
    foreach (['id', 'symbol', 'name', 'market', 'sig', 'remark', 'grade', 'sl0', 'target'] as $k) if (array_key_exists($k, $t)) $o[$k] = $t[$k];
    if (array_key_exists('sl0', $o)) { $o['sl'] = $o['sl0']; unset($o['sl0']); }
    $o['setup'] = t_pub_setup($t['setup'] ?? null);
    $o['active'] = ($t['status'] ?? '') === 'open';
    $o['target_label'] = t_pub_level((string)($t['target_label'] ?? ''));
    if (!empty($t['targets_more'])) $o['targets_more'] = array_map(fn($x) => ['level' => t_pub_level((string)$x['label']), 'price' => $x['price']], $t['targets_more']);
    if ($withResult) {
        foreach (['status', 'touched', 'r'] as $k) if (array_key_exists($k, $t)) $o[$k] = $t[$k];
        $o['reason'] = isset($t['reason']) ? (T2_PUB_EXIT[$t['reason']] ?? 'Exit') : null;
        $o['day'] = isset($t['entry_time']) ? t_ist((int)$t['entry_time'], 'Y-m-d') : null;
    }
    return $o;
}
function t2_public(array $d): array {
    if (isset($d['error']) || !empty($d['locked'])) return $d;
    $p = $d['pending'] ?? null; $z = $d['zone'] ?? null; $live = ($d['trade']['status'] ?? '') === 'open' ? $d['trade'] : null;
    return [
        'symbol' => $d['symbol'], 'name' => $d['name'], 'market' => $d['market'], 'bias' => $d['bias'], 'status' => T2_PUB_STATUS[$d['status']] ?? 'WATCH',
        'current_price' => $d['current_price'], 'sl' => $d['sl'], 'target' => $d['target'], 'grade' => $d['grade'] ?? null,
        'signal_id' => $live['id'] ?? ($live ? substr(md5($d['symbol'] . '|' . $live['sig'] . '|' . $live['entry_time']), 0, 12) : null),
        'last_bar' => $d['last_bar'], 'setup' => t_pub_setup($d['setup'] ?? null), 'remark' => $d['remark'],
        // only the zone the position is built from: CALL = Pulse Floor (day low), PUT = Pulse Ceiling (day high) (owner, 5 Oct 2026)
        'zone' => $z ? ['from' => $z['from'], 'pivots' => $z['pivots'], 'pdc' => $z['pdc'] ?? null] + (function () use ($z, $p, $live, $d) {
            $s = $live['sig'] ?? ($p['sig'] ?? ($d['bias'] ?? null));
            if ($s === 'CALL' && isset($z['day_low'])) return ['side' => 'floor', 'level' => $z['day_low']];
            if ($s === 'PUT' && isset($z['day_high'])) return ['side' => 'ceiling', 'level' => $z['day_high']];
            return [];
        })() : null,
        // which targets the live trade has reached (entry itself stays private)
        'hits' => $live ? (function () use ($live, $d) {
            $sg = $live['sig'] === 'CALL' ? 1 : -1; $ext = null;
            foreach ($d['candles'] ?? [] as $c) if ((int)$c['time'] + 600 > (int)$live['entry_time']) $ext = $ext === null ? ($sg > 0 ? $c['high'] : $c['low']) : ($sg > 0 ? max($ext, $c['high']) : min($ext, $c['low']));
            $T = array_merge([(float)$live['target']], array_map(fn($x) => (float)$x['price'], $live['targets_more'] ?? []));
            return array_map(fn($v) => $ext !== null && ($ext - $v) * $sg >= 0, $T);
        })() : null,
        'targets' => array_map(fn($x) => ['label' => $x['label'], 'price' => $x['price'], 'level' => t_pub_level((string)$x['fib'])], $d['targets'] ?? []),
        'flow' => $d['flow'] ?? null, 'hc' => !empty($d['hc']) ? 1 : 0,
        'ph' => $d['ph'] ?? null, 'pht' => $d['pht'] ?? null, 'pw' => (int)($d['pw'] ?? 0), 'pw_n' => (int)($d['pw_n'] ?? 0), 'go' => (int)($d['go'] ?? 0),
        // Owner, 20 Oct 2026: both setups name the gate that fired; 'both' = one gate with a double grade.
        't0' => $d['t0'] === null ? null : ($d['t0'] === 'bb' ? 'BB Sweep' : ($d['t0'] === 'both' ? 'Sweep + BB' : 'Liquidity Sweep')),
        'lad' => (int)($d['lad'] ?? (!empty($d['v10']) ? 10 : 8)), 'sl_wide' => (int)($d['sl_wide'] ?? 0),
        'zg' => (int)($d['zg'] ?? 2), 'zlv' => $d['zlv'] ?? null,
        'mzp' => $d['mzp'] ?? null, 'mzl' => $d['mzl'] ?? null,
        'tier1' => $d['tier1'] ? ['tier' => $d['tier1']['tier'], 'signal' => $d['tier1']['signal'], 'bias' => $d['tier1']['bias'],
            'strength' => $d['tier1']['strength'], 'live' => $d['tier1']['live'], 'status' => $d['tier1']['status'], 'remark' => $d['tier1']['remark'],
            'setup' => t_pub_setup($d['tier1']['setup'] ?? null), 'zone_near' => t_pub_zone($d['tier1']['zone_near'] ?? null)] : null,
        'trade' => t2_pub_trade($live), 'trades' => array_map(fn($t) => t2_pub_trade($t), $d['trades'] ?? []),
        'pending' => $p ? ['stage' => 'setup', 'sig' => $p['sig'], 'time' => $p['time']] : null,
        'pb5' => (int)($d['pb5'] ?? 0),
        'st5' => (int)($d['st5'] ?? 0),
        'ema5' => (int)($d['ema5'] ?? 0),
        'pip' => $d['pip'], 'candles' => $d['candles'], 'cset' => $d['cset'] ?? null, 'confluence' => $d['confluence'], 'news' => $d['news'], 'generated_at' => $d['generated_at'],
    ];
}
/** Recent trades list (public): R / outcome per trade and the aggregate record, without entry / exit prices or times. */
function t2_trades_public(array $v): array {
    $v['items'] = array_map(fn($t) => t2_pub_trade($t, true), $v['items']); $v['top'] = array_map(fn($t) => t2_pub_trade($t, true), $v['top']);
    if (isset($v['stats']['since'])) $v['stats']['since'] = $v['stats']['since'] ? t_ist((int)$v['stats']['since'], 'Y-m-d') : null;
    return $v;
}

/* ---------------- Telegram ---------------- */
function t2_tg_text(array $p): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES); $t = $p['trade'] ?? null; $open = ($p['status'] ?? '') === 'ACTION_TRIGGERED' && $t;
    $L = ["<b>ChartPulse Tier 2 \u{00b7} " . $e($p['name']) . "</b>"];
    if ($open) {
        $L[] = ($t['sig'] === 'CALL' ? "\u{1F7E2} <b>CALL</b>" : "\u{1F534} <b>PUT</b>") . " \u{00b7} <b>FLY</b>" . (!empty($t['grade']) ? " \u{00b7} grade <b>" . $e($t['grade']) . '</b>' : '');
        $L[] = 'SL: ' . t_fp($t['sl0']) . " \u{00b7} TP1: " . t_fp($t['target']) . ' (' . $e(t_pub_level((string)$t['target_label'])) . ')';
        foreach (($t['targets_more'] ?? []) as $q => $x) $L[] = 'TP' . ($q + 2) . ': ' . t_fp($x['price']) . ' (' . $e(t_pub_level((string)$x['label'])) . ')';
        if (!empty($p['remark'])) $L[] = 'Remark: ' . $e($p['remark']);
    } else {
        $L[] = 'Bias: <b>' . $e($p['bias']) . '</b> | ' . (($p['status'] ?? '') === 'PATTERN' ? 'Setting up, waiting for confirmation' : (!empty($p['flow']) ? $e($p['flow']) : 'Watching the Pulse zone'));
        $L[] = 'Price: ' . t_fp($p['current_price']);
    }
    if (!empty($p['zone'])) $L[] = 'Pulse Floor ' . t_fp($p['zone']['day_low']) . " \u{00b7} Pulse Ceiling " . t_fp($p['zone']['day_high']);
    $L[] = "chartpulse.io \u{00b7} not investment advice";
    return implode("\n", $L);
}
function t2_tg_send(string $text, string $chat): array {
    $tok = trim((string)t_cfg('telegram_bot_token', ''));
    if ($tok === '') return [false, 'Telegram is not set up on this site yet.'];
    if ($chat === '') return [false, 'Enter your Telegram chat ID first.'];
    $ch = curl_init('https://api.telegram.org/bot' . $tok . '/sendMessage');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true])]);
    $res = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (!empty($res['ok'])) return [true, 'Sent to Telegram'];
    return [false, 'Telegram said: ' . (string)($res['description'] ?? 'no response') . '. Start a chat with the bot first, then try again.'];
}
