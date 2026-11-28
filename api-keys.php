<?php
// /api/ -- get a ChartPulse API key (paid plan) for the MT5 auto-entry EA. owner, 28 Nov 2026
declare(strict_types=1);
require_once __DIR__ . '/torus_seo.php';
header('Cache-Control: no-store');
seo_head('ChartPulse API & MT5 Auto-Entry EA | ChartPulse', 'ChartPulse signals API for MT5: Confluence, God-father, Day Drive and Reversal setups with candle-pattern auto entry. Paid plans.', '/api/', [], 'noindex'); ?>
<main class="page wrap seo" id="main">
  <h1>ChartPulse API &amp; MT5 auto-entry</h1>
  <p>Your API key connects the ChartPulse EA in MetaTrader 5 to live setups from Confluence, God-father (A+/A), Day Drive and Reversal. The EA enters only after a candle pattern confirms at the zone; exit stays manual.</p>
  <p><button class="btn" id="mk" type="button">Create my API key</button></p>
  <pre id="out" style="white-space:pre-wrap;background:#111116;padding:12px;border-radius:8px;display:none"></pre>
  <h2>Setup in MT5</h2>
  <ol>
    <li>Tools → Options → Expert Advisors → tick "Allow WebRequest for listed URL" and add <code>https://chartpulse.io</code>.</li>
    <li>Copy <code>ChartPulse_AutoEntry.mq5</code> into MQL5/Experts, compile it in MetaEditor.</li>
    <li>Attach it to any one chart, paste the key in <b>ApiKey</b>, set risk and broker symbol suffix.</li>
    <li>Run on a demo account first. Creating a new key switches off the old one.</li>
  </ol>
  <p class="muted">API access needs a paid plan. Not financial advice; automated trading can lose money.</p>
</main>
<script>
document.getElementById("mk").onclick = async () => {
  const o = document.getElementById("out"); o.style.display = "block"; o.textContent = "Creating…";
  try { const j = await fetch("/api/v1/keys", { method: "POST", credentials: "same-origin" }).then(r => r.json());
    o.textContent = j.key ? "Your key (shown once, copy it now):\n\n" + j.key + "\n\nValid until " + j.expires : (j.error || "Error"); }
  catch (e) { o.textContent = "Network error"; }
};
</script>
<?php seo_foot();
