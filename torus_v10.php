<?php
// torus_v10.php - Trend setup v10 (owner PDF "Day Low / Day High Scanner - Only for Trend" v3, 9 Oct 2026,
// final per owner 11 Oct 2026). Server-only (torus_*.php is denied by .htaccess). Retracement is NOT touched here.
//  Bias (owner, 19 Oct 2026): Supertrend (10,3) on 1H, 4H, 1D, 1W; 3/4 needed, 4/4 = +10. 30m is OUT of the
//  vote -- it still drives the Stochastic, the ADX and the tick-0 sweep.
//  Score 100: Location 30 | Reversal saboot 35 (5m retest 27 / sweep 16, 5m BB touch 8) | Exhaustion 15 (30m Stoch %K 10, 5m RSI 5)
//             | Trend 20 (30m ADX + DI 10, MTF 5/5 10). Valid: score >= 33 and a PDL/PDH or Fib level near the zone.
//  Ticks (owner PDF "Final Setup", 19 Oct 2026 -- replaces the 13 Oct FVG tick and moves ticks 1-3 to 5m):
//    0 = 30m Liquidity Sweep / BB Sweep, 1 = 5m CHoCH, 2 = 5m close beyond the 5 EMA, 3 = 5m Supertrend flip,
//    4 = 10m pattern + next-candle confirm. Any order, first (by time) = entry.
//  Ticks only start counting once today's range has reached 30% of ADR (owner, 19 Oct 2026; was 50%).
//  Cycle reset: a 5m close beyond the zone (running Day Low - band / Day High + band).
//  Owner, 13 Oct 2026 (later): a confirmed RETEST (pw=2 -- touch, bounce, return, reclaim) ALSO resets the entry-tick
//    search inside the same cycle: ticks fired before the retest no longer count, a fresh tick search starts from
//    the retest bar. A plain sweep (pw=1) does not reset. The day extreme / cycle boundary itself is unaffected.
//  SL (owner, 19 Oct 2026) = 1.5% from the position line, on the losing side -- both setups, replacing the
//  5m BB wick. Targets = next 3 Fibonacci daily pivots (retracement: 1).
//  Exit (owner, 19 Oct 2026) = 8m Supertrend (10,3) flips against the trade, or an 8m candle closes across the
//  5 EMA. 8m candles are built from 1m bars anchored to the session open; without 1m bars there is no 8m exit.

const T10_ON = true;
const T10_MIN_SCORE = 30.0;
const T10_ENTRY_ATR = 2.0;   // entry range from the Day Low / High (owner, 19 Oct 2026: 1.0 -> 2.0).
// Why: 1 x ATR5 was so tight on low-ATR symbols that a signal died in 2-3 candles. Live example CRV-USD:
// ATR5 was ~0.22% of price, so a 0.5% bounce off the Day Low already put price 2.2 x ATR5 away and the
// row fell out of 'signal' while the setup still looked fine on the chart. This value only controls how
// long a signal stays alive (GATE CLOSE); it does not change the score, the ticks or the SL.
const T10_GATE_HOLD = 240;   // GATE CLOSE stays 4 min, then the signal is removed
const T10_ADR_PCT = 0.30;    // owner, 19 Oct 2026: ticks start once today's range reaches 30% of ADR (was 50%)
const T10_SL_PCT = 0.015;    // owner, 19 Oct 2026: SL = 1.5% from the position line, both setups

// ---- Zone Entry Gates ladder (owner, 20 Oct 2026; updated 3 Nov 2026) ---------------------------------
// ONE gate (diamond) + 4 entry ticks. Gate is now 10m (was 30m). Tick 3 = 5m reversal pattern + next
// candle confirm. Old tick 3 (Double Confirm / FLY) is now tick 4.
//   GATE OPEN (not an entry):
//     Liquidity Sweep -- a 10m wick past the Day extreme OR past a zone-group level (Prev Low / Prev High,
//       a Fib level, the Fibonacci daily pivot), candle closing back on the right side of it. Depth has to
//       be more than T10_GATE_MIN_ATR x ATR5 (otherwise it is noise, not a sweep) and less than
//       T10_GATE_MAX_ATR x ATR5 (deeper is a breakdown). A Strong-Zone level swept is the best arming.
//     BB Sweep -- the fallback gate: exhaustion, not liquidity. A 5m wick past the outer Bollinger band
//       with the candle closing back inside. Both on the same candle = ONE gate, labelled "Sweep + BB".
//   1 Early Entry (5m CHoCH)  2 Entry A (5m 5 EMA)  3 Reversal Pat (5m pattern + next 5m confirm)
//   4 Double Confirm (10m pattern + next 10m candle) = FLY
//   TREND: the first tick is the entry, gate or no gate (without a gate the diamond stays empty).
//   RETRACEMENT: ticks are only counted once the gate is open, and the 5 EMA alone is never an entry --
//     it only raises the grade. This is what stops "entry first, sweep afterwards" (the stop-hunt case).
//   SL: the sweep wick is the anchor -- sweep extreme -/+ T10_SL_BUF_ATR x ATR5, capped at 1.5%. A
//     structural SL wider than 1.5% is flagged WIDE (the trade itself uses the cap).
//   Gate expiry BEFORE an entry: price more than T10_ENTRY_ATR x ATR5 outside the band, a 5m close beyond
//     the zone (cycle reset -> APPROACHING AGAIN), or the gate going older than T10_GATE_AGE.
//   AFTER an entry everything freezes: setup, position line, SL, targets. Band exits, zone shifts, retests
//     and bias flips no longer touch the row -- only the SL, a target or the 8m exit can end it.
const T10_GATE_MIN_ATR = 0.1;    // a wick shallower than this is noise, not a sweep
const T10_GATE_MAX_ATR = 2.0;    // deeper than this is a breakdown, not a sweep
const T10_GATE_AGE = 1800;       // 3 x 10m candles: an unused gate expires
const T10_CHOCH_BUF = 0.1;       // x ATR5 buffer over the swing level (body close, not a wick)
const T10_CHOCH_BARS = 3;        // at least 3 candles after the Day-extreme / sweep candle
const T10_SL_BUF_ATR = 0.1;      // x ATR5 buffer under the sweep wick

function t10_closed(array $f, int $sec, int $now): array {
    $n = count($f['t']); while ($n > 0 && (int)$f['t'][$n - 1] + $sec > $now) $n--;
    if ($n === count($f['t'])) return $f;
    $o = []; foreach ($f as $k => $v) $o[$k] = is_array($v) ? array_slice($v, 0, $n) : $v;
    return $o;
}
/** 10m bars from 5m bars, closed only. (Gate changed from 30m → 10m, 3 Nov 2026.) */
function t10_m10(?array $F5, int $now): ?array {
    if (!$F5 || count($F5['t'] ?? []) < 20) return null;
    $f = t10_closed(t_resample($F5 + ['v' => array_fill(0, count($F5['t']), 0)], 600), 600, $now);
    return count($f['c']) >= 6 ? $f : null;
}
/** @deprecated Back-compat alias — callers updated to t10_m10, kept for torus_v10 internal use. */
function t10_m30(?array $F5, int $now): ?array { return t10_m10($F5, $now); }
/** Supertrend (10,3) direction of the last bar, or null. */
function t10_st_last(?array $f): ?int {
    if (!$f || count($f['c'] ?? []) < 12) return null;
    [$d] = t_supertrend($f, 10, 3.0); return (int)end($d);
}
/** Stochastic %K (14, 3): SMA(3) of the raw %K, last bar. */
function t10_stoch_k(?array $f, int $p = 14, int $s = 3): ?float {
    if (!$f) return null; $n = count($f['c']); if ($n < $p + $s) return null;
    $raw = [];
    for ($i = $n - $s; $i < $n; $i++) {
        $hh = max(array_slice($f['h'], $i - $p + 1, $p)); $ll = min(array_slice($f['l'], $i - $p + 1, $p));
        $raw[] = $hh > $ll ? 100 * ((float)$f['c'][$i] - $ll) / ($hh - $ll) : 50.0;
    }
    return array_sum($raw) / $s;
}
/** Bias over the 4 MTF timeframes (owner, 19 Oct 2026: 30m removed from the vote; it still drives the
 *  Stochastic, the ADX and the tick-0 sweep). $tfDirs = 1H/4H/1D/1W dirs from t_evaluate. 3/4 needed, 4/4 = +10.
 *  $m30 is kept in the signature (callers pass it) but no longer votes. Returns [bias, al, dirs]. */
function t10_bias(array $tfDirs, ?array $m30): array {
    $dirs = $tfDirs;
    $up = 0; $dn = 0; foreach ($dirs as $d) { if ($d === 1) $up++; elseif ($d === -1) $dn++; }
    $bias = $up >= 3 ? 'UP' : ($dn >= 3 ? 'DOWN' : 'NEUTRAL');
    return [$bias, max($up, $dn), $dirs];
}

/**
 * The gate, part 1 -- Liquidity Sweep on 10m (was 30m; changed 3 Nov 2026), confirmed on the 10m close.
 * The wick has to go past a real level and the candle has to close back on the right side of it. Levels:
 * the running Day extreme and every zone-group level passed in $lv (Prev Low / Prev High, Fib, pivot) --
 * a Strong-Zone level swept is the strongest arming there is. Depth has to sit between T10_GATE_MIN_ATR
 * and T10_GATE_MAX_ATR x ATR5: shallower is noise, deeper is a breakdown.
 * Returns [close time, close price, 'liq', wick extreme, level name, depth] of the first one in the window.
 */
