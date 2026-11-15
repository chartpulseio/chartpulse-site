<?php
/**
 * Crypto Order Flow (owner, 15 Nov 2026)                                  chartpulse.io
 * Free order flow for crypto perpetuals, from public exchange data (no keys):
 *   - CVD / delta: Binance futures and spot klines carry the taker-buy volume of every candle, so
 *     delta = taker buy - taker sell, and CVD is its running sum. Spot and futures are shown apart.
 *   - Open interest history, long/short account ratios, top-trader ratio, taker buy/sell ratio: Binance futures/data.
 *   - Order book: Binance futures depth snapshot -> bids vs asks inside 0.5 / 1 / 2 / 5 % of price, and the biggest walls.
 *   - Liquidations: OKX public liquidation orders (Binance and Bybit publish liquidations only as a live stream).
 *   - Liquidation map: an ESTIMATE. Each rise in open interest is assumed to open positions at that candle's price
 *     with a spread of leverages; their liquidation prices are kept until price trades through them or open
 *     interest falls. The page says plainly that this is a model, as every liquidation heatmap is.
 */
declare(strict_types=1);
require_once __DIR__ . '/torus_engine.php';

const OF_FAPI = 'https://fapi.binance.com';
const OF_SPOT = 'https://data-api.binance.vision';
const OF_BOARD_N = 60, OF_BOARD_TTL = 90, OF_SYM_TTL = 45;
const OF_TFS = ['5m' => 300, '15m' => 900, '1h' => 3600, '4h' => 14400];
const OF_LEV = [[10, .30], [25, .30], [50, .25], [100, .15]];   // assumed leverage mix for the liquidation map

