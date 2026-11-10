<?php
// ---------------------------------------------------------------------------
// Upstox adapter (NSE / BSE indices / MCX commodity futures).
// Used only when an access token is set in config.php; otherwise Yahoo serves India.
// The token never leaves the server.
// ---------------------------------------------------------------------------
declare(strict_types=1);

const T_UP_API = 'https://api.upstox.com';
const T_UP_ASSETS = 'https://assets.upstox.com/market-quote/instruments/exchange/';
/** MCX commodities scanned when Upstox is configured: our symbol => [underlying, display name] */
const T_MCX = [
    'MCX:GOLD' => ['GOLD', 'Gold (MCX)'], 'MCX:GOLDM' => ['GOLDM', 'Gold Mini (MCX)'], 'MCX:SILVER' => ['SILVER', 'Silver (MCX)'],
    'MCX:SILVERM' => ['SILVERM', 'Silver Mini (MCX)'], 'MCX:CRUDEOIL' => ['CRUDEOIL', 'Crude Oil (MCX)'], 'MCX:CRUDEOILM' => ['CRUDEOILM', 'Crude Oil Mini (MCX)'],
    'MCX:NATURALGAS' => ['NATURALGAS', 'Natural Gas (MCX)'], 'MCX:NATGASMINI' => ['NATGASMINI', 'Nat Gas Mini (MCX)'], 'MCX:COPPER' => ['COPPER', 'Copper (MCX)'],
    'MCX:ZINC' => ['ZINC', 'Zinc (MCX)'], 'MCX:ALUMINIUM' => ['ALUMINIUM', 'Aluminium (MCX)'], 'MCX:LEAD' => ['LEAD', 'Lead (MCX)'],
];
/** Yahoo index symbols -> Upstox index names (matched case-insensitively against the instrument file) */
const T_UP_INDEX = [
    '^NSEI' => ['Nifty 50'], '^NSEBANK' => ['Nifty Bank'], 'NIFTY_FIN_SERVICE.NS' => ['Nifty Fin Service', 'Nifty Financial Services'],
    'NIFTY_MID_SELECT.NS' => ['NIFTY MID SELECT', 'Nifty Midcap Select'], '^CNXIT' => ['Nifty IT'], '^CNXAUTO' => ['Nifty Auto'],
    '^CNXPHARMA' => ['Nifty Pharma'], '^CNXFMCG' => ['Nifty FMCG'], '^CNXMETAL' => ['Nifty Metal'], '^CNXREALTY' => ['Nifty Realty'],
    '^CNXENERGY' => ['Nifty Energy'], '^CNXPSUBANK' => ['Nifty PSU Bank'],
];

function t_cfg(string $k, $def = null) {
    static $c = null;
    if ($c === null) {
        // The web server gets the config path from .htaccess (SetEnv TORUS_CONFIG). The cron job runs from the
        // command line, where that variable does not exist, so it fell back to a config.php that is not there and
        // ran with NO settings: no Alpaca (it scanned those symbols through Yahoo while the website asked for
        // Alpaca), no Telegram, no admin password. Same file for both now (10 Nov 2026).
        $p = getenv('TORUS_CONFIG') ?: '';
        if ($p === '' || !is_file($p)) { $alt = dirname(__DIR__) . '/cp_secrets/cp-config.php'; $p = @is_file($alt) ? $alt : __DIR__ . '/config.php'; }
        $c = is_file($p) ? (array)(include $p) : [];
    }
    return $c[$k] ?? $def;
}
function t_up_token(): string { return trim((string)t_cfg('upstox_token', 'eyJ0eXAiOiJKV1QiLCJrZXlfaWQiOiJza192MS4wIiwiYWxnIjoiSFMyNTYifQ.eyJzdWIiOiI3NEI4NjQiLCJqdGkiOiI2YWFkNDhhNjE0Yjg3NTA1NWQyY2VkNmEiLCJpc011bHRpQ2xpZW50IjpmYWxzZSwiaXNQbHVzUGxhbiI6dHJ1ZSwiaXNFeHRlbmRlZCI6dHJ1ZSwiaWF0IjoxNzg5NzQxMjIyLCJpc3MiOiJ1ZGFwaS1nYXRld2F5LXNlcnZpY2UiLCJleHAiOjE4MjEzMDQ4MDB9.ccHeAW27YUOPYf3cAnhWxl53Alg4sXbKcUQ2kfBKfo4')); }
function t_up_on(): bool { return strlen(t_up_token()) > 20; }
/** Token expiry from the JWT payload (no secret needed, nothing is sent anywhere). */
function t_up_token_exp(): ?int {
    $p = explode('.', t_up_token());
    if (count($p) < 2) return null;
    $j = json_decode((string)base64_decode(strtr($p[1], '-_', '+/')), true);
    return isset($j['exp']) ? (int)$j['exp'] : null;
}
function t_up_hdr(): array { return ['Authorization: Bearer ' . t_up_token()]; }
function t_up_req(string $path): array { return ['u' => T_UP_API . $path, 'h' => t_up_hdr()]; }
function t_up_map(): array {
    static $m = null;
    if ($m === null) $m = t_read_json(T_DATA . '/upstox_map.json', []);
    return $m;
}
/** Upstox instrument key for one of our symbols, or null (then Yahoo is used). */
function t_up_key(string $sym): ?string {
    if (!t_up_on()) return null;
    $m = t_up_map();
    return $m['nse'][$sym] ?? ($m['mcx'][$sym]['key'] ?? null);
}
function t_up_state(array $patch = []): array {
    $p = T_DATA . '/upstox_state.json';
    $s = t_read_json($p, []);
    if ($patch) { $s = array_merge($s, $patch); t_write_json($p, $s); }
    return $s;
}

