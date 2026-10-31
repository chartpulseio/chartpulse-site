<?php
// ---------------------------------------------------------------------------
// ChartPulse daily research report (admin only).
//   Every Tier-2 trade of one IST calendar day, with its full story: Tier-1 signal, 10m pattern, 5m and 3m 5-EMA crosses,
//   SL (and how it was built), minimum target, how far price ran, the exit and what happened after a stop-loss.
//   Built each morning by cron.php for the previous day (08:00 IST, after the US and crypto sessions have closed),
//   saved for good in data/reports/YYYY-MM-DD.json, and sent to the admin Telegram chat (config 'telegram_admin_chat').
//   admin/reports.php reads the same files (day view, week / month summaries, CSV).
// ---------------------------------------------------------------------------
declare(strict_types=1);
require_once __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_tier2.php';

const TR_TZ = 'Asia/Kolkata';
function tr_dir(): string { $d = T_DATA . '/reports'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function tr_bounds(string $d): array { $a = (new DateTime($d . ' 00:00:00', new DateTimeZone(TR_TZ)))->getTimestamp(); return [$a, $a + 86400]; }
function tr_today(int $now): string { return (new DateTime('@' . $now))->setTimezone(new DateTimeZone(TR_TZ))->format('Y-m-d'); }
function tr_shift(string $d, int $days): string { return (new DateTime($d . ' 12:00:00', new DateTimeZone(TR_TZ)))->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d'); }
function tr_valid(string $d): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }
function tr_load(string $d): ?array { return tr_valid($d) ? t_read_json(tr_dir() . "/$d.json", null) : null; }
function tr_dates(): array { $o = []; foreach (glob(tr_dir() . '/*.json') ?: [] as $f) { $b = basename($f, '.json'); if (tr_valid($b)) $o[] = $b; } rsort($o); return $o; }

/** Trades of [$from, $to) that are still open, by symbol (owner, 30 Sep 2026). */
function tr_open(int $from, int $to): array {
    $o = [];
    foreach (t_read_json(T_DATA . '/t2trades.json', ['items' => []])['items'] ?? [] as $x)
        if (($x['status'] ?? '') === 'open' && (int)$x['entry_time'] >= $from && (int)$x['entry_time'] < $to) $o[$x['symbol']] = ($o[$x['symbol']] ?? 0) + 1;
    return $o;
}
/** Replay every symbol that had a Tier-1 signal between $from and $to (all of them, not the page budget).
    Symbols with a trade still open go first, so a run that hits the time limit finishes the open ones before anything else.
    $onlyOpen: later passes replay just those symbols. */
function tr_refresh(int $from, int $to, int $maxSec = 150, bool $onlyOpen = false): int {
    $t0 = time(); $now = t_now(); $L = &t_siglog(); $syms = [];
    $open = tr_open($from, $to);
    foreach (array_keys($open) as $s) if (!str_starts_with($s, 'MCX:') && isset(t_symbols()[$s])) $syms[$s] = true;
    if (!$onlyOpen) foreach ($L['items'] as $e) {
        $s = $e['symbol']; $end = !empty($e['end']) ? (int)$e['end'] : $now;
        if ($end < $from - 1800 || (int)$e['start'] > $to || str_starts_with($s, 'MCX:') || !isset(t_symbols()[$s])) continue;
        $syms[$s] = true;
    }
    $n = 0;
    foreach (array_chunk(array_keys($syms), 8) as $chunk) {
        if (time() - $t0 > $maxSec) break;
        $urls = []; foreach ($chunk as $s) { $urls["$s|5"] = t_url($s, '5d', '5m'); $urls["$s|1"] = t_url($s, '5d', '1m'); }
        $B = t_fetch($urls);
        foreach ($chunk as $s) {
            $md = t2_data_parse($B["$s|5"] ?? null, $B["$s|1"] ?? null); if (!$md) continue;
            $f5 = $md['f5']; $ses = t2_sessions($f5, $md['tz']); $nS = count($ses);
            for ($k = 1; $k < $nS; $k++) {
                [$a, $b] = $ses[$k]; $dayTs = (int)$f5['t'][$a]; $endTs = (int)$f5['t'][$b] + 300;
                if ($endTs < $from - 3600 || $dayTs > $to + 3600) continue;
                $eps = array_values(array_filter(t2_episodes($s, $dayTs, $now, null), fn($x) => $x['s'] < $endTs + 1800));
                $run = $k === $nS - 1 && $now - (int)$f5['t'][$b] < 5400;
                if (!$eps) { if (!$run) t2_settle_orphans($s, $f5, $md['f1'], $a, $b, []); continue; }
                $R = t2_replay_session($f5, $md['f1'], $a, $b, $eps, t_fib_pivots($f5, $dayTs), $now, $run);
                t2_trades_merge($s, $R['trades'], t_scan_load()['rows'][$s] ?? null);
                if (!$run) t2_settle_orphans($s, $f5, $md['f1'], $a, $b, array_flip(array_map(fn($t) => substr(md5($s . '|' . $t['sig'] . '|' . $t['entry_time']), 0, 12), $R['trades'])));
            }
            $n++;
        }
    }
    return $n;
}

/** Totals for a list of trades: overall and split by setup, market, pattern, side, exit reason and IST hour. */
function tr_summary(array $T): array {
    $one = function (array $X): array {
        $C = array_values(array_filter($X, fn($x) => ($x['status'] ?? '') === 'closed' && $x['r'] !== null));
        $W = array_filter($C, fn($x) => $x['r'] > 0); $gw = array_sum(array_column($W, 'r')); $gl = -array_sum(array_map(fn($x) => min(0, $x['r']), $C));
        $avg = fn($k) => $X ? round(array_sum(array_map(fn($x) => (float)($x[$k] ?? 0), $X)) / count($X), 2) : null;
        return ['n' => count($X), 'closed' => count($C), 'open' => count($X) - count($C), 'wins' => count($W),
            'sl' => count(array_filter($C, fn($x) => $x['reason'] === 'Stop-loss')),
            'win_rate' => $C ? round(count($W) / count($C) * 100, 1) : null, 'total_r' => round($gw - $gl, 2), 'avg_r' => $C ? round(($gw - $gl) / count($C), 2) : null,
            'pf' => $gl > 0 ? round($gw / $gl, 2) : null, 'target_rate' => $X ? round(count(array_filter($X, fn($x) => !empty($x['touched']))) / count($X) * 100, 1) : null,
            'avg_run_r' => $avg('run_r'), 'avg_mfe_r' => $avg('mfe_r'), 'avg_mae_r' => $avg('mae_r'),
            'sl_then_target' => count(array_filter($C, fn($x) => !empty($x['target_after_sl'])))];
    };
    $by = function (callable $key) use ($T, $one): array { $g = []; foreach ($T as $x) $g[$key($x)][] = $x; ksort($g); return array_map($one, $g); };
    return ['all' => $one($T), 'setup' => $by(fn($x) => tr_setup($x)), 'market' => $by(fn($x) => $x['market'] ?: 'Other'), 'pattern' => $by(fn($x) => $x['pattern'] ?? '?'),
        'side' => $by(fn($x) => $x['sig']), 'exit' => $by(fn($x) => $x['reason'] ?? 'Open'), 'hour' => $by(fn($x) => t_ist((int)$x['entry_time'], 'H') . ':00')];
}
function tr_setup(array $x): string {
    $s = strtolower((string)($x['setup'] ?? '')); $r = (string)($x['remark'] ?? '');
    return $s === 'trend' || $r === 'Long target' ? 'Trend' : ($s === 'retrace' || $r === 'Small target' ? 'Retracement' : 'Unknown');
}

/** Build (or rebuild) the report of IST day $d and save it. */
function tr_build(string $d, bool $refresh = true): array {
    [$a, $b] = tr_bounds($d); $now = t_now();
    if ($refresh) tr_refresh($a, $b);
    $all = t_read_json(T_DATA . '/t2trades.json', ['items' => []])['items'] ?? [];
    $T = array_values(array_filter($all, fn($x) => (int)$x['entry_time'] >= $a && (int)$x['entry_time'] < $b));
    usort($T, fn($x, $y) => $x['entry_time'] <=> $y['entry_time']);
    // Tier-1 signals of the day and whether a Tier-2 trade came out of them
    $L = &t_siglog(); $S = [];
    foreach ($L['items'] as $e) {
        if ((int)$e['start'] < $a || (int)$e['start'] >= $b) continue;
        $end = !empty($e['end']) ? (int)$e['end'] : (int)$e['last'] + 300;
        $hit = array_values(array_filter($all, fn($x) => $x['symbol'] === $e['symbol'] && $x['sig'] === $e['signal'] && $x['entry_time'] >= (int)$e['start'] - 1200 && $x['entry_time'] <= $end + 3600 * 12));
        $S[] = ['symbol' => $e['symbol'], 'name' => $e['name'] ?? $e['symbol'], 'market' => $e['market'] ?? '', 'signal' => $e['signal'], 'setup' => $e['setup'] ?? null,
            'alignment' => $e['alignment'] ?? null, 'price' => $e['price'] ?? null, 'start' => (int)$e['start'], 'end' => $end, 'trade' => $hit ? $hit[0]['id'] : null];
    }
    usort($S, fn($x, $y) => $x['start'] <=> $y['start']);
    $old = tr_load($d);
    if ($old) {   // keep the 48-hour study of each trade (price path, independent of the exit) across rebuilds
        $st = []; foreach ($old['trades'] ?? [] as $x) if (!empty($x['study'])) $st[$x['id']] = $x['study'];
        foreach ($T as &$x) if (isset($st[$x['id']])) $x['study'] = $st[$x['id']]; unset($x);
    }
    $nOpen = count(array_filter($T, fn($x) => ($x['status'] ?? '') === 'open'));
    $rep = ['date' => $d, 'tz' => TR_TZ, 'built_at' => $now, 'final' => $now >= $b + 8 * 3600 && $nOpen === 0, 'open' => $nOpen, 'trades' => $T, 'signals' => $S, 'summary' => tr_summary($T),
        'sent_at' => $old['sent_at'] ?? null];
    foreach (['sent_ok', 'send_info', 'settle_tries', 'closed_at', 'study_at'] as $k) if (isset($old[$k])) $rep[$k] = $old[$k];
    if (array_filter($T, fn($x) => !empty($x['study']))) $rep['conclusions'] = ts_conclude($T);
    t_write_json(tr_dir() . "/$d.json", $rep);
    return $rep;
}
/** Trades of the saved days between two dates (inclusive). */
function tr_range(string $from, string $to): array {
    $T = []; $days = 0;
    for ($d = $from; $d <= $to; $d = tr_shift($d, 1)) { $r = tr_load($d); if ($r) { $days++; foreach ($r['trades'] as $x) $T[] = $x; } }
    return ['from' => $from, 'to' => $to, 'days' => $days, 'trades' => $T, 'summary' => tr_summary($T)];
}
function tr_week(string $d): array { $w = (int)(new DateTime($d . ' 12:00:00'))->format('N'); $m = tr_shift($d, 1 - $w); return [$m, tr_shift($m, 6)]; }
function tr_month(string $d): array { $f = substr($d, 0, 8) . '01'; return [$f, (new DateTime($f))->modify('last day of this month')->format('Y-m-d')]; }

/** TradingView symbol code (same mapping as CP.tvSymbol in static/v2/cp.js). */
function t_tv_code(string $s): string {
    static $I = ['^NSEI' => 'NSE:NIFTY', '^NSEBANK' => 'NSE:BANKNIFTY', '^BSESN' => 'BSE:SENSEX', 'NIFTY_FIN_SERVICE.NS' => 'NSE:CNXFINANCE', 'NIFTY_MID_SELECT.NS' => 'NSE:NIFTY_MID_SELECT', '^CNXIT' => 'NSE:CNXIT', '^CNXAUTO' => 'NSE:CNXAUTO', '^CNXPHARMA' => 'NSE:CNXPHARMA', '^CNXFMCG' => 'NSE:CNXFMCG', '^CNXMETAL' => 'NSE:CNXMETAL', '^CNXREALTY' => 'NSE:CNXREALTY', '^CNXENERGY' => 'NSE:CNXENERGY', '^CNXPSUBANK' => 'NSE:CNXPSUBANK', '^GSPC' => 'SP:SPX', '^NDX' => 'NASDAQ:NDX', '^IXIC' => 'NASDAQ:IXIC', '^DJI' => 'DJ:DJI', '^RUT' => 'TVC:RUT', '^GSPTSE' => 'TSX:TSX', '^FTSE' => 'TVC:UKX', '^GDAXI' => 'XETR:DAX', '^FCHI' => 'EURONEXT:PX1', '^AEX' => 'EURONEXT:AEX', '^SSMI' => 'SIX:SMI', 'FTSEMIB.MI' => 'TVC:FTSEMIB', '^IBEX' => 'BME:IBC', '^STOXX50E' => 'TVC:SX5E', '^N225' => 'TVC:NI225', '^HSI' => 'TVC:HSI', '000001.SS' => 'SSE:000001', '000300.SS' => 'SSE:000300', '399001.SZ' => 'SZSE:399001', '^KS11' => 'KRX:KOSPI', '^TWII' => 'TWSE:TAIEX', '^STI' => 'TVC:STI', '^AXJO' => 'ASX:XJO', '^BVSP' => 'BMFBOVESPA:IBOV', '^MXX' => 'BMV:ME', '^TASI.SR' => 'TADAWUL:TASI', 'DX-Y.NYB' => 'TVC:DXY', 'ES=F' => 'CME_MINI:ES1!', 'NQ=F' => 'CME_MINI:NQ1!', 'YM=F' => 'CBOT_MINI:YM1!', 'RTY=F' => 'CME_MINI:RTY1!', 'NKD=F' => 'CME:NKD1!', 'ZN=F' => 'CBOT:ZN1!', 'ZB=F' => 'CBOT:ZB1!', 'GC=F' => 'COMEX:GC1!', 'PAXG-USD' => 'OANDA:XAUUSD', 'SI=F' => 'COMEX:SI1!', 'HG=F' => 'COMEX:HG1!', 'PL=F' => 'NYMEX:PL1!', 'PA=F' => 'NYMEX:PA1!', 'CL=F' => 'NYMEX:CL1!', 'BZ=F' => 'NYMEX:BB1!', 'NG=F' => 'NYMEX:NG1!', 'HO=F' => 'NYMEX:HO1!', 'RB=F' => 'NYMEX:RB1!', 'ZC=F' => 'CBOT:ZC1!', 'ZS=F' => 'CBOT:ZS1!', 'ZW=F' => 'CBOT:ZW1!', 'KC=F' => 'ICEUS:KC1!', 'SB=F' => 'ICEUS:SB1!', 'CT=F' => 'ICEUS:CT1!', 'CC=F' => 'ICEUS:CC1!', 'LE=F' => 'CME:LE1!', 'HE=F' => 'CME:HE1!'];
    static $X = ['.NS' => 'NSE', '.BO' => 'BSE', '.TO' => 'TSX', '.L' => 'LSE', '.DE' => 'XETR', '.PA' => 'EURONEXT', '.AS' => 'EURONEXT', '.SW' => 'SIX', '.MI' => 'MIL', '.MC' => 'BME', '.CO' => 'OMXCOP', '.ST' => 'OMXSTO', '.T' => 'TSE', '.HK' => 'HKEX', '.SS' => 'SSE', '.SZ' => 'SZSE', '.KS' => 'KRX', '.TW' => 'TWSE', '.SI' => 'SGX', '.AX' => 'ASX', '.SA' => 'BMFBOVESPA', '.MX' => 'BMV', '.SR' => 'TADAWUL', '.JO' => 'JSE'];
    if (isset($I[$s])) return $I[$s];
    if (str_starts_with($s, 'MCX:')) return $s . '1!';
    if (str_ends_with($s, '=X')) return 'FX_IDC:' . substr($s, 0, -2);
    if (str_ends_with($s, '-USD')) return 'CRYPTO:' . preg_replace('/\d+$/', '', substr($s, 0, -4)) . 'USD';
    $i = strrpos($s, '.');
    if ($i && isset($X[substr($s, $i)])) { $base = str_replace('-', '_', substr($s, 0, $i)); if (str_ends_with($s, '.HK')) $base = (string)(int)$base; return $X[substr($s, $i)] . ':' . $base; }
    return preg_replace('/-/', '.', $s, 1);
}

/* ---------------- Telegram ---------------- */
function tr_px(array $x, $v): string { return $v === null ? '–' : t_fp((float)$v); }
function tr_move(array $x): string {
    $pip = t2_pip($x['symbol'], (float)$x['entry']); $mv = ((float)$x['last'] - (float)$x['entry']) * ($x['sig'] === 'CALL' ? 1 : -1);
    $s = fn($v) => ($v > 0 ? '+' : '') . $v;
    return ($pip ? $s(round($mv / $pip, 1)) . ' pips' : $s(t_fp($mv)) . ' pts') . ' · ' . $s(round((float)$x['pct'], 2)) . '% · <b>' . $s($x['r']) . 'R</b>';
}
function tr_trade_block(array $x, int $i): string {
    $x += array_fill_keys(['signal_time', 'signal_price', 'alignment', 'pattern_bar', 'confirm_time', 'ema5_cross', 'day_ext', 'atr5', 'target_time', 'run_r', 'run_pct', 'run_time', 'pivots_crossed', 'after_sl_r', 'target_after_sl', 'exit', 'exit_time', 'reason'], null);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES); $h = fn($t) => $t ? t_ist((int)$t, 'H:i') : '–';
    $call = $x['sig'] === 'CALL'; $won = ($x['r'] ?? 0) > 0; $open = $x['status'] === 'open';
    $icon = $open ? "\u{23F3}" : ($won ? "\u{2705}" : "\u{274C}");
    $tv = htmlspecialchars(t_tv_code($x['symbol']), ENT_QUOTES);
    $L = [];
    $L[] = "$icon <b>" . $i . '. ' . $e($x['name']) . '</b> · ' . ($call ? "\u{1F7E2} CALL" : "\u{1F534} PUT") . ' · <i>' . tr_setup($x) . '</i>';
    $L[] = "<code>$tv</code>";
    $L[] = "\u{1F4E1} Signal " . $h($x['signal_time']) . ($x['signal_price'] !== null ? ' @ ' . tr_px($x, $x['signal_price']) : '') . ($x['alignment'] ? ' · ' . (int)$x['alignment'] . '/4' : '');
    $pb = $x['pattern_bar'] ?? null;
    $L[] = "\u{1F56F} " . $e($x['pattern']) . ' (10m) ' . ($pb ? $h($pb['t']) . ' · ' . ($call ? 'L ' . tr_px($x, $pb['l']) : 'H ' . tr_px($x, $pb['h'])) : '') . ' · confirmed ' . $h($x['confirm_time'] ?? null);
    $c5 = $x['ema5_cross'] ?? null;
    $L[] = "\u{3030} 5m EMA cross " . ($c5 ? $h($c5[0]) . ' @ ' . tr_px($x, $c5[1]) : '–') . ' · <b>Entry</b> (3m) ' . $h($x['entry_time']) . ' @ <b>' . tr_px($x, $x['entry']) . '</b>';
    $L[] = "\u{1F6D1} SL " . tr_px($x, $x['sl0']) . ' <i>(' . ($call ? 'Day Low ' : 'Day High ') . tr_px($x, $x['day_ext'] ?? null) . ($call ? ' − ' : ' + ') . '0.4×ATR ' . tr_px($x, $x['atr5'] ?? null) . ')</i>';
    $L[] = "\u{1F3AF} Min target " . $e($x['target_label']) . ' ' . tr_px($x, $x['target']) . ' · ' . (!empty($x['touched']) ? 'hit ' . $h($x['target_time'] ?? null) . " \u{2714}" : 'not reached');
    $pc = $x['pivots_crossed'] ?? [];
    $L[] = "\u{1F4C8} Max run +" . ($x['run_r'] ?? '–') . 'R (' . ($x['run_pct'] ?? '–') . '%) by ' . $h($x['run_time'] ?? null) . ($pc ? ' · crossed ' . implode(', ', array_map($e, $pc)) : '');
    if ($open) $L[] = "\u{23F3} Still open · now " . tr_move($x);
    else $L[] = "\u{1F6AA} Exit " . $h($x['exit_time']) . ' @ ' . tr_px($x, $x['exit']) . ' · ' . $e($x['reason']) . ' → ' . tr_move($x);
    if (($x['reason'] ?? '') === 'Stop-loss') $L[] = "\u{1F50E} After SL: price went +" . ($x['after_sl_r'] ?? 0) . 'R' . (!empty($x['target_after_sl']) ? " · <b>target reached later</b> \u{26A0}" : ' · target not reached');
    return implode("\n", $L);
}
function tr_sum_lines(array $s, string $title): array {
    $sg = fn($v) => $v === null ? '–' : (($v > 0 ? '+' : '') . $v);
    $a = $s['all'];
    $L = ["<b>$title</b>", "Trades <b>{$a['n']}</b> · \u{2705} {$a['wins']} won · \u{274C} {$a['sl']} SL" . ($a['open'] ? " · \u{23F3} {$a['open']} open" : ''),
        'Win rate <b>' . ($a['win_rate'] ?? '–') . '%</b> · Net <b>' . $sg($a['total_r']) . 'R</b> · Avg ' . $sg($a['avg_r']) . 'R · PF ' . ($a['pf'] ?? '–'),
        'Target hit ' . ($a['target_rate'] ?? '–') . '% · Avg max run +' . ($a['avg_run_r'] ?? '–') . 'R'];
    foreach (['Trend' => "\u{1F4C8}", 'Retracement' => "\u{21A9}"] as $k => $ic) if (isset($s['setup'][$k])) { $x = $s['setup'][$k];
        $L[] = "$ic $k: {$x['n']} trades · win " . ($x['win_rate'] ?? '–') . '% · ' . $sg($x['total_r']) . 'R · avg run +' . ($x['avg_run_r'] ?? '–') . 'R'; }
    if ($a['sl_then_target']) $L[] = "\u{26A0} {$a['sl_then_target']} SL trade(s) later reached the target: check the SL distance";
    return $L;
}
/** Messages (each under Telegram's 4096 characters) for one day, plus week / month totals when due. */
function tr_tg_messages(array $rep, string $link): array {
    $d = $rep['date']; $T = $rep['trades']; $s = $rep['summary'];
    $head = ["\u{1F4CA} <b>ChartPulse · Tier-2 report</b>", "\u{1F4C5} " . (new DateTime($d))->format('D, d M Y') . ' (IST)' . (!empty($rep['conclusions']) ? ' · with the 48-hour target study' : ''), str_repeat("\u{2501}", 18)];
    $L = array_merge($head, !empty($rep['conclusions']) ? array_merge(ts_tg_lines($rep['conclusions']), ['']) : [], tr_sum_lines($s, 'Trade results (actual exits)'));
    if ($T) {
        $C = array_values(array_filter($T, fn($x) => $x['status'] === 'closed')); usort($C, fn($x, $y) => $y['r'] <=> $x['r']);
        if ($C) { $L[] = "\u{1F3C6} Best: " . htmlspecialchars($C[0]['name']) . ' ' . ($C[0]['r'] > 0 ? '+' : '') . $C[0]['r'] . 'R';
            $w = $C[count($C) - 1]; $L[] = "\u{1F4A5} Worst: " . htmlspecialchars($w['name']) . ' ' . ($w['r'] > 0 ? '+' : '') . $w['r'] . 'R'; }
    } else $L[] = 'No Tier-2 entry on this day.';
    $noEntry = count(array_filter($rep['signals'], fn($x) => !$x['trade']));
    $L[] = "\u{1F4E1} Tier-1 signals: " . count($rep['signals']) . ' · without a Tier-2 entry: ' . $noEntry;
    $L[] = "\u{1F517} <a href=\"" . htmlspecialchars($link) . "\">Open the full report</a>";
    $msgs = [implode("\n", $L)];
    // one block per trade, packed into messages
    $buf = ''; foreach ($T as $i => $x) { $blk = tr_trade_block($x, $i + 1) . "\n\n"; if (strlen($buf) + strlen($blk) > 3800) { $msgs[] = rtrim($buf); $buf = ''; } $buf .= $blk; }
    if ($buf !== '') $msgs[] = "<b>Trades · " . (new DateTime($d))->format('d M') . "</b>\n\n" . rtrim($buf);
    // weekly with Sunday's report (sent Monday morning, Mon-Sun), monthly with the report of a month's last day
    if ((new DateTime($d))->format('N') === '7') { [$f, $t] = tr_week($d); $R = tr_range($f, $t);
        $msgs[] = implode("\n", array_merge(["\u{1F5D3} <b>Week " . (new DateTime($f))->format('d M') . ' – ' . (new DateTime($t))->format('d M') . '</b>'], tr_sum_lines($R['summary'], 'Weekly total'))); }
    if ((new DateTime($d))->format('t') === (new DateTime($d))->format('d')) { [$f, $t] = tr_month($d); $R = tr_range($f, $t);
        $msgs[] = implode("\n", array_merge(["\u{1F4C6} <b>" . (new DateTime($f))->format('F Y') . '</b>'], tr_sum_lines($R['summary'], 'Monthly total'))); }
    $msgs[] = "chartpulse.io \u{00b7} research data, not investment advice";
    return $msgs;
}
function tr_send(array $rep): array {
    $chat = trim((string)t_cfg('telegram_admin_chat', ''));
    if ($chat === '' || trim((string)t_cfg('telegram_bot_token', '')) === '') return [false, 'Telegram admin chat or bot token is not set in config.php'];
    $base = rtrim((string)t_cfg('site_url', 'https://chartpulse.io'), '/');
    $ok = true; $info = '';
    foreach (tr_tg_messages($rep, $base . '/admin/reports.php?d=' . $rep['date']) as $m) { [$k, $info] = t2_tg_send($m, $chat); $ok = $ok && $k; if (!$k) break; usleep(400000); }
    return [$ok, $info];
}
/** Called by cron.php (owner, 30 Sep 2026): from 08:00 IST
    1. yesterday: keep replaying until every trade is closed (its sessions have ended), then mark it closed; nothing is sent yet;
    2. the day before yesterday: run the 48-hour target study, add the conclusions and send the report (Monday's on Wednesday). */
