<?php
// ChartPulse GEX engine — dealer gamma / vanna / charm exposure from real option chains.
// US: Alpaca (contracts for OI + options snapshots for IV/greeks). India: Upstox option chain.
// Convention: dealers long calls / short puts customers' flow => call GEX +, put GEX -.
declare(strict_types=1);

require_once __DIR__ . '/torus_paths.php';
require_once __DIR__ . '/torus_upstox.php';   // t_cfg (reads config via TORUS_CONFIG), t_up_token
define('GEX_DIR', cp_data_dir() . '/gex');
const GEX_TTL = 60;               // seconds a computed chain is reused
const GEX_DAYS = 45;              // expiries considered (calendar days)
const GEX_US = ['SPY', 'QQQ', 'IWM', 'AAPL', 'NVDA', 'TSLA', 'MSFT', 'AMZN', 'META', 'GOOGL', 'AMD'];
const GEX_IN = [
    'NIFTY'     => ['key' => 'NSE_INDEX|Nifty 50',   'name' => 'NIFTY 50'],
    'BANKNIFTY' => ['key' => 'NSE_INDEX|Nifty Bank', 'name' => 'NIFTY BANK'],
    'FINNIFTY'  => ['key' => 'NSE_INDEX|Nifty Fin Service', 'name' => 'NIFTY FIN'],
];

function gex_cfg(string $k, $def = null) { return t_cfg($k, $def); }
function gex_first(array $keys): string {
    foreach ($keys as $k) { $v = trim((string)gex_cfg($k, '')); if ($v !== '') return $v; $e = getenv(strtoupper($k)); if ($e) return $e; }
    return '';
}
function gex_alpaca_hdr(): array {
    $id = gex_first(['alpaca_key', 'alpaca_key_id', 'alpaca_api_key', 'apca_api_key_id']);
    $sk = gex_first(['alpaca_secret', 'alpaca_secret_key', 'alpaca_api_secret', 'apca_api_secret_key']);
    return ($id && $sk) ? ['APCA-API-KEY-ID: ' . $id, 'APCA-API-SECRET-KEY: ' . $sk] : [];
}
function gex_alpaca_trade_base(): string {
    $b = gex_first(['alpaca_base_url', 'alpaca_endpoint']);
    return rtrim($b ?: 'https://paper-api.alpaca.markets', '/');
}
function gex_upstox_hdr(): array {
    $t = t_up_token();
    return $t ? ['Authorization: Bearer ' . $t, 'Accept: application/json'] : [];
}

function gex_get(string $url, array $hdr): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $hdr, CURLOPT_ENCODING => '']);
    $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($b === false || $code >= 400) return null;
    $j = json_decode($b, true);
    return is_array($j) ? $j : null;
}

// ---------- Black-Scholes (r = q = 0, adequate for short-dated exposure maps) ----------
function gex_npdf(float $x): float { return exp(-0.5 * $x * $x) / 2.5066282746310002; }
function gex_greeks(float $S, float $K, float $T, float $iv): array {
    if ($S <= 0 || $K <= 0 || $iv <= 0.001) return ['g' => 0.0, 'va' => 0.0, 'ch' => 0.0];
    $T = max($T, 1 / (365 * 24));                      // floor at 1 hour
    $sq = $iv * sqrt($T);
    $d1 = (log($S / $K) + 0.5 * $iv * $iv * $T) / $sq; $d2 = $d1 - $sq;
    $n = gex_npdf($d1);
    return [
        'g'  => $n / ($S * $sq),                        // gamma
        'va' => -$n * $d2 / $iv,                        // dDelta/dVol (same for call & put)
        'ch' => $n * $d2 / (2 * $T) / 365,              // delta decay per day (call; put identical at r=q=0)
    ];
}