function t10_gate30(array $M30, int $sg, int $dayTs, int $from, int $to, float $a5, array $lv): ?array {
    $barSec = 600;   // 10m bars (was 1800 / 30m)
    $n = count($M30['t'] ?? []); if ($n < 2 || $a5 <= 0) return null;
    $dLo = T10_GATE_MIN_ATR * $a5; $dHi = T10_GATE_MAX_ATR * $a5;
    $ext = $sg > 0 ? INF : -INF; $prev = null;
    for ($i = 0; $i < $n; $i++) {
        $t = (int)$M30['t'][$i]; if ($t < $dayTs) continue;
        $l = (float)$M30['l'][$i]; $h = (float)$M30['h'][$i]; $c = (float)$M30['c'][$i];
        $w = $sg > 0 ? $l : $h; $tc = $t + $barSec;
        if ($tc > $from && $tc <= $to + $barSec) {
            $L = []; if ($prev !== null) $L['Pulse zone'] = (float)$prev;
            foreach ($lv as $k => $p) if ($p !== null) $L[(string)$k] = (float)$p;
            foreach ($L as $k => $p) {
                $d = ($p - $w) * $sg;                      // how far the wick went past the level
                if ($d < $dLo || $d > $dHi) continue;
                if (($c - $p) * $sg > 0) return [$tc, $c, 'liq', $w, (string)$k, $d];
            }
        }
        $ext = $sg > 0 ? min($ext, $l) : max($ext, $h); $prev = $ext;
    }
    return null;
}
/**
 * The gate, part 2 -- BB Sweep, the fallback (owner, 20 Oct 2026: "BB Sweep liquidity nahi, exhaustion hai").
 * 5m: the wick goes past the outer Bollinger band (20,2) and the candle closes back inside it. Same return
 * shape as t10_gate30, with the band as the level.
 */
function t10_gate_bb(array $F, int $a, int $b, int $sg, array $BBlo, array $BBup, int $from, int $to): ?array {
    for ($i = max($a, 1); $i <= $b; $i++) {
        $tc = (int)$F['t'][$i] + 300; if ($tc <= $from) continue; if ($tc > $to) break;
        $l = (float)$F['l'][$i]; $h = (float)$F['h'][$i]; $c = (float)$F['c'][$i];
        $bd = $sg > 0 ? ($BBlo[$i] ?? null) : ($BBup[$i] ?? null); if ($bd === null) continue;
        if ($sg > 0 ? ($l < $bd && $c > $bd) : ($h > $bd && $c < $bd))
            return [$tc, $c, 'bb', $sg > 0 ? $l : $h, 'BB', 0.0];
    }
    return null;
}
/** A Tier-2 v10 trade of this symbol that is still open (used to freeze the row after entry), or null. */
function t10_open_trade(string $sym): ?array {
    static $M = null;
    if ($M === null) { $M = []; $now = time();
        $j = t_read_json(T_DATA . '/t2trades.json', ['items' => []]);
        foreach (($j['items'] ?? []) as $t) {
            if (($t['status'] ?? '') !== 'open' || (int)($t['rules'] ?? 0) < 10) continue;
            if ($now - (int)($t['entry_time'] ?? 0) > 43200) continue;
            $M[(string)$t['symbol']] = $t;
        } }
    return $M[$sym] ?? null;
}
/** True once the Tier-2 trade taken at $te has closed -- that is what releases the post-entry freeze. */
function t10_trade_closed(string $sym, int $te): bool {
    static $M = null;
    if ($M === null) { $M = [];
        $j = t_read_json(T_DATA . '/t2trades.json', ['items' => []]);
        foreach (($j['items'] ?? []) as $t)
            if (($t['status'] ?? '') === 'closed') $M[(string)$t['symbol'] . '|' . (int)($t['entry_time'] ?? 0)] = true; }
    return isset($M[$sym . '|' . $te]);
}
/**
 * Which cycle the row shows. Normally the newest one -- but an entry freezes its cycle (owner, 20 Oct 2026),
 * so a cycle whose entry has not been closed out by Tier 2 yet keeps the row, whatever later bars do.
 * $arm = retracement rules (gate first, no 5-EMA entry).
 */
function t10_pick(array $C, string $sym, bool $arm): ?array {
    if (!$C) return null;
    $k = $arm ? 'first_rt' : 'first';
    for ($i = count($C) - 1; $i >= 0; $i--) {
        $e = $C[$i][$k] ?? null; if ($e === null) continue;
        if (t10_trade_closed($sym, (int)$e)) break;   // that trade is over: the newest cycle takes over
        return $C[$i];
    }
    return $C[count($C) - 1];
}
/**
 * ADR gate (owner, 19 Oct 2026; 30%, was 50%): the index of the first 5m bar at which today's range has reached
 * T10_ADR_PCT of the ADR. Ticks are not counted before it. Returns null when there is no ADR to gate on, or
 * PHP_INT_MAX when today's range has not reached the threshold at all (so no ticks are counted today).
 */
function t10_adr_gate(array $F, int $a, int $b, ?float $adr): ?int {
    if (!$adr || $adr <= 0) return null;
    $need = T10_ADR_PCT * $adr; $hi = -INF; $lo = INF;
    for ($i = $a; $i <= $b; $i++) {
        $hi = max($hi, (float)$F['h'][$i]); $lo = min($lo, (float)$F['l'][$i]);
        if ($hi - $lo >= $need) return $i;
    }
    return PHP_INT_MAX;
}
/** 8-minute candles from 1-minute bars, anchored to the session open, closed bars only (owner, 19 Oct 2026). */
function t10_eight(?array $M1, int $dayTs, int $now): ?array {
    if (!$M1 || empty($M1['t'])) return null;
    $off = $dayTs % 480;
    $G = t_group($M1, fn($t) => intdiv($t - $off, 480));
    $n = count($G['t'] ?? []); while ($n > 0 && (int)$G['t'][$n - 1] + 480 > $now) $n--;
    if ($n < 12) return null;
    foreach ($G as $k => $v) if (is_array($v)) $G[$k] = array_slice($v, 0, $n);
    return $G;
}
/**
 * Exit check after entry (owner PDF, 19 Oct 2026): the 8m Supertrend (10,3) flips against the trade, or an 8m
 * candle closes across the 5 EMA against the trade -- whichever is first. Checks start from the candle AFTER
 * entry, so the Supertrend / EMA position already standing at entry cannot exit the trade immediately.
 * $G8 = 8m bars from t10_eight(). Returns [time, price, reason] or null (also null when there are no 1m bars).
 */
function t10_exit_8m(?array $G8, int $te, int $sg, int $now): ?array {
    if (!$G8) return null;
    $n = count($G8['c'] ?? []); if ($n < 12) return null;
    [$D] = t_supertrend($G8, 10, 3.0);
    $E = t_ema(array_map('floatval', $G8['c']), 5);
    for ($j = 1; $j < $n; $j++) {
        $tc = (int)$G8['t'][$j] + 480; if ($tc > $now) break; if ($tc <= $te) continue;
        $c = (float)$G8['c'][$j];
        if ($D[$j] === -$sg && $D[$j - 1] === $sg) return [$tc, $c, '8m trend flip'];
        if ($E[$j] !== null && $E[$j - 1] !== null
            && ($sg > 0 ? ($c < $E[$j] && $G8['c'][$j - 1] >= $E[$j - 1]) : ($c > $E[$j] && $G8['c'][$j - 1] <= $E[$j - 1]))) return [$tc, $c, '8m 5 EMA cross'];
    }
    return null;
}
/**
 * Tier-1 zone group (owner PDF, 19 Oct 2026). Which level families stand at the zone:
 *   0 = STRONG ZONE -- a Fib level, a Fibonacci daily pivot and a PDH/PDL all sit in the same zone
 *   1 = exactly one of Fib / Pivot / PDH / PDL / PDC stands there
 *   2 = none (near the zone, but further out)
 * The group sorts the Tier-1 list ABOVE the status, so a Strong Zone APPROACHING row outranks a one-level FLY.
 * Pivot and PDC are used only here and in the remark -- they do not change the score (owner PDF S12 Q4).
 * Returns [group, public label or null].
 */
