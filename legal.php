<?php
declare(strict_types=1);
require is_file(__DIR__ . '/torus_upstox.php') ? __DIR__ . '/torus_upstox.php' : dirname(__DIR__) . '/torus_upstox.php';   // t_cfg()
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$biz = t_cfg('business_name', 'ChartPulse'); $mail = t_cfg('support_email', ''); $tel = t_cfg('support_phone', ''); $addr = t_cfg('business_address', '');
$sebi = t_cfg('sebi_registration', ''); $upd = t_cfg('legal_updated', '25 September 2026');
$pages = ['terms' => 'Terms & Conditions', 'privacy' => 'Privacy Policy', 'refund' => 'Refund & Cancellation', 'contact' => 'Contact us', 'disclaimer' => 'Risk disclaimer'];
$p = $_GET['p'] ?? 'terms'; if (!isset($pages[$p])) $p = 'terms';
$title = $pages[$p] . ' · ' . $biz;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($title) ?></title>
<meta name="description" content="<?= $e($pages[$p]) ?> for <?= $e($biz) ?>">
<meta name="theme-color" content="#050506">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="<?= $e($pages[$p]) ?> for <?= $e($biz) ?>">
<meta property="og:type" content="website">
<link rel="canonical" href="https://chartpulse.io<?= $p === 'contact' ? '/contact/' : '/legal/' . $e($p) ?>">
<meta property="og:site_name" content="ChartPulse">
<meta property="og:url" content="https://chartpulse.io<?= $p === 'contact' ? '/contact/' : '/legal/' . $e($p) ?>">
<meta property="og:image" content="https://chartpulse.io/static/og.jpg">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://chartpulse.io/static/og.jpg">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 88 88' aria-hidden='true'><ellipse cx='44' cy='44' rx='32' ry='12.5' fill='none' stroke='%23F4B740' stroke-width='3'/><ellipse cx='44' cy='44' rx='32' ry='12.5' fill='none' stroke='%2322AB94' stroke-width='2.4' transform='rotate(60 44 44)'/><ellipse cx='44' cy='44' rx='32' ry='12.5' fill='none' stroke='%23F7525F' stroke-width='2.4' transform='rotate(-60 44 44)'/><circle cx='44' cy='44' r='4.5' fill='%23EDEDEF'/></svg>">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="/static/v2/fonts/ibm-plex-sans-400-latin.woff2" crossorigin>
<link rel="stylesheet" href="/static/v2/base.css?v=10">
<link rel="stylesheet" href="/static/v2/cp.css?v=9">
<link rel="stylesheet" href="/static/v2/odds.css?v=2">
<script defer src="/static/v2/consent.js?v=2"></script>
<link rel="stylesheet" href="/static/v2/premium.css?v=2">
<link rel="stylesheet" href="/static/v2/fonts.css?v=1">
<link rel="stylesheet" href="/static/v2/sitehead.css?v=2">
<script defer src="/static/v2/sitehead.js?v=3"></script>
</head>
<body class="site legal-page" data-page="legal">
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
<a href="/bias/" data-p="seo">Bias <small>FREE</small></a>
<a href="/movers/" data-p="movers">Movers</a>
<a href="/news.html" data-p="news">News &amp; Impact <small>IMPACT</small></a>
<a href="/insights.html" data-p="insights">Insights</a>
</div></details>
<details class="nav-dd"><summary>Scanners</summary><div class="nav-menu">
<a href="/scanner.html" data-p="scanner">Tier-1 Scanner <small>TIER-1</small></a>
<a href="/scanners/" data-p="scanners">All scanners <small class="badge-live">15</small></a>
<a href="/scanners/bottom-reversal/" data-p="bottomreversal">Bottom Reversal <small>FREE</small></a>
<a href="/scanners/ema-200/" data-p="ema200">200 EMA <small>FREE</small></a>
<a href="/scanners/day-drive/" data-p="dayDrive">Day Drive <small>FREE</small></a>
</div></details>
<details class="nav-dd"><summary>Tools</summary><div class="nav-menu">
<a href="/backtesting/" data-p="backtest">Backtesting <small>FREE</small></a>
<a href="/desk" data-p="desk">Bias Desk <small>FREE</small></a>
</div></details>
<a href="/pricing.html" data-p="pricing">Pricing</a>
<a href="/pricing.html" data-p="pricing-cta">Get access</a>
</nav>
  <div class="head-right">
    <button class="cmdk-trigger" type="button" data-cmdk aria-label="Search markets"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span>Search markets…</span><kbd>⌘K</kbd></button>
    <span class="live-pill" id="hdrLive" title="Instruments trading right now"><span class="ldot off"></span><b>–</b> live</span>
    <span class="clock" id="cpClock" title="Times on this site are shown in your own time zone"></span>
    <a class="btn primary" href="/pricing.html">Get access</a>
    <button class="nav-toggle" type="button" aria-label="Menu" aria-controls="siteNav" aria-expanded="false"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
  </div>
