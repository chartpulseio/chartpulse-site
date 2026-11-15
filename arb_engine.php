<?php
/**
 * Crypto Arbitrage Scanner (owner, 15 Nov 2026)                          chartpulse.io
 * Three views built from public exchange tickers (no keys, no orders):
 *   fund  : funding carry       -> short the perpetual + hold the coin (spot). You collect funding while it stays positive.
 *   cross : funding, exchange vs exchange -> long the perpetual where funding is lowest, short it where funding is highest.
 *   gaps  : price gap           -> the same coin is cheaper to buy on one exchange than it sells for on another.
 * Different from the Liquidation Squeeze Scanner: that one reads funding as CROWDING at a chart zone and gives a CALL / PUT.
 * This one gives no direction; both legs are hedged and the number shown is the yield or the gap.
 * What it cannot see: whether deposits / withdrawals are open, order-book depth, borrow cost. The page says so.
 */
declare(strict_types=1);
require_once __DIR__ . '/torus_engine.php';

const ARB_TTL = 45;
const ARB_FEE_GAP = 0.002;     // two taker trades at about 0.1% each
const ARB_FEE_CARRY = 0.003;   // open + close both legs (spot 0.1% x2, perp 0.05% x2)
const ARB_MIN_SPOT = 300000;   // $ 24h volume on both exchanges of a price gap
const ARB_MIN_PERP = 5000000;  // $ 24h turnover of a perpetual
const ARB_EX = ['bybit' => 'Bybit', 'binance' => 'Binance', 'okx' => 'OKX', 'kucoin' => 'KuCoin', 'gate' => 'Gate', 'mexc' => 'MEXC', 'bitget' => 'Bitget'];

/** "BTCUSDT" / "BTC-USDT" / "BTC_USDT" -> "BTC"; null for non-USDT pairs, stablecoins and leveraged tokens. */
function arb_base(string $sym): ?string {
    $s = strtoupper(str_replace(['-', '_', '/'], '', $sym));
    if (!str_ends_with($s, 'USDT')) return null;
    $b = substr($s, 0, -4);
    if ($b === '' || strlen($b) > 12 || !preg_match('/^[A-Z0-9]+$/', $b)) return null;
    if (in_array($b, ['USDC', 'FDUSD', 'DAI', 'TUSD', 'USDE', 'USDD', 'PYUSD', 'BUSD', 'USD1', 'EUR', 'EURI', 'USDP', 'AEUR', 'XUSD', 'USTC'], true)) return null;
    if (preg_match('/(\d[LS]|UP|DOWN|BULL|BEAR)$/', $b) && strlen($b) > 4) return null;
    return $b;
}
function arb_f($v): float { return is_numeric($v) ? (float)$v : 0.0; }