function t10_zone_group(float $zone, float $tol, array $lv): array {
    if ($tol <= 0) return [2, null];
    $near = fn($p) => $p !== null && abs((float)$p - $zone) <= $tol;
    $fib = false; foreach (($lv['fibs'] ?? []) as $f) if ($near($f)) { $fib = true; break; }
    $pivK = null; foreach (($lv['pivots'] ?? []) as $k => $v) if ($near($v)) { $pivK = $k; break; }
    $pdK = null;
    foreach (['PDH' => $lv['pdh'] ?? null, 'PDL' => $lv['pdl'] ?? null] as $k => $v) if ($near($v)) { $pdK = $k; break; }
    $pdc = $near($lv['pdc'] ?? null);
    if ($fib && $pivK !== null && $pdK !== null) return [0, 'STRONG ZONE (Fib + Pivot + PDH/PDL)'];
    $one = [];
    if ($fib) $one[] = 'Fib';
    if ($pivK !== null) $one[] = t_pub_level($pivK);
    if ($pdK !== null) $one[] = $pdK === 'PDH' ? 'Prev High' : 'Prev Low';
    if ($pdc) $one[] = 'Prev Close';
    if (count($one) >= 1) return [1, $one[0]];
    return [2, null];
}
/** Running trade-side extreme from the session start: RX[i] = Day Low (sg>0) / Day High (sg<0) up to bar i. */
function t10_rx(array $F, int $a, int $b, int $sg): array {
    $RX = []; $ext = $sg > 0 ? INF : -INF;
    for ($i = $a; $i <= $b; $i++) { $ext = $sg > 0 ? min($ext, (float)$F['l'][$i]) : max($ext, (float)$F['h'][$i]); $RX[$i] = $ext; }
    return $RX;
}
/** Cycles: a new one starts at every 5m close beyond the zone (prior running extreme -/+ band). Returns [[start, end], ...]. */
function t10_cycles(array $F, int $a, int $b, int $sg, array $A5, array $RX, float $zb): array {
    $C = []; $s = $a;
    for ($i = $a + 1; $i <= $b; $i++) {
        $band = $zb * (float)$A5[$i]; $c = (float)$F['c'][$i]; $p = $RX[$i - 1];
        if ($sg > 0 ? $c < $p - $band : $c > $p + $band) { $C[] = [$s, $i - 1]; $s = $i; }
    }
    $C[] = [$s, $b];
    return $C;
}
/**
 * Evaluate one cycle [$cs..$ce] of 5m bars (session starts at $a). Closed bars only. Ladder of 20 Oct 2026:
 * one gate + 3 ticks.
 * $BBlo / $BBup = BB(20,2) on $F closes. $M30 = closed 30m bars or null (the Liquidity Sweep). $flyEv =
 * t_fly_events events (tick 3). $gateIdx = the ADR gate from t10_adr_gate (null = none). $lv = the zone-group
 * levels the Liquidity Sweep may sweep. Returns:
 *   ticks[4]    = [gate time, Early Entry, Entry A, Double Confirm] (null = not yet)
 *   first       = TREND entry: the first of ticks 1-3, gate or no gate
 *   first_rt    = RETRACEMENT entry: the first of ticks 1 / 3 at or after the gate (5 EMA is confirm only)
 *   sl / sl_rt  = the sweep-wick SL capped at 1.5%, with wide / wide_rt set when structure needs more
 *   gate, gate_px, gate_type ('liq' / 'bb' / 'both'), gate_level, gate_exp, sw_wick
 *   pw / bb / t0 / fly / ext / retest  (score inputs and remarks, unchanged)
 */
function t10_cycle_eval(array $F, int $a, int $cs, int $ce, int $sg, array $A5, array $RX, array $BBlo, array $BBup, float $zb, ?array $M30, array $flyEv, ?int $gateIdx = null, array $lv = [], int $now = 0, ?int $fromIdx = null): array {
    $dayTs = (int)$F['t'][$a]; $tce = (int)$F['t'][$ce] + 300;
    // retest / sweep / BB touch on 5m -- score inputs only (unchanged)
    $pw = 0; $bb = false; $touch = false; $bounce = false; $retestAt = null;
    for ($i = $cs; $i <= $ce; $i++) {
        $l = (float)$F['l'][$i]; $h = (float)$F['h'][$i]; $c = (float)$F['c'][$i]; $band = $zb * (float)$A5[$i]; $rx = $RX[$i];
        if ($sg > 0 ? ($BBlo[$i] !== null && $l <= $BBlo[$i]) : ($BBup[$i] !== null && $h >= $BBup[$i])) $bb = true;
        if ($i > $a) { $p = $RX[$i - 1]; if ($sg > 0 ? ($l < $p && $c > $p) : ($h > $p && $c < $p)) $pw = max($pw, 1); }
        $near = $sg > 0 ? $l - $rx <= $band : $rx - $h <= $band;
        if (!$touch) { $touch = $near; continue; }
        if (!$bounce) { $bounce = $sg > 0 ? $c >= $rx + 2 * $band : $c <= $rx - 2 * $band; continue; }
        $noNew = $i > $a && $RX[$i] == $RX[$i - 1];
        if ($near && $noNew && ($sg > 0 ? $c > $rx + $band : $c < $rx - $band)) { $pw = 2; if ($retestAt === null) $retestAt = $i; }
    }
    // A confirmed retest (pw=2) restarts the tick search inside the same cycle (owner, 13 Oct 2026); a plain
    // sweep does not. The ADR gate (30% of ADR, owner 19 Oct 2026) is the other start condition.
    $tkA0 = $retestAt ?? $cs; $ticksOn = true;
    // Re-Fly (owner, 4 Nov 2026): a retest after an entry that was already frozen restarts the tick search from there.
    if ($fromIdx !== null) $tkA0 = $fromIdx;
    if ($gateIdx !== null) { if ($gateIdx > $ce) $ticksOn = false; else $tkA0 = max($tkA0, $gateIdx); }
    $tkA = min($tkA0, $ce); $tkTcs = (int)$F['t'][$tkA]; $a5 = (float)$A5[$ce];
    // ---- the gate (not a tick, not an entry) --------------------------------------------------------------
    $g = null;
    if ($ticksOn) {
        $gl = $M30 ? t10_gate30($M30, $sg, $dayTs, $tkTcs, $tce, $a5, $lv) : null;
        $gb = t10_gate_bb($F, $tkA, $ce, $sg, $BBlo, $BBup, $tkTcs, $tce);
        $g = ($gl && $gb) ? ($gb[0] <= $gl[0] ? $gb : $gl) : ($gl ?: $gb);
        // Both on the same 10m candle: ONE gate, not two -- it is only graded "double" (owner, 20 Oct 2026).
        if ($gl && $gb && abs($gl[0] - $gb[0]) <= 600) { $g = $gl; $g[2] = 'both'; }
    }
    $gateAt = $g[0] ?? null; $swWick = $g[3] ?? null;
    // 5 elements: [gate, tick1, tick2, tick3(5m pat), tick4(Double Confirm/FLY)]
    $tk = [$gateAt, null, null, null, null]; $tp = [$g[1] ?? null, null, null, null, null];
    // ---- ticks 1 and 2 on 5m, tick 3 = 5m reversal pattern + next 5m candle confirm ------------------
    if ($ticksOn) {
        $Fs = []; foreach (['t', 'o', 'h', 'l', 'c'] as $kk) $Fs[$kk] = array_slice($F[$kk], 0, $ce + 1);
        $E5 = t_ema(array_map('floatval', $Fs['c']), 5);
        $piv = null; $loIdx = $a; $lx = $sg > 0 ? INF : -INF;
        for ($i = $a; $i <= $ce; $i++) {
            // running Day extreme / sweep extreme: causal, so later bars can never undo a tick already fired
            $v = $sg > 0 ? (float)$F['l'][$i] : (float)$F['h'][$i];
            if ($sg > 0 ? $v < $lx : $v > $lx) { $lx = $v; $loIdx = $i; }
            // structure pivots (2 bars each side, confirmed two bars later), only those made AFTER that extreme
            if ($i >= $a + 4 && $i + 2 <= $ce) { $q = $i - 2;
                if ($q > $loIdx) {
                    if ($sg > 0) { $x = (float)$F['h'][$q]; if ($x > $F['h'][$q - 1] && $x > $F['h'][$q - 2] && $x >= $F['h'][$q + 1] && $x >= $F['h'][$q + 2]) $piv = $x; }
                    else { $x = (float)$F['l'][$q]; if ($x < $F['l'][$q - 1] && $x < $F['l'][$q - 2] && $x <= $F['l'][$q + 1] && $x <= $F['l'][$q + 2]) $piv = $x; }
                }
            }
            if ($i < max($tkA, 1)) continue;
            $tc = (int)$F['t'][$i] + 300; if ($tc > $tce) break;
            $c = (float)$F['c'][$i]; $buf = T10_CHOCH_BUF * (float)$A5[$i];
            // 1 Early Entry (CHoCH): a 5m BODY close through the first swing level made after the Day extreme /
            // sweep extreme, with a 0.1 x ATR5 buffer, at least 3 candles after that extreme candle.
            if ($tk[1] === null && $piv !== null && $i >= $loIdx + T10_CHOCH_BARS
                && ($sg > 0 ? $c > $piv + $buf : $c < $piv - $buf)) { $tk[1] = $tc; $tp[1] = $c; }
            // 2 Entry A: a 5m candle closes beyond the 5 EMA.
            if ($tk[2] === null && $E5[$i] !== null && ($sg > 0 ? $c > $E5[$i] : $c < $E5[$i])) { $tk[2] = $tc; $tp[2] = $c; }
            // 3 Reversal Pat: a major 5m reversal pattern + the NEXT 5m candle closes beyond pattern close
            // in the signal direction. Patterns: Engulfing / Harami / Hammer / Pin bar / Shooting Star /
            // Morning-Evening Star / Inverted Hammer / Tweezer / Dark Cloud / Piercing / Abandoned Baby /
            // Three White Soldiers / Three Black Crows (all detected by t_reversal). (3 Nov 2026)
            // ZONE (4 Nov 2026): the pattern candle must sit near the Day/sweep extreme (within zb x ATR5), same as tick 4.
            $zBand = $zb * (float)$A5[$i]; $inZone = $sg > 0 ? ((float)$F['l'][$i] - $RX[$i] <= $zBand) : ($RX[$i] - (float)$F['h'][$i] <= $zBand);
            if ($tk[3] === null && $i + 1 <= $ce && $inZone) {
                $pat = t_reversal($Fs, $i, $sg);
                if ($pat !== null) {
                    // next 5m candle must close in the signal direction beyond the pattern candle's close
                    $nc = (float)$F['c'][$i + 1];
                    if ($sg > 0 ? ($nc > $c && $nc > (float)$F['o'][$i + 1]) : ($nc < $c && $nc < (float)$F['o'][$i + 1])) {
                        $tk[3] = (int)$F['t'][$i + 1] + 300; $tp[3] = $nc;
                    }
                }
            }
        }
    }
    // 4 Double Confirm: a 10m reversal pattern confirmed by the NEXT 10m candle -> FLY (was tick 3)
    $fly = null;
    if ($ticksOn) foreach ($flyEv as $e) if ((int)$e['pattern_time'] >= $tkTcs + 600 && (int)$e['time'] <= $tce + 600) { $fly = $e; $tk[4] = (int)$e['time']; $tp[4] = (float)$e['price']; break; }
    // ---- entries -----------------------------------------------------------------------------------------
    $pick = function (array $idx, bool $arm) use ($tk, $tp, $gateAt) {
        $t = null; $p = null;
        foreach ($idx as $q) {
            $x = $tk[$q]; if ($x === null) continue;
            if ($arm && ($gateAt === null || $x < $gateAt)) continue;   // retracement: no tick before the gate
            if ($t === null || $x < $t) { $t = $x; $p = $tp[$q]; }
        }
        return [$t, $p];
    };
    [$first, $fpx] = $pick([1, 2, 3, 4], false);        // trend: first tick wins, gate or no gate
    [$firstRt, $fpxRt] = $pick([1, 3, 4], true);        // retracement: gate first, never 5 EMA alone
    // The gate expires unused after 3 x 10m candles (and the caller's band / zone rules), but never after an entry.
    $gateExp = $gateAt !== null && $first === null && $now > 0 && $now - $gateAt > T10_GATE_AGE;
    // ---- SL: the sweep wick is the anchor, 1.5% is the cap -----------------------------------------------
    $mk = function (?float $px) use ($sg, $swWick, $a5) {
        if ($px === null) return [null, false];
        $cap = $sg > 0 ? $px * (1 - T10_SL_PCT) : $px * (1 + T10_SL_PCT);
        if ($swWick === null || $a5 <= 0) return [$cap, false];
        $st = $sg > 0 ? $swWick - T10_SL_BUF_ATR * $a5 : $swWick + T10_SL_BUF_ATR * $a5;
        if (($px - $st) * $sg <= 0) return [$cap, false];                  // the wick is the wrong side of entry
        $wide = ($px - $st) * $sg > $px * T10_SL_PCT;                      // structure wants more than 1.5%
        return [$wide ? $cap : $st, $wide];
    };
    [$sl, $wide] = $mk($fpx); [$slRt, $wideRt] = $mk($fpxRt);
    return ['cs' => $tkTcs, 'ce' => $tce, 'ticks' => $tk, 'tick_px' => $tp,
        'first' => $first, 'first_px' => $fpx, 'first_rt' => $firstRt, 'first_rt_px' => $fpxRt,
        'pw' => $pw, 'bb' => $bb, 'sl' => $sl, 'sl_rt' => $slRt, 'wide' => $wide, 'wide_rt' => $wideRt,
        'gate' => $gateAt, 'gate_px' => $g[1] ?? null, 'gate_type' => $g[2] ?? null, 'gate_level' => $g[4] ?? null,
        'gate_exp' => $gateExp, 'sw_wick' => $swWick, 't0' => $g[2] ?? null,
        'fly' => $fly, 'ext' => $RX[$ce], 'retest' => $retestAt !== null];
}
/**
 * Bars where a confirmed retest happens inside one cycle: touch the zone, bounce, come back and reclaim
 * (same rule as t10_cycle_eval, pw = 2). Used for Re-Fly (owner, 4 Nov 2026). Returns bar indices in order.
 */