</header>
<main class="page wrap legal" id="main">
  <nav class="legal-nav" aria-label="Policies"><?php foreach ($pages as $k => $v): ?><a href="<?= $k === 'contact' ? '/contact/' : '/legal/' . $k ?>" class="<?= $k === $p ? 'on' : '' ?>"><?= $e($v) ?></a><?php endforeach; ?></nav>
  <div><h1><?= $e($pages[$p]) ?></h1><p class="upd">Last updated: <?= $e($upd) ?></p></div>
<?php if ($p === 'terms'): ?>
  <p>These terms govern your use of the website and services of <b><?= $e($biz) ?></b> ("we", "us"). By using the site or buying a plan you agree to them.</p>
  <h2>1. The service</h2><p>We provide market data dashboards, rule-based scanners (including the Tier-1 scanner), charts and analytics for stocks, indices, futures, currencies and crypto assets. Features differ by plan and may change as the product develops.</p>
  <h2>2. Not investment advice</h2><p>All content, signals, scores and levels are generated by software from third-party market data. They are informational and educational only and are not a recommendation to buy, sell or hold any instrument. You alone are responsible for your trading decisions and their results. See the <a href="/legal/disclaimer">Risk disclaimer</a>.</p>
  <h2>3. Eligibility and access</h2><p>You must be at least 18 years old and able to form a binding contract. Access is personal to the buyer; sharing, reselling or redistributing access, signals or data is not allowed and may lead to termination without refund.</p>
  <h2>4. Plans and payment</h2><p>Plan prices are shown on the Pricing page in Indian Rupees and include applicable taxes unless stated otherwise. Payments are processed by Razorpay; we do not see or store your card, UPI or bank details. A plan covers the period shown at checkout.</p>
  <h2>5. Data sources and availability</h2><p>Prices come from third parties (such as Yahoo Finance and Upstox) and may be delayed, incomplete or wrong. The service may be interrupted for maintenance or reasons outside our control. We do not guarantee uninterrupted or error-free operation.</p>
  <h2>6. Acceptable use</h2><p>Do not scrape, overload, reverse-engineer or attack the service, and do not use it for unlawful purposes.</p>
  <h2>7. Intellectual property</h2><p>The site, software, scoring method, design and content belong to <?= $e($biz) ?>. Charts are provided by TradingView under their terms.</p>
  <h2>8. Limitation of liability</h2><p>To the extent permitted by law, we are not liable for any trading losses or indirect, incidental or consequential damages. Our total liability for any claim is limited to the amount you paid us in the three months before the claim.</p>
  <h2>9. Termination</h2><p>We may suspend or end access for breach of these terms. You may stop using the service at any time; refunds follow the <a href="/legal/refund">Refund &amp; Cancellation policy</a>.</p>
  <h2>10. Governing law</h2><p>These terms are governed by the laws of India. Courts at the place of our registered office have exclusive jurisdiction.</p>
  <h2>11. Contact</h2><p>Questions about these terms: <?= $mail ? '<a href="mailto:' . $e($mail) . '">' . $e($mail) . '</a>' : 'see the Contact page' ?>.</p>
