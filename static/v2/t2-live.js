/* ChartPulse Tier 2: make the live view look live (4 Nov 2026).
 * - price flashes green/red when it moves between refreshes
 * - "updated Xs ago" counts up every second, so the page never looks frozen
 * Only reads the DOM that tier2.js already writes; no data or engine changes. */
(() => {
  "use strict";
  const price = document.getElementById("t2Price");
  const upd = document.getElementById("t2Upd");
  if (!price || !upd) return;

  const st = document.createElement("style");
  st.textContent = [
    "@keyframes t2Up{0%{background:rgba(34,171,148,.35)}100%{background:transparent}}",
    "@keyframes t2Dn{0%{background:rgba(247,82,95,.35)}100%{background:transparent}}",
    "#t2Price.tick-up{animation:t2Up 1.2s ease-out;border-radius:6px}",
    "#t2Price.tick-dn{animation:t2Dn 1.2s ease-out;border-radius:6px}",
    "#t2Ago{margin-left:8px;color:#A1A1AA;font-variant-numeric:tabular-nums}",
    "@media (prefers-reduced-motion:reduce){#t2Price.tick-up,#t2Price.tick-dn{animation:none}}"
  ].join("");
  document.head.append(st);

  const ago = document.createElement("span");
  ago.id = "t2Ago";
  upd.append(ago);

  let last = NaN;
  let lastAt = 0;

  const num = (s) => parseFloat(String(s).replace(/[^0-9.\-]/g, ""));

  // tier2.js rewrites #t2Price on every refresh; compare with the previous value and flash.
  new MutationObserver(() => {
    const v = num(price.textContent);
    if (!Number.isFinite(v)) return;
    if (Number.isFinite(last) && v !== last) {
      price.classList.remove("tick-up", "tick-dn");
      void price.offsetWidth;   // restart the animation
      price.classList.add(v > last ? "tick-up" : "tick-dn");
    }
    last = v;
    lastAt = Date.now();
  }).observe(price, { childList: true, characterData: true, subtree: true });

  // tier2.js rewrites #t2Upd too; keep our counter after its text on each rewrite.
  new MutationObserver(() => {
    if (!ago.isConnected) upd.append(ago);
  }).observe(upd, { childList: true });

  setInterval(() => {
    if (!lastAt) { ago.textContent = ""; return; }
    const s = Math.max(0, Math.round((Date.now() - lastAt) / 1000));
    ago.textContent = s < 60 ? "· updated " + s + "s ago" : "· updated " + Math.floor(s / 60) + "m ago";
  }, 1000);
})();
