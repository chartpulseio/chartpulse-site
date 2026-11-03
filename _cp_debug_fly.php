<?php
ini_set('display_errors', '1'); error_reporting(E_ALL);
set_exception_handler(function($e){ header('Content-Type: application/json'); echo json_encode(['fatal'=>$e->getMessage()]); exit; });
require __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_tier2.php';
header('Content-Type: application/json');

$j = t_read_json(T_DATA . '/t2trades.json', ['items' => []]);
$items = $j['items'] ?? [];
$now = t_now();
$cut = $now - 30 * 86400;

$labels = ['GATE', 'TICK1 Early Entry', 'TICK2 Entry A', 'TICK3 Reversal Pat', 'TICK4 Double Confirm'];
$stats = []; // idx => ['n'=>0,'sl'=>0]
foreach ([1,2,3,4] as $i) $stats[$i] = ['n' => 0, 'sl' => 0, 'tp' => 0, 'other' => 0, 'r_sum' => 0.0];

$total10 = 0; $matched = 0; $unmatched = 0; $closedTotal = 0;
foreach ($items as $t) {
    if ((int)($t['rules'] ?? 0) !== 10) continue;   // only v10 (ticks-based) trades
    if ((int)($t['entry_time'] ?? 0) < $cut) continue;
    $total10++;
    if (($t['status'] ?? '') !== 'closed') continue;
    $closedTotal++;
    $ticks = $t['ticks'] ?? null; $et = (int)($t['entry_time'] ?? 0);
    if (!is_array($ticks)) { $unmatched++; continue; }
    $idx = null;
    foreach ([1,2,3,4] as $i) if ((int)($ticks[$i] ?? 0) === $et) { $idx = $i; break; }
    if ($idx === null) { $unmatched++; continue; }
    $matched++;
    $stats[$idx]['n']++;
    $reason = $t['reason'] ?? '';
    if ($reason === 'Stop-loss') $stats[$idx]['sl']++;
    elseif ($reason === 'Fib TP1' || (float)($t['r'] ?? 0) > 0) $stats[$idx]['tp']++;
    else $stats[$idx]['other']++;
    $stats[$idx]['r_sum'] += (float)($t['r'] ?? 0);
}

$out = ['window_days' => 30, 'total_v10_trades_in_window' => $total10, 'closed' => $closedTotal, 'matched_to_a_tick' => $matched, 'unmatched' => $unmatched, 'by_tick' => []];
foreach ([1,2,3,4] as $i) {
    $s = $stats[$i]; $n = $s['n'];
    $out['by_tick'][] = [
        'tick' => $labels[$i],
        'trades' => $n,
        'sl_hit' => $s['sl'],
        'sl_hit_pct' => $n ? round($s['sl'] / $n * 100, 1) : null,
        'tp_hit' => $s['tp'],
        'other_exit' => $s['other'],
        'avg_r' => $n ? round($s['r_sum'] / $n, 2) : null,
    ];
}
echo json_encode($out, JSON_PRETTY_PRINT);
