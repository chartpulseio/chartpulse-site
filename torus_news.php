<?php
// ChartPulse News & Impact.
// Sources: public RSS feeds (no key), Finnhub and Marketaux (free keys pasted into config.php as finnhub_key / marketaux_key).
// Every story is tagged to instruments in our universe, graded for impact (high / medium / low) and sentiment,
// and carries the market reaction since it was published (from our own hourly bars).
declare(strict_types=1);
require_once __DIR__ . '/torus_engine.php';

const T_NEWS_TTL = 600;          // refresh the combined feed every 10 min
const T_NEWS_MX_TTL = 1200;      // Marketaux free plan: 100 requests/day, 3 stories per request
const T_NEWS_KEEP = 72 * 3600;   // stories older than 72 h drop out
const T_NEWS_MAX = 160;

function t_news_feeds(): array {
    $f = [
        ['ET Markets', 'https://economictimes.indiatimes.com/markets/rssfeeds/1977021501.cms', 'India'],
        ['ET Stocks', 'https://economictimes.indiatimes.com/markets/stocks/rssfeeds/2146842.cms', 'India'],
        ['Mint', 'https://www.livemint.com/rss/markets', 'India'],
        ['Business Standard', 'https://www.business-standard.com/rss/markets-106.rss', 'India'],
        ['Moneycontrol', 'https://www.moneycontrol.com/rss/marketreports.xml', 'India'],
        ['Moneycontrol', 'https://www.moneycontrol.com/rss/business.xml', 'India'],
        ['The Hindu BusinessLine', 'https://www.thehindubusinessline.com/markets/feeder/default.rss', 'India'],
        ['RBI', 'https://www.rbi.org.in/pressreleases_rss.xml', 'India'],
        ['SEBI', 'https://www.sebi.gov.in/sebirss.xml', 'India'],
        ['CNBC', 'https://www.cnbc.com/id/100003114/device/rss/rss.html', 'Global'],
        ['CNBC Markets', 'https://www.cnbc.com/id/20910258/device/rss/rss.html', 'Global'],
        ['MarketWatch', 'https://feeds.content.dowjones.io/public/rss/mw_topstories', 'Global'],
        ['Yahoo Finance', 'https://finance.yahoo.com/news/rssindex', 'Global'],
        ['BBC Business', 'https://feeds.bbci.co.uk/news/business/rss.xml', 'Global'],
        ['Investing.com', 'https://www.investing.com/rss/news_25.rss', 'Global'],
        ['Investing.com FX', 'https://www.investing.com/rss/news_1.rss', 'Forex'],
        ['CoinDesk', 'https://www.coindesk.com/arc/outboundfeeds/rss/', 'Crypto'],
        ['Cointelegraph', 'https://cointelegraph.com/rss', 'Crypto'],
    ];
    foreach ((array)t_cfg('news_feeds', []) as $x) if (is_array($x) && count($x) >= 2) $f[] = [(string)$x[0], (string)$x[1], (string)($x[2] ?? 'Global')];
    return $f;
}
function t_news_x_handles(): array {
    $h = (array)t_cfg('x_handles', ['NSEIndia', 'RBI', 'SEBI_India', 'BSEIndia', 'FinMinIndia', 'ETMarkets', 'CNBCTV18Live', 'Reuters', 'business', 'WSJmarkets']);
    return array_values(array_filter(array_map(fn($x) => preg_replace('/[^A-Za-z0-9_]/', '', (string)$x), $h)));
}

