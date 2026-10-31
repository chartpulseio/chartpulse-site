/* ============================================================================
   Header v7 - night sky canvas.
   Rebuilt 26 Oct 2026 (the original file was missing from the server).

   The approved concept: a genuinely dark night sky; the moon on one side
   giving its own light with "ChartPulse" written inside it; many stars, of
   which only a few twinkle, and those carry a famous market symbol. No
   sun-style rays, no mountains.

   Engineering guards, all of which only ever reduce work:
     - devicePixelRatio capped at 2
     - paused while the tab is hidden
     - prefers-reduced-motion  -> one static frame, no loop
     - Save-Data on            -> one static frame, no loop
     - deviceMemory under 4 GB -> one static frame, no loop
     - under 30 fps sustained  -> stops and leaves the static frame
   The canvas is decorative and aria-hidden in the markup.
   ========================================================================== */
(function () {
  'use strict';

  var cv = document.getElementById('fx7');
  if (!cv || !cv.getContext) return;

  var ctx = cv.getContext('2d', { alpha: true });
  if (!ctx) return;

  // ---- capability gates ---------------------------------------------------
  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  var conn = navigator.connection || {};
  var lowEnd = (navigator.deviceMemory && navigator.deviceMemory < 4) || conn.saveData === true;

  function wantsStill() {
    return lowEnd || (mq && mq.matches);
  }

  // ---- model --------------------------------------------------------------
  var SYMBOLS = ['NIFTY', 'BTC', 'S&P 500', 'GOLD', 'EUR/USD', 'NASDAQ', 'DAX'];
  var stars = [];
  var twinklers = [];
  var moon = { x: 0, y: 0, r: 0 };
  var W = 0, H = 0, dpr = 1;

  function rand(a, b) { return a + Math.random() * (b - a); }

  function build() {
    var rect = cv.getBoundingClientRect();
    W = Math.max(1, Math.round(rect.width));
    H = Math.max(1, Math.round(rect.height));
    dpr = Math.min(window.devicePixelRatio || 1, 2);   // capped at 2

    cv.width = Math.round(W * dpr);
    cv.height = Math.round(H * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    // The moon sits on the right, clear of the copy, and shrinks on narrow screens.
    moon.r = Math.max(42, Math.min(W, H) * (W < 760 ? 0.13 : 0.11));
    moon.x = W < 760 ? W * 0.78 : W * 0.80;
    moon.y = W < 760 ? H * 0.20 : H * 0.30;

    // Density scales with area so a phone does not draw a desktop's worth.
    var count = Math.round(Math.min(190, Math.max(50, (W * H) / 9000)));
    stars = [];
    for (var i = 0; i < count; i++) {
      stars.push({ x: rand(0, W), y: rand(0, H), r: rand(0.4, 1.5), a: rand(0.18, 0.78) });
    }

    // Only a few twinkle, and those are the ones carrying a symbol.
    var n = W < 560 ? 3 : (W < 900 ? 5 : SYMBOLS.length);
    twinklers = [];
    for (var j = 0; j < n; j++) {
      twinklers.push({
        x: rand(W * 0.06, W * (W < 760 ? 0.92 : 0.70)),
        y: rand(H * 0.12, H * 0.86),
        r: rand(1.5, 2.3),
        label: SYMBOLS[j % SYMBOLS.length],
        phase: Math.random() * Math.PI * 2,
        speed: rand(0.6, 1.1)
      });
    }
  }

  // ---- paint --------------------------------------------------------------
  function drawMoon() {
    var r = moon.r;

    // Its own light, falling off smoothly. No rays.
    var glow = ctx.createRadialGradient(moon.x, moon.y, r * 0.7, moon.x, moon.y, r * 3.6);
    glow.addColorStop(0, 'rgba(206,218,255,0.17)');
    glow.addColorStop(0.45, 'rgba(176,192,240,0.06)');
    glow.addColorStop(1, 'rgba(176,192,240,0)');
    ctx.fillStyle = glow;
    ctx.beginPath();
    ctx.arc(moon.x, moon.y, r * 3.6, 0, Math.PI * 2);
    ctx.fill();

    // The disc.
    var face = ctx.createRadialGradient(
      moon.x - r * 0.32, moon.y - r * 0.34, r * 0.1,
      moon.x, moon.y, r
    );
    face.addColorStop(0, '#F4F7FF');
    face.addColorStop(0.58, '#DCE3F5');
    face.addColorStop(1, '#AFBBD8');
    ctx.fillStyle = face;
    ctx.beginPath();
    ctx.arc(moon.x, moon.y, r, 0, Math.PI * 2);
    ctx.fill();

    // A few craters so it is a moon, not a circle.
    ctx.fillStyle = 'rgba(126,140,172,0.20)';
    var craters = [[-0.34, -0.12, 0.15], [0.26, 0.30, 0.11], [0.06, -0.44, 0.08], [-0.12, 0.42, 0.07]];
    for (var i = 0; i < craters.length; i++) {
      ctx.beginPath();
      ctx.arc(moon.x + craters[i][0] * r, moon.y + craters[i][1] * r, craters[i][2] * r, 0, Math.PI * 2);
      ctx.fill();
    }

    // "ChartPulse" written inside the moon.
    var fs = Math.max(9, r * 0.235);
    ctx.save();
    ctx.font = '600 ' + fs.toFixed(1) + 'px "IBM Plex Sans", system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = 'rgba(42,52,78,0.80)';
    ctx.fillText('ChartPulse', moon.x, moon.y + r * 0.04);
    ctx.restore();
  }

  function drawStars() {
    for (var i = 0; i < stars.length; i++) {
      var s = stars[i];
      ctx.globalAlpha = s.a;
      ctx.fillStyle = '#DCE6FF';
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.globalAlpha = 1;
  }

  function drawTwinklers(t) {
    for (var i = 0; i < twinklers.length; i++) {
      var k = twinklers[i];
      // t is 0 when still, so this collapses to a steady star.
      var pulse = t ? (0.55 + 0.45 * Math.sin(k.phase + t * k.speed)) : 0.9;

      ctx.globalAlpha = pulse;
      ctx.fillStyle = '#FFF4DC';
      ctx.beginPath();
      ctx.arc(k.x, k.y, k.r, 0, Math.PI * 2);
      ctx.fill();

      ctx.globalAlpha = pulse * 0.30;
      ctx.fillStyle = '#F4B740';
      ctx.beginPath();
      ctx.arc(k.x, k.y, k.r * 3.1, 0, Math.PI * 2);
      ctx.fill();

      // the symbol it carries
      ctx.globalAlpha = Math.min(0.72, pulse * 0.8);
      ctx.fillStyle = '#C9D4EE';
      ctx.font = '500 10px "IBM Plex Mono", ui-monospace, Menlo, monospace';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      ctx.fillText(k.label, k.x + k.r * 4.2, k.y);
    }
    ctx.globalAlpha = 1;
  }

  function frame(t) {
    ctx.clearRect(0, 0, W, H);
    drawStars();
    drawMoon();
    drawTwinklers(t);
  }

  // ---- loop, with the fps guard -------------------------------------------
  var raf = 0, running = false, start = 0;
  var samples = 0, slow = 0, last = 0;

  function tick(now) {
    if (!running) return;
    if (!start) start = now;
    if (last) {
      var dt = now - last;
      // Sustained slowness, not one hitch: 30 slow frames in 120 and it stops.
      samples++;
      if (dt > 33.4) slow++;
      if (samples >= 120) {
        if (slow > 30) { stop(); frame(0); return; }
        samples = 0; slow = 0;
      }
    }
    last = now;
    frame((now - start) / 1000);
    raf = requestAnimationFrame(tick);
  }

  function play() {
    if (running || wantsStill()) return;
    running = true;
    last = 0;
    raf = requestAnimationFrame(tick);
  }

  function stop() {
    running = false;
    if (raf) cancelAnimationFrame(raf);
    raf = 0;
  }

  // ---- wiring -------------------------------------------------------------
  var resizeTimer = 0;
  function onResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      build();
      if (wantsStill()) { stop(); frame(0); } else { frame(0); }
    }, 150);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop();
    else if (!wantsStill()) play();
  });

  if (mq) {
    var onMq = function () {
      build();
      if (wantsStill()) { stop(); frame(0); } else { play(); }
    };
    if (mq.addEventListener) mq.addEventListener('change', onMq);
    else if (mq.addListener) mq.addListener(onMq);
  }

  window.addEventListener('resize', onResize, { passive: true });

  function boot() {
    build();
    frame(0);                       // a complete still frame first
    if (!wantsStill() && !document.hidden) play();
  }

  // Start after first paint so the hero text is never held up.
  if ('requestIdleCallback' in window) {
    requestIdleCallback(boot, { timeout: 1200 });
  } else {
    requestAnimationFrame(function () { setTimeout(boot, 0); });
  }
})();
