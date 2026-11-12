<?php
/**
 * Liquidation Squeeze Scanner (owner, 13 Nov 2026)                      chartpulse.io
 * Crypto perpetual futures crowding + ChartPulse zones.
 *   Crowded longs  (high positive funding, open interest rising) at a resistance / today's high  -> long squeeze risk -> PUT
 *   Crowded shorts (negative funding, open interest rising)      at a support / today's low      -> short squeeze risk -> CALL
 *   SQUEEZE ON = price is already moving against the crowd while open interest falls (positions being closed / liquidated)
 * Data: Bybit public linear tickers (one call: funding, open interest, 24h change, turnover), Binance futures as fallback.
 * Open-interest change is measured from our own snapshots (kept 8 h). Liquidations themselves are not streamed yet:
 * the squeeze is read from funding + open interest + price, and the page says so.
 */
declare(strict_types=1);
require_once __DIR__ . '/tl_engine.php';

const SQ_TTL = 55;
const SQ_LONG = 0.0003;      // funding per interval >= 0.03%: longs paying, crowded
const SQ_LONG_X = 0.0008;    // >= 0.08%: extreme
const SQ_SHORT = -0.0002;    // <= -0.02%: shorts paying, crowded
const SQ_SHORT_X = -0.0006;

/** Our crypto symbol -> list of perp tickers to try (BTC-USD -> BTCUSDT; GRT6719-USD -> GRTUSDT; SHIB -> SHIB1000USDT / 1000SHIBUSDT). */
function sq_perps(string $sym): array {
    if (!preg_match('/^([A-Z0-9]+?)\d*-USD$/', $sym, $m)) return [];
    $b = preg_replace('/\d{3,}$/', '', $m[1]) ?: $m[1];
    return [$b . 'USDT', '1000' . $b . 'USDT', $b . '1000USDT', '10000' . $b . 'USDT', '1000000' . $b . 'USDT'];
}

/** sym => [fr, oi_usd, px, chg24, turnover, src] from Bybit (or Binance). */
function sq_market(): array {
    $b = t_fetch_multi(['by' => 'https://api.bybit.com/v5/market/tickers?category=linear'], $codes);
    $M = [];
    $j = $b['by'] ? json_decode($b['by'], true) : null;
    foreach ((array)($j['result']['list'] ?? []) as $x) {
        if (!str_ends_with((string)$x['symbol'], 'USDT')) continue;
        $M[$x['symbol']] = ['fr' => (float)($x['fundingRate'] ?? 0), 'oi' => (float)($x['openInterestValue'] ?? 0), 'px' => (float)($x['lastPrice'] ?? 0),
                            'chg' => (float)($x['price24hPcnt'] ?? 0) * 100, 'tv' => (float)($x['turnover24h'] ?? 0), 'src' => 'Bybit'];
    }
    if (count($M) > 50) return $M;
    // fallback: Binance futures (funding + price + volume in two calls; open interest from our own value estimate is unavailable here)
    $b = t_fetch_multi(['p' => 'https://fapi.binance.com/fapi/v1/premiumIndex', 't' => 'https://fapi.binance.com/fapi/v1/ticker/24hr']);
    $P = $b['p'] ? json_decode($b['p'], true) : []; $T = $b['t'] ? json_decode($b['t'], true) : [];
    $tk = []; foreach ((array)$T as $x) $tk[$x['symbol']] = $x;
    foreach ((array)$P as $x) { $s = (string)$x['symbol']; if (!str_ends_with($s, 'USDT')) continue; $t = $tk[$s] ?? [];
        $M[$s] = ['fr' => (float)$x['lastFundingRate'], 'oi' => 0.0, 'px' => (float)$x['markPrice'], 'chg' => (float)($t['priceChangePercent'] ?? 0), 'tv' => (float)($t['quoteVolume'] ?? 0), 'src' => 'Binance']; }
    return $M;
}

