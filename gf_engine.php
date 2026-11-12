<?php
/**
 * ChartPulse God-father Scanner                                   chartpulse.io
 * (owner, 12 Nov 2026): every ChartPulse scanner joined into one confluence board.
 *
 * Scanners are grouped by the job they do, and a group counts ONCE (five 200-EMA style
 * scanners agreeing are one opinion, not five):
 *   BIAS      Daily trend + 4H or Weekly agreeing (same MTF as the trend-line scanners)  -> hard gate
 *   LOCATION  the "Power Zone": trend lines on any chart (2+ touches), today's Day High / Low,
 *             PDH/PDL/Fib/pivot zones on the line, the 200 EMA, Daily OB / FVG / OTE            -> hard gate
 *   SETUP     Rejection, Fake breakout (trap), Breakout + retest, Day-extreme reversal
 *   TRIGGER   a turn has printed: Hero / Hybrid rejection or failed break, Tier-1 CALL/PUT,
 *             200 EMA entry, Day Drive DRIVE/ARMED
 *   EXTRAS    momentum (EMA strategy scanners), participation (ORB + VWAP + volume),
 *             news (high-impact story against the side = NEWS RISK, with it = boost)
 * Grades are labels, never points on the page: A+ / A / B / GET READY / WATCH / NEWS RISK.
 * Not measured yet: no win rate is claimed. Every A+ / A is logged for later checking.
 */
declare(strict_types=1);
require_once __DIR__ . '/tl_engine.php';
require_once __DIR__ . '/news_scan.php';

const GF_TTL = 75;                                    // seconds the board stays cached
const GF_LINE_TFS = ['1W', '1D', '4H', '1H', '30m', '15m', '10m'];
const GF_BIG = ['1W' => 3, '1D' => 3, '4H' => 2, '1H' => 1, '30m' => 0, '15m' => 0, '10m' => 0];
const GF_TFN = ['1W' => 'Weekly', '1D' => 'Daily', '4H' => '4H', '1H' => '1H', '30m' => '30m', '15m' => '15m', '10m' => '10m'];
// Yahoo's own exchange delay (15-30 min; see the 5 Nov delayed-symbols note). A turn candle that is 20 min old is not an entry.
const GF_DELAYED_EX = ['LSE' => 20, 'XETRA' => 15, 'EURONEXT' => 15, 'SIX' => 30, 'MIL' => 20, 'BME' => 15, 'JPX' => 20, 'HKEX' => 15, 'SSE' => 30, 'SZSE' => 30, 'CN' => 30,
    'KRX' => 20, 'TWSE' => 20, 'SGX' => 20, 'ASX' => 20, 'B3' => 15, 'BMV' => 20, 'TADAWUL' => 15, 'JSE' => 15];
const GF_LATE = 600;      // s: last trade older than this at the fetch, or fetch older than this -> data is late
const GF_NEAR = 0.35;     // x daily ATR: a line this close to price is part of the zone
const GF_RETEST = 0.35;   // x daily ATR: a broken line price has come back to
const GF_STRAT = [
    'institutional' => ['3-liquidity-fvg' => ['Liquidity Sweep + OB + FVG', 'loc'], '1-smc-power-of-3' => ['SMC Power of 3 + OTE', 'loc'], '4-orb-vwap' => ['Range Breakout + VWAP + Volume', 'part']],
    'ema-strategies' => ['3-ema-50-21-alignment' => ['EMA 200 / 50 / 21 alignment', 'mom'], '7-ema-supertrend-rsi' => ['EMA 200 + Supertrend + RSI 50', 'mom'], '5-vwap-ema-combo' => ['VWAP + EMA 200', 'mom']],
];

function gf_side_of(?string $b): ?string { return $b === 'CALL' || $b === 'UP' || $b === 'LONG' || $b === 'BUY' ? 'CALL' : ($b === 'PUT' || $b === 'DOWN' || $b === 'SHORT' || $b === 'SELL' ? 'PUT' : null); }

