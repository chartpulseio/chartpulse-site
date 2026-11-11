<?php
// ---------------------------------------------------------------------------
// ChartPulse SEO pages (owner, 29 Sep 2026): server-rendered pages Google can read without JavaScript.
//   /bias/                 all symbols by market          (bias.php)
//   /bias/<slug>           one symbol: bias, stats, news   (bias.php?slug=)
//   /movers/, /movers/DATE today's biggest moves + archive (movers.php)
//   /sitemap.xml           every page above                (sitemap.php)
// Public data only (the same fields the free AI Scan shows). No signal, zone, score or rule internals.
// ---------------------------------------------------------------------------
declare(strict_types=1);
require_once __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/torus_report.php';   // t_tv_code()

const SEO_BASE = 'https://chartpulse.io';
const SEO_OG = SEO_BASE . '/static/og.jpg';
define('SEO_MOVERS', T_DATA . '/movers');

function seo_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function seo_slugify(string $s): string {
    $s = strtolower(str_replace(['&', '^'], ['', ''], $s));
    $s = preg_replace('/=x$/', '', $s);
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}
/** URL slug for a symbol: name + ticker, e.g. "nvidia-nvda", "bitcoin-btc-usd", "nifty-50-nsei". */
function seo_slug(string $sym): string {
    $n = seo_slugify(t_name($sym)); $t = seo_slugify($sym);
    if ($n === '' || $n === $t) return $t;
    if (str_ends_with($n, '-' . $t) || str_starts_with($t, $n . '-')) return str_starts_with($t, $n . '-') ? $t : $n;
    return $n . '-' . $t;
}
function seo_slug_map(): array {
    static $m = null; if ($m !== null) return $m;
    $m = []; foreach (array_keys(t_symbols()) as $s) { $k = seo_slug($s); if (!isset($m[$k])) $m[$k] = $s; }
    return $m;
}
function seo_url(string $sym): string { return '/bias/' . seo_slug($sym); }

/** Public rows keyed by symbol (same view the pages get from api.php). */
function seo_rows(): array {
    static $r = null; if ($r !== null) return $r;
    $r = []; foreach (t_view_rows(t_scan_load(), t_now()) as $x) $r[$x['symbol']] = $x;
    return $r;
}
function seo_news(?string $sym, int $max = 8): array {
    $nc = t_read_json(T_DATA . '/news.json', ['items' => []]); $out = [];
    foreach (($nc['items'] ?? []) as $x) {
        if (!preg_match('~^https?://~i', (string)($x['u'] ?? ''))) continue;
        $hit = $sym === null;
        if (!$hit) foreach (($x['tags'] ?? []) as $tg) if (($tg['symbol'] ?? '') === $sym) { $hit = true; break; }
        if ($hit) $out[] = $x;
        if (count($out) >= $max) break;
    }
    return $out;
}
function seo_pct($v, int $d = 2): string { return $v === null ? '–' : (($v > 0 ? '+' : '') . number_format((float)$v, $d) . '%'); }
function seo_cls($v): string { return $v > 0 ? 'up' : ($v < 0 ? 'down' : ''); }
function seo_bias(array $r): array {   // [word, css, conviction]
    if (($r['bias'] ?? '') === 'UP') return ['Bullish', 'up', ($r['alignment'] ?? 0) === 4 ? 'Strong' : 'Moderate'];
    if (($r['bias'] ?? '') === 'DOWN') return ['Bearish', 'down', ($r['alignment'] ?? 0) === 4 ? 'Strong' : 'Moderate'];
    return ['Mixed', 'neu', 'No clear trend'];
}
function seo_ago(int $ts): string {
    $m = max(1, (int)round((time() - $ts) / 60));
    return $m < 60 ? "{$m}m ago" : ($m < 1440 ? round($m / 60) . 'h ago' : round($m / 1440) . 'd ago');
}
/** Plain-English description of the numbers. Describes, never predicts or recommends. */
function seo_story(array $r): string {
    $n = $r['name']; $L = [];
    $ch = $r['change_pct'] ?? null; $mv = $r['move_atr'] ?? null;
    if ($ch !== null) {
        $dir = $ch > 0 ? 'up' : ($ch < 0 ? 'down' : 'flat');
        $size = $mv === null ? '' : ($mv >= 2 ? ', an unusually stretched move at ' . number_format($mv, 1) . '× its average daily range' : ($mv >= 1.2 ? ', a bigger than usual day at ' . number_format($mv, 1) . '× its average daily range' : ', within its normal daily range'));
        $L[] = $dir === 'flat' ? "$n is flat today." : sprintf('%s is %s %s today%s.', $n, $dir, number_format(abs($ch), 2) . '%', $size);
    }
    [$w, , $c] = seo_bias($r);
    $L[] = $w === 'Mixed' ? 'The ChartPulse engine reads its trend layers as split, so there is no clear bias right now.'
        : sprintf('The ChartPulse engine reads the trend as %s (%s, Pulse Sync %d/4).', strtolower($w), strtolower($c), (int)($r['alignment'] ?? 0));
    if (($r['ret_1m'] ?? null) !== null && ($r['ret_1y'] ?? null) !== null) $L[] = sprintf('Over the past month it has moved %s, and %s over the past year.', seo_pct($r['ret_1m'], 1), seo_pct($r['ret_1y'], 1));
    if (($r['from_hi_pct'] ?? null) !== null) $L[] = sprintf('It trades %s%% below its 52-week high and %s%% above its 52-week low.', number_format(abs((float)$r['from_hi_pct']), 1), number_format(abs((float)($r['from_lo_pct'] ?? 0)), 1));
    return implode(' ', $L);
}

