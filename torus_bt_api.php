<?php
/* ChartPulse Pro Backtesting - request validation, market data, caching, limits.
 * Server-only (blocked by .htaccess). The public entry point is backtest.php.
 */

require_once __DIR__ . '/torus_bt.php';

if (!defined('BT_API')) { define('BT_API', 1);

define('BT_MAX_BARS', 20000);   /* one backtest */
define('BT_MAX_COMBOS', 120);     /* walk-forward grid */
define('BT_MAX_WF_RUNS', 900);     /* combos x windows */
define('BT_WF_SECONDS', 45);      /* soft time budget for a walk-forward */
define('BT_CURVE_POINTS', 1500);
define('BT_TRADES_SHOWN', 500);

define('BT_BASE_TF', ['5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600,
                    '4h' => 14400, '1d' => 86400, '1w' => 604800]);

define('BT_SRC_OK', ['open', 'high', 'low', 'close', 'volume', 'hl2', 'hlc3', 'ohlc4']);

function bt_dir($sub = '')
{
    $p = __DIR__ . '/data/bt' . ($sub ? '/' . $sub : '');
    if (!is_dir($p)) @mkdir($p, 0775, true);
    return $p;
}

function bt_ip()
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $hops = array_map('trim', explode(',', $xff));
        $ip = end($hops);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* file-based limiter, same idea as the site's api.php */
function bt_rate($bucket, $limit, $window)
{
    $f = bt_dir('rl') . '/' . $bucket . '_' . md5(bt_ip()) . '.json';
    $now = time();
    $hits = [];
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) $hits = $j;
    }
    $hits = array_values(array_filter($hits, function ($t) use ($now, $window) { return $t > $now - $window; }));
    if (count($hits) >= $limit) {
        $retry = max(1, $window - ($now - $hits[0]));
        throw new BtLimit('Too many requests. Please wait a moment and try again.', $retry);
    }
    $hits[] = $now;
    @file_put_contents($f, json_encode($hits), LOCK_EX);
}

class BtLimit extends Exception
{
    public $retry;
    public function __construct($m, $retry) { parent::__construct($m); $this->retry = $retry; }
}

/* ------------------------------------------------------------- validation */

function bt_num($v, $name, $min, $max)
{
    if (!is_numeric($v)) throw new BtError("$name must be a number");
    $v = (float)$v;
    if ($v < $min || $v > $max) throw new BtError("$name must be between $min and $max");
    return $v;
}

function bt_int($v, $name, $min, $max)
{
    if (!is_numeric($v) || (int)$v != $v + 0) {
        if (!is_numeric($v)) throw new BtError("$name must be a whole number");
    }
    $v = (int)$v;
    if ($v < $min || $v > $max) throw new BtError("$name must be between $min and $max");
    return $v;
}

function bt_validate_operand($op)
{
    if (!is_array($op)) throw new BtError('A rule operand is not valid');
    $out = [];
    $hasInd = isset($op['ind']) && $op['ind'] !== '' && $op['ind'] !== null;
    $hasVal = isset($op['value']) && $op['value'] !== '' && $op['value'] !== null;
    if ($hasInd === $hasVal) throw new BtError("Give either an indicator or a number on each side of a rule, not both");
    if ($hasVal) {
        $out['value'] = bt_num($op['value'], 'number', -1e12, 1e12);
        if (!empty($op['tf'])) throw new BtError('A plain number cannot carry a timeframe');
        return $out;
    }
    $ind = (string)$op['ind'];
    if (!in_array($ind, BT_INDS, true)) throw new BtError("Unknown indicator: $ind");
    $out['ind'] = $ind;
    if (in_array($ind, BT_NEEDS_PERIOD, true)) {
        if (!isset($op['period'])) throw new BtError("'$ind' needs a period");
        $out['period'] = bt_int($op['period'], "$ind period", 1, 500);
    }
    if (isset($op['period2']) && $op['period2'] !== '' && $op['period2'] !== null) {
        $out['period2'] = bt_int($op['period2'], "$ind second period", 1, 500);
    }
    if (isset($op['period3']) && $op['period3'] !== '' && $op['period3'] !== null) {
        $out['period3'] = bt_int($op['period3'], "$ind third period", 1, 500);
    }
    if (isset($op['mult']) && $op['mult'] !== '' && $op['mult'] !== null) {
        $out['mult'] = bt_num($op['mult'], "$ind multiplier", 0.1, 20);
    }
    if (isset($op['back']) && $op['back'] !== '' && $op['back'] !== null) {
        $out['back'] = bt_int($op['back'], 'bars back', 0, 200);
    }
    if (isset($op['offset']) && $op['offset'] !== '' && $op['offset'] !== null) {
        $out['offset'] = bt_num($op['offset'], 'offset', 0.1, 10);
    }
    if (isset($op['src']) && $op['src'] !== '' && $op['src'] !== null) {
        if (!in_array($op['src'], BT_SRC_OK, true)) throw new BtError('That price source is not valid');
        $out['src'] = (string)$op['src'];
    }
    if (isset($op['tf']) && $op['tf'] !== '' && $op['tf'] !== null) {
        if (!isset(BT_TF_SEC[$op['tf']])) throw new BtError('That higher timeframe is not valid');
        $out['tf'] = (string)$op['tf'];
    }
    if ($ind === 'macd' || $ind === 'macd_signal' || $ind === 'macd_hist') {
        $f = $out['period']; $s = $out['period2'] ?? 26;
        if ($f >= $s) throw new BtError('MACD fast period must be smaller than the slow period');
    }
    return $out;
}