/** Daily trend is the veto; 4H or Weekly must agree. */
function gf_bias(?array $m): ?array {
    $d = $m['d'] ?? null; if (!is_array($d)) return null;
    $D = (int)($d['1D'] ?? 0); if (!$D) return null;
    $w4 = (int)($d['4H'] ?? 0) === $D; $w1 = (int)($d['1W'] ?? 0) === $D;
    if (!$w4 && !$w1) return null;
    $n = 0; foreach ($d as $v) if ((int)$v === $D) $n++;
    return ['side' => $D > 0 ? 'CALL' : 'PUT', 'n' => $n, 'of' => count($d), 'with4h' => $w4, 'with1w' => $w1,
            'pull' => array_values(array_filter(['30m', '1H'], fn($k) => isset($d[$k]) && (int)$d[$k] === -$D))];
}

/** Lines around price on the side of the trade, from every chart. CALL: support (and broken resistance), PUT: mirror. */
function gf_lines(array $tl, string $side, float $atrPct): array {
    $out = ['at' => [], 'retest' => [], 'ahead' => []]; $call = $side === 'CALL';
    foreach (GF_LINE_TFS as $k) {
        $c = $tl['tf'][$k] ?? null; if (!is_array($c)) continue;
        $own = $c[$call ? 'sup' : 'res'] ?? null; $opp = $c[$call ? 'res' : 'sup'] ?? null;
        $nearPct = $atrPct > 0 ? GF_NEAR * $atrPct : 0.4;
        if (is_array($own) && (int)$own['n'] >= 2 && $own['st'] !== 'broken' && abs((float)$own['gap']) <= $nearPct)
            $out['at'][] = ['tf' => $k, 'n' => (int)$own['n'], 'lv' => (float)$own['now'], 'dir' => $own['dir'], 'z' => $own['z'] ?? null, 'st' => $own['st']];
        if (is_array($opp) && (int)$opp['n'] >= 2) {
            $g = (float)$opp['gap'];   // (line - price) / price %
            if ($opp['st'] === 'broken' && abs($g) <= ($atrPct > 0 ? GF_RETEST * $atrPct : 0.4) && ($call ? $g <= 0 : $g >= 0))
                $out['retest'][] = ['tf' => $k, 'n' => (int)$opp['n'], 'lv' => (float)$opp['now'], 'dir' => $opp['dir'], 'z' => $opp['z'] ?? null];
            elseif ($opp['st'] !== 'broken' && ($call ? $g > 0 : $g < 0) && (GF_BIG[$k] ?? 0) >= 1)
                $out['ahead'][] = ['tf' => $k, 'n' => (int)$opp['n'], 'lv' => (float)$opp['now'], 'gap' => $g];
        }
    }
    usort($out['ahead'], fn($a, $b) => abs($a['gap']) <=> abs($b['gap']));
    return $out;
}

/** The 6 strategy scanners, FULL lists on 1D, 1H and 15m bars: symbol => [[name, grp, side, setup, tf], ...]. */
function gf_read_strats(): array {
    $by = [];
    foreach (GF_STRAT as $dir => $ids) foreach (['1D', '1H', '15m'] as $tf) {
        $c = t_read_json(__DIR__ . "/$dir/cache/full_$tf.json", null);
        if (!is_array($c) && $tf === '1D') {   // first run: fall back to the page caches (top 100)
            foreach ($ids as $id => [$name, $grp]) { $pc = t_read_json(__DIR__ . "/$dir/cache/scan_$id.json", null);
                foreach ((is_array($pc) ? $pc['data'] ?? [] : []) as $r) if ($sd = gf_side_of($r['bias'] ?? null)) $by[(string)$r['symbol']][] = ['name' => $name, 'grp' => $grp, 'side' => $sd, 'setup' => (string)($r['setup'] ?? ''), 'tf' => '1D']; }
            continue;
        }
        if (!is_array($c) || time() - (int)($c['at'] ?? 0) > 3 * 3600) continue;   // stale intraday readings are ignored
        foreach ($ids as $id => [$name, $grp]) foreach ((array)($c['sig'][$id] ?? []) as $x) {
            $sd = gf_side_of($x[1] ?? null); if (!$sd) continue;
            $by[(string)$x[0]][] = ['name' => $name, 'grp' => $grp, 'side' => $sd, 'setup' => (string)($x[3] ?? ''), 'tf' => $tf];
        }
    }
    return $by;
}
/** "EMA 200 + Supertrend + RSI 50 (1H, Daily)" */
function gf_fam(array $L): string {
    $g = []; foreach ($L as $x) $g[$x['name']][] = $x['tf'] === '1D' ? 'Daily' : $x['tf'];
    $o = []; foreach ($g as $n => $t) $o[] = $n . ' (' . implode(', ', array_unique($t)) . ')';
    return implode(' · ', array_slice($o, 0, 2));
}