// ---------- Data providers -> normalised rows: [K, type c|p, exp Y-m-d, oi, iv, gamma|null, vol, mid] ----------
function gex_us_chain(string $sym): ?array {
    $h = gex_alpaca_hdr(); if (!$h) return ['err' => 'alpaca_not_configured'];
    $lte = gmdate('Y-m-d', time() + GEX_DAYS * 86400); $today = gmdate('Y-m-d', time() - 5 * 3600);
    $spotJ = gex_get('https://data.alpaca.markets/v2/stocks/' . $sym . '/snapshot?feed=iex', $h);
    $spot = (float)($spotJ['latestTrade']['p'] ?? $spotJ['dailyBar']['c'] ?? 0);
    if ($spot <= 0) return ['err' => 'no_spot'];
    $lo = round($spot * 0.80, 2); $hi = round($spot * 1.20, 2);
    // Open interest (daily, from trading API)
    $oi = []; $tok = ''; $guard = 0;
    do {
        $u = gex_alpaca_trade_base() . "/v2/options/contracts?underlying_symbols=$sym&expiration_date_gte=$today&expiration_date_lte=$lte&strike_price_gte=$lo&strike_price_lte=$hi&limit=10000" . ($tok ? '&page_token=' . urlencode($tok) : '');
        $j = gex_get($u, $h); if (!$j) break;
        foreach ($j['option_contracts'] ?? [] as $c) $oi[$c['symbol']] = ['K' => (float)$c['strike_price'], 't' => $c['type'] === 'call' ? 'c' : 'p', 'e' => $c['expiration_date'], 'oi' => (float)($c['open_interest'] ?? 0)];
        $tok = (string)($j['next_page_token'] ?? '');
    } while ($tok && ++$guard < 10);
    // Snapshots (IV, greeks, volume, quotes)
    $snap = []; $tok = ''; $guard = 0;
    do {
        $u = "https://data.alpaca.markets/v1beta1/options/snapshots/$sym?feed=indicative&limit=1000&expiration_date_lte=$lte&strike_price_gte=$lo&strike_price_lte=$hi" . ($tok ? '&page_token=' . urlencode($tok) : '');
        $j = gex_get($u, $h); if (!$j) break;
        foreach ($j['snapshots'] ?? [] as $occ => $s) $snap[$occ] = $s;
        $tok = (string)($j['next_page_token'] ?? '');
    } while ($tok && ++$guard < 15);
    if (!$oi && !$snap) return ['err' => 'no_chain'];
    $rows = [];
    foreach ($oi + array_fill_keys(array_keys($snap), null) as $occ => $c) {
        $s = $snap[$occ] ?? [];
        if (!$c) { // parse OCC: SPY251128C00590000
            if (!preg_match('/(\d{6})([CP])(\d{8})$/', $occ, $m)) continue;
            $c = ['K' => (int)$m[3] / 1000, 't' => strtolower($m[2]), 'e' => '20' . substr($m[1], 0, 2) . '-' . substr($m[1], 2, 2) . '-' . substr($m[1], 4, 2), 'oi' => 0];
        }
        $b = (float)($s['latestQuote']['bp'] ?? 0); $a = (float)($s['latestQuote']['ap'] ?? 0);
        $rows[] = [$c['K'], $c['t'], $c['e'], $c['oi'], (float)($s['impliedVolatility'] ?? 0), isset($s['greeks']['gamma']) ? (float)$s['greeks']['gamma'] : null, (float)($s['dailyBar']['v'] ?? 0), ($a > 0 ? ($a + $b) / 2 : (float)($s['latestTrade']['p'] ?? 0))];
    }
    return ['spot' => $spot, 'mult' => 100, 'rows' => $rows, 'src' => 'alpaca', 'tz' => 'America/New_York', 'close' => '16:00'];
}

