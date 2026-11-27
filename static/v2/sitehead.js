/* ChartPulse site header (10 Nov 2026). One header for every page: this script replaces whatever header / nav
   markup the page shipped with, so the menu is the same everywhere and is edited in ONE place -- MENU below. */
(function () {
  "use strict";
  /* Menu (owner, 27 Nov 2026): the important scanners on the bar, everything else by category. tag = small highlight. */
  var MENU = [
    { t: "Tier-1 Scanner", href: "/scanner.html", match: ["/scanner.html", "/tier2.html"] },
    { t: "God-father", href: "/scanners/godfather/", tag: "TOP", match: ["/scanners/godfather-v2/"] },
    { t: "Confluence", href: "/scanners/confluence/", tag: "NEW" },
    { t: "Day Drive", href: "/scanners/day-drive/" },
    { t: "GEX", href: "/scanners/gex/" },
    { t: "Trend Lines", cols: [{ items: [
      { t: "Confluence Scanner", d: "Lines of 4H, 1H, 30m, 15m in one zone: intraday reversal, trap-aware, 5m entry", href: "/scanners/confluence/", tag: "NEW" },
      { t: "Trendline Hero", d: "Swing reversal: big trend + a line on the day / week / month high or low (200 EMA filter inside)", href: "/scanners/trendline-hero/", match: ["/scanners/hero-200-ema/", "/scanners/hybrid-trend-lines/"] },
      { t: "Trend Line Scanner", d: "Breakouts and retests on seven timeframes", href: "/scanners/trend-lines/" }
    ] }] },
    { t: "Scanners", cols: [
      { h: "Reversal & trend", items: [
        { t: "200 EMA", d: "Setups at the 200 EMA", href: "/scanners/ema-200/" },
        { t: "Movers Reversal", d: "Today's biggest movers and which are snapping back", href: "/movers/" },
        { t: "News Scanner", d: "Markets in the news, tone and trend", href: "/scanners/news/" }
      ] },
      { h: "Smart money", items: [
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
    { t: "Crypto", cols: [{ items: [
      { t: "Crypto Order Flow", d: "CVD, open interest, order book, liquidations and the liquidation heatmap", href: "/scanners/order-flow/" },
      { t: "Liquidation Squeeze Scanner", d: "Crowded longs or shorts at a key zone, and squeezes starting", href: "/scanners/squeeze/" },
      { t: "Crypto Arbitrage Scanner", d: "Funding rate arbitrage and price gaps across seven exchanges", href: "/scanners/crypto-arbitrage/" }
    ] }] },
    { t: "AI", cols: [{ items: [
      { t: "AI Scanner", d: "Bias, four forecast models and the AI committee for any symbol", href: "/bias/", match: ["/desk/"] },
      { t: "Next Candle AI", d: "Four AI models guess the next 5-minute candle, with a GEX label", href: "/scanners/next-candle/" },
      { t: "24-Hour AI Forecast", d: "Likely price path and range for the next 24 hours", href: "/scanners/forecast-24h/" },
      { t: "Binary Scanner", d: "Short-expiry setups", href: "/scanners/binary/" }
    ] }] },
    { t: "Tools", cols: [
      { h: "Test", items: [
        { t: "Backtesting", d: "Test your own strategy on history", href: "/backtesting/" },
        { t: "AI Backtesting", d: "Let AI test a strategy for you", href: "/ai-backtesting/" }
      ] },
      { h: "More", items: [
        { t: "All scanners", d: "Every scanner on one page", href: "/scanners/", exact: true },
        { t: "Today's report", d: "Best setups, GEX and movers today", href: "/today/" },
        { t: "Learn", d: "Plain-English guides: GEX, trend lines, order flow, AI", href: "/learn/" },
        { t: "GEX levels today", d: "Call wall, put wall, zero gamma for every market", href: "/gex/" },
        { t: "Insights", d: "Breadth, volatility and momentum", href: "/insights.html" },
        { t: "Affiliates", d: "Earn 20% for every trader you bring", href: "/affiliates/" }
      ] }
    ] },
    { t: "Pricing", href: "/pricing.html" }
  ];
  var TAG = function (x) { return x ? '<i style="font-style:normal;font:700 8.5px/1 IBM Plex Mono,monospace;letter-spacing:.06em;margin-left:5px;padding:2px 4px;border-radius:3px;vertical-align:2px;background:' + (x === "TOP" ? "#F4B740;color:#1a1200" : "#2ee59d;color:#04140d") + '">' + x + "</i>" : ""; };
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
      if (m.href) { nav += '<a href="' + m.href + '"' + (isOn(m.href, m.match) ? ' class="on" aria-current="page"' : "") + ">" + esc(m.t) + TAG(m.tag) + "</a>"; return; }
      var any = false, cols = "";
      m.cols.forEach(function (c) {
        cols += '<div class="cph-col">' + (c.h ? "<p>" + esc(c.h) + "</p>" : "");
        c.items.forEach(function (it) {
          var on = isOn(it.href, it.match, it.exact); any = any || on;
          cols += '<a href="' + it.href + '"' + (on ? ' class="on" aria-current="page"' : "") + "><b>" + esc(it.t) + TAG(it.tag) + "</b><span>" + esc(it.d) + "</span></a>";
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
    /* Offer strip (owner, 27 Nov 2026): free for a limited time, then 99 USD a month. Not shown inside embedded charts. */
    if (!/[?&]embed=1/.test(location.search) && !document.getElementById("cpOffer")) {
      var ob = document.createElement("div"); ob.id = "cpOffer";
      ob.setAttribute("style", "position:relative;z-index:5;text-align:center;font:600 12.5px/1.4 'IBM Plex Sans',system-ui,sans-serif;padding:7px 12px;color:#1a1200;background:linear-gradient(90deg,#F4B740,#ffd97a,#F4B740)");
      ob.innerHTML = '\uD83C\uDF81 <b>Free access for a limited time</b> \u2014 every scanner, GEX and AI tool is open now. After that ChartPulse Pro is <b>$99/month</b>. <a href="/pricing.html" style="color:#1a1200;text-decoration:underline;font-weight:700;margin-left:6px">Lock your spot \u2192</a>';
      h.parentNode.insertBefore(ob, h.nextSibling);
    }

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
