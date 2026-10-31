<?php
/**
 * ChartPulse — Daily Bottom Reversal Scanner API
 * Reuses torus_engine.php data-fetching infrastructure (Yahoo Finance + Upstox).
 * Endpoint: GET dd_br_api.php  →  { updated, signals: [...] }
 *
 * Caches results in data/br_signals.json, refreshes every 15 minutes.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://chartpulse.io');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/dd_br_engine.php';

define('BR_CACHE', T_DATA . '/br_signals.json');
define('BR_STALE', 900);   // 15 minutes

$now = time();

// Return cached results if fresh
if (is_file(BR_CACHE)) {
    $cached = json_decode((string)file_get_contents(BR_CACHE), true);
    if ($cached && isset($cached['ts']) && $now - $cached['ts'] < BR_STALE) {
        echo json_encode(['updated' => $cached['updated'], 'signals' => $cached['signals']]);
        exit;
    }
}

// Run scan using existing torus frames (already fetched by the main scanner)
t_ensure_dirs();
$symbols = t_symbols();   // sym => market
$allSignals = [];

$SHOW = ['ENTRY', 'PATTERN', 'GATE', 'E0', 'E1', 'E2', 'E3', 'SWEPT'];

foreach ($symbols as $sym => $market) {
    $fr = t_read_frames($sym);
    if (!$fr || empty($fr['1H']['t']) || empty($fr['1D']['t'])) continue;

    try {
        $signals = br_scan($sym, $fr, $market);
        foreach ($signals as $s) {
            if (!empty($s['alert']) || in_array($s['stage'] ?? '', $SHOW)) {
                $allSignals[] = $s;
            }
        }
    } catch (\Throwable $e) {
        // skip symbol on error
    }
}

// Sort by rank descending
usort($allSignals, fn($a, $b) => ($b['rank'] ?? 0) <=> ($a['rank'] ?? 0));

$result = [
    'updated' => gmdate('Y-m-d\TH:i:s\Z'),
    'signals' => $allSignals,
    'ts'      => $now,
];

// Cache
@file_put_contents(BR_CACHE, json_encode($result, JSON_UNESCAPED_SLASHES));

// Output (without ts)
echo json_encode(['updated' => $result['updated'], 'signals' => $result['signals']]);
