/* ChartPulse v10 UI (owner PDF "Final Setup", 19 Oct 2026). Loaded after cp.js and before torus.js on the
   scanner, and on tier2.html. Wraps CP.flowChip and replaces CP.byTier; cp.js and tier2.js are unchanged.

   What this file shows, and where it comes from:
     - the gate ladder, identical on Tier 1 and Tier 2, straight off the row's ph[] array:
       a diamond for the gate, then 3 entry ticks (x/3) -- Early Entry, Entry A, Double Confirm
     - the gate remark: "Liquidity Sweep" / "BB Sweep" / "Sweep + BB" (row.t0), both setups
     - the Strong Zone remark, or the single level standing at the zone (row.zg / row.zlv)
     - the dark RETEST ++ chip (row.pw)
     - the status ladder APPROACHING / APPROACHING AGAIN / SIGNAL / FLY / GATE CLOSE

   Sort (owner PDF S9.1, 19 Oct 2026) -- this REPLACES the 13 Oct "retest first" order:
     zone group first, status second. A Strong Zone APPROACHING row sits ABOVE a one-level FLY row.
     Inside a group: FLY, then SIGNAL, then APPROACHING, then GATE CLOSE; then retest/sweep; then score.

   Owner, 12 Oct 2026: the Recent-winners summary line stays hidden on Tier 1.
   Ladder (owner, 20 Oct 2026): row.lad === 10 means the gate ladder - ph[0] is the GATE (a diamond, never an
   entry) and ph[1..3] are Early Entry / Entry A / Double Confirm, so the counter reads x/3. lad 8 is the old
   phase row an earlier engine stored. The Supertrend-flip tick is gone. */
