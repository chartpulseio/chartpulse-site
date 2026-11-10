/* AI Scan board (10 Nov 2026). Shows every engine that worked on the open symbol -- what it read and what it
   concluded -- above the existing desk view, on the AI Scan page and inside Tier 2. It adds no request of its
   own: it is drawn from the payload CP.desk.render already receives, and lists only engines that really ran. */
(function () {
  "use strict";
  if (!window.CP || !CP.desk || CP.desk._aib) return;
  var esc = CP.esc, fmtP = CP.fmtP;
  var n = function (x, d) { return x == null || !isFinite(x) ? "–" : Number(x).toFixed(d == null ? 1 : d); };
  var sg = function (x, d) { return x == null || !isFinite(x) ? "–" : (x > 0 ? "+" : "") + Number(x).toFixed(d == null ? 1 : d); };
  var tone = function (v) { return v > 0 ? "up" : v < 0 ? "dn" : "nt"; };
  var word = function (v) { return v > 0 ? "Bullish" : v < 0 ? "Bearish" : "Neutral"; };
  var fac = function (d, re) { var f = (d.factors || []).filter(function (x) { return re.test(x.name); })[0]; return f ? f.value : null; };

  function engines(d) {
    var p = d.params || {}, m = d.memory || {}, st = d.selftest || {}, nw = d.news || {}, cone = d.cone || [], px = p.price;
    var E = [];
    var fEng = fac(d, /engine bias/i);
    E.push({ t: "Trend engine", src: "Supertrend on 1H, 4H, 1D, 1W", v: fEng, val: d.bias && d.bias.engine ? (d.bias.engine === "UP" ? "Uptrend" : d.bias.engine === "DOWN" ? "Downtrend" : "Split") : "–", note: "Weight " + sg(fEng) + " of 30" });
    var fTr = fac(d, /^Trend/i);
    E.push({ t: "Moving-average stack", src: "EMA 5, 20, 50 on the daily chart", v: fTr, val: p.trend || "–", note: "Price is " + sg(p.ema20_dist, 2) + "% from its 20 EMA" });
    var fMo = fac(d, /Momentum/i);
    E.push({ t: "Momentum", src: "RSI 14", v: fMo, val: "RSI " + n(p.rsi), note: p.rsi >= 70 ? "Overbought" : p.rsi <= 30 ? "Oversold" : p.rsi >= 50 ? "Buyers in control" : "Sellers in control" });
    var fCa = fac(d, /candle/i);
    E.push({ t: "Candle reader", src: "Last closed daily candle", v: fCa, val: p.candle || "No pattern", note: fCa ? "Counts " + sg(fCa) + " toward the bias" : "Nothing decisive on the last bar" });
    var fVo = fac(d, /Volume/i);
    E.push({ t: "Volume", src: "Today against the 20-day average", v: fVo, val: p.vol_ratio != null ? n(p.vol_ratio, 2) + "×" : "–", note: p.vol_ratio == null ? "No volume on this market" : p.vol_ratio >= 1.2 ? "Heavier than usual" : p.vol_ratio <= 0.8 ? "Lighter than usual" : "About normal" });
    E.push({ t: "Volatility", src: "ATR 14 and one-year history", v: 0, st: "Measured", val: n(p.atr_pct, 2) + "% a day", note: "Higher than " + n(p.atr_pctile, 0) + "% of the past year · " + n(p.vol_ann) + "% annualised" });
    E.push({ t: "Range map", src: "20-day high and low", v: p.range_pos == null ? 0 : p.range_pos >= 66 ? 1 : p.range_pos <= 33 ? -1 : 0, st: "Mapped", val: n(p.range_pos, 0) + "% of range", note: fmtP(p.lo20) + " to " + fmtP(p.hi20) });
    if (cone.length) {
      var last = cone[cone.length - 1], mv = px ? (last.p50 / px - 1) * 100 : null;
      E.push({ t: "Forecast model", src: "TimesFM, " + cone.length + " bars ahead", v: mv == null ? 0 : mv > 0.15 ? 1 : mv < -0.15 ? -1 : 0, val: sg(mv, 2) + "% median", note: "80% band " + fmtP(last.p10) + " to " + fmtP(last.p90) });
    } else E.push({ t: "Forecast model", src: "TimesFM", off: 1, val: "No forecast yet", note: "Runs hourly; this symbol is queued" });
    var fMe = fac(d, /memory/i);
    E.push(m.n ? { t: "Setup memory", src: "Searched " + (m.pool || 0) + " past setups on " + (m.scope || "this chart"), v: fMe, val: m.n + " close matches", note: n(m.up) + "% went up · average best run " + sg(m.avg_mfe, 2) + "%" }
      : { t: "Setup memory", src: "Past setups like this one", off: 1, val: "No close match", note: "Not enough similar history" });
    var hasNews = nw.status !== "no_data" && nw.n;
    E.push(hasNews ? { t: "News tone", src: "FinBERT on " + nw.n + " headlines", v: nw.tone, val: word(nw.tone), note: "Tone score " + sg(nw.tone, 2) }
      : { t: "News tone", src: "FinBERT on recent headlines", off: 1, val: "No headlines read", note: "Nothing recent for this symbol" });
    E.push(st.trades ? { t: "Self-test", src: "This bias method, replayed on this chart", v: st.pf >= 1 ? 1 : -1, st: st.pf >= 1 ? "Held up" : "Weak here", val: n(st.hit) + "% hit rate", note: st.trades + " past calls · profit factor " + n(st.pf, 2) }
      : { t: "Self-test", src: "This bias method on this chart", off: 1, val: "Not enough calls", note: "Too little history to test" });
    var ag = d.agents || {}, lv = d.live || {}, rep = lv.report || (ag.status === "done" ? ag : null);
    E.push(rep ? { t: "Analyst committee", src: "Four AI analysts and a judge", v: 0, st: "Reported", val: "Report ready", note: "Full write-up is below" }
      : { t: "Analyst committee", src: "Four AI analysts and a judge", off: 1, val: ag.status === "queued" || ag.status === "running" ? "Working on it" : "On request", note: ag.status ? "Usually done within the hour" : "Start it from the button below" });
    return E;
  }

  function mount(root, d) {
    if (!root || !d || !d.bias) return;
    var old = root.querySelector(":scope > .aib"); if (old) old.remove();
    var b = d.bias, p = d.params || {}, pl = d.plan || {}, m = d.memory || {}, E = engines(d);
    var ran = E.filter(function (e) { return !e.off; }).length;
    var cls = /BULL/i.test(b.label) ? "up" : /BEAR/i.test(b.label) ? "dn" : "nt";
    var pos = Math.max(2, Math.min(98, 50 + (b.score || 0) / 2));
    var rows = [["Now", p.price], ["Entry", pl.entry], ["Stop", pl.stop], ["Target 1", pl.t1], ["Target 2", pl.t2]].filter(function (r) { return r[1] != null; });
    var el = document.createElement("section");
    el.className = "aib"; el.setAttribute("aria-label", "AI Scan summary");
    el.innerHTML =
      '<div class="aib-top"><div class="aib-verdict">' +
        '<p class="aib-kicker"><b>' + esc(d.name || d.symbol) + "</b> · " + esc(d.market || "") + " · " + (d.tf === "1H" ? "hourly" : "daily") + " view</p>" +
        '<h2 class="aib-call ' + cls + '">' + esc(String(b.label || "").charAt(0) + String(b.label || "").slice(1).toLowerCase()) + "</h2>" +
        (d.explanation ? '<p class="aib-why">' + esc(d.explanation) + "</p>" : "") +
        '<div class="aib-meter"><span>Bearish</span><div class="aib-track"><i style="left:50%"></i></div><span>Bullish</span></div>' +
      '</div><div class="aib-stats">' +
        "<div><b>" + sg(b.score, 0) + "</b><span>bias score, from −100 to +100</span></div>" +
        "<div><b>" + n(b.conviction, 0) + "%</b><span>conviction</span></div>" +
        "<div><b>" + ran + " of " + E.length + "</b><span>engines reported</span></div>" +
        "<div><b>" + ((d.data && d.data.bars) || 0).toLocaleString() + "</b><span>candles read" + (m.pool ? " · " + m.pool + " past setups searched" : "") + "</span></div>" +
      "</div></div>" +
      '<div class="aib-run"><span><b class="aib-n">0</b> of ' + E.length + ' engines</span><div class="aib-bar"><i></i></div><span class="aib-state">reading the chart</span></div>' +
      '<div class="aib-grid">' + E.map(function (e) {
        var t = e.off ? "" : tone(e.v);
        return '<article class="aib-eng wait ' + t + (e.off ? " off" : "") + '"><div class="aib-eh"><h3>' + esc(e.t) + '</h3><span class="aib-st" data-st="' + esc(e.off ? "Standing by" : e.st || word(e.v)) + '">Running</span></div>' +
          '<p class="aib-src">' + esc(e.src) + '</p><div class="aib-val">' + esc(e.val) + '</div><div class="aib-note">' + esc(e.note) + "</div></article>";
      }).join("") + "</div>" +
      (rows.length > 1 ? '<div class="aib-lv"><table><caption>Levels this scan is working with</caption><thead><tr><th scope="col">Level</th><th scope="col">Price</th><th scope="col">From now</th></tr></thead><tbody>' +
        rows.map(function (r) { var ch = p.price ? (r[1] / p.price - 1) * 100 : null; return "<tr><td>" + r[0] + "</td><td>" + fmtP(r[1]) + '</td><td class="' + (ch > 0 ? "up-t" : ch < 0 ? "dn-t" : "") + '">' + (r[0] === "Now" ? "–" : sg(ch, 2) + "%") + "</td></tr>"; }).join("") +
        "</tbody></table></div>" : "") +
      '<p class="aib-foot">Education and research only. Every figure above comes from the engines listed; none of it is investment advice.</p>';
    root.insertBefore(el, root.firstChild);

    var cards = [].slice.call(el.querySelectorAll(".aib-eng")), bar = el.querySelector(".aib-bar i"), cnt = el.querySelector(".aib-n"), state = el.querySelector(".aib-state"), dot = el.querySelector(".aib-track i");
    var done = function (i) {
      var c = cards[i], s = c.querySelector(".aib-st"); c.classList.remove("wait"); s.textContent = s.getAttribute("data-st");
      cnt.textContent = i + 1; bar.style.width = ((i + 1) / cards.length * 100) + "%";
      if (i === cards.length - 1) { state.textContent = "scan complete"; dot.style.left = pos + "%"; }
    };
    var key = d.symbol + "|" + d.tf, still = matchMedia("(prefers-reduced-motion: reduce)").matches || root._aibKey === key;
    root._aibKey = key;
    if (still) { cards.forEach(function (_, i) { done(i); }); return; }
    cards.forEach(function (_, i) { setTimeout(function () { if (el.isConnected) done(i); }, 260 + i * 170); });
  }

  var orig = CP.desk.render;
  CP.desk.render = function (root, d) {
    var r = orig.apply(this, arguments);
    try { mount(root, d); } catch (e) {}
    return r;
  };
  CP.desk._aib = true;
})();