<?php elseif ($p === 'privacy'): ?>
  <p>This policy explains what <b><?= $e($biz) ?></b> collects and how it is used.</p>
  <h2>What we collect</h2><ul><li>Checkout details you enter: name, email and mobile number.</li><li>Payment references from Razorpay (order ID, payment ID, amount, status). Card, UPI and bank details are handled only by Razorpay.</li><li>Basic technical logs kept by our hosting provider (IP address, browser, pages requested) for security and reliability.</li><li>Anonymous usage statistics through Google Analytics (pages viewed, device type, approximate location, how you reached the site), where you allow it.</li></ul>
  <h2>How we use it</h2><ul><li>To deliver and support your plan and send service messages.</li><li>To prevent fraud and keep the service secure.</li><li>To meet legal, tax and accounting duties.</li></ul>
  <h2>Sharing</h2><p>We share data only with service providers needed to run the service (payment processing by Razorpay, hosting, website statistics by Google Analytics) or when the law requires it. We do not sell personal data.</p>
  <h2>Cookies and storage</h2><p>We use Google Analytics 4 cookies to count visits and see which pages are useful. IP addresses are not stored by Google Analytics 4. Visitors in the UK, EU and EEA are asked first and analytics stays off unless they accept; elsewhere it is on by default and you can switch it off at any time. The site does not use advertising cookies, and advertising and personalisation signals are always turned off. Your cookie choice is kept in your own browser. Embedded TradingView charts and the Razorpay checkout may set their own cookies under their policies.</p><p><button type="button" class="btn ghost" onclick="window.cpCookieSettings&&cpCookieSettings()">Cookie settings</button></p>
  <h2>Retention</h2><p>Payment records are kept as long as tax and accounting law requires. Other data is deleted when no longer needed.</p>
  <h2>Your choices</h2><p>You can ask for a copy, correction or deletion of your data, subject to legal retention rules, by writing to <?= $mail ? '<a href="mailto:' . $e($mail) . '">' . $e($mail) . '</a>' : 'us' ?>.</p>

<h2>ChartPulse Assistant (AI chat)</h2>
<p>The site has an optional AI help assistant. It is loaded only when you click the
&ldquo;Ask ChartPulse&rdquo; button &mdash; nothing chat-related runs otherwise.</p>
<ul>
  <li>We store the question, the answer, the page you asked from and the time, so we can
      improve the answers. These records are deleted automatically after 90 days.</li>
  <li>We do <b>not</b> store your IP address. Rate limiting uses a salted hash that is
      rotated every day and cannot be turned back into an address.</li>
  <li>Email addresses, phone numbers, card-like numbers and ID-style numbers are masked
      <i>before</i> anything is written to our logs.</li>
  <li>Your conversation is kept in your browser&rsquo;s <code>sessionStorage</code> only and
      disappears when you close the tab.</li>
</ul>
<p>When the assistant cannot answer from our own site content, your (masked) question and
the relevant site passages may be sent to <b>Google Gemini</b> on its free tier to generate
a reply. Reports indicate that free-tier content may be used to improve Google&rsquo;s products,
so please do not type personal or financial information into the chat. Your question is never
sent together with your IP address, access code or payment details.</p>
<p>The assistant is for information only. It does not give investment advice, price targets
or buy/sell recommendations.</p>
<?php elseif ($p === 'refund'): ?>
  <p>This policy applies to all paid plans of <b><?= $e($biz) ?></b>.</p>
  <h2>Delivery of the service</h2><p>Plans are digital services and no physical goods are shipped. Access starts immediately after a successful payment: the browser used for checkout is unlocked and a personal access code is shown on screen for your other devices. If the page closes before you see the code, recover it on the <a href="/pricing.html#recover">Pricing page</a> with your payment ID and checkout email, or write to support.</p>
  <h2>Cancellation</h2><p>You can cancel future renewals at any time by writing to support. The current paid period continues until it ends.</p>
  <h2>Refunds</h2><ul><li>Duplicate or excess payments are refunded in full.</li><li>If we fail to deliver access within 7 days of payment and cannot fix it, the payment is refunded in full.</li><li>Once access has been delivered, fees for the running period are not refundable, because the service is consumed immediately.</li></ul>
  <h2>How refunds are paid</h2><p>Approved refunds are made to the original payment method through Razorpay, usually within 5 to 7 working days of approval.</p>
  <h2>Requests</h2><p>Write to <?= $mail ? '<a href="mailto:' . $e($mail) . '">' . $e($mail) . '</a>' : 'support' ?> with your payment ID.</p>
