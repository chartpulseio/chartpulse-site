<?php
/**
 * Binary Pulse Scanner (owner, 13 Nov 2026)                              chartpulse.io
 * Up / down calls with a suggested expiry (15 min, 30 min or 1 hour) for markets whose price is REAL TIME and fresh:
 * forex, crypto and gold / silver spot (PAXG). Delayed markets (Yahoo exchange delay) and stale prices are never used.
 * A call needs at least two ChartPulse signals on the same side (votes):
 *   Trendline Hero rejection / failed break (live) +2, at the line +1 | Hybrid rejection / failed break +1
 *   Day Drive DRIVE / BOUNCE GO +2, ARMED +1 | Movers Reversal snapping / turning +1 | Tier-1 CALL / PUT +1
 *   Liquidation Squeeze ON / READY on the same side (crypto) +1
 *   a signal on the other side takes its votes away.
 * Every call is recorded and checked at expiry against the live price: the page shows the real hit rate per expiry.
 */
declare(strict_types=1);
require_once __DIR__ . '/tl_engine.php';
require_once __DIR__ . '/sq_engine.php';

const BP_TTL = 45;
const BP_FRESH = 360;            // s: price must be this fresh
const BP_GOLD = ['PAXG-USD'];    // gold spot (real-time crypto feed); futures are 10 min late on Yahoo
const BP_DELAYED_EX = ['LSE', 'XETRA', 'EURONEXT', 'SIX', 'MIL', 'BME', 'JPX', 'HKEX', 'CN', 'KRX', 'TWSE', 'SGX', 'ASX', 'B3', 'BMV', 'TADAWUL', 'JSE'];

function bp_exp_for(?string $tf): int { return in_array($tf, ['15m', '30m', '10m', '5m'], true) ? 15 : ($tf === '1H' ? 30 : 60); }