function t10_retest_idx(array $F, int $cs, int $ce, int $sg, array $A5, array $RX, float $zb): array {
    $touch = false; $bounce = false; $out = [];
    for ($i = $cs; $i <= $ce; $i++) {
        $l = (float)$F['l'][$i]; $h = (float)$F['h'][$i]; $c = (float)$F['c'][$i];
        $band = $zb * (float)$A5[$i]; $rx = $RX[$i];
        $near = $sg > 0 ? $l - $rx <= $band : $rx - $h <= $band;
        if (!$touch) { $touch = $near; continue; }
        if (!$bounce) { $bounce = $sg > 0 ? $c >= $rx + 2 * $band : $c <= $rx - 2 * $band; continue; }
        $noNew = $i > $cs && $RX[$i] == $RX[$i - 1];
        if ($near && $noNew && ($sg > 0 ? $c > $rx + $band : $c < $rx - $band)) $out[] = $i;
    }
    return $out;
}

/**
 * All cycles of a session with their evaluation. $M1 = 1m bars or null, $adr = ADR for the tick gate,
 * $lv = zone-group levels for the Liquidity Sweep, $arm = retracement rules (used only to decide which
 * entry freezes a cycle). Owner, 20 Oct 2026: once a cycle has an entry it is re-run to the end of the
 * session, so a later close beyond the zone no longer resets it -- after entry everything freezes.
 */
function t10_session(array $F, int $a, int $b, int $sg, ?array $M1, float $zb, int $now, ?float $adr = null, array $lv = [], bool $arm = false): array {
    $b = min($b, count($F['t']) - 1); while ($b >= $a && (int)$F['t'][$b] + 300 > $now) $b--;
    if ($b - $a < 3) return [];
    $A5 = t_atr($F, 14); $RX = t10_rx($F, $a, $b, $sg);
    [, $U, $Lo] = t_bb(array_map('floatval', array_slice($F['c'], 0, $b + 1)), 20, 2.0);
    $M30 = t10_m30($F, $now);
    $gate = t10_adr_gate($F, $a, $b, $adr);
    $fly = t_fly_events($F, $a, $b, $sg, $M1, $zb, $now)['events'];
    $key = $arm ? 'first_rt' : 'first';
    $out = []; $last = null;
    foreach (t10_cycles($F, $a, $b, $sg, $A5, $RX, $zb) as [$cs, $ce]) {
        $ev = t10_cycle_eval($F, $a, $cs, $ce, $sg, $A5, $RX, $Lo, $U, $zb, $M30, $fly, $gate, $lv, $now);
        if (($ev[$key] ?? null) !== null && $ce < $b) {
            $ev = t10_cycle_eval($F, $a, $cs, $b, $sg, $A5, $RX, $Lo, $U, $zb, $M30, $fly, $gate, $lv, $now);
            $ev['frozen'] = true;
        }
        $out[] = $ev; $last = [$cs, $ev];
    }
    // Re-Fly (owner, 4 Nov 2026): after the entry is frozen, the first confirmed retest that comes later restarts
    // the tick search. If that gives a new entry it is a separate cycle marked 'refly' (shown as "Re-Fly" on Tier 1).
    if ($last !== null && ($last[1][$key] ?? null) !== null) {
        [$cs0, $ev0] = $last;
        foreach (t10_retest_idx($F, $cs0, $b, $sg, $A5, $RX, $zb) as $r) {
            if ((int)$F['t'][$r] + 300 <= (int)$ev0[$key]) continue;   // a retest before the entry does not count
            $re = t10_cycle_eval($F, $a, $cs0, $b, $sg, $A5, $RX, $Lo, $U, $zb, $M30, $fly, $gate, $lv, $now, $r);
            if (($re[$key] ?? null) !== null) { $re['refly'] = true; $re['frozen'] = false; $out[] = $re; }
            break;   // only the first retest after the entry is considered
        }
    }
    return $out;
}

/**
 * FVG tick for a FIXED zone level (retracement; trend uses the running-extreme version inside t10_cycle_eval).
 * 5m 3-candle imbalance near $zone: CALL (sg>0) = low[i] > high[i-2]; PUT (sg<0) = high[i] < low[i-2]. "Near" = within
 * 1 x ATR5 of $zone (same entry-range definition as the other ticks). ON as soon as the 3rd candle closes, no
 * mitigation wait (owner 13 Oct 2026). Returns the latest qualifying bar's [time, price] in [$a,$b], or null.
 */