function gex_in_chain(string $sym): ?array {
    $h = gex_upstox_hdr(); if (!$h) return ['err' => 'upstox_not_configured'];
    $key = GEX_IN[$sym]['key']; $ek = urlencode($key);
    $cj = gex_get("https://api.upstox.com/v2/option/contract?instrument_key=$ek", $h);
    $exps = []; $lot = 0;
    foreach ($cj['data'] ?? [] as $c) { $exps[$c['expiry']] = 1; $lot = $lot ?: (int)($c['lot_size'] ?? 0); }
    $exps = array_keys($exps); sort($exps);
    $lim = date('Y-m-d', time() + GEX_DAYS * 86400); $today = date('Y-m-d');
    $exps = array_values(array_filter($exps, fn($e) => $e >= $today && $e <= $lim));
    if (!$exps) return ['err' => 'no_expiries'];
    $exps = array_slice($exps, 0, 6);
    $rows = []; $spot = 0.0;
    foreach ($exps as $e) {
        $j = gex_get("https://api.upstox.com/v2/option/chain?instrument_key=$ek&expiry_date=$e", $h);
        foreach ($j['data'] ?? [] as $r) {
            $spot = $spot ?: (float)($r['underlying_spot_price'] ?? 0);
            $K = (float)$r['strike_price'];
            foreach (['c' => 'call_options', 'p' => 'put_options'] as $t => $f) {
                $md = $r[$f]['market_data'] ?? []; $gk = $r[$f]['option_greeks'] ?? [];
                $iv = (float)($gk['iv'] ?? 0); if ($iv > 3) $iv /= 100;   // Upstox gives IV in %
                $rows[] = [$K, $t, $e, (float)($md['oi'] ?? 0), $iv, isset($gk['gamma']) ? (float)$gk['gamma'] : null, (float)($md['volume'] ?? 0), (float)($md['ltp'] ?? 0), (float)($md['prev_oi'] ?? 0)];
            }
        }
    }
    if ($spot <= 0) return ['err' => 'no_chain'];
    return ['spot' => $spot, 'mult' => $lot ?: 1, 'rows' => $rows, 'src' => 'upstox', 'tz' => 'Asia/Kolkata', 'close' => '15:30'];
}

// ---------- Exposure maths ----------
function gex_T(string $exp, string $tz, string $close): float {
    $z = new DateTimeZone($tz); $end = new DateTime("$exp $close", $z);
    return max(($end->getTimestamp() - time()) / (365 * 86400), 1 / (365 * 24));
}
function gex_total_at(array $rows, float $S, int $mult, array $Tmap): float {
    $t = 0.0;
    foreach ($rows as $r) { if ($r[3] <= 0 || $r[4] <= 0) continue; $g = gex_greeks($S, $r[0], $Tmap[$r[2]], $r[4])['g']; $t += ($r[1] === 'c' ? 1 : -1) * $g * $r[3] * $mult * $S * $S * 0.01; }
    return $t;
}

