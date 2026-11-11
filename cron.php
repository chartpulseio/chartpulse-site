<?php
// Cron (Hostinger, every 5 min): php cron.php
//   1. scan steps until nothing is due or ~50 s pass (keeps Tier 1 and the signal log running without visitors)
//   2. replays recent Tier-1 signals into Tier-2 trades (Recent trades list)
//   3. after 08:00 IST: builds yesterday's research report once and sends it to the admin Telegram chat
//   4. saves today's market movers (archive pages /movers/DATE) and, after 21:00 IST, posts them once to the Telegram channel
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/torus_engine.php';
require __DIR__ . '/torus_report.php';
require __DIR__ . '/torus_seo.php';
@set_time_limit(280);
$t0 = time();
while (time() - $t0 < 50 && t_scan_step(false, 20)) { }
t2_trades_refresh(10);
$m = tr_daily(t_now());
try { require_once __DIR__ . '/torus_desk.php'; $mx = trim(ml_keepalive() . ' ' . qd_backfill_step() . ' ' . ta_step()); if ($mx !== '') $m = trim(($m ?? '') . ' · ' . $mx, ' ·'); } catch (Throwable $e) { $m = trim(($m ?? '') . ' · ml ' . $e->getMessage(), ' ·'); }   // AI Scan: keep the ML Space awake, fill Qdrant
try { seo_movers_snapshot(t_now()); $tg = seo_tg_daily(t_now()); if ($tg) $m = trim(($m ?? '') . ' · channel ' . $tg, ' ·'); $ix = seo_indexnow(t_now()); if ($ix) $m = trim(($m ?? '') . ' · ' . $ix, ' ·'); } catch (Throwable $e) { $m = trim(($m ?? '') . ' · seo ' . $e->getMessage(), ' ·'); }
$line = 'ok ' . date('c') . ($m ? " · $m" : '');
@file_put_contents(T_DATA . '/cron_last.txt', $line . "\n");   // last run, for checking the schedule
echo $line . "\n";