/** Stream-scan a gzip JSON array of flat objects, keeping the ones $keep accepts (low memory). */
function t_up_scan_gz(string $file, callable $pre, callable $keep): array {
    $gz = @gzopen($file, 'rb');
    if (!$gz) return [];
    $buf = ''; $out = [];
    while (!gzeof($gz)) {
        $buf .= (string)gzread($gz, 1 << 20);
        $pos = 0;
        while (($a = strpos($buf, '{', $pos)) !== false && ($b = strpos($buf, '}', $a)) !== false) {
            $obj = substr($buf, $a, $b - $a + 1); $pos = $b + 1;
            if ($pre($obj)) { $j = json_decode($obj, true); if (is_array($j) && $keep($j)) $out[] = $j; }
        }
        $buf = substr($buf, $pos);
    }
    gzclose($gz);
    return $out;
}
/** Builds data/upstox_map.json: NSE equities and indices by symbol, nearest MCX futures. Runs about once a day. */
function t_up_build_map(int $now): bool {
    $bodies = t_fetch(['NSE' => T_UP_ASSETS . 'NSE.json.gz', 'MCX' => T_UP_ASSETS . 'MCX.json.gz'], $codes);
    $map = ['built' => $now, 'nse' => [], 'mcx' => [], 'missing' => []];
    $old = t_up_map();
    $want = [];
    foreach (t_universe()['India'] ?? [] as $s => $_) if (str_ends_with($s, '.NS')) $want[substr($s, 0, -3)] = $s;
    $idx = [];
    foreach (T_UP_INDEX as $s => $names) foreach ($names as $n) $idx[strtolower($n)] = $s;
    if ($bodies['NSE']) {
        $tmp = T_DATA . '/nse_instruments.json.gz'; file_put_contents($tmp, $bodies['NSE']); unset($bodies['NSE']);
        $recs = t_up_scan_gz($tmp,
            fn($o) => str_contains($o, '"NSE_EQ"') || str_contains($o, '"NSE_INDEX"'),
            function ($j) use ($want, $idx) {
                if (($j['segment'] ?? '') === 'NSE_EQ') return isset($want[$j['trading_symbol'] ?? '']);
                return ($j['segment'] ?? '') === 'NSE_INDEX' && (isset($idx[strtolower($j['name'] ?? '')]) || isset($idx[strtolower($j['trading_symbol'] ?? '')]));
            });
        @unlink($tmp);
        foreach ($recs as $j) {
            if ($j['segment'] === 'NSE_EQ') { $s = $want[$j['trading_symbol']]; if (!isset($map['nse'][$s]) || ($j['instrument_type'] ?? '') === 'EQ') $map['nse'][$s] = $j['instrument_key']; }
            else { $s = $idx[strtolower($j['name'] ?? '')] ?? $idx[strtolower($j['trading_symbol'] ?? '')]; $map['nse'][$s] = $j['instrument_key']; }
        }
        $map['nse']['^BSESN'] = 'BSE_INDEX|SENSEX';
    } else $map['nse'] = $old['nse'] ?? [];
    if ($bodies['MCX'] ?? null) {
        $tmp = T_DATA . '/mcx_instruments.json.gz'; file_put_contents($tmp, $bodies['MCX']);
        $under = []; foreach (T_MCX as $s => [$u]) $under[$u] = $s;
        $recs = t_up_scan_gz($tmp, fn($o) => str_contains($o, '"FUT"') && str_contains($o, '"MCX_FO"'),
            function ($j) use ($under) { $u = $j['underlying_symbol'] ?? ($j['asset_symbol'] ?? ($j['name'] ?? '')); return ($j['instrument_type'] ?? '') === 'FUT' && isset($under[$u]); });
        @unlink($tmp);
        $cut = ($now + 2 * 86400) * 1000;   // roll 2 days before expiry
        foreach ($recs as $j) {
            $u = $j['underlying_symbol'] ?? ($j['asset_symbol'] ?? $j['name']); $s = $under[$u]; $e = (int)($j['expiry'] ?? 0);
            if ($e < $cut) continue;
            if (!isset($map['mcx'][$s]) || $e < $map['mcx'][$s]['expiry']) $map['mcx'][$s] = ['key' => $j['instrument_key'], 'expiry' => $e, 'contract' => $j['trading_symbol'] ?? $u];
        }
    } else $map['mcx'] = $old['mcx'] ?? [];
    foreach ($want as $s) if (!isset($map['nse'][$s])) $map['missing'][] = $s;
    foreach (T_UP_INDEX as $s => $_) if (!isset($map['nse'][$s])) $map['missing'][] = $s;
    $ok = count($map['nse']) > 10;
    if ($ok || !$old) t_write_json(T_DATA . '/upstox_map.json', $map);
    t_up_state(['map_at' => $now, 'map_ok' => $ok, 'map_codes' => $codes, 'nse_keys' => count($map['nse']), 'mcx_keys' => count($map['mcx'])]);
    return $ok;
}
function t_up_map_due(int $now): bool {
    if (!t_up_on()) return false;
    $m = t_up_map(); $st = t_up_state();
    if ($now - (int)($st['map_at'] ?? 0) < 3600) return false;           // at most one attempt per hour
    return empty($m['built']) || $now - (int)$m['built'] >= 20 * 3600;
}

