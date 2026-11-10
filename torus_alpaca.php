<?php
// ---------------------------------------------------------------------------
// Alpaca Markets adapter (US stocks + Nasdaq/NYSE ETFs, and the crypto coins
// Alpaca itself lists). Used only when a key/secret is set in config.php
// (or the fallback default below); otherwise Yahoo serves these symbols, same
// as before. The key/secret never leave the server. (owner, 5 Nov 2026)
//
// Alpaca is US-only: no forex, no metals/commodities, no international
// exchanges -- those stay on Yahoo (see chartpulse-delayed-symbols-yahoo-5nov.md).
// ---------------------------------------------------------------------------
declare(strict_types=1);

const T_ALP_STOCK_API  = 'https://data.alpaca.markets/v2/stocks';
const T_ALP_CRYPTO_API = 'https://data.alpaca.markets/v1beta3/crypto/us';

/** US-listed stocks & ETFs (from t_symbols(), market "US", minus the 5 index tickers Alpaca can't quote). */
const T_ALP_US = ['AAPL','MSFT','NVDA','AMZN','GOOGL','META','TSLA','AVGO','BRK-B','JPM','V','MA','UNH','LLY','XOM','JNJ','WMT','PG','HD','COST','ORCL','CRM','NFLX','ADBE','AMD','INTC','CSCO','QCOM','TXN','MU','AMAT','LRCX','ARM','TSM','SMCI','IBM','NOW','INTU','PEP','KO','MCD','SBUX','NKE','DIS','BA','CAT','GS','MS','BAC','WFC','C','AXP','PYPL','CVX','PFE','MRK','ABBV','TMO','UBER','ABNB','SHOP','PLTR','COIN','MSTR','HOOD','SOFI','AFRM','UPST','RIVN','LCID','NIO','BABA','PDD','JD','GME','AMC','MARA','RIOT','CVNA','APP','CRWV','CRCL','SNOW','CRWD','PANW','NET','DDOG','RBLX','DKNG','SNAP','SPOT','IONQ','RGTI','QBTS','SOUN','BBAI','RKLB','ASTS','OKLO','SMR','HIMS','ACHR','SNPS','FTNT','CTAS','PAYX','VRSK','TDY','EXPD','JKHY','TFX','CTSH','SPY','QQQ','IWM','DIA','TLT','GLD','SLV','XLF','XLE','XLK','SMH','ARKK','TQQQ','SOXL','IBIT','LIN','ACN','TMUS','VZ','T','CMCSA','PM','GE','GEV','RTX','LMT','HON','DE','UPS','LOW','TJX','BKNG','ISRG','VRTX','REGN','AMGN','GILD','BMY','ABT','DHR','SCHW','BLK','SPGI','CME','COF','PGR','F','GM','DAL','UAL','CCL','MRNA','ZM','TTD','ROKU','PINS','LULU','CMG','ANET','MRVL','KLAC','ADI','NXPI','ON','WDAY','TEAM','ZS','MDB','VST','CEG','CCJ','RDDT','NBIS','ALAB','TEM','LUNR','SERV','RXRX','PLUG','LAZR','OPEN','TLRY','BYND','CLSK','BITF','HUT','WULF','CIFR','IREN','APLD','NNE','LEU','UEC','DJT','BIDU','LI','XPEV','NTES','TCOM','BILI','NVO'];

/** Our symbol -> Alpaca crypto pair, limited to coins Alpaca's own /v2/assets?asset_class=crypto actually lists
 *  (checked live, 5 Nov 2026 -- Alpaca's crypto universe is much smaller than Yahoo's, so most of our 99 crypto
 *  symbols stay on Yahoo; only these are real-time via Alpaca). */
