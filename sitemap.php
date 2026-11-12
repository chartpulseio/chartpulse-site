<?php
// sitemap.xml (served via .htaccess rewrite). Updated 2 Nov 2026.
// Only indexable URLs. Scanners (12 Nov 2026): the hub, the 8 ChartPulse scanners and the 6 active strategy scanners; removed scanners are not listed.
// No changefreq / priority (Google ignores them).
declare(strict_types=1);
require __DIR__ . '/torus_seo.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$today = gmdate('Y-m-d', t_now());
// Static pages: lastmod from the file's own modification time.
$mt = static function (string $f) use ($today): string {
    $t = @filemtime(__DIR__ . '/' . $f);
    return $t ? gmdate('Y-m-d', $t) : $today;
};
$U = [
    ['/', $mt('index.html')],
    ['/scanners/', $mt('scanners.html')],
    ['/scanner.html', $mt('scanner.html')],
    ['/scanners/day-drive/', $mt('day-drive.html')],
    ['/scanners/trend-lines/', $mt('trend-lines.html')],
    ['/scanners/hybrid-trend-lines/', $mt('hybrid-trend-lines.html')],
    ['/scanners/godfather/', $mt('godfather.html')],
    ['/scanners/trendline-hero/', $mt('trendline-hero.html')],
    ['/scanners/hero-200-ema/', $mt('hero-200-ema.html')],
    ['/scanners/news/', $mt('news-scanner.html')],
    ['/scanners/ema-200/', $mt('ema200.html')],
    ['/institutional/', $mt('institutional/index.php')],
    ['/institutional/scanner.php?id=3-liquidity-fvg', $mt('institutional/includes/strategies.php')],
    ['/institutional/scanner.php?id=1-smc-power-of-3', $mt('institutional/includes/strategies.php')],
    ['/institutional/scanner.php?id=4-orb-vwap', $mt('institutional/includes/strategies.php')],
    ['/ema-strategies/', $mt('ema-strategies/index.php')],
    ['/ema-strategies/scanner.php?id=3-ema-50-21-alignment', $mt('ema-strategies/includes/strategies.php')],
    ['/ema-strategies/scanner.php?id=7-ema-supertrend-rsi', $mt('ema-strategies/includes/strategies.php')],
    ['/ema-strategies/scanner.php?id=5-vwap-ema-combo', $mt('ema-strategies/includes/strategies.php')],
    ['/backtesting/', $mt('pro-backtesting.html')],
    ['/desk/', $mt('desk.html')],
    ['/news.html', $mt('news.html')],
    ['/insights.html', $mt('insights.html')],
    ['/pricing.html', $mt('pricing.html')],
    ['/affiliates/', $mt('affiliates.html')],
    ['/legal/terms', $mt('legal.php')],
    ['/legal/privacy', $mt('legal.php')],
    ['/legal/refund', $mt('legal.php')],
    ['/legal/disclaimer', $mt('legal.php')],
    ['/contact/', $mt('legal.php')],
    ['/bias/', $today],
    ['/movers/', $today],
];
foreach (array_keys(t_symbols()) as $s) $U[] = [seo_url($s), $today];
foreach (seo_movers_dates(365) as $d) if ($d !== $today) $U[] = ["/movers/$d", $d];
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($U as $u) echo '<url><loc>', htmlspecialchars(SEO_BASE . $u[0], ENT_XML1), '</loc><lastmod>', $u[1], "</lastmod></url>\n";
echo "</urlset>\n";