/** Upstox candles (newest first) -> frame (oldest first). $shift moves daily stamps from 00:00 to the session open. */
function t_up_parse(?string $body, int $shift = 0): ?array {
    if (!$body) return null;
    $j = json_decode($body, true); $c = $j['data']['candles'] ?? null;
    if (!is_array($c) || !$c) return null;
    $rows = [];
    foreach ($c as $x) {
        if (!is_array($x) || count($x) < 5) continue;
        $t = strtotime((string)$x[0]); if (!$t) continue;
        $o = (float)$x[1]; $h = (float)$x[2]; $l = (float)$x[3]; $cl = (float)$x[4];
        if ($cl <= 0) continue;
        if ($o <= 0) $o = $cl;
        $rows[$t + $shift] = [t_r($o), t_r(max($h, $o, $cl)), t_r($l > 0 ? min($l, $o, $cl) : min($o, $cl)), t_r($cl), (float)($x[5] ?? 0)];
    }
    ksort($rows);
    $f = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    foreach ($rows as $t => [$o, $h, $l, $cl, $v]) { $f['t'][] = $t; $f['o'][] = $o; $f['h'][] = $h; $f['l'][] = $l; $f['c'][] = $cl; $f['v'][] = $v; }
    return $f;
}
/** Seconds from local midnight to the first session of the exchange (daily candle label). */
function t_open_offset(string $ex): int {
    $d = t_exchanges()[$ex]['days'][1][0][0] ?? '00:00';
    [$h, $m] = array_map('intval', explode(':', $d));
    return $h * 3600 + $m * 60;
}
/** Today's trading window by the exchange clock, [start, end] or [0, 0] on non-trading days. */
function t_today_window(string $ex, int $now): array {
    $day = t_local_day(t_exchanges()[$ex]['tz']); $d = $day($now); $a = 0; $b = 0;
    foreach (t_intervals($ex, $now) as [$s, $e]) if ($day($s) === $d) { if (!$a) $a = $s; $b = $e; }
    return [$a, $b];
}
function t_up_ymd(int $ts): string { $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone('Asia/Kolkata')); return $d->format('Y-m-d'); }
/** Batch full quotes for many instrument keys: instrument_key => quote. */
function t_up_quotes(array $keys): array {
    $out = [];
    foreach (array_chunk(array_values(array_unique($keys)), 450) as $i => $chunk) {
        $b = t_fetch(["q$i" => t_up_req('/v2/market-quote/quotes?instrument_key=' . implode(',', array_map('rawurlencode', $chunk)))], $codes);
        if (($codes["q$i"] ?? 0) === 401) t_up_state(['auth_error' => t_now()]);
        $j = json_decode((string)($b["q$i"] ?? ''), true);
        foreach (($j['data'] ?? []) as $k => $q) { $key = $q['instrument_token'] ?? str_replace(':', '|', (string)$k); $out[$key] = $q; }
    }
    return $out;
}
