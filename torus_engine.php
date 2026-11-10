<?php
// ---------------------------------------------------------------------------
// TORUS engine (PHP edition for shared hosting) - Strategy v8 (5 Oct 2026): one score, one FLY / RUNWAY engine and one
// reversal-pattern list for BOTH setups (trend / long target and retracement / short target), shared with Tier 2.
// Multi-country universe, exchange sessions, rolling live-only scanner,
// indicators, Tier-1 scoring, stats and the AI brief. Server-side only.
// ---------------------------------------------------------------------------
declare(strict_types=1);

const T_REFRESH = 300;          // live symbol refresh (s)
const T_REFRESH_HOT = 60;       // Tier-1 shortlist (3/4+ aligned) and anything near an ATR zone: every minute
const T_REFRESH_QUIET = 900;    // exchange open by the clock but no fresh trade (holiday, halt, delayed feed)
const T_REFRESH_CLOSED = 14400; // closed market refresh
const T_FULL_EVERY = 604800;    // full-history refetch
const T_FRESH = 3600;           // last trade must be within 60 min of the fetch to count as live
const T_STALE = 1800;           // data older than 30 min never produces a signal
const T_CHUNK = 16;             // symbols per scan step
const T_KEEP_H = 1500;          // 1H bars kept in cache
const T_KEEP_D = 1300;          // daily bars kept in cache
const T_ZONE_PRICE = 'live';
require_once __DIR__ . '/torus_paths.php';
define('T_DATA', cp_data_dir());   // outside the deployed folder -- see torus_paths.php
if (PHP_SAPI !== 'cli' && !headers_sent()) header_remove('X-Powered-By');   // do not advertise the PHP version
define('T_FRAMES', T_DATA . '/frames');

const T_DEFAULT_KNOBS = [
    'atr_multiplier' => 3.0, 'st_period' => 10, 'ema_span' => 5,
    'alignment_weight' => 10.0, 'zone_band' => 0.4,
];

require_once __DIR__ . '/torus_upstox.php';
require_once __DIR__ . '/torus_alpaca.php';  // Alpaca (owner, 5 Nov 2026): real-time US stocks + the crypto coins Alpaca lists
require_once __DIR__ . '/torus_sweep.php';   // v9 (owner, 6 Oct 2026): ADR near + sweep/reclaim, shared by Tier 1 and Tier 2
require_once __DIR__ . '/torus_v10.php';     // v10 (owner, 11 Oct 2026): trend setup from the owner's PDF v3

function t_now(): int { $e = getenv('TORUS_NOW'); return ($e !== false && $e !== '') ? (int)$e : time(); }

// ---------------------------------------------------------------------------
// Universe
// ---------------------------------------------------------------------------
function t_universe(): array {
    static $u = null;
    if ($u === null) {
        $u = require __DIR__ . '/torus_universe.php';
        if (t_up_on()) {   // MCX futures exist only through Upstox
            $m = t_up_map(); $g = [];
            foreach (T_MCX as $s => [$und, $nm]) if (isset($m['mcx'][$s])) $g[$s] = $nm;
            if ($g) $u = array_slice($u, 0, 1, true) + ['MCX Commodities' => $g] + array_slice($u, 1, null, true);
        }
    }
    return $u;
}
function t_symbols(): array {
    static $s = null;
    if ($s === null) { $s = []; foreach (t_universe() as $m => $l) foreach ($l as $x => $nm) $s[$x] = $m; }
    return $s;
}
function t_name(string $s): string {
    static $n = null;
    if ($n === null) { $n = []; foreach (t_universe() as $l) $n += $l; }
    return $n[$s] ?? $s;
}
/** Position of a symbol inside its market list (lists are ordered by index weight and liquidity). */
function t_rank(string $s): int {
    static $k = null;
    if ($k === null) { $k = []; foreach (t_universe() as $l) { $i = 0; foreach ($l as $x => $_) $k[$x] = $i++; } }
    return $k[$s] ?? 99;
}
function t_ist(int $ts, string $fmt): string {
    $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone('Asia/Kolkata')); return $d->format($fmt);
}
/** Round to 8 significant digits (keeps meme-coin prices like 0.0000095 intact). */
function t_r(float $x): float { return $x == 0.0 ? 0.0 : round($x, max(0, 7 - (int)floor(log10(abs($x))))); }
function t_clean($v) {
    if (is_float($v)) return (is_nan($v) || is_infinite($v)) ? null : $v;
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = t_clean($x); }
    return $v;
}
/**
 * Visitor IP (owner, 29 Sep 2026). Hostinger's CDN (hcdn) sits in front of the site: when it forwards a request, the last
 * X-Forwarded-For hop is the address it saw; otherwise REMOTE_ADDR is the visitor. Only used to key rate limits.
 */
function t_client_ip(): string {
    $ra = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $hops = array_values(array_filter(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))), fn($x) => filter_var($x, FILTER_VALIDATE_IP)));
    return $hops ? $hops[count($hops) - 1] : $ra;
}
/** Fixed-window rate limit: true while this visitor is under $max hits per $win seconds in $bucket. File based (data/rl), no database. */
function t_rl(string $bucket, int $max, int $win = 60): bool {
    $dir = T_DATA . '/rl'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $f = $dir . '/' . substr(hash('sha256', $bucket . '|' . t_client_ip()), 0, 20);
    $h = @fopen($f, 'c+'); if (!$h) return true;   // never lock people out because of a disk problem
    flock($h, LOCK_EX); $now = time();
    $d = json_decode((string)stream_get_contents($h), true) ?: ['t' => $now, 'n' => 0];
    if ($now - (int)$d['t'] >= $win) $d = ['t' => $now, 'n' => 0];
    $d['n']++; ftruncate($h, 0); rewind($h); fwrite($h, json_encode($d)); flock($h, LOCK_UN); fclose($h);
    if (mt_rand(1, 400) === 1) foreach ((array)glob($dir . '/*') as $x) if (is_file($x) && $now - (int)@filemtime($x) > 900) @unlink($x);   // tidy old counters
    return $d['n'] <= $max;
}
function t_json($data): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(t_clean($data), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

// ---------------------------------------------------------------------------
// Exchanges and trading sessions (local exchange time, ISO weekday 1=Mon..7=Sun)
// ---------------------------------------------------------------------------
function t_exchanges(): array {
    static $x = null;
    if ($x !== null) return $x;
    $wd = function (array ...$s) { return [1 => $s, 2 => $s, 3 => $s, 4 => $s, 5 => $s]; };
    // Sunday open -> Friday close, with an optional daily break Mon-Thu
    $wk = function (string $open, string $close, ?string $bs = null, ?string $be = null) {
        $mid = $bs ? [['00:00', $bs], [$be, '24:00']] : [['00:00', '24:00']];
        return [7 => [[$open, '24:00']], 1 => $mid, 2 => $mid, 3 => $mid, 4 => $mid, 5 => [['00:00', $close]]];
    };
    $grain = [['00:00', '08:45'], ['09:30', '14:20'], ['20:00', '24:00']];
    $x = [
        'NSE' => ['NSE', 'Asia/Kolkata', $wd(['09:15', '15:30'])],
        'US' => ['NYSE · Nasdaq', 'America/New_York', $wd(['09:30', '16:00'])],
        'TSX' => ['TSX', 'America/Toronto', $wd(['09:30', '16:00'])],
        'BMV' => ['BMV', 'America/New_York', $wd(['09:30', '16:00'])],
        'B3' => ['B3', 'America/Sao_Paulo', $wd(['10:00', '18:00'])],
        'LSE' => ['LSE', 'Europe/London', $wd(['08:00', '16:35'])],
        'XETRA' => ['Xetra', 'Europe/Berlin', $wd(['09:00', '17:35'])],
        'EURONEXT' => ['Euronext', 'Europe/Paris', $wd(['09:00', '17:35'])],
        'SIX' => ['SIX', 'Europe/Zurich', $wd(['09:00', '17:35'])],
        'MIL' => ['Borsa Italiana', 'Europe/Rome', $wd(['09:00', '17:35'])],
        'BME' => ['BME', 'Europe/Madrid', $wd(['09:00', '17:35'])],
        'CPH' => ['Nasdaq Copenhagen', 'Europe/Copenhagen', $wd(['09:00', '17:00'])],
        'STO' => ['Nasdaq Stockholm', 'Europe/Stockholm', $wd(['09:00', '17:30'])],
        'JSE' => ['JSE', 'Africa/Johannesburg', $wd(['09:00', '17:00'])],
        'TADAWUL' => ['Tadawul', 'Asia/Riyadh', [7 => [['10:00', '15:10']], 1 => [['10:00', '15:10']], 2 => [['10:00', '15:10']], 3 => [['10:00', '15:10']], 4 => [['10:00', '15:10']]]],
        'JPX' => ['TSE', 'Asia/Tokyo', $wd(['09:00', '11:30'], ['12:30', '15:30'])],
        'HKEX' => ['HKEX', 'Asia/Hong_Kong', $wd(['09:30', '12:00'], ['13:00', '16:10'])],
        'CN' => ['SSE · SZSE', 'Asia/Shanghai', $wd(['09:30', '11:30'], ['13:00', '15:00'])],
        'KRX' => ['KRX', 'Asia/Seoul', $wd(['09:00', '15:30'])],
        'TWSE' => ['TWSE', 'Asia/Taipei', $wd(['09:00', '13:30'])],
        'SGX' => ['SGX', 'Asia/Singapore', $wd(['09:00', '17:05'])],
        'ASX' => ['ASX', 'Australia/Sydney', $wd(['10:00', '16:12'])],
        'CME' => ['CME Globex', 'America/New_York', $wk('18:00', '17:00', '17:00', '18:00')],
        'CBOT_GRAIN' => ['CBOT Grains', 'America/New_York', [7 => [['20:00', '24:00']], 1 => $grain, 2 => $grain, 3 => $grain, 4 => $grain, 5 => [['00:00', '08:45'], ['09:30', '14:20']]]],
        'CME_LIVE' => ['CME Livestock', 'America/New_York', $wd(['09:30', '14:05'])],
        'ICE_KC' => ['ICE Coffee', 'America/New_York', $wd(['04:15', '13:30'])],
        'ICE_SB' => ['ICE Sugar', 'America/New_York', $wd(['03:30', '13:00'])],
        'ICE_CC' => ['ICE Cocoa', 'America/New_York', $wd(['04:45', '13:30'])],
        'ICE_CT' => ['ICE Cotton', 'America/New_York', $wk('21:00', '14:20', '14:20', '21:00')],
        'ICE_DX' => ['ICE Dollar Index', 'America/New_York', $wk('20:00', '17:00', '17:00', '20:00')],
        'FX' => ['Forex 24×5', 'America/New_York', $wk('17:00', '17:00')],
        'CRYPTO' => ['Crypto 24×7', 'UTC', array_fill(1, 7, [['00:00', '24:00']])],
        // MCX closes 23:30 IST while the US is on daylight time, 23:55 otherwise
        'MCX' => ['MCX', 'Asia/Kolkata', $wd(['09:00', '23:30']), $wd(['09:00', '23:55'])],
    ];
    foreach ($x as $k => $v) $x[$k] = ['venue' => $v[0], 'tz' => $v[1], 'days' => $v[2], 'days_std' => $v[3] ?? null];
    return $x;
}
/** World Markets strip: display groups -> exchange codes */
function t_chips(): array {
    return [
        'ASX' => ['Sydney', ['ASX']], 'JPX' => ['Tokyo', ['JPX']], 'KRX' => ['Seoul', ['KRX']], 'CN' => ['Shanghai', ['CN']],
        'HKEX' => ['Hong Kong', ['HKEX']], 'TWSE' => ['Taipei', ['TWSE']], 'SGX' => ['Singapore', ['SGX']], 'NSE' => ['India NSE', ['NSE']], 'MCX' => ['India MCX', ['MCX']],
        'TADAWUL' => ['Riyadh', ['TADAWUL']], 'JSE' => ['Johannesburg', ['JSE']], 'XETRA' => ['Frankfurt', ['XETRA']],
        'EURONEXT' => ['Paris · Amsterdam', ['EURONEXT']], 'LSE' => ['London', ['LSE']], 'SIX' => ['Zurich', ['SIX']],
        'MIL' => ['Milan', ['MIL']], 'BME' => ['Madrid', ['BME']], 'NORDIC' => ['Nordics', ['CPH', 'STO']],
        'US' => ['New York', ['US']], 'TSX' => ['Toronto', ['TSX']], 'BMV' => ['Mexico', ['BMV']], 'B3' => ['São Paulo', ['B3']],
        'FUT' => ['Futures', ['CME', 'CBOT_GRAIN', 'CME_LIVE', 'ICE_KC', 'ICE_SB', 'ICE_CC', 'ICE_CT', 'ICE_DX']],
        'FX' => ['Forex', ['FX']], 'CRYPTO' => ['Crypto', ['CRYPTO']],
    ];
}
function t_exchange(string $s): string {
    static $idx = ['^NSEI' => 'NSE', '^NSEBANK' => 'NSE', '^BSESN' => 'NSE', '^GSPC' => 'US', '^NDX' => 'US', '^IXIC' => 'US', '^DJI' => 'US',
        '^RUT' => 'US', '^GSPTSE' => 'TSX', '^FTSE' => 'LSE', '^GDAXI' => 'XETRA', '^STOXX50E' => 'XETRA', '^FCHI' => 'EURONEXT',
        '^AEX' => 'EURONEXT', '^SSMI' => 'SIX', '^IBEX' => 'BME', '^N225' => 'JPX', '^HSI' => 'HKEX', '^KS11' => 'KRX', '^TWII' => 'TWSE',
        '^STI' => 'SGX', '^AXJO' => 'ASX', '^BVSP' => 'B3', '^MXX' => 'BMV', 'DX-Y.NYB' => 'ICE_DX'];
    static $fut = ['KC' => 'ICE_KC', 'SB' => 'ICE_SB', 'CC' => 'ICE_CC', 'CT' => 'ICE_CT', 'ZC' => 'CBOT_GRAIN', 'ZS' => 'CBOT_GRAIN',
        'ZW' => 'CBOT_GRAIN', 'LE' => 'CME_LIVE', 'HE' => 'CME_LIVE'];
    static $suf = ['.NS' => 'NSE', '.BO' => 'NSE', '.TO' => 'TSX', '.L' => 'LSE', '.DE' => 'XETRA', '.PA' => 'EURONEXT', '.AS' => 'EURONEXT',
        '.BR' => 'EURONEXT', '.SW' => 'SIX', '.MI' => 'MIL', '.MC' => 'BME', '.CO' => 'CPH', '.ST' => 'STO', '.T' => 'JPX', '.HK' => 'HKEX',
        '.SS' => 'CN', '.SZ' => 'CN', '.KS' => 'KRX', '.KQ' => 'KRX', '.TW' => 'TWSE', '.SI' => 'SGX', '.AX' => 'ASX', '.SA' => 'B3',
        '.MX' => 'BMV', '.SR' => 'TADAWUL', '.JO' => 'JSE'];
    if (isset($idx[$s])) return $idx[$s];
    if (str_starts_with($s, 'MCX:')) return 'MCX';
    if (str_ends_with($s, '-USD')) return 'CRYPTO';
    if (str_ends_with($s, '=X')) return 'FX';
    if (str_ends_with($s, '=F')) return $fut[substr($s, 0, -2)] ?? 'CME';
    if (str_starts_with($s, '^CNX')) return 'NSE';
    $p = strrpos($s, '.');
    if ($p !== false && isset($suf[substr($s, $p)])) return $suf[substr($s, $p)];
    return 'US';
}
function t_at(DateTimeImmutable $day, string $hm): int {
    [$h, $m] = array_map('intval', explode(':', $hm));
    if ($h >= 24) return $day->modify('+1 day')->setTime(0, 0)->getTimestamp();
    return $day->setTime($h, $m)->getTimestamp();
}
/** Merged absolute open intervals [start, end) from 8 days back to 8 days ahead. */
function t_intervals(string $ex, int $now): array {
    static $cache = [];
    $E = t_exchanges()[$ex];
    $tz = new DateTimeZone($E['tz']);
    $d0 = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    $key = $ex . '|' . $d0->format('Y-m-d');
    if (isset($cache[$key])) return $cache[$key];
    $iv = [];
    for ($i = -8; $i <= 8; $i++) {
        $day = $d0->modify(($i >= 0 ? '+' : '') . $i . ' day');
        $days = $E['days'];
        if ($E['days_std'] && (new DateTime($day->format('Y-m-d') . ' 12:00', new DateTimeZone('America/New_York')))->format('I') === '0') $days = $E['days_std'];
        foreach ($days[(int)$day->format('N')] ?? [] as [$s, $e]) {
            $a = t_at($day, $s); $b = t_at($day, $e);
            if ($b > $a) $iv[] = [$a, $b];
        }
    }
    usort($iv, fn($x, $y) => $x[0] <=> $y[0]);
    $m = [];
    foreach ($iv as $x) {
        $k = count($m) - 1;
        if ($k >= 0 && $x[0] <= $m[$k][1]) $m[$k][1] = max($m[$k][1], $x[1]); else $m[] = $x;
    }
    return $cache[$key] = $m;
}
/** Clock state of an exchange: open / break (lunch or daily maintenance) / closed. */
function t_session(string $ex, int $now): array {
    static $memo = [];
    $mk = "$ex|$now";
    if (isset($memo[$mk])) return $memo[$mk];
    $open = false; $since = null; $close = null; $next = null; $last = null;
    foreach (t_intervals($ex, $now) as [$a, $b]) {
        if ($a <= $now && $now < $b) { $open = true; $since = $a; $close = $b; }
        elseif ($a > $now && $next === null) $next = $a;
        if ($b <= $now) $last = $b;
    }
    $state = $open ? 'open' : 'closed';
    if (!$open && $last !== null && $next !== null && $next - $last <= 3 * 3600) $state = 'break';
    if (count($memo) > 2000) $memo = [];
    return $memo[$mk] = ['open' => $open, 'state' => $state, 'since' => $since, 'close' => $close, 'next' => $next, 'last_close' => $last];
}
function t_local(string $ex, int $now, string $fmt = 'H:i'): string {
    $d = new DateTime('@' . $now); $d->setTimezone(new DateTimeZone(t_exchanges()[$ex]['tz'])); return $d->format($fmt);
}
/**
 * Live = exchange open by the clock AND the last fetch is recent AND the last trade was fresh at that fetch
 * AND now falls inside Yahoo's current trading period (catches holidays and early closes) AND today's daily bar exists.
 */
function t_status(string $sym, ?array $r, int $now): array {
    $ex = t_exchange($sym);
    $ss = t_session($ex, $now);
    if (!$r || !empty($r['mig'])) return ['live' => false, 'state' => $ss['open'] ? 'stale' : ($ss['state'] === 'break' ? 'break' : 'closed')];
    $ts = (int)($r['ts'] ?? 0); $rmt = (int)($r['rmt'] ?? 0); $cs = (int)($r['cs'] ?? 0); $ce = (int)($r['ce'] ?? 0);
    $recent = $now - $ts <= T_STALE;
    $traded = $rmt >= $ts - T_FRESH;
    $inCtp = !$cs || ($now >= $cs - 300 && $now < $ce + 300);
    $dayOk = $r['day_ok'] ?? true;
    if ($ss['open'] && $recent && $traded && $inCtp && $dayOk) $state = 'live';
    elseif ($ss['state'] === 'break') $state = 'break';
    elseif (!$ss['open']) $state = 'closed';
    elseif ($traded && $inCtp && !$recent) $state = 'stale';
    else $state = 'quiet';
    return ['live' => $state === 'live', 'state' => $state];
}

// ---------------------------------------------------------------------------
// Data fetch (Yahoo Finance chart API)
// ---------------------------------------------------------------------------
function t_url(string $sym, string $range, string $interval, string $host = 'query1'): string {
    return "https://$host.finance.yahoo.com/v8/finance/chart/" . rawurlencode($sym) . "?range=$range&interval=$interval";
}
/** $urls: key => url string, or key => ['u' => url, 'h' => extra headers]. Returns key => body|null (HTTP 200 only). */
function t_fetch_multi(array $urls, ?array &$codes = null): array {
    $fix = getenv('TORUS_FIXTURES');
    $out = []; $codes = [];
    if ($fix) {
        foreach ($urls as $k => $u) {
            $u = is_array($u) ? $u['u'] : $u;
            $f = "$fix/" . md5(preg_replace('#https://query\d#', 'https://query1', $u)) . '.json';
            if (!is_file($f) && str_contains($u, '/market-quote/quotes')) $f = "$fix/quotes.json";
            $out[$k] = is_file($f) ? file_get_contents($f) : null; $codes[$k] = $out[$k] === null ? 404 : 200;
        }
        return $out;
    }
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $k => $u) {
        $hdr = ['Accept: application/json'];
        if (is_array($u)) { $hdr = array_merge($hdr, $u['h'] ?? []); $u = $u['u']; }
        $h = curl_init($u);
        curl_setopt_array($h, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $hdr,
        ]);
        curl_multi_add_handle($mh, $h); $hs[$k] = $h;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st == CURLM_OK);
    foreach ($hs as $k => $h) {
        $code = (int)curl_getinfo($h, CURLINFO_HTTP_CODE); $codes[$k] = $code;
        $out[$k] = $code === 200 ? curl_multi_getcontent($h) : null;
        curl_multi_remove_handle($mh, $h); curl_close($h);
    }
    curl_multi_close($mh);
    return $out;
}
/** Fetch with one retry of failures (Yahoo: on the query2 host). */
function t_fetch(array $urls, ?array &$codes = null): array {
    $b = t_fetch_multi($urls, $codes);
    $retry = [];
    foreach ($b as $k => $x) if (!$x && ($codes[$k] ?? 0) !== 401 && ($codes[$k] ?? 0) !== 404) {
        $u = $urls[$k];
        if (is_array($u)) $retry[$k] = $u; else $retry[$k] = str_replace('https://query1.', 'https://query2.', $u);
    }
    if ($retry) { $c2 = []; foreach (t_fetch_multi($retry, $c2) as $k => $x) { $b[$k] = $x; $codes[$k] = $c2[$k]; } }
    return $b;
}
/** Returns [frame, meta] or [null, null]. */
function t_parse(?string $body): array {
    if (!$body) return [null, null];
    $j = json_decode($body, true);
    $r = $j['chart']['result'][0] ?? null;
    if (!$r || empty($r['timestamp'])) return [null, null];
    $q = $r['indicators']['quote'][0] ?? [];
    $f = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $prev = null;
    foreach ($r['timestamp'] as $i => $ts) {
        $c = $q['close'][$i] ?? null;
        if ($c === null || (float)$c <= 0) continue;
        $c = (float)$c;   // repair broken fields (e.g. open = 0 on a futures roll day) instead of trusting them
        $o = (float)($q['open'][$i] ?? 0); if ($o <= 0) $o = $prev ?? $c;
        $h = (float)($q['high'][$i] ?? 0); $l = (float)($q['low'][$i] ?? 0);
        $h = max($h, $o, $c); $l = $l > 0 ? min($l, $o, $c) : min($o, $c); $prev = $c;
        $f['t'][] = (int)$ts; $f['o'][] = t_r($o); $f['h'][] = t_r($h); $f['l'][] = t_r($l); $f['c'][] = t_r($c);
        $f['v'][] = (float)($q['volume'][$i] ?? 0);
    }
    $m = $r['meta'] ?? [];
    $tp = $m['currentTradingPeriod']['regular'] ?? [];
    $meta = ['rmt' => (int)($m['regularMarketTime'] ?? 0), 'cs' => (int)($tp['start'] ?? 0), 'ce' => (int)($tp['end'] ?? 0),
        'tz' => (string)($m['exchangeTimezoneName'] ?? ''), 'hi' => (float)($m['regularMarketDayHigh'] ?? 0),
        'lo' => (float)($m['regularMarketDayLow'] ?? 0), 'px' => (float)($m['regularMarketPrice'] ?? 0), 'vol' => (float)($m['regularMarketVolume'] ?? 0)];
    return [count($f['t']) ? $f : null, $meta];
}
/**
 * Yahoo sometimes stamps the live (last) bar with the last-trade time instead of the session start
 * (seen on FX daily bars). Snap it onto the period grid: merge into the previous bar when it belongs
 * to the same period, otherwise relabel it with the proper period start. DST shifts (±1h) are left alone.
 */
