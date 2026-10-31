<?php
// ---------------------------------------------------------------------------
// ChartPulse · 48-hour target study for the Tier-2 research report (owner, 30 Sep 2026). Server-only.
//   Trades often run past their exit, so each day's report is sent two days later (Monday's on Wednesday) with a study of
//   what price did in the 48 hours after every entry: which of the next three pivots in the trade's direction (T1, T2, T3)
//   were reached before the stop-loss, how far the best move went, and when. T1 is the trade's own minimum target.
//   Conclusions answer: which market reaches targets best, how far trend calls go, how often retracements touch their target.
// ---------------------------------------------------------------------------
declare(strict_types=1);
require_once __DIR__ . '/torus_report.php';

const TS_HOURS = 48;

/** Pip size for forex, else null (stocks, indices, crypto, commodities are measured in points = price units). */
function ts_unit(string $sym, float $px): array { $p = t2_pip($sym, $px); return $p ? ['pips', $p] : ['points', null]; }
/** A price move in the trade's unit: pips for forex, points otherwise. */
function ts_move(string $sym, float $px, float $mv): float { [$u, $p] = ts_unit($sym, $px); if ($p) return round($mv / $p, 1); $a = abs($mv); return round($mv, $a >= 100 ? 1 : ($a >= 1 ? 2 : ($a >= 0.01 ? 4 : 6))); }
/** T1..T3: pivots beyond the entry in the trade's direction, nearest first. */
function ts_levels(array $P, float $pe, int $sg): array {
    $L = []; foreach ($P as $k => $v) { $d = ((float)$v - $pe) * $sg; if ($d > 0) $L[$k] = $d; }
    asort($L); $o = [];
    foreach (array_slice($L, 0, 3, true) as $k => $d) $o[] = ['k' => $k, 'p' => t_r($pe + $sg * $d), 'd' => $d];
    return $o;
}
/** The 48-hour path of one trade on 5-minute bars. $upto = the newest bar time we may use. */
function ts_trade(array $x, array $F5, ?array $P, int $upto): ?array {
    if (!$P) return null;
    $sg = ($x['sig'] ?? '') === 'CALL' ? 1 : -1; $pe = (float)$x['entry']; $sl = (float)($x['sl0'] ?? $x['sl']); $risk = abs($pe - $sl);
    $te = (int)$x['entry_time']; $end = min($te + TS_HOURS * 3600, $upto); if ($risk <= 1e-12) return null;
    $lv = ts_levels($P, $pe, $sg); $hitSL = array_fill(0, count($lv), null); $hit = $hitSL;
    $slT = null; $best = 0.0; $bestT = null; $bestSL = 0.0; $bestSLT = null; $worst = 0.0; $last = null;
    foreach ($F5['t'] as $i => $t) {
        $t = (int)$t; if ($t < $te) continue; if ($t >= $end) break;
        $h = (float)$F5['h'][$i]; $l = (float)$F5['l'][$i]; $last = $t;
        $fv = $sg > 0 ? $h - $pe : $pe - $l; $av = $sg > 0 ? $pe - $l : $h - $pe;
        if ($slT === null && ($sg > 0 ? $l <= $sl : $h >= $sl)) $slT = $t;          // stop and target in one bar: counted as stop first
        if ($slT === null) { $worst = max($worst, $av); if ($fv > $bestSL) { $bestSL = $fv; $bestSLT = $t; } foreach ($lv as $j => $v) if ($hitSL[$j] === null && $fv >= $v['d']) $hitSL[$j] = $t; }
        if ($fv > $best) { $best = $fv; $bestT = $t; }
        foreach ($lv as $j => $v) if ($hit[$j] === null && $fv >= $v['d']) $hit[$j] = $t;
    }
    if ($last === null) return null;
    $R = fn($v) => round($v / $risk, 2);
    return ['hours' => round((min($end, $last + 300) - $te) / 3600, 1), 'complete' => $end >= $te + TS_HOURS * 3600, 'sl_t' => $slT,
        'best_sl_r' => $R($bestSL), 'best_sl_t' => $bestSLT, 'best_sl_move' => ts_move($x['symbol'], $pe, $bestSL),
        'best_r' => $R($best), 'best_t' => $bestT, 'best_move' => ts_move($x['symbol'], $pe, $best), 'worst_r' => $R($worst),
        'lv' => array_map(fn($v, $a, $b) => ['k' => $v['k'], 'p' => $v['p'], 'r' => $R($v['d']), 'move' => ts_move($x['symbol'], $pe, $v['d']), 't_sl' => $a, 't' => $b], $lv, $hitSL, $hit),
        'unit' => ts_unit($x['symbol'], $pe)[0]];
}
/** Adds 'study' to every trade of report $rep that lacks a complete one. Returns how many are still missing. */
function ts_run(array &$rep, int $maxSec = 150): int {
    $t0 = time(); $now = t_now(); $by = [];
    foreach ($rep['trades'] as $i => $x) if (empty($x['study']['complete']) && !str_starts_with($x['symbol'], 'MCX:')) $by[$x['symbol']][] = $i;
    $range = $now - (int)($rep['trades'][0]['entry_time'] ?? $now) > 4 * 86400 ? '1mo' : '5d';
    foreach (array_chunk(array_keys($by), 8) as $chunk) {
        if (time() - $t0 > $maxSec) break;
        $urls = []; foreach ($chunk as $s) $urls[$s] = t_url($s, $range, '5m');
        $B = t_fetch($urls);
        foreach ($chunk as $s) {
            $md = t2_data_parse($B[$s] ?? null, null); if (!$md) continue;
            $f5 = $md['f5']; $ses = t2_sessions($f5, $md['tz']);
            foreach ($by[$s] as $i) {
                $te = (int)$rep['trades'][$i]['entry_time']; $dayTs = null;
                foreach ($ses as [$a, $b]) if ((int)$f5['t'][$a] <= $te && (int)$f5['t'][$b] + 300 > $te - 3600) { $dayTs = (int)$f5['t'][$a]; break; }
                $st = $dayTs !== null ? ts_trade($rep['trades'][$i], $f5, t_fib_pivots($f5, $dayTs), $now) : null;
                if ($st) $rep['trades'][$i]['study'] = $st;
            }
            unset($by[$s]);
        }
    }
    return array_sum(array_map('count', $by));
}