function gex_compute(array $ch, string $mode = 'all'): array {
    $S = $ch['spot']; $mult = (int)$ch['mult']; $rows = $ch['rows'];
    $exps = array_values(array_unique(array_column($rows, 2))); sort($exps);
    if ($mode === '0dte' && $exps) $rows = array_values(array_filter($rows, fn($r) => $r[2] === $exps[0]));
    if ($mode === 'week' && $exps) { $lim = date('Y-m-d', strtotime($exps[0]) + 6 * 86400); $rows = array_values(array_filter($rows, fn($r) => $r[2] <= $lim)); }
    $Tmap = []; foreach ($exps as $e) $Tmap[$e] = gex_T($e, $ch['tz'], $ch['close']);
    $st = []; $tot = ['gex' => 0.0, 'vanna' => 0.0, 'charm' => 0.0, 'coi' => 0.0, 'poi' => 0.0, 'cvol' => 0.0, 'pvol' => 0.0];
    foreach ($rows as $r) {
        [$K, $t, $e, $oi, $iv] = $r; if ($oi <= 0) continue;
        $gk = gex_greeks($S, $K, $Tmap[$e], $iv);
        $gam = ($r[5] !== null && $r[5] > 0) ? $r[5] : $gk['g'];
        $sg = $t === 'c' ? 1 : -1;
        $gex = $sg * $gam * $oi * $mult * $S * $S * 0.01;          // value of dealer hedge per 1% move
        $van = $sg * $gk['va'] * $oi * $mult * $S * 0.01;          // delta-$ change per +1 vol pt
        $chm = $sg * $gk['ch'] * $oi * $mult * $S;                 // delta-$ drift per day
        $k = (string)$K;
        $st[$k] = $st[$k] ?? ['k' => $K, 'gex' => 0.0, 'cg' => 0.0, 'pg' => 0.0, 'coi' => 0.0, 'poi' => 0.0, 'cvol' => 0.0, 'pvol' => 0.0, 'vanna' => 0.0, 'charm' => 0.0, 'doi' => 0.0];
        $st[$k]['gex'] += $gex; $st[$k][$t . 'g'] += $gex; $st[$k][$t . 'oi'] += $oi; $st[$k][$t . 'vol'] += $r[6];
        $st[$k]['vanna'] += $van; $st[$k]['charm'] += $chm;
        if (isset($r[8])) $st[$k]['doi'] += $sg * ($oi - $r[8]);
        $tot['gex'] += $gex; $tot['vanna'] += $van; $tot['charm'] += $chm; $tot[$t . 'oi'] += $oi; $tot[$t . 'vol'] += $r[6];
    }
    ksort($st, SORT_NUMERIC); $st = array_values($st);
    // Walls
    $cw = null; $pw = null; $absMax = null;
    foreach ($st as $x) {
        if ($x['cg'] > 0 && (!$cw || $x['cg'] > $cw['cg']) && $x['k'] >= $S * 0.97) $cw = $x;
        if ($x['pg'] < 0 && (!$pw || $x['pg'] < $pw['pg']) && $x['k'] <= $S * 1.03) $pw = $x;
        if (!$absMax || abs($x['gex']) > abs($absMax['gex'])) $absMax = $x;
    }
    // Zero-gamma flip: sweep spot ±8%
    $prof = []; $flip = null; $prev = null;
    for ($i = -40; $i <= 40; $i++) {
        $s = $S * (1 + $i * 0.002); $g = gex_total_at($rows, $s, $mult, $Tmap); $prof[] = [round($s, 2), round($g)];
        if ($prev && (($prev[1] < 0) !== ($g < 0))) { $f = $prev[0] + ($s - $prev[0]) * (abs($prev[1]) / (abs($prev[1]) + abs($g))); if ($flip === null || abs($f - $S) < abs($flip - $S)) $flip = $f; }
        $prev = [$s, $g];
    }
    // Max pain (nearest expiry) + expected move (ATM straddle, nearest expiry)
    $near = $exps[0] ?? null; $mp = null; $em = null;
    if ($near) {
        $nr = array_values(array_filter($ch['rows'], fn($r) => $r[2] === $near)); $ks = array_values(array_unique(array_column($nr, 0))); sort($ks);
        $best = INF; foreach ($ks as $p) { $pain = 0; foreach ($nr as $r) $pain += $r[3] * ($r[1] === 'c' ? max(0, $p - $r[0]) : max(0, $r[0] - $p)); if ($pain < $best) { $best = $pain; $mp = $p; } }
        $atm = null; foreach ($ks as $k) if ($atm === null || abs($k - $S) < abs($atm - $S)) $atm = $k;
        $c = $p = 0; foreach ($nr as $r) if ($r[0] == $atm) { if ($r[1] === 'c') $c = $r[7]; else $p = $r[7]; }
        if ($c > 0 && $p > 0) $em = ($c + $p) * 0.85;
    }
    $regime = $tot['gex'] >= 0 ? 'positive' : 'negative';
    if ($flip !== null) $regime = $S >= $flip ? 'positive' : 'negative';
    $round = fn($v) => round($v, 2);
    return [
        'spot' => $S, 'mult' => $mult, 'mode' => $mode, 'expiries' => $exps, 'near_expiry' => $near,
        'net_gex' => round($tot['gex']), 'net_vanna' => round($tot['vanna']), 'net_charm' => round($tot['charm']),
        'zero_gamma' => $flip ? $round($flip) : null, 'call_wall' => $cw['k'] ?? null, 'put_wall' => $pw['k'] ?? null,
        'major_level' => $absMax['k'] ?? null, 'max_pain' => $mp, 'expected_move' => $em ? $round($em) : null,
        'pcr_oi' => $tot['coi'] > 0 ? round($tot['poi'] / $tot['coi'], 2) : null,
        'pcr_vol' => $tot['cvol'] > 0 ? round($tot['pvol'] / $tot['cvol'], 2) : null,
        'regime' => $regime, 'hedge_per_1pct' => round(-$tot['gex']),  // + = dealers buy on a 1% rally
        'strikes' => array_map(fn($x) => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $x), array_values(array_filter($st, fn($x) => $x['k'] >= $S * 0.9 && $x['k'] <= $S * 1.1))),
        'profile' => $prof,
    ];
}

