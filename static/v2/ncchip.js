/* AI forecast label for scanner cards (owner, 15 Nov 2026). Reads /api.php?r=nc once a minute; CPNC.chip(symbol) returns a small
   label: how the three forecast models vote on the next 5-minute candle, and the price range they expect for the next hour.
   A label only, never a filter. Empty string for symbols the models do not cover. */
(function () {
  "use strict";
  var M = {}, at = 0, cbs = [];
  function f(v, d) { return Number(v).toLocaleString("en-US", { minimumFractionDigits: Math.min(d, 2), maximumFractionDigits: d }); }
  function load() {
    fetch("/api.php?r=nc", { cache: "no-store" }).then(function (r) { return r.json(); }).then(function (d) {
      var m = {}; if (d && d.age != null && d.age < 1200) (d.rows || []).forEach(function (r) { m[r.s] = r; });
      M = m; at = Date.now(); cbs.forEach(function (c) { try { c(); } catch (e) {} });
    }).catch(function () {});
  }
  window.CPNC = {
    chip: function (s) {
      var r = M[s]; if (!r) return "";
      var up = r.up, n = r.n, c = r.agree ? (r.side > 0 ? "#22AB94" : "#F7525F") : "#A3A5AF";
      return '<div style="margin:0 0 8px;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;color:#A3A5AF" title="Four AI forecast models (Kronos, Chronos-2, TimesFM 2.5, Moirai 2.0). A label for interest, not part of this call.">'
        + '<span style="display:inline-block;padding:1px 8px;border-radius:999px;border:1px solid ' + c + ';color:' + c + ';margin-right:6px">AI next 5m: ' + (r.agree ? n + "/" + n + (r.side > 0 ? " up" : " down") : up + " up · " + (n - up) + " down") + "</span>"
        + '<span style="display:inline-block;padding:1px 8px;border-radius:999px;border:1px solid rgba(110,168,254,.55);color:#6EA8FE">AI next-hour range ' + f(r.h1[0], r.dec) + " – " + f(r.h1[1], r.dec) + "</span></div>";
    },
    onUpdate: function (c) { cbs.push(c); }
  };
  load(); setInterval(load, 60000);
})();
