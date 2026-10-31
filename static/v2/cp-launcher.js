/* ChartPulse Assistant launcher (tiny).
   The only chat code that runs on page load. The panel CSS/JS is fetched
   on first click. Hidden on checkout and admin pages. */
(function () {
  'use strict';
  var p = location.pathname.toLowerCase();
  if (p.indexOf('/admin') === 0 || p.indexOf('pay.php') > -1 || p.indexOf('checkout') > -1) return;
  if (window.__cpChatLauncher) return;
  window.__cpChatLauncher = true;

  var V = '1';
  var btn = document.createElement('button');
  btn.id = 'cp-launch';
  btn.type = 'button';
  btn.setAttribute('aria-label', 'Ask ChartPulse');
  btn.innerHTML =
    '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
    '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.3 9.3 0 0 1-2.8-.4L3 21l1.6-4.6A8.3 8.3 0 0 1 3.6 11.5a8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 8.4 8.4z" ' +
    'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
    '<span>Ask ChartPulse</span>';
  btn.style.cssText =
    'position:fixed;right:18px;bottom:18px;z-index:9998;display:inline-flex;align-items:center;gap:8px;' +
    'padding:11px 16px;border-radius:999px;border:1px solid #1B2534;background:#0C121B;color:#DDE6F2;' +
    'font:500 13px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;' +
    'cursor:pointer;box-shadow:0 6px 22px rgba(0,0,0,.45);transition:border-color .15s';
  btn.addEventListener('mouseenter', function () { btn.style.borderColor = '#2DD4AA'; });
  btn.addEventListener('mouseleave', function () { btn.style.borderColor = '#1B2534'; });

  var loading = false;
  btn.addEventListener('click', function () {
    if (window.CPChat) { window.CPChat.open(); return; }
    if (loading) return;
    loading = true;
    btn.style.opacity = '.6';

    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = '/static/v2/cp-chat.css?v=' + V;
    document.head.appendChild(css);

    var js = document.createElement('script');
    js.src = '/static/v2/cp-chat.js?v=' + V;
    js.onload = function () {
      btn.style.opacity = '1';
      loading = false;
      if (window.CPChat) window.CPChat.open();
    };
    js.onerror = function () {
      btn.style.opacity = '1';
      loading = false;
    };
    document.head.appendChild(js);
  });

  function mount() { document.body.appendChild(btn); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
