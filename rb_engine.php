<?php
/**
 * Rubber Band Reversal Scanner (owner, 13 Nov 2026): the "movers" list turned into a scanner.
 * A market that has run far beyond its usual daily range is a stretched rubber band. The scanner lists every
 * live market stretched at least 1x its average daily range and tells whether it has started to snap back:
 *   STRETCHED  the run is still going, price is at (or near) today's extreme: watch only
 *   TURNING    a 15-minute candle turned at the extreme (long tail) or broke the last small swing back the other way
 *   SNAPPING   price has given back at least 25% of the day's move: the reversal is under way
 * Labels (not filters): key zone or trend line at the extreme, RSI stretched, with / against the big trend.
 * Snap-backs WITH the big trend (a down-trend market that spiked up and is turning down) rank first.
 * Plan: stop beyond today's extreme (+0.1 daily ATR), one target = the day's midpoint, or VWAP-like mean.
 */
declare(strict_types=1);
require_once __DIR__ . '/tl_engine.php';

const RB_MIN_STRETCH = 1.0;   // x average daily range
const RB_TTL = 60;
const RB_STAGE = [3 => 'SNAPPING', 2 => 'TURNING', 1 => 'STRETCHED'];

function rb_rsi(array $c, int $p = 14): ?float {
    $n = count($c); if ($n <= $p + 1) return null;
    $g = 0.0; $l = 0.0;
    for ($i = 1; $i <= $p; $i++) { $d = $c[$i] - $c[$i - 1]; if ($d > 0) $g += $d; else $l -= $d; }
    $ag = $g / $p; $al = $l / $p;
    for ($i = $p + 1; $i < $n; $i++) { $d = $c[$i] - $c[$i - 1]; $ag = ($ag * ($p - 1) + max($d, 0)) / $p; $al = ($al * ($p - 1) + max(-$d, 0)) / $p; }
    return $al == 0 ? 100.0 : 100 - 100 / (1 + $ag / $al);
}