/* ---------------- page chrome ---------------- */
function seo_head(string $title, string $desc, string $path, array $ld = [], string $robots = 'index,follow'): void {
    $u = SEO_BASE . $path; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<base href="/">
<title><?= seo_e($title) ?></title>
<meta name="description" content="<?= seo_e($desc) ?>">
<meta name="robots" content="<?= seo_e($robots) ?>">
<link rel="canonical" href="<?= seo_e($u) ?>">
<meta name="theme-color" content="#050506">
<meta property="og:site_name" content="ChartPulse">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= seo_e($title) ?>">
<meta property="og:description" content="<?= seo_e($desc) ?>">
<meta property="og:url" content="<?= seo_e($u) ?>">
<meta property="og:image" content="<?= SEO_OG ?>">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= seo_e($title) ?>">
<meta name="twitter:description" content="<?= seo_e($desc) ?>">
<meta name="twitter:image" content="<?= SEO_OG ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 88 88'><ellipse cx='44' cy='44' rx='32' ry='12.5' fill='none' stroke='%23F4B740' stroke-width='3'/><ellipse cx='44' cy='44' rx='32' ry='12.5' fill='none' stroke='%2322AB94' stroke-width='2.4' transform='rotate(60 44 44)'/><ellipse cx='44' cy='44' rx='32' ry='12.5' fill='none' stroke='%23F7525F' stroke-width='2.4' transform='rotate(-60 44 44)'/><circle cx='44' cy='44' r='4.5' fill='%23EDEDEF'/></svg>">
<link rel="preload" as="font" type="font/woff2" href="/static/v2/fonts/ibm-plex-sans-400-latin.woff2" crossorigin>
<link rel="stylesheet" href="/static/v2/base.css?v=10">
<link rel="stylesheet" href="/static/v2/cp.css?v=19">
<link rel="stylesheet" href="/static/v2/odds.css?v=2">
<link rel="stylesheet" href="/static/v2/premium.css?v=2">
<link rel="stylesheet" href="/static/v2/fonts.css?v=1">
<?php foreach ($ld as $j): ?><script type="application/ld+json"><?= json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
<script src="/static/v2/consent.js?v=2"></script>
<link rel="stylesheet" href="/static/v2/sitehead.css?v=2">
<script defer src="/static/v2/sitehead.js?v=7"></script>
</head>
<body class="site seo-page" data-page="seo">
<header class="site-head">
  <a class="logo" href="/" aria-label="ChartPulse home"><svg viewBox="0 0 88 88" aria-hidden="true"><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#F4B740" stroke-width="3"/><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#22AB94" stroke-width="2.4" transform="rotate(60 44 44)"/><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#F7525F" stroke-width="2.4" transform="rotate(-60 44 44)"/><circle cx="44" cy="44" r="4.5" fill="#EDEDEF"/></svg><span>Chart<b>Pulse</b></span></a>
  <nav class="site-nav" id="siteNav" aria-label="Site">
<style>
.site-nav .nav-dd{position:relative;display:inline-block}
.site-nav .nav-dd>summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:.3em}
.site-nav .nav-dd>summary::-webkit-details-marker{display:none}
.site-nav .nav-menu{position:absolute;top:100%;left:0;min-width:210px;display:flex;flex-direction:column;padding:6px;background:#0C0C0F;border:1px solid #26262d;border-radius:8px;z-index:50}
.site-nav .nav-menu a{white-space:nowrap;padding:6px 10px}
</style>
<details class="nav-dd"><summary>Markets</summary><div class="nav-menu">
<a href="/bias/" data-p="seo">AI Scan <small>FREE</small></a>
<a href="/movers/" data-p="movers">Movers</a>
<a href="/news.html" data-p="news">News &amp; Impact <small>IMPACT</small></a>
<a href="/insights.html" data-p="insights">Insights</a>
</div></details>
<details class="nav-dd"><summary>Scanners</summary><div class="nav-menu">
<a href="/scanner.html" data-p="scanner">Tier-1 Scanner <small>TIER-1</small></a>
<a href="/scanners/" data-p="scanners">All scanners <small class="badge-live">15</small></a>
<a href="/scanners/ema-200/" data-p="ema200">200 EMA <small>FREE</small></a>
<a href="/scanners/day-drive/" data-p="dayDrive">Day Drive <small>FREE</small></a>
</div></details>
<details class="nav-dd"><summary>Tools</summary><div class="nav-menu">
<a href="/backtesting/" data-p="backtest">Backtesting <small>FREE</small></a>
<a href="/desk" data-p="desk">AI Scan <small>FREE</small></a>
</div></details>
<a href="/pricing.html" data-p="pricing">Pricing</a>
<a href="/pricing.html" data-p="pricing-cta">Get access</a>
</nav>
  <div class="head-right">
    <button class="cmdk-trigger" type="button" data-cmdk aria-label="Search markets"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span>Search markets…</span><kbd>⌘K</kbd></button>
    <span class="clock" id="cpClock" title="Times on this site are shown in your own time zone"></span>
    <a class="btn primary" href="/pricing.html">Get access</a>
    <button class="nav-toggle" type="button" aria-label="Menu" aria-controls="siteNav" aria-expanded="false"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
  </div>
</header>
<?php }
function seo_crumbs(array $items): array {   // [[name, path], ...] -> BreadcrumbList
    $L = []; foreach ($items as $i => [$n, $p]) $L[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $n, 'item' => SEO_BASE . $p];
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $L];
}
function seo_foot(): void { ?>
<footer class="site-foot">
  <div class="foot-grid wrap">
    <div>
      <a class="logo" href="/" aria-label="ChartPulse home"><svg viewBox="0 0 88 88" aria-hidden="true"><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#F4B740" stroke-width="3"/><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#22AB94" stroke-width="2.4" transform="rotate(60 44 44)"/><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#F7525F" stroke-width="2.4" transform="rotate(-60 44 44)"/><circle cx="44" cy="44" r="4.5" fill="#EDEDEF"/></svg><span>Chart<b>Pulse</b></span></a>
      <p>Live trend bias, market movers and a rule-based signal scanner for stocks, indices, futures, forex and crypto.</p>
    </div>
    <div><h4>Free tools</h4><a href="/#bias">AI Scan</a><a href="/bias/">All symbols</a><a href="/movers/">Market movers today</a><a href="/news.html">News &amp; Impact</a></div>
    <div><h4>Product</h4><a href="/scanner.html">Tier-1 Scanner</a><a href="/insights.html">Market Insights</a><a href="/pricing.html">Pricing</a></div>
    <div><h4>Company</h4><a href="/legal.php?p=contact">Contact us</a><a href="/legal.php?p=terms">Terms &amp; Conditions</a><a href="/legal.php?p=privacy">Privacy Policy</a><a href="/legal.php?p=disclaimer">Risk disclaimer</a></div>
  </div>
  <div class="disclaimer wrap"><b>Risk disclaimer:</b> ChartPulse is a market-data and research tool. Everything on this page is general information generated by software from third-party data. It is not investment, financial, tax or legal advice, and it is not a recommendation to buy, sell or hold any instrument. Trading involves substantial risk of loss. Data may be delayed or inaccurate. © <span data-year></span> ChartPulse.</div>
</footer>
<script src="/static/v2/cp.js?v=99"></script>
</body>
</html>
<?php }