const T_ALP_CRYPTO = [
    'BTC-USD' => 'BTC/USD', 'ETH-USD' => 'ETH/USD', 'XRP-USD' => 'XRP/USD', 'SOL-USD' => 'SOL/USD', 'DOGE-USD' => 'DOGE/USD',
    'ADA-USD' => 'ADA/USD', 'HYPE32196-USD' => 'HYPE/USD', 'LINK-USD' => 'LINK/USD', 'BCH-USD' => 'BCH/USD', 'AVAX-USD' => 'AVAX/USD',
    'LTC-USD' => 'LTC/USD', 'SHIB-USD' => 'SHIB/USD', 'DOT-USD' => 'DOT/USD', 'UNI7083-USD' => 'UNI/USD', 'PEPE24478-USD' => 'PEPE/USD',
    'AAVE-USD' => 'AAVE/USD', 'ARB11841-USD' => 'ARB/USD', 'FIL-USD' => 'FIL/USD', 'RENDER-USD' => 'RENDER/USD', 'WIF-USD' => 'WIF/USD',
    'BONK-USD' => 'BONK/USD', 'TRUMP35336-USD' => 'TRUMP/USD', 'POL28321-USD' => 'POL/USD', 'ONDO-USD' => 'ONDO/USD', 'GRT6719-USD' => 'GRT/USD',
    'XTZ-USD' => 'XTZ/USD', 'CRV-USD' => 'CRV/USD', 'LDO-USD' => 'LDO/USD', 'BAT-USD' => 'BAT/USD',
];

// Key/secret come only from config.php (keys: 'alpaca_key_id', 'alpaca_secret_key') -- never hardcoded here,
// so they never end up in git history. Until config.php has them, t_alp_on() is false and Yahoo keeps serving
// these symbols, same as before.
function t_alp_key_id(): string { return trim((string)t_cfg('alpaca_key_id', '')); }
function t_alp_secret(): string { return trim((string)t_cfg('alpaca_secret_key', '')); }
function t_alp_on(): bool { return strlen(t_alp_key_id()) > 10 && strlen(t_alp_secret()) > 10; }
function t_alp_hdr(): array { return ['APCA-API-KEY-ID: ' . t_alp_key_id(), 'APCA-API-SECRET-KEY: ' . t_alp_secret()]; }
function t_alp_req(string $url): array { return ['u' => $url, 'h' => t_alp_hdr()]; }

/** Alpaca symbol for one of our symbols (stock ticker or "BASE/USD" crypto pair), or null (then Yahoo is used). */
function t_alp_key(string $sym): ?string {
    if (!t_alp_on()) return null;
    if (isset(T_ALP_CRYPTO[$sym])) return T_ALP_CRYPTO[$sym];
    // Alpaca writes share classes with a dot (BRK.B); ours use a dash (BRK-B). One unknown symbol makes Alpaca
    // reject the whole multi-symbol request, which took every other symbol in that batch down with it.
    return in_array($sym, T_ALP_US, true) ? str_replace('-', '.', $sym) : null;
}
/** Use Alpaca for this symbol right now? Not while it is in its cool-off after a failed history request
 *  (10 Nov 2026: a symbol Alpaca could not serve used to stop updating altogether -- live rows went stale
 *  for 15+ minutes and stayed on Tier 1. It now drops back to Yahoo for 30 minutes and Alpaca is tried again after). */
function t_alp_use(string $sym, array $scan, int $now): bool {
    return t_alp_key($sym) !== null && $now >= (int)($scan['alp_off'][$sym] ?? 0);
}
function t_alp_iso(int $ts): string { return gmdate('Y-m-d\TH:i:s\Z', $ts); }