function rb_row(string $sym, array $t1, array $raw, ?array $tl, int $now): ?array {
    $fr = t_read_frames($sym); if (!$fr) return null;
    $D = $fr['1D']; $nD = count($D['c'] ?? []); if ($nD < 20) return null;
    $A = t_atr($D, 14); $aD = (float)end($A); if ($aD <= 0) return null;
    $chg = (float)($t1['change_pct'] ?? 0); $atrPct = (float)($t1['atr_pct'] ?? 0); if ($atrPct <= 0) return null;
    $stretch = abs($chg) / $atrPct; if ($stretch < RB_MIN_STRETCH) return null;
    $up = $chg > 0; $side = $up ? 'PUT' : 'CALL'; $sg = $up ? -1 : 1;   // the snap-back goes against today's run
    $px = (float)($t1['price'] ?? end($D['c']));
    $hi = (float)$D['h'][$nD - 1]; $lo = (float)$D['l'][$nD - 1]; $open = (float)$D['o'][$nD - 1];
    $ext = $up ? $hi : $lo; $prev = (float)$D['c'][$nD - 2];
    $move = abs($ext - $prev); if ($move <= 0) return null;
    $given = abs($ext - $px) / $move;                                    // share of the run already given back

    // today's 15m candles (1H where no intraday bars are cached)
    $src = tl_src($fr, $fr['5m'] ?? null); $etf = !empty($src['15m']) && count($src['15m']['c']) >= 30 ? '15m' : '1H';
    $E = $src[$etf]; $ne = count($E['c']); $cs = (int)($raw['cs'] ?? 0);
    $ia = null; for ($i = 0; $i < $ne; $i++) if ((int)$E['t'][$i] >= $cs) { $ia = $i; break; }
    $stage = 1; $turn = null;
    if ($ia !== null && $ne - $ia >= 3) {
        $ix = $ia; for ($i = $ia; $i < $ne; $i++) if ($up ? (float)$E['h'][$i] > (float)$E['h'][$ix] : (float)$E['l'][$i] < (float)$E['l'][$ix]) $ix = $i;
        // a turn: the extreme candle (or the one after it) left a long tail, or a close broke the last small swing back
        for ($i = $ix; $i <= min($ix + 1, $ne - 2); $i++) {
            $o = (float)$E['o'][$i]; $h = (float)$E['h'][$i]; $l = (float)$E['l'][$i]; $c = (float)$E['c'][$i]; $rg = max($h - $l, 1e-12);
            $tail = $up ? ($h - max($o, $c)) / $rg : (min($o, $c) - $l) / $rg;
            if ($tail >= 0.55) { $turn = ($etf === '15m' ? '15-min' : '1-hour') . ' candle left a long tail at the ' . ($up ? 'high' : 'low'); break; }
        }
        if (!$turn) {
            // structure break: a close beyond the low (PUT) / high (CALL) of the extreme candle
            $key = $up ? (float)$E['l'][$ix] : (float)$E['h'][$ix];
            for ($j = $ix + 1; $j < $ne; $j++) if ($up ? (float)$E['c'][$j] < $key : (float)$E['c'][$j] > $key) { $turn = 'a ' . ($etf === '15m' ? '15-min' : '1-hour') . ' candle closed back past the ' . ($up ? 'high' : 'low') . ' candle'; break; }
        }
        if ($turn) $stage = 2;
    }
    if ($given >= 0.25 && ($turn || $given >= 0.4)) $stage = 3;
    if ($stage === 1 && $given > 0.2) $stage = 2;   // pulled back without a clean signal candle

    // labels
    $labels = [];
    $Z = array_values(array_filter(tl_zones($fr), fn($z) => $z['k'] !== 'Day High' && $z['k'] !== 'Day Low'));
    $zm = tl_match($Z, $ext, 0.12 * $aD);
    if ($zm) $labels[] = ['zone', ($zm['t'] === 'strong' ? '★ ' : '') . 'Hit ' . implode(' + ', array_slice($zm['n'], 0, 3))];
    $line = null;
    if ($tl) foreach (['1W', '1D', '4H', '1H'] as $k) {
        $L = $tl['tf'][$k][$up ? 'res' : 'sup'] ?? null;
        if (is_array($L) && (int)$L['n'] >= 2 && abs((float)$L['now'] - $ext) <= 0.15 * $aD) { $line = $k; $labels[] = ['line', 'Hit ' . ($k === '1W' ? 'Weekly' : ($k === '1D' ? 'Daily' : $k)) . ' trend line']; break; }
    }
    $rsi = rb_rsi(array_map('floatval', array_slice($E['c'], -120)));
    if ($rsi !== null && ($up ? $rsi >= 70 : $rsi <= 30)) $labels[] = ['rsi', 'RSI ' . round($rsi) . ' (' . ($up ? 'overbought' : 'oversold') . ')'];
    $big = (int)($tl['mtf']['d']['1D'] ?? 0); $with = $big !== 0 && ($big > 0) === ($side === 'CALL');
    if ($big) $labels[] = [$with ? 'with' : 'against', $with ? 'With the big trend' : 'Against the big trend'];
    if ($stretch >= 2) $labels[] = ['big', round($stretch, 1) . '× usual range'];

    // plan: one target, the day's midpoint between the extreme and yesterday's close (mean of the run)
    $plan = null;
    if ($stage >= 2) {
        $sl = $ext - $sg * 0.1 * $aD; $t1 = $ext + $sg * 0.5 * $move; $risk = abs($px - $sl);
        if ($risk > 0 && ($t1 - $px) * $sg > 0) $plan = ['entry' => $px, 'sl' => $sl, 't1' => $t1, 'rr' => round(abs($t1 - $px) / $risk, 1)];
    }
    $d = tl_dec($px);
    if ($plan) foreach ($plan as $k => $v) if ($k !== 'rr') $plan[$k] = round($v, $d);
    $say = ($up ? 'Ran up ' : 'Fell ') . round(abs($chg), 2) . '% today, ' . round($stretch, 1) . '× its usual daily range. '
         . ($stage === 1 ? 'Still at the ' . ($up ? 'high' : 'low') . ': no turn yet, watch only.'
            : ($stage === 2 ? 'Turning: ' . ($turn ?? 'price has started to come off the ' . ($up ? 'high' : 'low')) . '.'
               : 'Snapping back: ' . round($given * 100) . '% of the move already given back.'));
    return ['symbol' => $sym, 'name' => t_name($sym), 'market' => $t1['market'] ?? '', 'class' => tl_class($sym), 'live' => !empty($t1['live']),
            'price' => round($px, $d), 'dec' => $d, 'chg' => round($chg, 2), 'stretch' => round($stretch, 2), 'side' => $side,
            'stage' => $stage, 'stage_name' => RB_STAGE[$stage], 'given' => round($given * 100), 'ext' => round($ext, $d), 'etf' => $etf,
            'labels' => $labels, 'say' => $say, 'plan' => $plan, 'mtf' => $tl['mtf']['d'] ?? [],
            'key' => [!empty($t1['live']) ? 1 : 0, $stage, $with ? 1 : 0, ($zm || $line) ? 1 : 0, round($stretch, 2)]];
}

function rb_scan(): array {
    $now = t_now(); $ck = T_DATA . '/rb_scan1.json';
    $c = t_read_json($ck, null); if (is_array($c) && $now - (int)($c['at'] ?? 0) < RB_TTL) return $c;
    $SC = t_scan_load(); $T = []; foreach (t_view_rows($SC, $now) as $r) $T[$r['symbol']] = $r;
    $TL = []; try { foreach (tl_scan()['rows'] as $r) $TL[$r['symbol']] = $r; } catch (Throwable $e) {}
    $rows = []; $t0 = microtime(true);
    foreach ($T as $sym => $t1) {
        if (empty($t1['live']) && abs((float)($t1['change_pct'] ?? 0)) <= 0) continue;
        try { $r = rb_row($sym, $t1, $SC['rows'][$sym] ?? [], $TL[$sym] ?? null, $now); } catch (Throwable $e) { $r = null; }
        if ($r) $rows[] = $r;
    }
    usort($rows, fn($a, $b) => $b['key'] <=> $a['key']);
    $cnt = [3 => 0, 2 => 0, 1 => 0]; foreach ($rows as $r) if ($r['live']) $cnt[$r['stage']]++;
    foreach ($rows as &$r) unset($r['key']); unset($r);
    $p = ['at' => $now, 'rows' => $rows, 'meta' => ['universe' => count($T), 'stretched' => count($rows), 'counts' => $cnt, 'took_ms' => (int)round((microtime(true) - $t0) * 1000)]];
    t_write_json($ck, $p);
    return $p;
}