function t_norm_last(array $f, int $period = 86400): array {
    $n = count($f['t']);
    if ($n < 3) return $f;
    $a = $f['t'][$n - 3]; $b = $f['t'][$n - 2]; $c = $f['t'][$n - 1];
    $tod = fn($x) => (($x % $period) + $period) % $period;
    $near = function ($x, $y) use ($tod, $period) { $d = abs($tod($x) - $tod($y)); $d = min($d, $period - $d); return $d === 0 || $d === 3600; };
    if ($near($c, $b) || !$near($a, $b)) return $f;
    $k = intdiv($c - $b, $period);
    if ($k >= 1) { $f['t'][$n - 1] = $b + $k * $period; return $f; }
    $f['h'][$n - 2] = max($f['h'][$n - 2], $f['h'][$n - 1]); $f['l'][$n - 2] = min($f['l'][$n - 2], $f['l'][$n - 1]);
    $f['c'][$n - 2] = $f['c'][$n - 1]; $f['v'][$n - 2] += $f['v'][$n - 1];
    foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $x) array_pop($f[$x]);
    return $f;
}
/** Replace cached bars from the first new timestamp onward, keep the last $keep bars. */
function t_merge(?array $old, array $new, int $keep): array {
    if (!$old || !count($old['t'])) $m = $new;
    else {
        $t0 = $new['t'][0]; $m = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
        foreach ($old['t'] as $i => $t) { if ($t >= $t0) break; foreach ($m as $k => $_) $m[$k][] = $old[$k][$i]; }
        foreach ($m as $k => $_) $m[$k] = array_merge($m[$k], $new[$k]);
    }
    if (count($m['t']) > $keep) foreach ($m as $k => $_) $m[$k] = array_slice($m[$k], -$keep);
    return $m;
}
function t_resample(array $f, int $sec): array {
    $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $key = null; $j = -1;
    foreach ($f['t'] as $i => $t) {
        $k = intdiv($t, $sec);
        if ($k !== $key) { $key = $k; $j++; $o['t'][] = $k * $sec; $o['o'][] = $f['o'][$i]; $o['h'][] = $f['h'][$i]; $o['l'][] = $f['l'][$i]; $o['c'][] = $f['c'][$i]; $o['v'][] = $f['v'][$i]; }
        else { $o['h'][$j] = max($o['h'][$j], $f['h'][$i]); $o['l'][$j] = min($o['l'][$j], $f['l'][$i]); $o['c'][$j] = $f['c'][$i]; $o['v'][$j] += $f['v'][$i]; }
    }
    return $o;
}
/** Group bars by a key function (bars must be time-sorted). */
function t_group(array $f, callable $key): array {
    $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $cur = null; $j = -1;
    foreach ($f['t'] as $i => $t) {
        $k = $key($t);
        if ($k !== $cur) { $cur = $k; $j++; $o['t'][] = $t; $o['o'][] = $f['o'][$i]; $o['h'][] = $f['h'][$i]; $o['l'][] = $f['l'][$i]; $o['c'][] = $f['c'][$i]; $o['v'][] = $f['v'][$i]; }
        else { $o['h'][$j] = max($o['h'][$j], $f['h'][$i]); $o['l'][$j] = min($o['l'][$j], $f['l'][$i]); $o['c'][$j] = $f['c'][$i]; $o['v'][$j] += $f['v'][$i]; }
    }
    return $o;
}
function t_local_day(string $tz): callable {
    $z = new DateTimeZone($tz ?: 'UTC');
    return function (int $t) use ($z) { return intdiv($t + $z->getOffset(new DateTime('@' . $t)) + 86400 * 4000, 86400); };
}
/** Daily bars built from 1H bars (for instruments Yahoo has no daily history for). */
function t_daily_from_hourly(array $H, string $tz): array { return t_group($H, t_local_day($tz)); }
/** Weekly bars built from daily bars; weeks start Monday (Sunday for Sun-Thu markets). */
function t_weekly(array $D, string $tz): array {
    $day = t_local_day($tz);
    $shift = $tz === 'Asia/Riyadh' ? 3 : 4;   // epoch day 3 = Sunday, 4 = Monday (1970-01-04 / 05)
    return t_group($D, fn($t) => intdiv($day($t) - 4000 - $shift + 7 * 1000, 7));
}
function t_frame_path(string $sym): string { return T_FRAMES . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym) . '.json'; }
function t_read_frames(string $sym): ?array {
    $p = t_frame_path($sym);
    if (!is_file($p)) return null;
    $f = json_decode((string)file_get_contents($p), true);
    if (!$f || empty($f['1H']['t']) || empty($f['1D']['t'])) return null;
    if (empty($f['tz'])) $f['tz'] = t_exchanges()[t_exchange($sym)]['tz'];   // v1 cache files
    unset($f['1W']);
    return $f;
}
/** Frames ready for evaluation: 1H, 4H (resampled), 1D, 1W (from daily). */
function t_frames_eval(array $f): array {
    return ['1H' => $f['1H'], '4H' => t_resample($f['1H'], 14400), '1D' => $f['1D'], '1W' => t_weekly($f['1D'], $f['tz']), '5m' => $f['5m'] ?? null];
}
function t_load_frames(string $sym): ?array { $f = t_read_frames($sym); return $f ? t_frames_eval($f) : null; }

// ---------------------------------------------------------------------------
// Indicators
// ---------------------------------------------------------------------------
function t_ewm(array $x, float $alpha): array {
    $o = []; $n = count($x); if (!$n) return $o; $o[0] = (float)$x[0];
    for ($i = 1; $i < $n; $i++) $o[$i] = $alpha * $x[$i] + (1 - $alpha) * $o[$i - 1];
    return $o;
}
function t_ema(array $c, int $span): array { return t_ewm($c, 2 / (max($span, 1) + 1)); }
function t_atr(array $f, int $p = 14): array {
    $tr = [];
    foreach ($f['c'] as $i => $c) { $pc = $i ? $f['c'][$i - 1] : $f['c'][0]; $tr[] = max($f['h'][$i] - $f['l'][$i], abs($f['h'][$i] - $pc), abs($f['l'][$i] - $pc)); }
    return t_ewm($tr, 1 / $p);
}
function t_supertrend(array $f, int $per = 10, float $m = 3.0): array {
    $a = t_atr($f, $per); $n = count($f['c']); $fu = []; $fl = []; $d = [];
    for ($i = 0; $i < $n; $i++) {
        $hl2 = ($f['h'][$i] + $f['l'][$i]) / 2; $ub = $hl2 + $m * $a[$i]; $lb = $hl2 - $m * $a[$i];
        if ($i === 0) { $fu[] = $ub; $fl[] = $lb; $d[] = 1; continue; }
        $fu[$i] = ($ub < $fu[$i - 1] || $f['c'][$i - 1] > $fu[$i - 1]) ? $ub : $fu[$i - 1];
        $fl[$i] = ($lb > $fl[$i - 1] || $f['c'][$i - 1] < $fl[$i - 1]) ? $lb : $fl[$i - 1];
        if ($d[$i - 1] === -1 && $f['c'][$i] > $fu[$i - 1]) $d[$i] = 1;
        elseif ($d[$i - 1] === 1 && $f['c'][$i] < $fl[$i - 1]) $d[$i] = -1;
        else $d[$i] = $d[$i - 1];
    }
    $line = []; foreach ($d as $i => $x) $line[] = $x === 1 ? $fl[$i] : $fu[$i];
    return [$d, $line];
}
/** ADX series: [adx[], +DI[], -DI[]] (Wilder smoothing). */
function t_adx_s(array $f, int $p = 14): array {
    $n = count($f['c']); $pdm = [0.0]; $mdm = [0.0];
    for ($i = 1; $i < $n; $i++) {
        $up = $f['h'][$i] - $f['h'][$i - 1]; $dn = $f['l'][$i - 1] - $f['l'][$i];
        $pdm[] = ($up > $dn && $up > 0) ? $up : 0.0; $mdm[] = ($dn > $up && $dn > 0) ? $dn : 0.0;
    }
    $a = t_atr($f, $p); $P = t_ewm($pdm, 1 / $p); $M = t_ewm($mdm, 1 / $p); $dx = []; $PD = []; $MD = [];
    for ($i = 0; $i < $n; $i++) {
        $pdi = $a[$i] ? 100 * $P[$i] / $a[$i] : 0; $mdi = $a[$i] ? 100 * $M[$i] / $a[$i] : 0;
        $PD[] = (float)$pdi; $MD[] = (float)$mdi;
        $dx[] = ($pdi + $mdi) > 0 ? abs($pdi - $mdi) / ($pdi + $mdi) * 100 : 0.0;
    }
    return [t_ewm($dx, 1 / $p), $PD, $MD];
}
function t_adx(array $f, int $p = 14): array {
    [$ad, $PD, $MD] = t_adx_s($f, $p);
    return $ad ? [(float)end($ad), (float)end($PD), (float)end($MD)] : [0.0, 0.0, 0.0];
}