function sq_scan(): array {
    $now = t_now(); $ck = T_DATA . '/sq_scan1.json';
    $c = t_read_json($ck, null); if (is_array($c) && $now - (int)($c['at'] ?? 0) < SQ_TTL && !empty($c['rows'])) return $c;
    $lock = @fopen(T_DATA . '/.sq_lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return is_array($c) ? $c : ['at' => $now, 'rows' => [], 'meta' => ['error' => 'busy']];
    try {
        $M = sq_market();
        if (!$M) { if (is_array($c)) return $c; throw new RuntimeException('no futures data'); }
        // open-interest + price history from our own snapshots (one every ~4 min, 8 h kept)
        $hf = T_DATA . '/sq_hist.json'; $H = t_read_json($hf, []); if (!is_array($H)) $H = [];
        $SC = t_scan_load(); $T = []; foreach (t_view_rows($SC, $now) as $r) $T[$r['symbol']] = $r;
        $HE = []; try { foreach (tlh_scan(true)['rows'] as $r) $HE[$r['symbol']] = $r; } catch (Throwable $e) {}
        $rows = []; $snap = $now - (int)($H['_t'] ?? 0) >= 230;
        foreach (t_symbols() as $sym => $mkt) {
            if ($mkt !== 'Crypto') continue;
            $pk = null; foreach (sq_perps($sym) as $p) if (isset($M[$p])) { $pk = $p; break; } if (!$pk) continue;
            $x = $M[$pk]; $t1 = $T[$sym] ?? null;
            $hist = (array)($H[$pk] ?? []);
            if ($snap) { $hist[] = [$now, round($x['oi']), $x['px']]; $hist = array_values(array_filter($hist, fn($h) => $now - $h[0] <= 8 * 3600)); $H[$pk] = $hist; }
            $ago = function (int $sec) use ($hist, $now) { $best = null; foreach ($hist as $h) if ($now - $h[0] >= $sec * 0.8) $best = $h; return $best; };
            $h1 = $ago(3600); $h4 = $ago(4 * 3600);
            $oi1 = $h1 && $h1[1] > 0 && $x['oi'] > 0 ? ($x['oi'] / $h1[1] - 1) * 100 : null;
            $oi4 = $h4 && $h4[1] > 0 && $x['oi'] > 0 ? ($x['oi'] / $h4[1] - 1) * 100 : null;
            $p1 = $h1 && $h1[2] > 0 ? ($x['px'] / $h1[2] - 1) * 100 : null;
            $fr = $x['fr'];
            $side = $fr >= SQ_LONG ? 'PUT' : ($fr <= SQ_SHORT ? 'CALL' : null);
            if (!$side) continue;
            $put = $side === 'PUT'; $xtr = $put ? $fr >= SQ_LONG_X : $fr <= SQ_SHORT_X;
            // at a zone? today's high / low (Tier-1 ATR zone or top / bottom of today's range), or a Trendline Hero line on that side
            $he = $HE[$sym] ?? null; $heOk = $he && ($he['bias'] ?? '') === $side && (int)$he['stage'] >= 1;
            $rp = $t1['range_pos'] ?? null;
            $dayAt = $t1 && ((($t1['zone_near'] ?? '') === ($put ? 'ATR High' : 'ATR Low') && abs((float)($t1['gap'] ?? 9)) <= 1.0) || ($rp !== null && ($put ? $rp >= 0.85 : $rp <= 0.15)));
            $zone = []; if ($dayAt) $zone[] = $put ? "Today's high" : "Today's low";
            if ($heOk) $zone[] = ($he['tf'] ?? '') . ' trend line' . (!empty($he['anchor']) && !str_starts_with((string)$he['anchor'], 'Day') ? ' + ' . $he['anchor'] : '');
            $oiUp = ($oi4 ?? $oi1 ?? 0) >= 3; $oiDown = $oi1 !== null && $oi1 <= -2;
            $against = $p1 !== null && ($put ? $p1 <= -0.6 : $p1 >= 0.6);
            if ($against && $oiDown) $st = 3;                 // SQUEEZE ON: crowd being flushed
            elseif ($zone && ($oiUp || $xtr)) $st = 2;          // READY: crowded + at a zone
            elseif ($oiUp || $xtr) $st = 1;                     // BUILDING
            else $st = 0;                                       // crowded only
            $d = tl_dec($x['px'] ?: 1);
            $fmt = fn($v) => $v === null ? null : round($v, 2);
            $why = ($put ? 'Longs are crowded' : 'Shorts are crowded') . ': funding ' . number_format($fr * 100, 4) . '% ' . ($put ? '(longs pay shorts)' : '(shorts pay longs)')
                 . ($oi4 !== null ? ', open interest ' . ($oi4 >= 0 ? '+' : '') . round($oi4, 1) . '% in 4h' : ($oi1 !== null ? ', open interest ' . ($oi1 >= 0 ? '+' : '') . round($oi1, 1) . '% in 1h' : ''))
                 . ($zone ? ', price at ' . strtolower(implode(' + ', $zone)) : '') . '. '
                 . [3 => ($put ? 'Price is falling and open interest is dropping: longs are being flushed out now.' : 'Price is rising and open interest is dropping: shorts are being squeezed now.'),
                    2 => 'Set-up is ready: watch for the first turn ' . ($put ? 'down' : 'up') . ' from this zone.',
                    1 => 'Crowding is building. Not at a key zone yet.', 0 => 'Crowded, but open interest is not building. Watch only.'][$st];
            $rows[] = ['symbol' => $sym, 'name' => t_name($sym), 'perp' => $pk, 'src' => $x['src'], 'class' => 'crypto', 'side' => $side, 'stage' => $st,
                'price' => round($x['px'], $d), 'dec' => $d, 'chg24' => round($x['chg'], 2), 'fr' => $fr, 'fr_pct' => round($fr * 100, 4), 'extreme' => $xtr,
                'oi_usd' => round($x['oi']), 'oi1' => $fmt($oi1), 'oi4' => $fmt($oi4), 'p1' => $fmt($p1), 'tv24' => round($x['tv']), 'zone' => $zone, 'say' => $why,
                'live' => (bool)($t1['live'] ?? true), 'key' => [$st, $zone ? 1 : 0, $xtr ? 1 : 0, abs($fr)]];
        }
        if ($snap) { $H['_t'] = $now; foreach ($H as $k => $v) if ($k !== '_t' && (!is_array($v) || !$v || $now - (int)end($v)[0] > 8 * 3600)) unset($H[$k]); t_write_json($hf, $H); }
        usort($rows, fn($a, $b) => $b['key'] <=> $a['key']);
        $cnt = [3 => 0, 2 => 0, 1 => 0, 0 => 0]; foreach ($rows as &$r) { $cnt[$r['stage']]++; unset($r['key']); } unset($r);
        $hrs = 0; foreach ($H as $k => $v) if ($k !== '_t' && is_array($v) && count($v) > 1) { $hrs = max($hrs, ($now - (int)$v[0][0]) / 3600); }
        $p = ['at' => $now, 'rows' => $rows, 'meta' => ['src' => reset($M)['src'] ?? '', 'perps' => count($M), 'crowded' => count($rows), 'counts' => $cnt, 'history_h' => round($hrs, 1)]];
        t_write_json($ck, $p);
        return $p;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
