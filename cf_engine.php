<?php
/**
 * Confluence Scanner (owner, 27 Nov 2026)                                   chartpulse.io
 *
 * Ready-made reversal setups where trend lines from several charts meet, in the direction of the big trend.
 *   1. Bias only from the big charts: Daily AND Weekly trend agree (tl_bias_dw). Down = PUT at resistance, up = CALL at support.
 *   2. Lines from the v3 engine (tl_frame) on 4H, 1H, 30m, 15m -- only the bias side (resistance for PUT, support for CALL).
 *   3. Confluence zone: lines of at least 2 different charts whose current value sits within 0.35 x daily ATR of each other.
 *      Weight: 4H 4, 1H 3, 30m 2, 15m 1.
 *   4. Trap-aware (ATR): the day's extreme at the zone (within 0.25 ATR), a wick through it up to 0.5 ATR with price back
 *      = sweep (best); price more than 0.5 ATR through the zone = breakout, dropped.
 *   5. Reversal ladder: 1 approaching, 2 at zone, 3 sweep, 4 rejection candle (15m wick / engulfing), 5 GO (5m CHoCH).
 *   6. Plan: entry, stop beyond the extreme + 0.25 ATR, targets 1.5R and 2.5R.
 * Labels only (never filters): key levels on the zone (PDH/PDL, week/month high/low), round number, GEX wall, line touches.
 */
declare(strict_types=1);
require_once __DIR__ . '/tl_engine.php';

const CF_TFS = ['4H' => 4, '1H' => 3, '30m' => 2, '15m' => 1];   // charts and their weight
const CF_ZONE = 0.35;     // x daily ATR: lines this close together form one zone
const CF_AT = 0.25;       // x daily ATR: the extreme is "at" the zone
const CF_NEAR = 0.5;      // x daily ATR: approaching
const CF_OVER = 0.5;      // x daily ATR: price further through the zone than this = breakout
const CF_STAGE = [5 => 'GO', 4 => 'REJECTION', 3 => 'SWEEP', 2 => 'AT ZONE', 1 => 'APPROACHING'];

/** Value of a published v3 line (tl_frame $pub, shown-bar indices) at a fractional index. */
function cf_line_at(array $L, float $i): float {
    $di = ($L['i2'] - $L['i1']) ?: 1;
    if (!empty($L['log']) && $L['p1'] > 0 && $L['p2'] > 0) return exp(log($L['p1']) + (log($L['p2']) - log($L['p1'])) * ($i - $L['i1']) / $di);
    return $L['p1'] + ($L['p2'] - $L['p1']) * ($i - $L['i1']) / $di;
}

/** Index of the last bar at or before $t (binary search), -1 when before the first. */
function cf_idx(array $T, int $t): int {
    $lo = 0; $hi = count($T) - 1; if ($hi < 0 || $t < $T[0]) return -1;
    while ($lo < $hi) { $m = intdiv($lo + $hi + 1, 2); if ($T[$m] <= $t) $lo = $m; else $hi = $m - 1; }
    return $lo;
}