/* ---------------- conclusions ---------------- */
/** Totals of the study for a list of trades. */
function ts_stats(array $T): array {
    $S = array_values(array_filter($T, fn($x) => !empty($x['study'])));
    $n = count($S); $pct = fn($k) => $n ? round($k / $n * 100, 1) : null;
    $hitSL = fn($j) => count(array_filter($S, fn($x) => !empty($x['study']['lv'][$j]['t_sl'])));
    $hitAny = fn($j) => count(array_filter($S, fn($x) => !empty($x['study']['lv'][$j]['t'])));
    $med = function (array $a) { $a = array_values(array_filter($a, fn($v) => $v !== null)); if (!$a) return null; sort($a); return $a[intdiv(count($a), 2)]; };
    $mins = fn($j) => $med(array_map(fn($x) => !empty($x['study']['lv'][$j]['t_sl']) ? (int)round(($x['study']['lv'][$j]['t_sl'] - $x['entry_time']) / 60) : null, $S));
    $C = array_filter($T, fn($x) => ($x['status'] ?? '') === 'closed' && $x['r'] !== null);
    return ['n' => count($T), 'studied' => $n,
        't1' => $pct($hitSL(0)), 't2' => $pct($hitSL(1)), 't3' => $pct($hitSL(2)), 't1_any' => $pct($hitAny(0)), 't2_any' => $pct($hitAny(1)), 't3_any' => $pct($hitAny(2)),
        'sl_first' => $pct(count(array_filter($S, fn($x) => $x['study']['sl_t'] !== null && empty($x['study']['lv'][0]['t_sl'])))),
        't1_min' => $mins(0), 't2_min' => $mins(1),
        'best_sl_r' => $med(array_map(fn($x) => $x['study']['best_sl_r'], $S)), 'best_r' => $med(array_map(fn($x) => $x['study']['best_r'], $S)),
        'net_r' => round(array_sum(array_map(fn($x) => (float)$x['r'], $C)), 2),
        'left_r' => round(array_sum(array_map(fn($x) => max(0, (float)$x['study']['best_sl_r'] - max(0, (float)($x['r'] ?? 0))), array_filter($S, fn($x) => ($x['r'] ?? 0) > 0))), 2)];
}
/** Per market, trend and retracement, plus plain-language conclusions. */
function ts_conclude(array $T): array {
    $mk = []; foreach ($T as $x) $mk[$x['market'] ?: 'Other'][] = $x;
    $M = array_map('ts_stats', $mk); uasort($M, fn($a, $b) => [$b['studied'] >= 5, $b['t1'] ?? -1, $b['best_sl_r'] ?? -1] <=> [$a['studied'] >= 5, $a['t1'] ?? -1, $a['best_sl_r'] ?? -1]);
    $tr = array_values(array_filter($T, fn($x) => tr_setup($x) === 'Trend')); $re = array_values(array_filter($T, fn($x) => tr_setup($x) === 'Retracement'));
    $trM = []; foreach ($tr as $x) $trM[$x['market'] ?: 'Other'][] = $x; $trM = array_map('ts_stats', $trM);
    $reM = []; foreach ($re as $x) $reM[$x['market'] ?: 'Other'][] = $x; $reM = array_map('ts_stats', $reM);
    $all = ts_stats($T); $TR = ts_stats($tr); $RE = ts_stats($re);
    $L = [];
    $ok = array_filter($M, fn($s) => $s['studied'] >= 5);
    if ($ok) { $k = array_key_first($ok); $w = array_key_last($ok);
        $L[] = "Best market for targets: $k (T1 before stop-loss " . $ok[$k]['t1'] . '%, median best move +' . $ok[$k]['best_sl_r'] . 'R, ' . $ok[$k]['studied'] . ' trades).';
        if ($w !== $k) $L[] = "Weakest: $w (T1 before stop-loss " . $ok[$w]['t1'] . '%, net ' . ($ok[$w]['net_r'] > 0 ? '+' : '') . $ok[$w]['net_r'] . 'R).'; }
    if ($TR['studied']) $L[] = 'Trend calls: T1 ' . $TR['t1'] . '% · T2 ' . $TR['t2'] . '% · T3 ' . $TR['t3'] . '% reached before the stop-loss (within 48 h); median best move +' . $TR['best_sl_r'] . 'R.'
        . ($TR['t2'] !== null && $TR['t2'] >= 30 ? ' T2 is reached often enough to test holding a part to T2.' : ($TR['t1'] !== null && $TR['t1'] < 40 ? ' Most trend calls stop before T1: entries or stop-loss need work.' : ''));
    $dp = ts_depth($tr); if ($dp['n']) $L[] = 'Where trend calls end up (furthest level before any stop-loss, 48 h): stop before T1 ' . $dp['none'] . '% · T1 ' . $dp['t1'] . '% · T2 ' . $dp['t2'] . '% · T3 or beyond ' . $dp['t3'] . '%.';
    if ($RE['studied']) $L[] = 'Retracements: the single target was touched before the stop-loss in ' . $RE['t1'] . '% (median ' . ($RE['t1_min'] ?? '–') . ' min after entry); stop-loss came first in ' . $RE['sl_first'] . '%.';
    if ($all['left_r'] > 0) $L[] = 'Winning trades left +' . $all['left_r'] . 'R on the table versus their best move before any stop-loss.';
    $small = array_filter($M, fn($s) => $s['studied'] > 0 && $s['studied'] < 5); if ($small) $L[] = 'Too few trades to judge: ' . implode(', ', array_keys($small)) . '.';
    return ['all' => $all, 'trend' => $TR, 'retrace' => $RE, 'market' => $M, 'trend_market' => $trM, 'retrace_market' => $reM, 'lines' => $L,
        'trend_depth' => $dp, 'retrace_depth' => ts_depth($re), 'sl' => ts_sl($T)];
}
/** How far trades get before any stop-loss (48 h study): share whose furthest level is none / T1 / T2 / T3+. */
function ts_depth(array $T): array {
    $S = array_values(array_filter($T, fn($x) => !empty($x['study']['lv']))); $n = count($S); $c = [0, 0, 0, 0];
    foreach ($S as $x) { $k = 0; foreach ($x['study']['lv'] as $j => $l) if (!empty($l['t_sl'])) $k = $j + 1; $c[min($k, 3)]++; }
    $p = fn($v) => $n ? round($v / $n * 100, 1) : null;
    return ['n' => $n, 'none' => $p($c[0]), 't1' => $p($c[1]), 't2' => $p($c[2]), 't3' => $p($c[3])];
}

