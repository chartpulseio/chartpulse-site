/* ChartPulse: Connect Telegram button for the Tier 2 page (4 Nov 2026).
 * Opens the bot deep link, then polls until the bot has linked this account. */
(() => {
  "use strict";
  const btn = document.getElementById("t2LinkBtn");
  const state = document.getElementById("t2LinkState");
  const chatInput = document.getElementById("t2Chat");
  if (!btn || !state || !chatInput) return;

  let timer = 0;

  function show(linked, chat) {
    chatInput.value = linked ? chat : "";
    btn.hidden = linked;
    state.textContent = linked ? "Connected ✓ FLY alerts on" : "Not connected";
    state.className = "t2-link-state " + (linked ? "ok" : "");
  }

  async function check() {
    try {
      const r = await fetch("/api.php?r=tg_link_status", { cache: "no-store", credentials: "same-origin" });
      const j = await r.json();
      if (j && j.ok) show(!!j.linked, j.chat || "");
      return !!(j && j.linked);
    } catch (e) {
      return false;
    }
  }

  function waitForLink() {
    clearInterval(timer);
    let tries = 0;
    timer = setInterval(async () => {
      tries++;
      const linked = await check();
      if (linked || tries >= 100) clearInterval(timer);   // stop after about 5 minutes
    }, 3000);
  }

  btn.addEventListener("click", async () => {
    btn.disabled = true;
    state.textContent = "Opening Telegram…";
    try {
      const r = await fetch("/api.php?r=tg_link", {
        method: "POST",
        cache: "no-store",
        credentials: "same-origin",
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j.ok) throw new Error(j.error || "Could not start the connection.");
      window.open(j.url, "_blank", "noopener");
      state.textContent = "Press Start in the bot, then come back here…";
      waitForLink();
    } catch (e) {
      state.textContent = e.message;
      state.className = "t2-link-state err";
    } finally {
      btn.disabled = false;
    }
  });

  check();
})();