/** Build the setup for one symbol. $full adds everything the symbol page needs. */
function cf_row(string $sym, array $row, int $now, bool $full = false): ?array {
    $fr = t_read_frames($sym); if (!$fr || count($fr['1D']['c'] ?? []) < 30) return null;
    $src = tl_src($fr, $fr['5m'] ?? null, $sym);
    $mtf = tl_mtf($src, true); $bias = $mtf['bias']; if (!$bias && !$full) return null;
    $put = $bias === 'PUT'; $side = $put ? 1 : -1; $sg = $put ? 1 : -1;   // line side 1 = resistance
    $D = $fr['1D']; $nD = count($D['c']); $A = t_atr($D, 14); $atr = (float)end($A); if ($atr <= 0) return null;
    $px = (float)($row['price'] ?? $D['c'][$nD - 1]); $d = tl_dec($px);
    $hi = (float)$D['h'][$nD - 1]; $lo = (float)$D['l'][$nD - 1]; $X = $put ? $hi : $lo;

    // lines of the bias side on every chart, with today's value
    $frames = []; $cand = [];
    foreach (CF_TFS as $tf => $w) {
        if (empty($src[$tf]['t'])) continue;
        $f = tl_frame($tf, $src[$tf], true); if (!$f) continue; $frames[$tf] = $f;
        $nb = count($f['bars']['t']);
        foreach ($f['lines'] ?? [] as $L) {
            if (!$L || (int)$L['side'] !== $side || $L['state'] === 'broken') continue;
            $v = cf_line_at($L, $nb - 1);
            $cand[] = ['tf' => $tf, 'w' => $w, 'v' => $v, 'L' => $L];
        }
    }
    // best zone: window of CF_ZONE x ATR with the largest weight of distinct charts, nearest to today's extreme on ties
    usort($cand, fn($a, $b) => $a['v'] <=> $b['v']);
    $best = null; $nc = count($cand);
    for ($i = 0; $i < $nc; $i++) {
        $tfs = []; $mem = [];
        for ($j = $i; $j < $nc && $cand[$j]['v'] - $cand[$i]['v'] <= CF_ZONE * $atr; $j++) {
            $c = $cand[$j]; if (!isset($tfs[$c['tf']]) || $c['L']['touches'] > $tfs[$c['tf']]['L']['touches']) $tfs[$c['tf']] = $c;
        }
        if (count($tfs) < 2) continue;
        $wt = array_sum(array_column($tfs, 'w')); $zl = min(array_column($tfs, 'v')); $zh = max(array_column($tfs, 'v'));
        $dist = $put ? ($zl - $X) : ($X - $zh);   // > 0: the extreme has not reached the zone yet
        $k = $wt * 10 - max(0, $dist / $atr) * 6 + count($tfs);
        if (!$best || $k > $best['k']) $best = ['k' => $k, 'tfs' => $tfs, 'zl' => $zl, 'zh' => $zh, 'wt' => $wt];
    }
    if (!$best && !$full) return null;

    $stage = 0; $why = ''; $ladder = []; $plan = null; $sweep = false; $brk = false;
    if ($best) {
        $zl = $best['zl']; $zh = $best['zh']; $near = $put ? $zl : $zh; $far = $put ? $zh : $zl;
        $reach = ($X - $near) * $sg / $atr;       // >= 0: the extreme touched the zone's near edge
        $thru = ($X - $far) * $sg / $atr;         // > 0: the extreme went through the far edge
        $pthru = ($px - $far) * $sg / $atr;       // price beyond the far edge
        if ($pthru > CF_OVER) { $brk = true; }
        elseif ($thru > 0.05) { $sweep = $pthru <= 0.1; $stage = $sweep ? 3 : 2; }
        elseif ($reach >= -CF_AT) $stage = 2;
        elseif ($reach >= -CF_NEAR) $stage = 1;
        $ladder[] = ['k' => 'At the zone', 'ok' => $stage >= 2];
        $ladder[] = ['k' => 'Sweep (wick through, back inside)', 'ok' => $sweep];
        // rejection on 15m and CHoCH on 5m, read after the extreme
        $rej = false; $go = false; $gi = null;
        $M = $src['15m'] ?? null; $F5 = $src['5m'] ?? null;
        if ($stage >= 2 && $M && count($M['t']) > 10) {
            $m = count($M['t']);
            for ($i = max(0, $m - 6); $i < $m; $i++) {
                $h = $M['h'][$i]; $l = $M['l'][$i]; $o = $M['o'][$i]; $c = $M['c'][$i]; $r = max($h - $l, 1e-12);
                $ext = $put ? $h : $l; if (abs($ext - $X) > 0.35 * $atr && ($ext - $near) * $sg < -CF_AT * $atr) continue;
                $wick = $put ? ($h - max($o, $c)) / $r : (min($o, $c) - $l) / $r;
                $eng = $i > 0 && ($put ? ($c < $o && $c < $M['l'][$i - 1] + 0.3 * ($M['h'][$i - 1] - $M['l'][$i - 1]) && $o >= $M['c'][$i - 1])
                                      : ($c > $o && $c > $M['h'][$i - 1] - 0.3 * ($M['h'][$i - 1] - $M['l'][$i - 1]) && $o <= $M['c'][$i - 1]));
                if ($wick >= 0.5 || $eng) { $rej = true; break; }
            }
        }
        if ($stage >= 2 && $F5 && count($F5['t']) > 40) {
            $n5 = count($F5['t']); $s0 = max(0, $n5 - 48); $xi = $s0;
            for ($i = $s0; $i < $n5; $i++) if ($put ? $F5['h'][$i] >= $F5['h'][$xi] : $F5['l'][$i] <= $F5['l'][$xi]) $xi = $i;
            $ext5 = $put ? $F5['h'][$xi] : $F5['l'][$xi];
            if (($ext5 - $near) * $sg >= -CF_AT * $atr) {
                $piv = $put ? min(array_slice($F5['l'], max(0, $xi - 8), max(1, $xi - max(0, $xi - 8)))) : max(array_slice($F5['h'], max(0, $xi - 8), max(1, $xi - max(0, $xi - 8))));
                for ($i = $xi + 1; $i < $n5; $i++) if (($F5['c'][$i] - $piv) * $sg < 0) { $go = true; $gi = $i; break; }
                if ($go && ($px - $ext5) * $sg > 0) $go = false;   // made a new extreme after the break: not valid any more
                if ($go && $gi < $n5 - 12) $go = false;           // the turn is older than ~1 hour: not a fresh entry any more
            }
        }
        $ladder[] = ['k' => 'Rejection candle (15m wick or engulfing)', 'ok' => $rej];
        $ladder[] = ['k' => 'CHoCH on 5m (structure turned)', 'ok' => $go];
        if ($stage >= 2 && $rej) $stage = 4;
        if ($stage >= 2 && $go) $stage = 5;
        $away = ($near - $px) * $sg / $atr;     // how far price has already moved away from the zone, in the trade direction
        if ($away > 1.0) { $stage = 0; $why = 'Price already moved more than 1 ATR away from the zone: the reversal has played out.'; }
        if ($brk) $why = 'Price closed more than 0.5 ATR through the zone: breakout, no reversal setup.';
        // plan
        $stop = $X + $sg * 0.25 * $atr; $entry = $stage >= 5 ? $px : $near; $risk = max(abs($stop - $entry), 0.2 * $atr);
        $plan = ['entry' => round($entry, $d), 'stop' => round($stop, $d), 't1' => round($entry - $sg * 1.5 * $risk, $d), 't2' => round($entry - $sg * 2.5 * $risk, $d),
                 'risk_pct' => round($risk / max($entry, 1e-12) * 100, 2), 'note' => $stage >= 5 ? 'Entry now (5m structure turned at the zone).' : 'Wait for the 5m CHoCH at the zone; plan shown at the zone edge.'];
    }
    if ((!$best || $brk || $stage === 0) && !$full) return null;

    // labels (never filters)
    $labels = [];
    if ($best) {
        $zc = ($best['zl'] + $best['zh']) / 2;
        foreach (tl_zones($fr) as $z) if (in_array($z['g'], ['level'], true) && abs($z['p'] - $zc) <= 0.3 * $atr && !in_array($z['k'], ['Day High', 'Day Low'], true)) $labels[] = $z['k'];
        $step = pow(10, floor(log10(max($zc, 1e-9))) - 1) * 5; if ($step > 0 && abs($zc - round($zc / $step) * $step) <= 0.15 * $atr) $labels[] = 'Round number';
    }
    $gx = null; if (is_file(__DIR__ . '/gex_engine.php')) { try { require_once __DIR__ . '/gex_engine.php'; $gx = gex_label_for($sym); } catch (Throwable $e) {} }
    if ($gx && $best) foreach (['cw' => 'Call wall', 'pw' => 'Put wall', 'flip' => 'Gamma flip'] as $k => $t) if (!empty($gx[$k]) && abs($gx[$k] - ($best['zl'] + $best['zh']) / 2) <= 0.4 * $atr) $labels[] = 'GEX ' . $t;

    $tfl = $best ? array_values(array_map(fn($c) => ['tf' => $c['tf'], 'v' => round($c['v'], $d), 'touches' => $c['L']['touches'], 'lvl' => $c['L']['lvl'], 'sweeps' => $c['L']['sweeps']], $best['tfs'])) : [];
    usort($tfl, fn($a, $b) => CF_TFS[$b['tf']] <=> CF_TFS[$a['tf']]);
    $key = $stage * 1000 + ($best['wt'] ?? 0) * 40 + count($tfl) * 15 + array_sum(array_column($tfl, 'touches')) + count($labels) * 8 + ($sweep ? 30 : 0);
    $out = ['symbol' => $sym, 'name' => $row['name'] ?? $sym, 'market' => $row['market'] ?? '', 'class' => $row['class'] ?? '', 'price' => round($px, $d), 'dec' => $d,
            'bias' => $bias, 'mtf' => ['d' => $mtf['d'], 'rule' => 'D+W'], 'atr' => round($atr, $d + 1), 'x' => round($X, $d),
            'zone' => $best ? [round($best['zl'], $d), round($best['zh'], $d)] : null, 'weight' => $best['wt'] ?? 0, 'tfs' => $tfl,
            'stage' => $stage, 'stage_name' => CF_STAGE[$stage] ?? ($brk ? 'BREAKOUT' : 'NONE'), 'sweep' => $sweep, 'breakout' => $brk, 'why' => $why,
            'ladder' => $ladder, 'plan' => $plan, 'labels' => array_values(array_unique($labels)), 'gex' => $gx, 'key' => $key, 'at' => $now];
    if ($full) {
        $out['frames'] = [];
        foreach ($frames as $tf => $f) $out['frames'][] = ['tf' => $tf, 'pattern' => $f['pattern'], 'text' => $f['text'], 'lines' => $f['lines'], 'res' => $f['res'], 'sup' => $f['sup'], 'bars' => $f['bars']];
        // mixture chart: 15m bars (1H where there is no intraday data) with volume, zone lines projected onto them
        $mk = !empty($src['15m']['t']) ? '15m' : '1H'; $MB = $src[$mk]; $mn = count($MB['t']); $m0 = max(0, $mn - 220);
        $mix = ['tf' => $mk, 't' => array_slice($MB['t'], $m0), 'o' => array_slice($MB['o'], $m0), 'h' => array_slice($MB['h'], $m0), 'l' => array_slice($MB['l'], $m0),
                'c' => array_slice($MB['c'], $m0), 'v' => array_slice($MB['v'] ?? array_fill(0, $mn, 0), $m0), 'lines' => []];
        if ($best) foreach ($best['tfs'] as $tf => $c) {
            $fb = $frames[$tf]['bars']['t']; $pts = [];
            foreach ($mix['t'] as $T) { $j = cf_idx($fb, (int)$T); $pts[] = $j < 0 ? null : round(cf_line_at($c['L'], $j), $d + 1); }
            $mix['lines'][] = ['tf' => $tf, 'pts' => $pts];
        }
        $out['mix'] = $mix;
    }
    return $out;
}

