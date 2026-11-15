/* ============================================================================
   Header v7 hero - drawn live, no video (15 Nov 2026).
   The old 960x540 loop looked soft and cartoonish on large screens. This scene is
   rendered at the screen's own resolution: a deep star field in three depths, a
   faint galactic band, a slow scanner sweep, and the "brightest" stars - which
   are the top-ranked symbols on the live scanner right now, joined as a
   constellation. Pauses off screen; one still frame when motion is reduced.
   ========================================================================== */
(function () {
  var hero = document.querySelector('.cp-hero7'), c = document.getElementById('fx7');
  if (!hero || !c || !c.getContext) return;
  var x = c.getContext('2d'), W = 0, H = 0, D = 1, TAU = Math.PI * 2;
  var still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var run = true, raf = 0, layers = [], neb = null, tw = [], MS = [], links = [], shoot = null, nextShoot = 2600, born = 0;
  var m = { tx: 0, ty: 0, x: 0, y: 0, on: 0, a: 0 };
  var NAMES = ['BTC', 'NVDA', 'GOLD', 'EURUSD', 'ETH', 'AAPL', 'NIFTY', 'TSLA', 'SOL', 'CRUDE', 'MSFT', 'GBPUSD', 'XRP', 'SPX'];
  function rng(seed) { var a = seed >>> 0; return function () { a = (a + 0x6D2B79F5) | 0; var t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }
  function mk(w, h) { var o = document.createElement('canvas'); o.width = Math.max(1, Math.round(w * D)); o.height = Math.max(1, Math.round(h * D)); var g = o.getContext('2d'); g.setTransform(D, 0, 0, D, 0, 0); return [o, g]; }
  var TINT = ['205,222,255', '225,235,255', '255,248,236', '255,232,200', '190,210,255'];

  function build() {
    D = Math.min(window.devicePixelRatio || 1, 2); W = hero.clientWidth; H = hero.clientHeight; if (!W || !H) return;
    c.width = Math.round(W * D); c.height = Math.round(H * D); x.setTransform(D, 0, 0, D, 0, 0);
    var R = rng(7341), small = W < 760, area = W * H, i, o, g, P = 40;
    // galactic band: many faint glows along a diagonal, drawn once
    o = mk(W, H); neb = o[0]; g = o[1]; g.globalCompositeOperation = 'lighter';
    var ax = W * (small ? .1 : .34), ay = H * 1.05, bx = W * 1.04, by = H * -.05, n = small ? 34 : 64;
    for (i = 0; i < n; i++) {
      var k = R(), px = ax + (bx - ax) * k + (R() - .5) * W * .16, py = ay + (by - ay) * k + (R() - .5) * H * .30, r = (small ? 70 : 110) + R() * (small ? 130 : 230);
      var hue = R(), col = hue < .5 ? '96,116,220' : hue < .8 ? '140,104,214' : hue < .93 ? '70,170,200' : '244,190,110', gr = g.createRadialGradient(px, py, 0, px, py, r);
      gr.addColorStop(0, 'rgba(' + col + ',' + (.030 + R() * .035) + ')'); gr.addColorStop(1, 'rgba(' + col + ',0)');
      g.fillStyle = gr; g.fillRect(px - r, py - r, r * 2, r * 2);
    }
    for (i = 0; i < (small ? 260 : 900); i++) {              // star dust inside the band
      k = R(); px = ax + (bx - ax) * k + (R() + R() + R() - 1.5) * W * .14; py = ay + (by - ay) * k + (R() + R() + R() - 1.5) * H * .26;
      g.fillStyle = 'rgba(' + TINT[(R() * 5) | 0] + ',' + (.10 + R() * .34) + ')'; g.beginPath(); g.arc(px, py, .3 + R() * .35, 0, TAU); g.fill();
    }
    // three depths of stars, drawn once and moved as whole sheets
    layers = [];
    [[area / 700, .5, 1.1, .7, 4], [area / 2200, .8, 1.5, .9, 9], [area / 8000, 1.1, 2.1, 1, 16]].forEach(function (L, li) {
      o = mk(W + P * 2, H + P * 2); g = o[1]; var cnt = Math.min(li ? 900 : 2400, Math.round(L[0] * (small ? .7 : 1)));
      for (i = 0; i < cnt; i++) {
        var sx = R() * (W + P * 2), sy = R() * (H + P * 2), sr = L[1] + R() * (L[2] - L[1]), al = (.25 + R() * .75) * L[3];
        g.fillStyle = 'rgba(' + TINT[(R() * 5) | 0] + ',' + al.toFixed(3) + ')'; g.beginPath(); g.arc(sx, sy, sr * .5, 0, TAU); g.fill();
        if (li === 2 && R() < .5) { var gg = g.createRadialGradient(sx, sy, 0, sx, sy, sr * 4); gg.addColorStop(0, 'rgba(210,225,255,' + (al * .35).toFixed(3) + ')'); gg.addColorStop(1, 'rgba(210,225,255,0)'); g.fillStyle = gg; g.fillRect(sx - sr * 4, sy - sr * 4, sr * 8, sr * 8); }
      }
      layers.push({ cv: o[0], par: L[4], P: P });
    });
    tw = []; for (i = 0; i < (small ? 26 : 70); i++) tw.push({ x: R() * W, y: R() * H, r: .6 + R() * .9, p: R() * TAU, s: .0006 + R() * .0018, c: TINT[(R() * 5) | 0] });
    place(); born = performance.now();
  }
  // the brightest stars: placed on the open side of the hero, never under the headline
  function place() {
    var R = rng(9917), small = W < 760, cnt = small ? 7 : Math.min(14, NAMES.length), tries, i, j, ok, px, py, minD = small ? 78 : 118;
    var x0 = small ? .08 : .46, x1 = .95, y0 = small ? .06 : .10, y1 = small ? .92 : .88, old = MS; MS = [];
    for (i = 0; i < cnt; i++) {
      tries = 0;
      do { px = W * (x0 + R() * (x1 - x0)); py = H * (y0 + R() * (y1 - y0)); ok = true;
        for (j = 0; j < MS.length; j++) if (Math.hypot(MS[j].x - px, MS[j].y - py) < minD) { ok = false; break; }
      } while (!ok && ++tries < 60);
      var rank = i / Math.max(1, cnt - 1);
      MS.push({ x: px, y: py, name: (old[i] && old[i].name) || NAMES[i], b: (1 - rank * .62) * (small ? .6 : 1), ph: R() * TAU, warm: i % 3 !== 1 });
    }
    links = [];
    for (i = 0; i < MS.length; i++) { var best = -1, bd = 1e9; for (j = 0; j < MS.length; j++) { if (j === i) continue; var d = Math.hypot(MS[j].x - MS[i].x, MS[j].y - MS[i].y); if (d < bd && !links.some(function (l) { return l[0] === j && l[1] === i; })) { bd = d; best = j; } } if (best >= 0 && bd < Math.max(W, H) * .32) links.push([i, best]); }
  }
  function star(px, py, b, warm, boost) {
    var col = warm ? '255,236,196' : '214,230,255', r = (7 + 13 * b) * (1 + boost * .5), a = Math.min(1, .55 + .45 * b + boost * .3), g = x.createRadialGradient(px, py, 0, px, py, r * 2.6);
    g.addColorStop(0, 'rgba(' + col + ',' + (.55 * a).toFixed(3) + ')'); g.addColorStop(.18, 'rgba(' + col + ',' + (.16 * a).toFixed(3) + ')'); g.addColorStop(1, 'rgba(' + col + ',0)');
    x.fillStyle = g; x.beginPath(); x.arc(px, py, r * 2.6, 0, TAU); x.fill();
    var L = r * (3.2 + boost * 1.4), sp = x.createLinearGradient(px - L, py, px + L, py);
    sp.addColorStop(0, 'rgba(' + col + ',0)'); sp.addColorStop(.5, 'rgba(' + col + ',' + (.9 * a).toFixed(3) + ')'); sp.addColorStop(1, 'rgba(' + col + ',0)');
    x.strokeStyle = sp; x.lineWidth = 1; x.beginPath(); x.moveTo(px - L, py); x.lineTo(px + L, py); x.stroke();
    sp = x.createLinearGradient(px, py - L, px, py + L);
    sp.addColorStop(0, 'rgba(' + col + ',0)'); sp.addColorStop(.5, 'rgba(' + col + ',' + (.9 * a).toFixed(3) + ')'); sp.addColorStop(1, 'rgba(' + col + ',0)');
    x.strokeStyle = sp; x.beginPath(); x.moveTo(px, py - L); x.lineTo(px, py + L); x.stroke();
    x.fillStyle = 'rgba(255,255,255,' + a.toFixed(3) + ')'; x.beginPath(); x.arc(px, py, 1.1 + 1.3 * b, 0, TAU); x.fill();
  }
  function frame(t) {
    raf = 0; if (!run || !W) return;
    var age = Math.min(1, (t - born) / 2200), i, s, a;
    m.x += (m.tx - m.x) * .06; m.y += (m.ty - m.y) * .06; m.a += (m.on - m.a) * .05;
    var ox = (.5 - m.x / W) * m.a, oy = (.5 - m.y / H) * m.a, dr = still ? 0 : t * .00004;
    x.setTransform(D, 0, 0, D, 0, 0); x.clearRect(0, 0, W, H); x.globalCompositeOperation = 'lighter';
    if (neb) { x.globalAlpha = age; x.drawImage(neb, ox * 6, oy * 4, W, H); x.globalAlpha = 1; }
    for (i = 0; i < layers.length; i++) { s = layers[i]; x.globalAlpha = age;
      x.drawImage(s.cv, -s.P + ox * s.par + Math.sin(dr * (i + 1)) * (4 + i * 4), -s.P + oy * s.par * .7 + Math.cos(dr * (i + 1) * .8) * (3 + i * 3), W + s.P * 2, H + s.P * 2); }
    x.globalAlpha = 1;
    for (i = 0; i < tw.length; i++) { s = tw[i]; a = .5 + .5 * Math.sin(t * s.s + s.p);
      x.fillStyle = 'rgba(' + s.c + ',' + (.15 + .75 * a * age).toFixed(3) + ')'; x.beginPath(); x.arc(s.x + ox * 12, s.y + oy * 9, s.r * (.7 + .5 * a), 0, TAU); x.fill(); }
    // scanner sweep: a soft beam turning slowly; the brightest stars flare as it passes
    var cx = W * (W < 760 ? .5 : .72), cy = H * (W < 760 ? .26 : .48), ang = (t * .00016) % TAU, far = Math.hypot(W, H);
    if (!still && x.createConicGradient) { var cg = x.createConicGradient(ang - .5, cx, cy);
      cg.addColorStop(0, 'rgba(120,170,255,0)'); cg.addColorStop(.05, 'rgba(140,180,255,.03)'); cg.addColorStop(.078, 'rgba(190,210,255,.085)'); cg.addColorStop(.08, 'rgba(255,236,196,.10)'); cg.addColorStop(.086, 'rgba(160,190,255,.03)'); cg.addColorStop(.10, 'rgba(120,170,255,0)'); cg.addColorStop(1, 'rgba(120,170,255,0)');
      x.fillStyle = cg; x.globalAlpha = age; x.beginPath(); x.arc(cx, cy, far, 0, TAU); x.fill(); x.globalAlpha = 1; }
    // constellation of the brightest
    var px = ox * 22, py = oy * 16;
    x.lineWidth = 1;
    for (i = 0; i < links.length; i++) { var A = MS[links[i][0]], B = MS[links[i][1]], k = Math.max(0, Math.min(1, (t - born - 900 - i * 160) / 900)); if (!k) continue;
      var lg = x.createLinearGradient(A.x, A.y, B.x, B.y); lg.addColorStop(0, 'rgba(150,185,255,.05)'); lg.addColorStop(.5, 'rgba(170,200,255,.20)'); lg.addColorStop(1, 'rgba(150,185,255,.05)');
      x.strokeStyle = lg; x.beginPath(); x.moveTo(A.x + px, A.y + py); x.lineTo(A.x + px + (B.x - A.x) * k, A.y + py + (B.y - A.y) * k); x.stroke(); }
    x.font = '500 11px ui-monospace,"IBM Plex Mono",Menlo,monospace'; x.textBaseline = 'middle';
    for (i = 0; i < MS.length; i++) { s = MS[i]; var k2 = Math.max(0, Math.min(1, (t - born - 300 - i * 110) / 700)); if (!k2) continue;
      var da = Math.atan2(s.y - cy, s.x - cx) - (ang - .5 + .08 * TAU); da = Math.atan2(Math.sin(da), Math.cos(da));
      var boost = still ? 0 : Math.exp(-da * da * 30), pulse = still ? 1 : .88 + .12 * Math.sin(t * .0012 + s.ph);
      star(s.x + px, s.y + py, s.b * pulse * k2, s.warm, boost);
      if (W < 760) continue;
      x.globalCompositeOperation = 'source-over';
      var tw2 = x.measureText(s.name).width + 14, lx = s.x + px - tw2 / 2, ly = s.y + py - (20 + 14 * s.b) - 9, la = (.55 + .45 * s.b + boost * .3) * k2;
      x.fillStyle = 'rgba(8,12,22,' + (.72 * k2).toFixed(3) + ')'; x.strokeStyle = 'rgba(150,185,255,' + (.34 * la).toFixed(3) + ')';
      x.beginPath(); if (x.roundRect) x.roundRect(lx, ly, tw2, 18, 5); else x.rect(lx, ly, tw2, 18); x.fill(); x.stroke();
      x.fillStyle = 'rgba(214,228,255,' + Math.min(1, la).toFixed(3) + ')'; x.fillText(s.name, lx + 7, ly + 9.5);
      x.globalCompositeOperation = 'lighter'; }
    // a shooting star now and then
    if (!still) {
      if (!shoot && t - born > nextShoot) { var an = Math.PI * (.12 + Math.random() * .1); shoot = { x: W * (.45 + Math.random() * .5), y: H * Math.random() * .3, vx: -Math.cos(an) * 1.05, vy: Math.sin(an) * 1.05, t: t }; }
      if (shoot) { var q = t - shoot.t; if (q > 900) { shoot = null; nextShoot = t - born + 4200 + Math.random() * 6000; }
        else { var hx = shoot.x + shoot.vx * q, hy = shoot.y + shoot.vy * q, al = Math.sin(q / 900 * Math.PI), tg = x.createLinearGradient(hx - shoot.vx * 190, hy - shoot.vy * 190, hx, hy);
          tg.addColorStop(0, 'rgba(255,255,255,0)'); tg.addColorStop(1, 'rgba(255,246,222,' + al.toFixed(3) + ')'); x.strokeStyle = tg; x.lineWidth = 1.5; x.beginPath(); x.moveTo(hx - shoot.vx * 190, hy - shoot.vy * 190); x.lineTo(hx, hy); x.stroke(); } }
      // the pulse line travelling along the base
      var yb = H - 3, bx2 = ((t % 8000) / 8000) * (W + 400) - 200, fa = Math.max(0, Math.min(1, Math.min(bx2 + 80, W + 80 - bx2) / 200)), hg = x.createLinearGradient(0, 0, W, 0);
      hg.addColorStop(0, 'rgba(244,183,64,0)'); hg.addColorStop(.5, 'rgba(244,183,64,.32)'); hg.addColorStop(1, 'rgba(244,183,64,0)'); x.strokeStyle = hg; x.lineWidth = 1; x.beginPath(); x.moveTo(0, yb); x.lineTo(W, yb); x.stroke();
      var PL = [[-60, 0], [-44, 0], [-34, -4], [-24, 0], [-12, 6], [0, -30], [10, 12], [22, 0], [44, -7], [64, 0]];
      x.save(); x.globalAlpha = fa; x.shadowColor = '#f5c451'; x.shadowBlur = 12; x.strokeStyle = 'rgba(255,226,150,.92)'; x.lineWidth = 1.8; x.beginPath();
      PL.forEach(function (p, j) { j ? x.lineTo(bx2 + p[0], yb + p[1]) : x.moveTo(bx2 + p[0], yb + p[1]); }); x.stroke(); x.restore();
    }
    if (!still || age < 1) raf = requestAnimationFrame(frame);
  }
  function kick() { if (!raf && run) raf = requestAnimationFrame(frame); }
  hero.addEventListener('pointermove', function (e) { if (e.pointerType !== 'mouse') return; var r = hero.getBoundingClientRect(); m.tx = e.clientX - r.left; m.ty = e.clientY - r.top; m.on = 1; });
  hero.addEventListener('pointerleave', function () { m.on = 0; });
  var rs = 0; function resize() { clearTimeout(rs); rs = setTimeout(function () { build(); kick(); }, 120); }
  if (window.ResizeObserver) new ResizeObserver(resize).observe(hero); else addEventListener('resize', resize);
  build();
  if ('IntersectionObserver' in window) new IntersectionObserver(function (e) { run = e[0].isIntersecting; kick(); }).observe(hero);
  kick();
  // name the brightest stars after the top-ranked symbols on the live scanner
  function short(s) { return String(s || '').replace(/^\^/, '').replace(/(-USD|=X|=F|\.NS|\.BO|\.L|\.DE|\.PA|\.HK|\.T|\.TO|\.AX|\.SA|\.KS|\.TW|\.SI|\.MI|\.AS|\.SW|\.ST|\.MC)$/i, '').replace(/^MCX:/, '').slice(0, 9); }
  try {
    fetch('/api.php?r=scan').then(function (r) { return r.json(); }).then(function (d) {
      var seen = {}, top = (d.rows || []).filter(function (r) { return r.symbol && r.strength != null; }).sort(function (a, b) { return (b.strength || 0) - (a.strength || 0); })
        .map(function (r) { return short(r.symbol); }).filter(function (n) { if (n.length < 2 || seen[n]) return false; seen[n] = 1; return true; }).slice(0, 14);
      if (top.length >= 6) { NAMES = top.concat(NAMES).slice(0, 14); for (var i = 0; i < MS.length; i++) MS[i].name = NAMES[i]; if (still) { born = performance.now() - 5000; kick(); } }
    }).catch(function () {});
  } catch (e) {}
})();