function bt_validate_rules($list, $label)
{
    if (!is_array($list)) return [];
    if (count($list) > 10) throw new BtError("$label can hold at most 10 rules");
    $out = [];
    foreach ($list as $r) {
        if (!is_array($r) || !isset($r['op'])) throw new BtError("A $label rule is incomplete");
        if (!in_array($r['op'], ['>', '<', '>=', '<=', 'crosses_above', 'crosses_below'], true)) {
            throw new BtError('Unknown operator: ' . $r['op']);
        }
        $out[] = ['left' => bt_validate_operand($r['left'] ?? []), 'op' => $r['op'],
                  'right' => bt_validate_operand($r['right'] ?? [])];
    }
    return $out;
}

function bt_validate_strategy($s)
{
    if (!is_array($s)) throw new BtError('The strategy is not valid');
    $r = $s['rules'] ?? [];
    $rules = [
        'entry_long'  => bt_validate_rules($r['entry_long']  ?? [], 'Long entry'),
        'exit_long'   => bt_validate_rules($r['exit_long']   ?? [], 'Long exit'),
        'entry_short' => bt_validate_rules($r['entry_short'] ?? [], 'Short entry'),
        'exit_short'  => bt_validate_rules($r['exit_short']  ?? [], 'Short exit'),
    ];
    if (!$rules['entry_long'] && !$rules['entry_short']) {
        throw new BtError('At least one entry rule is needed');
    }
    $ri = $s['risk'] ?? [];
    $risk = [];
    $lim = [
        'stop_loss_pct'   => [0.01, 100],  'take_profit_pct' => [0.01, 1000],
        'stop_loss_atr'   => [0.1, 20],    'take_profit_atr' => [0.1, 50],
        'trail_pct'       => [0.05, 100],  'trail_atr'       => [0.1, 20],
        'risk_pct'        => [0.1, 100],
    ];
    foreach ($lim as $k => $mm) {
        if (isset($ri[$k]) && $ri[$k] !== '' && $ri[$k] !== null) {
            $risk[$k] = bt_num($ri[$k], $k, $mm[0], $mm[1]);
        }
    }
    if (isset($ri['max_bars']) && $ri['max_bars'] !== '' && $ri['max_bars'] !== null) {
        $risk['max_bars'] = bt_int($ri['max_bars'], 'max bars', 1, 5000);
    }
    if (isset($ri['atr_period']) && $ri['atr_period'] !== '' && $ri['atr_period'] !== null) {
        $risk['atr_period'] = bt_int($ri['atr_period'], 'ATR period', 2, 200);
    }
    if (!$rules['exit_long'] && $rules['entry_long'] && !$risk) {
        /* no exit at all: the trade would only close at the end of the data */
        $risk['_warn_no_exit'] = 1;
        unset($risk['_warn_no_exit']);
    }
    return ['rules' => $rules, 'risk' => $risk];
}