/** Fetch every exchange in parallel. Returns [spot[ex][BASE] = [bid, ask, vol], perp[ex][BASE] = [fr, int, next, px, idx, vol], ok[ex] = bool]. */
function arb_market(): array {
    $U = [
        'bybit_s' => 'https://api.bybit.com/v5/market/tickers?category=spot', 'bybit_p' => 'https://api.bybit.com/v5/market/tickers?category=linear',
        'binance_s' => 'https://data-api.binance.vision/api/v3/ticker/24hr', 'binance_p' => 'https://fapi.binance.com/fapi/v1/premiumIndex',
        'binance_v' => 'https://fapi.binance.com/fapi/v1/ticker/24hr', 'binance_i' => 'https://fapi.binance.com/fapi/v1/fundingInfo',
        'okx_s' => 'https://www.okx.com/api/v5/market/tickers?instType=SPOT',
        'kucoin_s' => 'https://api.kucoin.com/api/v1/market/allTickers',
        'gate_s' => 'https://api.gateio.ws/api/v4/spot/tickers', 'gate_p' => 'https://api.gateio.ws/api/v4/futures/usdt/tickers', 'gate_c' => 'https://api.gateio.ws/api/v4/futures/usdt/contracts',
        'mexc_s' => 'https://api.mexc.com/api/v3/ticker/24hr',
        'bitget_s' => 'https://api.bitget.com/api/v2/spot/market/tickers', 'bitget_p' => 'https://api.bitget.com/api/v2/mix/market/tickers?productType=USDT-FUTURES',
        'bitget_f' => 'https://api.bitget.com/api/v2/mix/market/current-fund-rate?productType=USDT-FUTURES',
    ];
    $R = t_fetch_multi($U, $codes); $J = [];
    foreach ($U as $k => $_) { $j = isset($R[$k]) && $R[$k] ? json_decode((string)$R[$k], true) : null; $J[$k] = is_array($j) ? $j : []; }
    $S = []; $P = [];
    $spot = function (string $ex, array $rows, string $ks, string $kb, string $ka, string $kv) use (&$S) {
        foreach ($rows as $x) { if (!is_array($x)) continue; $b = arb_base((string)($x[$ks] ?? '')); if (!$b) continue;
            $bid = arb_f($x[$kb] ?? 0); $ask = arb_f($x[$ka] ?? 0); if ($bid <= 0 || $ask <= 0 || $ask < $bid) continue;
            $S[$ex][$b] = [$bid, $ask, arb_f($x[$kv] ?? 0)]; }
    };
    $perp = function (string $ex, string $sym, float $fr, float $int, int $next, float $px, float $idx, float $vol) use (&$P) {
        $b = arb_base($sym); if (!$b || $px <= 0) return;
        $mult = 0; if (preg_match('/^(1000000|10000|1000)([A-Z]+)$/', $b, $m)) { $b = $m[2]; $mult = 1; } elseif (preg_match('/^([A-Z]+)1000$/', $b, $m)) { $b = $m[1]; $mult = 1; }
        $P[$ex][$b] = [$fr, $int > 0 ? $int : 8.0, $next, $px, $mult ? 0.0 : $idx, $vol, $int > 0 ? 1 : 0];
    };
    $spot('bybit', (array)($J['bybit_s']['result']['list'] ?? []), 'symbol', 'bid1Price', 'ask1Price', 'turnover24h');
    foreach ((array)($J['bybit_p']['result']['list'] ?? []) as $x) if (is_array($x) && ($x['fundingRate'] ?? '') !== '')
        $perp('bybit', (string)$x['symbol'], arb_f($x['fundingRate']), arb_f($x['fundingIntervalHour'] ?? 0), (int)(arb_f($x['nextFundingTime'] ?? 0) / 1000), arb_f($x['markPrice'] ?? $x['lastPrice'] ?? 0), arb_f($x['indexPrice'] ?? 0), arb_f($x['turnover24h'] ?? 0));
    $spot('binance', $J['binance_s'], 'symbol', 'bidPrice', 'askPrice', 'quoteVolume');
    $bv = []; foreach ($J['binance_v'] as $x) if (is_array($x)) $bv[(string)($x['symbol'] ?? '')] = arb_f($x['quoteVolume'] ?? 0);
    $bi = []; foreach ($J['binance_i'] as $x) if (is_array($x)) $bi[(string)($x['symbol'] ?? '')] = arb_f($x['fundingIntervalHours'] ?? 0);
    foreach ($J['binance_p'] as $x) if (is_array($x) && isset($x['symbol'], $x['lastFundingRate']) && $x['lastFundingRate'] !== '')
        $perp('binance', (string)$x['symbol'], arb_f($x['lastFundingRate']), $bi[$x['symbol']] ?? 8.0, (int)(arb_f($x['nextFundingTime'] ?? 0) / 1000), arb_f($x['markPrice'] ?? 0), arb_f($x['indexPrice'] ?? 0), $bv[$x['symbol']] ?? 0.0);
    $spot('okx', (array)($J['okx_s']['data'] ?? []), 'instId', 'bidPx', 'askPx', 'volCcy24h');
    $spot('kucoin', (array)($J['kucoin_s']['data']['ticker'] ?? []), 'symbol', 'buy', 'sell', 'volValue');
    $spot('gate', $J['gate_s'], 'currency_pair', 'highest_bid', 'lowest_ask', 'quote_volume');
    $gc = []; foreach ($J['gate_c'] as $x) if (is_array($x)) $gc[(string)($x['name'] ?? '')] = [arb_f($x['funding_interval'] ?? 0) / 3600, (int)arb_f($x['funding_next_apply'] ?? 0)];
    foreach ($J['gate_p'] as $x) if (is_array($x) && isset($x['contract'], $x['funding_rate'])) { $c = $gc[$x['contract']] ?? [0.0, 0];
        $perp('gate', (string)$x['contract'], arb_f($x['funding_rate']), $c[0], $c[1], arb_f($x['mark_price'] ?? $x['last'] ?? 0), arb_f($x['index_price'] ?? 0), arb_f($x['volume_24h_quote'] ?? $x['volume_24h_settle'] ?? 0)); }
    $spot('mexc', $J['mexc_s'], 'symbol', 'bidPrice', 'askPrice', 'quoteVolume');
    $spot('bitget', (array)($J['bitget_s']['data'] ?? []), 'symbol', 'bidPr', 'askPr', 'usdtVolume');
    $bg = []; foreach ((array)($J['bitget_f']['data'] ?? []) as $x) if (is_array($x) && isset($x['symbol'])) $bg[(string)$x['symbol']] = [arb_f($x['fundingRateInterval'] ?? 0), (int)(arb_f($x['nextUpdate'] ?? 0) / 1000)];
    foreach ((array)($J['bitget_p']['data'] ?? []) as $x) if (is_array($x) && isset($x['symbol'], $x['fundingRate']) && $x['fundingRate'] !== '')
        $perp('bitget', (string)$x['symbol'], arb_f($x['fundingRate']), $bg[$x['symbol']][0] ?? 0.0, $bg[$x['symbol']][1] ?? 0, arb_f($x['markPrice'] ?? $x['lastPr'] ?? 0), arb_f($x['indexPrice'] ?? 0), arb_f($x['usdtVolume'] ?? 0));
    $ok = []; foreach (ARB_EX as $ex => $_) $ok[$ex] = ['spot' => count($S[$ex] ?? []), 'perp' => count($P[$ex] ?? [])];
    return [$S, $P, $ok];
}