/* ---------------- stop-loss department (owner, 30 Sep 2026) ---------------- */
/** Every trade our rules stopped out: how fast, how wide, what price did next, re-entries, and T1-then-stop (if held). */
function ts_sl(array $T): array {
    $pc = fn($a, $b) => $b ? round($a / $b * 100, 1) : null;
    $med = function (array $a) { $a = array_values(array_filter($a, fn($v) => $v !== null)); if (!$a) return null; sort($a); return $a[intdiv(count($a), 2)]; };
    $isSL = fn($x) => ($x['reason'] ?? '') === 'Stop-loss';
    $SL = array_values(array_filter($T, $isSL)); $n = count($SL);
    $grp = function (callable $key) use ($T, $isSL, $n, $pc) { $g = [];
        foreach ($T as $x) { $k = $key($x); $g[$k] ??= ['trades' => 0, 'sl' => 0]; $g[$k]['trades']++; if ($isSL($x)) $g[$k]['sl']++; }
        foreach ($g as &$v) { $v['rate'] = $pc($v['sl'], $v['trades']); $v['share'] = $pc($v['sl'], $n); } unset($v);
        uasort($g, fn($a, $b) => $b['sl'] <=> $a['sl']); return $g; };
    $bySym = []; foreach ($T as $x) $bySym[$x['symbol']][] = $x;
    $rows = []; $re = ['n' => 0, 'same_side' => 0, 'won' => 0, 'sl_again' => 0, 'net_r' => 0.0];
    foreach ($SL as $x) {
        $te = (int)$x['entry_time']; $tx = (int)($x['exit_time'] ?? $te); $st = $x['study'] ?? null;
        $nx = null; foreach ($bySym[$x['symbol']] as $y) if ($y !== $x && (int)$y['entry_time'] >= $tx && (int)$y['entry_time'] - $tx < 12 * 3600 && (!$nx || $y['entry_time'] < $nx['entry_time'])) $nx = $y;
        if ($nx) { $re['n']++; if ($nx['sig'] === $x['sig']) $re['same_side']++; if (($nx['r'] ?? 0) > 0) $re['won']++; if ($isSL($nx)) $re['sl_again']++; $re['net_r'] += (float)($nx['r'] ?? 0); }
        $t1 = $st['lv'][0] ?? null;
        $rows[] = ['id' => $x['id'], 'd' => t_ist($te, 'Y-m-d'), 'name' => $x['name'], 'symbol' => $x['symbol'], 'market' => $x['market'], 'setup' => tr_setup($x), 'sig' => $x['sig'],
            'entry_time' => $te, 'mins' => (int)round(($tx - $te) / 60), 'width_atr' => !empty($x['atr5']) ? round(abs((float)$x['entry'] - (float)$x['sl0']) / (float)$x['atr5'], 2) : null,
            't1_later' => $t1 ? (!empty($t1['t']) && empty($t1['t_sl'])) : (bool)($x['target_after_sl'] ?? false),
            'after_r' => $x['after_sl_r'] ?? null, 'reentry' => $nx ? ['sig' => $nx['sig'], 'r' => $nx['r'], 'reason' => $nx['reason'] ?? 'Open', 'id' => $nx['id']] : null];
    }
    $re['net_r'] = round($re['net_r'], 2);
    // T1 reached before any stop, then price came back to the stop within 48 h (what would happen if the trade were simply held)
    $hit = array_values(array_filter($T, fn($x) => !empty($x['study']['lv'][0]['t_sl'])));
    $back = array_values(array_filter($hit, fn($x) => !empty($x['study']['sl_t']) && $x['study']['sl_t'] > $x['study']['lv'][0]['t_sl']));
    $backTr = count(array_filter($back, fn($x) => tr_setup($x) === 'Trend')); $hitTr = count(array_filter($hit, fn($x) => tr_setup($x) === 'Trend'));
    $studiedSL = array_values(array_filter($SL, fn($x) => !empty($x['study'])));
    $later = count(array_filter($rows, fn($r) => $r['t1_later']));
    $S = ['trades' => count($T), 'n' => $n, 'rate' => $pc($n, count($T)), 'setup' => $grp(fn($x) => tr_setup($x)), 'market' => $grp(fn($x) => $x['market'] ?: 'Other'),
        'side' => $grp(fn($x) => $x['sig']), 'mins_med' => $med(array_column($rows, 'mins')), 'fast15' => $pc(count(array_filter($rows, fn($r) => $r['mins'] <= 15)), $n),
        'fast60' => $pc(count(array_filter($rows, fn($r) => $r['mins'] <= 60)), $n), 'width_med' => $med(array_column($rows, 'width_atr')),
        't1_later' => $pc($later, $n), 'reentry' => $re + ['pct' => $pc($re['n'], $n)],
        't1_then_sl' => ['hit' => count($hit), 'back' => count($back), 'pct' => $pc(count($back), count($hit)), 'trend' => $backTr, 'trend_of_back' => $pc($backTr, count($back)),
            'trend_rate' => $pc($backTr, $hitTr), 'retrace_rate' => $pc(count($back) - $backTr, count($hit) - $hitTr),
            'list' => array_map(fn($x) => ['id' => $x['id'], 'd' => t_ist((int)$x['entry_time'], 'Y-m-d'), 'name' => $x['name'], 'market' => $x['market'], 'setup' => tr_setup($x), 'sig' => $x['sig'],
                't1' => $x['study']['lv'][0]['t_sl'], 'sl' => $x['study']['sl_t'], 'r' => $x['r']], $back)],
        'studied' => count($studiedSL), 'rows' => $rows];
    $L = [];
    if ($n) {
        $tr = $S['setup']['Trend'] ?? null; $rt = $S['setup']['Retracement'] ?? null;
        $L[] = "$n of " . count($T) . ' trades hit the stop-loss (' . $S['rate'] . '%).' . ($tr && $rt ? ' Trend: ' . $tr['sl'] . ' (' . $tr['rate'] . '% of trend trades) · Retracement: ' . $rt['sl'] . ' (' . $rt['rate'] . '% of retracements). '
            . ($tr['rate'] > $rt['rate'] + 3 ? 'Trend calls get stopped more often.' : ($rt['rate'] > $tr['rate'] + 3 ? 'Retracements get stopped more often.' : 'Both are stopped about equally.')) : '');
        $mk = array_filter($S['market'], fn($v) => $v['trades'] >= 5); uasort($mk, fn($a, $b) => $b['rate'] <=> $a['rate']);
        if ($mk) { $k = array_key_first($mk); $L[] = "Most stop-losses by market: $k (" . $mk[$k]['rate'] . '% of its trades, ' . $mk[$k]['sl'] . ' stops).'; }
        $L[] = 'Median time from entry to stop-loss: ' . $S['mins_med'] . ' min; ' . $S['fast15'] . '% within 15 min, ' . $S['fast60'] . '% within 1 hour. Median stop width ' . ($S['width_med'] ?? '–') . '× ATR5.'
            . ($S['fast15'] !== null && $S['fast15'] >= 30 ? ' Many stops come very fast: entries may be early or the stop too tight.' : '');
        if ($S['studied']) $L[] = 'After the stop-loss, price still reached T1 within 48 h in ' . $S['t1_later'] . '% of stopped trades'
            . ($S['t1_later'] >= 30 ? ': the direction was often right and the stop was hunted, so test a wider stop.' : ': mostly the direction was wrong, so the stop did its job.');
        $L[] = 'Re-entries: ' . $re['n'] . ' stopped trades (' . $S['reentry']['pct'] . '%) got a new entry on the same symbol within 12 h (' . $re['same_side'] . ' on the same side); those re-entries won ' . $re['won'] . ', were stopped again ' . $re['sl_again'] . ', net ' . ($re['net_r'] > 0 ? '+' : '') . $re['net_r'] . 'R.';
    }
    $b = $S['t1_then_sl'];
    if ($b['hit']) $L[] = 'T1 then stop-loss (if held, 48 h): ' . $b['back'] . ' of ' . $b['hit'] . ' trades that reached T1 later came back to the stop (' . $b['pct'] . '%); trend ' . ($b['trend_rate'] ?? '–') . '% · retracement ' . ($b['retrace_rate'] ?? '–') . '%. '
        . ($b['pct'] !== null && $b['pct'] >= 25 ? 'Taking profit or moving the stop at T1 protects these.' : 'Few give it all back after T1.');
    $S['lines'] = $L;
    return $S;
}
/** Telegram block with the conclusions. */
function ts_tg_lines(array $c): array {
    $L = ["\u{1F9EA} <b>48-hour target study</b>"];
    foreach ($c['lines'] as $x) $L[] = '• ' . htmlspecialchars($x);
    $L[] = ''; $L[] = '<b>Markets</b> (T1 / T2 before SL · best move):';
    foreach (array_slice($c['market'], 0, 6, true) as $k => $s) if ($s['studied']) $L[] = htmlspecialchars($k) . ': ' . $s['t1'] . '% / ' . $s['t2'] . '% · +' . $s['best_sl_r'] . 'R (' . $s['studied'] . ')';
    if (!empty($c['sl']['lines'])) { $L[] = ''; $L[] = "\u{1F6D1} <b>Stop-loss department</b>"; foreach ($c['sl']['lines'] as $x) $L[] = '• ' . htmlspecialchars($x); }
    return $L;
}
