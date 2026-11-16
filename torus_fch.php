<?php
/* Forecast history for backtests (owner, 16 Nov 2026).
 * The chartpulse-ml job "Forecast history for AI Backtesting" walks through history with Chronos-2, TimesFM 2.5,
 * Moirai 2.0 and Kronos: at each closed candle it stores the model's forecast of the NEXT close, made only from
 * candles up to that one. Files: ml_dir()/fch/<SYM>_<tf>_<model>.json  rows [[t, close, p50_next], ...].
 * Backtest indicators:  ai_votes = models saying up minus models saying down (-4..4)
 *                       ai_move  = average expected next-candle move in %   (both known at the close of candle t)
 */
require_once __DIR__ . '/torus_paths.php';
const FCH_MODELS = ['chronos2' => 'Chronos-2', 'timesfm' => 'TimesFM 2.5', 'moirai2' => 'Moirai 2.0', 'kronos' => 'Kronos'];
const FCH_TFS = ['1h' => 3600, '1d' => 86400];
function fch_dir(): string { $d = cp_data_dir() . '/ml/fch'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function fch_rj(string $f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : null; }
function fch_key(string $sym): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper($sym)); }
function fch_store(array $in): array {
    $m = (string)($in['model'] ?? ''); $tf = (string)($in['tf'] ?? ''); $sym = (string)($in['sym'] ?? '');
    if (!isset(FCH_MODELS[$m]) || !isset(FCH_TFS[$tf]) || $sym === '' || !is_array($in['rows'] ?? null)) return ['error' => 'bad payload'];
    $rows = [];
    foreach ($in['rows'] as $r) if (is_array($r) && count($r) === 3 && is_numeric($r[0]) && is_numeric($r[1]) && is_numeric($r[2]) && $r[1] > 0) $rows[] = [(int)$r[0], (float)$r[1], (float)$r[2]];
    if (count($rows) < 50) return ['error' => 'too few rows'];
    $f = fch_dir() . '/' . fch_key($sym) . "_{$tf}_$m.json"; @file_put_contents($f . '.tmp', json_encode(['at' => time(), 'sym' => $sym, 'tf' => $tf, 'model' => $m, 'rows' => $rows]), LOCK_EX); @rename($f . '.tmp', $f);
    return ['ok' => true, 'rows' => count($rows)];
}
/** Which markets / timeframes have history: [sym => [tf => [models]]] */
function fch_index(): array {
    $o = [];
    foreach (glob(fch_dir() . '/*_*_*.json') ?: [] as $f) if (preg_match('~/([A-Z0-9]+)_(1h|1d)_([a-z0-9]+)\.json$~', $f, $m)) $o[$m[1]][$m[2]][] = $m[3];
    return $o;
}
function fch_has(string $sym, string $tf): bool { return !empty(fch_index()[fch_key($sym)][$tf]); }
/** Per bar: [votes, avg move %, models counted] or null, aligned to $d['t'] (forecast from the same candle or the closest earlier one in the same step). */
function fch_align(array $d): array {
    static $cache = [];
    $sym = (string)($d['sym'] ?? ''); $tf = (string)($d['tf'] ?? ''); $ck = $sym . '|' . $tf . '|' . count($d['t']);
    if (isset($cache[$ck])) return $cache[$ck];
    $len = count($d['t']); $out = array_fill(0, $len, null);
    if (!isset(FCH_TFS[$tf])) return $cache[$ck] = $out;
    $step = FCH_TFS[$tf]; $acc = array_fill(0, $len, null);
    foreach (array_keys(FCH_MODELS) as $m) {
        $j = fch_rj(fch_dir() . '/' . fch_key($sym) . "_{$tf}_$m.json"); if (!is_array($j)) continue;
        $R = $j['rows']; $k = 0; $nR = count($R);
        for ($i = 0; $i < $len; $i++) {
            $t = (int)$d['t'][$i];
            while ($k + 1 < $nR && $R[$k + 1][0] <= $t) $k++;
            if ($nR && $R[$k][0] <= $t && $t - $R[$k][0] < $step) {
                $c = $R[$k][1]; $mv = ($R[$k][2] / $c - 1) * 100;
                $a = $acc[$i] ?? [0, 0.0, 0]; $a[0] += $mv > 0 ? 1 : ($mv < 0 ? -1 : 0); $a[1] += $mv; $a[2]++; $acc[$i] = $a;
            }
        }
    }
    for ($i = 0; $i < $len; $i++) if ($acc[$i]) $out[$i] = [$acc[$i][0], $acc[$i][1] / $acc[$i][2], $acc[$i][2]];
    return $cache[$ck] = $out;
}
function fch_series(array $d, string $kind): array {
    if (!fch_has((string)($d['sym'] ?? ''), (string)($d['tf'] ?? ''))) throw new BtError(fch_missing());
    $A = fch_align($d); $o = [];
    foreach ($A as $i => $a) $o[$i] = $a === null ? NAN : ($kind === 'ai_votes' ? (float)$a[0] : (float)$a[1]);
    return $o;
}
function fch_missing(): string {
    $names = ['NSEI' => 'Nifty 50', 'NSEBANK' => 'Bank Nifty', 'BTCUSD' => 'Bitcoin', 'GCF' => 'Gold', 'EURUSDX' => 'EUR/USD']; $have = [];
    foreach (fch_index() as $k => $tfs) $have[] = ($names[$k] ?? $k) . ' (' . implode(', ', array_map(fn($t) => $t === '1d' ? 'daily' : '1 hour', array_keys($tfs))) . ')';
    return 'AI forecast rules can only be tested where forecast history exists: ' . ($have ? implode(', ', $have) : 'it is still being built, check back in a few hours') . '.';
}
/** After a backtest: how often the four models agreed with each trade at entry, and how those trades did. */
function fch_check(array $d, array $trades): ?array {
    if (!fch_has((string)($d['sym'] ?? ''), (string)($d['tf'] ?? '')) || !$trades) return null;
    $A = fch_align($d); $pos = array_flip(array_map('intval', $d['t']));
    $g = ['with' => [0, 0, 0.0], 'against' => [0, 0, 0.0], 'split' => [0, 0, 0.0]]; $seen = 0;
    foreach ($trades as $t) {
        $i = $pos[(int)$t['entry_time']] ?? null; if ($i === null) continue; $i--;   // filled on the next bar: signal bar is the one before
        $a = $A[$i] ?? null; if (!$a) continue; $seen++;
        $dir = ($t['side'] ?? 'long') === 'short' ? -1 : 1; $v = $a[0] * $dir;
        $k = $v >= $a[2] - 1 && $v > 0 ? 'with' : ($v <= -($a[2] - 1) && $v < 0 ? 'against' : 'split');
        $g[$k][0]++; if ($t['pnl'] > 0) $g[$k][1]++; $g[$k][2] += $t['ret'];
    }
    if ($seen < 3) return null;
    $f = fn($x) => ['trades' => $x[0], 'win_pct' => $x[0] ? round(100 * $x[1] / $x[0], 1) : null, 'avg_ret_pct' => $x[0] ? round(100 * $x[2] / $x[0], 3) : null];
    return ['checked' => $seen, 'with' => $f($g['with']), 'against' => $f($g['against']), 'split' => $f($g['split']), 'models' => array_values(FCH_MODELS)];
}
