/* ChartPulse USDT checkout (Trust Wallet / any wallet). Used by pricing.js: window.CPX.start(sel, who) */
(() => {
  "use strict";
  const { $, esc } = window.CP;
  const NETS = [
    ["trc20", "TRC20", "Tron", "Fee ~1 USDT", "Recommended"],
    ["bep20", "BEP20", "BNB Smart Chain", "Fee under $0.10", "Low fee"],
    ["polygon", "Polygon", "Polygon PoS", "Fee under $0.05", ""],
    ["erc20", "ERC20", "Ethereum", "Fee $1–5, can be higher", "High fee"]
  ];
  const css = `.ux-nets{display:grid;gap:8px;margin:12px 0}.ux-net{display:flex;align-items:center;gap:12px;width:100%;text-align:left;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.03);color:inherit;cursor:pointer;font:inherit}
.ux-net:hover{border-color:#F4B740;background:rgba(244,183,64,.06)}.ux-net b{font-size:15px}.ux-net small{display:block;opacity:.65;font-size:12px}.ux-net .t{margin-left:auto;font-size:11px;padding:3px 8px;border-radius:99px;background:rgba(34,171,148,.15);color:#22AB94;white-space:nowrap}.ux-net .t.w{background:rgba(247,82,95,.14);color:#F7525F}
.ux-pay{display:grid;gap:12px;margin-top:10px}.ux-row{display:flex;gap:14px;align-items:center}.ux-qr{background:#fff;padding:8px;border-radius:10px;flex:none;width:148px;height:148px}.ux-qr img,.ux-qr canvas{width:132px!important;height:132px!important}
.ux-f{display:grid;gap:4px;min-width:0}.ux-f span{font-size:12px;opacity:.65}.ux-v{display:flex;gap:8px;align-items:center;min-width:0}.ux-v code{font-family:ui-monospace,Menlo,monospace;font-size:13px;word-break:break-all;background:rgba(255,255,255,.05);padding:6px 8px;border-radius:8px}
.ux-amt code{font-size:20px;color:#F4B740;font-weight:700}.ux-cp{flex:none;font-size:12px;padding:5px 9px;border-radius:8px;border:1px solid rgba(255,255,255,.18);background:none;color:inherit;cursor:pointer}
.ux-warn{font-size:12.5px;line-height:1.5;padding:9px 11px;border-radius:10px;background:rgba(244,183,64,.08);border:1px solid rgba(244,183,64,.25)}.ux-st{display:flex;gap:8px;align-items:center;font-size:13px;opacity:.85}.ux-st i{width:8px;height:8px;border-radius:50%;background:#F4B740;animation:uxp 1.4s infinite}
@keyframes uxp{50%{opacity:.25}}.ux-tx{font-size:12.5px}.ux-tx summary{cursor:pointer;opacity:.75}.ux-back{background:none;border:0;color:inherit;opacity:.65;cursor:pointer;padding:0;font:inherit;font-size:13px}
@media(max-width:520px){.ux-row{flex-direction:column;align-items:stretch}.ux-qr{margin:0 auto}}`;
  document.head.insertAdjacentHTML("beforeend", `<style id="cpUsdtCss">${css}</style>`);

  async function post(a, body) {
    const r = await fetch("/pay.php?a=" + a, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.error || "Something went wrong. Please try again.");
    return j;
  }
  let qrLib = null;
  function loadQR() {
    if (window.QRCode) return Promise.resolve();
    return qrLib || (qrLib = new Promise((ok, no) => { const s = document.createElement("script"); s.src = "https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"; s.onload = ok; s.onerror = no; document.head.appendChild(s); }));
  }
  const card = () => $("#payCard");
  let timer = null, tick = null;
  function stop() { clearTimeout(timer); clearInterval(tick); timer = tick = null; }
  function copyBtn(v) { return `<button type="button" class="ux-cp" data-cp="${esc(v)}">Copy</button>`; }
  function bindCopy(root) {
    root.querySelectorAll("[data-cp]").forEach(b => b.addEventListener("click", () => {
      navigator.clipboard && navigator.clipboard.writeText(b.dataset.cp).then(() => { b.textContent = "Copied"; setTimeout(() => (b.textContent = "Copy"), 1400); });
    }));
  }
  function success(v) {
    stop();
    const code = v.access_code ? `<div class="px-code"><span>Your access code</span><b class="mono">${esc(v.access_code)}</b><small>This browser is unlocked already. Use the code to unlock your plan on your other devices.</small></div>` : "";
    const tx = v.tx || v.payment_id || "";
    card().innerHTML = `<div class="ok-box"><div class="tick">✓</div><h3>Payment received</h3><p class="m-sub">Transaction <b class="mono" style="word-break:break-all">${esc(tx)}</b>. Keep it for your records.</p>${code}<a class="btn primary lg shine" href="scanner.html">Open the scanner</a></div>`;
  }

  function chooser(sel, who, title, total) {
    stop();
    card().innerHTML = `<h3>${esc(title)}</h3><p class="m-sub">Pay <b>${esc(total)}</b> in USDT from Trust Wallet or any crypto wallet. Choose the network your USDT is on.</p>
      <div class="ux-nets">${NETS.map(n => `<button type="button" class="ux-net" data-net="${n[0]}"><div><b>${n[1]}</b> <small>${n[2]} · ${n[3]}</small></div>${n[4] ? `<span class="t ${n[0] === "erc20" ? "w" : ""}">${n[4]}</span>` : ""}</button>`).join("")}</div>
      <p class="m-err" id="uxErr"></p><div class="m-actions"><button type="button" class="btn ghost" id="uxCancel">Cancel</button></div>`;
    $("#uxCancel").addEventListener("click", () => location.reload());
    card().querySelectorAll("[data-net]").forEach(b => b.addEventListener("click", async () => {
      card().querySelectorAll("[data-net]").forEach(x => (x.disabled = true));
      $("#uxErr").textContent = "";
      try {
        const o = await post("usdt_order", { sku: sel.sku, period: sel.period, net: b.dataset.net, name: who.name, email: who.email, phone: who.phone, website: who.website });
        try { localStorage.setItem("cp_usdt", JSON.stringify({ order: o.order, at: Date.now() })); } catch (e) {}
        pay(o, who.email, () => chooser(sel, who, title, total));
      } catch (err) { $("#uxErr").textContent = err.message; card().querySelectorAll("[data-net]").forEach(x => (x.disabled = false)); }
    }));
  }

  function pay(o, email, back) {
    stop();
    card().innerHTML = `<button type="button" class="ux-back" id="uxBack">← Change network</button>
      <h3 style="margin-top:6px">Send USDT on ${esc(o.net_label)}</h3>
      <div class="ux-pay">
        <div class="ux-row"><div class="ux-qr" id="uxQr"></div>
          <div class="ux-f" style="flex:1">
            <div class="ux-f ux-amt"><span>Exact amount (USDT)</span><div class="ux-v"><code>${esc(o.amount)}</code>${copyBtn(o.amount)}</div></div>
            <div class="ux-f"><span>To address · ${esc(o.net_label)}</span><div class="ux-v"><code>${esc(o.address)}</code>${copyBtn(o.address)}</div></div>
          </div></div>
        <div class="ux-warn">Send <b>exactly ${esc(o.amount)} USDT</b> on <b>${esc(o.net_label)}</b> only. The exact amount is how we find your payment. Network fee (${esc(o.fee)}) is paid by your wallet, on top.</div>
        <a class="btn primary lg shine" href="${esc(o.tw)}" target="_blank" rel="noopener">Open in Trust Wallet</a>
        <div class="ux-st" id="uxSt"><i></i><span>Waiting for your payment. This page checks automatically.</span></div>
        <details class="ux-tx"><summary>Already sent? Paste the transaction hash</summary>
          <form id="uxTxF" style="margin-top:8px;display:grid;gap:8px"><label class="field"><span>Transaction hash</span><input id="uxTx" required maxlength="80" spellcheck="false" autocomplete="off" placeholder="${o.net === "trc20" ? "64 characters" : "0x…"}"></label>
          <button class="btn ghost" id="uxTxGo">Check this transaction</button></form></details>
        <p class="m-err" id="uxErr"></p>
      </div>`;
    bindCopy(card());
    $("#uxBack").addEventListener("click", back);
    loadQR().then(() => { new window.QRCode($("#uxQr"), { text: o.address, width: 132, height: 132, correctLevel: window.QRCode.CorrectLevel.M }); }).catch(() => ($("#uxQr").style.display = "none"));
    const skew = Date.now() / 1000 - o.now;
    const st = $("#uxSt span");
    tick = setInterval(() => {
      const left = Math.round(o.expires - (Date.now() / 1000 - skew));
      if (left > 0) st.textContent = `Waiting for your payment · ${Math.floor(left / 60)}:${String(left % 60).padStart(2, "0")} left. This page checks automatically.`;
      else st.textContent = "Time is up for this amount. If you already sent it, keep this page open or paste the transaction hash below.";
    }, 1000);
    let n = 0;
    const poll = async () => {
      n++;
      try { const v = await post("usdt_check", { order: o.order }); if (v.status === "paid") return success(v); } catch (e) {}
      if (n < 400) timer = setTimeout(poll, n < 40 ? 12000 : 25000);
    };
    timer = setTimeout(poll, 8000);
    $("#uxTxF").addEventListener("submit", async e => {
      e.preventDefault(); const go = $("#uxTxGo"); go.disabled = true; $("#uxErr").textContent = "";
      try { success(await post("usdt_recover", { net: o.net, tx: $("#uxTx").value.trim(), email })); }
      catch (err) { $("#uxErr").textContent = err.message; go.disabled = false; }
    });
  }

  /* USDT recovery on the pricing FAQ (closed the page before the code showed) */
  function recoverForm() {
    const host = $("#recForm"); if (!host || $("#uxRec")) return;
    host.insertAdjacentHTML("afterend", `<form id="uxRec" style="max-width:440px;margin-top:22px"><p><b>Paid with USDT?</b> Pick the network, paste the transaction hash and the email you used at checkout.</p>
      <label class="field"><span>Network</span><select id="uxRNet">${NETS.map(n => `<option value="${n[0]}">${n[1]} · ${n[2]}</option>`).join("")}</select></label>
      <label class="field"><span>Transaction hash</span><input id="uxRTx" required maxlength="80" spellcheck="false" autocomplete="off"></label>
      <label class="field"><span>Email used at checkout</span><input id="uxREm" type="email" required maxlength="120" autocomplete="email"></label>
      <p class="m-err" id="uxRErr"></p><button type="submit" class="btn primary" id="uxRGo">Recover access</button><div id="uxROut" style="margin-top:14px"></div></form>`);
    $("#uxRec").addEventListener("submit", async e => {
      e.preventDefault(); const go = $("#uxRGo"); go.disabled = true; $("#uxRErr").textContent = ""; $("#uxROut").innerHTML = "";
      try {
        const v = await post("usdt_recover", { net: $("#uxRNet").value, tx: $("#uxRTx").value.trim(), email: $("#uxREm").value.trim() });
        $("#uxROut").innerHTML = v.access_code ? `<div class="px-code"><span>Your access code</span><b class="mono">${esc(v.access_code)}</b><small>This browser is unlocked. Use the code on your other devices.</small></div><a class="btn primary" href="scanner.html">Open the scanner</a>` : `<p>Payment confirmed. We'll contact you by email.</p>`;
      } catch (err) { $("#uxRErr").textContent = err.message; }
      go.disabled = false;
    });
  }
  recoverForm();

  window.CPX = {
    start(sel, who, usd) {
      const title = $("#payTitle") ? $("#payTitle").textContent : "Checkout";
      chooser(sel, who, title, "$" + Number(usd).toLocaleString("en-US") + " (≈ " + usd + " USDT)");
    }
  };
})();
