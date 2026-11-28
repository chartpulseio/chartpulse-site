<?php
// /api/ -- get a ChartPulse API key (paid plan) for the MT5 auto-entry EA. owner, 28 Nov 2026
declare(strict_types=1);
require_once __DIR__ . '/torus_seo.php';
header('Cache-Control: no-store');
seo_head('ChartPulse API & MT5 Auto-Entry EA | ChartPulse', 'ChartPulse signals API for MT5: Confluence, God-father, Day Drive and Reversal setups with candle-pattern auto entry. Paid plans.', '/api/', [], 'noindex'); ?>
<main class="page wrap seo" id="main">
  <h1>ChartPulse API &amp; MT5 auto-entry</h1>
  <p>Your API key connects the ChartPulse EA in MetaTrader 5 to live setups from Confluence, God-father (A+/A), Day Drive and Reversal. The EA enters only after a candle pattern confirms at the zone; exit stays manual.</p>
  <p><b>Step 1.</b> <button class="btn" id="mk" type="button">Create my API key</button></p>
  <pre id="out" style="white-space:pre-wrap;background:#111116;padding:12px;border-radius:8px;display:none"></pre>
  <p><b>Step 2.</b> <a class="btn" href="/static/ChartPulse_AutoEntry.mq5" download>Download the MT5 EA</a></p>
  <h2>Step 3. Add it to MT5 (2 minutes)</h2>
  <ol>
    <li>In MT5: <b>Tools → Options → Expert Advisors</b> → tick <b>Allow WebRequest for listed URL</b> → add <code>https://chartpulse.io</code> → OK.</li>
    <li><b>File → Open Data Folder → MQL5 → Experts</b>, paste <code>ChartPulse_AutoEntry.mq5</code> there.</li>
    <li>In the Navigator press <b>Refresh</b>, double-click the EA (MetaEditor opens) and press <b>Compile</b>.</li>
    <li>Drag <b>ChartPulse_AutoEntry</b> onto any one chart, paste your key in <b>ApiKey</b>, turn on <b>Algo Trading</b>.</li>
    <li>If your broker adds a suffix to names (like <code>EURUSD.m</code>), set <b>SymbolSuffix</b>. Different names (like <code>GOLD</code>)? Use <b>SymbolMap</b>: <code>XAUUSD=GOLD</code>.</li>
  </ol>
  <h2>What it does</h2>
  <ul>
    <li>Reads live setups (Confluence, God-father A+/A, Day Drive, Reversal) for markets that are open, and keeps only those in the Daily + Weekly trend: bearish = SELL from the day high, bullish = BUY from the day low.</li>
    <li>Watches M5, M10, M15 and M30 on every setup symbol. Enters only when a candle pattern confirms at the zone: Double Denial, Morning/Evening Star, Engulfing, Pin Bar, 3-Bar Play.</li>
    <li>One position per symbol, whatever the timeframe; a cooldown stops re-entries. High-impact news and rollover time are skipped.</li>
    <li>Stop-loss beyond the pattern plus a buffer; no take-profit, you close the trade yourself.</li>
    <li>Every pattern it finds is logged to <code>MQL5/Files/ChartPulse_log.csv</code>.</li>
  </ul>
  <p><b>Start on a demo account.</b> Set <b>TradingEnabled = false</b> to watch without trading.</p>
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
