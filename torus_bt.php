<?php
/* ChartPulse Pro Backtesting - execution engine, metrics, Monte Carlo, walk-forward.
 * Server-only (blocked by .htaccess).
 *
 * Engine rules (do not change without knowing why - ported 1:1 from the reference engine):
 *  - a signal is made at the CLOSE of a bar, the order fills at the NEXT bar's OPEN
 *  - if stop-loss and target are both touched inside one bar, the STOP is taken
 *  - on a gap, the stop/target fills at the open price
 *  - slippage applies on market fills and stops, never on a limit (target) fill
 *  - if the account hits zero the position is force-closed and trading stops
 */

require_once __DIR__ . '/torus_bt_ind.php';

if (!defined('BT_CORE')) { define('BT_CORE', 1);

/* --------------------------------------------------------------- backtest */

function bt_run($d, $strategy, $capital = 10000.0, $commission = 0.001, $slippage = 0.0005,
                $size = 1.0, $start = 0)
{
    if ($capital <= 0 || !($size > 0 && $size <= 1) || $commission < 0 || $slippage < 0) {
        throw new BtError('capital must be > 0, size between 0 and 1, commission/slippage >= 0');
    }
    $rules = $strategy['rules'] ?? [];
    $risk  = $strategy['risk'] ?? [];
    $sl    = isset($risk['stop_loss_pct'])    ? (float)$risk['stop_loss_pct']    : null;
    $tp    = isset($risk['take_profit_pct'])  ? (float)$risk['take_profit_pct']  : null;
    $slA   = isset($risk['stop_loss_atr'])    ? (float)$risk['stop_loss_atr']    : null;
    $tpA   = isset($risk['take_profit_atr'])  ? (float)$risk['take_profit_atr']  : null;
    $trP   = isset($risk['trail_pct'])        ? (float)$risk['trail_pct']        : null;
    $trA   = isset($risk['trail_atr'])        ? (float)$risk['trail_atr']        : null;
    $maxB  = isset($risk['max_bars'])         ? (int)$risk['max_bars']           : null;
    $atrN  = isset($risk['atr_period'])       ? (int)$risk['atr_period']         : 14;
    $riskP = isset($risk['risk_pct'])         ? (float)$risk['risk_pct']         : null;

    $len = count($d['c']);
    $en_l = bt_all_rules($d, $rules['entry_long']  ?? []);
    $ex_l = bt_all_rules($d, $rules['exit_long']   ?? []);
    $en_s = bt_all_rules($d, $rules['entry_short'] ?? []);
    $ex_s = bt_all_rules($d, $rules['exit_short']  ?? []);
    $o = $d['o']; $h = $d['h']; $l = $d['l']; $c = $d['c'];

    $needAtr = ($slA !== null || $tpA !== null || $trA !== null);
    $atr = $needAtr ? bt_atr($d, $atrN) : null;

    $eq = (float)$capital; $pos = 0; $p = [];
    $trades = []; $curve = array_fill(0, $len, (float)$capital);
    $timeExit = false;

    $closeTrade = function ($i, $px, $reason, $slip = true) use (&$eq, &$pos, &$p, &$trades, $d, $slippage, $commission) {
        if ($slip) $px = $px * (1 - $slippage * $pos);   /* long sells lower, short buys higher */
        $fee = $p['qty'] * $px * $commission;
        $gross = ($px - $p['px']) * $p['qty'] * $pos;
        $net = $gross - $fee - $p['fee'];
        $eq += $gross - $fee;
        $trades[] = [
            'entry_time' => $d['t'][$p['i']], 'exit_time' => $d['t'][$i],
            'side' => $pos === 1 ? 'long' : 'short',
            'entry' => $p['px'], 'exit' => $px, 'pnl' => $net,
            'ret' => max($net / $p['eq0'], -1.0),
            'bars' => $i - $p['i'] + (($reason === 'signal' || $reason === 'time') ? 0 : 1),
            'reason' => $reason,
            'mfe_pct' => $p['mfe'], 'mae_pct' => $p['mae'],
        ];
        $pos = 0;
    };

    for ($i = max($start, 1); $i < $len; $i++) {
        /* 1) previous bar's signal -> act on this bar's open */
        if ($pos === 1 && ($ex_l[$i - 1] || $timeExit)) {
            $closeTrade($i, $o[$i], $timeExit ? 'time' : 'signal');
        } elseif ($pos === -1 && ($ex_s[$i - 1] || $timeExit)) {
            $closeTrade($i, $o[$i], $timeExit ? 'time' : 'signal');
        }
        $timeExit = false;

        if ($pos === 0 && ($en_l[$i - 1] || $en_s[$i - 1])) {
            $aref = ($atr !== null && !is_nan($atr[$i - 1])) ? $atr[$i - 1] : null;  /* signal bar's ATR */
            /* an ATR-based stop/target was asked for but ATR is not warmed up yet:
             * skip the entry rather than open an unprotected trade */
            if ($needAtr && $aref === null) { $curve[$i] = $eq; continue; }
            $pos = $en_l[$i - 1] ? 1 : -1;
            $px = $o[$i] * (1 + $slippage * $pos);

            /* stop / target levels are fixed at entry */
            $slPx = null; $tpPx = null;
            if ($sl !== null)  $slPx = $px * (1 - $pos * $sl / 100);
            if ($slA !== null && $aref !== null) {
                $cand = $px - $pos * $slA * $aref;
                $slPx = ($slPx === null) ? $cand : ($pos === 1 ? max($slPx, $cand) : min($slPx, $cand));
            }
            if ($tp !== null)  $tpPx = $px * (1 + $pos * $tp / 100);
            if ($tpA !== null && $aref !== null) {
                $cand = $px + $pos * $tpA * $aref;
                $tpPx = ($tpPx === null) ? $cand : ($pos === 1 ? min($tpPx, $cand) : max($tpPx, $cand));
            }
            if ($slPx !== null && (($pos === 1 && $slPx >= $px) || ($pos === -1 && $slPx <= $px))) $slPx = null;
            if ($tpPx !== null && (($pos === 1 && $tpPx <= $px) || ($pos === -1 && $tpPx >= $px))) $tpPx = null;

            /* position size: fixed fraction of equity, or risk-based when a stop exists */
            $qty = $eq * $size / $px;
            if ($riskP !== null && $slPx !== null) {
                $dist = abs($px - $slPx);
                if ($dist > 0) $qty = min($qty, ($eq * $riskP / 100) / $dist);
            }
            $fee = $qty * $px * $commission;
            $p = ['px' => $px, 'qty' => $qty, 'i' => $i, 'fee' => $fee, 'eq0' => $eq,
                  'sl' => $slPx, 'tp' => $tpPx, 'atr' => $aref, 'trail' => null,
                  'ext' => $px, 'mfe' => 0.0, 'mae' => 0.0];
            $eq -= $fee;
        }

        /* 2) inside the bar: stop-loss / trailing stop / target */
        if ($pos !== 0) {
            $slPx = $p['sl'];
            if ($p['trail'] !== null) {
                $slPx = ($slPx === null) ? $p['trail']
                      : ($pos === 1 ? max($slPx, $p['trail']) : min($slPx, $p['trail']));
            }
            $tpPx = $p['tp'];
            $hitSl = $slPx !== null && (($pos === 1 && $l[$i] <= $slPx) || ($pos === -1 && $h[$i] >= $slPx));
            $hitTp = $tpPx !== null && (($pos === 1 && $h[$i] >= $tpPx) || ($pos === -1 && $l[$i] <= $tpPx));

            /* track the best / worst excursion before deciding (reporting only) */
            $up = ($pos === 1 ? $h[$i] : $l[$i]); $dn = ($pos === 1 ? $l[$i] : $h[$i]);
            $p['mfe'] = max($p['mfe'], $pos * ($up - $p['px']) / $p['px'] * 100);
            $p['mae'] = min($p['mae'], $pos * ($dn - $p['px']) / $p['px'] * 100);

            if ($hitSl) {
                $closeTrade($i, $pos === 1 ? min($slPx, $o[$i]) : max($slPx, $o[$i]), 'stop');
            } elseif ($hitTp) {
                $closeTrade($i, $pos === 1 ? max($tpPx, $o[$i]) : min($tpPx, $o[$i]), 'target', false);
            } else {
                /* trail moves only AFTER the stop check, using this bar's extreme */
                if ($trP !== null || ($trA !== null && $p['atr'] !== null)) {
                    $p['ext'] = $pos === 1 ? max($p['ext'], $h[$i]) : min($p['ext'], $l[$i]);
                    $cands = [];
                    if ($trP !== null) $cands[] = $p['ext'] * (1 - $pos * $trP / 100);
                    if ($trA !== null && $p['atr'] !== null) $cands[] = $p['ext'] - $pos * $trA * $p['atr'];
                    foreach ($cands as $t) {
                        $p['trail'] = ($p['trail'] === null) ? $t
                            : ($pos === 1 ? max($p['trail'], $t) : min($p['trail'], $t));
                    }
                }
                if ($maxB !== null && ($i - $p['i'] + 1) >= $maxB) $timeExit = true;
            }
        }

        $curve[$i] = $eq + ($pos ? ($c[$i] - $p['px']) * $p['qty'] * $pos : 0.0);

        /* 3) account wiped out (possible on an unstopped short) -> force close, stop trading */
        if ($curve[$i] <= 0) {
            if ($pos !== 0) $closeTrade($i, $c[$i], 'liquidated', false);
            $eq = 0.0;
            for ($k = $i; $k < $len; $k++) $curve[$k] = 0.0;
            return [$curve, $trades];
        }
    }

    if ($pos !== 0) {
        $closeTrade($len - 1, $c[$len - 1], 'end');
        $curve[$len - 1] = $eq;
    }
    return [$curve, $trades];
}

/* ---------------------------------------------------------------- metrics */

function bt_fin($x)
{
    if ($x === null) return null;
    $x = (float)$x;
    return is_finite($x) ? $x : null;
}

function bt_pct($a, $q)       /* numpy percentile, linear interpolation */
{
    $n = count($a);
    if (!$n) return null;
    sort($a);
    $k = ($n - 1) * $q / 100;
    $f = (int)floor($k); $cl = (int)ceil($k);
    if ($f === $cl) return $a[$f];
    return $a[$f] + ($a[$cl] - $a[$f]) * ($k - $f);
}

function bt_metrics($curve, $trades, $times, $close = null, $start = 0)
{
    $n = count($curve);
    $cv = array_slice($curve, $start);
    $tt = array_slice($times, $start);
    $m = count($cv);
    $years = max(($tt[$m - 1] - $tt[0]) / (365.25 * 86400), 1e-9);
    $ppy = $m > 1 ? ($m - 1) / $years : 1.0;

    $ret = array_fill(0, $m, 0.0);
    for ($i = 1; $i < $m; $i++) {
        if ($cv[$i - 1] != 0.0) {
            $r = $cv[$i] / $cv[$i - 1] - 1;
            $ret[$i] = is_finite($r) ? $r : 0.0;
        }
    }
    $total = $cv[0] != 0.0 ? $cv[$m - 1] / $cv[0] - 1 : 0.0;

    $peak = $cv[0]; $maxDd = 0.0;
    for ($i = 0; $i < $m; $i++) {
        $peak = max($peak, $cv[$i]);
        if ($peak > 0) $maxDd = min($maxDd, $cv[$i] / $peak - 1);
    }

    $mean = array_sum($ret) / $m;
    $var = 0.0; $dsq = 0.0;
    foreach ($ret as $r) {
        $var += ($r - $mean) * ($r - $mean);
        if ($r < 0) $dsq += $r * $r;
    }
    $sd = $m > 1 ? sqrt($var / ($m - 1)) : 0.0;      /* pandas std(): ddof=1 */
    $down = sqrt($dsq / $m);

    if ($total <= -1)        $cagr = -1.0;
    elseif ($years >= 0.25)  $cagr = pow(1 + $total, 1 / $years) - 1;
    else                     $cagr = null;

    $nt = count($trades);
    $wins = 0.0; $losses = 0.0; $sumR = 0.0; $nWin = 0;
    $awSum = 0.0; $awN = 0; $alSum = 0.0; $alN = 0;
    $streak = 0; $worst = 0; $bars = 0;
    foreach ($trades as $t) {
        if ($t['pnl'] > 0) $wins += $t['pnl'];
        if ($t['pnl'] < 0) $losses -= $t['pnl'];
        $streak = $t['pnl'] < 0 ? $streak + 1 : 0;
        $worst = max($worst, $streak);
        $r = $t['ret']; $sumR += $r;
        if ($r > 0) { $nWin++; $awSum += $r; $awN++; }
        if ($r < 0) { $alSum += $r; $alN++; }
        $bars += $t['bars'];
    }
    $avgWin = $awN ? $awSum / $awN : 0.0;
    $avgLoss = $alN ? $alSum / $alN : 0.0;

    $out = [
        'total_return_pct'  => $total * 100,
        'cagr_pct'          => $cagr === null ? null : $cagr * 100,
        'max_drawdown_pct'  => $maxDd * 100,
        'sharpe'            => $sd > 0 ? $mean / $sd * sqrt($ppy) : 0.0,
        'sortino'           => $down > 0 ? $mean / $down * sqrt($ppy) : 0.0,
        'calmar'            => ($cagr !== null && $maxDd < 0) ? $cagr / abs($maxDd) : null,
        'win_rate_pct'      => $nt ? $nWin / $nt * 100 : 0.0,
        'profit_factor'     => $losses > 0 ? $wins / $losses : null,
        'avg_trade_pct'     => $nt ? $sumR / $nt * 100 : 0.0,
        'avg_win_pct'       => $avgWin * 100,
        'avg_loss_pct'      => $avgLoss * 100,
        'payoff_ratio'      => $avgLoss < 0 ? $avgWin / abs($avgLoss) : null,
        'exposure_pct'      => $nt ? min(100.0, $bars / $m * 100) : 0.0,
        'buy_hold_return_pct' => null,
    ];
    if ($close !== null) {
        $cl = array_slice($close, $start);
        $k = count($cl);
        if ($k > 1 && $cl[0] != 0.0) $out['buy_hold_return_pct'] = ($cl[$k - 1] / $cl[0] - 1) * 100;
    }
    foreach ($out as $k2 => $v) $out[$k2] = bt_fin($v);
    $out['trades'] = $nt;
    $out['max_consecutive_losses'] = $worst;
    return $out;
}

/* ------------------------------------------------------------ Monte Carlo
 * Resamples trade RETURNS (%) and compounds them, because position size moves
 * with equity. Treats trades as independent - streaks are not modelled. */
function bt_monte_carlo($trades, $capital = 10000.0, $runs = 1500, $seed = 42, $replace = true)
{
    $r = [];
    foreach ($trades as $t) $r[] = (float)$t['ret'];
    $n = count($r);
    if ($n < 5) return null;
    mt_srand($seed);
    $finals = []; $dds = []; $lossRuns = 0;
    for ($k = 0; $k < $runs; $k++) {
        if ($replace) {
            $seq = [];
            for ($j = 0; $j < $n; $j++) $seq[] = $r[mt_rand(0, $n - 1)];
        } else {
            $seq = $r; shuffle($seq);
        }
        $eq = $capital; $peak = $capital; $dd = 0.0;
        foreach ($seq as $x) {
            $eq *= (1 + $x);
            $peak = max($peak, $eq);
            if ($peak > 0) $dd = min($dd, ($eq - $peak) / $peak * 100);
        }
        $finals[] = $eq; $dds[] = $dd;
        if ($eq < $capital) $lossRuns++;
    }
    return [
        'runs' => $runs,
        'final_equity_p5'      => bt_fin(bt_pct($finals, 5)),
        'final_equity_median'  => bt_fin(bt_pct($finals, 50)),
        'final_equity_p95'     => bt_fin(bt_pct($finals, 95)),
        'median_drawdown_pct'  => bt_fin(bt_pct($dds, 50)),
        'bad_case_drawdown_pct' => bt_fin(bt_pct($dds, 5)),
        'prob_loss_pct'        => $lossRuns / $runs * 100,
    ];
}

/* ----------------------------------------------------------- walk-forward
 * Each window: find the best params on the train slice, then run them on the
 * next unseen test slice. Indicators always build from the full prior history,
 * the future is never visible. */
function bt_fill_params($obj, $prm)
{
    if (is_array($obj)) {
        $o = [];
        foreach ($obj as $k => $v) $o[$k] = bt_fill_params($v, $prm);
        return $o;
    }
    if (is_string($obj) && strlen($obj) > 1 && $obj[0] === '$') {
        $k = substr($obj, 1);
        if (!array_key_exists($k, $prm)) throw new BtError("The strategy uses \$$k but the grid has no such parameter");
        return $prm[$k];
    }
    return $obj;
}

function bt_slice($d, $to)
{
    $o = [];
    foreach ($d as $k => $v) $o[$k] = array_slice($v, 0, $to);
    return $o;
}

function bt_grid_combos($grid)
{
    $keys = array_keys($grid);
    $out = [[]];
    foreach ($keys as $k) {
        $next = [];
        foreach ($out as $row) foreach ($grid[$k] as $v) { $row[$k] = $v; $next[] = $row; }
        $out = $next;
    }
    return $out;
}

function bt_walk_forward($d, $strategyTpl, $grid, $trainBars, $testBars, $score = 'sharpe',
                         $minTrades = 5, $bt = [], $deadline = null)
{
    $combos = bt_grid_combos($grid);
    if (!$combos) throw new BtError('The grid is empty');
    $strategies = [];
    foreach ($combos as $cmb) $strategies[] = bt_validate_strategy(bt_fill_params($strategyTpl, $cmb));

    $len = count($d['c']);
    $cap = $bt['capital'] ?? 10000.0; $com = $bt['commission'] ?? 0.001;
    $slp = $bt['slippage'] ?? 0.0005; $sz = $bt['size'] ?? 1.0;

    $windows = []; $oos = []; $s = 0; $cut = false;
    while ($s + $trainBars + $testBars <= $len) {
        if ($deadline !== null && microtime(true) > $deadline) { $cut = true; break; }
        $trEnd = $s + $trainBars; $teEnd = $s + $trainBars + $testBars;
        $best = null; $bestSc = -INF;
        foreach ($combos as $ci => $prm) {
            [$cv, $tr] = bt_run(bt_slice($d, $trEnd), $strategies[$ci], $cap, $com, $slp, $sz, $s);
            $mm = bt_metrics($cv, $tr, array_slice($d['t'], 0, $trEnd), null, $s);
            $sc = $mm[$score] ?? null;
            if ($mm['trades'] >= $minTrades && $sc !== null && $sc > $bestSc) {
                $best = [$prm, $strategies[$ci]]; $bestSc = $sc;
            }
        }
        if ($best === null) {
            $windows[] = ['train_end' => $d['t'][$trEnd - 1], 'params' => null,
                          'note' => 'Not enough trades in training - window skipped'];
        } else {
            [$cv, $tr] = bt_run(bt_slice($d, $teEnd), $best[1], $cap, $com, $slp, $sz, $trEnd);
            $mm = bt_metrics($cv, $tr, array_slice($d['t'], 0, $teEnd), null, $trEnd);
            $windows[] = array_merge(['train_end' => $d['t'][$trEnd - 1], 'params' => $best[0],
                                      'train_score' => bt_fin($bestSc)], $mm);
            $oos[] = $mm['total_return_pct'] / 100;
        }
        $s += $testBars;
    }
    $comp = null;
    if ($oos) { $comp = 1.0; foreach ($oos as $r) $comp *= (1 + $r); $comp = ($comp - 1) * 100; }
    $prof = 0;
    foreach ($oos as $r) if ($r > 0) $prof++;
    return [
        'windows' => $windows,
        'n_windows' => count($windows),
        'tested_windows' => count($oos),
        'profitable_windows' => $prof,
        'oos_compound_return_pct' => bt_fin($comp),
        'cut_short' => $cut,
    ];
}

}