/** Parallel GET. key => url. Returns key => [code, body]. */
function t_news_get(array $urls, int $timeout = 9): array {
    $fix = getenv('TORUS_NEWS_FIXTURES');
    if ($fix) { $o = []; foreach ($urls as $k => $u) { $p = "$fix/" . md5($u) . '.xml'; $o[$k] = is_file($p) ? [200, file_get_contents($p)] : [404, null]; } return $o; }
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $k => $u) {
        $h = curl_init($u);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, application/json;q=0.9, */*;q=0.8', 'Accept-Language: en-IN,en;q=0.9']]);
        curl_multi_add_handle($mh, $h); $hs[$k] = $h;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st == CURLM_OK);
    $o = [];
    foreach ($hs as $k => $h) {
        $code = (int)curl_getinfo($h, CURLINFO_HTTP_CODE); $body = curl_multi_getcontent($h);
        $o[$k] = [$code, $code >= 200 && $code < 300 ? $body : null];
        curl_multi_remove_handle($mh, $h); curl_close($h);
    }
    curl_multi_close($mh);
    return $o;
}

function t_news_text(?string $s, int $max = 0): string {
    $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($max && mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
    return $s;
}
function t_news_url(?string $u): string { $u = trim((string)$u); return preg_match('#^https?://#i', $u) ? $u : ''; }

/** RSS 2.0 / RSS 1.0 / Atom → list of stories. */
function t_news_parse(string $xml, string $src, string $reg): array {
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$x) return [];
    $items = [];
    if (isset($x->channel->item)) foreach ($x->channel->item as $i) $items[] = $i;
    elseif (isset($x->item)) foreach ($x->item as $i) $items[] = $i;          // RSS 1.0
    elseif (isset($x->entry)) foreach ($x->entry as $i) $items[] = $i;        // Atom
    $out = [];
    foreach (array_slice($items, 0, 40) as $i) {
        $ns = $i->getNamespaces(true);
        $title = t_news_text((string)$i->title, 220);
        $link = (string)$i->link; if (!$link && isset($i->link['href'])) $link = (string)$i->link['href'];
        if (!$link && isset($i->guid)) $link = (string)$i->guid;
        $date = (string)($i->pubDate ?? '') ?: (string)($i->published ?? '') ?: (string)($i->updated ?? '');
        if (!$date && isset($ns['dc'])) $date = (string)$i->children($ns['dc'])->date;
        $desc = (string)($i->description ?? '') ?: (string)($i->summary ?? '') ?: (string)($i->content ?? '');
        $img = '';
        if (isset($i->enclosure['url']) && str_starts_with((string)$i->enclosure['type'], 'image')) $img = (string)$i->enclosure['url'];
        if (!$img && isset($ns['media'])) {
            $m = $i->children($ns['media']);
            if (isset($m->content)) foreach ($m->content as $c) { if (isset($c->attributes()->url)) { $img = (string)$c->attributes()->url; break; } }
            if (!$img && isset($m->thumbnail)) $img = (string)$m->thumbnail->attributes()->url;
            if (!$img && isset($m->group->content)) $img = (string)$m->group->content->attributes()->url;
        }
        if (!$img && preg_match('/<img[^>]+src=["\']([^"\']+)/i', $desc, $mm)) $img = $mm[1];
        $ts = $date ? strtotime($date) : false;
        if (!$title || !($link = t_news_url($link)) || !$ts) continue;
        $out[] = ['t' => $title, 's' => t_news_text($desc, 300), 'u' => $link, 'src' => $src, 'reg' => $reg, 'ts' => (int)$ts, 'img' => t_news_url($img)];
    }
    return $out;
}