function t10_fvg_zone(array $F, int $a, int $b, int $sg, array $A5, float $zone, int $now): ?array {
    $b = min($b, count($F['t']) - 1); while ($b >= $a && (int)$F['t'][$b] + 300 > $now) $b--;
    $found = null;
    for ($i = max($a, 2); $i <= $b; $i++) {
        $px = $sg > 0 ? (float)$F['l'][$i] : (float)$F['h'][$i];
        if (abs($px - $zone) > T10_ENTRY_ATR * (float)$A5[$i]) continue;
        $gap = $sg > 0 ? (float)$F['l'][$i] - (float)$F['h'][$i - 2] : (float)$F['l'][$i - 2] - (float)$F['h'][$i];
        if ($gap > 0) $found = [(int)$F['t'][$i] + 300, (float)$F['c'][$i]];
    }
    return $found;
}
/** v10 score (trend). $x: wantUp, zone, atr5, pdX, fibs[] (prices), pivots[] (prices), pdc, pw, bb, stoch, rsi5, adx, pdi, mdi, al5. */
function t10_score(array $x): array {
    $up = $x['wantUp']; $z = $x['zone']; $a5 = max(1e-12, (float)$x['atr5']);
    $dP = abs($x['pdX'] - $z); $dF = INF; $fN = null; foreach ($x['fibs'] as $f) if (abs($f - $z) < $dF) { $dF = abs($f - $z); $fN = $f; }
    // owner, 4 Nov 2026: the location bonus now covers EVERY major strong zone we treat as "major" elsewhere
    // (same set as the Tier-2 Key Zone line) -- PDH/PDL, Fib, Pivot, PDC -- not PDH/PDL + Fib alone.
    $dPv = INF; foreach (($x['pivots'] ?? []) as $pv) { $d = abs((float)$pv - $z); if ($d < $dPv) $dPv = $d; }
    $dC = !empty($x['pdc']) ? abs((float)$x['pdc'] - $z) : INF;
    $best = min($dP, $dF, $dPv, $dC);
    $loc = $best <= 1.5 * $a5 ? 20.0 : 0.0;
    $locB = ($dP <= $a5 && $dF <= $a5 && $fN !== null && abs($x['pdX'] - $fN) <= $a5) ? 10.0 : 0.0;
    $rev = $x['pw'] === 2 ? 27.0 : ($x['pw'] === 1 ? 16.0 : 0.0);
    $bb = $x['bb'] ? 8.0 : 0.0;
    $k = $x['stoch']; $st = 0.0;
    if ($k !== null) $st = $up ? ($k <= 20 ? 10.0 : ($k <= 30 ? 5.0 : 0.0)) : ($k >= 80 ? 10.0 : ($k >= 70 ? 5.0 : 0.0));
    $r = $x['rsi5']; $rs = 0.0;
    if ($r !== null) $rs = $up ? ($r <= 30 ? 5.0 : ($r <= 40 ? 2.0 : 0.0)) : ($r >= 70 ? 5.0 : ($r >= 60 ? 2.0 : 0.0));
    $diOk = $up ? $x['pdi'] > $x['mdi'] : $x['mdi'] > $x['pdi'];
    $adx = !$diOk ? 0.0 : ($x['adx'] >= 25 ? 10.0 : ($x['adx'] >= 20 ? 5.0 : 0.0));
    $mtf = $x['al5'] === 4 ? 10.0 : 0.0;   // owner, 19 Oct 2026: 4/4 on the 4-TF bias (30m is out of the vote)
    $parts = ['loc' => $loc, 'loc_bonus' => $locB, 'retest' => $rev, 'bb' => $bb, 'stoch' => $st, 'rsi5' => $rs, 'adx30' => $adx, 'mtf4' => $mtf];
    return [min(100.0, array_sum($parts)), $parts];
}

/**
 * Trend v10 for one symbol (called from t_evaluate). Returns null when this is not a v10 trend case, else the fields to merge
 * into the row: signal / setup / flow / tk / pw / al5 / strength / flow_sl / flow_at / components_v10.
 */
function t10_eval(string $sym, array $fr, array $tfDirs, array $ctx): ?array {
    if (!T10_ON) return null;
    $F5 = $fr['5m'] ?? null; $now = t_now();
    $m30 = t10_m30($F5, $now);
    [$bias, $al5] = t10_bias($tfDirs, $m30);
    if ($bias === 'NEUTRAL') return ['al5' => $al5, 'bias5' => $bias, 'trend' => false];
    $wantUp = $bias === 'UP'; $sg = $wantUp ? 1 : -1;
    $zone = $wantUp ? $ctx['atr_low'] : $ctx['atr_high'];
    $base = ['al5' => $al5, 'bias5' => $bias, 'trend' => true, 'sig' => $wantUp ? 'CALL' : 'PUT', 'zone10' => $zone];
    $bars = $ctx['bars']; $a5 = (float)$ctx['atr5'];
    if (!$bars || $a5 <= 0) return $base + ['valid' => false];
    $price = (float)$ctx['price'];
    $pdh = (float)$ctx['pdh']; $pdl = (float)$ctx['pdl']; $rg = $pdh - $pdl;
    $pdX = abs($pdh - $zone) <= abs($pdl - $zone) ? $pdh : $pdl;
    $fibs = []; foreach ([0.618, 0.705, 0.786] as $lv) $fibs[] = $wantUp ? $pdh - $rg * $lv : $pdl + $rg * $lv;   // prev-day Fib zones (pullback side)
    $inRange = abs($price - $zone) <= T10_ENTRY_ATR * $a5 && ($wantUp ? $price >= $zone - $ctx['band'] : $price <= $zone + $ctx['band']);
    // Zone-side guard (25 Oct 2026): when the day range fits inside 2×ATR5 both zones satisfy $inRange. A trend
    // signal must be nearer its own zone than the opposite one — CALL nearer Day Low, PUT nearer Day High.
    $oppZone = $wantUp ? $ctx['atr_high'] : $ctx['atr_low'];
    if ($inRange && $oppZone > 0 && abs($price - $zone) > abs($price - $oppZone)) $inRange = false;
    $touch = $wantUp ? $ctx['atLow'] : $ctx['atHigh'];
    // Owner, 20 Oct 2026: after an entry everything freezes, so a symbol with an open Tier-2 trade is still
    // evaluated even when price has walked out of the entry range.
    if (!$inRange && !$touch && !t10_open_trade($sym)) return $base + ['valid' => false];
    $M1 = $fr['1m'] ?? t_m1_get($sym);
    // Zone-group levels the 30m Liquidity Sweep is allowed to sweep (besides the running Day extreme).
    $glv = [$wantUp ? 'Prev Low' : 'Prev High' => $wantUp ? $pdl : $pdh];
    $fN = null; foreach ($fibs as $f) if ($fN === null || abs($f - $zone) < abs($fN - $zone)) $fN = $f;
    if ($fN !== null) $glv['Fib'] = $fN;
    $pvN = null; foreach (($ctx['pivots'] ?? []) as $pv) if ($pvN === null || abs((float)$pv - $zone) < abs($pvN - $zone)) $pvN = (float)$pv;
    if ($pvN !== null) $glv['Pivot'] = $pvN;
    $C = t10_session($F5, $bars[0], $bars[count($bars) - 1], $sg, $M1, (float)$ctx['zb'], $now, $ctx['adr'] ?? null, $glv, false);
    $cy = t10_pick($C, $sym, false);
    $s30 = t10_stoch_k($m30);
    $c5 = t10_closed($F5, 300, $now); $rsi5 = count($c5['c']) >= 20 ? t_rsi_last(array_map('floatval', array_slice($c5['c'], -200)), 14) : null;
    [$ax, $pd, $md] = $m30 ? t_adx($m30, 14) : [0.0, 0.0, 0.0];
    // Find nearest major zone to $zone for Tier-2 chart line
    $mjCandidates = ['PDH' => $pdh, 'PDL' => $pdl];
    if ($ctx['pdc'] ?? null) $mjCandidates['PDC'] = (float)$ctx['pdc'];
    // nearest fib
    $fNear = null; foreach ($fibs as $f) if ($fNear === null || abs($f - $zone) < abs($fNear - $zone)) $fNear = $f;
    if ($fNear !== null) $mjCandidates['Fib'] = $fNear;
    // nearest pivot
    $pvNear = null; foreach (($ctx['pivots'] ?? []) as $k => $v) if ($pvNear === null || abs((float)$v - $zone) < abs($pvNear - $zone)) { $pvNear = (float)$v; $pvNearLabel = $k; }
    if ($pvNear !== null) $mjCandidates['Pivot'] = $pvNear;
    $mjLabel = null; $mjPrice = null;
    foreach ($mjCandidates as $lbl => $price) {
        if ($mjPrice === null || abs($price - $zone) < abs($mjPrice - $zone)) { $mjPrice = $price; $mjLabel = $lbl; }
    }
    [$score, $parts] = t10_score(['wantUp' => $wantUp, 'zone' => $zone, 'atr5' => $a5, 'pdX' => $pdX, 'fibs' => $fibs,
        'pivots' => array_values($ctx['pivots'] ?? []), 'pdc' => $ctx['pdc'] ?? null,
        'pw' => $cy['pw'] ?? 0, 'bb' => $cy['bb'] ?? false, 'stoch' => $s30, 'rsi5' => $rsi5, 'adx' => $ax, 'pdi' => $pd, 'mdi' => $md, 'al5' => $al5]);
    $tk = $cy ? array_map(fn($t) => $t === null ? 0 : 1, $cy['ticks']) : [0, 0, 0, 0, 0];
    $tkAt = $cy ? array_map(fn($t) => $t === null ? 0 : (int)$t, $cy['ticks']) : [0, 0, 0, 0, 0];   // raw fire times, for the UI to show "when" next to a checked tick
    $entryAt = $cy['first'] ?? null; $ticksFired = $entryAt !== null;
    // bug fix (13 Oct 2026): a fired tick must stay shown while price is still in range (runbook v10 S6: "FLY ... stays
    // shown; it does not disappear"). Before, $valid was score-gated only, so a tick already ON could vanish from the
    // scanner the instant a later bar's score dipped under 33 -- the cycle's own ticks are sticky, so entry status
    // should be too. Score still gates a *fresh* APPROACHING/SIGNAL; it no longer hides an entry already in progress.
    $valid = ($score >= T10_MIN_SCORE && $parts['loc'] > 0) || $ticksFired;
    // Status ladder: APPROACHING -> GATE OPEN -> TICK 1/2/3 (SIGNAL) -> TICK 4 (FLY), and GATE CLOSE
    // only when no entry was ever taken and the gate expired unused. Tick 4 = Double Confirm = FLY.
    $gateOn = ($cy['gate'] ?? null) !== null;
    $flow = !$valid ? null
        : ($tk[4] && $ticksFired ? 'FLY' : ($ticksFired ? 'SIGNAL'
        : ($gateOn ? (!empty($cy['gate_exp']) ? 'GATE CLOSE' : 'GATE OPEN') : 'APPROACHING')));
    $go = $gateOn ? 1 : 0;
    // Tier-1 zone group + remark (owner PDF, 19 Oct 2026). Pivot and PDC count here only, never in the score.
    [$zg, $zlv] = t10_zone_group($zone, (float)($ctx['near'] ?? 0) > 0 ? (float)$ctx['near'] : (float)$ctx['band'],
        ['fibs' => $fibs, 'pivots' => $ctx['pivots'] ?? [], 'pdh' => $pdh, 'pdl' => $pdl, 'pdc' => $ctx['pdc'] ?? null]);
    return $base + ['valid' => $valid, 'strength' => round($score, 2), 'parts' => $parts, 'flow' => $flow, 'refly' => !empty($cy['refly']), 'tk' => $tk, 'tk_at' => $tkAt, 'go' => $go,
        't0' => $cy['t0'] ?? null, 'zg' => $zg, 'zlv' => $zlv, 'lad' => 10,
        'gate_lv' => $cy['gate_level'] ?? null, 'sl_wide' => !empty($cy['wide']) ? 1 : 0,
        'pw' => (int)($cy['pw'] ?? 0), 'flow_at' => $entryAt, 'flow_sl' => $cy['sl'] ?? null,
        'stoch30' => $s30 === null ? null : round($s30, 1), 'rsi5' => $rsi5 === null ? null : round($rsi5, 1), 'adx30' => round($ax, 1),
        'mzp' => $mjPrice !== null ? round($mjPrice, 4) : null, 'mzl' => $mjLabel];
}

