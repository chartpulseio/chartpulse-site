<?php
/**
 * Data-loop worker endpoint (owner, 13 Nov 2026). Called back-to-back by the Oracle VPS (micro instance) so the
 * price loop never waits for a visitor or the 5-10 min Hostinger cron. Each call runs scan steps for ~25 s
 * (same t_scan_step every page visit already runs, so it is safe to expose) and answers with a tiny JSON.
 *   GET /worker.php            -> {"ok":1,"steps":N,"due":M,"ms":T}
 */
declare(strict_types=1);
require __DIR__ . '/torus_engine.php';
header('Content-Type: application/json'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
if (!t_rl('wk', 20)) { http_response_code(429); echo '{"ok":0,"why":"rate"}'; exit; }
ignore_user_abort(true); @set_time_limit(60);
$t0 = microtime(true); $n = 0; $busy = 0;
while (microtime(true) - $t0 < 36) {
    try { $r = t_scan_step(false, 32); } catch (Throwable $e) { $r = false; }   // 32 per step: scan.json read + write paid half as often
    if ($r) { $n++; continue; }
    // false = nothing due, or another request holds the lock: wait a moment and try again
    $busy++; if ($busy > 6) break; usleep(1500000);
}
// GEX warm (26 Nov 2026): refresh option-chain exposure for a few symbols per call, in rotation, so the
// GEX scanner (/scanners/gex/) and its intraday snapshots stay live without visitors. ~12 s budget.
$gx = 0;
try {
    require_once __DIR__ . '/gex_engine.php';
    // 1) indices + BTC/ETH every minute; 2) the rest in rotation, refreshed when older than ~2.5 min
    if (!is_dir(GEX_DIR)) @mkdir(GEX_DIR, 0755, true);
    $age = fn($s) => is_file(GEX_DIR . "/chain_$s.json") ? time() - filemtime(GEX_DIR . "/chain_$s.json") : 1e9;
    $g0 = microtime(true);
    foreach (GEX_PRIORITY as $s) { if (microtime(true) - $g0 >= 12) break; if ($age($s) >= 55) { gex_get_symbol($s, 'all'); $gx++; } }
    $gsyms = array_values(array_diff(GEX_US, GEX_PRIORITY));
    $gf = GEX_DIR . '/warm_idx.txt'; $gi = (int)@file_get_contents($gf); $gchk = 0;
    while (microtime(true) - $g0 < 12 && $gchk++ < count($gsyms)) {
        $s = $gsyms[$gi % count($gsyms)]; $gi++;
        if ($age($s) < 150) continue;
        gex_get_symbol($s, 'all'); $gx++;
    }
    @file_put_contents($gf, (string)($gi % 1000));
    try { gex_alerts_step(); } catch (Throwable $e) {}   // Telegram: gamma flip lost/regained, price at call/put wall
} catch (Throwable $e) {}
// Trend lines v3 (26 Nov 2026): 20-year daily history for the whole universe, a few symbols per call, refreshed every 3 days
try { require_once __DIR__ . '/tl_engine.php'; tl3c_gc(); tl3_fill(4, 5.0); } catch (Throwable $e) {}
$scan = t_scan_load(); $due = count(t_due($scan, t_now()));
@file_put_contents(T_DATA . '/worker_last.txt', date('c') . " steps $n due $due\n");
echo json_encode(['ok' => 1, 'steps' => $n, 'due' => $due, 'gex' => $gx, 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