function tr_daily(int $now): ?string {
    $hour = (int)(new DateTime('@' . $now))->setTimezone(new DateTimeZone(TR_TZ))->format('G');
    if ($hour < 8) return tr_settle($now);
    $lk = T_DATA . '/.report_lock'; if (time() - (int)@filemtime($lk) < 240) return null;
    $d1 = tr_shift(tr_today($now), -1); $r1 = tr_load($d1);
    if (!$r1 || empty($r1['closed_at'])) {
        @touch($lk); [$a, $b] = tr_bounds($d1);
        tr_refresh($a, $b, 120, (bool)$r1);
        $left = array_sum(tr_open($a, $b)); $r1 = tr_build($d1, false);
        if ($left === 0 || $hour >= 11) { $r1['closed_at'] = $now; tr_save($d1, $r1); }
        return "report $d1: " . ($left ? "$left trades still being closed" : 'all trades closed');
    }
    $d2 = tr_shift(tr_today($now), -2); $r2 = tr_load($d2);
    if ($r2 && empty($r2['study_at'])) {
        @touch($lk);
        $miss = ts_run($r2, 150);
        if ($miss > 0 && $hour < 12) { tr_save($d2, $r2); return "study $d2: $miss trades left"; }
        $r2['conclusions'] = ts_conclude($r2['trades']); $r2['study_at'] = $now;
        [$ok, $info] = tr_send($r2);
        $r2['sent_at'] = $now; $r2['sent_ok'] = $ok; $r2['send_info'] = $info;   // one attempt; the page keeps the report and can resend
        tr_save($d2, $r2);
        return "report $d2 with the 48-hour study: " . ($ok ? 'sent' : $info);
    }
    return tr_settle($now);
}
function tr_save(string $d, array $r): void { t_write_json(tr_dir() . "/$d.json", $r); }
/** Reports of the last 3 days that still show open trades (built before this fix, or closed late): replay and rebuild them, never resend. */
function tr_settle(int $now): ?string {
    $lk = T_DATA . '/.report_settle'; if (time() - (int)@filemtime($lk) < 600) return null; @touch($lk);
    for ($i = 1; $i <= 3; $i++) {
        $d = tr_shift(tr_today($now), -$i); $r = tr_load($d); if (!$r) continue;
        [$a, $b] = tr_bounds($d);
        if ((!tr_open($a, $b) && ($r['open'] ?? null) === 0) || (int)($r['settle_tries'] ?? 0) >= 12) continue;
        tr_refresh($a, $b, 120, true);
        $n = tr_build($d, false); $n['settle_tries'] = (int)($r['settle_tries'] ?? 0) + 1;
        t_write_json(tr_dir() . "/$d.json", $n);
        return "report $d rebuilt: " . $n['open'] . ' open';
    }
    return null;
}
require_once __DIR__ . '/torus_study.php';   // 48-hour target study (ts_*)