function gf_scan(bool $force = false): array {
    $now = t_now(); $ck = T_DATA . '/gf_scan1.json';
    $c = t_read_json($ck, null);
    if (!$force && is_array($c) && $now - (int)($c['at'] ?? 0) < GF_TTL && !empty($c['rows'])) return $c;
    $lock = @fopen(T_DATA . '/.gf_lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if (is_array($c)) return $c; return ['at' => $now, 'rows' => [], 'meta' => ['progress' => 0]]; }
    try {
        $t0 = microtime(true);
        $SC = t_scan_load(); $T = []; foreach (t_view_rows($SC, $now) as $r) $T[$r['symbol']] = $r;
        $TL = []; $tlp = tl_scan(); foreach ($tlp['rows'] as $r) $TL[$r['symbol']] = $r;
        $HE = []; $hp = tlh_scan(true); foreach ($hp['rows'] as $r) $HE[$r['symbol']] = $r;
        $HY = []; $yp = tlh_scan(false); foreach ($yp['rows'] as $r) $HY[$r['symbol']] = $r;
        $NS = []; try { foreach (ns_scan()['rows'] as $r) $NS[$r['symbol']] = $r; } catch (Throwable $e) {}
        $E2 = []; $e2 = t_read_json(T_DATA . '/ema200_signals2.json', null);
        foreach ((is_array($e2) ? $e2['signals'] ?? [] : []) as $r) if (isset($r['symbol'])) $E2[$r['symbol']] = $r;
        $DD = t_read_json(T_DATA . '/daydrive/rows.json', []); if (!is_array($DD)) $DD = [];
        $ST = gf_read_strats();

        $rows = []; $cnt = ['A+' => 0, 'A' => 0, 'B' => 0, 'READY' => 0, 'WATCH' => 0, 'NEWS' => 0, 'bias' => 0];
        foreach ($TL as $sym => $tl) {
            $t1 = $T[$sym] ?? null; if (!$t1) continue;
            $B = gf_bias($tl['mtf'] ?? null); if (!$B) continue;   // GATE 1: no clear big trend -> not on the board
            $cnt['bias']++;
            $side = $B['side']; $call = $side === 'CALL';
            // ---- data freshness: exchange delay (Yahoo) + how old the last trade / last fetch is
            $raw = $SC['rows'][$sym] ?? []; $ex = t_exchange($sym);
            $late = 0; if (isset(GF_DELAYED_EX[$ex]) && ($raw['src'] ?? 'yahoo') === 'yahoo') $late = GF_DELAYED_EX[$ex];
            $fetchAge = $now - (int)($raw['ts'] ?? 0); $rmt = (int)($raw['rmt'] ?? 0);
            $tradeLag = $rmt > 0 ? (int)($raw['ts'] ?? 0) - $rmt : 0;   // some feeds (Alpaca, Upstox) carry no trade time
            if (!empty($t1['live']) && ($fetchAge > 900 || $tradeLag > GF_LATE)) $late = max($late, (int)round(max($fetchAge, $tradeLag) / 60));   // closed markets: not 'late', just closed
            $live = !empty($t1['live']); $atrPct = (float)($t1['atr_pct'] ?? 0); $px = (float)($t1['price'] ?? $tl['price']);
            $L = gf_lines($tl, $side, $atrPct);
            $he = $HE[$sym] ?? null; if ($he && gf_side_of($he['bias'] ?? null) !== $side) $he = null;
            $hy = $HY[$sym] ?? null; if ($hy && gf_side_of($hy['bias'] ?? null) !== $side) $hy = null;
            $line = $he ?: $hy;   // Hero first (stricter MTF), Hybrid as the fallback
            $st = $ST[$sym] ?? []; $e2 = $E2[$sym] ?? null; $dd = $DD[$sym] ?? null; $ns = $NS[$sym] ?? null;

            // ---- LOCATION: the Power Zone (each kind counts once)
            $pz = []; $why = [];
            $tlAt = $L['at'];
            if ($tlAt) {
                usort($tlAt, fn($a, $b) => [(GF_BIG[$b['tf']] ?? 0), $b['n']] <=> [(GF_BIG[$a['tf']] ?? 0), $a['n']]);
                $tfs = array_values(array_unique(array_map(fn($x) => $x['tf'], $tlAt)));
                $pz['line'] = 'Trend line ' . implode(' + ', array_map(fn($k) => GF_TFN[$k], array_slice($tfs, 0, 3)));
                if (count($tfs) >= 2) $pz['multi'] = count($tfs) . ' charts\' lines meet here';
            }
            if ($L['retest']) { $r0 = $L['retest'][0]; $pz['retest'] = 'Broken ' . GF_TFN[$r0['tf']] . ' line being retested'; }
            $dayAt = ($t1['zone_near'] ?? '') === ($call ? 'ATR Low' : 'ATR High') && isset($t1['gap']) && abs((float)$t1['gap']) <= 0.75;
            if ($line && (int)$line['stage'] >= 3) $dayAt = true;   // Hero / Hybrid: today's extreme is on the line
            if ($dayAt) $pz['day'] = $call ? "Today's Day Low" : "Today's Day High";
            $zn = array_values(array_unique(array_map('strval', (array)($line['zone']['n'] ?? []))));
            $zt = null; foreach (array_merge($tlAt, $L['retest']) as $x) if (!empty($x['z'])) { $zt = $x['z']; if ($zt === 'strong') break; }
            if ($zn) $pz['zone'] = implode(' · ', array_slice($zn, 0, 3));
            elseif ($zt) $pz['zone'] = ucfirst($zt) . ' zone on the line (PDH/PDL, pivot or Fib)';
            $emaAt = false;
            if (!empty($line['e200']['ok'])) $emaAt = true;
            if ($e2 && gf_side_of($e2['signal'] ?? null) === $side) foreach ((array)($e2['tfs'] ?? []) as $k => $x) if (!empty($x['in']) && in_array($k, ['1D', '4H', '1H'], true)) { $emaAt = true; break; }
            if ($emaAt) $pz['ema'] = '200 EMA on the zone';
            $smc = array_values(array_filter($st, fn($x) => $x['grp'] === 'loc' && $x['side'] === $side));
            if ($smc) $pz['smc'] = ($smc[0]['tf'] === '1D' ? 'Daily ' : $smc[0]['tf'] . ' ') . ($smc[0]['setup'] !== '' ? $smc[0]['setup'] : $smc[0]['name']);
            $nPz = count(array_intersect_key($pz, array_flip(['line', 'retest', 'day', 'zone', 'ema', 'smc'])));
            $hasLine = isset($pz['line']) || isset($pz['retest']) || ($line && (int)$line['stage'] >= 1);
            if (!$hasLine && !$dayAt && !$smc) continue;   // GATE 2: price is nowhere near a zone

            // ---- SETUP type
            $setup = null; $stage = $line ? (int)$line['stage'] : 0;
            if ($stage === 5) $setup = ['trap', 'Fake breakout (trap)'];
            elseif ($stage === 4) $setup = ['rej', 'Rejection at the line'];
            elseif (isset($pz['retest'])) $setup = ['retest', 'Breakout + retest'];
            elseif ($stage === 3 || isset($pz['line'])) $setup = ['line', 'At the trend line'];
            elseif ($dayAt) $setup = ['day', $call ? 'Day Low reversal' : 'Day High reversal'];
            elseif ($smc) $setup = ['smc', 'Order block / FVG'];
            else $setup = ['near', 'Approaching the line'];

            // ---- TRIGGER: has the turn printed?
            $trig = [];
            if ($line && $stage >= 4 && !empty($line['live'])) $trig[] = ($stage === 5 ? 'Failed break' : 'Rejection') . ' candle on ' . ($line['etf'] ?? '15m');
            if (gf_side_of($t1['signal'] ?? null) === $side && !empty($t1['live'])) $trig[] = 'Tier-1 ' . $side . ' fired';
            if ($e2 && gf_side_of($e2['signal'] ?? null) === $side && !empty($e2['is_entry']) && in_array($e2['entry_type'] ?? '', ['best', 'confirmed'], true)) $trig[] = '200 EMA entry (' . $e2['entry_type'] . ')';
            if (is_array($dd) && gf_side_of($dd['side'] ?? null) === $side && in_array($dd['status'] ?? '', ['DRIVE', 'ARMED'], true)) $trig[] = 'Day Drive ' . $dd['status'];
            $orb = array_values(array_filter($st, fn($x) => $x['grp'] === 'part' && $x['side'] === $side));

            // ---- EXTRAS
            $mom = array_values(array_filter($st, fn($x) => $x['grp'] === 'mom' && $x['side'] === $side));
            // only a DAILY momentum scanner against the trade counts against it: at a reversal entry the 1H / 15m
            // momentum still points the old way (it lags), so an intraday 'against' is expected, not a warning
            $momAgainst = array_values(array_filter($st, fn($x) => $x['grp'] === 'mom' && $x['side'] !== $side && $x['tf'] === '1D'));
            $newsRisk = false; $newsBoost = false; $newsTxt = null;
            if ($ns) {
                $nside = $ns['side'] === 'UP' ? 'CALL' : ($ns['side'] === 'DOWN' ? 'PUT' : null);
                $fresh = $now - (int)$ns['last'] <= 3 * 3600;
                if ($fresh && $ns['imp'] === 'high' && $nside && $nside !== $side) { $newsRisk = true; $newsTxt = 'High-impact news points the other way'; }
                elseif ($fresh && $nside === $side) { $newsBoost = true; $newsTxt = 'Fresh news points the same way'; }
                elseif ($fresh && $ns['imp'] === 'high') $newsTxt = 'High-impact news today, mixed tone';
            }
            $strongLine = false; foreach (array_merge($tlAt, $L['retest']) as $x) if ((GF_BIG[$x['tf']] ?? 0) >= 2 || $x['n'] >= 3) $strongLine = true;
            if ($line && ((GF_BIG[$line['tf']] ?? 0) >= 2 || (int)$line['touches'] >= 3)) $strongLine = true;
            $strongBias = $B['n'] >= 4;
            $extra = ($mom ? 1 : 0) + ($orb ? 1 : 0) + ($newsBoost ? 1 : 0) + ($strongBias ? 1 : 0) + ($B['pull'] ? 1 : 0);

            // A+ needs a real turn candle at the line or a Tier-1 tick (a 200 EMA / Day Drive state alone is not enough),
            // the line AND today's high/low in the zone, and two extras that are not just the pullback
            $realTurn = ($line && $stage >= 4 && !empty($line['live'])) || (gf_side_of($t1['signal'] ?? null) === $side && !empty($t1['live']));
            $lineAt = isset($pz['line']) || isset($pz['retest']) || $stage >= 3;
            $extra2 = ($mom ? 1 : 0) + ($orb ? 1 : 0) + ($newsBoost ? 1 : 0) + ($strongBias ? 1 : 0) + ($emaAt ? 1 : 0) + (isset($pz['multi']) ? 1 : 0);
            // ---- GRADE (labels only)
            $fired = (bool)$trig;
            if (!$live) $grade = 'WATCH';
            elseif ($newsRisk) $grade = 'NEWS';
            elseif ($late === 0 && $realTurn && $nPz >= 4 && $dayAt && $lineAt && $strongLine && ($mom || $orb || $newsBoost) && !$momAgainst) $grade = 'A+';
            elseif ($fired && $nPz >= 2) $grade = 'A';
            elseif ($fired) $grade = 'B';
            elseif ($nPz >= 2 && ($stage >= 3 || isset($pz['line']) || isset($pz['retest']) || $dayAt)) $grade = 'READY';
            else $grade = 'WATCH';
            $cnt[$grade]++;

            // ---- PLAN: entry, stop, targets (target 2 = the next big line ahead)
            $d = tl_dec($px ?: 1); $plan = null;
            $p = $line['plan'] ?? null;
            if (is_array($p) && isset($p['sl'])) $plan = ['entry' => (float)$p['entry'], 'sl' => (float)$p['sl'], 't1' => (float)$p['t1'], 't2' => isset($p['t2']) ? (float)$p['t2'] : null, 'from' => 'line'];
            $big = $L['ahead'][0] ?? null;
            if ($plan && $big) { $bl = (float)$big['lv']; if (($call && $bl > $plan['t1']) || (!$call && $bl < $plan['t1'])) { $plan['t2'] = $bl; $plan['t2why'] = 'next ' . GF_TFN[$big['tf']] . ' trend line'; } }
            if ($plan) { $risk = abs($plan['entry'] - $plan['sl']); $plan['rr'] = $risk > 0 ? round(abs($plan['t1'] - $plan['entry']) / $risk, 1) : null;
                foreach (['entry', 'sl', 't1', 't2'] as $k) if (isset($plan[$k]) && $plan[$k] !== null) $plan[$k] = round((float)$plan[$k], $d); }

            // ---- checklist the page shows (✓ / ✗), plain words
            $chk = [
                ['k' => 'bias', 'ok' => true, 't' => 'Big trend ' . ($call ? 'up' : 'down'), 'd' => 'Daily' . ($B['with4h'] ? ' + 4H' : '') . ($B['with1w'] ? ' + Weekly' : '') . " agree · {$B['n']}/{$B['of']} charts"],
                ['k' => 'zone', 'ok' => $nPz >= 2, 't' => $nPz >= 3 ? 'Power Zone (' . $nPz . ' levels meet)' : ($nPz === 2 ? 'Double zone' : 'Single level'), 'd' => implode(' · ', array_values(array_intersect_key($pz, array_flip(['line', 'retest', 'day', 'zone', 'ema', 'smc']))))],
                ['k' => 'line', 'ok' => $strongLine, 't' => $strongLine ? 'Strong trend line' : 'Small-chart line', 'd' => $line ? GF_TFN[$line['tf']] . ' line, ' . $line['touches'] . ' touches' . (($line['pattern'] ?? '') !== '' ? ' · ' . $line['pattern'] : '') : ($tlAt ? GF_TFN[$tlAt[0]['tf']] . ' line, ' . $tlAt[0]['n'] . ' touches' : 'no line right at price')],
                ['k' => 'trig', 'ok' => $fired, 't' => $fired ? 'Entry trigger fired' : 'Waiting for the turn', 'd' => $fired ? implode(' · ', $trig) : 'No turn candle yet: wait for a rejection, a failed break or a Tier-1 tick'],
                ['k' => 'mom', 'ok' => (bool)$mom && !$momAgainst, 't' => $mom ? 'Momentum agrees' : ($momAgainst ? 'Momentum disagrees' : 'Momentum neutral'), 'd' => $mom ? gf_fam($mom) : ($momAgainst ? gf_fam($momAgainst) . ' points the other way' : 'No EMA strategy signal on Daily, 1H or 15m')],
                ['k' => 'part', 'ok' => (bool)$orb, 't' => $orb ? 'Volume / VWAP with it' : 'No volume spike', 'd' => $orb ? gf_fam($orb) . ': ' . $orb[0]['setup'] : 'Range Breakout + VWAP has nothing here (Daily, 1H, 15m)'],
                ['k' => 'data', 'ok' => $late === 0, 't' => $late ? 'Data ~' . $late . ' min late' : 'Live data', 'd' => $late ? 'This market\'s price feed runs about ' . $late . ' minutes behind, so the turn candle may already be old. Capped at A: check the live chart before acting.' : 'Price feed is real time and fresh'],
                ['k' => 'news', 'ok' => !$newsRisk, 't' => $newsRisk ? 'News risk' : ($newsBoost ? 'News with it' : 'No news against it'), 'd' => $newsTxt ?? 'No fresh high-impact story'],
            ];
            $labels = [];
            if ($nPz >= 3) $labels[] = ['pz', $nPz >= 5 ? $nPz . '-level Power Zone' : ($nPz === 4 ? 'Quad Power Zone' : 'Triple Power Zone')];
            if (isset($pz['multi'])) $labels[] = ['mtf', 'Multi-chart lines'];
            if ($strongLine) $labels[] = ['line', 'Strong line'];
            if ($B['pull']) $labels[] = ['pull', implode('/', $B['pull']) . ' pullback'];
            if ($emaAt) $labels[] = ['ema', '200 EMA'];
            if ($smc) $labels[] = ['smc', 'OB / FVG'];
            if ($orb) $labels[] = ['vol', 'Volume'];
            foreach (['1D' => 'Daily', '1H' => '1H', '15m' => '15m'] as $tk => $tn) if (array_filter($mom, fn($x) => $x['tf'] === $tk)) $labels[] = ['mom', $tn . ' momentum'];
            if ($newsBoost) $labels[] = ['news', 'News with it'];
            if ($newsRisk) $labels[] = ['risk', 'News risk'];
            if ($late) $labels[] = ['risk', 'Data ~' . $late . ' min late'];

            $say = ($call ? 'CALL' : 'PUT') . ': big trend ' . ($call ? 'up' : 'down') . ', price at ' . strtolower($nPz >= 3 ? 'a power zone' : ($nPz === 2 ? 'a double zone' : 'a level'))
                 . ' (' . implode(', ', array_slice(array_values(array_intersect_key($pz, array_flip(['line', 'retest', 'day', 'zone', 'ema', 'smc']))), 0, 3)) . '). '
                 . ($fired ? 'The turn has printed: ' . $trig[0] . '.' : ($grade === 'READY' ? 'Get ready: wait for a turn candle at the zone.' : 'Watch only for now.'));

            $rank = ['A+' => 6, 'A' => 5, 'B' => 4, 'READY' => 3, 'NEWS' => 2, 'WATCH' => 1][$grade];
            $rows[] = ['symbol' => $sym, 'name' => t_name($sym), 'market' => $t1['market'] ?? '', 'class' => tl_class($sym), 'tv' => $t1['tv'] ?? null,
                'price' => round($px, $d), 'dec' => $d, 'chg' => $t1['change_pct'] ?? null, 'live' => $live, 'side' => $side, 'grade' => $grade,
                'setup' => $setup[1], 'skind' => $setup[0], 'pz' => $nPz, 'labels' => $labels, 'checks' => $chk, 'say' => $say, 'plan' => $plan,
                'mtf' => $tl['mtf']['d'] ?? [], 'line_tf' => $line['tf'] ?? ($tlAt[0]['tf'] ?? null), 'stage' => $stage,
                'late' => $late, 'news' => $ns ? array_slice($ns['stories'] ?? [], 0, 2) : [],
                'key' => [$rank, $live ? 1 : 0, $nPz, $strongLine ? 1 : 0, $extra, count($trig)]];
        }
        usort($rows, fn($a, $b) => $b['key'] <=> $a['key']);
        foreach ($rows as &$r) unset($r['key']); unset($r);
        $prog = min((float)($tlp['meta']['progress'] ?? 1), (float)($hp['meta']['progress'] ?? 1), (float)($yp['meta']['progress'] ?? 1));
        $p = ['at' => $now, 'rows' => $rows, 'meta' => ['universe' => count($T), 'with_bias' => $cnt['bias'], 'counts' => $cnt, 'progress' => $prog,
              'took_ms' => (int)round((microtime(true) - $t0) * 1000)]];
        if ($rows || $prog >= 0.9) t_write_json($ck, $p);
        gf_log($rows, $now);
        return $p;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Every A+ / A is written once per symbol+side per day, so grades can be checked against outcomes later. */
function gf_log(array $rows, int $now): void {
    $f = T_DATA . '/gf_log.json'; $L = t_read_json($f, []); if (!is_array($L)) $L = [];
    $day = gmdate('Y-m-d', $now); $ch = false;
    foreach ($rows as $r) {
        if (!in_array($r['grade'], ['A+', 'A'], true) || !$r['plan']) continue;
        $k = $day . '|' . $r['symbol'] . '|' . $r['side']; if (isset($L[$k])) continue;
        $L[$k] = ['t' => $now, 's' => $r['symbol'], 'side' => $r['side'], 'g' => $r['grade'], 'setup' => $r['setup'], 'pz' => $r['pz'], 'plan' => $r['plan']]; $ch = true;
    }
    if ($ch) { if (count($L) > 5000) $L = array_slice($L, -5000, null, true); t_write_json($f, $L); }
}