/* ---------- tagging: story → instruments in our universe ---------- */
function t_news_matcher(): array {
    static $m = null;
    if ($m !== null) return $m;
    $syms = t_symbols();
    $alias = [   // case-insensitive phrases → symbols (only those in the universe are kept)
        'nifty 50' => ['^NSEI'], 'nifty' => ['^NSEI'], 'sensex' => ['^BSESN'], 'bank nifty' => ['^NSEBANK'], 'banknifty' => ['^NSEBANK'],
        'nifty it' => ['^CNXIT'], 'nifty pharma' => ['^CNXPHARMA'], 'nifty auto' => ['^CNXAUTO'], 'nifty metal' => ['^CNXMETAL'], 'nifty fmcg' => ['^CNXFMCG'],
        'nifty realty' => ['^CNXREALTY'], 'psu bank' => ['^CNXPSUBANK'], 'rbi' => ['^NSEBANK'], 'repo rate' => ['^NSEBANK'], 'rupee' => ['USDINR=X'],
        'gold' => ['GC=F'], 'silver' => ['SI=F'], 'crude' => ['CL=F'], 'brent' => ['BZ=F'], 'oil prices' => ['CL=F'], 'natural gas' => ['NG=F'], 'copper' => ['HG=F'],
        'bitcoin' => ['BTC-USD'], 'ethereum' => ['ETH-USD'], 'ether' => ['ETH-USD'], 'solana' => ['SOL-USD'], 'xrp' => ['XRP-USD'], 'dogecoin' => ['DOGE-USD'],
        'dollar index' => ['DX-Y.NYB'], 'nasdaq' => ['^NDX'], 's&p 500' => ['^GSPC'], 'wall street' => ['^GSPC'], 'dow jones' => ['^DJI'], 'the dow' => ['^DJI'],
        'federal reserve' => ['^GSPC'], 'fomc' => ['^GSPC'], 'powell' => ['^GSPC'], 'treasury yields' => ['ZN=F'], 'nikkei' => ['^N225'], 'hang seng' => ['^HSI'],
        'ftse' => ['^FTSE'], 'dax' => ['^GDAXI'], 'kospi' => ['^KS11'], 'euro' => ['EURUSD=X'], 'yen' => ['USDJPY=X'], 'pound' => ['GBPUSD=X'], 'sterling' => ['GBPUSD=X'],
    ];
    $ci = [];
    foreach ($alias as $k => $v) { $v = array_values(array_filter($v, fn($s) => isset($syms[$s]))); if ($v) $ci[$k] = $v; }
    $stop = array_flip(['Target', 'Gap', 'Visa', 'Shell', 'Oracle', 'Meta', 'Block', 'Snap', 'Square', 'Energy', 'Power', 'Gold', 'Silver', 'Copper', 'Coffee', 'Sugar',
        'Cotton', 'Cocoa', 'Wheat', 'Corn', 'Soybeans', 'Platinum', 'Palladium', 'Natural Gas', 'Crude Oil WTI', 'Brent Crude', 'Heating Oil', 'Lean Hogs', 'Live Cattle', 'Bitcoin', 'Ethereum']);
    $cs = [];
    foreach ($syms as $s => $mk) {
        if (in_array($mk, ['Forex', 'Futures', 'Commodities', 'MCX Commodities'], true)) continue;
        $n = t_name($s);
        foreach (array_unique([$n, preg_replace('/\s+(Ltd|Limited|Inc|Corp|ADR|PV|Holdings|Group)\.?$/i', '', $n)]) as $v) {
            $v = trim($v);
            if (mb_strlen($v) >= 4 && !isset($stop[$v]) && !str_starts_with($v, '^')) $cs[$v][] = $s;
        }
        if (str_ends_with($s, '.NS')) { $b = substr($s, 0, -3); if (strlen($b) >= 3 && ctype_upper(str_replace(['&', '-'], '', $b))) $cs[$b][] = $s; }
    }
    $q = fn($a) => implode('|', array_map(fn($k) => preg_quote($k, '/'), $a));
    uksort($cs, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); uksort($ci, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return $m = ['cs' => $cs, 'ci' => $ci, 'rcs' => '/(?<![\w&])(' . $q(array_keys($cs)) . ')(?![\w&])/u', 'rci' => '/(?<![\w&])(' . $q(array_keys($ci)) . ')(?![\w&])/iu'];
}
function t_news_tag(string $text): array {
    $m = t_news_matcher(); $out = [];
    if (preg_match_all($m['rcs'], $text, $a)) foreach ($a[1] as $w) foreach ($m['cs'][$w] ?? [] as $s) $out[$s] = 1;
    if (count($out) < 3 && preg_match_all($m['rci'], $text, $a)) foreach ($a[1] as $w) foreach ($m['ci'][mb_strtolower($w)] ?? [] as $s) $out[$s] = 1;
    return array_slice(array_keys($out), 0, 3);
}

/* ---------- impact and sentiment (keyword model; Marketaux sentiment wins when present) ---------- */
function t_news_grade(string $title, string $sum): array {
    $t = mb_strtolower($title); $all = $t . ' ' . mb_strtolower($sum);
    $hi = ['repo rate', 'rate cut', 'rate hike', 'monetary policy', 'rbi policy', 'fomc', 'federal reserve', 'fed ', 'powell', 'inflation', 'cpi ', 'gdp', 'union budget', 'budget',
        'tariff', 'war ', 'sanction', 'crash', 'plunge', 'circuit', 'default', 'recession', 'opec', 'election result', 'lockdown', 'emergency', 'downgrade', 'bankrupt', 'sebi bans', 'ban on'];
    $md = ['earnings', 'results', 'q1', 'q2', 'q3', 'q4', 'quarterly', 'profit', 'revenue', 'guidance', 'upgrade', 'target price', 'ipo', 'merger', 'acquisition', 'acquire', 'buyback',
        'dividend', 'crude', 'oil', 'gold', 'dollar', 'yield', 'fii', 'fpi', 'dii', 'order win', 'stake', 'block deal', 'rally', 'slump', 'record high', 'all-time high', 'etf', 'outflows', 'inflows'];
    $pos = ['surge', 'soar', 'jump', 'rally', 'gain', 'rise', 'rises', 'rose', 'climb', 'record high', 'all-time high', 'beat', 'beats', 'upgrade', 'strong', 'bullish', 'boost', 'rebound',
        'outperform', 'raises', 'higher', 'profit up', 'wins', 'order win', 'approval', 'buyback', 'upbeat', 'recovers', 'green'];
    $neg = ['fall', 'falls', 'fell', 'drop', 'slump', 'plunge', 'crash', 'tumble', 'slide', 'decline', 'miss', 'misses', 'downgrade', 'weak', 'bearish', 'lower', 'loss', 'sell-off',
        'selloff', 'warning', 'probe', 'fraud', 'ban', 'default', 'cut to', 'slashes', 'layoff', 'red', 'worst', 'fears', 'concern', 'tariff'];
    // whole words only ("ban" must not match "bank", "red" must not match "reduced")
    $has = function (array $list, string $s): int { static $rx = []; $k = md5(implode('|', $list));
        $rx[$k] ??= '/(?<![a-z0-9])(' . implode('|', array_map(fn($w) => preg_quote(trim($w), '/'), $list)) . ')(?![a-z0-9])/u';
        return preg_match_all($rx[$k], $s, $m) ? count(array_unique($m[1])) : 0; };
    $h = $has($hi, $all); $m = $has($md, $all);
    $imp = $h ? 'high' : ($m ? 'medium' : 'low');
    $sc = ($has($pos, $t) * 2 + $has($pos, $all)) - ($has($neg, $t) * 2 + $has($neg, $all));
    $ss = max(-1, min(1, $sc / 5));
    return [$imp, $ss];
}
function t_news_sent(float $s): string { return $s >= 0.2 ? 'bull' : ($s <= -0.2 ? 'bear' : 'neu'); }

/** Price just before a story was published: close of the last 1H bar that ended before it. */
function t_news_base(string $sym, int $ts): ?float {
    static $cache = [];
    if (!array_key_exists($sym, $cache)) { $f = t_read_frames($sym); $cache[$sym] = $f ? $f['1H'] : null; }
    $H = $cache[$sym]; if (!$H || empty($H['t'])) return null;
    $n = count($H['t']); if ($ts < $H['t'][0]) return null;
    for ($i = $n - 1; $i >= 0; $i--) if ($H['t'][$i] + 3600 <= $ts) return (float)$H['c'][$i] ?: null;
    return (float)$H['o'][0] ?: null;
}

/* ---------- API sources ---------- */
function t_news_finnhub(): array {
    $k = trim((string)t_cfg('finnhub_key', ''));
    if ($k === '') return [[], null];
    $cat = ['general' => 'Global', 'forex' => 'Forex', 'crypto' => 'Crypto'];
    $urls = []; foreach ($cat as $c => $_) $urls[$c] = 'https://finnhub.io/api/v1/news?category=' . $c . '&token=' . rawurlencode($k);
    $out = []; $ok = 0;
    foreach (t_news_get($urls) as $c => [$code, $body]) {
        $j = $body ? json_decode($body, true) : null; if (!is_array($j)) continue; $ok++;
        foreach (array_slice($j, 0, 40) as $x) {
            $u = t_news_url($x['url'] ?? ''); $t = t_news_text($x['headline'] ?? '', 220);
            if ($u && $t && !empty($x['datetime'])) $out[] = ['t' => $t, 's' => t_news_text($x['summary'] ?? '', 300), 'u' => $u, 'src' => (string)($x['source'] ?? 'Finnhub') . ' · Finnhub',
                'reg' => $cat[$c], 'ts' => (int)$x['datetime'], 'img' => t_news_url($x['image'] ?? '')];
        }
    }
    return [$out, ['name' => 'Finnhub API', 'ok' => $ok > 0, 'n' => count($out)]];
}
function t_news_marketaux(int $now): array {
    $k = trim((string)t_cfg('marketaux_key', ''));
    if ($k === '') return [[], null];
    $p = T_DATA . '/news_mx.json'; $st = t_read_json($p, ['at' => 0, 'items' => [], 'ok' => false]);
    if ($now - (int)$st['at'] >= T_NEWS_MX_TTL) {
        $u = 'https://api.marketaux.com/v1/news/all?countries=in,us&filter_entities=true&must_have_entities=true&language=en&limit=3&api_token=' . rawurlencode($k);
        [$code, $body] = t_news_get(['mx' => $u])['mx'];
        $j = $body ? json_decode($body, true) : null; $st['at'] = $now; $st['ok'] = is_array($j) && isset($j['data']);
        foreach (($j['data'] ?? []) as $x) {
            $url = t_news_url($x['url'] ?? ''); $ts = strtotime((string)($x['published_at'] ?? '')); if (!$url || !$ts) continue;
            $ents = []; foreach (($x['entities'] ?? []) as $e) $ents[] = ['sym' => (string)($e['symbol'] ?? ''), 'name' => (string)($e['name'] ?? ''), 'sent' => (float)($e['sentiment_score'] ?? 0)];
            $st['items'][md5($url)] = ['t' => t_news_text($x['title'] ?? '', 220), 's' => t_news_text($x['description'] ?? ($x['snippet'] ?? ''), 300), 'u' => $url,
                'src' => (string)($x['source'] ?? 'Marketaux') . ' · Marketaux', 'reg' => 'Global', 'ts' => (int)$ts, 'img' => t_news_url($x['image_url'] ?? ''), 'mx' => $ents];
        }
        $st['items'] = array_filter($st['items'], fn($x) => $now - $x['ts'] < T_NEWS_KEEP);
        t_write_json($p, $st);
    }
    return [array_values($st['items']), ['name' => 'Marketaux API', 'ok' => (bool)$st['ok'], 'n' => count($st['items'])]];
}

/* ---------- build + cache ---------- */
function t_news_build(int $now): array {
    $feeds = t_news_feeds(); $urls = [];
    foreach ($feeds as $i => [$n, $u]) $urls[$i] = $u;
    $res = t_news_get($urls);
    $all = []; $srcs = [];
    foreach ($feeds as $i => [$n, $u, $reg]) {
        [$code, $body] = $res[$i]; $items = $body ? t_news_parse($body, $n, $reg) : [];
        $srcs[] = ['name' => $n, 'ok' => (bool)$items, 'n' => count($items), 'code' => $code];
        array_push($all, ...$items);
    }
    [$fh, $fs] = t_news_finnhub(); if ($fs) { $srcs[] = $fs; array_push($all, ...$fh); }
    [$mx, $ms] = t_news_marketaux($now); if ($ms) { $srcs[] = $ms; array_push($all, ...$mx); }
    // de-duplicate by link and by headline, newest first, within the window
    usort($all, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $seen = []; $out = [];
    foreach ($all as $x) {
        if ($x['ts'] > $now + 600 || $now - $x['ts'] > T_NEWS_KEEP) continue;
        $k1 = preg_replace('~[?#].*$~', '', $x['u']); $k2 = substr(preg_replace('/[^a-z0-9]/', '', mb_strtolower($x['t'])), 0, 70);
        if (isset($seen[$k1]) || isset($seen[$k2])) continue; $seen[$k1] = $seen[$k2] = 1;
        [$imp, $ss] = t_news_grade($x['t'], $x['s']);
        $syms = t_news_tag($x['t'] . ' ' . $x['s']);
        if (!empty($x['mx'])) {   // Marketaux entities: map tickers to ours and take their sentiment
            $m = []; foreach ($x['mx'] as $e) { foreach ([$e['sym'], $e['sym'] . '.NS'] as $c) if (isset(t_symbols()[$c])) $m[] = $c; }
            $syms = array_slice(array_values(array_unique(array_merge($m, $syms))), 0, 3);
            $ss = max(-1, min(1, array_sum(array_column($x['mx'], 'sent')) / max(1, count($x['mx']))));
        }
        $tags = []; foreach ($syms as $s) $tags[] = ['symbol' => $s, 'base' => t_news_base($s, $x['ts'])];
        $out[] = ['id' => substr(md5($x['u']), 0, 12), 't' => $x['t'], 's' => $x['s'], 'u' => $x['u'], 'src' => $x['src'], 'reg' => $x['reg'], 'ts' => $x['ts'], 'img' => $x['img'],
            'imp' => $imp, 'ss' => round($ss, 2), 'sen' => t_news_sent($ss), 'tags' => $tags];
        if (count($out) >= T_NEWS_MAX) break;
    }
    return ['v' => 1, 'at' => $now, 'items' => $out, 'sources' => $srcs];
}
function t_news_load(int $now, bool $force = false): array {
    t_ensure_dirs();
    $p = T_DATA . '/news.json'; $c = t_read_json($p, null);
    if (!$force && is_array($c) && $now - (int)($c['at'] ?? 0) < T_NEWS_TTL) return $c;
    $lock = fopen(T_DATA . '/.news.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return is_array($c) ? $c : ['at' => 0, 'items' => [], 'sources' => []];   // another request is refreshing
    try { $c = t_news_build($now); t_write_json($p, $c); return $c; }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}
/** The view sent to the page: stories with live prices, reaction since publish, mentions and mood. */
function t_news_view(bool $force = false): array {
    $now = t_now(); $c = t_news_load($now, $force);
    $scan = t_scan_load(); $by = [];
    foreach (t_view_rows($scan, $now) as $r) $by[$r['symbol']] = $r;
    $items = []; $men = []; $mood = ['bull' => 0, 'bear' => 0, 'neu' => 0];
    foreach ($c['items'] ?? [] as $x) {
        $syms = [];
        foreach ($x['tags'] as $t) {
            $r = $by[$t['symbol']] ?? null; if (!$r) continue;
            $since = ($t['base'] ?? null) && $r['price'] ? round(($r['price'] / $t['base'] - 1) * 100, 2) : null;
            if ($since !== null && abs($since) < 0.005) $since = null;   // nothing traded since the story (market shut): say nothing
            $syms[] = ['symbol' => $r['symbol'], 'name' => $r['name'], 'market' => $r['market'], 'price' => $r['price'], 'chg' => $r['change_pct'], 'since' => $since,
                'tier1' => $r['tier1'] ?? 'out', 'signal' => $r['signal'], 'bias' => $r['bias'], 'alignment' => $r['alignment'], 'live' => $r['live'], 'status' => $r['status'], 'strength' => $r['strength']];
            if ($now - $x['ts'] < 86400) {
                $m = &$men[$r['symbol']]; $m = $m ?? ['symbol' => $r['symbol'], 'name' => $r['name'], 'market' => $r['market'], 'n' => 0, 'bull' => 0, 'bear' => 0, 'chg' => $r['change_pct'],
                    'tier1' => $r['tier1'] ?? 'out', 'signal' => $r['signal'], 'bias' => $r['bias'], 'alignment' => $r['alignment'], 'live' => $r['live'], 'status' => $r['status'], 'strength' => $r['strength']];
                $m['n']++; if ($x['sen'] === 'bull') $m['bull']++; elseif ($x['sen'] === 'bear') $m['bear']++; unset($m);
            }
        }
        if ($now - $x['ts'] < 86400) $mood[$x['sen']]++;
        unset($x['tags']); $x['syms'] = $syms; $items[] = $x;
    }
    usort($men, fn($a, $b) => $b['n'] <=> $a['n'] ?: abs($b['chg'] ?? 0) <=> abs($a['chg'] ?? 0));
    return ['updated' => (int)($c['at'] ?? 0), 'now' => $now, 'items' => $items, 'mentions' => array_slice($men, 0, 14), 'mood' => $mood,
        'sources' => $c['sources'] ?? [], 'x' => t_news_x_handles(),
        'keys' => ['finnhub' => trim((string)t_cfg('finnhub_key', '')) !== '', 'marketaux' => trim((string)t_cfg('marketaux_key', '')) !== '']];
}