/* ---------------- movers (live + daily snapshot for the archive) ---------------- */
function seo_movers_lists(array $rows): array {
    $R = array_values(array_filter($rows, fn($r) => ($r['change_pct'] ?? null) !== null && ($r['price'] ?? 0) > 0));
    $pick = fn($r) => ['symbol' => $r['symbol'], 'name' => $r['name'], 'market' => $r['market'], 'price' => $r['price'], 'change_pct' => $r['change_pct'],
        'move_atr' => $r['move_atr'] ?? (($r['atr_pct'] ?? 0) > 0 ? round(abs((float)$r['change_pct']) / (float)$r['atr_pct'], 2) : null), 'bias' => $r['bias'] ?? null, 'live' => !empty($r['live'])];
    $g = $R; usort($g, fn($a, $b) => $b['change_pct'] <=> $a['change_pct']);
    $l = $R; usort($l, fn($a, $b) => $a['change_pct'] <=> $b['change_pct']);
    $s = array_map($pick, $R); usort($s, fn($a, $b) => ($b['move_atr'] ?? 0) <=> ($a['move_atr'] ?? 0));
    $by = []; foreach ($R as $r) { $m = $r['market']; if (!isset($by[$m]) || abs($r['change_pct']) > abs($by[$m]['change_pct'])) $by[$m] = $r; }
    ksort($by);
    return ['gainers' => array_map($pick, array_slice($g, 0, 15)), 'losers' => array_map($pick, array_slice($l, 0, 15)), 'stretched' => array_slice($s, 0, 15),
        'markets' => array_values(array_map($pick, $by)), 'count' => count($R),
        'up' => count(array_filter($R, fn($r) => $r['change_pct'] > 0)), 'down' => count(array_filter($R, fn($r) => $r['change_pct'] < 0))];
}
/** Called by cron.php: keeps today's (UTC) movers file current; the last save of the day becomes the archive page. */
function seo_movers_snapshot(int $now): void {
    if (!is_dir(SEO_MOVERS)) @mkdir(SEO_MOVERS, 0755, true);
    $d = seo_movers_lists(seo_rows()); if (!$d['count']) return;
    $d['date'] = gmdate('Y-m-d', $now); $d['saved_at'] = $now;
    t_write_json(SEO_MOVERS . '/' . $d['date'] . '.json', $d);
}
function seo_movers_dates(int $max = 60): array {
    $L = []; foreach ((array)glob(SEO_MOVERS . '/*.json') as $f) { $b = basename((string)$f, '.json'); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b)) $L[] = $b; }
    rsort($L); return array_slice($L, 0, $max);
}