function bp_scan(): array {
    $now = t_now(); $ck = T_DATA . '/bp_scan1.json';
    $c = t_read_json($ck, null); if (is_array($c) && $now - (int)($c['at'] ?? 0) < BP_TTL && isset($c['rows'])) return $c;
    $lock = @fopen(T_DATA . '/.bp_lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return is_array($c) ? $c : ['at' => $now, 'rows' => [], 'meta' => []];
    try {
        $SC = t_scan_load(); $T = []; foreach (t_view_rows($SC, $now) as $r) $T[$r['symbol']] = $r;
        $HE = []; $HY = []; $RB = []; $DD = []; $SQ = [];
        try { foreach (tlh_scan(true)['rows'] as $r) $HE[$r['symbol']] = $r; } catch (Throwable $e) {}
        try { foreach (tlh_scan(false)['rows'] as $r) $HY[$r['symbol']] = $r; } catch (Throwable $e) {}
        $rb = t_read_json(T_DATA . '/rb_scan1.json', null); foreach ((array)($rb['rows'] ?? []) as $r) $RB[$r['symbol']] = $r;
        $dd = t_read_json(T_DATA . '/daydrive/rows.json', []); if (is_array($dd)) $DD = $dd;
        try { foreach (sq_scan()['rows'] as $r) $SQ[$r['symbol']] = $r; } catch (Throwable $e) {}

        $rows = [];
        foreach ($T as $sym => $t1) {
            $mkt = $t1['market'] ?? ''; $isGold = in_array($sym, BP_GOLD, true);
            if (!($mkt === 'Forex' || $mkt === 'Crypto' || $isGold)) continue;
            $raw = $SC['rows'][$sym] ?? [];
            if (empty($t1['live']) || $now - (int)($raw['ts'] ?? 0) > BP_FRESH) continue;          // fresh, live prices only
            if (in_array(t_exchange($sym), BP_DELAYED_EX, true)) continue;
            $v = ['CALL' => 0, 'PUT' => 0]; $why = ['CALL' => [], 'PUT' => []]; $tf = null;
            $add = function (?string $side, int $n, string $txt) use (&$v, &$why) { if ($side === 'CALL' || $side === 'PUT') { $v[$side] += $n; $why[$side][] = $txt; } };
            if ($h = $HE[$sym] ?? null) if (!empty($h['live']) || (int)$h['stage'] === 3) {
                $st = (int)$h['stage'];
                if ($st >= 4) { $add($h['bias'], 2, 'Trendline Hero: ' . strtolower($h['stage_name']) . ' at the ' . $h['tf'] . ' line'); $tf = $h['etf'] ?? $h['tf']; }
                elseif ($st === 3) { $add($h['bias'], 1, 'Trendline Hero: at the ' . $h['tf'] . ' line'); $tf ??= $h['tf']; }
            }
            if (($h = $HY[$sym] ?? null) && (int)$h['stage'] >= 4 && !empty($h['live'])) { $add($h['bias'], 1, 'Hybrid: ' . strtolower($h['stage_name'])); $tf ??= $h['etf'] ?? $h['tf']; }
            if ($d = $DD[$sym] ?? null) {
                $s = (string)($d['status'] ?? ''); $sd = $d['side'] ?? null;
                if ($s === 'DRIVE' || $s === 'BOUNCE GO') { $add($sd, 2, 'Day Drive: ' . ($s === 'DRIVE' ? 'entry ready' : 'bounce entry')); $tf ??= '15m'; }
                elseif ($s === 'ARMED') $add($sd, 1, 'Day Drive: get ready');
            }
            if (($r = $RB[$sym] ?? null) && !empty($r['live']) && (int)$r['stage'] >= 2) { $add($r['side'], 1, 'Movers Reversal: ' . strtolower($r['stage_name'])); $tf ??= '15m'; }
            $sig = $t1['signal'] ?? null; if ($sig === 'CALL' || $sig === 'PUT') $add($sig, 1, 'Tier-1 ' . $sig);
            if (($q = $SQ[$sym] ?? null) && (int)$q['stage'] >= 2) $add($q['side'], 1, 'Squeeze: ' . ($q['stage'] === 3 ? 'squeeze on' : 'crowd at a zone'));
            $side = $v['CALL'] > $v['PUT'] ? 'CALL' : ($v['PUT'] > $v['CALL'] ? 'PUT' : null); if (!$side) continue;
            $net = $v[$side] - $v[$side === 'CALL' ? 'PUT' : 'CALL']; if ($net < 2) continue;
            $exp = bp_exp_for($tf);
            $d = tl_dec((float)$t1['price'] ?: 1);
            $rows[] = ['symbol' => $sym, 'name' => t_name($sym), 'market' => $isGold ? 'Gold' : $mkt, 'class' => $isGold ? 'commodity' : tl_class($sym),
                'side' => $side, 'votes' => $net, 'grade' => $net >= 4 ? 'Strong' : ($net === 3 ? 'Good' : 'Basic'), 'expiry' => $exp,
                'price' => round((float)$t1['price'], $d), 'dec' => $d, 'chg' => $t1['change_pct'] ?? null, 'why' => $why[$side],
                'against' => $why[$side === 'CALL' ? 'PUT' : 'CALL'], 'age' => $now - (int)$raw['ts'], 'key' => [$net, -$exp]];
        }
        usort($rows, fn($a, $b) => $b['key'] <=> $a['key']); foreach ($rows as &$r) unset($r['key']); unset($r);

        // ---- record every call once (symbol + side, 30 min apart) and grade it at expiry with the live price
        $lf = T_DATA . '/bp_log.json'; $L = t_read_json($lf, []); if (!is_array($L)) $L = [];
        $last = []; foreach ($L as $e) $last[$e['s'] . '|' . $e['side']] = max($last[$e['s'] . '|' . $e['side']] ?? 0, (int)$e['t']);
        foreach ($rows as $r) { $k = $r['symbol'] . '|' . $r['side'];
            if ($now - ($last[$k] ?? 0) >= 1800) $L[] = ['t' => $now, 's' => $r['symbol'], 'side' => $r['side'], 'px' => $r['price'], 'exp' => $r['expiry'], 'g' => $r['grade'], 'c' => $r['class']]; }
        foreach ($L as &$e) {
            if (isset($e['res'])) continue;
            $due = (int)$e['t'] + $e['exp'] * 60; if ($now < $due) continue;
            $rr = $SC['rows'][$e['s']] ?? null; $ts = (int)($rr['ts'] ?? 0);
            if ($ts >= $due && $ts <= $due + 420 && isset($rr['price'])) {
                $p = (float)$rr['price']; $e['end'] = $p;
                $e['res'] = $p == $e['px'] ? 'tie' : ((($p > $e['px']) === ($e['side'] === 'CALL')) ? 'win' : 'loss');
            } elseif ($now > $due + 420) $e['res'] = 'skip';   // no price close enough to the expiry: not counted
        } unset($e);
        $L = array_values(array_filter($L, fn($e) => $now - (int)$e['t'] <= 14 * 86400));
        t_write_json($lf, $L);
        $stats = [];
        foreach ([15, 30, 60] as $x) { $w = 0; $l = 0;
            foreach ($L as $e) if ($e['exp'] === $x && $now - $e['t'] <= 7 * 86400) { if (($e['res'] ?? '') === 'win') $w++; elseif (($e['res'] ?? '') === 'loss') $l++; }
            $stats[$x] = ['win' => $w, 'loss' => $l, 'rate' => $w + $l ? round($w / ($w + $l) * 100) : null]; }
        $recent = array_slice(array_reverse(array_values(array_filter($L, fn($e) => isset($e['res']) && $e['res'] !== 'skip'))), 0, 20);
        $p = ['at' => $now, 'rows' => $rows, 'meta' => ['calls' => count($rows), 'stats' => $stats, 'logged' => count($L)], 'recent' => $recent];
        t_write_json($ck, $p);
        return $p;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