// ---------------------------------------------------------------------------
// Tier-1 evaluation
// ---------------------------------------------------------------------------
function t_rsi_last(array $c, int $p = 14): float {
    $up = [0.0]; $dn = [0.0]; $n = count($c);
    for ($i = 1; $i < $n; $i++) { $d = $c[$i] - $c[$i - 1]; $up[] = $d > 0 ? $d : 0.0; $dn[] = $d < 0 ? -$d : 0.0; }
    $au = t_ewm($up, 1 / $p); $au = (float)end($au); $ad = t_ewm($dn, 1 / $p); $ad = (float)end($ad);
    if ($ad == 0) return $au > 0 ? 100.0 : 50.0;
    return 100 - 100 / (1 + $au / $ad);
}
/** True when price is at its trend-side ATR zone or beyond it: UP at/below ATR Low (+band), DOWN at/above ATR High (-band). */
function t_at_zone(string $bias, float $price, float $atrLow, float $atrHigh, float $band): bool {
    if ($bias === 'UP') return $price <= $atrLow + $band;
    if ($bias === 'DOWN') return $price >= $atrHigh - $band;
    return false;
}
function t_evaluate(string $sym, array $fr, array $knobs = []): array {
    // Tier-1 score v8 (owner, 5 Oct 2026), out of 100, same formula for both setups (see "v8 score" below).
    // The score only RANKS signals; it never makes a symbol eligible. Proximities: full on the level, zero at 1 x ATR5.
    $k = array_merge(T_DEFAULT_KNOBS, $knobs);
    $per = (int)$k['st_period']; $mult = (float)$k['atr_multiplier'];
    $D = $fr['1D']; $n = count($D['c']); $dayTs = $D['t'][$n - 1];
    $tfs = [];
    foreach (['1H', '4H', '1D', '1W'] as $tf) {
        [$d, $line] = t_supertrend($fr[$tf], $per, $mult); $idx = count($d) - 1;
        if ($tf === '1H' && $idx > 0 && (int)$fr['1H']['t'][$idx] + 3600 > time()) $idx--;   // owner, 5 Oct 2026 18:51 IST: last CLOSED 1H candle (live, like TradingView), not frozen at the day start
        $lastFlip = -1; for ($i = 1; $i <= $idx; $i++) if ($d[$i] !== $d[$i - 1]) $lastFlip = $i - 1;
        $tfs[$tf] = ['dir' => $d[$idx], 'st' => $line[$idx], 'live_dir' => $d[count($d) - 1],
            'bars_in_trend' => $lastFlip >= 0 ? $idx - $lastFlip : $idx + 1];
    }
    $up = 0; foreach ($tfs as $v) if ($v['dir'] === 1) $up++;
    $down = 4 - $up;
    $bias = $up > $down ? 'UP' : ($down > $up ? 'DOWN' : 'NEUTRAL');
    $alignment = max($up, $down);

    $pdh = $D['h'][$n - 2]; $pdl = $D['l'][$n - 2];
    $tOpen = $D['o'][$n - 1]; $tHigh = $D['h'][$n - 1]; $tLow = $D['l'][$n - 1];
    $atrD = t_atr($D, 14)[$n - 2]; $last = $D['c'][$n - 1]; $price = $last;
    // Tier-1 v5 zone (27 Sep 2026, owner): the zone is today's LIVE Day Low / Day High; a 5-minute candle is "at" it when its low
    // is within zone_band (0.4) x ATR(14, 5-minute) of the Day Low (its high within the same distance of the Day High).
    // The last four 5m candles (20 min) count, so a scan every minute cannot miss a touch.
    $F5 = $fr['5m'] ?? null; $bars = [];
    if ($F5 && !empty($F5['t'])) foreach ($F5['t'] as $i => $t) if ($t >= $dayTs && $t < $dayTs + 86400) $bars[] = $i;
    if ($bars) {
        $atr5 = (float)t_atr($F5, 14)[count($F5['c']) - 1];
        $atrLow = min(array_map(fn($i) => (float)$F5['l'][$i], $bars)); $atrHigh = max(array_map(fn($i) => (float)$F5['h'][$i], $bars));
        $atrLow = min($atrLow, $price); $atrHigh = max($atrHigh, $price);
        $win = array_slice($bars, -4);
        $lowX = min(array_map(fn($i) => (float)$F5['l'][$i], $win)); $highX = max(array_map(fn($i) => (float)$F5['h'][$i], $win));
        $lowX = min($lowX, $price); $highX = max($highX, $price);
    } else {   // no intraday bars yet: daily bar's live high/low, 5m ATR estimated from the 1H ATR
        $atr5 = (float)t_atr($fr['1H'], 14)[count($fr['1H']['c']) - 1] / sqrt(12);
        $atrLow = min($tLow, $price); $atrHigh = max($tHigh, $price); $lowX = $price; $highX = $price;
    }
    if ($atr5 <= 0) $atr5 = $atrD / sqrt(78);
    $band = (float)$k['zone_band'] * $atr5;
    // Tier-1 v4 (27 Sep 2026): both zones trade. Price at the zone on the trend side = trend entry (long target);
    // price at the opposite zone = retracement entry against the trend (small target). The nearer zone is the one watched.
    $atLow = $lowX - $atrLow <= $band; $atHigh = $atrHigh - $highX <= $band;
    if ($atLow && !$atHigh) $zone = $atrLow;
    elseif ($atHigh && !$atLow) $zone = $atrHigh;
    else $zone = abs($price - $atrLow) <= abs($price - $atrHigh) ? $atrLow : $atrHigh;
    $zoneSide = $zone == $atrLow ? 'ATR Low' : 'ATR High';
    $cl = fn($x) => max(0.0, min(1.0, (float)$x));
    // proximity (owner, 5 Oct 2026 18:51 IST, same as the owner's TradingView script): "near" = within 10% of today's Day range
    // (Day High - Day Low) -> full points, else 0. Falls back to 1 x ATR5 only when today's range is not known yet.
    // v9 (owner, 6 Oct 2026): near = 0.10 x clamp(Day range, 0.5 x ADR, 1.5 x ADR); ADR = median H-L of the last 20 completed sessions.
    // Fewer than 10 sessions of history -> old rule (10% of Day range). Old near kept for logging (components.strength_old).
    $adrV = tsw_adr($D, $n); [$zTol, $zTolOld, $nearFb] = tsw_near((float)$tHigh, (float)$tLow, $adrV);
    $pbT = function ($a, $b, $tol) use ($atr5) { if ($tol > 0) return abs($a - $b) <= $tol ? 100.0 : 0.0; return $atr5 > 0 ? max(0.0, min(100.0, 100 - abs($a - $b) / $atr5 * 100)) : 0.0; };
    $pb = fn($a, $b) => $pbT($a, $b, $zTol);

    // Fib OTE of the previous day's range: at the ATR Low the pullback is measured down from PDH, at the ATR High up from PDL
    $swH = $pdh; $swL = $pdl; $rg = $swH - $swL;
    $retrDown = $zone == $atrHigh;
    $fibs = []; $fibProx = 0.0; $fibLvl = null;
    foreach ([0.618, 0.705, 0.786] as $lv) {
        $p = $retrDown ? $swL + $rg * $lv : $swH - $rg * $lv;
        $s = round($pb($p, $zone), 2);
        if ($fibLvl === null || $s > $fibProx) { $fibProx = $s; $fibLvl = $p; }
        $fibs[] = ['ratio' => $lv, 'price' => $p, 'score' => $s];
    }
    $bonus4 = $alignment === 4 ? 10.0 : 0.0;
    $fibPts = 15 * $fibProx / 100;

    $wantUp = $zone == $atrLow;   // the side a signal at this zone would take (CALL at the ATR Low, PUT at the ATR High)
    $rsi = t_rsi_last($fr['1H']['c'], 14);
    [$ax, $pdi, $mdi] = t_adx($D, 14);
    $diOk = ($wantUp && $pdi > $mdi) || (!$wantUp && $mdi > $pdi);   // DI in the trade direction
    [$a1] = t_adx_s($fr['1H'], 14); $m1 = count($a1);
    $ax1 = $m1 ? (float)$a1[$m1 - 1] : 0.0; $pk1 = $m1 ? max(array_slice($a1, -10)) : 0.0;
    // Tick 0 is the 30m Liquidity / BB Sweep from 19 Oct 2026 and is produced inside the cycle evaluation for
    // BOTH setups, so the old standalone FVG ("Gap Zone") flag is gone.
    // v8 score (owner, 5 Oct 2026), SAME formula for BOTH setups (replaces trend v7 and retracement v6), max 100:
    //   Pulse Sync 7.5 per agreeing TF (30) | 4/4 bonus 10 | PDL (CALL zone) / PDH (PUT zone) proximity 15 | prev-day Fib OTE proximity 15 |
    //   confluence 10 only when the zone price, that PDL/PDH and a Fib OTE level (0.618 / 0.705 / 0.786) all lie within 0.5 x ATR5 of the zone |
    //   ADX 1H 10: 0 at 25 -> 10 at 40 | RSI 10m 10: CALL 0 at 30 -> 10 at 20; PUT 0 at 70 -> 10 at 80 |
    //   FLY grade bonus A+ +10 / A +5 / B 0. Total capped at 100 (score_v 8).
    $G10 = ($F5 && !empty($F5['t'])) ? t_ten($F5, $dayTs) : null;
    $rsi10 = $G10 && count($G10['c']) >= 15 ? t_rsi_last($G10['c'], 14) : null;
    $scoreV = 8; $trendPts = $alignment * 7.5;
    // owner, 5 Oct 2026 11:54 IST: CALL or PUT, trend or retracement — whichever of PDH / PDL is nearer the zone scores (same proximity)
    $pdX = abs($pdh - $zone) <= abs($pdl - $zone) ? $pdh : $pdl; $pdhlProx = $pb($pdX, $zone); $pdhlNear = $pdX == $pdh ? 'PDH' : 'PDL';
    $pdhlPts = 15 * $pdhlProx / 100;
    $adxPts = 10 * $cl(($ax1 - 25) / 15); $adxState = $ax1 >= 25 ? 'strong-1h' : 'weak-1h';
    $rv = $rsi10 ?? $rsi;
    $rsiPts = 10 * ($wantUp ? $cl((30 - $rv) / 10) : $cl(($rv - 70) / 10));
    $cTol = $zTol > 0 ? $zTol : 0.5 * $atr5;
    $fibIn = false; foreach ($fibs as $fx) if (abs($fx['price'] - $zone) <= $cTol) { $fibIn = true; break; }
    $conf = ($cTol > 0 && abs($pdX - $zone) <= $cTol && $fibIn) ? 10.0 : 0.0;

    // Tier-1 rule (confirmed 25 Sep 2026): 3/4 or 4/4 alignment; bullish only at the ATR Day Low or below it,
    // bearish only at the ATR Day High or above it (within zone_band ATR of the level counts as "at").
    // Everything else is not a signal. The score below only measures strength; it never makes a symbol eligible.
    $inZone = $bias !== 'NEUTRAL' && ($zone == $atrLow ? $atLow : $atHigh);
    $overext = ($zone == $atrLow && $price < $zone - $band) || ($zone == $atrHigh && $price > $zone + $band);
    $signal = $inZone ? ($zone == $atrLow ? 'CALL' : 'PUT') : 'WAIT';
    $setup = $signal === 'WAIT' ? null : ((($signal === 'CALL') === ($bias === 'UP')) ? 'trend' : 'retrace');
    // v10 (owner, 11 Oct 2026): the TREND setup follows torus_v10.php (5-TF bias, new score, FVG + 4 ticks, owner 13 Oct 2026). Retracement untouched here (FVG added below via $fvgFlag).
    // Fibonacci daily pivots + the previous close: used only for the Tier-1 zone group and its remark (owner PDF,
    // 19 Oct 2026) -- they never enter the score.
    $pivR = ($F5 && $bars) ? t_fib_pivots($F5, (int)$dayTs) : null;
    $pdc = $D['c'][$n - 2];
    $v10ctx = ['atr_low' => $atrLow, 'atr_high' => $atrHigh, 'atr5' => $atr5, 'band' => $band,
        'zb' => (float)$k['zone_band'], 'price' => $price, 'atLow' => $atLow, 'atHigh' => $atHigh, 'bars' => $bars,
        'pdh' => $pdh, 'pdl' => $pdl, 'pdc' => $pdc, 'atrd' => (float)$atrD, 'adr' => $adrV, 'near' => $zTol, 'pivots' => $pivR ?: []];
    $v10 = t10_eval($sym, $fr, array_map(fn($v) => (int)$v['dir'], $tfs), $v10ctx);
    $isV10 = $v10 && !empty($v10['trend']) && !empty($v10['valid']);
    if ($isV10) {
        $signal = $v10['sig']; $setup = 'trend'; $zone = $v10['zone10']; $zoneSide = $signal === 'CALL' ? 'ATR Low' : 'ATR High';
        $wantUp = $signal === 'CALL'; $bias = $v10['bias5']; $inZone = true;
    } elseif ($setup === 'trend') { $signal = 'WAIT'; $setup = null; }   // a trend entry now needs the v10 rules
    // Retracement v10 (owner PDF, 19 Oct 2026): the same cycle machinery and the same 5 ticks as trend, taken
    // against the MTF bias. Replaces the old v8 RUNWAY / FLY path. See t_rt_eval() in torus_v10.php.
    $rt = null; $isRt = false;
    if ($setup === 'retrace') {
        // owner, 4 Nov 2026: the location bonus now covers EVERY major strong zone (same set as the Tier-2 Key
        // Zone line) -- PDH/PDL, Fib, Pivot, PDC -- not PDH/PDL alone.
        $majProx = $pdhlProx;
        $fibNearR = null; foreach ($fibs as $fx) if ($fibNearR === null || abs($fx['price'] - $zone) < abs($fibNearR - $zone)) $fibNearR = $fx['price'];
        if ($fibNearR !== null) $majProx = max($majProx, $pb($fibNearR, $zone));
        $pivNearR = null; foreach (($pivR ?: []) as $pv) if ($pivNearR === null || abs((float)$pv - $zone) < abs($pivNearR - $zone)) $pivNearR = (float)$pv;
        if ($pivNearR !== null) $majProx = max($majProx, $pb($pivNearR, $zone));
        if (!empty($pdc)) $majProx = max($majProx, $pb($pdc, $zone));
        $rt = t_rt_eval($sym, $fr, $v10ctx + ['zone' => $zone, 'wantUp' => $wantUp, 'pdX' => $pdX,
            'locFull' => $majProx >= 100.0, 'locHalf' => $majProx > 0, 'conf' => $conf >= 5,
            'alignment' => $alignment, 'fibs' => array_map(fn($f) => $f['price'], $fibs)]);
        $isRt = $rt && !empty($rt['valid']);
        if (!$isRt) { $signal = 'WAIT'; $setup = null; }   // a retracement entry now needs the v10 rules too
    }
    // Mid-Trend v3 (owner, 8 Nov 2026 redesign -- "our easiest strategy, but it never fires").
    // OLD gate (v2, 3 Nov): price first had to clear the Day Low/High ATR band, AND the qualifying zone
    // itself had to sit beyond that band -- two rare conditions stacked on top of the 4h+1d MTF-alignment
    // check and a >=2-level confluence cluster. That compound AND-chain is why it never fired even once.
    // NEW gate: just the owner's own words -- whenever price sits inside the range of a 1H Fibonacci
    // retracement level, PDH/PDL/PDC, a classic pivot level, the weekly high/low or the monthly high/low,
    // in the direction the MTF bias (4h+1d) already favours, that's the alert. No band-clear requirement,
    // no "zone must be beyond the band", no multi-level clustering -- one level within tolerance is enough.
    $wkFrom = max(0, $n - 6); $wkSlH = array_slice($D['h'], $wkFrom, max(0, $n - 1 - $wkFrom)); $wkSlL = array_slice($D['l'], $wkFrom, max(0, $n - 1 - $wkFrom));
    $wH = $wkSlH ? max($wkSlH) : $pdh; $wL = $wkSlL ? min($wkSlL) : $pdl;
    $moFrom = max(0, $n - 22); $moSlH = array_slice($D['h'], $moFrom, max(0, $n - 1 - $moFrom)); $moSlL = array_slice($D['l'], $moFrom, max(0, $n - 1 - $moFrom));
    $mH = $moSlH ? max($moSlH) : $pdh; $mL = $moSlL ? min($moSlL) : $pdl;
    // 1H Fibonacci retracement -- swing high/low of the last 60 CLOSED 1H candles, standard ratios from both ends
    // (which end is "the" swing depends on which way price last moved; keeping both avoids guessing that here).
    $H1 = $fr['1H'] ?? null; $h1n = $H1 ? count($H1['c'] ?? []) : 0; $fibs1h = [];
    if ($h1n > 10) {
        $w0 = max(0, $h1n - 61); $len = $h1n - 1 - $w0;   // excludes the still-forming last 1H candle
        if ($len > 1) {
            $sw1hH = max(array_slice($H1['h'], $w0, $len)); $sw1hL = min(array_slice($H1['l'], $w0, $len));
            if ($sw1hH > $sw1hL) { $rg1h = $sw1hH - $sw1hL; foreach ([0.382, 0.5, 0.618, 0.705, 0.786] as $lv) { $fibs1h[] = $sw1hH - $rg1h * $lv; $fibs1h[] = $sw1hL + $rg1h * $lv; } }
        }
    }
    $mtctx = $v10ctx + ['mt_active' => true, 'zone' => $zone, 'wantUp' => $wantUp,
        'bars' => $bars, 'atr5' => $atr5, 'atr_low' => $atrLow, 'atr_high' => $atrHigh,
        'pivots' => $pivR ?: [], 'fibs' => array_merge(array_map(fn($f) => $f['price'], $fibs), $fibs1h),
        'pdh' => $pdh, 'pdl' => $pdl, 'pdc' => $pdc, 'wh' => $wH, 'wl' => $wL, 'mh' => $mH, 'ml' => $mL];
    $mt = t_mt_eval($sym, $fr, array_map(fn($v) => (int)$v['dir'], $tfs), $mtctx);
    $isMt = $mt && !empty($mt['valid']);
    if ($setup === 'retrace') $bonus4 = 0.0;   // owner, 5 Oct 2026 11:37 IST: 4/4 Pulse Sync bonus only for trend setups
    // Retracement / short target (owner, 5 Oct 2026, 07:05 IST): mirror of trend. Entry, RUNWAY and FLY already follow the trade side
    // ($wantUp), RSI 10m scoring stays by trade side (PUT at the Day High scores RSI > 70, CALL at the Day Low RSI < 30).
    // ADX 1H is inverted: weak trend favours the counter-move. 0 at ADX >= 25 -> 10 at ADX <= 10.
    if ($setup === 'retrace') { $adxPts = 10 * $cl((25 - $ax1) / 15); $adxState = $ax1 >= 25 ? 'strong-1h' : 'weak-1h'; }
    $e = t_ema($D['c'], (int)$k['ema_span']);
    // v8 entry status (BOTH setups, while live price is inside its zone band): FLY (graded A+ / A / B) / RUNWAY / APPROACHING.
    // Closed bars only. "ema" runways need 1m bars: $fr['1m'] (Tier 2 passes its bars) or the 1m cache Tier 2 keeps (t_m1_get);
    // without either the scanner sees "bb" runways only.
    $flow = null; $grade = null; $gradePts = 0.0;
    // v8: the status clears as soon as live price leaves the zone band, even while the 20-minute touch window keeps the signal
    $inNow = $wantUp ? $price <= $atrLow + $band : $price >= $atrHigh - $band;
    if (!$isV10 && !$isRt && $setup !== null && $bars && $inNow) {
        $flow = t_flow_v8($F5, $bars[0], $bars[count($bars) - 1], $wantUp ? 1 : -1, $fr['1m'] ?? t_m1_get($sym), (float)$k['zone_band'], t_now());
        if ($flow && $flow['state'] === 'FLY') { $grade = $flow['grade']; $gradePts = T_GRADE_PTS[$grade] ?? 0.0; }
        if (!$flow) $flow = ['state' => 'APPROACHING'];
    }
    // v9 sweep + reclaim (shared t_fly_events). Flags OFF = shadow: nothing changes, only logged.
    $swX = $flow['sweep'] ?? null;
    [$swSt, $swPts, $swGated] = tsw_rules($setup, $flow['state'] ?? null, $swX, TSW_CFG, $flow['runway_type'] ?? null);
    // owner, 13 Oct 2026: "30m sweep mandatory nahi - gate open hai" -- sweep never gates a retrace entry; pw/bb still flow through as remarks only.
    if ($swGated && $setup !== 'retrace') { $flow = $swSt === 'RUNWAY' ? ['state' => 'RUNWAY', 'time' => $flow['runway_time'] ?? ($flow['time'] ?? null), 'runway_type' => $flow['runway_type'], 'sl' => $flow['sl'] ?? null] : ['state' => 'APPROACHING']; $grade = null; $gradePts = 0.0; }
    $strength = max(0.0, min(100.0, $trendPts + $bonus4 + $pdhlPts + $fibPts + $conf + $adxPts + $rsiPts + $gradePts + $swPts));
    if ($isRt) {
        $strength = (float)$rt['strength'];
        $flow = ['state' => $rt['flow'], 'time' => $rt['flow_at'], 'sl' => $rt['flow_sl']];
        $grade = null; $swX = null;
        $adxState = ((float)$rt['adx30']) >= 25 ? 'strong-30m' : 'weak-30m';
    }
    if ($isV10) { $strength = (float)$v10['strength']; $flow = ['state' => $v10['flow'], 'time' => $v10['flow_at'], 'sl' => $v10['flow_sl']]; $grade = null; $swX = null; }
    // owner, 4 Nov 2026 fix: t_mt_eval() already builds a full standalone signal (sig/strength/flow/ticks/zone
    // group, same shape as v10/rt above) but nothing ever promoted it into $signal/$setup/$strength -- it only
    // ever reached the row as the auxiliary 'mt' field, which t_apply_status/t_view_rows never treat as a live
    // signal (requires $signal !== 'WAIT'). Result: Mid-Trend never ranked or showed on Tier-1 at all, no
    // matter how strong. Wiring it in exactly like v10/rt do, now that it has independently qualified ($isMt).
    if ($isMt) { $signal = $mt['sig']; $setup = 'midtrend'; $strength = (float)$mt['strength']; $flow = ['state' => $mt['flow'], 'time' => $mt['flow_at'], 'sl' => $mt['flow_sl']]; $grade = null; $swX = null; }
    // v9 logging: the same score with the old near rule, and whether the 40 cut-off flipped
    $fibOld = 0.0; foreach ($fibs as $fx) $fibOld = max($fibOld, $pbT($fx['price'], $zone, $zTolOld));
    $cTolO = $zTolOld > 0 ? $zTolOld : 0.5 * $atr5; $fibInO = false; foreach ($fibs as $fx) if (abs($fx['price'] - $zone) <= $cTolO) { $fibInO = true; break; }
    $confO = ($cTolO > 0 && abs($pdX - $zone) <= $cTolO && $fibInO) ? 10.0 : 0.0;
    $strengthOld = max(0.0, min(100.0, $trendPts + $bonus4 + 15 * $pbT($pdX, $zone, $zTolOld) / 100 + 15 * $fibOld / 100 + $confO + $adxPts + $rsiPts + $gradePts + $swPts));
    $nearFlip = tsw_cutoff_change($strengthOld, $strength);
    if ($nearFlip !== null) tsw_log_once('near_log.jsonl', $sym . '|' . gmdate('Y-m-d', $dayTs) . '|' . $nearFlip, ['ts' => t_now(), 'symbol' => $sym,
        'old' => round($strengthOld, 2), 'new' => round($strength, 2), 'change' => $nearFlip, 'near' => $zTol, 'near_old' => $zTolOld, 'adr' => $adrV]);
    if ($setup !== null && ($flow['state'] ?? null) === 'FLY') tsw_log_once('sweep_log.jsonl', $sym . '|' . ($flow['time'] ?? 0), ['ts' => t_now(), 'symbol' => $sym,
        'flow_time' => $flow['time'] ?? null, 'grade' => $grade, 'sl' => $flow['sl'] ?? null] + tsw_log_fields($setup, $swX));
    // The 13 Oct retracement SL buffer is gone: both setups now use 1.5% from the position line (owner, 19 Oct 2026).
    return [
        'symbol' => $sym, 'name' => t_name($sym), 'market' => t_symbols()[$sym] ?? 'Other', 'signal' => $signal, 'rule' => 5, 'setup' => $setup,
        'remark' => $setup === 'trend' ? 'Long target' : ($setup === 'retrace' ? 'Small target' : ($setup === 'midtrend' ? 'Mid-trend target' : null)),
        'strength' => round($strength, 2), 'bias' => $bias, 'alignment' => $alignment, 'tf' => $tfs,
        'zone_touch' => $inZone, 'zone_near' => $zoneSide, 'overextended' => $overext, 'confluence' => $conf >= 5,
        'components' => ['alignment' => round($trendPts, 2), 'alignment_max' => 30.0, 'bonus4' => $bonus4,
            'pdhl' => round($pdhlPts, 2), 'fib' => round($fibPts, 2), 'confluence' => round($conf, 2),
            'adx' => round($adxPts, 2), 'rsi' => round($rsiPts, 2), 'grade' => $gradePts, 'adx_state' => $adxState, 'score_v' => $scoreV,
            'near_v' => 9, 'near' => $zTol, 'near_old' => $zTolOld, 'adr' => $adrV, 'near_fallback' => $nearFb, 'strength_old' => round($strengthOld, 2), 'cutoff_change' => $nearFlip,
            'sweep_pts' => $swPts, 'sweep_gated' => $swGated, 'sweep' => $swX,
            'v10' => $isV10 ? $v10['parts'] + ['stoch30' => $v10['stoch30'], 'rsi5' => $v10['rsi5'], 'adx30' => $v10['adx30'], 'al5' => $v10['al5']] : null,
            'rt' => $isRt ? $rt['parts'] + ['stoch30' => $rt['stoch30'], 'adx30' => $rt['adx30']] : null],
        'flow' => $flow['state'] ?? null, 'flow_at' => $flow['time'] ?? null, 'flow_sl' => $flow['sl'] ?? null,
        'refly' => $isV10 && !empty($v10['refly']) ? 1 : 0,   // Re-Fly (owner, 4 Nov 2026): fresh entry after a retest
        // v9 (owner, 6 Oct 2026): high-conviction = trend FLY with a sweep + reclaim; public as a bare flag (bright FLY chip + bolt), never the reason
        'hc' => !$isV10 && $setup === 'trend' && ($flow['state'] ?? null) === 'FLY' && $swX ? 1 : 0,
        'tk' => $isV10 ? $v10['tk'] : ($isRt ? $rt['tk'] : ($isMt ? $mt['tk'] : null)), 'tk_at' => $isV10 ? ($v10['tk_at'] ?? null) : ($isRt ? ($rt['tk_at'] ?? null) : null), 'pw' => $isV10 ? (int)$v10['pw'] : ($isRt ? (int)$rt['pw'] : ($isMt ? (int)$mt['pw'] : 0)),
        'al5' => $v10['al5'] ?? ($mt['al5'] ?? null), 'v10' => ($isV10 || $isRt || $isMt) ? 1 : 0,
        // Exceptional 2/4 split (owner, 4 Nov 2026): 1H+4H clean-blocked against 1D+1W. Backtest-validated --
        // lets this specific split rank on Tier-1 even though alignment is only 2/4 (t_tier1() below allows it
        // through). Frontend shows a distinct "2/4" badge on these rows so they're never mistaken for a normal 3/4+.
        'split24' => $isV10 && !empty($v10['split24']) ? 1 : 0,
        // Background 30m power check (owner, 4 Nov 2026): on the NORMAL 3/4-or-4/4 trend setup, when the 30m
        // Supertrend is still running counter to the bias at the Day extreme, backtest shows that's a stronger
        // entry. Pure background signal -- the 30m value itself is never shown, only this flag (same "POWER"
        // badge as split24, but split24 rows never also carry pw30 -- see t10_eval()).
        'pw30' => $isV10 && !empty($v10['pw30']) ? 1 : 0,
        'lad' => ($isV10 || $isRt || $isMt) ? 10 : 8, 'sl_wide' => $isV10 ? (int)($v10['sl_wide'] ?? 0) : ($isRt ? (int)($rt['sl_wide'] ?? 0) : ($isMt ? (int)($mt['sl_wide'] ?? 0) : 0)),
        'go' => $isV10 ? (int)($v10['go'] ?? 0) : ($isRt ? (int)$rt['go'] : ($isMt ? (int)($mt['go'] ?? 0) : ((!empty($swX) || ($flow['runway_type'] ?? null) === 'bb') ? 1 : 0))),
        'mt' => $isMt ? ['strength' => $mt['strength'], 'sig' => $mt['sig'], 'flow' => $mt['flow'],
            'tk' => $mt['tk'], 'go' => (int)($mt['go'] ?? 0), 'mt_zone_cnt' => $mt['mt_zone_cnt'],
            'parts' => $mt['parts'], 'al5' => $mt['al5']] : null,
        // tick-0 remark ('liq' / 'bb' in trend, GATE OPEN in retracement) and the Tier-1 zone group + its label
        't0' => $isV10 ? ($v10['t0'] ?? null) : ($isRt ? ($rt['t0'] ?? null) : ($isMt ? ($mt['t0'] ?? null) : null)),
        'zg' => $isV10 ? (int)($v10['zg'] ?? 2) : ($isRt ? (int)($rt['zg'] ?? 2) : ($isMt ? (int)($mt['zg'] ?? 2) : 2)),
        'zlv' => $isV10 ? ($v10['zlv'] ?? null) : ($isRt ? ($rt['zlv'] ?? null) : ($isMt ? ($mt['zlv'] ?? null) : null)),
        'grade' => $grade, 'runway_type' => $flow['runway_type'] ?? null, 'rsi_10m' => $rsi10 === null ? null : round($rsi10, 2),
        'adx_value' => round($ax, 2), 'di_plus' => round($pdi, 2), 'di_minus' => round($mdi, 2), 'di_ok' => $diOk,
        'adx_1h' => round($ax1, 2), 'adx_1h_peak' => round((float)$pk1, 2),
        'rsi' => round($rsi, 2),
        'price' => $last, 'change_pct' => round(($D['c'][$n - 1] / $D['c'][$n - 2] - 1) * 100, 3),
        'day_high' => $pdh, 'day_low' => $pdl, 'today_open' => $tOpen, 'today_high' => $tHigh, 'today_low' => $tLow,
        'atr' => $atrD, 'atr_pct' => round($atrD / $price * 100, 3), 'atr_low' => $atrLow, 'atr_high' => $atrHigh,
        // free Big Movers panel (public, no Tier-1 logic): today's move and range measured in daily ATRs
        // Day move label on Tier 1 (owner, 10 Nov 2026): how much of the daily ATR the day has used from the previous
        // close, on the side this row trades (its signal, else its bias). Same number the Trend / Mid-Trend gate checks.
        'mv' => (function () use ($signal, $bias, $atrHigh, $atrLow, $pdc, $atrD) {
            $sgM = $signal === 'CALL' ? 1 : ($signal === 'PUT' ? -1 : ($bias === 'UP' ? 1 : ($bias === 'DOWN' ? -1 : 0)));
            $u = $sgM ? t10_move_used((float)$atrHigh, (float)$atrLow, (float)$pdc, (float)$atrD, $sgM) : null;
            return $u === null ? null : (int)round($u * 100);
        })(),
        'move_atr' => $atrD > 0 ? round(abs($last - $D['c'][$n - 2]) / $atrD, 2) : null,
        'range_atr' => $atrD > 0 ? round(($atrHigh - $atrLow) / $atrD, 2) : null,
        'range_pct' => $price > 0 ? round(($atrHigh - $atrLow) / $price * 100, 3) : null,
        'range_pos' => $atrHigh > $atrLow ? round(($price - $atrLow) / ($atrHigh - $atrLow), 3) : null,
        'zone' => $zone, 'zone_band_px' => $band, 'atr5' => $atr5, 'zone_dist_atr' => $atr5 > 0 ? round(($price - $zone) / $atr5, 3) : 0.0,
        'pdhl_near' => $pdhlNear, 'pdhl_prox' => round($pdhlProx, 1), 'fib_prox' => round($fibProx, 1),
        'swing_high' => $swH, 'swing_low' => $swL, 'fib_levels' => $fibs,
        'ema' => $e[$n - 1], 'ema_span' => (int)$k['ema_span'],
        'time_ist' => t_ist(time(), 'H:i'), 'bar_date' => gmdate('Y-m-d', $dayTs),
    ];
}