/** Batched multi-symbol bars for one asset class, paginated via next_page_token. Returns alpaca-symbol => frame. */
function t_alp_bars(string $cls, array $alpSyms, string $tf, int $start, int $end): array {
    if (!$alpSyms) return [];
    $base = $cls === 'crypto' ? T_ALP_CRYPTO_API : T_ALP_STOCK_API;
    $out = [];
    // 10 Nov 2026: 49 symbols were stuck on "alpaca history unavailable". A long-range request for many symbols
    // runs to several pages; when one page failed the loop stopped and every symbol on the later pages came back
    // empty, then the whole batch was retried on the next scan step. Long ranges now go a few symbols at a time
    // and a failed page is retried once before giving up.
    $per = ($end - $start) > 30 * 86400 ? 6 : 100;
    foreach (array_chunk(array_values(array_unique($alpSyms)), $per) as $i => $chunk) {
        $syms = implode(',', $chunk);
        $page = null; $n = 0;
        do {
            $qs = 'symbols=' . $syms . '&timeframe=' . $tf . '&start=' . t_alp_iso($start) . '&end=' . t_alp_iso($end) . '&limit=10000&adjustment=raw';
            if ($cls !== 'crypto') $qs .= '&feed=iex';
            if ($page) $qs .= '&page_token=' . rawurlencode($page);
            $b = t_fetch(["b{$i}_{$n}" => t_alp_req("$base/bars?$qs")], $codes);
            $j = json_decode((string)($b["b{$i}_{$n}"] ?? ''), true);
            if (!is_array($j) || !array_key_exists('bars', $j)) {
                usleep(600000);
                $b = t_fetch(["b{$i}_{$n}" => t_alp_req("$base/bars?$qs")], $codes);
                $j = json_decode((string)($b["b{$i}_{$n}"] ?? ''), true);
            }
            if (!is_array($j)) break;
            foreach (($j['bars'] ?? []) as $s => $rows) {
                if (!isset($out[$s])) $out[$s] = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
                foreach ($rows as $x) {
                    $t = strtotime((string)($x['t'] ?? '')); $cl = (float)($x['c'] ?? 0);
                    if (!$t || $cl <= 0) continue;
                    $o = (float)($x['o'] ?? $cl); $h = (float)($x['h'] ?? 0); $l = (float)($x['l'] ?? 0);
                    $out[$s]['t'][] = $t; $out[$s]['o'][] = t_r($o); $out[$s]['h'][] = t_r(max($h, $o, $cl));
                    $out[$s]['l'][] = t_r($l > 0 ? min($l, $o, $cl) : min($o, $cl)); $out[$s]['c'][] = t_r($cl); $out[$s]['v'][] = (float)($x['v'] ?? 0);
                }
            }
            $page = $j['next_page_token'] ?? null; $n++;
        } while ($page && $n < 20);
    }
    return $out;
}

/** Batched snapshots (latest trade/quote + live day bar + prev day bar) for one asset class. alpaca-symbol => snapshot. */
function t_alp_snapshots(string $cls, array $alpSyms): array {
    if (!$alpSyms) return [];
    $base = $cls === 'crypto' ? T_ALP_CRYPTO_API : T_ALP_STOCK_API;
    $out = [];
    // 10 Nov 2026: 49 symbols were stuck on "alpaca history unavailable". A long-range request for many symbols
    // runs to several pages; when one page failed the loop stopped and every symbol on the later pages came back
    // empty, then the whole batch was retried on the next scan step. Long ranges now go a few symbols at a time
    // and a failed page is retried once before giving up.
    $per = ($end - $start) > 30 * 86400 ? 6 : 100;
    foreach (array_chunk(array_values(array_unique($alpSyms)), $per) as $i => $chunk) {
        $qs = 'symbols=' . implode(',', $chunk) . ($cls !== 'crypto' ? '&feed=iex' : '');
        $b = t_fetch(["s$i" => t_alp_req("$base/snapshots?$qs")], $codes);
        if (($codes["s$i"] ?? 0) === 401 || ($codes["s$i"] ?? 0) === 403) t_write_json(T_DATA . '/alpaca_state.json', ['auth_error' => t_now()]);
        $j = json_decode((string)($b["s$i"] ?? ''), true);
        if (!is_array($j)) continue;
        $snaps = $j['snapshots'] ?? $j;   // crypto wraps under "snapshots"; stocks are top-level symbol keys
        foreach ($snaps as $s => $snap) if (is_array($snap)) $out[$s] = $snap;
    }
    return $out;
}