(() => {
  "use strict";
  const CP = window.CP;
  /* 25 Oct 2026: hide scanner body until v10 patches are applied, preventing old v8 UI flash */
  try { const hd = document.createElement("style"); hd.id = "cpV10Hide"; hd.textContent = "body:not(.v10-ready) #tBody,body:not(.v10-ready) .scan-wrap{visibility:hidden}"; document.head.appendChild(hd); } catch (e) {}
  const css = ".tks{display:inline-flex;align-items:center;gap:2px;margin-left:6px;vertical-align:middle}.tks i{width:7px;height:7px;border-radius:2px;background:rgba(127,127,127,.28);display:inline-block}.tks i.gate{border-radius:1px;transform:rotate(45deg);width:6px;height:6px;margin-right:3px}.tks.call i.on{background:#22AB94}.tks.put i.on{background:#F7525F}.tks b{font:600 10px/1 var(--mono,monospace);margin-left:3px;opacity:.8}"
    + ".rtest{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:5px;font:700 10px/1.3 var(--mono,monospace);letter-spacing:.04em;background:#0b1220;color:#e6edf7;border:1px solid #2a3550;box-shadow:inset 0 0 0 1px rgba(255,255,255,.04);vertical-align:middle}.rtest.sw{background:#1b2333;color:#c9d4e5}"
    + ".gopen{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:5px;font:700 10px/1.3 var(--mono,monospace);letter-spacing:.04em;color:#2DD4AA;border:1px solid rgba(45,212,170,.55);background:rgba(45,212,170,.08);vertical-align:middle}"
    + ".gopen.put{color:#F7525F!important;border-color:rgba(247,82,95,.55)!important;background:rgba(247,82,95,.08)!important}"
    + ".zstrong{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:5px;font:700 10px/1.3 var(--mono,monospace);letter-spacing:.04em;color:#F4B740;border:1px solid rgba(244,183,64,.6);background:rgba(244,183,64,.1);vertical-align:middle}"
    + ".zlvl{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:4px;font:600 10px/1.3 var(--mono,monospace);color:#9fb0c9;border:1px solid rgba(159,176,201,.35);vertical-align:middle}"
    + ".win-h p{display:none!important}.flow.gate-close{opacity:.75;background:rgba(127,127,127,.18)!important;color:inherit!important;border-color:rgba(127,127,127,.4)!important}"
    + ".flow.gate-open{background:rgba(45,212,170,.1)!important;color:#2DD4AA!important;border-color:rgba(45,212,170,.5)!important}"
    + ".slw{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:4px;font:700 10px/1.3 var(--mono,monospace);color:#F4B740;border:1px solid rgba(244,183,64,.5);vertical-align:middle}"
    + ".t2v10{display:flex;flex-wrap:wrap;align-items:center;gap:2px;margin-left:4px}"
    + ".tgt-chip{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:5px;font:700 10px/1.3 var(--mono,monospace);letter-spacing:.04em;vertical-align:middle;border:1px solid}"
    + ".tgt-mid{color:#5B9BD5;border-color:rgba(91,155,213,.55);background:rgba(91,155,213,.08)}"
    + ".pb5tick{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:5px;font:700 10px/1.3 var(--mono,monospace);letter-spacing:.04em;vertical-align:middle;border:1px solid;color:#A78BFA;border-color:rgba(167,139,250,.55);background:rgba(167,139,250,.1)}"
    + ".pb5tick.put{color:#F7525F;border-color:rgba(247,82,95,.55);background:rgba(247,82,95,.08)}";
  try {
    if (!document.getElementById("cpV10Css")) { const s = document.createElement("style"); s.id = "cpV10Css"; s.textContent = css; document.head.appendChild(s); }
  } catch (e) {}

  // ---- labels -------------------------------------------------------------
  // Tick 0's label is whatever actually fired on 30m, so the chip and the dot tooltip always agree.
  const GATE = ["Gate Open", "Early Entry", "Entry A", "Double Confirm"];
  const OLD4 = ["Gap Zone", "Entry A", "Early Entry", "Double Confirm"];
  const isGate = r => r && r.lad === 10 && Array.isArray(r.ph) && r.ph.length === 4;
  const tkLabels = r => {
    if (!isGate(r)) return OLD4;
    const L = GATE.slice();
    if (r.t0) L[0] = r.t0;        // the gate names whichever sweep actually fired
    return L;
  };
  const flyIdx = r => (Array.isArray(r.ph) ? r.ph.length : 0) - 1;
  const TIP = {
    "GATE OPEN": "GATE OPEN: the zone was swept, waiting for the first entry tick",
    SIGNAL: "SIGNAL: entry ticks are coming in",
    "GATE CLOSE": "GATE CLOSE: price has left the entry range, this signal ends in a few minutes",
    "APPROACHING AGAIN": "the zone moved and the setup is building again here — this signal was not cancelled"
  };

  const tkDots = r => {
    if (!Array.isArray(r.ph) || !r.ph.length) return "";
    const TK = tkLabels(r), g = isGate(r);
    const tip = r.ph.map((x, i) => (x ? "✓ " : "· ") + (TK[i] || "Tick " + i)).join(" | ");
    // The gate is a diamond and is NOT counted as a tick: the counter is the 3 entry ticks only.
    const ticks = g ? r.ph.slice(1) : r.ph;
    return `<span class="tks ${r.signal === "PUT" ? "put" : "call"}" title="${tip}">`
      + r.ph.map((x, i) => `<i class="${g && i === 0 ? "gate " : ""}${x ? "on" : ""}"></i>`).join("")
      + `<b>${ticks.filter(Boolean).length}/${ticks.length}</b></span>`;
  };
  // A structural stop wider than 1.5% is capped and flagged, so the risk on screen is the real one.
  const slwChip = r => (r && r.sl_wide ? '<span class="slw" title="Structure stop is wider than 1.5% - capped">WIDE</span>' : "");
  const pwChip = r => r.pw === 2
    ? '<span class="rtest" title="Retest at the Pulse zone">RETEST ++</span>'
    : r.pw === 1 ? '<span class="rtest sw" title="Sweep at the Pulse zone">+</span>' : "";
  // Tick-0 remark. The server already words it: "Liquidity Sweep" / "BB Sweep" in trend, "GATE OPEN" in
  // retracement. The older `go` flag is the fallback for rows stored before 19 Oct 2026.
  const goChip = r => {
    if (r && r.t0) return `<span class="gopen" title="Gate open at the Pulse zone">${r.t0}</span>`;
    return r && r.go ? '<span class="gopen" title="Extra quality sign at the Pulse zone">GATE OPEN</span>' : "";
  };
  // Strong Zone, or the one level standing at the zone (owner PDF S9.1 / S9.2).
  const zChip = r => {
    if (!r) return "";
    if (r.zg === 0) return `<span class="zstrong" title="${r.zlv || "Fib + Pivot + PDH/PDL in one zone"}">STRONG ZONE</span>`;
    if (r.zg === 1 && r.zlv) return `<span class="zlvl" title="Price is standing at this level">${r.zlv}</span>`;
    return "";
  };

  const tkState = r => {
    if (!Array.isArray(r.ph) || !r.ph.length) return null;
    if (r.ph[flyIdx(r)]) return "FLY";
    if (!isGate(r)) return r.ph.some(Boolean) ? "SIGNAL" : "APPROACHING";
    // Gate ladder: an entry needs one of ticks 1-3; the gate alone is GATE OPEN, never a signal.
    if (r.ph.slice(1).some(Boolean)) return "SIGNAL";
    return r.ph[0] ? (r.flow === "GATE CLOSE" ? "GATE CLOSE" : "GATE OPEN") : "APPROACHING";
  };
  const stateChip = (r, state) => {
    const side = r.signal === "PUT" ? "put" : r.signal === "CALL" ? "call" : "";
    const hc = state === "FLY" && r.hc;
    return `<span class="flow ${state.toLowerCase().replace(/\s+/g, "-")} ${side}${hc ? " hc" : ""}" title="${hc ? "High-conviction entry · " : ""}${TIP[state] || ""}">${state === "FLY" ? "✈ " : ""}${state}${hc ? " ⚡" : ""}</span>`;
  };
  // Mid Target badge (midtrend). Long/Small target already show via the server remark field.
// Appears on Tier 1 and Tier 2 rows via decorate(). Server-side setup field stays private.
const setupChip = r => {
    // Mid-trend rides on a Retracement row as r.mt (server sets it once Retracement TP1 has hit).
    if (r.mt && r.mt.flow) {
        const up = r.mt.sig === 'CALL';
        return `<span class="tgt-chip tgt-mid" title="Mid Target — ${up ? 'Major Support' : 'Major Resistance'} zone">Mid Target</span>`;
    }
    return '';
};
// Pullback+5EMA combined tick — informational only, shown on trend/retrace only (not mid-trend)
const pb5Chip = r => {
    if (!r || !r.pb5 || r.mt) return ''; // not for mid-trend (r.mt set = mid-trend)
    const side = (r.signal === 'PUT' || r.bias === 'PUT') ? 'put' : 'call';
    return `<span class="pb5tick ${side}" title="Structure shift on 5m + Pullback to 5 EMA + Rejection candle — Pullback+5EMA combined">PB+5EMA ✓</span>`;
};
const decorate = r => setupChip(r) + goChip(r) + zChip(r) + tkDots(r) + pb5Chip(r) + slwChip(r) + pwChip(r);

  // ---- Tier 1 -------------------------------------------------------------
  if (CP && CP.flowChip) {
    const orig = CP.flowChip;
    CP.flowChip = r => {
      let h = orig(r);
      const isSignalTier = r && r.tier1 === "signal";
      if (r && r.flow === "GATE CLOSE") h = h.replace('class="flow gate close ', 'class="flow gate-close ');
      if (r && TIP[r.flow]) h = h.replace(/title="/, `title="${TIP[r.flow]}`);
      if (h.indexOf("flow none") >= 0 && isSignalTier) {
        const st = tkState(r);
        if (st) h = stateChip(r, st);
      }
      if (h.indexOf("flow none") >= 0 && !isSignalTier) return h;
      return h + decorate(r);
    };

    // Sort: status first (FLY at top), then zone group, then retest/sweep, then high-conviction, then score. (owner, 1 Nov 2026)
    const STATUS_RANK = { FLY: 0, SIGNAL: 1, RUNWAY: 1, "GATE OPEN": 2, APPROACHING: 3, "APPROACHING AGAIN": 3, "GATE CLOSE": 4 };
    const statusOf = r => tkState(r) || (r && r.flow) || null;
    const sortRank = r => {
      if (!r) return [9, 9, 9, 9, 9, 0];
      const out = r.tier1 === "out" ? 1 : 0;
      const zg = typeof r.zg === "number" ? r.zg : 2;   // 0 Strong Zone, 1 one level, 2 the rest
      const st = STATUS_RANK[statusOf(r)] ?? 4;
      const pw = 2 - (r.pw || 0);
      const hc = r.hc ? 0 : 1;
      return [out, st, zg, pw, hc, -(r.strength || 0)];  // st before zg: FLY always beats APPROACHING regardless of zone strength
    };
    CP.byTier = (a, b) => {
      const ra = sortRank(a), rb = sortRank(b);
      for (let i = 0; i < ra.length; i++) if (ra[i] !== rb[i]) return ra[i] - rb[i];
      return 0;
    };
    CP.tkDots = tkDots; CP.pwChip = pwChip; CP.tkState = tkState; CP.zChip = zChip;
    /* 1 Nov 2026: sort fix — status beats zone group; FLY always at top */
    document.body.classList.add("v10-ready");
  }

  // ---- Tier 2 -------------------------------------------------------------
  // tier2.js draws its own header chips and never reads ph / pw / go / t0 / zg, so the owner's standing rule
  // ("whatever is live on Tier 1 must look the same on Tier 2") was broken there. Rather than rewrite tier2.js,
  // this reads the same r=t2 payload the page uses and appends the identical strip into the header chip row.
  const t2Mount = () => {
    const chips = document.querySelector(".t2-chips");
    if (!chips) return null;
    let box = document.getElementById("t2v10");
    if (!box) { box = document.createElement("span"); box.id = "t2v10"; box.className = "t2v10"; chips.appendChild(box); }
    return box;
  };
  const t2Sym = () => {
    try { const u = new URL(location.href); return u.searchParams.get("s") || ""; } catch (e) { return ""; }
  };
  const t2Bias = () => {
    try { const u = new URL(location.href); return u.searchParams.get("bias") || ""; } catch (e) { return ""; }
  };
  // tier2.js writes its own status line from its own stage logic, which predates v10 -- so a row that is already
  // FLY on Tier 1 could still read "Watching the Pulse zone..." here. Rather than rewrite tier2.js, the live
  // Tier-1 state is written over that line (and over the copy in the Trade plan panel). APPROACHING is left
  // alone, because there the page's own wording is already correct.
  const T2_STATUS = {
    FLY: "FLY · entry confirmed",
    SIGNAL: "SIGNAL · entry ticks coming in",
    "GATE OPEN": "GATE OPEN · zone swept, waiting for the entry tick",
    "GATE CLOSE": "GATE CLOSE · this signal is ending"
  };
  const t2SyncStatus = r => {
    if (!r) return;
    const st = tkState(r) || r.flow || null;
    if (!st || !T2_STATUS[st]) return;
    const T = Array.isArray(r.ph) ? (isGate(r) ? r.ph.slice(1) : r.ph) : null;
    const n = T ? (st === "GATE OPEN" ? "" : " · " + T.filter(Boolean).length + "/" + T.length) : "";
    const want = T2_STATUS[st] + n;
    try {
      document.querySelectorAll("#t2Status b, .t2-status b").forEach(el => {
        if (el.textContent !== want) el.textContent = want;
      });
      // The Trade plan copy sits in a text node that shares its paragraph with the LONG TARGET chip, so it has
      // to be reached as a text node, not as a childless element.
      const plan = document.getElementById("t2Plan");
      if (plan) {
        const w = document.createTreeWalker(plan, NodeFilter.SHOW_TEXT);
        let tn;
        while ((tn = w.nextNode())) {
          if (/^\s*Watching the Pulse zone/.test(tn.nodeValue || "")) tn.nodeValue = want;
        }
      }
    } catch (e) {}
  };

  // t2dash.js draws the ENTRY TICKS panel from its own label list, which still knows the old 5-step order.
  // Rather than ship a second copy of that file, the panel is relabelled here from the same payload: one
  // GATE row plus the 3 entry ticks.
  const t2Ladder = r => {
    if (!isGate(r)) return;
    const box = document.querySelector("#sdCard .sd-ph");
    if (!box) return;
    const TK = tkLabels(r), P = r.ph;
    const pw = r.pw === 2 ? '<span class="rtest" title="Retest at the Pulse zone">RETEST ++</span>'
      : r.pw === 1 ? '<span class="rtest sw" title="Sweep at the Pulse zone">+</span>' : "";
    const row = (tag, name, on) => `<div class="sd-ph-r ${on ? "done" : ""}"><small>${tag}</small>${name}<span class="ck">✓</span></div>`;
    const pb5Row = r.pb5 && r.setup !== 'mid' ? `<div class="sd-ph-r done pb5-row" title="Structure shift on 5m + Pullback to 5 EMA + Rejection candle"><small>INFO</small><span class="pb5tick ${(r.signal||r.bias||'').toUpperCase()==='PUT'?'put':''}">PB+5EMA ✓</span><span class="ck">✓</span></div>` : "";
    box.innerHTML = `<div class="sd-sync-h"><span>Entry ladder${pw}</span><b>${P.slice(1).filter(Boolean).length}/3</b></div>`
      + row("GATE", TK[0], P[0])
      + [1, 2, 3].map(i => row("TICK " + i, TK[i], P[i])).join("")
      + pb5Row;
  };

  let t2Last = null;
  const t2Paint = async () => {
    const box = t2Mount(); const sym = t2Sym();
    if (!box || !sym) return;
    try {
      const q = "api.php?r=t2&s=" + encodeURIComponent(sym) + (t2Bias() ? "&bias=" + encodeURIComponent(t2Bias()) : "");
      const res = await fetch(q, { credentials: "same-origin" });
      if (!res.ok) return;                      // locked (402) or an error: leave the page as tier2.js drew it
      const d = await res.json();
      const r = d && (d.t2 || d.data || d) || null;
      if (!r || !Array.isArray(r.ph)) return;
      r.signal = r.signal || r.sig || t2Bias();
      t2Last = r;
      box.innerHTML = decorate(r);
      t2SyncStatus(r);
      t2Ladder(r);
    } catch (e) {}
  };
  if (document.querySelector(".t2-chips")) {
    // tier2.js redraws on its own schedule, so the status line is re-applied from the last payload in between
    // fetches; that keeps the two pages in step without a second network call.
    const go = () => { t2Paint(); setInterval(t2Paint, 30000); setInterval(() => { t2SyncStatus(t2Last); t2Ladder(t2Last); }, 3000); };
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", go); else go();
  }
})();