function t_stats(array $fr): array {
    $D = $fr['1D']; $c = $D['c']; $n = count($c);
    $ret = function ($k) use ($c, $n) { return $n > $k && $c[$n - 1 - $k] > 0 ? round(($c[$n - 1] / $c[$n - 1 - $k] - 1) * 100, 2) : null; };
    $lr = []; for ($i = max(1, $n - 20); $i < $n; $i++) if ($c[$i] > 0 && $c[$i - 1] > 0) $lr[] = log($c[$i] / $c[$i - 1]);
    $mu = array_sum($lr) / max(count($lr), 1); $var = 0; foreach ($lr as $x) $var += ($x - $mu) ** 2;
    $vol = sqrt($var / max(count($lr), 1)) * sqrt(252) * 100;
    $yr = min(252, $n); $hi = max(array_slice($D['h'], -$yr)); $lo = min(array_slice($D['l'], -$yr));
    [$d] = t_supertrend($D); $d = array_slice($d, -$yr); $fl = 0; $upc = 0;
    foreach ($d as $i => $x) { if ($i && $x !== $d[$i - 1]) $fl++; if ($x === 1) $upc++; }
    return [
        'ret_1w' => $ret(5), 'ret_1m' => $ret(21), 'ret_3m' => $ret(63), 'ret_1y' => $ret(252),
        'vol_20d' => round($vol, 2), 'hi_52w' => $hi, 'lo_52w' => $lo,
        'from_hi_pct' => $hi > 0 ? round(($c[$n - 1] / $hi - 1) * 100, 2) : null, 'from_lo_pct' => $lo > 0 ? round(($c[$n - 1] / $lo - 1) * 100, 2) : null,
        'st_flips_1y' => $fl, 'uptrend_share_1y' => round($upc / max(count($d), 1) * 100, 1),
        'spark' => array_map(fn($x) => t_r((float)$x), array_slice($c, -30)),
    ];
}

function t_fp($p): string {
    if ($p === null) return '-';
    if ($p >= 1000) return number_format($p, 2);
    if ($p >= 100) return number_format($p, 2, '.', '');
    if ($p >= 1) {   // 2 decimals for stocks, up to 4 where the market quotes them (forex, USD/INR, gas)
        $s = rtrim(number_format($p, 4, '.', ''), '0');
        return strlen($s) - strpos($s, '.') - 1 >= 2 ? $s : number_format($p, 2, '.', '');
    }
    if ($p >= 0.01) return number_format($p, 4, '.', '');
    return (string)t_r((float)sprintf('%.4g', $p));
}
/** Public names (owner, 29 Sep 2026): the rule's building blocks never appear under their technical names outside the server. */
const T_PULSE = ['ATR Low' => 'Pulse Floor', 'ATR High' => 'Pulse Ceiling'];
function t_pub_zone(?string $z): ?string { return $z === null ? null : (T_PULSE[$z] ?? $z); }
function t_pub_setup(?string $s): ?string { return $s === null ? null : ($s === 'trend' ? 'long' : ($s === 'midtrend' ? 'midtrend' : 'short')); }
function t_pub_level(string $k): string { return $k === 'PP' ? 'P0' : (str_starts_with($k, 'R') ? 'U' . substr($k, 1) : (str_starts_with($k, 'S') ? 'D' . substr($k, 1) : $k)); }
/** Public symbol brief: describes the state, never the rule's formulas or weights. */
function t_symbol_brief(array $r, ?array $s): array {
    $L = []; $px = (float)$r['price'];
    if ($r['bias'] === 'NEUTRAL') {
        $L[] = 'Pulse Sync is split, so there is no bias and the symbol stays out of the Tier-1 scanner.';
    } else {
        $word = $r['bias'] === 'UP' ? 'bullish' : 'bearish';
        $L[] = $r['alignment'] === 4 ? "Full Pulse Sync: $word." : "Bias is $word with Pulse Sync {$r['alignment']}/4.";
    }
    $side = t_pub_zone($r['zone_near']); $z = (float)$r['zone'];
    if ($z > 0 && $px > 0) $L[] = sprintf('%s is at %s; price is %.2f%% %s it.', $side, t_fp($z), abs($px / $z - 1) * 100, $px >= $z ? 'above' : 'below');
    if ($s) $L[] = sprintf('20-day volatility %.1f%% annualised; %.1f%% from the 52-week high.', $s['vol_20d'], $s['from_hi_pct']);
    if ($r['bias'] === 'NEUTRAL') $head = 'Not in the Tier-1 scanner: Pulse Sync is split.';
    elseif (isset($r['live']) && !$r['live']) $head = sprintf('%s. Not in the Tier-1 scanner while the market is not live; it rejoins at the next session.', T_STATUS_TEXT[$r['status']] ?? 'Market closed');
    elseif ($r['signal'] !== 'WAIT') $head = sprintf('%s signal at the %s%s. Strength %.0f/100.', $r['signal'], $side,
        ($r['remark'] ?? '') ? ' (' . strtolower($r['remark']) . ')' : '', $r['strength']);
    else $head = sprintf('UNDER PROCESS: %s bias. Waiting for price to reach its Pulse zone (nearest: %s).', $r['bias'] === 'UP' ? 'bullish' : 'bearish', $side);
    $conf = $r['strength'] >= 70 ? 'High' : ($r['strength'] >= 50 ? 'Medium' : 'Low');
    return ['headline' => $head, 'points' => $L, 'confidence' => $conf];
}
/**
 * Strip the rule's internals before anything leaves the server (owner, 29 Sep 2026: the strategy runs only here).
 * Out: final outputs only. Indicator values (RSI, ADX), previous-day levels, per-timeframe names, zone math and score parts stay in PHP.
 */
/**
 * Public entry-ticks array for a row (shared by t_public and Tier 2's t2_build, so both always agree, runbook
 * rule "Tier 1 and Tier 2 must always show the same signals"). Owner, 19 Oct 2026: BOTH setups now carry the
 * same 5 ticks -- 0 = the 30m Liquidity / BB Sweep, then Early Entry, Entry A, Trend Flip, Double Confirm.
 * The 4-element branch below is only for rows stored by the older engine, before retracement moved onto v10.
 */
function t_ph_build(array $r): array {
    $live = ($r['signal'] ?? 'WAIT') !== 'WAIT';
    // Owner, 20 Oct 2026 (updated 3 Nov 2026: 5 elements): one gate + 4 ticks -- [gate, Early Entry,
    // Entry A, Reversal Pat (5m), Double Confirm (10m/FLY)]. Pre-3Nov rows have 4 elements; pad with 0.
    if (!empty($r['v10'])) {
        if (!$live || !is_array($r['tk'] ?? null)) return [0, 0, 0, 0, 0];
        $t = array_map('intval', $r['tk']);
        while (count($t) < 5) $t[] = 0;   // back-compat: old 4-element rows get a zero tick4
        return $t;
    }
    $rt = $r['runway_type'] ?? null; $gr = $r['grade'] ?? null; $fl = $r['flow'] ?? null; $fvgOn = !empty($r['fvg']) ? 1 : 0;
    return $live ? [$fvgOn, ($rt === 'ema' || $gr === 'A') ? 1 : 0, ($rt === 'bb' || $gr === 'A+') ? 1 : 0, $fl === 'FLY' ? 1 : 0] : [0, 0, 0, 0];
}
/**
 * The unix timestamp each tick in t_ph_build() actually fired at (0 = not fired), so the UI can show "when"
 * next to a checked tick instead of asking the user to trust an opaque checkmark (owner, 4 Nov 2026 -- repeat
 * user reports that a tick looked "checked but never happened"; this makes the evidence visible, not the logic).
 * Only meaningful for v10 rows, since the older 4-tick branch never stored per-tick timestamps.
 */
function t_ph_times(array $r): array {
    $live = ($r['signal'] ?? 'WAIT') !== 'WAIT';
    if (!empty($r['v10']) && $live && is_array($r['tk_at'] ?? null)) {
        $t = array_map('intval', $r['tk_at']);
        while (count($t) < 5) $t[] = 0;
        return $t;
    }
    return [0, 0, 0, 0, 0];
}
function t_public(array $r): array {
    // owner, 5 Oct 2026: entry phases shown on Tier 2 as ticks only (Phase 1 / 2 / 3), never the rule behind them
    $live = ($r['signal'] ?? 'WAIT') !== 'WAIT';
    $r['ph'] = t_ph_build($r);
    $r['pht'] = t_ph_times($r);
    $r['lad'] = !empty($r['v10']) ? 10 : 8;
    $r['sl_wide'] = $live ? (int)($r['sl_wide'] ?? 0) : 0;
    $r['pw'] = $live ? (int)($r['pw'] ?? 0) : 0;
    $r['go'] = $live ? (int)($r['go'] ?? 0) : 0;
    // Tick-0 remark and the Tier-1 zone group (owner, 19 Oct 2026). 't0' is published as the public wording only:
    // trend says which sweep fired, retracement always says GATE OPEN. The rule behind it never leaves the server.
    // Owner, 20 Oct 2026: both setups now name the gate that actually fired. 'both' = the Liquidity Sweep and
    // the BB Sweep landed on the same candle, which is ONE gate with a "double" grade, never two.
    $t0 = $live ? ($r['t0'] ?? null) : null;
    $r['t0'] = $t0 === null ? null : ($t0 === 'bb' ? 'BB Sweep' : ($t0 === 'both' ? 'Sweep + BB' : 'Liquidity Sweep'));
    $r['zg'] = $live ? (int)($r['zg'] ?? 2) : 2;
    $r['zlv'] = $live ? ($r['zlv'] ?? null) : null;
    foreach (['components', 'rule', 'zone_touch', 'zone_band_px', 'zone_dist_atr', 'pdhl_near', 'pdhl_prox', 'fib_prox', 'fib_levels', 'swing_high', 'swing_low',
        'ema', 'ema_span', 'atr5', 'di_plus', 'di_minus', 'di_ok', 'adx_1h', 'adx_1h_peak', 'overextended', 'confluence', 'atr', 'mig', 'full', 'day_ok', 'cs', 'ce', 'rmt', 'time_ist', 'ukey',
        'adx_value', 'rsi', 'rsi_10m', 'flow_sl', 'refly', 'sticky', 'al5', 'tk', 'gc_until', 'runway_type', 'day_high', 'day_low', 'today_open', 'today_high', 'today_low', 'bar_date', 'fvg'] as $k) unset($r[$k]);
    if (array_key_exists('atr_low', $r)) { $r['floor'] = $r['atr_low']; unset($r['atr_low']); }
    if (array_key_exists('atr_high', $r)) { $r['ceiling'] = $r['atr_high']; unset($r['atr_high']); }
    if (isset($r['zone_near'])) $r['zone_near'] = t_pub_zone($r['zone_near']);
    if (array_key_exists('setup', $r)) $r['setup'] = t_pub_setup($r['setup']);
    // Pulse layers: the four trend readings, unnamed
    if (isset($r['tf']) && is_array($r['tf'])) $r['layers'] = array_values(array_map(fn($v) => is_array($v) ? (int)($v['dir'] ?? 0) : (int)$v, $r['tf']));
    unset($r['tf']);
    return $r;
}
function t_market_brief(array $rows): array {
    $n = count($rows);
    if (!$n) return ['headline' => 'Waiting for the first scan.', 'points' => [], 'regime' => '-'];
    $liveRows = array_values(array_filter($rows, fn($r) => !empty($r['live'])));
    $liveMk = count(array_unique(array_map(fn($r) => $r['ex'] ?? '', $liveRows)));
    $calls = array_values(array_filter($rows, fn($r) => $r['signal'] === 'CALL'));
    $puts = array_values(array_filter($rows, fn($r) => $r['signal'] === 'PUT'));
    $up = count(array_filter($rows, fn($r) => $r['bias'] === 'UP')); $br = $up / $n * 100;
    $regime = $br >= 60 ? 'Risk-on' : ($br <= 40 ? 'Risk-off' : 'Mixed');
    $g = []; foreach ($rows as $r) $g[$r['market']][] = $r;
    $mk = []; foreach ($g as $m => $v) $mk[$m] = count(array_filter($v, fn($r) => $r['bias'] === 'UP')) / count($v) * 100;
    arsort($mk); $mkK = array_keys($mk);
    $top = function ($a) { usort($a, fn($x, $y) => $y['strength'] <=> $x['strength']); return implode(', ', array_map(fn($r) => sprintf('%s (%.0f)', $r['name'], $r['strength']), array_slice($a, 0, 3))); };
    $pts = [
        sprintf('%d of %d instruments have an upward bias (%.0f%% breadth).', $up, $n, $br),
        sprintf('Strongest market: %s (%.0f%% up). Weakest: %s (%.0f%% up).', $mkK[0], $mk[$mkK[0]], end($mkK), $mk[end($mkK)]),
        sprintf('%d of %d instruments are live right now on %d open venues; closed markets never carry a signal.', count($liveRows), $n, $liveMk),
        sprintf('%d CALL and %d PUT signals are live. %d more are UNDER PROCESS, waiting for their Pulse zone.', count($calls), count($puts), count(array_filter($rows, fn($r) => ($r['tier1'] ?? '') === 'queue'))),
        sprintf('%d instruments have a split Pulse Sync and stay out of the Tier-1 scanner.', count(array_filter($rows, fn($r) => ($r['bias'] ?? '') === 'NEUTRAL'))),
    ];
    if ($calls) $pts[] = 'Top CALL: ' . $top($calls) . '.';
    if ($puts) $pts[] = 'Top PUT: ' . $top($puts) . '.';
    return ['headline' => sprintf('%s tape. %d live signals, %d under process, among %d live instruments.', $regime, count($calls) + count($puts),
        count(array_filter($rows, fn($r) => ($r['tier1'] ?? '') === 'queue')), count($liveRows)),
        'points' => $pts, 'regime' => $regime, 'breadth' => round($br, 1)];
}
function t_candles(array $fr, string $tf, array $knobs = [], int $L = 260): array {
    $k = array_merge(T_DEFAULT_KNOBS, $knobs); $f = $fr[$tf];
    [$d, $line] = t_supertrend($f, (int)$k['st_period'], (float)$k['atr_multiplier']);
    $e = t_ema($f['c'], (int)$k['ema_span']);
    $sl = fn($a) => array_values(array_slice($a, -$L));
    return ['t' => $sl($f['t']), 'o' => $sl($f['o']), 'h' => $sl($f['h']), 'l' => $sl($f['l']), 'c' => $sl($f['c']), 'v' => $sl($f['v']),
        'st' => $sl($line), 'dir' => $sl($d), 'ema' => $sl($e)];
}