/** Next $n Fibonacci daily pivots beyond $px in the trade direction: [[label, price], ...]. */
function t10_targets(?array $P, float $px, int $sg, int $n = 3): array {
    if (!$P) return [];
    $L = []; foreach ($P as $k => $v) { $d = ((float)$v - $px) * $sg; if ($d > 0) $L[$k] = $d; }
    asort($L); $o = [];
    foreach ($L as $k => $d) { if (count($o) >= $n) break; $o[] = ['label' => $k, 'price' => $px + $sg * $d]; }
    return $o;
}
/** 10m exit check after entry: Supertrend (10,3) flips against the trade, or a reverse pattern confirmed by the next 10m candle.
 *  $G = 10m bars (several days, anchored to the session), returns [time, price, reason] of the first exit after $te, or null. */
function t10_exit_10m(array $G, int $te, int $sg, int $now): ?array {
    $m = count($G['t']); if ($m < 12) return null;
    [$D] = t_supertrend($G, 10, 3.0);
    for ($j = 1; $j < $m; $j++) {
        $tc = (int)$G['t'][$j] + 600; if ($tc > $now) break; if ($tc <= $te) continue;
        if ($D[$j] === -$sg && $D[$j - 1] === $sg) return [$tc, (float)$G['c'][$j], '10m trend flip'];
        if ($j >= 1 && (int)$G['t'][$j - 1] + 600 > $te && t_reversal($G, $j - 1, -$sg) && t_fly_confirm($G, $j - 1, -$sg)) return [$tc, (float)$G['c'][$j], '10m reverse pattern'];
    }
    return null;
}
/**
 * Tier-2 trades of one session (v10) -- BOTH setups since 19 Oct 2026, because retracement now runs the same
 * cycle and the same 5 ticks as trend. Entry = the first tick of each cycle (price = that bar's close).
 * SL = 1.5% from the position line. Targets = the next Fibonacci pivots: 3 for trend, 1 for retracement.
 * Exit = SL, or the 8m Supertrend flip / 8m 5-EMA cross, or session end -- and for retracement also Fib TP1.
 * $epFor(sig, time) returns the Tier-1 episode or null.
 */
function t10_replay(array $F, ?array $M1, int $a, int $b, callable $epFor, ?array $P, int $now, bool $running): array {
    $out = [];
    if (!$M1 || !$P) return $out;
    $n1 = count($M1['t']); if ($n1 < 30) return $out;
    $dayTs = (int)$F['t'][$a]; $endTs = (int)$F['t'][$b] + 300;
    $G8 = t10_eight($M1, $dayTs, $now);   // owner, 19 Oct 2026: exit moved from 10m to 8m (built from 1m bars)
    $first = function (array $t, int $x): int { $lo = 0; $hi = count($t) - 1; $r = count($t); while ($lo <= $hi) { $m = intdiv($lo + $hi, 2); if ($t[$m] >= $x) { $r = $m; $hi = $m - 1; } else $lo = $m + 1; } return $r; };
    $busy = 0;
    foreach (['CALL' => 1, 'PUT' => -1] as $sig => $sg) {
        $lvP = []; foreach (($P ?? []) as $pk => $pv) $lvP[(string)$pk] = (float)$pv;
        foreach (t10_session($F, $a, $b, $sg, $M1, T2_ZB, $now, null, $lvP, false) as $cy) {
            $te = $cy['first']; if ($te === null) continue;
            $ep = $epFor($sig, $te); if (!$ep) continue;
            $isTrend = ($ep['setup'] ?? null) === 'trend';
            if (($ep['setup'] ?? null) !== 'trend' && ($ep['setup'] ?? null) !== 'retrace') continue;
            // Retracement uses its own entry: the gate has to be open first and the 5 EMA alone never enters.
            if (!$isTrend) { $te = $cy['first_rt']; if ($te === null) continue; $ep = $epFor($sig, $te); if (!$ep) continue; }
            if ($te <= $busy) continue;
            $pe = (float)($isTrend ? $cy['first_px'] : $cy['first_rt_px']);
            $slC = $isTrend ? $cy['sl'] : $cy['sl_rt'];
            $sl = $slC !== null ? (float)$slC : ($sg > 0 ? $pe * (1 - T10_SL_PCT) : $pe * (1 + T10_SL_PCT));
            $risk = abs($pe - $sl);
            if ($risk <= 1e-12 || ($pe - $sl) * $sg <= 0) continue;
            $tg = t10_targets($P, $pe, $sg, $isTrend ? 3 : 1); $tp1 = $tg[0]['price'] ?? $pe + $sg * $risk;
            $ex = null; $exT = null; $why = null; $touched = false; $tgT = null; $hit = [];
            $x10 = t10_exit_8m($G8, $te, $sg, $now);
            for ($x = $first($M1['t'], $te); $x < $n1 && $M1['t'][$x] < $endTs; $x++) {
                $tx = (int)$M1['t'][$x] + 60; if ($tx > $now) break;
                if ($x10 && $tx > $x10[0]) { [$exT, $ex, $why] = $x10; break; }
                $l = (float)$M1['l'][$x]; $h = (float)$M1['h'][$x];
                if ($sg > 0 ? $l <= $sl : $h >= $sl) { $ex = $sl; $exT = $tx; $why = 'Stop-loss'; break; }
                foreach ($tg as $q => $t) if (!isset($hit[$q]) && ($sg > 0 ? $h >= $t['price'] : $l <= $t['price'])) $hit[$q] = $tx;
                if (!$touched && isset($hit[0])) { $touched = true; $tgT = $hit[0];
                    // owner PDF, 19 Oct 2026: retracement has ONE target and the trade ends there. Trend's
                    // targets stay display levels -- it exits on the SL or the 8m rules only.
                    if (!$isTrend) { $ex = $tp1; $exT = $tx; $why = 'Fib TP1'; break; } }
            }
            if ($ex === null && $x10 && $x10[0] <= $now) [$exT, $ex, $why] = $x10;
            if ($ex === null && !$running) { $ex = (float)$F['c'][$b]; $exT = $endTs; $why = 'Session end'; }
            $px = $ex ?? (float)$M1['c'][$n1 - 1];
            $mfe = 0.0; $mae = 0.0;
            for ($x = $first($M1['t'], $te); $x < $n1 && $M1['t'][$x] < ($exT ?? $endTs); $x++) {
                $mfe = max($mfe, $sg > 0 ? (float)$M1['h'][$x] - $pe : $pe - (float)$M1['l'][$x]);
                $mae = max($mae, $sg > 0 ? $pe - (float)$M1['l'][$x] : (float)$M1['h'][$x] - $pe);
            }
            $R = fn($v) => round($v / $risk, 2); $P2 = fn($v) => round($v / $pe * 100, 4);
            $out[] = ['sig' => $sig, 'pattern' => 'Ticks', 'pattern_time' => $te, 'entry_time' => $te, 'entry' => t_r($pe), 'sl0' => t_r($sl), 'sl' => t_r($sl),
                'target' => t_r($tp1), 'target_label' => $tg[0]['label'] ?? '1R',
                'targets_more' => array_map(fn($t) => ['label' => $t['label'], 'price' => t_r($t['price'])], array_slice($tg, 1)),
                'targets_hit' => array_map(fn($q) => $hit[$q] ?? null, array_keys($tg)),
                'touched' => $touched, 'status' => $ex === null ? 'open' : 'closed', 'exit_time' => $exT, 'exit' => $ex === null ? null : t_r($ex),
                'reason' => $why, 'last' => t_r($px), 'pct' => round(($px - $pe) * $sg / $pe * 100, 4), 'r' => round(($px - $pe) * $sg / $risk, 2),
                'signal_time' => $ep['s'] ?? null, 'signal_price' => $ep['p'] ?? null, 'alignment' => $ep['al'] ?? null,
                'setup' => $isTrend ? 'trend' : 'retrace', 'remark' => $ep['remark'] ?? ($isTrend ? 'Long target' : 'Small target'),
                'confirm_time' => $te, 'confirm_close' => t_r($pe), 'day_ext' => t_r((float)$cy['ext']), 'atr5' => null, 'grade' => null, 'runway_type' => null, 'runway_time' => null,
                'rules' => 10, 'ticks' => $cy['ticks'], 'pw' => $cy['pw'], 'target_time' => $tgT,
                'mfe_pct' => $P2($mfe), 'mfe_r' => $R($mfe), 'mae_pct' => $P2($mae), 'mae_r' => $R($mae), 'run_pct' => $P2($mfe), 'run_r' => $R($mfe), 'run_time' => null,
                'pivots_crossed' => [], 'after_sl_r' => null, 'target_after_sl' => null, 'ema5_cross' => null, 'pattern_bar' => null];
            $busy = $ex === null ? PHP_INT_MAX : $exT;
        }
    }
    usort($out, fn($x, $y) => $x['entry_time'] <=> $y['entry_time']);
    return $out;
}

