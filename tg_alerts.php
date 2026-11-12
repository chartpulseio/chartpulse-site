<?php
// Telegram CALL / PUT alerts from every scanner (owner, 12 Nov 2026, revised 20:48 IST).
//   Every scanner that gives a CALL or a PUT sends it to Telegram:
//     Trendline Hero, Hero with 200 EMA, Hybrid Trend Line, Trend Line (Weekly / Daily / 4H), 200 EMA,
//     Day Drive (DRIVE), and the 6 strategy scanners on /scanners. Tier 1 FLY alerts go out from torus_engine.php
//     in the same format (tg_maybe_notify).
//   Message format (owner): scanner name, setup name, a short plain "why" note. No site link, no disclaimer.
// Run from cron.php every 5 minutes. Only NEW events are sent: the first run of a scanner only records what is already
// there, and the same event on the same market is not repeated inside its quiet period.
// Goes to every bot subscriber (people who pressed Start in @Chartpulseio_bot) and to config 'telegram_admin_chat'.
declare(strict_types=1);
if (!defined('T_DATA') && PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/tl_engine.php';

const TGA_MAX = 10;                                  // alerts per message; the rest is a count
const TGA_QUIET = ['h' => 6 * 3600, 'hero' => 6 * 3600, 'hero2' => 6 * 3600, 't' => 12 * 3600, 'e' => 24 * 3600, 'dd' => 6 * 3600, 'st' => 24 * 3600, 'gf' => 6 * 3600];
const TGA_TFS = ['1W', '1D', '4H'];
const TGA_TFN = ['1W' => 'Weekly', '1D' => 'Daily', '4H' => '4H', '1H' => '1H', '30m' => '30m', '15m' => '15m'];
// The strategy scanners kept on /scanners (12 Nov 2026): folder => [id => name]
const TGA_STRAT = [
    'ema-strategies' => ['3-ema-50-21-alignment' => 'EMA 200 + EMA 50 + EMA 21 Alignment', '5-vwap-ema-combo' => 'VWAP + EMA 200 Combo',
                         '7-ema-supertrend-rsi' => 'EMA 200 + Supertrend + RSI 50'],
    'institutional'  => ['1-smc-power-of-3' => 'SMC Power of 3 + OTE', '3-liquidity-fvg' => 'Liquidity Sweep + Order Block + FVG',
                         '4-orb-vwap' => 'Range Breakout + VWAP + Volume'],
];

function tga_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** One alert block. $plan: [entry, sl, target] or null. */
function tga_item(string $side, string $name, string $price, string $setup, string $why, ?array $plan = null): string {
    $L = [($side === 'CALL' ? "\u{1F7E2}" : "\u{1F534}") . ' <b>' . $side . ' · ' . tga_e($name) . '</b>' . ($price !== '' ? ' · ' . $price : ''),
          'Setup: ' . tga_e($setup), 'Why: ' . tga_e($why)];
    if ($plan) $L[] = 'Entry ' . $plan[0] . ' · SL ' . $plan[1] . ' · Target ' . $plan[2];
    return implode("\n", $L);
}

/** One message per scanner per run, strongest first. $ev: key => [rank, block]. */
function tga_msg(string $scanner, array $ev): string {
    uasort($ev, fn($a, $b) => $b[0] <=> $a[0]);
    $L = ["\u{1F4E1} <b>" . tga_e($scanner) . '</b>'];
    foreach (array_slice($ev, 0, TGA_MAX, true) as $x) { $L[] = ''; $L[] = $x[1]; }
    if (count($ev) > TGA_MAX) { $L[] = ''; $L[] = '+ ' . (count($ev) - TGA_MAX) . ' more ' . $scanner . ' alerts'; }
    return implode("\n", $L);
}

function tga_send(string $html): array {
    $n = tg_deliver($html);
    return $n > 0 ? [true, (string)$n] : [false, 'no subscribers and no admin chat yet'];
}

/** Keep the events that are new (not sent inside the quiet period); remember them. */
function tga_new(array &$S, string $k, array $ev, int $now): array {
    $seen = $S[$k] ?? []; $out = []; $q = TGA_QUIET[$k];
    foreach ($ev as $key => $x) {
        if (!isset($seen[$key]) || $now - (int)$seen[$key] >= $q) $out[$key] = $x;
        $seen[$key] = isset($out[$key]) ? $now : $seen[$key];
    }
    foreach ($seen as $key => $t) if ($now - (int)$t > 3 * $q) unset($seen[$key]);
    $S[$k] = $seen;
    return $out;
}

/** Record on the first run, send on later runs. */
function tga_push(array &$S, string $k, string $scanner, array $ev, int $now, array &$sent, string &$err): void {
    $first = !isset($S[$k]); $new = tga_new($S, $k, $ev, $now);
    if ($first || !$new) return;
    [$ok, $i] = tga_send(tga_msg($scanner, $new));
    if ($ok) $sent[] = $k . ' ' . count($new); else $err = $i;
}

function tga_zone(?string $t): string { return $t === 'strong' ? ' A strong zone sits on the line.' : ($t === 'major' ? ' A major zone sits on the line.' : ''); }

/** "Daily + 4H trend up" from a hybrid / hero row. */
function tga_trend(array $r): string {
    $tf = ['Daily'];
    if (!empty($r['with4h'])) $tf[] = '4H';
    if (!empty($r['with1w'])) $tf[] = 'Weekly';
    return implode(' + ', $tf) . ' trend ' . ($r['bias'] === 'PUT' ? 'down' : 'up');
}

/** Run a stepped scan to the end (each call works ~18 s; without visitors the row cache is cold). */
function tga_full(callable $scan, string $payload): array {
    $p = $scan();
    for ($i = 0; $i < 4 && (float)($p['meta']['progress'] ?? 0) < 0.9; $i++) { @unlink(T_DATA . '/tl/' . $payload); $p = $scan(); }
    return $p;
}

/** Hybrid / Hero row -> alert block, or null when it is not an entry (stage 3 at the line, 4 rejection, 5 failed break). */
function tga_line_item(array $r): ?string {
    $st = (int)$r['stage']; if ($st < 3 || empty($r['live'])) return null;
    $put = $r['bias'] === 'PUT'; $side = $put ? 'resistance' : 'support'; $tf = TGA_TFN[$r['tf']] ?? $r['tf'];
    $dec = (int)$r['dec']; $lv = tl_px((float)$r['lv'], $dec);
    $setup = [5 => 'Failed break', 4 => 'Rejection', 3 => 'At the line'][$st] . ' · ' . $tf . ' ' . $side . ' line';
    $what = [5 => 'price broke the ' . $tf . ' ' . $side . ' line at ' . $lv . ', could not hold and came back',
             4 => 'price touched the ' . $tf . ' ' . $side . ' line at ' . $lv . ' and turned ' . ($put ? 'down' : 'up'),
             3 => 'today\'s ' . ($put ? 'high' : 'low') . ' is sitting on the ' . $tf . ' ' . $side . ' line at ' . $lv][$st];
    $why = tga_trend($r) . '; ' . $what . '.' . tga_zone($r['zone']['t'] ?? null);
    $p = $r['plan'] ?? null;
    $plan = $p ? [tl_px((float)$p['entry'], $dec), tl_px((float)$p['sl'], $dec), tl_px((float)$p['t1'], $dec)] : null;
    return tga_item($r['bias'], (string)$r['name'], tl_px((float)$r['price'], $dec), $setup, $why, $plan);
}

function tga_step(int $now): string {
    if (trim((string)t_cfg('telegram_bot_token', '')) === '') return '';
    $f = T_DATA . '/tg_scan_alerts.json';
    $S = t_read_json($f, []); if (!is_array($S)) $S = [];
    $sent = []; $err = '';

    // ---- Trendline Hero + Hero with 200 EMA (same scan; a row with the 1H 200 EMA on its line goes under Hero with 200 EMA)
    try {
        $p = tga_full(fn() => tlh_scan(true), '_heroscan3.json'); $ev = []; $ev2 = [];
        if ((float)($p['meta']['progress'] ?? 0) >= 0.9) {
            foreach ($p['rows'] as $r) {
                $b = tga_line_item($r); if ($b === null) continue;
                $key = $r['symbol'] . '|' . $r['tf'] . '|' . (int)$r['stage'];
                if (!empty($r['e200']['ok'])) $ev2[$key] = [$r['key'], $b . "\nPlus: the 1H 200 EMA sits on the same line."];
                else $ev[$key] = [$r['key'], $b];
            }
            tga_push($S, 'hero', 'Trendline Hero', $ev, $now, $sent, $err);
            tga_push($S, 'hero2', 'Hero with 200 EMA', $ev2, $now, $sent, $err);
        }
    } catch (Throwable $e) { $err = 'hero ' . $e->getMessage(); }

    // ---- Hybrid Trend Line Scanner
    try {
        $p = tga_full('tlh_scan', '_hscan5.json'); $ev = [];
        if ((float)($p['meta']['progress'] ?? 0) >= 0.9) {
            foreach ($p['rows'] as $r) {
                $b = tga_line_item($r); if ($b === null) continue;
                $ev[$r['symbol'] . '|' . (int)$r['stage']] = [[(int)$r['stage'], $r['key'][2] ?? 0, $r['touches']], $b];
            }
            tga_push($S, 'h', 'Hybrid Trend Line Scanner', $ev, $now, $sent, $err);
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
                $tf = TGA_TFN[$g['tf']]; $lv = tl_px((float)$g['lv'], (int)$r['dec']); $line = $tf . ' ' . ($res ? 'resistance' : 'support') . ' line';
                $setup = ($brk ? 'Breakout' : 'Bounce') . ' · ' . $line;
                $why = $brk ? 'Price closed through the ' . $line . ' at ' . $lv . ', so the move can carry on ' . ($call ? 'up' : 'down') . '.'
                            : 'Price came to the ' . $line . ' at ' . $lv . ', a level where it turned before (' . (int)$g['n'] . ' touches).';
                if (!empty($g['w'])) $why .= ' Bigger trend agrees.';
                $why .= tga_zone($g['z'] ?? null);
                $ev[$r['symbol'] . '|' . $g['tf'] . '|' . $g['e']] = [[(int)$g['s']], tga_item($call ? 'CALL' : 'PUT', (string)$r['name'], '', $setup, $why)];
            }
            tga_push($S, 't', 'Trend Line Scanner', $ev, $now, $sent, $err);
        }
    } catch (Throwable $e) { $err = 'lines ' . $e->getMessage(); }

    // ---- 200 EMA Scanner (daily chart). The API file refreshes its own 15-minute cache when it is stale.
    try {
        ob_start(); $_GET = []; include __DIR__ . '/ema200_api.php'; ob_end_clean();
        $c = t_read_json(T_DATA . '/ema200_signals2.json', null); $ev = [];
        if (is_array($c) && !empty($c['signals'])) {
            foreach ($c['signals'] as $r) {
                $st = (int)$r['stage']; if ($st < 1) continue;
                $up = $r['signal'] === 'CALL'; $d = tl_dec((float)$r['price']);
                $setup = ucfirst((string)$r['entry_type']) . ' entry · Daily 200 EMA';
                $why = 'Daily trend ' . ($up ? 'up' : 'down') . ' and price came back to the 200 EMA at ' . tl_px((float)$r['ema200'], $d)
                     . (!empty($r['trigger']) ? '; ' . $r['trigger'] . ' confirmed the turn' : '') . '.' . tga_zone($r['zone']['t'] ?? null);
                $ev[$r['symbol'] . '|' . $r['signal'] . '|' . $st] = [[(int)($r['grade'] ?? 1), $st, (int)$r['strength']],
                    tga_item((string)$r['signal'], (string)($r['name'] ?? $r['symbol']), tl_px((float)$r['price'], $d), $setup, $why)];
            }
            tga_push($S, 'e', '200 EMA Scanner', $ev, $now, $sent, $err);
        }
    } catch (Throwable $e) { while (ob_get_level() > 0) ob_end_clean(); $err = 'ema ' . $e->getMessage(); }

    // ---- Day Drive: only DRIVE (all three steps passed = the entry)
    try {
        require_once __DIR__ . '/dd_engine.php';
        $p = dd_scan('all'); $ev = [];
        foreach ($p['rows'] ?? [] as $r) {
            if (($r['status'] ?? '') !== 'DRIVE') continue;
            $up = $r['side'] === 'CALL'; $d = tl_dec((float)$r['price']);
            $why = 'Day trend, structure and the 5-minute turn (CHoCH) all point ' . ($up ? 'up' : 'down') . '; RSI and the candle confirm.';
            $rk = $r['risk'] ?? null; $plan = null;
            $t1 = null; foreach ((array)($rk['targets'] ?? []) as $t) if (($t['label'] ?? '') === 'T1') $t1 = $t['price'];
            if (is_array($rk) && isset($rk['entry'], $rk['sl']) && $t1 !== null) $plan = [tl_px((float)$rk['entry'], $d), tl_px((float)$rk['sl'], $d), tl_px((float)$t1, $d)];
            $ev[$r['symbol'] . '|' . $r['side']] = [[(float)$r['score']], tga_item((string)$r['side'], (string)$r['name'], tl_px((float)$r['price'], $d), 'DRIVE · all 3 steps passed', $why, $plan)];
        }
        tga_push($S, 'dd', 'Day Drive Scanner', $ev, $now, $sent, $err);
    } catch (Throwable $e) { $err = 'daydrive ' . $e->getMessage(); }

    // ---- God-father Scanner: A+ only (every scanner lined up)
    try {
        require_once __DIR__ . '/gf_engine.php';
        $p = gf_scan(); $ev = [];
        foreach ($p['rows'] ?? [] as $r) {
            if ($r['grade'] !== 'A+' || empty($r['live'])) continue;
            $d = (int)$r['dec']; $pl = $r['plan'];
            $plan = $pl ? [tl_px((float)$pl['entry'], $d), tl_px((float)$pl['sl'], $d), tl_px((float)$pl['t1'], $d)] : null;
            $ev[$r['symbol'] . '|' . $r['side']] = [[(int)$r['pz']], tga_item((string)$r['side'], (string)$r['name'], tl_px((float)$r['price'], $d), 'A+ · ' . $r['setup'], (string)$r['say'], $plan)];
        }
        tga_push($S, 'gf', 'God-father Scanner', $ev, $now, $sent, $err);
    } catch (Throwable $e) { $err = 'godfather ' . $e->getMessage(); }

    // ---- The 6 strategy scanners on /scanners (daily). Their API rebuilds a stale cache, so call it once per folder.
    $ev = [];
    foreach (TGA_STRAT as $dir => $ids) {
        try {
            $first = array_key_first($ids); $cf = __DIR__ . "/$dir/cache/scan_$first.json";
            if (!is_file($cf) || time() - filemtime($cf) > 900) {
                $ctx = stream_context_create(['http' => ['timeout' => 100, 'ignore_errors' => true]]);
                @file_get_contents("https://chartpulse.io/$dir/api/scan.php?id=$first", false, $ctx);
            }
            foreach ($ids as $id => $sname) {
                $c = t_read_json(__DIR__ . "/$dir/cache/scan_$id.json", null);
                foreach ((is_array($c) ? $c['data'] ?? [] : []) as $r) {
                    $sym = (string)$r['symbol']; $d = tl_dec((float)$r['price']);
                    $ev[$id . '|' . $sym . '|' . $r['bias']] = [[(int)$r['score']], tga_item((string)$r['bias'], t_name($sym), tl_px((float)$r['price'], $d),
                        $sname . ' (Daily)', (string)$r['setup'] . '.', [tl_px((float)$r['entry'], $d), tl_px((float)$r['sl'], $d), tl_px((float)$r['tp'], $d)])];
                }
            }
        } catch (Throwable $e) { $err = 'strategies ' . $e->getMessage(); }
    }
    tga_push($S, 'st', 'Strategy Scanners', $ev, $now, $sent, $err);

    $S['last'] = ['at' => $now, 'sent' => $sent, 'err' => $err];
    if ($sent) $S['last_sent'] = ['at' => $now, 'sent' => $sent];
    t_write_json($f, $S);
    return ($sent ? 'alerts ' . implode(', ', $sent) : '') . ($err !== '' ? ' alerts-error ' . $err : '');
}

/** Safe status for the owner check (no chat ids, no token). */
function tga_status(): array {
    $S = t_read_json(T_DATA . '/tg_scan_alerts.json', []); if (!is_array($S)) $S = [];
    $subs = t_read_json(T_DATA . '/tg_subscribers.json', []);
    $tr = []; foreach (['hero', 'hero2', 'h', 't', 'e', 'dd', 'st'] as $k) $tr[$k] = count($S[$k] ?? []);
    return ['bot' => trim((string)t_cfg('telegram_bot_token', '')) !== '', 'admin_chat' => trim((string)t_cfg('telegram_admin_chat', '')) !== '',
            'subscribers' => is_array($subs) ? count($subs) : 0, 'now' => t_now(), 'last' => $S['last'] ?? null, 'last_sent' => $S['last_sent'] ?? null,
            'tracked' => $tr];
}