// ---------------------------------------------------------------------------
// Rolling scanner (driven by page polls and/or cron).
// Every step refreshes the most urgent symbols: live markets every 4-10 min,
// markets that just opened immediately, closed markets every 4 h.
// ---------------------------------------------------------------------------
/** 5-minute bars for the 10-minute views (analysis chart, Tier 2): Yahoo 5d/5m, cached 45 s per symbol. */
function t_m5(string $sym): ?array {
    $dir = T_DATA . '/m5'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $p = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym) . '.json';
    $c = t_read_json($p, null);
    if (is_array($c) && t_now() - (int)($c['_at'] ?? 0) < 45 && !empty($c['t'])) { unset($c['_at']); return $c; }
    [$f5] = t_parse(t_fetch(['m5' => t_url($sym, '5d', '5m')])['m5'] ?? null);
    if (!$f5 || count($f5['t']) < 20) return is_array($c) && !empty($c['t']) ? (function ($c) { unset($c['_at']); return $c; })($c) : null;
    t_write_json($p, $f5 + ['_at' => t_now()]);
    return $f5;
}
/** 10-minute candles from 5-minute bars, anchored to the session open ($dayTs). */
function t_ten(array $F5, int $dayTs): array { $off = $dayTs % 600; return t_group($F5, fn($t) => intdiv($t - $off, 600)); }
/** Bollinger Bands (period, k x population stdev) on closes: [mid[], up[], lo[]] (null before $p bars). */
function t_bb(array $c, int $p = 20, float $k = 2.0): array {
    $m = []; $u = []; $l = []; $n = count($c);
    for ($i = 0; $i < $n; $i++) {
        if ($i < $p - 1) { $m[] = null; $u[] = null; $l[] = null; continue; }
        $w = array_slice($c, $i - $p + 1, $p); $mu = array_sum($w) / $p; $v = 0.0; foreach ($w as $x) $v += ($x - $mu) ** 2; $sd = sqrt($v / $p);
        $m[] = $mu; $u[] = $mu + $k * $sd; $l[] = $mu - $k * $sd;
    }
    return [$m, $u, $l];
}
// ---------------------------------------------------------------------------
// Strategy v8 (owner, 5 Oct 2026): one shared entry engine for Tier 1 and Tier 2, BOTH setups
// (trend / long target and retracement / short target). CALL = bullish patterns at today's Day Low zone,
// PUT = bearish patterns at today's Day High zone. Everything below works on CLOSED bars only.
// ---------------------------------------------------------------------------
/**
 * Shared reversal-pattern list (v8). Bar $i of $G, side $sg (+1 bullish for CALL, -1 bearish for PUT). Pattern name or null.
 * Zero-range bars never form a pattern (multi-bar patterns need every bar to have a range). No standalone Doji.
 * "avg body" = mean body of up to 10 bars before $i (0.5 x mean range when every body is 0).
 *  Three-bar (checked first):
 *   Abandoned Baby        i-2 body >= avg against the trade; i-1 doji (body <= 10% range) gapped beyond i-2 (no wick overlap);
 *                         i in the trade direction gapped back (no wick overlap with i-1).
 *   Morning / Evening Star  i-2 body >= avg against the trade; i-1 small body (<= 50% of i-2's and <= avg);
 *                         i in the trade direction closing beyond the midpoint of i-2's body.
 *   Three White Soldiers / Three Black Crows  three bars in the trade direction, each close beyond the previous close,
 *                         each open inside the previous body, each body >= 50% avg.
 *  Two-bar:
 *   Engulfing             i-1 against the trade, i in the trade direction, i's body covers i-1's body and is larger.
 *   Piercing Line / Dark Cloud Cover  i-1 body >= avg against the trade; i opens at/beyond i-1's close and closes past the
 *                         midpoint of i-1's body but not past its open.
 *   Harami                i-1 body >= avg against the trade; i in the trade direction with a smaller body strictly inside i-1's body.
 *   Tweezer Bottom / Top  i-1 against the trade, i in the trade direction, lows (tops: highs) equal within 5% of the larger range.
 *  One-bar:
 *   Hammer (CALL) / Hanging Man (PUT)           lower wick >= 2 x body, upper wick <= 10% range, body >= 5% range.
 *   Inverted Hammer (CALL) / Shooting Star (PUT)  upper wick >= 2 x body, lower wick <= 10% range, body >= 5% range.
 *   Pin bar               tail against the trade >= 2/3 of the range; open and close in the opposite third.
 */
function t_reversal(array $G, int $i, int $sg): ?string {
    if ($i < 0 || !isset($G['c'][$i])) return null;
    $O = fn($k) => (float)$G['o'][$k]; $C = fn($k) => (float)$G['c'][$k]; $H = fn($k) => (float)$G['h'][$k]; $L = fn($k) => (float)$G['l'][$k];
    $rg = fn($k) => $H($k) - $L($k); $bd = fn($k) => abs($C($k) - $O($k));
    $dirOf = fn($k) => $C($k) > $O($k) ? 1 : ($C($k) < $O($k) ? -1 : 0);
    if ($rg($i) <= 0) return null;
    $sb = 0.0; $sr = 0.0; $m = 0; for ($k = max(0, $i - 10); $k < $i; $k++) { $sb += $bd($k); $sr += $rg($k); $m++; }
    $avgB = $m ? ($sb > 0 ? $sb / $m : 0.5 * $sr / $m) : $bd($i);
    // QUALITY GATE (4 Nov 2026): the pattern candle must be meaningfully sized (>= 0.6 x average range of the
    // previous 10 bars) and must come AFTER a move against the signal (close of i-1 vs close of i-4).
    // Without this, tiny doji/hammer-like candles produced constant 5m and 10m false reversal ticks.
    $avgR = $m ? $sr / $m : $rg($i);
    if ($rg($i) < 0.6 * $avgR || $i < 4) return null;
    $pm = $C($i - 1) - $C($i - 4);
    if ($sg > 0 ? !($pm < 0) : !($pm > 0)) return null;
    $bull = $sg > 0;
    if ($i >= 2 && $rg($i - 1) > 0 && $rg($i - 2) > 0) {   // three-bar
        $a = $i - 2; $b = $i - 1;
        if ($dirOf($a) === -$sg && $bd($a) >= $avgB && $bd($b) <= 0.1 * $rg($b) && $dirOf($i) === $sg
            && ($bull ? ($H($b) < $L($a) && $L($i) > $H($b)) : ($L($b) > $H($a) && $H($i) < $L($b)))) return 'Abandoned Baby';
        $midA = ($O($a) + $C($a)) / 2;
        if ($dirOf($a) === -$sg && $bd($a) >= $avgB && $bd($b) <= 0.5 * $bd($a) && $bd($b) <= $avgB && $dirOf($i) === $sg
            && ($bull ? $C($i) > $midA : $C($i) < $midA)) return $bull ? 'Morning Star' : 'Evening Star';
        if ($dirOf($a) === $sg && $dirOf($b) === $sg && $dirOf($i) === $sg && min($bd($a), $bd($b), $bd($i)) >= 0.5 * $avgB
            && ($bull ? ($C($b) > $C($a) && $C($i) > $C($b)) : ($C($b) < $C($a) && $C($i) < $C($b)))
            && $O($b) >= min($O($a), $C($a)) && $O($b) <= max($O($a), $C($a)) && $O($i) >= min($O($b), $C($b)) && $O($i) <= max($O($b), $C($b)))
            return $bull ? 'Three White Soldiers' : 'Three Black Crows';
    }
    if ($i >= 1 && $rg($i - 1) > 0) {   // two-bar
        $p = $i - 1; $pHi = max($O($p), $C($p)); $pLo = min($O($p), $C($p)); $mid = ($O($p) + $C($p)) / 2;
        if ($dirOf($p) === -$sg && $dirOf($i) === $sg && $bd($i) > $bd($p) && max($O($i), $C($i)) >= $pHi && min($O($i), $C($i)) <= $pLo) return 'Engulfing';
        if ($dirOf($p) === -$sg && $dirOf($i) === $sg && $bd($p) >= $avgB) {
            if ($bull && $O($i) <= $C($p) && $C($i) > $mid && $C($i) < $O($p)) return 'Piercing Line';
            if (!$bull && $O($i) >= $C($p) && $C($i) < $mid && $C($i) > $O($p)) return 'Dark Cloud Cover';
            if ($bd($i) < $bd($p) && max($O($i), $C($i)) < $pHi && min($O($i), $C($i)) > $pLo) return 'Harami';
        }
        if ($dirOf($p) === -$sg && $dirOf($i) === $sg && ($bull ? abs($L($i) - $L($p)) : abs($H($i) - $H($p))) <= 0.05 * max($rg($i), $rg($p)))
            return $bull ? 'Tweezer Bottom' : 'Tweezer Top';
    }
    $r = $rg($i); $b0 = $bd($i); $up = $H($i) - max($O($i), $C($i)); $dn = min($O($i), $C($i)) - $L($i);   // one-bar
    if ($b0 >= 0.05 * $r && $dn >= 2 * $b0 && $up <= 0.1 * $r) return $bull ? 'Hammer' : 'Hanging Man';
    if ($b0 >= 0.05 * $r && $up >= 2 * $b0 && $dn <= 0.1 * $r) return $bull ? 'Inverted Hammer' : 'Shooting Star';
    if ($bull ? ($dn >= 2 / 3 * $r && min($O($i), $C($i)) >= $L($i) + 2 / 3 * $r) : ($up >= 2 / 3 * $r && max($O($i), $C($i)) <= $H($i) - 2 / 3 * $r)) return 'Pin bar';
    return null;
}
/** Shared 10-minute builder (v8, Tier 1 and Tier 2): pairs of 5m bars [$a..$b] anchored to the session's first 5m bar $a.
 *  Only complete pairs. 'k' = index (in $F) of the second 5m bar of each 10m bar. */
function t_ten_session(array $F, int $a, int $b): array {
    $G = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'k' => []];
    for ($i = $a; $i + 1 <= $b; $i += 2) { $j = $i + 1;
        $G['t'][] = (int)$F['t'][$i]; $G['o'][] = (float)$F['o'][$i]; $G['h'][] = max((float)$F['h'][$i], (float)$F['h'][$j]);
        $G['l'][] = min((float)$F['l'][$i], (float)$F['l'][$j]); $G['c'][] = (float)$F['c'][$j]; $G['k'][] = $j; }
    return $G;
}
/** FLY confirmation: the 10m bar after the pattern closes beyond the pattern close AND beyond its own open, in the signal direction. */
function t_fly_confirm(array $G, int $j, int $sg): bool {
    if (!isset($G['c'][$j + 1])) return false; $c1 = (float)$G['c'][$j + 1];
    return $sg > 0 ? ($c1 > $G['c'][$j] && $c1 > $G['o'][$j + 1]) : ($c1 < $G['c'][$j] && $c1 < $G['o'][$j + 1]);
}
/** 3-minute bars resampled from 1-minute bars, only those closed by $now (null when fewer than 2). */
function t_three(?array $M1, int $now): ?array {
    if (!$M1 || empty($M1['t'])) return null;
    $F3 = t_resample($M1, 180); $n = count($F3['t']); while ($n > 0 && (int)$F3['t'][$n - 1] + 180 > $now) $n--;
    if ($n < 2) return null;
    foreach ($F3 as $k => $v) if (is_array($v)) $F3[$k] = array_slice($v, 0, $n);
    return $F3;
}
/** 1-minute bar cache shared with Tier 2 (Tier 2 fetches 1m bars; the Tier-1 scanner reuses them for "ema" runways). */
function t_m1_path(string $sym): string { return T_DATA . '/m1/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym) . '.json'; }
function t_m1_put(string $sym, ?array $f1): void {
    if (!$f1 || empty($f1['t'])) return; $d = T_DATA . '/m1'; if (!is_dir($d)) @mkdir($d, 0755, true);
    $keep = []; foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $k) $keep[$k] = array_slice($f1[$k] ?? array_fill(0, count($f1['t']), 0), -1500);
    @t_write_json(t_m1_path($sym), $keep + ['_at' => t_now()]);
}
function t_m1_get(string $sym, int $maxAge = 900): ?array {
    $c = t_read_json(t_m1_path($sym), null);
    if (!is_array($c) || empty($c['t']) || t_now() - (int)($c['_at'] ?? 0) > $maxAge) return null;
    unset($c['_at']); return $c;
}
/**
 * v8 RUNWAY (not mandatory, no time window). Only condition: the event happens near the zone, i.e. within $zb x ATR5 of today's
 * running Day Low (CALL) / Day High (PUT) at that moment. Closed bars only: events whose bar closed by $upto.
 *  "bb":  a closed 5m candle's wick pierces Bollinger Bands (20, 2) on 5m closes (lower band for CALL, upper for PUT).
 *         Void (stop hunt) when a later closed 5m bar trades through the wick -/+ 0.3 x ATR5.
 *  "ema": on closed 3m bars (from 1m), the close crosses the 5-EMA in the signal direction.
 *         Void when a later closed 3m bar trades through the Day extreme at the cross -/+ 0.3 x ATR5.
 * $F = 5m bars (earlier days feed the bands), [$a..$b] = today's session, $A5 = ATR(14) on $F, $F3 = 3m bars or null.
 * Returns ['bb' => latest valid event|null, 'ema' => latest valid event|null]; event = ['type', 'time' (bar close), 'x', 'void', 'atr'].
 */
function t_runway_v8(array $F, int $a, int $b, int $sg, array $A5, float $zb, ?array $F3, int $upto): array {
    $out = ['bb' => null, 'ema' => null];
    $b = min($b, count($F['t']) - 1); while ($b >= $a && (int)$F['t'][$b] + 300 > $upto) $b--;
    if ($b < $a) return $out;
    [, $U, $Lo] = t_bb(array_map('floatval', array_slice($F['c'], 0, $b + 1)), 20, 2.0);
    $ev = []; $ext = $sg > 0 ? INF : -INF;
    for ($i = $a; $i <= $b; $i++) {
        $ext = $sg > 0 ? min($ext, (float)$F['l'][$i]) : max($ext, (float)$F['h'][$i]); $at = (float)$A5[$i];
        $pierce = $sg > 0 ? ($Lo[$i] !== null && $F['l'][$i] < $Lo[$i]) : ($U[$i] !== null && $F['h'][$i] > $U[$i]);
        $near = $sg > 0 ? ((float)$F['l'][$i] - $ext <= $zb * $at) : ($ext - (float)$F['h'][$i] <= $zb * $at);
        if ($pierce && $near) $ev[] = ['i' => $i, 'x' => $sg > 0 ? (float)$F['l'][$i] : (float)$F['h'][$i]];
    }
    for ($q = count($ev) - 1; $q >= 0; $q--) {   // most recent event that no later closed bar has stop-hunted
        $i = $ev[$q]['i']; $vd = $ev[$q]['x'] - $sg * 0.3 * (float)$A5[$i]; $ok = true;
        for ($k = $i + 1; $k <= $b; $k++) if ($sg > 0 ? $F['l'][$k] <= $vd : $F['h'][$k] >= $vd) { $ok = false; break; }
        if ($ok) { $out['bb'] = ['type' => 'bb', 'time' => (int)$F['t'][$i] + 300, 'x' => $ev[$q]['x'], 'void' => $vd, 'atr' => (float)$A5[$i]]; break; }
    }
    if ($F3 && !empty($F3['t'])) {
        $dayTs = (int)$F['t'][$a]; $n3 = count($F3['t']); while ($n3 > 0 && (int)$F3['t'][$n3 - 1] + 180 > $upto) $n3--;
        if ($n3 >= 2) {
            $E3 = t_ema(array_map('floatval', array_slice($F3['c'], 0, $n3)), 5); $ext = $sg > 0 ? INF : -INF; $ev = []; $k5 = $a;
            for ($m = 0; $m < $n3; $m++) {
                if ((int)$F3['t'][$m] < $dayTs) continue;
                $ext = $sg > 0 ? min($ext, (float)$F3['l'][$m]) : max($ext, (float)$F3['h'][$m]);
                while ($k5 < $b && (int)$F['t'][$k5 + 1] + 300 <= (int)$F3['t'][$m] + 180) $k5++;
                if ($m < 1) continue;
                $u = $F3['c'][$m] > $E3[$m]; $w = $F3['c'][$m - 1] > $E3[$m - 1];
                $cross = $sg > 0 ? ($u && !$w) : ($F3['c'][$m] < $E3[$m] && $F3['c'][$m - 1] >= $E3[$m - 1]);
                $at = (float)$A5[$k5];
                $near = $sg > 0 ? ((float)$F3['l'][$m] - $ext <= $zb * $at) : ($ext - (float)$F3['h'][$m] <= $zb * $at);
                if ($cross && $near) $ev[] = ['m' => $m, 'x' => $ext, 'atr' => $at];
            }
            for ($q = count($ev) - 1; $q >= 0; $q--) {
                $m = $ev[$q]['m']; $vd = $ev[$q]['x'] - $sg * 0.3 * $ev[$q]['atr']; $ok = true;
                for ($k = $m + 1; $k < $n3; $k++) if ($sg > 0 ? $F3['l'][$k] <= $vd : $F3['h'][$k] >= $vd) { $ok = false; break; }
                if ($ok) { $out['ema'] = ['type' => 'ema', 'time' => (int)$F3['t'][$m] + 180, 'x' => $ev[$q]['x'], 'void' => $vd, 'atr' => $ev[$q]['atr']]; break; }
            }
        }
    }
    return $out;
}
/** v8 grade of a FLY from the runways seen up to its confirmation: [grade, runway type, runway event]. A+ bb, A ema only, B none. */
function t_grade_v8(array $rw): array {
    if ($rw['bb']) return ['A+', 'bb', $rw['bb']];
    if ($rw['ema']) return ['A', 'ema', $rw['ema']];
    return ['B', null, null];
}
const T_GRADE_PTS = ['A+' => 10.0, 'A' => 5.0, 'B' => 0.0];
/** v8 SL: A+ = bb runway wick -/+ 0.3 x ATR5; otherwise the pattern candle low (CALL) / high (PUT) -/+ 0.3 x ATR5 ($atr = ATR5 at signal time).
 *  Never on the wrong side of the signal price $px. */
function t_sl_v8(string $grade, ?array $rwEv, float $patExt, float $px, int $sg, float $atr): float {
    $ref = ($grade === 'A+' && $rwEv) ? (float)$rwEv['x'] : $patExt;
    return $sg > 0 ? min($ref, $px) - 0.3 * $atr : max($ref, $px) + 0.3 * $atr;
}
/**
 * v8 FLY events of one session (shared by Tier 1 and Tier 2, same gates):
 *  - 10m bars from t_ten_session (anchored to the session's first 5m bar $a), closed only;
 *  - no pattern that closes in the first 60 minutes of the session;
 *  - zone touch: the pattern bar's low (CALL) / high (PUT) within $zb x ATR5 of the running Day Low / High;
 *  - a shared reversal pattern on that bar, and the next closed 10m bar confirms (t_fly_confirm);
 *  - SL (t_sl_v8, grade from the runways up to the confirmation close); invalid when the confirmation bar already breaks it.
 * Signal price = close of the confirmation bar; ATR5 at signal time. Returns ['events' => [...chronological], 'pending' => pattern
 * on the last closed 10m bar still waiting for its confirmation | null].
 */
