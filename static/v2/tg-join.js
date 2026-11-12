/* ChartPulse: "Get CALL / PUT alerts on Telegram" (owner, 12 Nov 2026).
   A small button at the bottom-left of every scanner page. One tap opens @Chartpulseio_bot; the visitor presses
   Start and is subscribed. The panel shows the 3 easy steps for anyone new to Telegram. */
(function () {
  if (window.__cpTgJoin) return; window.__cpTgJoin = 1;
  var BOT = 'Chartpulseio_bot';
  var LINK = 'https://t.me/' + BOT + '?start=alerts';
  var css = '' +
    '.cptg-btn{position:fixed;left:16px;bottom:16px;z-index:9990;display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;border:0;cursor:pointer;' +
    'background:linear-gradient(135deg,#2AABEE,#229ED9);color:#fff;font:600 14px/1.2 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;box-shadow:0 6px 22px rgba(34,158,217,.45)}' +
    '.cptg-btn:hover{filter:brightness(1.08)}.cptg-btn svg{width:18px;height:18px;flex:none}' +
    '.cptg-pan{position:fixed;left:16px;bottom:70px;z-index:9991;width:300px;max-width:calc(100vw - 32px);background:#11161f;color:#e6edf3;border:1px solid #263142;' +
    'border-radius:14px;padding:16px;box-shadow:0 18px 50px rgba(0,0,0,.55);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;display:none}' +
    '.cptg-pan.on{display:block}.cptg-pan h4{margin:0 0 4px;font-size:16px;color:#fff}.cptg-pan p{margin:0 0 10px;color:#9fb0c3;font-size:13px}' +
    '.cptg-pan ol{margin:0 0 12px;padding-left:20px}.cptg-pan li{margin:3px 0}' +
    '.cptg-go{display:block;text-align:center;padding:11px;border-radius:10px;background:#229ED9;color:#fff!important;text-decoration:none;font-weight:700}' +
    '.cptg-go:hover{background:#2AABEE}.cptg-x{position:absolute;right:10px;top:8px;background:none;border:0;color:#7d8da1;font-size:20px;cursor:pointer;line-height:1}' +
    '.cptg-sm{display:block;margin-top:8px;text-align:center;font-size:12px;color:#7d8da1}.cptg-sm b{color:#cfe3f5}' +
    '@media (max-width:600px){.cptg-btn span{display:none}.cptg-btn{padding:11px}}';
  var plane = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.8 15.6l-.4 5.3c.6 0 .8-.3 1.1-.6l2.7-2.6 5.6 4.1c1 .6 1.8.3 2-.9L24 3.6c.4-1.6-.6-2.2-1.6-1.8L1.2 10c-1.5.6-1.4 1.4-.3 1.8l5.4 1.7L18.9 5.6c.6-.4 1.1-.2.7.2"/></svg>';
  function init() {
    var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
    var b = document.createElement('button');
    b.className = 'cptg-btn'; b.type = 'button'; b.setAttribute('aria-expanded', 'false');
    b.innerHTML = plane + '<span>Free alerts on Telegram</span>';
    b.title = 'Get CALL / PUT alerts on Telegram';
    var p = document.createElement('div');
    p.className = 'cptg-pan'; p.setAttribute('role', 'dialog'); p.setAttribute('aria-label', 'Telegram alerts');
    p.innerHTML = '<button class="cptg-x" type="button" aria-label="Close">&times;</button>' +
      '<h4>Get every CALL / PUT on Telegram</h4>' +
      '<p>Free. All scanners. Each alert says the scanner, the setup and why it fired.</p>' +
      '<ol><li>Tap the blue button below</li><li>Telegram opens our bot &mdash; press <b>Start</b></li><li>Done! Alerts arrive automatically</li></ol>' +
      '<a class="cptg-go" href="' + LINK + '" target="_blank" rel="noopener">Join @' + BOT + '</a>' +
      '<span class="cptg-sm">No Telegram yet? Get it free from <b>telegram.org</b>. Send /stop to the bot anytime to leave.</span>';
    function set(on) { p.classList.toggle('on', on); b.setAttribute('aria-expanded', on ? 'true' : 'false'); }
    b.addEventListener('click', function () { set(!p.classList.contains('on')); });
    p.querySelector('.cptg-x').addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
    document.body.appendChild(p); document.body.appendChild(b);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