/** The ranked list. Same incremental pattern as tlh_scan: a time budget per call, rows cached ~4 minutes. */
function cf_scan(): array {
    $dir = T_DATA . '/cf'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $now = t_now(); $ck = $dir . '/_scan1.json';
    $c = t_read_json($ck, null);
    if (is_array($c) && $now - (int)($c['at'] ?? 0) < 30 && isset($c['rows'])) return $c;
    $scan = t_scan_load(); $syms = array_keys($scan['rows'] ?? []);
    $cp = $dir . '/_rows1.json'; $cache = t_read_json($cp, []); if (!is_array($cache)) $cache = [];
    $t0 = microtime(true); $done = 0; $fresh = 0;
    foreach ($syms as $sym) {
        $old = $cache[$sym] ?? null;
        if (is_array($old) && $now - (int)($old['at'] ?? 0) < 240) { $done++; continue; }
        if (microtime(true) - $t0 > TL_BUDGET) continue;
        try { $r = cf_row($sym, $scan['rows'][$sym], $now); } catch (Throwable $e) { $r = null; }
        $cache[$sym] = $r ?: ['at' => $now, 'none' => 1]; $done++; $fresh++;
    }
    foreach ($cache as $s => $_) if (!isset($scan['rows'][$s])) unset($cache[$s]);
    if ($fresh) t_write_json($cp, $cache);
    $rows = array_values(array_filter($cache, fn($r) => is_array($r) && empty($r['none'])));
    usort($rows, fn($a, $b) => $b['key'] <=> $a['key']);
    $cnt = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach ($rows as $r) $cnt[$r['stage']] = ($cnt[$r['stage']] ?? 0) + 1;
    $p = ['at' => $now, 'rows' => $rows, 'meta' => ['universe' => count($syms), 'scanned' => $done, 'progress' => count($syms) ? round($done / count($syms), 3) : 1,
          'counts' => $cnt, 'took_ms' => (int)round((microtime(true) - $t0) * 1000)]];
    t_write_json($ck, $p);
    return $p;
}

/** One symbol for page 2 (cached 30 s). */
function cf_detail(string $sym): ?array {
    $dir = T_DATA . '/cf'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $p = $dir . '/d_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym) . '.json'; $now = t_now();
    $c = t_read_json($p, null); if (is_array($c) && $now - (int)($c['at'] ?? 0) < 30) return $c;
    $scan = t_scan_load(); $row = $scan['rows'][$sym] ?? null; if (!$row) return null;
    $r = cf_row($sym, $row, $now, true); if ($r) t_write_json($p, $r);
    return $r;
}