function t_fly_events(array $F, int $a, int $b, int $sg, ?array $M1, float $zb, int $now): array {
    $out = ['events' => [], 'pending' => null];
    $b = min($b, count($F['t']) - 1); while ($b >= $a && (int)$F['t'][$b] + 300 > $now) $b--;
    if ($b - $a < 3) return $out;
    $A5 = t_atr($F, 14); $F3 = t_three($M1, $now); $G = t_ten_session($F, $a, $b); $m = count($G['t']); $dayTs = (int)$F['t'][$a];
    $ext = $sg > 0 ? INF : -INF; $RX = [];
    for ($i = $a; $i <= $b; $i++) { $ext = $sg > 0 ? min($ext, (float)$F['l'][$i]) : max($ext, (float)$F['h'][$i]); $RX[$i] = $ext; }
    for ($j = 0; $j < $m; $j++) {
        $tClose = $G['t'][$j] + 600; if ($tClose - $dayTs < 3600) continue;
        $kj = $G['k'][$j]; $tol = $zb * (float)$A5[$kj];
        if (!($sg > 0 ? $G['l'][$j] - $RX[$kj] <= $tol : $RX[$kj] - $G['h'][$j] <= $tol)) continue;
        $pn = t_reversal($G, $j, $sg); if (!$pn) continue;
        if ($j + 1 >= $m) { $out['pending'] = ['pattern' => $pn, 'time' => $tClose, 'j' => $j]; break; }
        if (!t_fly_confirm($G, $j, $sg)) continue;
        $k1 = $G['k'][$j + 1]; $at = (float)$A5[$k1]; $px = (float)$G['c'][$j + 1]; $tc = (int)$G['t'][$j + 1] + 600;
        $pat = $sg > 0 ? (float)$G['l'][$j] : (float)$G['h'][$j];
        [$gr, $rt, $ev] = t_grade_v8(t_runway_v8($F, $a, $b, $sg, $A5, $zb, $F3, $tc));
        $sl = t_sl_v8($gr, $ev, $pat, $px, $sg, $at);
        // v9 sweep + reclaim as of the confirmation close (closed 5m bars only); sweep SL = the further one; risk > 1.5 x ATR5 = no signal
        $e5 = $k1; while ($e5 + 1 <= $b && (int)$F['t'][$e5 + 1] + 300 <= $tc) $e5++;
        [$pdhS, $pdlS] = tsw_prev_hl($F, $a);
        $sw = tsw_detect($sg, tsw_rows($F, $a, $e5), $at, (float)$RX[$e5], (float)$RX[$e5], $tc, $dayTs, $pdhS, $pdlS, tsw_h1($F, $e5, $tc));
        if ($sw) { $sl = tsw_sl($sg, $sl, $sw, $at); if (TSW_CFG['risk_filter'] && !tsw_risk_ok($sg, $px, $sl, $at)) continue; }
        if ($sg > 0 ? $G['l'][$j + 1] <= $sl : $G['h'][$j + 1] >= $sl) continue;   // SL broke before the confirmation closed: setup void
        $out['events'][] = ['j' => $j, 'pattern' => $pn, 'pattern_time' => $tClose, 'time' => $tc, 'price' => $px, 'k' => $k1, 'atr' => $at,
            'grade' => $gr, 'runway_type' => $rt, 'runway_time' => $ev['time'] ?? null, 'sl' => $sl, 'day_ext' => $RX[$k1], 'sweep' => $sw,
            'bar' => ['t' => $G['t'][$j], 'o' => $G['o'][$j], 'h' => $G['h'][$j], 'l' => $G['l'][$j], 'c' => $G['c'][$j]]];
    }
    return $out;
}
/**
 * v8 Tier-1 entry status (both setups): FLY (with grade) / RUNWAY / null (= APPROACHING when price is in its zone).
 * FLY = the latest t_fly_events event whose SL no later closed 5m bar has broken. RUNWAY = the most recent valid runway.
 * $M1 = 1m bars or null (then only "bb" runways can be seen).
 */
function t_flow_v8(array $F, int $a, int $b, int $sg, ?array $M1, float $zb, int $now): ?array {
    $b = min($b, count($F['t']) - 1); while ($b >= $a && (int)$F['t'][$b] + 300 > $now) $b--;
    if ($b < $a) return null;
    $E = t_fly_events($F, $a, $b, $sg, $M1, $zb, $now)['events'];
    if ($E) {
        $e = $E[count($E) - 1]; $hit = false;
        for ($k = $e['k'] + 1; $k <= $b; $k++) if ($sg > 0 ? $F['l'][$k] <= $e['sl'] : $F['h'][$k] >= $e['sl']) { $hit = true; break; }
        if (!$hit) return ['state' => 'FLY', 'time' => $e['time'], 'grade' => $e['grade'], 'runway_type' => $e['runway_type'], 'pattern' => $e['pattern'], 'sl' => $e['sl'], 'sweep' => $e['sweep'] ?? null];
    }
    $rw = t_runway_v8($F, $a, $b, $sg, t_atr($F, 14), $zb, t_three($M1, $now), $now);
    $ev = $rw['bb'] && $rw['ema'] ? ($rw['ema']['time'] > $rw['bb']['time'] ? $rw['ema'] : $rw['bb']) : ($rw['bb'] ?? $rw['ema']);
    if ($ev) return ['state' => 'RUNWAY', 'time' => $ev['time'], 'grade' => null, 'runway_type' => $ev['type'], 'sl' => $ev['void']];
    return null;
}
/** v8 Tier-1 order inside the signal tier: FLY A+ (0), FLY A (1), FLY B (2), RUNWAY (3), APPROACHING (4), nothing (5). */
function t_flow_rank(array $r): int {
    if (($r['tier1'] ?? '') !== 'signal' || empty($r['flow'])) return 5;
    if ($r['flow'] === 'FLY') return ['A+' => 0, 'A' => 1][$r['grade'] ?? 'B'] ?? 2;
    if (in_array($r['flow'], ['RUNWAY', 'SIGNAL'], true)) return 3;
    if ($r['flow'] === 'GATE OPEN') return 4;   // owner, 20 Oct 2026: gate open, entry still awaited
    return in_array($r['flow'], ['APPROACHING', 'APPROACHING AGAIN', 'GATE CLOSE'], true) ? 5 : 6;
}
/** v8 row order: signal > queue > out, then t_flow_rank, then score (desc). */
function t_row_cmp(array $x, array $y): int {
    $T = ['signal' => 0, 'queue' => 1, 'out' => 2];
    return [$T[$x['tier1'] ?? 'out'] ?? 3, t_flow_rank($x), -(float)($x['strength'] ?? 0)] <=> [$T[$y['tier1'] ?? 'out'] ?? 3, t_flow_rank($y), -(float)($y['strength'] ?? 0)];
}
/** Fibonacci pivot points of the previous session (TradingView "Pivot Points Standard", type Fibonacci, timeframe D):
 *  PP = (H + L + C) / 3; R1/S1 = PP +/- 0.382 x (H - L); R2/S2 = PP +/- 0.618 x (H - L); R3/S3 = PP +/- 1.000 x (H - L).
 *  H / L / C come from the 5-minute bars of the session before $dayTs. */
function t_fib_pivots(array $F5, int $dayTs): ?array {
    $pe = -1; foreach ($F5['t'] as $i => $t) { if ($t < $dayTs) $pe = $i; else break; }
    if ($pe < 0) return null;
    $from = (int)$F5['t'][$pe] - 86400 + 300; $h = -INF; $l = INF;
    for ($i = $pe; $i >= 0 && $F5['t'][$i] >= $from; $i--) { $h = max($h, (float)$F5['h'][$i]); $l = min($l, (float)$F5['l'][$i]); }
    $c = (float)$F5['c'][$pe]; $pp = ($h + $l + $c) / 3; $rg = $h - $l;
    if ($rg <= 0) return null;
    $L = ['PP' => $pp];
    foreach ([1 => 0.382, 2 => 0.618, 3 => 1.0] as $k => $f) { $L["R$k"] = $pp + $rg * $f; $L["S$k"] = $pp - $rg * $f; }
    return $L;
}
/** The pivots worth drawing (no noise): PP, R1, R2, S1, S2 always; R3 / S3 only once today's range has reached R2 / S2. */
function t_pivot_view(array $P, float $lo, float $hi): array {
    $out = [];
    foreach (['R3', 'R2', 'R1', 'PP', 'S1', 'S2', 'S3'] as $k) {
        if (($k === 'R3' && $hi < $P['R2']) || ($k === 'S3' && $lo > $P['S2'])) continue;
        $out[] = ['label' => t_pub_level($k), 'kind' => $k === 'PP' ? 'pp' : ($k[0] === 'R' ? 'u' : 'd'), 'price' => t_r($P[$k])];
    }
    return $out;
}
/** Drawing data for the 10-minute views: today's Day High / Low, the reversal band on each, and the Fibonacci pivot points. */
function t_zone_view(array $F5, int $dayTs, float $tol, string $near, float $price): ?array {
    $lo = INF; $hi = -INF;
    foreach ($F5['t'] as $i => $t) if ($t >= $dayTs && $t < $dayTs + 86400) { $lo = min($lo, (float)$F5['l'][$i]); $hi = max($hi, (float)$F5['h'][$i]); }
    if (!is_finite($lo)) return null;
    $lo = min($lo, $price); $hi = max($hi, $price); $P = t_fib_pivots($F5, $dayTs);
    return ['from' => $dayTs, 'floor' => t_r($lo), 'ceiling' => t_r($hi), 'pivots' => $P ? t_pivot_view($P, $lo, $hi) : []];
}
function t_read_json(string $p, $def) { if (!is_file($p)) return $def; $j = json_decode((string)file_get_contents($p), true); return $j ?? $def; }
function t_write_json(string $p, $d): void { $tmp = $p . '.tmp'; file_put_contents($tmp, json_encode(t_clean($d), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)); rename($tmp, $p); }
function t_ensure_dirs(): void { if (!is_dir(T_FRAMES)) @mkdir(T_FRAMES, 0755, true); }

function t_slim(array $r, array $st): array {
    $o = [];
    // owner, 4 Nov 2026 fix: 'mt' was missing here, so it never survived to the stored scan row -- the frontend's
    // Mid Target badge and the pb5/ema5/st5 "not for mid-trend" exclusions (all keyed on r.mt) never fired either.
    foreach (['symbol','name','market','signal','rule','setup','remark','strength','bias','alignment','zone_touch','zone_near','price','change_pct','adx_value','atr_pct','time_ist','components','rsi','zone','zone_dist_atr','overextended','move_atr','range_atr','range_pct','range_pos','flow','flow_at','flow_sl','grade','runway_type','atr5','atr_low','atr_high','hc','tk','pw','pw_n','al5','v10','go','t0','zg','zlv','lad','sl_wide','mt','split24','pw30','mv'] as $k) $o[$k] = $r[$k] ?? null;
    $o['tf'] = array_map(fn($v) => $v['dir'], $r['tf']);
    foreach (['spark', 'ret_1w', 'ret_1m', 'ret_3m', 'ret_1y', 'vol_20d', 'from_hi_pct', 'from_lo_pct', 'hi_52w', 'lo_52w'] as $k) $o[$k] = $st[$k];
    return $o;
}
function t_scan_load(): array {
    $s = t_read_json(T_DATA . '/scan.json', null);
    if (!is_array($s)) $s = [];
    if ((int)($s['v'] ?? 1) < 2) {   // v1 stored a list of rows: keep them as provisional rows until refetched
        $rows = [];
        foreach (($s['rows'] ?? []) as $r) if (isset($r['symbol'])) { $r['mig'] = 1; $r['ts'] = (int)($s['updated'] ?? 0); $r['ex'] = t_exchange($r['symbol']); $rows[$r['symbol']] = $r; }
        $s = ['v' => 2, 'updated' => (int)($s['updated'] ?? 0), 'rows' => $rows, 'fail' => [], 'force' => 0];
    }
    $s['rows'] = $s['rows'] ?? []; $s['fail'] = $s['fail'] ?? [];
    return $s;
}
/** Symbols that need a refresh now, most urgent first: symbol => [priority, -age]. */
function t_due(array $scan, int $now): array {
    $pri = []; $force = (int)($scan['force'] ?? 0);
    foreach (t_symbols() as $sym => $_) {
        $ss = t_session(t_exchange($sym), $now);
        $r = $scan['rows'][$sym] ?? null; $f = $scan['fail'][$sym] ?? null;
        if ($f && strncmp((string)($f['why'] ?? ''), 'alpaca', 6) === 0) $f = null;   // an Alpaca miss falls back to Yahoo now, it never parks the symbol
        if ($f && $now < (int)$f['at'] + min(86400, 300 * 2 ** min((int)$f['n'] - 1, 9))) continue;   // back off after failures
        if (!$r || !empty($r['mig'])) { $pri[$sym] = [$ss['open'] ? 0 : 3, 0]; continue; }
        $age = $now - (int)$r['ts'];
        $wantSrc = t_up_key($sym) ? 'upstox' : (t_alp_use($sym, $scan, $now) ? 'alpaca' : 'yahoo');
        if ($wantSrc !== ($r['src'] ?? 'yahoo') && $age >= 30) { $pri[$sym] = [$ss['open'] ? 1 : 3, -$age]; continue; }   // data source switched
        if ($ss['open']) {
            $traded = (int)$r['rmt'] >= (int)$r['ts'] - T_FRESH;
            $inCtp = empty($r['cs']) || ($now >= $r['cs'] - 300 && $now < $r['ce'] + 300);
            if ($traded && $inCtp && ($r['day_ok'] ?? true)) {
                $lim = (abs((float)($r['zone_dist_atr'] ?? 9)) <= 0.75 || (int)($r['alignment'] ?? 0) >= 3 || !empty($r['split24'])) ? T_REFRESH_HOT : T_REFRESH;
                if ($age >= $lim || ($force > $r['ts'] && $age >= 45)) $pri[$sym] = [2, -$age];
            } elseif ($age >= 60 && ($r['ts'] < $ss['since'] || (!empty($r['ce']) && $now >= $r['ce'] && $r['ts'] < $r['ce']))) {
                $pri[$sym] = [1, -$age];                                    // session just started / trading period rolled over
            } elseif ($age >= ($now - $ss['since'] < 3600 ? 300 : T_REFRESH_QUIET)) {
                $pri[$sym] = [4, -$age];                                    // open by the clock but no fresh trades yet
            }
        } elseif ($age >= T_REFRESH_CLOSED) $pri[$sym] = [5, -$age];
    }
    uasort($pri, fn($a, $b) => $a <=> $b);
    return $pri;
}
function t_fail(array &$scan, string $s, int $now, string $why): void {
    $n = (int)($scan['fail'][$s]['n'] ?? 0) + 1;
    $scan['fail'][$s] = ['n' => $n, 'at' => $now, 'why' => $why];
}
/** Index of the last daily bar if it is the session that started at $cs (Yahoo's current trading period). */
function t_day_index(array $D, int $cs, int $ce): ?int {
    $n = count($D['t']); if (!$n) return null;
    $t = $D['t'][$n - 1];
    return ($t >= $cs - 7200 && $t < max($ce, $cs + 3600)) ? $n - 1 : null;
}
/** 5-minute bars are fetched for symbols that can signal (3/4+ aligned) or have no row yet. */
function t_want5(?array $r): bool { return !$r || !empty($r['mig']) || (int)($r['alignment'] ?? 0) >= 2; }   // v10: 3/5 can come from 2/4 + 30m
function t_process(array $syms, array &$scan, int $now): void {
    $up = []; $al = []; $yh = [];
    foreach ($syms as $s) {
        if (t_up_key($s)) $up[] = $s;          // NSE / MCX via Upstox, unchanged
        elseif (t_alp_use($s, $scan, $now)) $al[] = $s;     // US stocks + Alpaca's own crypto list (unless Alpaca just failed for it)
        else $yh[] = $s;                       // everything else stays on Yahoo
    }
    if ($yh) t_process_yahoo($yh, $scan, $now);
    if ($up) t_process_upstox($up, $scan, $now);
    if ($al) t_process_alpaca($al, $scan, $now);
}
function t_needs_full(?array $fr, ?array $r, int $now, string $src, string $ukey = ''): bool {
    return !$fr || !$r || !empty($r['mig']) || ($r['src'] ?? 'yahoo') !== $src || ($r['ukey'] ?? '') !== $ukey
        || $now - (int)($r['full'] ?? 0) >= T_FULL_EVERY || $now - (int)($r['ts'] ?? 0) >= 3 * 86400;
}
/** Evaluate, store frames and the row. $m = [rmt, cs, ce]. */
/* ---------------- sticky entry status + cycle (owner, 5 Oct 2026, 16:28 / 16:46 IST) ----------------
   A RUNWAY / FLY stays on Tier 1 while an entry can still be taken: price within 1 x ATR5 of the Day High / Day Low zone
   it came from, and the stop not crossed (a small move away and back keeps it). Leaving that range or crossing the stop ends it.
   Once ended, that signal never comes back; only a NEW Day High (PUT side) / Day Low (CALL side) starts a fresh cycle:
   APPROACHING -> RUNWAY -> FLY. An open Tier-2 trade shows the same FLY on Tier 1 under the same rules. */
function t_t2_open_map(): array {
    static $M = null; if ($M !== null) return $M; $M = [];
    $j = t_read_json(T_DATA . '/t2trades.json', ['items' => []]); $now = time();
    foreach (($j['items'] ?? []) as $t) {
        if (($t['status'] ?? '') !== 'open' || $now - (int)($t['entry_time'] ?? 0) > 43200) continue;
        $s = (string)($t['symbol'] ?? ''); if ($s === '' || (isset($M[$s]) && $M[$s]['entry_time'] > $t['entry_time'])) continue;
        $M[$s] = $t;
    }
    return $M;
}
/** In the entry range: no further than T10_ENTRY_ATR x ATR5 from the zone level on the trade side, and the
 *  stop not crossed. Owner, 19 Oct 2026: the range is 2 x ATR5 -- this test had been left on the old 1 x. */
function t_entry_ok(float $px, int $sg, float $ext, float $a5, ?float $sl): bool {
    if ($px <= 0 || $ext <= 0 || $a5 <= 0) return false;
    if ($sl !== null && $sl > 0 && ($px - $sl) * $sg <= 0) return false;
    return ($px - $ext) * $sg <= T10_ENTRY_ATR * $a5;
}
/** Has price now reached/crossed the stop on the adverse side? Owner, 4 Nov 2026: a setup whose last tick
 *  (Double Confirm / FLY) already fired was being treated as permanently valid below -- the ticks stayed
 *  checked on screen even after price ran all the way to the stop-loss, because only a logged Tier-2 trade
 *  exit (t10_recent_exit) ever cleared it, and a FLY that never became a tracked trade has no such exit event.
 *  This check runs even for an already-entered row, so price crossing its own stop always ends the cycle. */