/* ---------------- Telegram channel: one daily movers post (owner, 29 Sep 2026) ---------------- */
/** Posts once a day after 21:00 IST (US session open) to config 'telegram_channel' (e.g. @chartpulse_movers) with 'telegram_bot_token'. */
function seo_tg_daily(int $now): ?string {
    $chan = trim((string)t_cfg('telegram_channel', '')); $tok = trim((string)t_cfg('telegram_bot_token', ''));
    if ($chan === '' || $tok === '') return null;
    $ist = $now + 19800; if ((int)gmdate('G', $ist) < 21) return null;
    if (!is_dir(SEO_MOVERS)) @mkdir(SEO_MOVERS, 0755, true);
    $mark = SEO_MOVERS . '/.tg_' . gmdate('Y-m-d', $ist); if (is_file($mark)) return null;
    $d = seo_movers_lists(seo_rows()); if (!$d['count']) return null;
    @touch($mark);
    $line = fn($x) => sprintf('%s %s <b>%s</b> · %s', $x['change_pct'] >= 0 ? "\u{1F7E2}" : "\u{1F534}", seo_e($x['name']), seo_pct($x['change_pct']), $x['move_atr'] !== null ? number_format((float)$x['move_atr'], 1) . '× usual range' : '');
    $L = ["<b>\u{1F4CA} Biggest movers today · " . gmdate('d M Y', $ist) . '</b>', '', '<b>Up the most</b>'];
    foreach (array_slice($d['gainers'], 0, 5) as $x) $L[] = $line($x);
    $L[] = ''; $L[] = '<b>Down the most</b>';
    foreach (array_slice($d['losers'], 0, 5) as $x) $L[] = $line($x);
    $L[] = ''; $L[] = sprintf('%d up · %d down across %d instruments.', $d['up'], $d['down'], $d['count']);
    $L[] = 'Full list: ' . SEO_BASE . '/movers/';
    $L[] = '<i>Market data only, not investment advice. Big moves can keep going or reverse.</i>';
    $ch = curl_init('https://api.telegram.org/bot' . $tok . '/sendMessage');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => ['chat_id' => $chan, 'text' => implode("\n", $L), 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']]);
    $res = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) { @unlink($mark); return 'telegram http ' . $code; }
    return 'sent';
}

