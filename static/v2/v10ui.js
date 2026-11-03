/* ChartPulse v10 UI (owner PDF "Final Setup", 19 Oct 2026; updated 3 Nov 2026). Loaded after cp.js and
   before torus.js on the scanner, and on tier2.html. Wraps CP.flowChip and replaces CP.byTier; cp.js and
   tier2.js are unchanged.

   What this file shows, and where it comes from:
     - the gate ladder, identical on Tier 1 and Tier 2, straight off the row's ph[] array:
       a diamond for the gate, then 4 entry ticks (x/4) -- Early Entry, Entry A, Reversal Pat, Double Confirm
       GATE is now 10m (was 30m). Tick 3 = 5m reversal pattern + next 5m candle confirm. Tick 4 = FLY.
     - the gate remark: "Liquidity Sweep" / "BB Sweep" / "Sweep + BB" (row.t0), both setups
     - the Strong Zone remark, or the single level standing at the zone (row.zg / row.zlv)
     - the dark RETEST ++ chip (row.pw)
     - the status ladder APPROACHING / APPROACHING AGAIN / TICK 1 / TICK 2 / TICK 3 / FLY / GATE CLOSE

   Sort (3 Nov 2026): tick count descending -- TICK 4 (FLY) > TICK 3 > TICK 2 > TICK 1 > GATE OPEN > APPROACHING.
     Score is the tiebreaker within the same tick level. Zone group is secondary to tick count.

   Owner, 12 Oct 2026: the Recent-winners summary line stays hidden on Tier 1.
   Ladder (updated 3 Nov 2026): row.lad === 10 means the gate ladder - ph[0] is the GATE (a diamond, never an
   entry) and ph[1..4] are Early Entry / Entry A / Reversal Pat / Double Confirm, so the counter reads x/4.
   lad 8 is the old phase row an earlier engine stored. ph.length===4 is back-compat for pre-3Nov rows. */
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
    + ".pb5tick.put{color:#F7525F;border-color:rgba(247,82,95,.55);background:rgba(247,82,95,.08)}"
    + ".flow.tick-1,.flow.tick-2,.flow.tick-3,.flow.tick-4{background:rgba(91,155,213,.1)!important;color:#5B9BD5!important;border-color:rgba(91,155,213,.5)!important}"
    + ".flow.tick-3{background:rgba(244,183,64,.08)!important;color:#F4B740!important;border-color:rgba(244,183,64,.5)!important}"
    + ".flow.tick-4{background:rgba(34,171,148,.1)!important;color:#22AB94!important;border-color:rgba(34,171,148,.5)!important}";
  try {
    if (!document.getElementById("cpV10Css")) { const s = document.createElement("style"); s.id = "cpV10Css"; s.textContent = css; document.head.appendChild(s); }
  } catch (e) {}

  // ---- labels -------------------------------------------------------------
  // Tick 0's label is whatever actually fired on 10m, so the chip and the dot tooltip always agree.
  // ph.length===5: Gate + 4 ticks (Early Entry, Entry A, Reversal Pat, Double Confirm)
  // ph.length===4: back-compat pre-3Nov rows (Gate + 3 ticks)
  const GATE5 = ["Gate Open", "Early Entry", "Entry A", "Reversal Pat", "Double Confirm"];
  const GATE4 = ["Gate Open", "Early Entry", "Entry A", "Double Confirm"];
  const OLD4  = ["Gap Zone", "Entry A", "Early Entry", "Double Confirm"];
  const isGate = r => r && r.lad === 10 && Array.isArray(r.ph) && (r.ph.length === 5 || r.ph.length === 4);
  const tkLabels = r => {
    if (!isGate(r)) return OLD4;
    const base = r.ph.length === 5 ? GATE5 : GATE4;
    const L = base.slice();
    if (r.t0) L[0] = r.t0;        // the gate names whichever sweep actually fired
    return L;
  };
  const flyIdx = r => (Array.isArray(r.ph) ? r.ph.length : 0) - 1;
  const TIP = {
    "GATE OPEN": "GATE OPEN: the zone was swept, waiting for the first entry tick",
    SIGNAL: "SIGNAL: entry ticks are coming in",
    "TICK 1": "TICK 1: Early Entry confirmed",
    "TICK 2": "TICK 2: Entry A confirmed",
    "TICK 3": "TICK 3: Reversal Pattern confirmed",
    "TICK 4": "TICK 4: Double Confirm — all ticks in",
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
    // Gate ladder: gate alone = GATE OPEN; any entry tick = TICK N (highest tick confirmed)
    const ticks = r.ph.slice(1);
    if (ticks.some(Boolean)) {
      for (let i = ticks.length - 1; i >= 0; i--) {
        if (ticks[i]) return "TICK " + (i + 1);
      }
    }
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
      const isSignalTier = r && r.tier1 === "signal";
      // For signal-tier rows, always derive status from ph[] — server r.flow can be stale/generic.
      if (isSignalTier && Array.isArray(r.ph) && r.ph.length) {
        const st = tkState(r);
        if (st) return stateChip(r, st) + decorate(r);
      }
      let h = orig(r);
      if (r && r.flow === "GATE CLOSE") h = h.replace('class="flow gate close ', 'class="flow gate-close ');
      if (r && TIP[r.flow]) h = h.replace(/title="/, `title="${TIP[r.flow]}`);
      if (h.indexOf("flow none") >= 0 && isSignalTier) {
        const st = tkState(r);
        if (st) h = stateChip(r, st);
      }
      if (h.indexOf("flow none") >= 0 && !isSignalTier) return h;
      return h + decorate(r);
    };

    // Sort (3 Nov 2026): tick count descending — TICK 4 (FLY) > TICK 3 > TICK 2 > TICK 1 > GATE OPEN > APPROACHING.
    // tickCountRank: 0=4 ticks, 1=3 ticks, 2=2 ticks, 3=1 tick, 4=gate only, 5=approaching, 6=gate-close
    const statusOf = r => tkState(r) || (r && r.flow) || null;
    const tickCountRank = r => {
      if (!r || !Array.isArray(r.ph)) return 5;
      const st = statusOf(r);
      if (st === "GATE CLOSE") return 6;
      if (st === "FLY") return 0;
      // "TICK N" state: rank = 4 - N (TICK 4→0, TICK 3→1, TICK 2→2, TICK 1→3)
      const tm = st && st.match(/^TICK (\d+)$/);
      if (tm) return Math.max(0, 4 - parseInt(tm[1], 10));
      if (st === "GATE OPEN") return 4;
      if (!isGate(r)) {
        const cnt = r.ph.filter(Boolean).length;
        return cnt ? 4 - cnt : 5;
      }
      const ticks = r.ph.slice(1);
      const cnt = ticks.filter(Boolean).length;
      if (cnt === 0) return r.ph[0] ? 4 : 5;
      return 4 - cnt;
    };
    const sortRank = r => {
      if (!r) return [9, 9, 9, 9, 9, 0];
      const out = r.tier1 === "out" ? 1 : 0;
      const tc = tickCountRank(r);              // tick count descending (fewer rank = more ticks)
      const pw = 2 - (r.pw || 0);
      const hc = r.hc ? 0 : 1;
      return [out, tc, pw, hc, -(r.strength || 0)];
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
  // T2_STATUS: show actual tick level, not just generic FLY/SIGNAL labels.
  // For SIGNAL states, we show TICK N (how many ticks are confirmed).
  const t2StatusLabel = r => {
    if (!r) return null;
    const st = tkState(r) || r.flow || null;
    if (!st) return null;
    if (st === "FLY") return "FLY · entry confirmed";
    if (st === "GATE CLOSE") return "GATE CLOSE · this signal is ending";
    if (st === "GATE OPEN") return "GATE OPEN · zone swept, waiting for entry tick";
    if (st === "APPROACHING" || st === "APPROACHING AGAIN") return null;  // leave page's own wording
    // "TICK N" state from tkState: enrich with label name
    const tm = st && st.match(/^TICK (\d+)$/);
    if (tm) {
      const TK = tkLabels(r);
      const idx = parseInt(tm[1], 10);
      const name = TK[idx] || st;
      return `${st} · ${name} confirmed`;
    }
    return "SIGNAL · entry ticks coming in";
  };
  const t2SyncStatus = r => {
    if (!r) return;
    const want = t2StatusLabel(r);
    if (!want) return;
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
    const tickCount = P.length - 1;  // 4 for new rows (5-element ph), 3 for back-compat (4-element ph)
    const tickIdxs = Array.from({ length: tickCount }, (_, i) => i + 1);
    box.innerHTML = `<div class="sd-sync-h"><span>Entry ladder${pw}</span><b>${P.slice(1).filter(Boolean).length}/${tickCount}</b></div>`
      + row("GATE", TK[0], P[0])
      + tickIdxs.map(i => row("TICK " + i, TK[i], P[i])).join("")
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