function t_sl_crossed(float $px, int $sg, ?float $sl): bool {
    return $sl !== null && $sl > 0 && ($px - $sl) * $sg <= 0;
}
function t_sticky_apply(string $s, array $row, int $now): array {
    static $S = null; if ($S === null) { $S = t_read_json(T_DATA . '/t1sticky.json', []); if (!isset($S['_end']) || !is_array($S['_end'])) $S['_end'] = []; }
    $px = (float)($row['price'] ?? 0); $a5 = (float)($row['atr5'] ?? 0); $zone = (float)($row['zone'] ?? 0); $changed = false;
    $zs = fn(string $sig): float => (float)($row[$sig === 'CALL' ? 'atr_low' : 'atr_high'] ?? 0) ?: $zone;   // the signal's own zone: Day Low for CALL, Day High for PUT
    $clear = function (array $r): array { foreach (['setup', 'remark', 'flow', 'flow_at', 'grade', 'runway_type', 'tk'] as $f) $r[$f] = null; $r['signal'] = 'WAIT'; $r['pw'] = 0; $r['pw_n'] = 0; $r['v10'] = 0; return $r; };
    // v10 (owner, 11 Oct 2026): a trend signal that ends shows GATE CLOSE for 4 minutes, then disappears
    if (!isset($S['_gc']) || !is_array($S['_gc'])) $S['_gc'] = [];
    $gcSet = function (array $r) use (&$S, $s, $now, &$changed): array {
        $S['_gc'][$s] = ['until' => $now + T10_GATE_HOLD, 'signal' => $r['signal'], 'setup' => 'trend', 'remark' => $r['remark'] ?? 'Long target',
            'tk' => $r['tk'] ?? null, 'tk_at' => $r['tk_at'] ?? null, 'pw' => (int)($r['pw'] ?? 0), 'pw_n' => (int)($r['pw_n'] ?? 0), 'flow_at' => $r['flow_at'] ?? $now];
        $changed = true; return $r;
    };
    $gcShow = function (array $r) use (&$S, $s, $now): array {
        $g = $S['_gc'][$s] ?? null; if (!$g || $now > (int)$g['until']) return $r;
        foreach (['signal', 'setup', 'remark', 'tk', 'tk_at', 'pw', 'pw_n', 'flow_at'] as $f) $r[$f] = $g[$f] ?? ($f === 'pw_n' ? 0 : null);
        $r['flow'] = 'GATE CLOSE'; $r['v10'] = 1; $r['grade'] = null; $r['gc_until'] = (int)$g['until']; return $r;
    };
    $rx = t10_recent_exit($s, $now);   // Tier-2 trend trade exited (10m flip / reverse pattern / SL) in the last 4 min
    if ($rx && !isset($S['_gc'][$s])) { $gcSet(['signal' => $rx['sig'], 'remark' => 'Long target', 'tk' => $row['tk'] ?? [0, 0, 0, 0, 1], 'tk_at' => $row['tk_at'] ?? null, 'pw' => $row['pw'] ?? 0, 'flow_at' => $rx['entry_time']]); $S['_gc'][$s]['until'] = (int)$rx['exit_time'] + T10_GATE_HOLD; }
    $endIt = function (string $sig, float $ext) use (&$S, $s, $now, &$changed) { $S['_end'][$s] = ['sig' => $sig, 'ext' => $ext, 'at' => $now]; unset($S[$s]); $changed = true; };
    // an ended cycle: only a new Day High (PUT) / Day Low (CALL) beyond the old one re-opens it
    $E = $S['_end'][$s] ?? null;
    if ($E) {
        $esg = $E['sig'] === 'CALL' ? 1 : -1;
        if ($now - (int)$E['at'] > 86400) { unset($S['_end'][$s]); $E = null; $changed = true; }
        elseif (empty($E['open']) && $zs($E['sig']) > 0 && ($zs($E['sig']) - (float)$E['ext']) * $esg < 0) { $S['_end'][$s]['open'] = 1; $E['open'] = 1; $changed = true; }   // new Day High / Low: fresh cycle
    }
    $blocked = fn(string $sig, int $at) => $E && $E['sig'] === $sig && $at <= (int)$E['at'];   // signals from before the end never come back
    if ($E && empty($E['open']) && ($row['signal'] ?? 'WAIT') === $E['sig']) {   // back at the old zone without a new extreme: nothing until a new Day High / Low
        $row = $clear($row);
        if ($changed) t_sticky_save($S, $now);
        return $row;
    }
    // Task G (3 Nov 2026): fresh cycle after GATE CLOSE — add a "zone retested" note so the user knows this
    // zone already fired once today. Apply only when the row has a live signal and the old end matches direction.
    // Owner, 5 Nov 2026 fix: this used to REPLACE the remark with the Hindi "Zone retested · fly hua tha",
    // wiping out "Long target" / "Small target" / "Mid-trend target" -- the Remark column then gave no clue
    // which kind of target this was. Now it appends the English note onto whatever target wording was
    // already there, so both are visible together.
    if ($E && !empty($E['open']) && ($row['signal'] ?? 'WAIT') === ($E['sig'] ?? '') && !empty($row['flow'])) {
        $row['remark'] = ($row['remark'] ?? null) ? $row['remark'] . ' · zone retested' : 'Zone retested';
    }
    // owner, 10 Nov 2026: a NEW Day High (PUT) / Day Low (CALL) beyond the extreme a held entry was built on makes
    // that entry stale -- its ticks must not stay on screen. $past(sig, ext) = today's extreme has moved past ext.
    $eps = max($a5 * 0.02, abs($zone) * 1e-7);
    $past = fn(string $sig, float $ext): bool => $ext > 0 && $zs($sig) > 0 && ($sig === 'CALL' ? $zs($sig) < $ext - $eps : $zs($sig) > $ext + $eps);
    $T = t_t2_open_map()[$s] ?? null;
    if ($T && !empty($T['day_ext']) && $past((string)$T['sig'], (float)$T['day_ext'])) $T = null;
    if ($T && !$blocked((string)$T['sig'], (int)($T['pattern_time'] ?? $T['entry_time']))) {   // Tier 2 is in a trade: Tier 1 mirrors it while an entry is possible
        $sg = ($T['sig'] ?? '') === 'CALL' ? 1 : -1; $ta = $a5 > 0 ? $a5 : (float)($T['atr5'] ?? 0);
        // Owner, 20 Oct 2026: an open trade is frozen -- the band no longer ends it, only its own exit does.
        if (true) {
            $row['signal'] = $T['sig']; $row['setup'] = $T['setup'] ?? 'trend'; $row['remark'] = $T['remark'] ?? ($row['setup'] === 'trend' ? 'Long target' : 'Small target');
            $row['flow'] = 'FLY'; $row['flow_at'] = (int)($T['pattern_time'] ?? $T['entry_time']); $row['grade'] = $T['grade'] ?? ($row['grade'] ?? 'B');
            $row['runway_type'] = $T['runway_type'] ?? null; $row['sticky'] = 't2';
            if ($changed) t_sticky_save($S, $now);
            return $row;
        }
        $endIt((string)$T['sig'], (float)($T['day_ext'] ?? $zs((string)$T['sig'])));
    }
    $live = ($row['signal'] ?? 'WAIT') !== 'WAIT' && !empty($row['setup']) && in_array($row['flow'] ?? null, ['FLY', 'RUNWAY', 'SIGNAL', 'GATE OPEN'], true);
    // Owner, 20 Oct 2026: after an entry everything freezes. A row whose entry tick has fired is not ended by
    // the band / zone rules any more -- only the SL, a target or the 8m exit closes it (Tier 2 reports that,
    // and t10_recent_exit then turns it into GATE CLOSE).
    // Owner, 4 Nov 2026 fix: "entry" means the LAST tick (Double Confirm / FLY) only -- not any of Tick 1-3.
    // The old check froze invalidation the moment Tick 1 fired, so a setup that got Tick1-3 but never Tick4
    // (no real entry) could run far outside its ATR entry range and never GATE CLOSE -- stale ticks stayed
    // checked indefinitely. Only a genuine entry (last tick) should stop the exhaustion check below.
    $tkArr = is_array($row['tk'] ?? null) ? array_map('intval', $row['tk']) : [];
    $hasEntry = !empty($row['v10']) && !empty($tkArr) && !empty($tkArr[count($tkArr) - 1]);
    if (isset($S['_gc'][$s]) && $now <= (int)$S['_gc'][$s]['until'] && !($live && (int)($row['flow_at'] ?? 0) > (int)($S['_gc'][$s]['flow_at'] ?? 0))) {
        $row = $gcShow($clear($row)); if ($changed) t_sticky_save($S, $now); return $row;   // GATE CLOSE is showing; a newer cycle replaces it
    }
    if (isset($S['_gc'][$s]) && $now > (int)$S['_gc'][$s]['until']) { unset($S['_gc'][$s]); $changed = true; }
    if ($live && $blocked((string)$row['signal'], (int)($row['flow_at'] ?? 0))) {   // old signal of an ended cycle: show APPROACHING (no flow) only
        foreach (['flow', 'flow_at', 'grade', 'runway_type', 'tk', 'tk_at', 't0'] as $f) $row[$f] = null;
        $row['pw'] = 0; $row['go'] = 0;   // 10 Nov 2026: the ended cycle's tick marks were left on the row with no status beside them
        if (!empty($row['v10'])) $row['flow'] = 'APPROACHING';
        $live = false;
    }
    $rowSl = isset($row['flow_sl']) ? (float)$row['flow_sl'] : null;
    $rowSg = ($row['signal'] ?? 'WAIT') === 'CALL' ? 1 : -1;
    // Owner, 4 Nov 2026 fix: an entered row (hasEntry true) used to skip this whole check and freeze forever --
    // it should still end the moment price crosses ITS OWN stop, entry or no entry. The ATR/zone distance test
    // stays entry-only (that's what decides whether the setup is still "in range" before an entry exists).
    if ($live && (($hasEntry && t_sl_crossed($px, $rowSg, $rowSl)) || (!$hasEntry && $a5 > 0 && $zs($row['signal']) > 0 && !t_entry_ok($px, $rowSg, $zs($row['signal']), $a5, $rowSl)))) {
        $wasV10 = !empty($row['v10']); if ($wasV10) $gcSet($row);
        $endIt((string)$row['signal'], $zs($row['signal'])); $row = $wasV10 ? $gcShow($clear($row)) : $clear($row);   // left the entry range or crossed the stop
    } elseif ($live) {
        // Owner, 5 Nov 2026: the RETEST chip showed a fixed "++" no matter how many times the zone was
        // actually retested this cycle. pw_n counts real retest EVENTS (pw rising to 2 from something
        // else) across the whole cycle, carried forward scan to scan in the same sticky state pw already
        // lives in -- so it naturally resets to 0 whenever $endIt() unsets $S[$s] and a fresh cycle starts.
        $prevPw = (int)($S[$s]['pw'] ?? 0); $prevPwN = (int)($S[$s]['pw_n'] ?? 0); $curPw = (int)($row['pw'] ?? 0);
        $pwN = ($curPw === 2 && $prevPw !== 2) ? $prevPwN + 1 : $prevPwN;
        $row['pw_n'] = $pwN;
        $S[$s] = ['signal' => $row['signal'], 'setup' => $row['setup'], 'remark' => $row['remark'] ?? null, 'flow' => $row['flow'], 'flow_at' => (int)($row['flow_at'] ?? $now),
            'grade' => $row['grade'] ?? null, 'runway_type' => $row['runway_type'] ?? null, 'sl' => isset($row['flow_sl']) ? (float)$row['flow_sl'] : null,
            'ext' => $zs($row['signal']), 'a5' => $a5, 'seen' => $now, 'tk' => $row['tk'] ?? null, 'tk_at' => $row['tk_at'] ?? null, 'pw' => $curPw, 'pw_n' => $pwN, 'v10' => (int)($row['v10'] ?? 0)];
        $changed = true;
    } elseif (isset($S[$s])) {
        $k = $S[$s]; $sg = $k['signal'] === 'CALL' ? 1 : -1;
        // owner, 4 Nov 2026: same fix as $hasEntry above -- last tick (Double Confirm) only, not any of Tick 1-3.
        $kTk = is_array($k['tk'] ?? null) ? array_map('intval', $k['tk']) : [];
        $kEntry = !empty($k['v10']) && !empty($kTk) && !empty($kTk[count($kTk) - 1]);
        // Owner, 4 Nov 2026 fix: same as above -- an entered ($kEntry) setup used to count as "ok" no matter
        // where price went for up to 8 hours. Now its own stop still ends it; only being inside the stop keeps
        // an entered row sticky past the ATR/zone-distance test (which only makes sense pre-entry).
        // Owner, 4 Nov 2026 (2nd fix): dropped the 8-hour hard time cap entirely -- owner wants this PRICE-ONLY.
        // A cycle now stays alive purely on: own SL not crossed, AND (already entered OR still inside entry-ATR
        // range of the zone). Time no longer ends a cycle by itself; only price does (SL cross, or -- pre-entry --
        // moving outside the ATR entry window, which is what trips GATE CLOSE below).
        $ok = !t_sl_crossed($px, $sg, $k['sl']) && ($kEntry || t_entry_ok($px, $sg, (float)($k['ext'] ?? 0), $a5 > 0 ? $a5 : (float)($k['a5'] ?? 0), $k['sl']));
        $flip = ($row['signal'] ?? 'WAIT') !== 'WAIT' && $row['signal'] !== $k['signal'];
        if (!empty($k['v10']) && ($k['setup'] ?? '') === 'trend' && $past((string)$k['signal'], (float)($k['ext'] ?? 0))) {
            unset($S[$s]); $changed = true;   // new extreme: drop the old snapshot quietly, the row shows the fresh cycle
        }
        elseif (!$ok || $flip) {
            if (!empty($k['v10'])) { $gcSet($k); $row = $gcShow($row); }
            $endIt((string)$k['signal'], (float)($k['ext'] ?? $zs($k['signal'])));
        }
        elseif (($row['signal'] ?? 'WAIT') === 'WAIT' || empty($row['flow'])) {
            foreach (['signal', 'setup', 'remark', 'flow', 'flow_at', 'grade', 'runway_type', 'tk', 'tk_at', 'pw', 'pw_n', 'v10'] as $f) $row[$f] = $k[$f] ?? ($f === 'pw_n' ? 0 : null);
            // Owner, 4 Nov 2026 fix: flow_sl (stored here as 'sl') was missing from this restore list, so a row
            // held sticky from the saved snapshot showed its real ticks/flow but a null stop-loss publicly --
            // the SL-cross check above reads the correct $k['sl'] internally, but the API/UI never saw the number.
            $row['flow_sl'] = $k['sl'] ?? null;
            $row['sticky'] = 'hold';
        }
    }
    if ($changed) t_sticky_save($S, $now);
    return $row;
}
function t_sticky_save(array $S, int $now): void {
    $E = array_filter($S['_end'] ?? [], fn($v) => $now - (int)$v['at'] < 86400); unset($S['_end']);
    $G = array_filter($S['_gc'] ?? [], fn($v) => $now <= (int)$v['until'] + 60); unset($S['_gc']);
    $S = array_filter($S, fn($v) => is_array($v) && $now - (int)($v['seen'] ?? 0) < 86400); $S['_end'] = $E; $S['_gc'] = $G;
    t_write_json(T_DATA . '/t1sticky.json', $S);
}
/** Disturbed chart: over half of the recent 10m candles are flat lines (range <= 1 price tick). Such symbols never enter Tier 1 (owner, 5 Oct 2026). */
function t_choppy(?array $M5): bool {
    if (!$M5 || count($M5['t'] ?? []) < 80) return false;
    $n = count($M5['t']); $a = max(0, $n - 240);
    $T = ['t' => array_slice($M5['t'], $a), 'o' => array_slice($M5['o'], $a), 'h' => array_slice($M5['h'], $a), 'l' => array_slice($M5['l'], $a), 'c' => array_slice($M5['c'], $a), 'v' => array_slice($M5['v'] ?? array_fill(0, $n, 0), $a)];
    $G = t_group($T, fn($t) => intdiv((int)$t, 600));
    $k = count($G['t']); if ($k < 40) return false;
    $s = max(0, $k - 120); $H = array_slice($G['h'], $s); $Lo = array_slice($G['l'], $s); $O = array_slice($G['o'], $s); $C = array_slice($G['c'], $s);
    $u = []; foreach ([$H, $Lo, $O, $C] as $arr) foreach ($arr as $x) $u[] = round((float)$x, 8);
    $u = array_values(array_unique($u)); sort($u); $tick = INF;
    for ($i = 1; $i < count($u); $i++) { $d = $u[$i] - $u[$i - 1]; if ($d > 1e-12 && $d < $tick) $tick = $d; }
    if (!is_finite($tick)) return true;
    $flat = 0; foreach ($H as $i => $h) if ((float)$h - (float)$Lo[$i] <= $tick * 1.01) $flat++;
    return $flat / count($H) >= 0.5;
}
function t_finish(string $s, array $fr, array $m, bool $dayOk, array &$scan, int $now, array $extra, ?array $M5 = null): void {
    // 5-minute bars for the live Day Low/High zone and ATR5; the last 400 are cached so the analysis view sees the same zone
    if ($M5 && count($M5['t']) >= 2) $fr['5m'] = array_map(fn($a) => array_slice($a, -400), $M5); else unset($fr['5m']);
    $ev = t_frames_eval($fr);
    if (count($ev['1D']['c']) < 40 || count($ev['1H']['c']) < 60 || count($ev['1W']['c']) < 15) { t_fail($scan, $s, $now, 'short history'); return; }
    try { $row = t_slim(t_evaluate($s, $ev), t_stats($ev)); }
    catch (Throwable $e) { t_fail($scan, $s, $now, 'evaluation error (' . get_class($e) . ' line ' . $e->getLine() . ')'); return; }
    file_put_contents(t_frame_path($s), json_encode($fr, JSON_UNESCAPED_SLASHES));
    $row += ['ex' => t_exchange($s), 'ts' => $now, 'rmt' => (int)$m['rmt'], 'cs' => (int)$m['cs'], 'ce' => (int)$m['ce'], 'day_ok' => $dayOk,
        'full' => (int)($fr['full'] ?? $now)] + $extra;
    $row['choppy'] = t_choppy($M5);
    $row = t_sticky_apply($s, $row, $now);
    $scan['rows'][$s] = $row;
    unset($scan['fail'][$s]);
    t_siglog_track($s, $row, $now);
}
/* ---------------- recent signals log (data/signals.json) ----------------
   Every CALL / PUT the scanner sees is kept with the time it appeared and the time it ended, so a signal that
   was live for a few minutes still shows in "Recent signals" after price has moved away. */
function &t_siglog(): array {
    static $L = null;
    if ($L === null) { $L = t_read_json(T_DATA . '/signals.json', ['items' => []]); $L['_dirty'] = false; if (!isset($L['items']) || !is_array($L['items'])) $L['items'] = []; }
    return $L;
}

/* ---- Telegram broadcast helpers (3 Nov 2026) ---- */