function bt_check_tfs($strategy, $timeframe)
{
    $base = BT_BASE_TF[$timeframe];
    foreach ($strategy['rules'] as $list) {
        foreach ($list as $rule) {
            foreach (['left', 'right'] as $side) {
                $tf = $rule[$side]['tf'] ?? null;
                if ($tf && BT_TF_SEC[$tf] < $base) {
                    throw new BtError("Higher timeframe '$tf' cannot be smaller than the chart timeframe '$timeframe'");
                }
            }
        }
    }
}

function bt_validate_settings($q)
{
    $sym = strtoupper(trim((string)($q['symbol'] ?? '')));
    if (!preg_match('/^[A-Z0-9][A-Z0-9.\-=^]{0,19}$/', $sym)) throw new BtError('That symbol code is not valid');
    $tf = (string)($q['timeframe'] ?? '1d');
    if (!isset(BT_BASE_TF[$tf])) throw new BtError('That timeframe is not valid');
    $start = (string)($q['start'] ?? '');
    $end   = (string)($q['end'] ?? '');
    foreach ([$start, $end] as $dstr) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dstr)) throw new BtError('Dates must be in YYYY-MM-DD format');
    }
    $s = strtotime($start . ' 00:00:00 UTC');
    $e = strtotime($end . ' 23:59:59 UTC');
    if ($s === false || $e === false || $s >= $e) throw new BtError('The start date must come before the end date');
    if ($s < strtotime('2000-01-01 UTC')) throw new BtError('The start date must be after the year 2000');
    return [
        'symbol' => $sym, 'timeframe' => $tf, 'start' => $s, 'end' => $e,
        'capital'    => bt_num($q['capital']    ?? 10000, 'Capital', 1, 1e9),
        'commission' => bt_num($q['commission'] ?? 0.001, 'Commission', 0, 0.05),
        'slippage'   => bt_num($q['slippage']   ?? 0.0005, 'Slippage', 0, 0.05),
        'size'       => bt_num($q['size']       ?? 1.0, 'Position size', 0.01, 1),
    ];
}

/* ------------------------------------------------------------ market data */

function bt_http($url, $timeout = 20)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ChartPulse/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new BtData('The market data service did not respond');
    return [$code, $body, $err];
}

class BtData extends Exception {}

function bt_is_crypto($sym)
{
    return (bool)preg_match('/^[A-Z0-9]{2,10}-USD$/', $sym) || (bool)preg_match('/^[A-Z0-9]{4,12}USDT$/', $sym);
}

function bt_binance_pair($sym)
{
    if (preg_match('/^([A-Z0-9]{2,10})-USD$/', $sym, $m)) return $m[1] . 'USDT';
    return $sym;
}

/* Binance klines - full history, every timeframe */
function bt_fetch_binance($pair, $tf, $startMs, $endMs)
{
    $iv = ['5m' => '5m', '15m' => '15m', '30m' => '30m', '1h' => '1h', '4h' => '4h', '1d' => '1d', '1w' => '1w'][$tf];
    $d = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    $cur = $startMs; $nowMs = time() * 1000; $guard = 0;
    while ($cur < $endMs && $guard++ < 60) {
        $url = 'https://api.binance.com/api/v3/klines?symbol=' . urlencode($pair) . '&interval=' . $iv
             . '&startTime=' . $cur . '&endTime=' . $endMs . '&limit=1000';
        [$code, $body] = bt_http($url);
        if ($code === 400) throw new BtData('crypto-not-found');
        if ($code === 451 || $code === 403) throw new BtData('crypto-blocked');
        if ($code !== 200) throw new BtData('crypto-error');
        $rows = json_decode($body, true);
        if (!is_array($rows) || !$rows) break;
        foreach ($rows as $r) {
            if ((int)$r[6] >= $nowMs) continue;          /* drop the candle still running */
            $d['t'][] = intdiv((int)$r[0], 1000);
            $d['o'][] = (float)$r[1]; $d['h'][] = (float)$r[2];
            $d['l'][] = (float)$r[3]; $d['c'][] = (float)$r[4]; $d['v'][] = (float)$r[5];
        }
        $last = end($rows);
        $cur = (int)$last[6] + 1;
        if (count($rows) < 1000) break;
        if (count($d['t']) > BT_MAX_BARS * 2) break;
    }
    return $d;
}

