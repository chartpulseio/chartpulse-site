/* ChartPulse site header (10 Nov 2026). One header for every page: this script replaces whatever header / nav
   markup the page shipped with, so the menu is the same everywhere and is edited in ONE place -- MENU below. */
(function () {
  "use strict";
  var MENU = [
    { t: "Scanner", href: "/scanner.html", match: ["/scanner.html", "/tier2.html"] },
    { t: "God-father", href: "/scanners/godfather/" },
    { t: "200 EMA", href: "/scanners/ema-200/" },
    { t: "Day Drive", href: "/scanners/day-drive/" },
    { t: "Trend Scanners", cols: [{ items: [
      { t: "Trendline Hero", d: "Trend line + day / week / month high or low, with the big trend", href: "/scanners/trendline-hero/" },
      { t: "Hero with 200 EMA", d: "Hero setups with the 1H 200 EMA on the line", href: "/scanners/hero-200-ema/" },
      { t: "Hybrid Trend Lines", d: "Day high or low at a line, with the trend", href: "/scanners/hybrid-trend-lines/" },
      { t: "Trend Line Scanner", d: "Breakouts and tests on seven timeframes", href: "/scanners/trend-lines/" },
      { t: "Movers Reversal Scanner", d: "Today's biggest movers and which are snapping back", href: "/movers/" }
    ] }] },
    { t: "Strategy Scanners", cols: [
      { h: "Institutional (smart money)", items: [
        { t: "Liquidity Sweep + Order Block + FVG", d: "Stop hunt, then order block and fair value gap", href: "/institutional/scanner.php?id=3-liquidity-fvg" },
        { t: "SMC Power of 3 + OTE", d: "Accumulation, manipulation, distribution with the OTE entry", href: "/institutional/scanner.php?id=1-smc-power-of-3" },
        { t: "Range Breakout + VWAP + Volume", d: "Opening range break with VWAP and a volume spike", href: "/institutional/scanner.php?id=4-orb-vwap" }
      ] },
      { h: "EMA strategies", items: [
        { t: "EMA 200 + 50 + 21 Alignment", d: "All three EMAs stacked the same way", href: "/ema-strategies/scanner.php?id=3-ema-50-21-alignment" },
        { t: "EMA 200 + Supertrend + RSI 50", d: "Trend, Supertrend and momentum agreeing", href: "/ema-strategies/scanner.php?id=7-ema-supertrend-rsi" },
        { t: "VWAP + EMA 200 Combo", d: "Price on the right side of VWAP and the 200 EMA", href: "/ema-strategies/scanner.php?id=5-vwap-ema-combo" }
      ] }
    ] },
    { t: "Crypto Scanners", cols: [{ items: [
      { t: "Crypto Order Flow", d: "CVD, open interest, order book, liquidations and the liquidation heatmap", href: "/scanners/order-flow/" },
      { t: "Crypto Arbitrage Scanner", d: "Funding rate arbitrage and price gaps across seven exchanges", href: "/scanners/crypto-arbitrage/" },
      { t: "Liquidation Squeeze Scanner", d: "Crowded longs or shorts at a key zone, and squeezes starting", href: "/scanners/squeeze/" }
    ] }] },
    { t: "Binary Scanner", href: "/scanners/binary/" },
    { t: "GEX", href: "/scanners/gex/" },
    { t: "AI Forecast", cols: [{ items: [
      { t: "Next Candle AI", d: "Four AI forecast models guess the next 5-minute candle. For fun, every guess graded", href: "/scanners/next-candle/" },
      { t: "24-Hour AI Forecast", d: "Likely price path and range for the next 24 hours, refreshed every hour", href: "/scanners/forecast-24h/" },
      { t: "AI Scanner", d: "Bias, four forecast models and the AI committee for any symbol", href: "/bias/", match: ["/desk/"] }
    ] }] },
    { t: "AI Backtesting", href: "/ai-backtesting/" },
    { t: "More", cols: [
      { h: "Markets", items: [
        { t: "News Scanner", d: "Markets in the news, tone and trend", href: "/scanners/news/" },
        { t: "All scanners", d: "Every scanner on one page", href: "/scanners/", exact: true },
        { t: "News & impact", d: "Headlines and what they moved", href: "/news.html" },
        { t: "Insights", d: "Breadth, volatility and momentum", href: "/insights.html" }
      ] },
      { h: "Tools", items: [
        { t: "Backtesting", d: "Test your own strategy on history", href: "/backtesting/" },
        { t: "Affiliates", d: "Earn 20% for every trader you bring", href: "/affiliates/" }
      ] }
    ] },
    { t: "Pricing", href: "/pricing.html" }
  ];
  /* affiliate link: ?ref=CODE is remembered for checkout (30 days) and counted once per browser */
  try {
    var rf = (new URLSearchParams(location.search).get("ref") || "").toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 16);
    if (rf.length >= 4 && localStorage.getItem("cp_ref") !== rf) {
      localStorage.setItem("cp_ref", rf);
      fetch("/aff.php?a=click", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ code: rf }) }).catch(function () {});
    }
  } catch (e) {}
  var LOGO = '<svg viewBox="0 0 88 88" aria-hidden="true"><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#F4B740" stroke-width="3"/><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#22AB94" stroke-width="2.4" transform="rotate(60 44 44)"/><ellipse cx="44" cy="44" rx="32" ry="12.5" fill="none" stroke="#F7525F" stroke-width="2.4" transform="rotate(-60 44 44)"/><circle cx="44" cy="44" r="4.5" fill="#EDEDEF"/></svg>';
  var CHEV = '<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M1.5 3.5 5 7l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var path = location.pathname.replace(/index\.html$/, "");
  var norm = function (p) { return p.replace(/\/+$/, "") || "/"; };
  var isOn = function (href, extra, exact) {
    if (exact) return norm(path) === norm(href);
    if (href.indexOf("?") > 0) return location.pathname + location.search === href;
    var list = [href].concat(extra || []);
    for (var i = 0; i < list.length; i++) {
      var h = norm(list[i]), p = norm(path);
      if (p === h || (h !== "/" && h.indexOf(".html") < 0 && p.indexOf(h + "/") === 0)) return true;
    }
    return false;
  };
  var esc = function (s) { return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;"); };

  function build() {
    var nav = "";
    MENU.forEach(function (m, i) {
      if (m.href) { nav += '<a href="' + m.href + '"' + (isOn(m.href, m.match) ? ' class="on" aria-current="page"' : "") + ">" + esc(m.t) + "</a>"; return; }
      var any = false, cols = "";
      m.cols.forEach(function (c) {
        cols += '<div class="cph-col">' + (c.h ? "<p>" + esc(c.h) + "</p>" : "");
        c.items.forEach(function (it) {
          var on = isOn(it.href, it.match, it.exact); any = any || on;
          cols += '<a href="' + it.href + '"' + (on ? ' class="on" aria-current="page"' : "") + "><b>" + esc(it.t) + "</b><span>" + esc(it.d) + "</span></a>";
        });
        cols += "</div>";
      });
      nav += '<div class="cph-dd' + (any ? " on" : "") + '"><button type="button" aria-expanded="false" aria-controls="cphP' + i + '">' + esc(m.t) + CHEV +
        '</button><div class="cph-panel' + (m.cols.length > 1 ? " two" : "") + '" id="cphP' + i + '">' + cols + "</div></div>";
    });
    var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || "");
    var h = document.createElement("header");
    h.className = "site-head cph";
    h.innerHTML =
      '<a class="cph-logo" href="/" aria-label="ChartPulse home">' + LOGO + "<span>Chart<b>Pulse</b></span></a>" +
      '<nav class="cph-nav" id="siteNav" aria-label="Site">' + nav + "</nav>" +
      '<div class="cph-right">' +
        '<button class="cph-search" type="button" data-cmdk aria-label="Search markets"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span>Search</span><kbd>' + (mac ? "⌘K" : "Ctrl K") + "</kbd></button>" +
        '<span class="cph-live hide" id="hdrLive" title="Instruments trading right now"><span class="ldot off"></span><b>–</b> live</span>' +
        '<span class="cph-clock" id="cpClock"></span>' +
        '<a class="cph-cta" href="/pricing.html">Get access</a>' +
        '<button class="cph-burger" type="button" aria-label="Menu" aria-controls="siteNav" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>' +
      "</div>";
    return h;
  }

  function mount() {
    if (document.querySelector("header.cph")) return;
    var old = document.getElementById("siteNav"), h = build();
    var wrap = old ? (old.closest("header.site-head") || (old.parentElement && old.parentElement.classList.contains("rail") && old.parentElement.parentElement === document.body ? old.parentElement : old)) : null;
    if (wrap) wrap.parentNode.replaceChild(h, wrap); else document.body.insertBefore(h, document.body.firstChild);

    var dds = [].slice.call(h.querySelectorAll(".cph-dd"));
    var close = function (except) { dds.forEach(function (d) { if (d !== except) { d.classList.remove("open"); d.firstChild.setAttribute("aria-expanded", "false"); } }); };
    dds.forEach(function (d) {
      d.firstChild.addEventListener("click", function (e) {
        e.stopPropagation(); close(d);
        var o = d.classList.toggle("open"); d.firstChild.setAttribute("aria-expanded", o ? "true" : "false");
      });
    });
    document.addEventListener("click", function (e) { if (!e.target.closest || !e.target.closest(".cph-dd")) close(null); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") { close(null); setMenu(false); } });
    var burger = h.querySelector(".cph-burger");
    var setMenu = function (o) { h.classList.toggle("menu", o); burger.setAttribute("aria-expanded", o ? "true" : "false"); document.documentElement.style.overflow = o ? "hidden" : ""; };
    burger.addEventListener("click", function () { setMenu(!h.classList.contains("menu")); });
    // The full menu shows only when it really fits beside the logo and the buttons; otherwise the header folds to the menu button.
    var navEl = h.querySelector(".cph-nav"), right = h.querySelector(".cph-right"), logo = h.querySelector(".cph-logo"), need = 0;
    var fit = function () {
      if (!need) { h.classList.remove("fold"); need = navEl.scrollWidth; }
      var room = h.clientWidth - logo.offsetWidth - right.offsetWidth - 84;
      var fold = window.innerWidth <= 1180 || need > room;
      if (fold !== h.classList.contains("fold")) { h.classList.toggle("fold", fold); if (!fold) setMenu(false); }
    };
    fit(); window.addEventListener("resize", function () { need = 0; fit(); }); if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { need = 0; fit(); });

    // Live count: cp.js fills #hdrLive on the pages that load it; show the pill only once it holds a number.
    var live = h.querySelector("#hdrLive"), num = live.querySelector("b");
    var show = function () { if (/\d/.test(num.textContent)) live.classList.remove("hide"); };
    if (window.MutationObserver) new MutationObserver(show).observe(num, { childList: true, characterData: true, subtree: true });
    // Search: cp.js opens its command palette from [data-cmdk]; on pages without it, go to the screener.
    h.querySelector(".cph-search").addEventListener("click", function () { if (!window.CP) location.href = "/scanner.html#screener"; });
  }
  if (document.body) mount(); else document.addEventListener("DOMContentLoaded", mount);
})();