/** Send one message to all subscribers in data/tg_subscribers.json. Fire-and-forget (curl async). */
function tg_broadcast(string $html): void {
    $tok = trim((string)t_cfg('telegram_bot_token', ''));
    if ($tok === '') return;
    $sub_file = T_DATA . '/tg_subscribers.json';
    if (!is_file($sub_file)) return;
    $subs = json_decode((string)file_get_contents($sub_file), true);
    if (!is_array($subs) || empty($subs)) return;
    foreach ($subs as $chat_id => $_) {
        $ch = curl_init('https://api.telegram.org/bot' . $tok . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'chat_id'                  => (string)$chat_id,
                'text'                     => $html,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
        usleep(50000);   // 50ms between sends — Telegram rate limit safe
    }
}

/** Track per-symbol last-notified flow so we only alert on state UPGRADES. Stored in data/tg_flow_state.json. */
function &tg_flow_state(): array {
    static $S = null;
    if ($S === null) { $f = T_DATA . '/tg_flow_state.json'; $S = is_file($f) ? (array)json_decode((string)file_get_contents($f), true) : []; }
    return $S;
}
function tg_flow_state_save(): void {
    $S = &tg_flow_state();
    file_put_contents(T_DATA . '/tg_flow_state.json', json_encode($S, JSON_UNESCAPED_UNICODE));
}

/** Flow upgrade order — higher = stronger signal. Only alert when moving forward. */
const TG_FLOW_RANK = ['APPROACHING' => 1, 'GATE OPEN' => 2, 'GATE CLOSE' => 2,
    'TICK 1' => 3, 'TICK 2' => 4, 'TICK 3' => 5, 'SIGNAL' => 4, 'FLY' => 6];

/** Called from t_siglog_track() — check if this row has a new/upgraded flow and broadcast. */
function tg_maybe_notify(string $sym, array $row): void {
    $flow  = $row['flow']   ?? null;
    $sig   = $row['signal'] ?? null;
    $setup = $row['setup']  ?? null;
    if (!$flow || !$sig || !$setup) return;
    if ($flow !== 'FLY') return;   // Telegram alerts: FLY only (owner, 4 Nov 2026)

    $S     = &tg_flow_state();
    $prev  = $S[$sym] ?? null;
    $rank  = TG_FLOW_RANK[$flow]       ?? 0;
    $prevR = TG_FLOW_RANK[$prev ?? ''] ?? 0;
    // Only notify if: no previous state, OR flow rank increased
    if ($prev === $flow) return;
    if ($prevR > 0 && $rank <= $prevR) return;

    // Update state
    $S[$sym] = $flow;
    tg_flow_state_save();

    // Build message
    $name  = $row['name']     ?? $sym;
    $price = isset($row['price'])    ? '₹' . number_format((float)$row['price'], 2)    : '';
    $str   = isset($row['strength']) ? round((float)$row['strength']) . ' pts'          : '';
    $emoji = match($flow) {
        'GATE OPEN'         => '🟡',
        'SIGNAL', 'TICK 1' => '🟢',
        'TICK 2'            => '🔵',
        'TICK 3'            => '🟣',
        'FLY'               => '✈️',
        default             => '🔔',
    };
    $setupLabel = match($setup) {
        'trend'      => 'Trend',
        'retracement'=> 'Retracement',
        'midtrend'   => 'Mid-Trend',
        default      => ucfirst($setup),
    };
    $lines = [
        "$emoji <b>$flow — $sig</b>",
        "<b>$name</b>" . ($price ? "  ·  $price" : ''),
        "Setup: $setupLabel" . ($str ? "  ·  Score: $str" : ''),
        '📊 <a href="https://chartpulse.io/scanner.html">Open ChartPulse</a>',
        '<i>Not investment advice.</i>',
    ];
    tg_broadcast(implode("\n", $lines));
}

/** Reset flow state when a signal ends (so re-entry gets a fresh notification). */
function tg_clear_flow(string $sym): void {
    $S = &tg_flow_state();
    if (isset($S[$sym])) { unset($S[$sym]); tg_flow_state_save(); }
}
function t_siglog_track(string $s, array $row, int $now): void {
    $L = &t_siglog(); $it = &$L['items'];
    $live = t_status($s, $row, $now)['live'];
    $sig = ($live && in_array($row['signal'] ?? '', ['CALL', 'PUT'], true)) ? $row['signal'] : null;
    $open = null;
    foreach ($it as $k => $e) if ($e['symbol'] === $s && empty($e['end'])) { $open = $k; break; }
    if ($open !== null && (!$sig || $it[$open]['signal'] !== $sig)) {
        $it[$open]['end'] = min($now, (int)$it[$open]['last'] + 300); $it[$open]['exit'] = $row['price'] ?? null; $open = null; $L['_dirty'] = true;
        tg_clear_flow($s);   // signal ended — reset so re-entry notifies fresh
    }
    if ($sig && $open === null) {
        $it[] = ['id' => substr(md5($s . $sig . $now), 0, 10), 'symbol' => $s, 'name' => $row['name'] ?? $s, 'market' => $row['market'] ?? '', 'signal' => $sig,
            'setup' => $row['setup'] ?? null, 'remark' => $row['remark'] ?? null, 'price' => $row['price'] ?? null, 'strength' => $row['strength'] ?? null,
            'alignment' => $row['alignment'] ?? null, 'start' => $now, 'last' => $now, 'end' => null, 'exit' => null];
        $L['_dirty'] = true;
    } elseif ($sig) { $it[$open]['last'] = $now; $it[$open]['last_price'] = $row['price'] ?? null; $L['_dirty'] = true; }
    // Telegram: notify on new / upgraded flow state (fire-and-forget, non-blocking)
    if ($live && $sig) tg_maybe_notify($s, $row);
}
function t_siglog_flush(int $now): void {
    $L = &t_siglog(); if (empty($L['_dirty'])) return;
    $it = array_values(array_filter($L['items'], fn($e) => (int)$e['start'] >= $now - 129600));   // keep 36 h
    foreach ($it as &$e) if (empty($e['end']) && $now - (int)$e['last'] > 3600) $e['end'] = (int)$e['last'] + 300;   // symbol not refreshed for an hour
    unset($e);
    if (count($it) > 400) $it = array_slice($it, -400);
    $L['items'] = $it; $L['_dirty'] = false;
    t_write_json(T_DATA . '/signals.json', ['items' => $it]);
}
/** Recent signals for the page: the last 24 h, newest first. A signal not refreshed for 30 min counts as ended. */
function t_siglog_view(int $now, int $max = 80): array {
    $L = &t_siglog(); $out = [];
    foreach ($L['items'] as $e) {
        if ((int)$e['start'] < $now - 86400) continue;
        $end = $e['end'] ?? null; if (!$end && $now - (int)$e['last'] > 1800) $end = (int)$e['last'] + 300;
        $out[] = ['symbol' => $e['symbol'], 'name' => $e['name'], 'market' => $e['market'], 'signal' => $e['signal'], 'setup' => t_pub_setup($e['setup'] ?? null), 'remark' => $e['remark'],
            'price' => $e['price'], 'strength' => $e['strength'], 'start' => (int)$e['start'], 'end' => $end ? (int)$end : null, 'active' => !$end];
    }
    usort($out, fn($a, $b) => $b['start'] <=> $a['start']);
    return array_slice($out, 0, $max);
}
function t_process_yahoo(array $syms, array &$scan, int $now): void {
    $plan = []; $urls = [];
    foreach ($syms as $s) {
        $fr = t_read_frames($s); $r = $scan['rows'][$s] ?? null;
        $full = t_needs_full($fr, $r, $now, 'yahoo');
        $plan[$s] = ['full' => $full, 'fr' => $fr];
        if ($full) { $urls["$s|H"] = t_url($s, !empty($fr['synth']) ? '1y' : '6mo', '1h'); $urls["$s|D"] = t_url($s, '2y', '1d'); }
        else $urls["$s|H"] = t_url($s, '5d', '1h');
        if (t_want5($r)) $urls["$s|M"] = t_url($s, '5d', '5m');
        if ($r && !empty($r['v10']) && ($r['signal'] ?? 'WAIT') !== 'WAIT' && !t_m1_get($s, 120)) $urls["$s|1"] = t_url($s, '2d', '1m');   // v10 ticks 1-3 (3m)
    }
    $b = t_fetch($urls);
    $work = []; $need = [];
    foreach ($plan as $s => $p) {
        [$H, $m] = t_parse($b["$s|H"] ?? null);
        if (!$H) { t_fail($scan, $s, $now, ($b["$s|H"] ?? null) ? 'no 1H bars' : 'request failed'); continue; }
        $fr = $p['fr'];
        if ($p['full']) {
            if (empty($b["$s|D"])) { t_fail($scan, $s, $now, 'daily request failed'); continue; }
            [$D, $mD] = t_parse($b["$s|D"]);
            $tz = $m['tz'] ?: (($mD['tz'] ?? '') ?: t_exchanges()[t_exchange($s)]['tz']);
            $synth = !$D || count($D['t']) < 60;   // Yahoo keeps no daily history for a few indices: build it from 1H
            $fr = ['v' => 2, 'src' => 'yahoo', 'tz' => $tz, 'synth' => $synth, '1H' => t_merge(null, $H, $synth ? 2400 : T_KEEP_H),
                '1D' => $synth ? t_daily_from_hourly($H, $tz) : t_merge(null, t_norm_last($D), T_KEEP_D), 'full' => $now];
            if ($synth && count($H['t']) < 1200) $need["$s|H1Y"] = t_url($s, '1y', '1h');
        } else {
            $fr['1H'] = t_merge($fr['1H'], $H, !empty($fr['synth']) ? 2400 : T_KEEP_H);
            if (empty($fr['synth']) && $m['cs'] && $m['rmt'] >= $m['cs'] && t_day_index($fr['1D'], $m['cs'], $m['ce']) === null) $need["$s|D5"] = t_url($s, '5d', '1d');
        }
        $work[$s] = [$fr, $m];
    }
    if ($need) {
        $b2 = t_fetch($need);
        foreach ($work as $s => [$fr, $m]) {
            if (isset($need["$s|H1Y"])) { [$H] = t_parse($b2["$s|H1Y"] ?? null); if ($H && count($H['t']) > count($fr['1H']['t'])) $fr['1H'] = t_merge(null, $H, 2400); }
            if (isset($need["$s|D5"])) { [$D] = t_parse($b2["$s|D5"] ?? null); if ($D) $fr['1D'] = t_merge($fr['1D'], t_norm_last($D), T_KEEP_D); }
            $work[$s] = [$fr, $m];
        }
    }
    foreach ($work as $s => [$fr, $m]) {
        if (!empty($fr['synth'])) $fr['1D'] = t_daily_from_hourly($fr['1H'], $fr['tz']);
        $dayOk = true;
        if ($m['cs'] && $m['rmt'] >= $m['cs']) {
            $k = t_day_index($fr['1D'], $m['cs'], $m['ce']);
            if ($k === null) $dayOk = false;
            elseif (empty($fr['synth']) && $m['px'] > 0 && $m['hi'] > 0 && $m['lo'] > 0) {   // live day high / low / last from the quote
                $o = $fr['1D']['o'][$k]; $c = t_r($m['px']);
                $fr['1D']['c'][$k] = $c; $fr['1D']['h'][$k] = max(t_r($m['hi']), $o, $c); $fr['1D']['l'][$k] = min(t_r($m['lo']), $o, $c);
                if ($m['vol'] > 0) $fr['1D']['v'][$k] = $m['vol'];
            }
        }
        [$M5] = isset($b["$s|M"]) ? t_parse($b["$s|M"]) : [null];
        if (isset($b["$s|1"])) { [$M1x] = t_parse($b["$s|1"]); if ($M1x && count($M1x['t']) > 30) t_m1_put($s, $M1x); }
        t_finish($s, $fr, $m, $dayOk, $scan, $now, ['src' => 'yahoo', 'ukey' => ''], $M5 ?: null);
    }
}
function t_process_upstox(array $syms, array &$scan, int $now): void {
    $today = t_up_ymd($now); $urls = []; $plan = [];
    foreach ($syms as $s) {
        $key = (string)t_up_key($s); $ex = t_exchange($s); $fr = t_read_frames($s); $r = $scan['rows'][$s] ?? null;
        $full = t_needs_full($fr, $r, $now, 'upstox', $key);
        $k = rawurlencode($key);
        $urls["$s|I"] = t_up_req("/v3/historical-candle/intraday/$k/hours/1");
        if (t_want5($r)) $urls["$s|M"] = t_up_req("/v3/historical-candle/intraday/$k/minutes/5");
        if ($full) {
            $urls["$s|H"] = t_up_req("/v3/historical-candle/$k/hours/1/$today/" . t_up_ymd($now - 88 * 86400));
            $urls["$s|D"] = t_up_req("/v3/historical-candle/$k/days/1/$today/" . t_up_ymd($now - 730 * 86400));
        }
        $plan[$s] = [$full, $fr, $key, $ex];
    }
    $b = t_fetch($urls, $codes);
    if (in_array(401, $codes, true)) t_up_state(['auth_error' => $now]);
    $quotes = t_up_quotes(array_column($plan, 2));
    foreach ($plan as $s => [$full, $fr, $key, $ex]) {
        $I = t_up_parse($b["$s|I"] ?? null);
        if ($full) {
            $H = t_up_parse($b["$s|H"] ?? null); $D = t_up_parse($b["$s|D"] ?? null, t_open_offset($ex));
            if (!$H || !$D) { t_fail($scan, $s, $now, 'upstox history unavailable (HTTP ' . ($codes["$s|H"] ?? 0) . '/' . ($codes["$s|D"] ?? 0) . ')'); continue; }
            $fr = ['v' => 2, 'src' => 'upstox', 'tz' => 'Asia/Kolkata', 'synth' => false, '1H' => t_merge(null, $H, T_KEEP_H), '1D' => t_merge(null, $D, T_KEEP_D), 'full' => $now];
        }
        if ($I) $fr['1H'] = t_merge($fr['1H'], $I, T_KEEP_H);
        [$cs, $ce] = t_today_window($ex, $now);
        $q = $quotes[$key] ?? null;
        $ltt = (int)((float)($q['last_trade_time'] ?? 0) / 1000);
        $lastI = ($I && $I['t']) ? $I['t'][count($I['t']) - 1] : 0;
        $rmt = max($ltt, $lastI);
        $dayOk = true;
        if ($cs && $rmt >= $cs) {
            $D = $fr['1D']; $n = count($D['t']); $has = $n && $D['t'][$n - 1] >= $cs - 7200;
            $qo = $q['ohlc'] ?? null; $lp = (float)($q['last_price'] ?? 0);
            if ($qo && $lp > 0 && (float)($qo['open'] ?? 0) > 0) {
                $o = t_r((float)$qo['open']); $c = t_r($lp);
                $h = max(t_r((float)$qo['high']), $o, $c); $l = min(t_r((float)$qo['low']), $o, $c); $v = (float)($q['volume'] ?? 0);
                if ($has) { $D['o'][$n - 1] = $o; $D['h'][$n - 1] = $h; $D['l'][$n - 1] = $l; $D['c'][$n - 1] = $c; if ($v > 0) $D['v'][$n - 1] = $v; }
                else { $D['t'][] = $cs; $D['o'][] = $o; $D['h'][] = $h; $D['l'][] = $l; $D['c'][] = $c; $D['v'][] = $v; }
            } elseif (!$has && $I) {   // no quote: build today's bar from today's hourly candles
                $sub = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
                foreach ($I['t'] as $i => $t) if ($t >= $cs - 7200) foreach ($sub as $kk => $_) $sub[$kk][] = $I[$kk][$i];
                if ($sub['t']) { $g = t_group($sub, fn($t) => 0); foreach ($g as $kk => $vv) $D[$kk][] = $kk === 't' ? $cs : $vv[0]; }
                else $dayOk = false;
            } elseif (!$has) $dayOk = false;
            $fr['1D'] = $D;
        }
        $M5 = isset($b["$s|M"]) ? t_up_parse($b["$s|M"]) : null;
        t_finish($s, $fr, ['rmt' => $rmt, 'cs' => $cs, 'ce' => $ce], $dayOk, $scan, $now, ['src' => 'upstox', 'ukey' => $key], $M5);
    }
}
/** Runs one scan step when something is due. Returns true if work was done. */
function t_scan_step(bool $force = false, int $chunk = T_CHUNK): bool {
    t_ensure_dirs();
    $lock = fopen(T_DATA . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return false;
    try {
        $now = t_now();
        $scan = t_scan_load();
        if ($force) $scan['force'] = $now;
        if (t_up_map_due($now)) { t_up_build_map($now); return true; }   // refresh Upstox instrument keys (about daily)
        $due = t_due($scan, $now);
        if ($due) t_process(array_slice(array_keys($due), 0, $chunk), $scan, $now);
        if (!$due && !$force) return false;
        $scan['rows'] = array_intersect_key($scan['rows'], t_symbols());
        $scan['fail'] = array_intersect_key($scan['fail'], t_symbols());
        $scan['updated'] = $now;
        t_write_json(T_DATA . '/scan.json', $scan);
        t_siglog_flush($now);
        return (bool)$due;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

// ---------------------------------------------------------------------------
// Views: live/closed override, world markets, meta
// ---------------------------------------------------------------------------
/** Tier-1 queue position: 'signal' (CALL/PUT, live, at zone), 'queue' (UNDER PROCESS: live, 3/4+, not at zone yet),
 *  'out' (not in the Tier-1 scanner: 2/4 alignment or market not live). */
function t_tier1(array $r): string {
    if (!empty($r['choppy'])) return 'out';   // disturbed (flat-line) chart
    $al = !empty($r['v10']) && isset($r['al5']) ? (int)$r['al5'] : (int)($r['alignment'] ?? 0);   // v10 trend: 3/5 timeframes
    // Exceptional 2/4 split (owner, 4 Nov 2026): backtest-validated, so it ranks on Tier-1 despite al=2 --
    // every other 2/4 (or any alignment below 3) still stays out.
    if (($al < 3 && empty($r['split24'])) || !in_array($r['bias'] ?? '', ['UP', 'DOWN'], true)) return 'out';
    if (empty($r['live'])) return 'out';
    return ($r['signal'] ?? 'WAIT') !== 'WAIT' ? 'signal' : 'queue';
}
const T_STATUS_TEXT = ['live' => 'Live', 'closed' => 'Market closed', 'break' => 'Trading break', 'quiet' => 'No live trades (holiday or halt)',
    'stale' => 'Refreshing data', 'pending' => 'Not scanned yet'];
/** A symbol only carries a CALL/PUT while its market is live. */
function t_apply_status(string $sym, array $r, ?array $row, int $now): array {
    $st = t_status($sym, $row, $now);
    $r['status'] = $st['state']; $r['live'] = $st['live']; $r['exchange'] = t_exchanges()[t_exchange($sym)]['venue'];
    if (($r['rule'] ?? 1) < 4 && ($r['signal'] ?? 'WAIT') !== 'WAIT' && empty($r['setup'])) { $r['setup'] = 'trend'; $r['remark'] = 'Long target'; }   // rows stored before v4 were all trend entries
    if (($r['rule'] ?? 1) < 2 && in_array($r['bias'] ?? '', ['UP', 'DOWN'], true)) {   // row stored before the rule change: re-derive from its distance to the zone
        $b = (float)T_DEFAULT_KNOBS['zone_band']; $d = (float)($r['zone_dist_atr'] ?? 99);
        $at = $r['bias'] === 'UP' ? $d <= $b : $d >= -$b;
        $r['signal'] = $at ? ($r['bias'] === 'UP' ? 'CALL' : 'PUT') : 'WAIT'; $r['zone_touch'] = $at; $r['rule'] = 2;
    }
    if (!$st['live']) { $r['signal'] = 'WAIT'; $r['setup'] = null; $r['remark'] = null; }
    if (($r['signal'] ?? 'WAIT') === 'WAIT' || empty($r['setup'])) { $r['flow'] = null; $r['flow_at'] = null; $r['grade'] = null; $r['runway_type'] = null; }   // v8 status (both setups) lives only inside the zone
    if (($r['flow'] ?? null) !== 'FLY') $r['grade'] = null;
    $r['tier1'] = t_tier1($r);
    return $r;
}
function t_view_rows(array $scan, int $now): array {
    $out = [];
    foreach ($scan['rows'] as $s => $r) {
        if (!isset(t_symbols()[$s])) continue;
        $r = t_apply_status($s, $r, $r, $now);
        $zd = $r['zone_dist_atr'] ?? null; $r = t_public($r); unset($r['hi_52w'], $r['lo_52w']);
        if ($zd !== null) $r['gap'] = round((float)$zd, 1);   // coarse distance to the Pulse zone only
        $r['spark'] = array_map(fn($x) => (float)sprintf('%.4g', (float)$x), $r['spark'] ?? []);   // sparkline needs 4 digits only
        $r['rk'] = t_rank($s);
        $out[] = $r;
    }
    return $out;
}
function t_world(array $rows, int $now): array {
    $tot = []; $live = []; $quiet = [];
    foreach ($rows as $r) {
        $ex = $r['ex'] ?? t_exchange($r['symbol']);
        $tot[$ex] = ($tot[$ex] ?? 0) + 1;
        if ($r['live']) $live[$ex] = ($live[$ex] ?? 0) + 1;
        if ($r['status'] === 'quiet') $quiet[$ex] = ($quiet[$ex] ?? 0) + 1;
    }
    $X = t_exchanges(); $out = [];
    foreach (t_chips() as $key => [$label, $codes]) {
        $n = 0; $l = 0; $q = 0; $open = false; $brk = false; $next = null; $close = null;
        foreach ($codes as $c) {
            $n += $tot[$c] ?? 0; $l += $live[$c] ?? 0; $q += $quiet[$c] ?? 0;
            $ss = t_session($c, $now);
            if ($ss['open']) { $open = true; $close = max($close ?? 0, (int)$ss['close']); }
            elseif ($ss['state'] === 'break') $brk = true;
            if (!$ss['open'] && $ss['next']) $next = min($next ?? PHP_INT_MAX, (int)$ss['next']);
        }
        if (!$n) continue;
        $state = $open ? ($l === 0 && $q > 0 ? 'holiday' : 'open') : ($brk ? 'break' : 'closed');
        $venue = $key === 'FUT' ? 'CME · ICE · CBOT' : implode(' · ', array_map(fn($c) => $X[$c]['venue'], $codes));
        $out[] = ['key' => $key, 'name' => $label, 'venue' => $venue, 'state' => $state, 'local' => t_local($codes[0], $now), 'tz' => $X[$codes[0]]['tz'],
            'utc_offset' => (new DateTime('@' . $now))->setTimezone(new DateTimeZone($X[$codes[0]]['tz']))->format('P'),
            'opens_in' => $open ? null : ($next ? $next - $now : null), 'closes_in' => $open && $close ? $close - $now : null,
            'live' => $l, 'total' => $n,
            'iv' => array_values(array_filter(t_intervals($codes[0], $now), fn($x) => $x[1] > $now - 172800 && $x[0] < $now + 172800))];
    }
    return $out;
}
function t_meta(array $scan, array $rows, int $now): array {
    $total = count(t_symbols());
    $fetched = count(array_filter($scan['rows'], fn($r) => empty($r['mig'])));
    $dead = [];
    foreach ($scan['fail'] as $s => $f) if ((int)$f['n'] >= 3 && empty($scan['rows'][$s])) $dead[] = $s;
    $due = t_due($scan, $now);
    $done = $fetched + count(array_filter(array_keys($scan['fail']), fn($s) => empty($scan['rows'][$s])));
    return [
        'mode' => $fetched ? 'live' : 'starting',
        'updated_ist' => !empty($scan['updated']) ? t_ist((int)$scan['updated'], 'd M Y, H:i') . ' IST' : null,
        'progress' => $done >= $total ? 1.0 : round($done / $total, 3),
        'count' => count($rows), 'universe' => $total,
        'live' => count(array_filter($rows, fn($r) => $r['live'])),
        'due' => count($due), 'next_poll' => count($due) >= T_CHUNK ? 3 : (count($due) ? 8 : 20),
        'refresh_seconds' => T_REFRESH, 'failed' => $dead, 'now' => $now,
        'exchanges' => t_world($rows, $now),
    ];
}
