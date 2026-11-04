<?php
require __DIR__.'/torus_engine.php';
require_once __DIR__.'/torus_tier2.php';
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: application/json');

$path = T_DATA . '/t2trades.json';
$diag = ['path' => $path, 'exists' => file_exists($path), 'size' => file_exists($path) ? filesize($path) : null, 'readable' => is_readable($path)];
$j = t_read_json($path, ['items' => []]);
$items = $j['items'] ?? [];
$now = time();
if (count($items) === 0) {
    $cronLast = @file_get_contents(T_DATA . '/cron_last.txt');
    $dataFiles = is_dir(T_DATA) ? array_values(array_diff(scandir(T_DATA), ['.', '..'])) : null;
    $sub = [];
    foreach (['frames', 'm1', 't2', 'rl'] as $d) { $p = T_DATA . '/' . $d; $sub[$d] = is_dir($p) ? array_slice(array_values(array_diff(scandir($p), ['.', '..'])), 0, 10) : null; }
    echo json_encode(['diag' => $diag, 'raw_keys' => array_keys($j), 'cron_last' => $cronLast, 'data_dir_files' => $dataFiles, 'sub' => $sub]);
    exit;
}

$trend = []; $retrace = [];
foreach ($items as $t) {
    if ((int)($t['rules'] ?? 0) !== 10) continue;
    if (($t['status'] ?? '') !== 'closed') continue;
    if (($t['setup'] ?? '') === 'trend') $trend[] = $t;
    elseif (($t['setup'] ?? '') === 'retrace') $retrace[] = $t;
}

// ---- which tick triggered entry, per trade (compare entry_time to the ticks[] timestamps) ----
$tickNames = [1 => 'Tick1 Early Entry', 2 => 'Tick2 Entry A', 3 => 'Tick3 Reversal Pat', 4 => 'Tick4 Double Confirm'];
$tickStats = []; // idx => ['n'=>, 'sl'=>, 'win'=>]
foreach (array_merge($trend, $retrace) as $t) {
    $te = $t['entry_time'] ?? null; $ticks = $t['ticks'] ?? null;
    if ($te === null || !is_array($ticks)) continue;
    $idx = null;
    foreach ([1, 2, 3, 4] as $q) if (($ticks[$q] ?? null) === $te) { $idx = $q; break; }
    if ($idx === null) continue;
    if (!isset($tickStats[$idx])) $tickStats[$idx] = ['n' => 0, 'sl' => 0, 'win' => 0, 'r_sum' => 0.0];
    $tickStats[$idx]['n']++;
    $r = (float)($t['r'] ?? 0);
    $tickStats[$idx]['r_sum'] += $r;
    if (($t['reason'] ?? '') === 'Stop-loss') $tickStats[$idx]['sl']++;
    if ($r > 0) $tickStats[$idx]['win']++;
}
$tickOut = [];
foreach ($tickStats as $idx => $s) {
    $tickOut[] = ['tick' => $tickNames[$idx] ?? ('Tick' . $idx), 'n' => $s['n'],
        'win_pct' => round($s['win'] / $s['n'] * 100, 1), 'sl_pct' => round($s['sl'] / $s['n'] * 100, 1),
        'avg_r' => round($s['r_sum'] / $s['n'], 2)];
}

// ---- zone (target_label) stats: which strong-zone label got touched/hit most often ----
$zoneStats = [];
foreach (array_merge($trend, $retrace) as $t) {
    $lab = $t['target_label'] ?? null; if (!$lab) continue;
    // classify
    $cls = $lab;
    if (in_array($lab, ['PDH', 'PDL', 'PDC'])) $cls = 'PDH/PDL/PDC';
    elseif (preg_match('/^[UPD]\d*$/', $lab) || $lab === 'P0') $cls = 'Pivot';
    elseif (is_numeric($lab) || stripos($lab, 'fib') !== false || preg_match('/^0\.\d+/', $lab)) $cls = 'Fib';
    if (!isset($zoneStats[$cls])) $zoneStats[$cls] = ['n' => 0, 'touched' => 0];
    $zoneStats[$cls]['n']++;
    $hit = !empty($t['touched']) || ($t['reason'] ?? '') === 'Fib TP1';
    if ($hit) $zoneStats[$cls]['touched']++;
}
$zoneOut = [];
foreach ($zoneStats as $cls => $s) $zoneOut[] = ['zone' => $cls, 'n' => $s['n'], 'tp_hit_pct' => round($s['touched'] / $s['n'] * 100, 1)];
usort($zoneOut, fn($a, $b) => $b['n'] <=> $a['n']);

// ---- trend: how far calls ran (MFE) ----
$trendR = array_map(fn($t) => (float)($t['mfe_r'] ?? 0), $trend);
$trendPct = array_map(fn($t) => (float)($t['mfe_pct'] ?? 0), $trend);
$avg = fn($a) => count($a) ? round(array_sum($a) / count($a), 3) : null;
sort($trendR); $med = fn($a) => count($a) ? $a[intdiv(count($a), 2)] : null;

// ---- retracement: where TP typically closed ----
$rtHit = array_filter($retrace, fn($t) => ($t['reason'] ?? '') === 'Fib TP1');
$rtR = array_map(fn($t) => (float)($t['r'] ?? 0), $rtHit);
$rtPct = array_map(fn($t) => (float)($t['pct'] ?? 0), $rtHit);

echo json_encode([
    'window' => ['from' => date('Y-m-d', $now - 45 * 86400), 'to' => date('Y-m-d', $now), 'total_items' => count($items)],
    'counts' => ['trend_closed' => count($trend), 'retrace_closed' => count($retrace)],
    'tick_stats' => $tickOut,
    'zone_stats' => $zoneOut,
    'trend_run' => ['n' => count($trend), 'avg_mfe_r' => $avg($trendR), 'median_mfe_r' => $med($trendR), 'avg_mfe_pct' => $avg($trendPct)],
    'retrace_tp' => ['n_hit' => count($rtHit), 'avg_r' => $avg($rtR), 'avg_pct' => $avg($rtPct)],
], JSON_PRETTY_PRINT);