function of_dir(): string { $d = T_DATA . '/of'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function of_json(?string $s) { if ($s === null || $s === '') return null; $j = json_decode($s, true); return is_array($j) ? $j : null; }
function of_f($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function of_r(float $v, int $d = 2): float { return round($v, $d); }
function of_px(float $p): float { return round($p, $p >= 1000 ? 2 : ($p >= 10 ? 3 : ($p >= 1 ? 4 : ($p >= .01 ? 6 : 8)))); }
/** Cached builder: one rebuild at a time, stale copy served while another request rebuilds. */
function of_cached(string $key, int $ttl, callable $build): array {
    $f = of_dir() . "/$key.json"; $c = t_read_json($f, null); $now = t_now();
    if (is_array($c) && $now - (int)($c['at'] ?? 0) < $ttl) return $c;
    $lock = @fopen(of_dir() . "/.lock_$key", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if (is_array($c)) return $c; usleep(2500000); $c = t_read_json($f, null); return is_array($c) ? $c : ['error' => 'busy']; }
    try { $out = $build(); if (!isset($out['error'])) { $out['at'] = $now; t_write_json($f, $out); } elseif (is_array($c)) return $c; return $out; }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}
/** Delta of one kline row: [buy, sell, volume] in quote currency (USDT). */
function of_kd(array $k): array { $qv = of_f($k[7]); $tb = of_f($k[10]); return [$tb, max(0.0, $qv - $tb), $qv]; }

/* ---------------------------------------------------------------- market board */
function of_board(): array {
    return of_cached('board', OF_BOARD_TTL, function () {
        $R = t_fetch_multi(['t' => OF_FAPI . '/fapi/v1/ticker/24hr', 'p' => OF_FAPI . '/fapi/v1/premiumIndex', 'x' => OF_FAPI . '/fapi/v1/exchangeInfo']);
        $T = of_json($R['t'] ?? null); $P = of_json($R['p'] ?? null); $X = of_json($R['x'] ?? null);
        if (!$T) return ['error' => 'no ticker'];
        $ok = []; foreach ((array)($X['symbols'] ?? []) as $s) if (($s['contractType'] ?? '') === 'PERPETUAL' && ($s['quoteAsset'] ?? '') === 'USDT' && ($s['status'] ?? '') === 'TRADING') $ok[$s['symbol']] = 1;
        $fr = []; foreach ((array)$P as $p) $fr[$p['symbol'] ?? ''] = of_f($p['lastFundingRate'] ?? 0);
        $L = array_filter($T, fn($t) => isset($ok[$t['symbol'] ?? '']) || (!$ok && str_ends_with((string)($t['symbol'] ?? ''), 'USDT')));
        usort($L, fn($a, $b) => of_f($b['quoteVolume']) <=> of_f($a['quoteVolume']));
        $L = array_slice(array_values($L), 0, OF_BOARD_N);
        $U = [];
        foreach ($L as $t) { $s = $t['symbol'];
            $U["k$s"] = OF_FAPI . "/fapi/v1/klines?symbol=$s&interval=1h&limit=25";
            $U["o$s"] = OF_FAPI . "/futures/data/openInterestHist?symbol=$s&period=1h&limit=25"; }
        $D = t_fetch_multi($U);
        $rows = [];
        foreach ($L as $t) { $s = $t['symbol']; $K = of_json($D["k$s"] ?? null); $O = of_json($D["o$s"] ?? null);
            if (!$K || count($K) < 25) continue;
            array_pop($K);                                            // the candle still forming is left out
            $n = count($K); $px = of_f($t['lastPrice']);
            $sum = function (int $h) use ($K, $n) { $b = 0.0; $v = 0.0; for ($i = max(0, $n - $h); $i < $n; $i++) { [$x, , $q] = of_kd($K[$i]); $b += $x; $v += $q; } return [$b, $v]; };
            $chg = fn(int $h) => ($c0 = of_f($K[max(0, $n - $h)][1])) > 0 ? ($px / $c0 - 1) * 100 : 0.0;
            $d = []; $dv = []; foreach ([1, 4, 24] as $h) { [$b, $v] = $sum($h); $d[$h] = $v > 0 ? (2 * $b - $v) / $v * 100 : 0.0; $dv[$h] = 2 * $b - $v; }
            $oi = []; $oiNow = null;
            if ($O && count($O) >= 5) { $m = count($O); $oiNow = of_f($O[$m - 1]['sumOpenInterestValue']);
                foreach ([1, 4, 24] as $h) { $a = of_f($O[max(0, $m - 1 - $h)]['sumOpenInterestValue']); $oi[$h] = $a > 0 ? ($oiNow / $a - 1) * 100 : null; } }
            $p4 = $chg(4); $o4 = $oi[4] ?? null; $d4 = $d[4];
            $read = of_read($p4, $o4, $d4);
            $rows[] = ['s' => $s, 'coin' => preg_replace('/USDT$/', '', $s), 'px' => of_px($px), 'c1' => of_r($chg(1)), 'c4' => of_r($p4), 'c24' => of_r(of_f($t['priceChangePercent'])),
                'd1' => of_r($d[1], 1), 'd4' => of_r($d4, 1), 'd24' => of_r($d[24], 1), 'dv4' => round($dv[4]), 'dv24' => round($dv[24]),
                'oi' => $oiNow === null ? null : round($oiNow), 'oi1' => isset($oi[1]) ? of_r($oi[1]) : null, 'oi4' => $o4 === null ? null : of_r($o4), 'oi24' => isset($oi[24]) ? of_r($oi[24]) : null,
                'fr' => isset($fr[$s]) ? of_r($fr[$s] * 100, 4) : null, 'vol' => round(of_f($t['quoteVolume'])), 'read' => $read[0], 'tone' => $read[1], 'div' => $read[2]];
        }
        if (count($rows) < 10) return ['error' => 'too few rows'];
        return ['rows' => $rows, 'src' => 'Binance USDT perpetuals, 1-hour candles'];
    });
}
/** Plain reading of price + open interest + delta over the same window. [label, tone(+1 up / -1 down / 0), divergence]. */
function of_read(float $p, ?float $oi, float $d): array {
    $div = ($p > .4 && $d < -6) ? 'Price up on net selling' : (($p < -.4 && $d > 6) ? 'Price down on net buying' : '');
    if ($oi === null || abs($p) < .25) return [abs($d) >= 8 ? ($d > 0 ? 'Buyers in control, price flat' : 'Sellers in control, price flat') : 'Quiet', $d >= 8 ? 1 : ($d <= -8 ? -1 : 0), $div];
    if ($p > 0 && $oi > .5) return ['New longs pushing up', 1, $div];
    if ($p > 0 && $oi < -.5) return ['Short covering', 1, $div];
    if ($p < 0 && $oi > .5) return ['New shorts pushing down', -1, $div];
    if ($p < 0 && $oi < -.5) return ['Longs closing / liquidated', -1, $div];
    return [$p > 0 ? 'Drifting up' : 'Drifting down', $p > 0 ? 1 : -1, $div];
}

/* ---------------------------------------------------------------- one coin, deep view */
function of_symbol(string $s, string $tf): array {
    if (!preg_match('/^[A-Z0-9]{2,20}USDT$/', $s)) return ['error' => 'bad symbol'];
    if (!isset(OF_TFS[$tf])) $tf = '15m';
    return of_cached("s_{$s}_$tf", OF_SYM_TTL, function () use ($s, $tf) {
        $per = $tf === '4h' ? '4h' : ($tf === '1h' ? '1h' : ($tf === '5m' ? '5m' : '15m'));
        $base = preg_replace('/USDT$/', '', $s);
        $U = ['k' => OF_FAPI . "/fapi/v1/klines?symbol=$s&interval=$tf&limit=300", 'sk' => OF_SPOT . "/api/v3/klines?symbol=$s&interval=$tf&limit=300",
            'oi' => OF_FAPI . "/futures/data/openInterestHist?symbol=$s&period=$per&limit=300",
            'ga' => OF_FAPI . "/futures/data/globalLongShortAccountRatio?symbol=$s&period=$per&limit=150",
            'tp' => OF_FAPI . "/futures/data/topLongShortPositionRatio?symbol=$s&period=$per&limit=150",
            'tk' => OF_FAPI . "/futures/data/takerlongshortRatio?symbol=$s&period=$per&limit=150",
            'dp' => OF_FAPI . "/fapi/v1/depth?symbol=$s&limit=1000", 'pi' => OF_FAPI . "/fapi/v1/premiumIndex?symbol=$s",
            'lq' => "https://www.okx.com/api/v5/public/liquidation-orders?instType=SWAP&state=filled&instFamily=$base-USDT&limit=100",
            'ok' => "https://www.okx.com/api/v5/public/instruments?instType=SWAP&instFamily=$base-USDT"];
        $R = t_fetch_multi($U);
        $K = of_json($R['k'] ?? null); if (!$K || count($K) < 30) return ['error' => 'no candles'];
        $c = []; $cvd = 0.0; $last = count($K) - 1;
        foreach ($K as $i => $k) { [$b, $se, $q] = of_kd($k); $cvd += $b - $se;
            $c[] = [(int)($k[0] / 1000), of_px(of_f($k[1])), of_px(of_f($k[2])), of_px(of_f($k[3])), of_px(of_f($k[4])), round($q), round($b - $se), round($cvd), $i === $last ? 1 : 0]; }
        $SK = of_json($R['sk'] ?? null); $sc = [];
        if ($SK) { $cv = 0.0; foreach ($SK as $k) { [$b, $se] = of_kd($k); $cv += $b - $se; $sc[] = [(int)($k[0] / 1000), round($b - $se), round($cv)]; } }
        $O = of_json($R['oi'] ?? null); $oi = [];
        foreach ((array)$O as $o) if (isset($o['timestamp'])) $oi[] = [(int)($o['timestamp'] / 1000), round(of_f($o['sumOpenInterestValue'])), of_f($o['sumOpenInterest'])];
        $ratio = function ($J, string $k) { $o = []; foreach ((array)$J as $x) if (isset($x['timestamp'])) $o[] = [(int)($x['timestamp'] / 1000), of_r(of_f($x[$k]), 3)]; return $o; };
        $px = of_f($K[$last][4]); $PI = of_json($R['pi'] ?? null);
        $mark = of_f($PI['markPrice'] ?? 0) ?: $px;
        return ['s' => $s, 'coin' => $base, 'tf' => $tf, 'px' => of_px($mark), 'fr' => of_r(of_f($PI['lastFundingRate'] ?? 0) * 100, 4), 'next_fr' => (int)(of_f($PI['nextFundingTime'] ?? 0) / 1000),
            'c' => $c, 'spot' => $sc, 'oi' => $oi, 'ls' => ['acc' => $ratio(of_json($R['ga'] ?? null), 'longShortRatio'), 'top' => $ratio(of_json($R['tp'] ?? null), 'longShortRatio'), 'taker' => $ratio(of_json($R['tk'] ?? null), 'buySellRatio')],
            'book' => of_book(of_json($R['dp'] ?? null), $mark), 'liq' => of_liqs(of_json($R['lq'] ?? null), of_json($R['ok'] ?? null)), 'map' => of_map($K, $O ?: [], $mark)];
    });
}
/** Order book: depth inside bands around price, imbalance, and the biggest walls (USDT value). */
function of_book(?array $D, float $px): ?array {
    if (!$D || empty($D['bids']) || empty($D['asks']) || $px <= 0) return null;
    $band = []; $walls = ['bid' => [], 'ask' => []];
    foreach ([.1, .25, .5, 1] as $b) $band[(string)$b] = [0.0, 0.0];
    foreach (['bids' => 0, 'asks' => 1] as $side => $j) foreach ($D[$side] as $l) { $p = of_f($l[0]); $v = $p * of_f($l[1]); $dist = abs($p / $px - 1) * 100;
        foreach ($band as $b => $_) if ($dist <= (float)$b) $band[$b][$j] += $v;
        $walls[$j ? 'ask' : 'bid'][] = [of_px($p), round($v), of_r($dist, 2)]; }
    foreach ($walls as $k => $w) { usort($w, fn($a, $b) => $b[1] <=> $a[1]); $walls[$k] = array_slice($w, 0, 6); }
    $far = end($D['bids']); $farA = end($D['asks']); $reach = min(abs(of_f($far[0]) / $px - 1), abs(of_f($farA[0]) / $px - 1)) * 100;
    $out = []; foreach ($band as $b => [$bid, $ask]) if ((float)$b <= $reach + .001) $out[] = ['pct' => (float)$b, 'bid' => round($bid), 'ask' => round($ask), 'imb' => $bid + $ask > 0 ? of_r(($bid - $ask) / ($bid + $ask) * 100, 1) : 0.0];
    $tb = 0.0; $ta = 0.0; foreach ($D['bids'] as $l) $tb += of_f($l[0]) * of_f($l[1]); foreach ($D['asks'] as $l) $ta += of_f($l[0]) * of_f($l[1]);
    if (!$out || end($out)['pct'] < $reach - .01) $out[] = ['pct' => of_r($reach, 2), 'bid' => round($tb), 'ask' => round($ta), 'imb' => $tb + $ta > 0 ? of_r(($tb - $ta) / ($tb + $ta) * 100, 1) : 0.0, 'all' => 1];
    return ['bands' => $out, 'walls' => $walls, 'reach' => of_r($reach, 2)];
}
/** OKX filled liquidations: side of the position that was closed, size in USDT, time. */
function of_liqs(?array $J, ?array $I): ?array {
    if (!$J || ($J['code'] ?? '1') !== '0') return null;
    $ct = []; foreach ((array)($I['data'] ?? []) as $x) $ct[$x['instId'] ?? ''] = of_f($x['ctVal'] ?? 0);
    $L = []; $now = time(); $tot = ['long' => 0.0, 'short' => 0.0];
    foreach ((array)($J['data'] ?? []) as $g) { $cv = $ct[$g['instId'] ?? ''] ?? 0.0;
        foreach ((array)($g['details'] ?? []) as $d) { $p = of_f($d['bkPx'] ?? 0); $v = $cv > 0 ? of_f($d['sz'] ?? 0) * $cv * $p : 0.0; if ($v <= 0) continue;
            $long = ($d['posSide'] ?? '') === 'long' || (($d['posSide'] ?? '') === 'net' && ($d['side'] ?? '') === 'sell');
            $t = (int)(of_f($d['ts'] ?? 0) / 1000); if ($now - $t <= 86400) $tot[$long ? 'long' : 'short'] += $v;
            $L[] = ['t' => $t, 'side' => $long ? 'long' : 'short', 'px' => of_px($p), 'v' => round($v)]; } }
    usort($L, fn($a, $b) => $b['t'] <=> $a['t']);
    return ['src' => 'OKX', 'list' => array_slice($L, 0, 40), 'long' => round($tot['long']), 'short' => round($tot['short']), 'n' => count($L), 'since' => $L ? end($L)['t'] : null];
}
/** Estimated liquidation map from open-interest changes (see the note at the top). Grid rows = price buckets, cols = time. */
function of_map(array $K, array $O, float $px): ?array {
    if (count($O) < 20 || count($K) < 20) return null;
    $oi = []; foreach ($O as $o) $oi[(int)($o['timestamp'] / 1000)] = of_f($o['sumOpenInterestValue']);
    $C = []; foreach ($K as $k) { $t = (int)($k[0] / 1000); $C[] = ['t' => $t, 'h' => of_f($k[2]), 'l' => of_f($k[3]), 'p' => (of_f($k[2]) + of_f($k[3]) + of_f($k[4])) / 3, 'd' => of_kd($k)]; }
    $lo = $px * .82; $hi = $px * 1.18; $R = 90; $bw = ($hi - $lo) / $R; $n = count($C); $from = max(0, $n - 160);
    $lv = []; $prev = null; $grid = []; $ts = []; $mx = 0.0; $now = array_fill(0, $R, [0.0, 0.0]);
    foreach ($C as $i => $k) {
        $o = $oi[$k['t']] ?? null;
        if ($o !== null && $prev !== null) {
            $dO = $o - $prev;
            if ($dO > 0) { [$b, $se, $q] = $k['d']; $longShare = $q > 0 ? min(.8, max(.2, $b / $q)) : .5;
                foreach (OF_LEV as [$L, $w]) { $lv[] = [$k['p'] * (1 - 1 / $L), $dO * $longShare * $w, 1]; $lv[] = [$k['p'] * (1 + 1 / $L), $dO * (1 - $longShare) * $w, -1]; } }
            elseif ($dO < 0 && $prev > 0) { $f = max(0.0, 1 + $dO / $prev); foreach ($lv as &$x) $x[1] *= $f; unset($x); }
        }
        if ($o !== null) $prev = $o;
        foreach ($lv as $j => $x) if (($x[2] > 0 && $k['l'] <= $x[0]) || ($x[2] < 0 && $k['h'] >= $x[0])) unset($lv[$j]);   // traded through: liquidated
        if (count($lv) > 4000) { usort($lv, fn($a, $b) => $b[1] <=> $a[1]); $lv = array_slice($lv, 0, 3000); }
        if ($i < $from) continue;
        $row = array_fill(0, $R, 0.0);
        foreach ($lv as $x) { $r = (int)floor(($x[0] - $lo) / $bw); if ($r < 0 || $r >= $R) continue; $row[$r] += $x[1]; if ($i === $n - 1) $now[$r][$x[2] > 0 ? 0 : 1] += $x[1]; }
        foreach ($row as $v) if ($v > $mx) $mx = $v;
        $grid[] = $row; $ts[] = $k['t'];
    }
    if ($mx <= 0) return null;
    $g = array_map(fn($row) => array_map(fn($v) => (int)round($v / $mx * 255), $row), $grid);
    $near = ['above' => [], 'below' => []];
    foreach ($now as $r => [$lg, $sh]) { $mid = $lo + ($r + .5) * $bw; $v = $lg + $sh; if ($v <= 0) continue;
        $near[$mid > $px ? 'above' : 'below'][] = [of_px($mid), round($v), of_r(($mid / $px - 1) * 100, 2), $sh > $lg ? 'short' : 'long']; }
    foreach ($near as $k => $L) { usort($L, fn($a, $b) => $b[1] <=> $a[1]); $near[$k] = array_slice($L, 0, 5); }
    return ['lo' => of_px($lo), 'hi' => of_px($hi), 'rows' => $R, 't' => $ts, 'g' => $g, 'near' => $near];
}