function arb_scan(): array {
    $now = t_now(); $ck = T_DATA . '/arb_scan1.json';
    $c = t_read_json($ck, null); if (is_array($c) && $now - (int)($c['at'] ?? 0) < ARB_TTL && !empty($c['meta'])) return $c;
    $lock = @fopen(T_DATA . '/.arb_lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return is_array($c) ? $c : ['at' => $now, 'fund' => [], 'cross' => [], 'gaps' => [], 'meta' => ['error' => 'busy']];
    try {
        [$S, $P, $ok] = arb_market();
        if (!$S && !$P) { if (is_array($c)) return $c; throw new RuntimeException('no exchange data'); }
        $r = fn(float $v, int $d = 4) => round($v, $d);

        // ---- funding: every perpetual as "daily %" so 1h / 4h / 8h funding can be compared
        $F = [];
        foreach ($P as $ex => $L) foreach ($L as $b => [$fr, $int, $next, $px, $idx, $vol, $known]) {
            if ($vol < ARB_MIN_PERP || abs($fr) > 0.03) continue;
            $F[$b][$ex] = ['fr' => $fr, 'int' => $int, 'known' => $known, 'next' => $next, 'day' => $fr * 24 / $int * 100, 'px' => $px, 'basis' => $idx > 0 ? ($px / $idx - 1) * 100 : null, 'vol' => $vol];
        }
        $fund = []; $cross = [];
        foreach ($F as $b => $L) {
            // An exchange that does not publish its funding period gets the period the other exchanges use for this coin (they usually match).
            $ki = []; foreach ($L as $q) if ($q['known']) $ki[] = $q['int'];
            if ($ki) { sort($ki); $bi2 = $ki[intdiv(count($ki), 2)]; foreach ($L as $ex => $q) if (!$q['known']) { $L[$ex]['int'] = $bi2; $L[$ex]['day'] = $q['fr'] * 24 / $bi2 * 100; } }
            $spotOn = []; foreach ($S as $ex => $M) if (isset($M[$b]) && $M[$b][2] >= ARB_MIN_SPOT) $spotOn[] = ARB_EX[$ex];
            uasort($L, fn($a, $z) => $z['day'] <=> $a['day']); $hi = array_key_first($L); $lo = array_key_last($L); $x = $L[$hi];
            if ($x['day'] * 365 >= 8 && $spotOn) {
                $fund[] = ['coin' => $b, 'ex' => ARB_EX[$hi], 'rate' => $r($x['fr'] * 100), 'int' => $x['int'], 'int_known' => $x['known'], 'day' => $r($x['day']), 'apr' => $r($x['day'] * 365, 1),
                    'basis' => $x['basis'] === null ? null : $r($x['basis'], 3), 'next' => $x['next'], 'vol' => round($x['vol']), 'spot' => $spotOn, 'same' => in_array(ARB_EX[$hi], $spotOn, true) ? 1 : 0,
                    'fee_days' => $x['day'] > 0 ? $r(ARB_FEE_CARRY * 100 / $x['day'], 1) : null];
            }
            if (count($L) >= 2 && $hi !== $lo) { $y = $L[$lo]; $d = $x['day'] - $y['day']; $pg = $y['px'] > 0 ? abs($x['px'] / $y['px'] - 1) : 1;
                // both periods must be published, both rates live (an exact 0 is a paused market), and the two contracts must price the same thing
                if ($d * 365 >= 10 && $x['known'] && $y['known'] && $x['fr'] != 0.0 && $y['fr'] != 0.0 && $pg <= 0.01) $cross[] = ['coin' => $b, 'short_ex' => ARB_EX[$hi], 'short_rate' => $r($x['fr'] * 100), 'short_int' => $x['int'], 'long_ex' => ARB_EX[$lo], 'long_rate' => $r($y['fr'] * 100), 'long_int' => $y['int'],
                    'day' => $r($d), 'apr' => $r($d * 365, 1), 'px_gap' => $y['px'] > 0 ? $r(($x['px'] / $y['px'] - 1) * 100, 3) : null, 'vol' => round(min($x['vol'], $y['vol'])), 'n' => count($L),
                    'fee_days' => $d > 0 ? $r(0.2 / $d, 1) : null]; }
        }
        usort($fund, fn($a, $z) => $z['apr'] <=> $a['apr']); usort($cross, fn($a, $z) => $z['apr'] <=> $a['apr']);

        // ---- price gap: cheapest ask vs highest bid on another exchange, same coin
        $G = []; $all = [];
        foreach ($S as $ex => $M) foreach ($M as $b => $q) if ($q[2] >= ARB_MIN_SPOT) $all[$b][$ex] = $q;
        foreach ($all as $b => $L) {
            if (count($L) < 3) continue;                                   // a ticker on fewer than three exchanges is too easy to confuse with another coin
            $mids = array_map(fn($q) => ($q[0] + $q[1]) / 2, $L); sort($mids); $med = $mids[intdiv(count($mids), 2)];
            $L = array_filter($L, fn($q) => abs((($q[0] + $q[1]) / 2) / $med - 1) <= 0.08 && ($q[1] / $q[0] - 1) <= 0.01);   // same ticker, different coin; or a very wide book
            if (count($L) < 2) continue;
            $buy = null; $sell = null;
            foreach ($L as $ex => $q) { if ($buy === null || $q[1] < $L[$buy][1]) $buy = $ex; if ($sell === null || $q[0] > $L[$sell][0]) $sell = $ex; }
            if ($buy === $sell) continue;
            $g = $L[$sell][0] / $L[$buy][1] - 1; if ($g < 0.002) continue;
            $G[] = ['coin' => $b, 'buy_ex' => ARB_EX[$buy], 'buy' => $L[$buy][1], 'sell_ex' => ARB_EX[$sell], 'sell' => $L[$sell][0], 'gap' => $r($g * 100, 3), 'net' => $r(($g - ARB_FEE_GAP) * 100, 3),
                'vol' => round(min($L[$buy][2], $L[$sell][2])), 'n' => count($L), 'big' => $g >= 0.02 ? 1 : 0];
        }
        usort($G, fn($a, $z) => $z['gap'] <=> $a['gap']);

        $out = ['at' => $now, 'fund' => array_slice($fund, 0, 120), 'cross' => array_slice($cross, 0, 120), 'gaps' => array_slice($G, 0, 120),
            'meta' => ['exchanges' => $ok, 'coins' => count($F), 'spot_coins' => count($all), 'fee_gap' => ARB_FEE_GAP * 100, 'fee_carry' => ARB_FEE_CARRY * 100,
                       'n' => ['fund' => count($fund), 'cross' => count($cross), 'gaps' => count($G)], 'bitget_periods' => count($bg)]];
        t_write_json($ck, $out); return $out;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
