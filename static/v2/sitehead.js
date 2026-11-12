/* ChartPulse site header (10 Nov 2026). One header for every page: this script replaces whatever header / nav
   markup the page shipped with, so the menu is the same everywhere and is edited in ONE place -- MENU below. */
(function () {
  "use strict";
  var MENU = [
    { t: "Scanner", href: "/scanner.html", match: ["/scanner.html", "/tier2.html"] },
    { t: "God-father Scanner", href: "/scanners/godfather/" },
    { t: "Trend Line Scanners", cols: [{ items: [
      { t: "Trendline Hero", d: "Trend line + day / week / month high or low, with the big trend", href: "/scanners/trendline-hero/" },
      { t: "Hero with 200 EMA", d: "Hero setups with the 1H 200 EMA on the line", href: "/scanners/hero-200-ema/" },
      { t: "Hybrid Trend Lines", d: "Day high or low at a line, with the trend", href: "/scanners/hybrid-trend-lines/" },
      { t: "Trend Line Scanner", d: "Breakouts and tests on seven timeframes", href: "/scanners/trend-lines/" }
    ] }] },
    { t: "Day Drive Scanner", href: "/scanners/day-drive/" },
    { t: "Movers Reversal Scanner", href: "/movers/" },
    { t: "200 EMA Scanner", href: "/scanners/ema-200/" },
    { t: "AI Scanner", href: "/bias/", match: ["/desk/"] },
    { t: "News Scanner", href: "/scanners/news/" },
    { t: "Squeeze & Binary", cols: [{ items: [
      { t: "Liquidation Squeeze Scanner", d: "Crowded longs or shorts at a key zone, and squeezes starting", href: "/scanners/squeeze/" },
      { t: "Binary Pulse Scanner", d: "Up / down calls with expiry on live forex, crypto and gold", href: "/scanners/binary/" }
    ] }] },
    { t: "Markets", cols: [{ items: [
      { t: "AI Scan", d: "Trend direction for every symbol", href: "/bias/" },
      { t: "Movers Reversal Scanner", d: "Today's biggest movers and which are snapping back", href: "/movers/" },
      { t: "News & impact", d: "Headlines and what they moved", href: "/news.html" },
      { t: "Insights", d: "Breadth, volatility and momentum", href: "/insights.html" }
    ] }] },
    { t: "Tools", cols: [
      { h: "Free scanners", items: [
        { t: "All scanners", d: "Fifteen ready-made scans", href: "/scanners/" },
        { t: "God-father Scanner", d: "Every scanner joined into one A+ call", href: "/scanners/godfather/" },
        { t: "200 EMA", d: "Price meeting its 200 EMA", href: "/scanners/ema-200/" },
        { t: "Day Drive", d: "Three checks, weekly chart to 15m entry", href: "/scanners/day-drive/" },
        { t: "Trend lines", d: "Breakouts and tests on seven timeframes", href: "/scanners/trend-lines/" },
        { t: "Hybrid trend lines", d: "Day high or low at a line, with the trend", href: "/scanners/hybrid-trend-lines/" },
        { t: "Trendline Hero", d: "Trend line trades with the big trend, long targets", href: "/scanners/trendline-hero/" },
        { t: "Hero with 200 EMA", d: "Hero setups with the 1H 200 EMA on the line", href: "/scanners/hero-200-ema/" },
        { t: "News scanner", d: "Markets in the news, tone and trend", href: "/scanners/news/" }
      ] },
      { h: "Research", items: [
        { t: "AI Backtesting", d: "Type a strategy in plain words, get a backtest", href: "/ai-backtesting/" },
        { t: "Backtesting", d: "Test your own strategy on history", href: "/backtesting/" },
        { t: "AI Scan", d: "Forecast, news tone and analyst view for one symbol", href: "/desk/" }
      ] }
    ] },
    { t: "Pricing", href: "/pricing.html" },
    { t: "Affiliates", href: "/affiliates/" }
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
  var isOn = function (href, extra) {
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
          var on = isOn(it.href); any = any || on;
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

    // Live count: cp.js fills #hdrLive on the pages that load it; show the pill only once it holds a number.
    var live = h.querySelector("#hdrLive"), num = live.querySelector("b");
    var show = function () { if (/\d/.test(num.textContent)) live.classList.remove("hide"); };
    if (window.MutationObserver) new MutationObserver(show).observe(num, { childList: true, characterData: true, subtree: true });
    // Search: cp.js opens its command palette from [data-cmdk]; on pages without it, go to the screener.
    h.querySelector(".cph-search").addEventListener("click", function () { if (!window.CP) location.href = "/scanner.html#screener"; });
  }
  if (document.body) mount(); else document.addEventListener("DOMContentLoaded", mount);
})();