/* Yahoo chart - stocks, indices, forex, commodities, India, crypto fallback */
function bt_fetch_yahoo($sym, $iv, $p1, $p2)
{
    $hosts = ['query1.finance.yahoo.com', 'query2.finance.yahoo.com'];
    $last = '';
    foreach ($hosts as $hst) {
        $url = "https://$hst/v8/finance/chart/" . rawurlencode($sym)
             . "?period1=$p1&period2=$p2&interval=$iv&includePrePost=false&events=div%2Csplit";
        [$code, $body] = bt_http($url);
        if ($code === 404) throw new BtData('not-found');
        if ($code !== 200) { $last = "http $code"; continue; }
        $j = json_decode($body, true);
        $res = $j['chart']['result'][0] ?? null;
        if (!$res) {
            $err = $j['chart']['error']['code'] ?? '';
            if ($err === 'Not Found') throw new BtData('not-found');
            $last = 'empty'; continue;
        }
        $ts = $res['timestamp'] ?? [];
        $q = $res['indicators']['quote'][0] ?? [];
        if (!$ts || !isset($q['close'])) return ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
        $d = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
        $n = count($ts);
        for ($i = 0; $i < $n; $i++) {
            $o = $q['open'][$i] ?? null; $h = $q['high'][$i] ?? null;
            $l = $q['low'][$i] ?? null;  $c = $q['close'][$i] ?? null;
            if ($o === null || $h === null || $l === null || $c === null) continue;
            if ($o <= 0 || $h <= 0 || $l <= 0 || $c <= 0) continue;
            $d['t'][] = (int)$ts[$i];
            $d['o'][] = (float)$o; $d['h'][] = (float)$h;
            $d['l'][] = (float)$l; $d['c'][] = (float)$c;
            $d['v'][] = (float)($q['volume'][$i] ?? 0);
        }
        return $d;
    }
    throw new BtData('The market data service is not responding right now (' . $last . ')');
}

/* group base bars into a bigger timeframe (4h from 1h, 1w from 1d) */
function bt_resample($d, $step)
{
    $out = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    $cur = null; $j = -1; $n = count($d['c']);
    for ($i = 0; $i < $n; $i++) {
        $bk = bt_bucket($d['t'][$i], $step);
        if ($bk !== $cur) {
            $cur = $bk; $j++;
            $out['t'][$j] = $bk; $out['o'][$j] = $d['o'][$i];
            $out['h'][$j] = $d['h'][$i]; $out['l'][$j] = $d['l'][$i]; $out['v'][$j] = 0.0;
        }
        $out['h'][$j] = max($out['h'][$j], $d['h'][$i]);
        $out['l'][$j] = min($out['l'][$j], $d['l'][$i]);
        $out['c'][$j] = $d['c'][$i];
        $out['v'][$j] += $d['v'][$i] ?? 0.0;
    }
    return $out;
}

function bt_cache_get($key, $ttl)
{
    $f = bt_dir('cache') . '/' . $key . '.gz';
    if (!is_file($f) || time() - filemtime($f) > $ttl) return null;
    $raw = @file_get_contents($f);
    if ($raw === false) return null;
    $j = @gzdecode($raw);
    if ($j === false) return null;
    $d = json_decode($j, true);
    return is_array($d) && isset($d['c']) ? $d : null;
}