<?php elseif ($p === 'contact'): ?>
  <p>We usually reply within one working day.</p>
  <div class="g2" style="margin-top:10px">
    <div class="card spot"><h2>Email</h2><p style="font-size:16px"><?= $mail ? '<a href="mailto:' . $e($mail) . '">' . $e($mail) . '</a>' : '—' ?></p></div>
    <div class="card spot"><h2>Phone</h2><p style="font-size:16px"><?= $tel ? '<a href="tel:' . $e(preg_replace('/\s+/', '', $tel)) . '">' . $e($tel) . '</a>' : '—' ?></p></div>
    <div class="card spot"><h2>Business</h2><p style="font-size:16px"><?= $e($biz) ?></p></div>
    <div class="card spot"><h2>Address</h2><p style="font-size:16px;white-space:pre-line"><?= $addr ? $e($addr) : '—' ?></p></div>
  </div>
<?php else: ?>
  <p><b><?= $e($biz) ?></b> provides market data analytics and rule-based scanning tools for education and research.</p>
  <h2>No advice</h2><p>Nothing on this website is investment, trading, tax or legal advice, or a recommendation or offer to buy or sell any security, derivative, commodity, currency or crypto asset. Scores, signals, levels and briefs are the output of a fixed rule set applied to market data and do not consider your finances, objectives or risk tolerance.</p>
  <?php if ($sebi): ?><h2>Registration</h2><p><?= $e($sebi) ?></p><?php endif; ?>
  <h2>Risk</h2><p>Trading in equities, futures, options, forex and crypto carries a high risk of loss and may not suit everyone. Leverage magnifies losses. Past performance and back-tested or simulated results do not guarantee future results.</p>
  <h2>Data</h2><p>Market data comes from third parties and can be delayed, incomplete or wrong. Signals are shown only while a market is live, but delays can still make them stale. Always confirm prices with your broker before acting.</p>
  <h2>Your responsibility</h2><p>You are solely responsible for your trading decisions. Consult a SEBI-registered investment adviser before making investment decisions.</p>
<?php endif; ?>
</main>
<footer class="site-foot">
  <div class="foot-grid wrap">
    <div>
      <a class="logo" href="/" aria-label="ChartPulse home"><svg viewBox="0 0 88 88" aria-hidden="true"><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#F4B740" stroke-width="3"/><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#22AB94" stroke-width="2.4" transform="rotate(60 44 44)"/><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#F7525F" stroke-width="2.4" transform="rotate(-60 44 44)"/><circle cx="44" cy="44" r="4.5" fill="#EDEDEF"/></svg><span>Chart<b>Pulse</b></span></a>
      <p>Multi-market Tier-1 scanner for stocks, indices, futures, forex and crypto. Market data from Yahoo Finance and Upstox (India). Charts by TradingView.</p>
      <span class="foot-status"><span class="ldot"></span>Signals only for markets that are live</span>
    </div>
    <div><h4>Product</h4><a href="/">Overview</a><a href="/scanner.html">Tier-1 Scanner</a><a href="/insights.html">Market Insights</a><a href="/news.html">News &amp; Impact</a><a href="/pricing.html">Pricing</a></div>
    <div><h4>Company</h4><a href="/contact/">Contact us</a><a href="/legal/terms">Terms &amp; Conditions</a><a href="/legal/privacy">Privacy Policy</a><a href="/legal/refund">Refund &amp; Cancellation</a></div>
    <div><h4>Resources</h4><a href="/legal/disclaimer">Risk disclaimer</a><a href="/api.php?r=export">Signals CSV</a><a href="#" data-cmdk>Search <kbd>⌘K</kbd></a></div>
  </div>
  <div class="disclaimer wrap"><b>Risk disclaimer:</b> ChartPulse provides market data analytics and rule-based scanning tools for education and research. Nothing on this website is investment advice or a recommendation to buy or sell any security, derivative, currency or crypto asset. Trading involves substantial risk of loss. Signals are model output based on delayed or real-time third-party data that may be inaccurate. Consult a SEBI-registered adviser before acting. © <span data-year></span> ChartPulse.</div>
</footer>
<script defer src="/static/v2/cp.js?v=99"></script>
</body>
</html>
