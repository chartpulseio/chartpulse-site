<?php
// Telegram alerts for the scanners (owner, 12 Nov 2026).
//   Hybrid Trend Line Scanner : price AT THE LINE, REJECTION, FAILED BREAK, or BROKEN through the line
//   Trend Line Scanner        : Weekly / Daily / 4H lines only -- price comes to a line, or breaks it
//   200 EMA Scanner (daily)   : a new Early / Confirmed / Best signal, or a signal moving up a stage
// Run from cron.php every 5 minutes. Only NEW events are sent: the first run only records what is already
// there, and the same event on the same market is not repeated inside its quiet period.
// One short digest per scanner per run, best setups first, so the chat is not flooded.
// Goes to config 'telegram_admin_chat'. Set config 'telegram_alert_to' => 'subs' to send to every bot subscriber.
declare(strict_types=1);
if (!defined('T_DATA') && PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/tl_engine.php';

const TGA_MAX = 15;                                  // lines per digest; the rest is a count and a link
const TGA_QUIET = ['h' => 6 * 3600, 't' => 12 * 3600, 'e' => 24 * 3600];
const TGA_TFS = ['1W', '1D', '4H'];
const TGA_TFN = ['1W' => 'Weekly', '1D' => 'Daily', '4H' => '4H', '1H' => '1H', '30m' => '30m', '15m' => '15m'];

function tga_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function tga_send(string $html): array {
    $to = strtolower(trim((string)t_cfg('telegram_alert_to', 'admin')));
    if ($to === 'subs') { tg_broadcast($html); return [true, 'subs']; }
    $chat = trim((string)t_cfg('telegram_admin_chat', ''));
    if ($chat === '') return [false, 'admin chat is not set in config'];
    [$ok, $info] = t2_tg_send($html, $chat);
    return [$ok, $ok ? 'admin' : $info];
}

/** Keep the events that are new (not sent inside the quiet period); remember them. $ev: key => [rank, line]. */
function tga_new(array &$S, string $k, array $ev, int $now): array {
    $seen = $S[$k] ?? []; $out = [];
    foreach ($ev as $key => $x) {
        if (!isset($seen[$key]) || $now - (int)$seen[$key] >= TGA_QUIET[$k]) $out[$key] = $x;
        $seen[$key] = isset($out[$key]) ? $now : $seen[$key];
    }
    foreach ($seen as $key => $t) if ($now - (int)$t > 3 * TGA_QUIET[$k]) unset($seen[$key]);
    $S[$k] = $seen;
    return $out;
}

function tga_digest(string $title, array $ev, string $url): string {
    uasort($ev, fn($a, $b) => $b[0] <=> $a[0]);
    $L = ['<b>' . $title . '</b>', ''];
    foreach (array_slice($ev, 0, TGA_MAX, true) as $x) $L[] = $x[1];
    if (count($ev) > TGA_MAX) $L[] = '+ ' . (count($ev) - TGA_MAX) . ' more on the page';
    $L[] = ''; $L[] = $url; $L[] = 'Education only, not investment advice';
    return implode("\n", $L);
}

function tga_zone(?string $t): string { return $t === 'strong' ? ' · strong zone' : ($t === 'major' ? ' · major zone' : ''); }

/** Run a stepped scan to the end (each call works ~18 s; without visitors the row cache is cold). */
function tga_full(callable $scan, string $payload): array {
    $p = $scan();
    for ($i = 0; $i < 4 && (float)($p['meta']['progress'] ?? 0) < 0.9; $i++) { @unlink(T_DATA . '/tl/' . $payload); $p = $scan(); }
    return $p;
}

function tga_step(int $now): string {
    if (trim((string)t_cfg('telegram_bot_token', '')) === '') return '';
    $f = T_DATA . '/tg_scan_alerts.json';
    $S = t_read_json($f, []); if (!is_array($S)) $S = [];
    $sent = []; $err = '';

    // ---- Hybrid Trend Line Scanner
    try {
        $p = tga_full('tlh_scan', '_hscan5.json'); $ev = [];
        if ((float)($p['meta']['progress'] ?? 0) >= 0.9) {
            foreach ($p['rows'] as $r) {
                $st = (int)$r['stage']; if ($st < 2 || empty($r['live'])) continue;
                $side = $r['bias'] === 'PUT' ? 'resistance' : 'support';
                $what = [5 => 'broke the line and came back (failed break)', 4 => 'touched the line and turned (rejection)', 3 => 'is at the line', 2 => 'broke through the line'][$st];
                $ev[$r['symbol'] . '|' . $st] = [[$st, $r['key'][2] ?? 0, $r['touches']],
                    ($st >= 4 ? "\u{1F6A8} " : ($st === 3 ? "\u{1F514} " : "\u{26A0}\u{FE0F} ")) . '<b>' . tga_e($r['bias']) . ' · ' . tga_e($r['name']) . '</b> ' . $what
                    . ' · ' . (TGA_TFN[$r['tf']] ?? $r['tf']) . ' ' . $side . ' ' . tl_px((float)$r['lv'], (int)$r['dec']) . ' · price ' . tl_px((float)$r['price'], (int)$r['dec'])
                    . tga_zone($r['zone']['t'] ?? null)];
            }
            $first = !isset($S['h']); $new = tga_new($S, 'h', $ev, $now);
            if (!$first && $new) { [$ok, $i] = tga_send(tga_digest('Hybrid Trend Line Scanner', $new, 'https://chartpulse.io/scanners/hybrid-trend-lines/')); if ($ok) $sent[] = 'hybrid ' . count($new); else $err = $i; }
        }
    } catch (Throwable $e) { $err = 'hybrid ' . $e->getMessage(); }

    // ---- Trend Line Scanner: Weekly, Daily, 4H
    try {
        $p = tga_full('tl_scan', '_scan6.json'); $ev = [];
        if ((float)($p['meta']['progress'] ?? 0) >= 0.9) {
            foreach ($p['rows'] as $r) foreach ($r['ev'] ?? [] as $g) {
                if (!in_array($g['tf'], TGA_TFS, true)) continue;
                $brk = str_starts_with($g['e'], 'break'); $res = $g['e'] === 'break_up' || $g['e'] === 'at_res';
                $call = $g['e'] === 'break_up' || $g['e'] === 'at_sup';
                $ev[$r['symbol'] . '|' . $g['tf'] . '|' . $g['e']] = [[(int)$g['s']],
                    ($brk ? ($res ? "\u{1F53C} " : "\u{1F53D} ") : "\u{1F514} ") . '<b>' . tga_e($r['name']) . '</b> ' . ($brk ? 'broke ' : 'is at ') . TGA_TFN[$g['tf']] . ' '
                    . ($res ? 'resistance' : 'support') . ' ' . tl_px((float)$g['lv'], (int)$r['dec']) . ' · ' . ($call ? 'CALL' : 'PUT') . ' side'
                    . (!empty($g['w']) ? ' · with the trend' : '') . tga_zone($g['z'] ?? null)];
            }
            $first = !isset($S['t']); $new = tga_new($S, 't', $ev, $now);
            if (!$first && $new) { [$ok, $i] = tga_send(tga_digest('Trend Line Scanner · Weekly / Daily / 4H', $new, 'https://chartpulse.io/scanners/trend-lines/')); if ($ok) $sent[] = 'lines ' . count($new); else $err = $i; }
        }
    } catch (Throwable $e) { $err = 'lines ' . $e->getMessage(); }

    // ---- 200 EMA Scanner (daily chart). The API file refreshes its own 15-minute cache when it is stale.
    try {
        ob_start(); $_GET = []; include __DIR__ . '/ema200_api.php'; ob_end_clean();
        $c = t_read_json(T_DATA . '/ema200_signals2.json', null); $ev = [];
        if (is_array($c) && !empty($c['signals'])) {
            foreach ($c['signals'] as $r) {
                $st = (int)$r['stage']; if ($st < 1) continue;
                $ev[$r['symbol'] . '|' . $r['signal'] . '|' . $st] = [[(int)($r['grade'] ?? 1), $st, (int)$r['strength']],
                    "\u{1F4C8} <b>" . tga_e($r['signal']) . ' · ' . tga_e((string)($r['name'] ?? $r['symbol'])) . '</b> ' . ucfirst((string)$r['entry_type']) . ' entry at the 200 EMA '
                    . tl_px((float)$r['ema200'], tl_dec((float)$r['price'])) . ' · price ' . tl_px((float)$r['price'], tl_dec((float)$r['price']))
                    . (!empty($r['with']) ? ' · with the trend' : '') . tga_zone($r['zone']['t'] ?? null)];
            }
            $first = !isset($S['e']); $new = tga_new($S, 'e', $ev, $now);
            if (!$first && $new) { [$ok, $i] = tga_send(tga_digest('200 EMA Scanner · Daily', $new, 'https://chartpulse.io/scanners/ema-200/')); if ($ok) $sent[] = 'ema ' . count($new); else $err = $i; }
        }
    } catch (Throwable $e) { while (ob_get_level() > 0) ob_end_clean(); $err = 'ema ' . $e->getMessage(); }

    $S['last'] = ['at' => $now, 'sent' => $sent, 'err' => $err];
    if ($sent) $S['last_sent'] = ['at' => $now, 'sent' => $sent];
    t_write_json($f, $S);
    return ($sent ? 'alerts ' . implode(', ', $sent) : '') . ($err !== '' ? ' alerts-error ' . $err : '');
}

/** Safe status for the owner check (no chat ids, no token). */
function tga_status(): array {
    $S = t_read_json(T_DATA . '/tg_scan_alerts.json', []); if (!is_array($S)) $S = [];
    return ['bot' => trim((string)t_cfg('telegram_bot_token', '')) !== '', 'admin_chat' => trim((string)t_cfg('telegram_admin_chat', '')) !== '',
            'to' => strtolower(trim((string)t_cfg('telegram_alert_to', 'admin'))), 'now' => t_now(), 'last' => $S['last'] ?? null, 'last_sent' => $S['last_sent'] ?? null,
            'tracked' => ['hybrid' => count($S['h'] ?? []), 'lines' => count($S['t'] ?? []), 'ema' => count($S['e'] ?? [])]];
}