function bt_cache_put($key, $d)
{
    $dir = bt_dir('cache');
    @file_put_contents($dir . '/' . $key . '.gz', gzencode(json_encode($d), 6), LOCK_EX);
    $files = glob($dir . '/*.gz');
    if ($files && count($files) > 160) {
        usort($files, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
        foreach (array_slice($files, 0, count($files) - 140) as $old) @unlink($old);
    }
}

/* Loads bars for the symbol/timeframe, cached, and returns them trimmed to the
 * requested window plus a warm-up head so indicators are ready at the start. */
function bt_load($st, $warmup = 400)
{
    $sym = $st['symbol']; $tf = $st['timeframe'];
    $step = BT_BASE_TF[$tf];
    $intraday = $step < 86400;
    $ttl = $intraday ? 900 : 21600;
    $provider = null; $d = null;

    $key = 'v1_' . md5($sym . '|' . $tf);
    $cached = bt_cache_get($key, $ttl);
    if ($cached) { $d = $cached; $provider = $d['src'] ?? 'cache'; }

    if ($d === null) {
        $now = time();
        if (bt_is_crypto($sym)) {
            try {
                $from = $intraday ? $now - ($step * BT_MAX_BARS * 2) : strtotime('2015-01-01 UTC');
                $from = max($from, strtotime('2015-01-01 UTC'));
                $d = bt_fetch_binance(bt_binance_pair($sym), $tf, $from * 1000, $now * 1000);
                $provider = 'binance';
                if (count($d['c']) < 60) { $d = null; }
            } catch (BtData $e) { $d = null; }
        }
        if ($d === null) {
            /* Yahoo range limits: 5/15/30m ~60d, 60m ~730d, 1d full */
            $map = ['5m' => ['5m', 59], '15m' => ['15m', 59], '30m' => ['30m', 59],
                    '1h' => ['60m', 729], '4h' => ['60m', 729], '1d' => ['1d', 0], '1w' => ['1d', 0]];
            [$iv, $days] = $map[$tf];
            $p2 = time();
            $p1 = $days ? $p2 - $days * 86400 : strtotime('1990-01-01 UTC');
            $d = bt_fetch_yahoo($sym, $iv, $p1, $p2);
            $provider = 'yahoo';
            if ($tf === '4h' || $tf === '1w') $d = bt_resample($d, $step);
        }
        if (!$d || count($d['c']) < 60) {
            throw new BtData('Not enough history for this symbol. Try another symbol or a larger timeframe.');
        }
        /* de-duplicate and sort */
        $idx = array_keys($d['t']);
        usort($idx, function ($a, $b) use ($d) { return $d['t'][$a] <=> $d['t'][$b]; });
        $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $prev = null;
        foreach ($idx as $i) {
            if ($prev !== null && $d['t'][$i] === $prev) continue;
            $prev = $d['t'][$i];
            foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $k) $o[$k][] = $d[$k][$i] ?? 0.0;
        }
        $d = $o; $d['src'] = $provider;
        bt_cache_put($key, $d);
    }

    /* window + warm-up head */
    $n = count($d['c']);
    $first = null; $lastI = null;
    for ($i = 0; $i < $n; $i++) { if ($d['t'][$i] >= $st['start']) { $first = $i; break; } }
    for ($i = $n - 1; $i >= 0; $i--) { if ($d['t'][$i] <= $st['end']) { $lastI = $i; break; } }
    if ($first === null || $lastI === null || $lastI <= $first) {
        throw new BtData('No data in this date range (available: '
            . gmdate('d M Y', $d['t'][0]) . ' se ' . gmdate('d M Y', $d['t'][$n - 1]) . '). Range badlein.');
    }

    $head = max(0, $first - $warmup);
    $tradeFrom = $first - $head;
    $slice = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    for ($i = $head; $i <= $lastI; $i++) {
        foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $k) $slice[$k][] = $d[$k][$i];
    }
    $bars = count($slice['c']);
    if ($bars > BT_MAX_BARS) {
        throw new BtError('This range comes to ' . $bars . ' bars, the limit is ' . BT_MAX_BARS
            . '. Shorten the date range or pick a larger timeframe.');
    }
    if ($bars - $tradeFrom < 30) {
        throw new BtData('This date range holds only a handful of bars. Widen the range.');
    }
    bt_validate_ohlc($slice);
    return [$slice, $tradeFrom, $provider, $d['src'] ?? $provider];
}

function bt_validate_ohlc($d)
{
    $n = count($d['c']);
    if ($n < 50) throw new BtData('At least 50 bars are needed');
    for ($i = 0; $i < $n; $i++) {
        $o = $d['o'][$i]; $h = $d['h'][$i]; $l = $d['l'][$i]; $c = $d['c'][$i];
        if (!is_finite($o) || !is_finite($h) || !is_finite($l) || !is_finite($c)) {
            throw new BtData('Some candles in this data are malformed');
        }
        if ($o <= 0 || $h <= 0 || $l <= 0 || $c <= 0) throw new BtData('Some candles in this data are malformed');
        if ($h < max($o, $c) - 1e-9 || $l > min($o, $c) + 1e-9) {
            throw new BtData('Some candles in this data are malformed');
        }
        if ($i && $d['t'][$i] <= $d['t'][$i - 1]) throw new BtData('The time order of this data is broken');
    }
}

