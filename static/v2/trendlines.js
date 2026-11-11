/* Trend lines panel (Tier 2 + AI Scan). One small chart per timeframe that is drawing a line right now,
   with the line(s) on it and a plain-English reading. Data: /tl_api.php?s=SYMBOL (tl_engine.php). */
(() => {
  "use strict";
  const CP = window.CP || (window.CP = {});
  const esc = CP.esc || (s => String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])));
  const W = 640, H = 250, PL = 6, PR = 64, PT = 10, PB = 20, EXT = 14;
  const fmt = (v, d) => Number(v).toLocaleString("en-US", { minimumFractionDigits: d, maximumFractionDigits: d });
  const TFN = { "5m": "5 minute", "10m": "10 minute", "15m": "15 minute", "30m": "30 minute", "1H": "1 hour", "4H": "4 hour", "1D": "Daily", "1W": "Weekly" };
  const STATE = { holding: "Holding", testing: "Being tested now", broken: "Broken" };

  function chart(f) {
    const b = f.bars, n = b.c.length, tot = n + EXT, cw = (W - PL - PR) / tot;
    const X = i => PL + (i + 0.5) * cw;
    const lv = (L, i) => L.p1 + L.slope * (i - L.i1);
    let lo = Math.min(...b.l), hi = Math.max(...b.h);
    [f.res, f.sup].forEach(L => { if (!L) return; [L.i1, n - 1 + EXT].forEach(i => { const v = lv(L, i); if (isFinite(v)) { lo = Math.min(lo, v); hi = Math.max(hi, v); } }); });
    const span = hi - lo || 1; const bLo = Math.min(...b.l), bHi = Math.max(...b.h), bs = bHi - bLo || 1;
    lo = Math.max(lo, bLo - bs * 0.35) - span * 0.03; hi = Math.min(hi, bHi + bs * 0.35) + span * 0.03;
    const Y = v => PT + (hi - v) / (hi - lo) * (H - PT - PB);
    let s = `<svg class="tlx-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(f.tf)} chart with trend lines: ${esc(f.pattern)}">`;
    for (let g = 0; g <= 3; g++) { const v = lo + (hi - lo) * g / 3, y = Y(v); s += `<line class="tlx-grid" x1="${PL}" x2="${W - PR}" y1="${y.toFixed(1)}" y2="${y.toFixed(1)}"/><text class="tlx-ax" x="${W - PR + 6}" y="${(y + 3).toFixed(1)}">${fmt(v, f.dec)}</text>`; }
    const bw = Math.max(1, cw * 0.62);
    for (let i = 0; i < n; i++) {
      const up = b.c[i] >= b.o[i], x = X(i), y1 = Y(Math.max(b.o[i], b.c[i])), y2 = Y(Math.min(b.o[i], b.c[i]));
      s += `<line class="tlx-w ${up ? "u" : "d"}" x1="${x.toFixed(1)}" x2="${x.toFixed(1)}" y1="${Y(b.h[i]).toFixed(1)}" y2="${Y(b.l[i]).toFixed(1)}"/>`
        + `<rect class="tlx-b ${up ? "u" : "d"}" x="${(x - bw / 2).toFixed(1)}" y="${y1.toFixed(1)}" width="${bw.toFixed(1)}" height="${Math.max(1, y2 - y1).toFixed(1)}"/>`;
    }
    const clip = `tlc${Math.random().toString(36).slice(2, 8)}`;
    s += `<clipPath id="${clip}"><rect x="${PL}" y="${PT}" width="${W - PL - PR}" height="${H - PT - PB}"/></clipPath><g clip-path="url(#${clip})">`;
    [["res", f.res], ["sup", f.sup]].forEach(([k, L]) => {
      if (!L) return;
      const xa = X(L.i1), ya = Y(lv(L, L.i1)), xe = X(n - 1), ye = Y(lv(L, n - 1)), xf = X(n - 1 + EXT), yf = Y(lv(L, n - 1 + EXT));
      s += `<line class="tlx-l ${k} ${L.state}" x1="${xa.toFixed(1)}" y1="${ya.toFixed(1)}" x2="${xe.toFixed(1)}" y2="${ye.toFixed(1)}"/>`
        + `<line class="tlx-l ${k} ext" x1="${xe.toFixed(1)}" y1="${ye.toFixed(1)}" x2="${xf.toFixed(1)}" y2="${yf.toFixed(1)}"/>`
        + `<circle class="tlx-p ${k}" cx="${xa.toFixed(1)}" cy="${ya.toFixed(1)}" r="3.2"/><circle class="tlx-p ${k}" cx="${X(L.i2).toFixed(1)}" cy="${Y(L.p2).toFixed(1)}" r="3.2"/>`;
    });
    s += `</g>`;
    [["res", f.res], ["sup", f.sup]].forEach(([k, L]) => {
      if (!L) return; const y = Math.min(H - PB - 2, Math.max(PT + 8, Y(L.now)));
      s += `<rect class="tlx-tag ${k}" x="${W - PR + 2}" y="${(y - 8).toFixed(1)}" width="${PR - 4}" height="16" rx="3"/><text class="tlx-tagt" x="${W - PR + 6}" y="${(y + 3.5).toFixed(1)}">${fmt(L.now, f.dec)}</text>`;
    });
    const py = Y(f.price);
    s += `<line class="tlx-px" x1="${PL}" x2="${W - PR}" y1="${py.toFixed(1)}" y2="${py.toFixed(1)}"/>`;
    const d0 = new Date(b.t[0] * 1000), d1 = new Date(b.t[n - 1] * 1000), intr = f.tf !== "1D" && f.tf !== "1W";
    const lab = d => intr ? d.toLocaleString("en-GB", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : d.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "2-digit" });
    s += `<text class="tlx-ax" x="${PL}" y="${H - 5}">${esc(lab(d0))}</text><text class="tlx-ax" text-anchor="end" x="${X(n - 1).toFixed(1)}" y="${H - 5}">${esc(lab(d1))}</text></svg>`;
    return s;
  }

  const chip = (k, L) => L ? `<span class="tlx-chip ${k} ${L.state}"><i></i>${k === "res" ? "Resistance" : "Support"} · ${esc(L.dir)} · ${L.touches} touches · ${esc(STATE[L.state] || L.state)}</span>` : "";
  const zone = z => z ? `<span class="tlx-zone ${esc(z.t)}">${z.t === "strong" ? "★ Strong zone" : "Major zone"} · ${esc((z.n || []).join(" + "))}</span>` : "";
  const MTFN = ["30m", "1H", "4H", "1D", "1W"];
  function mtf(m) {
    if (!m || !m.d) return "";
    const tag = m.bias ? `<b class="tlx-bias ${m.bias}">${m.bias === "CALL" ? "▲" : "▼"} ${m.bias} bias · ${m.bias === "CALL" ? m.up : m.down}/${m.n}</b>` : `<b class="tlx-bias none">No clear bias · ${m.up} up, ${m.down} down</b>`;
    return `<div class="tlx-mtf"><span class="tlx-mtfl">MTF trend</span>${MTFN.map(k => { const d = m.d[k] || 0; return `<span class="tlx-mt ${d > 0 ? "u" : d < 0 ? "d" : "n"}">${k} ${d > 0 ? "▲" : d < 0 ? "▼" : "–"}</span>`; }).join("")}${tag}</div>`;
  }
  const EVN = { break_up: "broke above resistance", break_down: "broke below support", at_sup: "at support", at_res: "at resistance" };
  function plan(p) {
    const f = v => fmt(v, p.dec);
    return `<article class="tlx-plan ${esc(p.dir)}">
      <div class="tlx-ptop"><span class="tlx-pdir">${p.dir === "CALL" ? "▲ CALL" : "▼ PUT"}</span><b>${p.view === "swing" ? "Swing plan" : "Intraday plan"} · ${esc(p.tf)} ${esc(p.kind)}</b><span class="tlx-q">Quality ${p.q}/100</span></div>
      <p class="tlx-pwhy">${esc(p.pattern)}, ${esc(EVN[p.e] || "")}. ${esc(p.read || "")}</p>
      ${zone(p.zone)}
      <table><tr><td>Entry<small>${esc(p.how)}</small></td><td>${f(p.entry)}</td></tr>
      <tr><td>Stop-loss<small>${esc(p.exit)} Risk ${p.risk_pct}% of price.</small></td><td class="dn">${f(p.sl)}</td></tr>
      <tr><td>Target 1<small>1.5 × the risk. Take part of the position off here.</small></td><td class="up">${f(p.t1)}</td></tr>
      <tr><td>Target 2<small>${/the risk$/.test(p.t2why) ? "2.5 × the risk." : esc(p.t2why) + " (" + p.rr2 + " × the risk)."}</small></td><td class="up">${f(p.t2)}</td></tr></table>
    </article>`;
  }

  function html(d) {
    if (d.error) return `<p class="tlx-empty">${esc(d.error)}</p>`;
    const fr = d.frames || [];
    let h = `<header class="tlx-head"><div><h2>Trend lines on the chart right now</h2><p class="tlx-sub">${esc(d.summary || "")}</p></div><span class="tlx-n">${fr.length} timeframe${fr.length === 1 ? "" : "s"}</span></header>` + mtf(d.mtf);
    const pl = d.plans || [];
    if (pl.length) h += `<div class="tlx-plans">${pl.map(plan).join("")}</div><p class="tlx-note">Entry, stop and targets are worked out by rule from the line and the chart's ATR, only for signals that agree with the MTF bias. No win rate is attached: these rules have not been measured.</p>`;
    else if (fr.length) h += `<p class="tlx-note">No trade plan right now: no line with three or more touches is being broken or tested in the direction of the MTF bias.</p>`;
    if (!fr.length) return h;
    h += `<div class="tlx-grid2">` + fr.map(f => `<article class="tlx-card lean-${esc(f.lean)}">
      <div class="tlx-top"><span class="tlx-tf">${esc(f.tf)}</span><b>${esc(f.pattern)}</b><em>${esc(TFN[f.tf] || f.tf)} chart</em></div>
      ${chart(f)}
      <div class="tlx-chips">${chip("res", f.res)}${f.res ? zone(f.res.zone) : ""}${chip("sup", f.sup)}${f.sup ? zone(f.sup.zone) : ""}</div>
      <ul class="tlx-txt">${(f.text || []).map(t => `<li>${esc(t)}</li>`).join("")}</ul>
    </article>`).join("") + `</div><p class="tlx-note">Lines are drawn by rule from swing highs and lows: a line is kept only while no candle has closed through it. They describe the chart; they are not a forecast or advice.</p>`;
    return h;
  }

  const S = { sym: null, node: null, at: 0, busy: false };
  async function load(sym, cb) {
    if (S.busy) return; S.busy = true;
    try {
      const r = await fetch(`/tl_api.php?s=${encodeURIComponent(sym)}`, { cache: "no-store" });
      const d = await r.json();
      if (S.sym === sym && S.node) { S.node.innerHTML = html(d); S.at = Date.now(); } if (cb) try { cb(d); } catch (e) {}
    } catch (e) { if (S.node && !S.at) S.node.innerHTML = `<p class="tlx-empty">Trend lines could not be loaded just now.</p>`; }
    finally { S.busy = false; }
  }
  function mount(root, sym, cb) {
    if (!root || !sym) return;
    if (S.sym !== sym || !S.node) { S.sym = sym; S.at = 0; S.node = document.createElement("section"); S.node.className = "tlx"; S.node.innerHTML = `<p class="tlx-empty">Reading the trend lines on every timeframe…</p>`; }
    const after = root.querySelector(":scope > .aib");
    if (after) after.insertAdjacentElement("afterend", S.node); else root.insertAdjacentElement("afterbegin", S.node);
    if (Date.now() - S.at > 60000) load(sym, cb);
  }
  CP.trendlines = { mount, html };

  function hook() {
    if (!CP.desk || !CP.desk.render || CP.desk._tlx) return !!(CP.desk && CP.desk._tlx);
    const orig = CP.desk.render;
    CP.desk.render = function (root, d) { const r = orig.apply(this, arguments); try { mount(root, d && d.symbol); } catch (e) {} return r; };
    CP.desk._tlx = true; return true;
  }
  if (!hook()) document.addEventListener("DOMContentLoaded", hook);
})();