/** A Tier-2 trade of this symbol (either setup) that closed in the last GATE CLOSE window (for Tier 1). */
function t10_recent_exit(string $sym, int $now): ?array {
    static $M = null;
    if ($M === null) { $M = [];
        $j = t_read_json(T_DATA . '/t2trades.json', ['items' => []]);
        foreach (($j['items'] ?? []) as $t) {
            if (($t['status'] ?? '') !== 'closed' || (int)($t['rules'] ?? 0) < 10) continue;   // owner, 19 Oct 2026: both setups
            if ($now - (int)($t['exit_time'] ?? 0) > T10_GATE_HOLD) continue;
            $M[(string)$t['symbol']] = $t;
        } }
    return $M[$sym] ?? null;
}


/** Retracement v2 score (owner PDF "Retracement Setup Notes" v2, 9 Oct 2026). Kept on the OLD 4-TF $alignment
 * (not migrated to 5-TF). Max 100: Location 46 (31 full-proximity / 15 half-proximity + 15 confluence bonus),
 * Exhaustion 23 (30m Stochastic graded toward exhaustion on the trade side), Trend Kamzori 31 (30m ADX INVERSE
 * 0/5/10/15 at >=25/20-25/15-20/<=15 -- weak trend favours mean-reversion -- + MTF inverse bonus on the kept
 * 4-TF scale: 3/4 align = 16, 4/4 align = 0). Sweep is NOT part of this score and never gates (owner, 13 Oct
 * 2026: "30m sweep mandatory nahi - gate open hai") -- pw/bb stay RETEST++ / GATE OPEN remarks only, same
 * treatment as trend's GATE OPEN. Valid: score >= T_RT_MIN_SCORE and inside the zone (caller's $inZone test).
 */
function t_rt_score(array $x): array {
    $up = $x['wantUp'];
    $loc = $x['locFull'] ? 31.0 : ($x['locHalf'] ? 15.0 : 0.0);
    $locB = $x['conf'] ? 15.0 : 0.0;
    $k = $x['stoch30']; $exh = 0.0;
    if ($k !== null) {
        $g = $up ? (100 - $k) : $k;   // distance toward the exhaustion side, 0..100
        $exh = $g >= 80 ? 23.0 : ($g >= 60 ? 17.0 : ($g >= 40 ? 12.0 : ($g >= 20 ? 6.0 : 0.0)));
    }
    $ax = (float)$x['adx30'];
    $adxInv = $ax >= 25 ? 0.0 : ($ax >= 20 ? 5.0 : ($ax >= 15 ? 10.0 : 15.0));
    $mtfInv = $x['alignment'] === 3 ? 16.0 : 0.0;   // 4/4 = 0, 3/4 = 16 (kept old 4-TF alignment, owner: "bias purana rakhna hai, only 4")
    $parts = ['loc' => $loc, 'loc_bonus' => $locB, 'exhaustion' => $exh, 'adx30_inv' => $adxInv, 'mtf4_inv' => $mtfInv];
    return [min(100.0, array_sum($parts)), $parts];
}
const T_RT_MIN_SCORE = 30.0;   // owner, 3 Nov 2026 (was 33): lowered to 30 for more visibility on Tier 1

/**
 * Retracement v10 (owner PDF "Final Setup", 19 Oct 2026). The retracement setup now runs the SAME cycle machinery
 * and the SAME 5 entry ticks as trend -- it no longer uses the old v8 RUNWAY / FLY engine. What stays different:
 *   - direction: taken AGAINST the MTF bias (bullish bias -> PUT at the Day High, bearish -> CALL at the Day Low)
 *   - score: t_rt_score (Location 46 / Exhaustion 23 / Trend-weakness 31), valid at T_RT_MIN_SCORE (33) or more
 *   - the 30m sweep is NOT a gate: it is tick 0, and on Tier 1 / Tier 2 it shows as the GATE OPEN remark
 *   - 1 target (the next Fib pivot), where trend has 3
 * SL (1.5%) and the 8m exit are the same as trend. Returns the fields to merge into the row, or null.
 */
function t_rt_eval(string $sym, array $fr, array $ctx): ?array {
    if (!T10_ON) return null;
    $F5 = $fr['5m'] ?? null; $now = t_now();
    $bars = $ctx['bars']; $a5 = (float)$ctx['atr5'];
    if (!$F5 || !$bars || $a5 <= 0) return null;
    $wantUp = (bool)$ctx['wantUp']; $sg = $wantUp ? 1 : -1; $zone = (float)$ctx['zone'];
    $M1 = $fr['1m'] ?? t_m1_get($sym);
    $glv = [$wantUp ? 'Prev Low' : 'Prev High' => $wantUp ? ($ctx['pdl'] ?? null) : ($ctx['pdh'] ?? null)];
    $fN = null; foreach (($ctx['fibs'] ?? []) as $f) if ($fN === null || abs((float)$f - $zone) < abs($fN - $zone)) $fN = (float)$f;
    if ($fN !== null) $glv['Fib'] = $fN;
    $pvN = null; foreach (($ctx['pivots'] ?? []) as $pv) if ($pvN === null || abs((float)$pv - $zone) < abs($pvN - $zone)) $pvN = (float)$pv;
    if ($pvN !== null) $glv['Pivot'] = $pvN;
    $C = t10_session($F5, $bars[0], $bars[count($bars) - 1], $sg, $M1, (float)$ctx['zb'], $now, $ctx['adr'] ?? null, $glv, true);
    $cy = t10_pick($C, $sym, true);
    $m30 = t10_m30($F5, $now);
    [$ax] = $m30 ? t_adx($m30, 14) : [0.0, 0.0, 0.0];
    $s30 = t10_stoch_k($m30);
    [$score, $parts] = t_rt_score(['wantUp' => $wantUp, 'zone' => $zone, 'atr5' => $a5, 'pdX' => (float)$ctx['pdX'],
        'locFull' => (bool)$ctx['locFull'], 'locHalf' => (bool)$ctx['locHalf'], 'conf' => (bool)$ctx['conf'],
        'stoch30' => $s30, 'adx30' => $ax, 'alignment' => (int)$ctx['alignment']]);
    $tk = $cy ? array_map(fn($t) => $t === null ? 0 : 1, $cy['ticks']) : [0, 0, 0, 0, 0];
    $tkAt = $cy ? array_map(fn($t) => $t === null ? 0 : (int)$t, $cy['ticks']) : [0, 0, 0, 0, 0];   // raw fire times, for the UI to show "when" next to a checked tick
    // Retracement entry (owner, 20 Oct 2026): the gate HAS to be open first, and the 5 EMA alone is confirm
    // only -- so the entry is the first Early Entry / Reversal Pat / Double Confirm at or after the gate.
    $entryAt = $cy['first_rt'] ?? null; $ticksFired = $entryAt !== null;
    $valid = ($score >= T_RT_MIN_SCORE && !empty($ctx['locHalf'])) || $ticksFired;
    $gateOn = ($cy['gate'] ?? null) !== null;
    $flow = !$valid ? null
        : ($tk[4] && $ticksFired ? 'FLY' : ($ticksFired ? 'SIGNAL'
        : ($gateOn ? (!empty($cy['gate_exp']) ? 'GATE CLOSE' : 'GATE OPEN') : 'APPROACHING')));
    $go = $gateOn ? 1 : 0;
    [$zg, $zlv] = t10_zone_group($zone, (float)($ctx['near'] ?? 0) > 0 ? (float)$ctx['near'] : (float)$ctx['band'],
        ['fibs' => $ctx['fibs'] ?? [], 'pivots' => $ctx['pivots'] ?? [], 'pdh' => $ctx['pdh'] ?? null,
         'pdl' => $ctx['pdl'] ?? null, 'pdc' => $ctx['pdc'] ?? null]);
    return ['valid' => $valid, 'strength' => round($score, 2), 'parts' => $parts, 'flow' => $flow, 'tk' => $tk, 'tk_at' => $tkAt,
        'go' => $go, 't0' => $cy['t0'] ?? null, 'zg' => $zg, 'zlv' => $zlv, 'pw' => (int)($cy['pw'] ?? 0),
        'lad' => 10, 'gate_lv' => $cy['gate_level'] ?? null, 'sl_wide' => !empty($cy['wide_rt']) ? 1 : 0,
        'flow_at' => $entryAt, 'flow_sl' => $cy['sl_rt'] ?? null,
        'stoch30' => $s30 === null ? null : round($s30, 1), 'adx30' => round($ax, 1)];
}

/** 8m exit check for retracement (owner, 13 Oct 2026, "short settings"): 8m Supertrend (10,3) flips against the
 * trade, or an 8m reverse pattern confirmed by the next 8m candle close. Mirrors t10_exit_10m but on 8m bars
 * built from the 5m feed via t_resample. $F5 = 5m bars, $te = entry time, $sg = trade sign (+1 CALL / -1 PUT). */
