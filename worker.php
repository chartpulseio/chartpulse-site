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
while (microtime(true) - $t0 < 40) {
    try { $r = t_scan_step(false, 32);   // bigger chunk: the per-step cost (scan.json read + write) is paid half as often } catch (Throwable $e) { $r = false; }
    if ($r) { $n++; continue; }
    // false = nothing due, or another request holds the lock: wait a moment and try again
    $busy++; if ($busy > 6) break; usleep(1500000);
}
$scan = t_scan_load(); $due = count(t_due($scan, t_now()));
@file_put_contents(T_DATA . '/worker_last.txt', date('c') . " steps $n due $due\n");
echo json_encode(['ok' => 1, 'steps' => $n, 'due' => $due, 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