function gex_summary(string $sym, array $g): string {
    $f = $g['zero_gamma']; $S = $g['spot']; $u = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    $s = "$sym is in " . ($g['regime'] === 'positive' ? 'positive gamma: dealers sell rallies and buy dips, so moves tend to be damped and mean-reverting' : 'negative gamma: dealers chase the move, so volatility tends to expand');
    if ($f) $s .= '. Gamma flip ' . $u($f) . ($S >= $f ? ' sits below price; losing it would flip the regime' : ' sits above price; reclaiming it would calm the tape');
    if ($g['call_wall']) $s .= '. Call wall ' . $u($g['call_wall']) . ' acts as resistance';
    if ($g['put_wall']) $s .= ', put wall ' . $u($g['put_wall']) . ' as support';
    if ($g['expected_move']) $s .= '. Expected move to ' . $g['near_expiry'] . ': ±' . $u($g['expected_move']);
    return $s . '.';
}

function gex_market(string $sym): ?string { return isset(GEX_IN[$sym]) ? 'IN' : (in_array($sym, GEX_US, true) ? 'US' : null); }

function gex_get_symbol(string $sym, string $mode = 'all'): array {
    $sym = strtoupper($sym); $mk = gex_market($sym);
    if (!$mk) return ['ok' => false, 'error' => 'unsupported'];
    if (!is_dir(GEX_DIR)) @mkdir(GEX_DIR, 0755, true);
    $cf = GEX_DIR . "/chain_$sym.json";
    $ch = null;
    if (is_file($cf) && time() - filemtime($cf) < GEX_TTL) $ch = json_decode((string)file_get_contents($cf), true);
    if (!$ch) {
        $ch = $mk === 'US' ? gex_us_chain($sym) : gex_in_chain($sym);
        if (!empty($ch['rows'])) { $ch['ts'] = time(); file_put_contents($cf, json_encode($ch), LOCK_EX); }
        elseif (is_file($cf)) { $ch = json_decode((string)file_get_contents($cf), true); $ch['stale'] = true; }
    }
    if (empty($ch['rows'])) return ['ok' => false, 'error' => 'unavailable'];
    $g = gex_compute($ch, $mode);
    $g['symbol'] = $sym; $g['market'] = $mk; $g['src'] = $ch['src']; $g['ts'] = $ch['ts'] ?? time(); $g['stale'] = !empty($ch['stale']);
    $g['summary'] = gex_summary($sym, $g);
    $g['history'] = gex_history($sym, $g, $mode === 'all');
    $g['ok'] = true;
    return $g;
}

// Intraday snapshots every ~2 min (all-expiry view only): spot, net GEX, flip, walls
function gex_history(string $sym, array $g, bool $write): array {
    $f = GEX_DIR . "/hist_{$sym}_" . date('Ymd') . '.json';
    $h = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if ($write && (!$h || time() - end($h)['t'] >= 120)) {
        $h[] = ['t' => time(), 's' => $g['spot'], 'g' => $g['net_gex'], 'f' => $g['zero_gamma'], 'cw' => $g['call_wall'], 'pw' => $g['put_wall'],
                'k' => array_map(fn($x) => [$x['k'], round($x['gex'])], $g['strikes'])];
        $h = array_slice($h, -400);
        file_put_contents($f, json_encode($h), LOCK_EX);
    }
    return $h;
}