function t_process_alpaca(array $syms, array &$scan, int $now): void {
    $plan = [];
    foreach ($syms as $s) {
        $alp = t_alp_key($s); if (!$alp) continue;
        $ex = t_exchange($s); $fr = t_read_frames($s); $r = $scan['rows'][$s] ?? null;
        $plan[$s] = [t_needs_full($fr, $r, $now, 'alpaca', $alp), $fr, $alp, $ex];
    }
    if (!$plan) return;
    $classOf = fn(string $alp) => str_contains($alp, '/') ? 'crypto' : 'stock';
    $frames1H = []; $frames1D = []; $frames5M = []; $snapAll = [];
    foreach (['stock', 'crypto'] as $cls) {
        $list = array_keys(array_filter($plan, fn($p) => $classOf($p[2]) === $cls));
        if (!$list) continue;
        $full = array_values(array_filter($list, fn($s) => $plan[$s][0]));
        $inc  = array_values(array_filter($list, fn($s) => !$plan[$s][0]));
        if ($full) {
            $frames1H += t_alp_bars($cls, array_map(fn($s) => $plan[$s][2], $full), '1Hour', $now - 95 * 86400, $now);
            $frames1D += t_alp_bars($cls, array_map(fn($s) => $plan[$s][2], $full), '1Day', $now - 735 * 86400, $now);
        }
        if ($inc) $frames1H += t_alp_bars($cls, array_map(fn($s) => $plan[$s][2], $inc), '1Hour', $now - 4 * 86400, $now);
        $need5 = array_values(array_filter($list, fn($s) => t_want5($scan['rows'][$s] ?? null)));
        if ($need5) $frames5M += t_alp_bars($cls, array_map(fn($s) => $plan[$s][2], $need5), '5Min', $now - 3 * 86400, $now);
        $snapAll += t_alp_snapshots($cls, array_map(fn($s) => $plan[$s][2], $list));
    }
    $fb = [];
    foreach ($plan as $s => [$full, $fr, $alp, $ex]) {
        $Hnew = $frames1H[$alp] ?? null;
        if ($full) {
            $Dfull = $frames1D[$alp] ?? null;
            if (!$Hnew || !$Dfull) { $scan['alp_off'][$s] = $now + 1800; unset($scan['fail'][$s]); $fb[] = $s; continue; }
            $fr = ['v' => 2, 'src' => 'alpaca', 'tz' => 'UTC', 'synth' => false, '1H' => t_merge(null, $Hnew, T_KEEP_H), '1D' => t_merge(null, $Dfull, T_KEEP_D), 'full' => $now];
        } elseif ($Hnew) {
            $fr['1H'] = t_merge($fr['1H'] ?? null, $Hnew, T_KEEP_H);
        }
        [$cs, $ce] = t_today_window($ex, $now);
        $snap = $snapAll[$alp] ?? null;
        $rmtTrade = 0;
        if ($snap) { $tt = $snap['latestTrade']['t'] ?? ($snap['latestQuote']['t'] ?? null); if ($tt) $rmtTrade = (int)strtotime((string)$tt); }
        $lastH = ($Hnew && $Hnew['t']) ? end($Hnew['t']) : 0;
        $rmt = max($rmtTrade, $lastH);
        $dayOk = true;
        if ($cs && $rmt >= $cs) {
            $D = $fr['1D']; $n = count($D['t']); $has = $n && $D['t'][$n - 1] >= $cs - 7200;
            $db = $snap['dailyBar'] ?? null;
            if ($db && (float)($db['o'] ?? 0) > 0) {
                $o = t_r((float)$db['o']); $c = t_r((float)($db['c'] ?? $db['o']));
                $h = max(t_r((float)($db['h'] ?? 0)), $o, $c); $l = min(t_r((float)($db['l'] ?? $o)), $o, $c); $v = (float)($db['v'] ?? 0);
                if ($has) { $D['o'][$n - 1] = $o; $D['h'][$n - 1] = $h; $D['l'][$n - 1] = $l; $D['c'][$n - 1] = $c; if ($v > 0) $D['v'][$n - 1] = $v; }
                else { $D['t'][] = $cs; $D['o'][] = $o; $D['h'][] = $h; $D['l'][] = $l; $D['c'][] = $c; $D['v'][] = $v; }
            } elseif (!$has && $Hnew) {
                $sub = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
                foreach ($Hnew['t'] as $i => $t) if ($t >= $cs - 7200) foreach ($sub as $kk => $_) $sub[$kk][] = $Hnew[$kk][$i];
                if ($sub['t']) { $g = t_group($sub, fn($t) => 0); foreach ($g as $kk => $vv) $D[$kk][] = $kk === 't' ? $cs : $vv[0]; }
                else $dayOk = false;
            } elseif (!$has) $dayOk = false;
            $fr['1D'] = $D;
        }
        $M5 = $frames5M[$alp] ?? null;
        t_finish($s, $fr, ['rmt' => $rmt, 'cs' => $cs, 'ce' => $ce], $dayOk, $scan, $now, ['src' => 'alpaca', 'ukey' => $alp], $M5);
    }
    if ($fb) t_process_yahoo($fb, $scan, $now);   // Alpaca could not serve these: Yahoo keeps them live meanwhile
}