/* --------------------------------------------------------------- handlers */

function bt_sample_curve($curve, $times, $bh, $from, $max = BT_CURVE_POINTS)
{
    $n = count($curve);
    $m = $n - $from;
    $stepN = max(1, (int)ceil($m / $max));
    $out = [];
    for ($i = $from; $i < $n; $i += $stepN) {
        $out[] = ['t' => $times[$i], 'v' => round($curve[$i], 2), 'bh' => round($bh[$i], 2)];
    }
    $lastT = $times[$n - 1];
    if (!$out || $out[count($out) - 1]['t'] !== $lastT) {
        $out[] = ['t' => $lastT, 'v' => round($curve[$n - 1], 2), 'bh' => round($bh[$n - 1], 2)];
    }
    return $out;
}

function bt_handle_backtest($q)
{
    bt_rate('bt', 30, 300);
    $st = bt_validate_settings($q);
    $strategy = bt_validate_strategy($q['strategy'] ?? []);
    bt_check_tfs($strategy, $st['timeframe']);
    [$d, $from, $provider] = bt_load($st);

    [$curve, $trades] = bt_run($d, $strategy, $st['capital'], $st['commission'],
                               $st['slippage'], $st['size'], max($from, 1));
    $n = count($d['c']);
    $base = $d['c'][$from];
    $bh = [];
    for ($i = 0; $i < $n; $i++) $bh[$i] = $st['capital'] * $d['c'][$i] / $base;

    $m = bt_metrics($curve, $trades, $d['t'], $d['c'], $from);
    $shown = [];
    foreach (array_slice($trades, 0, BT_TRADES_SHOWN) as $t) {
        $shown[] = [
            'entry_time' => gmdate('Y-m-d H:i', $t['entry_time']),
            'exit_time'  => gmdate('Y-m-d H:i', $t['exit_time']),
            'side' => $t['side'],
            'entry' => bt_r($t['entry']), 'exit' => bt_r($t['exit']),
            'pnl' => round($t['pnl'], 2), 'ret_pct' => round($t['ret'] * 100, 3),
            'bars' => $t['bars'], 'reason' => $t['reason'],
            'mfe_pct' => round($t['mfe_pct'], 2), 'mae_pct' => round($t['mae_pct'], 2),
        ];
    }
    return [
        'symbol' => $st['symbol'], 'timeframe' => $st['timeframe'], 'source' => $provider,
        'bars' => $n - $from,
        'from' => gmdate('Y-m-d', $d['t'][$from]), 'to' => gmdate('Y-m-d', $d['t'][$n - 1]),
        'metrics' => $m,
        'equity' => bt_sample_curve($curve, $d['t'], $bh, $from),
        'trades' => $shown,
        'trades_total' => count($trades),
        'monte_carlo' => count($trades) >= 10 ? bt_monte_carlo($trades, $st['capital']) : null,
        'notes' => bt_notes($m, $trades, $strategy),
    ];
}

function bt_r($v)
{
    $a = abs($v);
    $dp = $a >= 1000 ? 2 : ($a >= 1 ? 4 : ($a >= 0.01 ? 6 : 8));
    return round($v, $dp);
}

/* plain-language warnings - the honest part, so nobody reads a fluke as an edge */
function bt_notes($m, $trades, $strategy)
{
    $out = [];
    $n = count($trades);
    if ($n === 0) {
        $out[] = ['warn', 'No trade was taken at all. The rules are too strict, or an indicator period is longer than the data.'];
        return $out;
    }
    if ($n < 30) $out[] = ['warn', "Only $n trades - on so few trades the result can be luck. Trust a read with 100+ trades."];
    if (($m['exposure_pct'] ?? 0) > 95) $out[] = ['info', 'The strategy was in the market almost all the time - this is close to buy & hold.'];
    if (($m['max_drawdown_pct'] ?? 0) < -50) $out[] = ['warn', 'Drawdown is over 50% - sitting through that on a real account is very hard.'];
    $end = 0; $liq = 0;
    foreach ($trades as $t) { if ($t['reason'] === 'end') $end++; if ($t['reason'] === 'liquidated') $liq++; }
    if ($liq) $out[] = ['warn', 'The account hit zero - trading was stopped from that point.'];
    if ($end && $n <= 3) $out[] = ['info', 'The trade closed at the end of the data (no exit rule fired).'];
    if (empty($strategy['risk'])) $out[] = ['info', 'No stop-loss was set - there was no limit on a loss.'];
    if (($m['buy_hold_return_pct'] ?? null) !== null && ($m['total_return_pct'] ?? 0) < $m['buy_hold_return_pct']) {
        $out[] = ['info', 'Buy & hold beat this strategy - the do-nothing benchmark did better.'];
    }
    if (($m['profit_factor'] ?? null) !== null && $m['profit_factor'] > 3 && $n < 50) {
        $out[] = ['warn', 'Profit factor is very high on few trades - this smells of curve fitting. Run a walk-forward.'];
    }
    return $out;
}

