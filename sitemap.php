<?php
// sitemap.xml (served through a rewrite in .htaccess). Owner, 29 Sep 2026.
declare(strict_types=1);
require __DIR__ . '/torus_seo.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$today = gmdate('Y-m-d', t_now());
$U = [['/', 'hourly', '1.0'], ['/bias/', 'hourly', '0.9'], ['/movers/', 'hourly', '0.9'], ['/scanner.html', 'hourly', '0.8'], ['/pro-backtesting', 'weekly', '0.8'], ['/news.html', 'hourly', '0.7'],
    ['/insights.html', 'daily', '0.6'], ['/pricing.html', 'weekly', '0.6'],
    ['/legal.php?p=disclaimer', 'monthly', '0.3'], ['/legal.php?p=terms', 'monthly', '0.2'], ['/legal.php?p=privacy', 'monthly', '0.2'], ['/legal.php?p=refund', 'monthly', '0.2'], ['/legal.php?p=contact', 'monthly', '0.3']];
foreach (array_keys(t_symbols()) as $s) $U[] = [seo_url($s), 'daily', '0.7'];
foreach (seo_movers_dates(365) as $d) if ($d !== $today) $U[] = ["/movers/$d", 'never', '0.4', $d];
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($U as $u) echo '<url><loc>', htmlspecialchars(SEO_BASE . $u[0], ENT_XML1), '</loc><lastmod>', $u[3] ?? $today, '</lastmod><changefreq>', $u[1], '</changefreq><priority>', $u[2], "</priority></url>\n";
echo "</urlset>\n";