/* ---------------- IndexNow: tell Bing (and other IndexNow engines) about new or changed pages (owner, 29 Sep 2026) ---------------- */
/* The key is public by design: the same value is served at /<key>.txt so the engines can check we own the site. */
const SEO_INDEXNOW_KEY = '8aaa2893f0dafb776c47e0d10a7a4516';
/** Called by cron.php. At most one submission an hour: every page once on the first run, then only what changed
    (bias pages whose bias word flipped, the hub pages once a day, each new daily movers archive). */
function seo_indexnow(int $now): ?string {
    $sf = T_DATA . '/indexnow.json';
    $S = t_read_json($sf, []); if (!is_array($S)) $S = [];
    if ($now - (int)($S['last'] ?? 0) < 3600) return null;
    $today = gmdate('Y-m-d', $now);
    $rows = seo_rows(); $bias = [];
    foreach (array_keys(t_symbols()) as $s) if (isset($rows[$s])) $bias[$s] = seo_bias($rows[$s])[0];
    $dates = array_values(array_filter(seo_movers_dates(365), fn($x) => $x !== $today));
    $P = [];
    if (empty($S['full'])) {   // first run: the whole site
        $P = ['/', '/bias/', '/movers/', '/scanner.html', '/news.html', '/insights.html', '/pricing.html'];
        foreach (array_keys(t_symbols()) as $s) $P[] = seo_url($s);
        foreach ($dates as $d) $P[] = "/movers/$d";
    } else {
        if (($S['day'] ?? '') !== $today) array_push($P, '/', '/bias/', '/movers/');
        $old = (array)($S['bias'] ?? []);
        foreach ($bias as $s => $w) if (isset($old[$s]) && $old[$s] !== $w) $P[] = seo_url($s);
        $seen = (array)($S['arch'] ?? []);
        foreach ($dates as $d) if (!in_array($d, $seen, true)) $P[] = "/movers/$d";
    }
    $P = array_slice(array_values(array_unique($P)), 0, 9000);
    $keep = function (?string $res) use ($sf, $S, $now, $today, $bias, $dates) {
        $S['last'] = $now; $S['full'] = $S['full'] ?? $today; $S['day'] = $today;
        $S['bias'] = array_merge((array)($S['bias'] ?? []), $bias); $S['arch'] = array_slice($dates, 0, 60);
        if ($res !== null) $S['result'] = $res;
        t_write_json($sf, $S);
    };
    if (!$P) { $keep(null); return null; }
    $body = json_encode(['host' => parse_url(SEO_BASE, PHP_URL_HOST), 'key' => SEO_INDEXNOW_KEY,
        'keyLocation' => SEO_BASE . '/' . SEO_INDEXNOW_KEY . '.txt', 'urlList' => array_map(fn($p) => SEO_BASE . $p, $P)], JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8']]);
    $res = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code === 200 || $code === 202) { $keep(gmdate('c', $now) . " · $code · " . count($P) . ' urls'); return 'indexnow ' . count($P); }
    $S['last'] = $now; $S['result'] = gmdate('c', $now) . " · http $code · " . substr(preg_replace('/\s+/', ' ', strip_tags($res)), 0, 300); t_write_json($sf, $S);   // retry in an hour, nothing marked as sent
    return 'indexnow http ' . $code;
}
