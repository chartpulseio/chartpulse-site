<?php
/**
 * ChartPulse — 200 EMA Scanner API.
 *
 * GET ema200_api.php?side=ALL|LONG|SHORT&entry=best,confirmed,early,watch&min_score=N
 *   -> { updated, counts, scanned, skipped, signals: [...] }
 *
 * Data comes from the daily frames the Tier 1 / Tier 2 scanner already wrote
 * (t_read_frames), so this endpoint makes no market-data calls of its own.
 * Full results are cached in data/ema200_signals.json and refreshed every 15
 * minutes; the query string only filters the cached set.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://chartpulse.io');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/ema200_engine.php';
require_once __DIR__ . '/tl_engine.php';   // MTF trend + key zones, the same code the Trend Line Scanner uses

define('E2_CACHE', T_DATA . '/ema200_signals2.json');
define('E2_STALE', 900);

/** Per-stage default minimum score when the caller does not pass min_score. */
const E2_DEFAULT_MIN = ['best' => 2, 'confirmed' => 2, 'early' => 1, 'watch' => 1];

$now = time();
$all = null;

if (is_file(E2_CACHE)) {
    $cached = json_decode((string)file_get_contents(E2_CACHE), true);
    if ($cached && isset($cached['ts']) && $now - $cached['ts'] < E2_STALE) $all = $cached;
}

if ($all === null) {
    t_ensure_dirs();
    $rows = []; $scanned = 0; $skipped = 0;
    $hasTv = function_exists('t_tv_code');

    foreach (t_symbols() as $sym => $market) {
        $fr = t_read_frames($sym);
        $d  = $fr['1D'] ?? null;
        if (!$d || empty($d['c'])) { $skipped++; continue; }

        try {
            $r = e2_scan($d);
        } catch (\Throwable $e) {
            $skipped++; continue;           // detail is not leaked to the client
        }
        if ($r === null) { $skipped++; continue; }   // fewer than 260 daily bars
        $scanned++;
        if ($r['state'] === 'NONE' || $r['stage'] === null) continue;

        $px = (float)end($d['c']);
        $row = e2_row($sym, $market, $r, $px, $hasTv ? t_tv_code($sym, $market) : null);
        // owner, 11 Nov 2026: same quality labels as the Trend Line Scanner.
        //   MTF trend  : Supertrend on 30m 1H 4H 1D 1W; a bias needs 75%+ of the available ones
        //   key zone   : is the 200 EMA itself sitting on a day / PD / weekly / monthly level, a pivot or a Fib?
        //   grade      : Top  = trend with the signal + Confirmed or Best stage + a zone on the EMA + 2 of 3 filters
        //                Good = trend with the signal + (Confirmed or Best stage, or a zone); Watch rows stay Basic
        try {
            $mtf = tl_mtf(tl_src($fr, $fr['5m'] ?? null));
            $A = t_atr($d, 14); $atrD = (float)end($A);
            $zone = $atrD > 0 ? tl_match(tl_zones($fr), (float)$r['ema200'], 0.1 * $atrD) : null;
        } catch (\Throwable $e) { $mtf = ['d' => [], 'up' => 0, 'down' => 0, 'n' => 0, 'bias' => null]; $zone = null; }
        $with = $mtf['bias'] === $row['signal']; $stage = (int)$row['stage'];
        $row['name'] = t_name($sym);
        $row['mtf'] = $mtf; $row['with'] = $with; $row['against'] = $mtf['bias'] !== null && !$with;
        $row['zone'] = $zone ? ['t' => $zone['t'], 'n' => $zone['n']] : null;
        $row['grade'] = $stage === 0 ? 1 : (($with && $stage >= 2 && $zone && $row['score'] >= 2) ? 3 : (($with && ($stage >= 2 || $zone)) ? 2 : 1));
        $rows[] = $row;
    }

    // Best setups first: grade, then stage (Best > Confirmed > Early > Watch), then a strong zone, then filters agreeing
    $zr = fn($x) => $x['zone'] ? ($x['zone']['t'] === 'strong' ? 2 : 1) : 0;
    usort($rows, fn($a, $b) => [$b['grade'], $b['stage'], $zr($b), $b['strength']] <=> [$a['grade'], $a['stage'], $zr($a), $a['strength']]);

    $all = [
        'updated' => gmdate('Y-m-d\TH:i:s\Z'),
        'scanned' => $scanned, 'skipped' => $skipped,
        'signals' => $rows, 'ts' => $now,
    ];
    @file_put_contents(E2_CACHE, json_encode($all, JSON_UNESCAPED_SLASHES));
}

/* ---------------------------------------------------------------- filters */
$side = strtoupper((string)($_GET['side'] ?? 'ALL'));
if (!in_array($side, ['ALL', 'LONG', 'SHORT'], true)) $side = 'ALL';

$entry = array_keys(E2_DEFAULT_MIN);
if (isset($_GET['entry']) && $_GET['entry'] !== '') {
    $want = array_filter(array_map('trim', explode(',', strtolower((string)$_GET['entry']))));
    $want = array_values(array_intersect($want, $entry));
    if ($want) $entry = $want;
}

$minScore = null;
if (isset($_GET['min_score']) && $_GET['min_score'] !== '') {
    $minScore = max(0, min(3, (int)$_GET['min_score']));
}

$quality = strtolower((string)($_GET['quality'] ?? 'all'));
$mkt = strtolower((string)($_GET['mkt'] ?? 'all'));   // all | forex | crypto | other (stocks, indices, futures)
if (!in_array($mkt, ['all', 'forex', 'crypto', 'other'], true)) $mkt = 'all';
$out = [];
foreach ($all['signals'] as $s) {
    if ($side !== 'ALL' && $s['side'] !== $side) continue;
    if ($mkt !== 'all' && tl_class($s['symbol']) !== $mkt) continue;
    if (!in_array($s['entry_type'], $entry, true)) continue;
    $need = $minScore ?? (E2_DEFAULT_MIN[$s['entry_type']] ?? 0);
    if ($s['score'] < $need) continue;
    if ($quality === 'top' && $s['grade'] < 3) continue;
    if ($quality === 'with' && !$s['with']) continue;
    if ($quality === 'zone' && !$s['zone']) continue;
    $out[] = $s;
}

$counts = ['best' => 0, 'confirmed' => 0, 'early' => 0, 'watch' => 0];
foreach ($out as $s) $counts[$s['entry_type']]++;

echo json_encode([
    'updated' => $all['updated'],
    'scanned' => $all['scanned'], 'skipped' => $all['skipped'],
    'counts'  => $counts,
    'signals' => $out,
], JSON_UNESCAPED_SLASHES);