function bt_handle_walkforward($q)
{
    bt_rate('btwf', 6, 600);
    $st = bt_validate_settings($q);
    $grid = $q['grid'] ?? [];
    if (!is_array($grid) || !$grid) throw new BtError('The grid is empty - add at least one parameter');
    if (count($grid) > 4) throw new BtError('A grid can hold at most 4 parameters');
    $clean = [];
    $combos = 1;
    foreach ($grid as $k => $vals) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,20}$/', (string)$k)) throw new BtError('That grid parameter name is not valid');
        if (!is_array($vals) || count($vals) < 1 || count($vals) > 20) {
            throw new BtError('Each parameter can have 1 to 20 values');
        }
        $v = [];
        foreach ($vals as $x) {
            if (!is_numeric($x)) throw new BtError('Grid values must be numbers');
            $v[] = (float)$x == (int)$x ? (int)$x : (float)$x;
        }
        $clean[$k] = array_values(array_unique($v, SORT_REGULAR));
        $combos *= count($clean[$k]);
    }
    if ($combos > BT_MAX_COMBOS) throw new BtError("This grid has $combos combinations, the limit is " . BT_MAX_COMBOS . '.');

    $tpl = $q['strategy'] ?? [];
    if (!is_array($tpl)) throw new BtError('The strategy is not valid');
    $trainBars = bt_int($q['train_bars'] ?? 250, 'Train bars', 60, 20000);
    $testBars  = bt_int($q['test_bars'] ?? 60, 'Test bars', 20, 10000);

    [$d, $from, $provider] = bt_load($st);
    $len = count($d['c']) - $from;
    if ($len < $trainBars + $testBars) {
        throw new BtError("The data ($len bars) is smaller than train + test bars. Widen the range or lower the bars.");
    }
    $windows = (int)floor(($len - $trainBars) / $testBars);
    if ($windows * $combos > BT_MAX_WF_RUNS) {
        throw new BtError('That is too heavy (' . ($windows * $combos) . ' backtests). Use a smaller grid or larger test bars.');
    }

    /* the walk-forward always starts at the first tradable bar */
    $dd = $d;
    if ($from > 0) {
        foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $k) $dd[$k] = array_slice($d[$k], $from);
    }
    $probe = bt_validate_strategy(bt_fill_params($tpl, bt_first_combo($clean)));
    bt_check_tfs($probe, $st['timeframe']);

    $res = bt_walk_forward($dd, $tpl, $clean, $trainBars, $testBars,
        in_array($q['score'] ?? 'sharpe', ['sharpe', 'sortino', 'total_return_pct', 'calmar'], true)
            ? $q['score'] : 'sharpe',
        bt_int($q['min_trades'] ?? 5, 'Min trades', 1, 1000),
        ['capital' => $st['capital'], 'commission' => $st['commission'],
         'slippage' => $st['slippage'], 'size' => $st['size']],
        microtime(true) + BT_WF_SECONDS);

    foreach ($res['windows'] as &$w) $w['train_end'] = gmdate('Y-m-d', $w['train_end']);
    unset($w);
    $res['symbol'] = $st['symbol'];
    $res['timeframe'] = $st['timeframe'];
    $res['source'] = $provider;
    $res['combinations'] = $combos;
    return $res;
}

function bt_first_combo($grid)
{
    $o = [];
    foreach ($grid as $k => $v) $o[$k] = $v[0];
    return $o;
}

}