function t_rt_exit_8m(array $F5, int $te, int $sg, int $now): ?array {
    if (!$F5 || count($F5['t'] ?? []) < 30) return null;
    $G = t10_closed(t_resample($F5 + ['v' => array_fill(0, count($F5['t']), 0)], 480), 480, $now);
    $n = count($G['c'] ?? []); if ($n < 12) return null;
    [$D] = t_supertrend($G, 10, 3.0); $tc = null;
    for ($j = 1; $j < $n; $j++) {
        $tc = (int)$G['t'][$j] + 480; if ($tc <= $te) continue;
        if ($D[$j] === -$sg && $D[$j - 1] === $sg) return [$tc, (float)$G['c'][$j], '8m supertrend reversal'];
        if ($j >= 1 && t_reversal($G, $j - 1, -$sg) && t_fly_confirm($G, $j - 1, -$sg)) return [$tc, (float)$G['c'][$j], '8m reverse pattern'];
    }
    return null;
}

/* ============================================================================
   Mid-Trend setup (owner PDF "Major Support / Resi: Complete Strategy", 23 Oct 2026).
   Fires AFTER a Retracement TP1 hit. Entry at a fresh Major Support (CALL) or
   Major Resistance (PUT) zone that qualifies as STRONG_ZONE or better.
   ============================================================================ */

/**
 * Mid-Trend score. $x: wantUp, zoneLvCount, stoch30, adx30, al5.
 * Location: STRONG_ZONE+ (3+ levels within 0.35xATR5) = 30 pts; STRONG_ZONE (2+ levels) = 20 pts.
 * Exhaustion: 30m Stochastic graded toward exhaustion side, 0-15 pts.
 * Trend align: al5 4/4 = 15 pts; 3/4 = 8 pts.
 * Minimum T_MT_MIN_SCORE (33) to be valid (STRONG_ZONE alone satisfies it).
 */
function t_mt_score(array $x): array {
    $up = $x['wantUp'];
    $cnt = (int)($x['zoneLvCount'] ?? 0);
    $loc = $cnt >= 3 ? 30.0 : ($cnt >= 2 ? 20.0 : 0.0);
    $k = $x['stoch30'] ?? null; $exh = 0.0;
    if ($k !== null) {
        $g = $up ? (100 - $k) : $k;
        $exh = $g >= 80 ? 15.0 : ($g >= 60 ? 10.0 : ($g >= 40 ? 5.0 : 0.0));
    }
    $al5 = (int)($x['al5'] ?? 0);
    $trend = $al5 >= 4 ? 15.0 : ($al5 >= 3 ? 8.0 : 0.0);
    $parts = ['loc' => $loc, 'exhaustion' => $exh, 'trend_align' => $trend];
    return [min(100.0, array_sum($parts)), $parts];
}
const T_MT_MIN_SCORE = 30.0;

/**
 * Mid-Trend v1 (owner PDF 23 Oct 2026). Same cycle machinery as Trend/Retracement.
 * Gate: $ctx['mt_active'] = true (engine sets this when Retracement TP1 fires).
 * Zone: nearest major zone with STRONG_ZONE (2+ levels within 0.35xATR5).
 * Bias: 4H+1D both aligned -- checked via $tfDirs['4h'] and $tfDirs['1d'].
 * Tick system: identical to Trend (gate + 3 entry ticks, lad=10).
 * setup = 'midtrend'; signal = "Major Support" (CALL) / "Major Resistance" (PUT).
 * Returns same field structure as t_rt_eval. Returns null when gate not active or zone not found.
 */
function t_mt_eval(string $sym, array $fr, array $tfDirs, array $ctx): ?array {
    if (!T10_ON) return null;
    if (empty($ctx['mt_active'])) return null;   // engine sets this when ATR band is cleared
    $F5 = $fr['5m'] ?? null; $now = t_now();
    $bars = $ctx['bars']; $a5 = (float)$ctx['atr5'];
    if (!$F5 || !$bars || $a5 <= 0) return null;
    $m30 = t10_m30($F5, $now);
    [$bias, $al5] = t10_bias($tfDirs, $m30);
    if ($bias === 'NEUTRAL') return null;
    $wantUp = $bias === 'UP'; $sg = $wantUp ? 1 : -1;
    // 4H+1D alignment gate (both must agree with bias direction)
    $d4h = $tfDirs['4h'] ?? 0; $d1d = $tfDirs['1d'] ?? 0;
    if ($d4h !== $sg || $d1d !== $sg) return null;
    // Zone anchor: nearest strong zone BEYOND the ATR band (3 Nov 2026 redesign).
    // Price has already cleared the ATR band (gate in engine). Find the closest pivot/fib/PDH/PDL/PDC
    // that is: (a) on the far side of the band (below atr_low for CALL, above atr_high for PUT),
    // and (b) within 1.0×ATR5 of current price.
    $price    = (float)$ctx['price'];
    $atrLow   = (float)($ctx['atr_low']  ?? 0);
    $atrHigh  = (float)($ctx['atr_high'] ?? 0);
    $lvAll    = array_merge(
        array_values($ctx['pivots'] ?? []),
        array_values($ctx['fibs']   ?? []),
        array_filter([(float)($ctx['pdh'] ?? 0), (float)($ctx['pdl'] ?? 0), (float)($ctx['pdc'] ?? 0)])
    );
    $zone = null; $bestDist = PHP_FLOAT_MAX;
    foreach ($lvAll as $lv) {
        $lv = (float)$lv;
        if ($lv <= 0) continue;
        // Must be beyond the ATR band in the trade direction
        if ($wantUp && $lv >= $atrLow)  continue;   // CALL: zone must be below atr_low
        if (!$wantUp && $lv <= $atrHigh) continue;  // PUT: zone must be above atr_high
        $dist = abs($price - $lv);
        if ($dist < $bestDist) { $bestDist = $dist; $zone = $lv; }
    }
    if ($zone === null) return null;                 // no strong zone found beyond band
    if (abs($price - $zone) > 1.0 * $a5) return null; // zone must be within 1×ATR5
    // Count qualifying levels within 0.35xATR5 of zone (STRONG_ZONE gate)
    $tol = 0.35 * $a5;
    $lvArr = array_merge(
        array_values($ctx['pivots'] ?? []),
        array_values($ctx['fibs']   ?? []),
        array_filter([$ctx['pdh'] ?? null, $ctx['pdl'] ?? null, $ctx['pdc'] ?? null])
    );
    $cnt = 0; $glv = [];
    foreach ($lvArr as $lv) {
        if ((float)$lv <= 0) continue;
        if (abs((float)$lv - $zone) <= $tol) { $cnt++; $glv[] = (float)$lv; }
    }
    if ($cnt < 2) return null;   // STRONG_ZONE requires at least 2 levels
    $M1 = $fr['1m'] ?? t_m1_get($sym);
    $glvMap = []; foreach ($glv as $i => $v) $glvMap['ML' . ($i + 1)] = $v;
    $C = t10_session($F5, $bars[0], $bars[count($bars) - 1], $sg, $M1, (float)$ctx['zb'], $now, $ctx['adr'] ?? null, $glvMap, false);
    $cy = t10_pick($C, $sym, false);
    $s30 = t10_stoch_k($m30);
    [$ax] = $m30 ? t_adx($m30, 14) : [0.0, 0.0, 0.0];
    [$score, $parts] = t_mt_score(['wantUp' => $wantUp, 'zoneLvCount' => $cnt, 'stoch30' => $s30, 'adx30' => $ax, 'al5' => $al5]);
    $tk = $cy ? array_map(fn($t) => $t === null ? 0 : 1, $cy['ticks']) : [0, 0, 0, 0, 0];
    $entryAt = $cy['first'] ?? null; $ticksFired = $entryAt !== null;
    $valid = ($score >= T_MT_MIN_SCORE && $cnt >= 2) || $ticksFired;
    $gateOn = ($cy['gate'] ?? null) !== null;
    $flow = !$valid ? null
        : ($tk[4] && $ticksFired ? 'FLY' : ($ticksFired ? 'SIGNAL'
        : ($gateOn ? (!empty($cy['gate_exp']) ? 'GATE CLOSE' : 'GATE OPEN') : 'APPROACHING')));
    $go = $gateOn ? 1 : 0;
    [$zg, $zlv] = t10_zone_group($zone, $tol,
        ['fibs' => $ctx['fibs'] ?? [], 'pivots' => $ctx['pivots'] ?? [],
         'pdh' => $ctx['pdh'] ?? null, 'pdl' => $ctx['pdl'] ?? null, 'pdc' => $ctx['pdc'] ?? null]);
    return ['valid' => $valid, 'strength' => round($score, 2), 'parts' => $parts,
        'flow' => $flow, 'tk' => $tk, 'go' => $go,
        't0' => $cy['t0'] ?? null, 'zg' => $zg, 'zlv' => $zlv,
        'pw' => (int)($cy['pw'] ?? 0), 'lad' => 10,
        'gate_lv' => $cy['gate_level'] ?? null, 'sl_wide' => !empty($cy['wide']) ? 1 : 0,
        'flow_at' => $entryAt, 'flow_sl' => $cy['sl'] ?? null,
        'stoch30' => $s30 === null ? null : round($s30, 1), 'adx30' => round($ax, 1),
        'setup' => 'midtrend', 'sig' => $wantUp ? 'CALL' : 'PUT', 'al5' => $al5,
        'mt_zone_cnt' => $cnt];
}
