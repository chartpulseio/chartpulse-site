/* =====================================================================
   TORUS Tier-1 Scanner - front-end (ChartPulse)
   Talks to the PHP API. Never contains strategy logic. Times are shown in the viewer's zone.
   ===================================================================== */
(() => {
'use strict';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
const CSS = getComputedStyle(document.documentElement);
const C = k => CSS.getPropertyValue(k).trim();
const COL = { call: C('--call'), put: C('--put'), wait: C('--wait'), accent: C('--accent'), ai: C('--ai'),
  ink: C('--ink'), ink2: C('--ink2'), muted: C('--muted'), line: C('--line'), line2: C('--line2'), bg2: C('--bg2') };
const SIGCOL = { CALL: COL.call, PUT: COL.put, WAIT: COL.wait };

function fmtP(p) {
  if (p == null || isNaN(p)) return '–';
  const a = Math.abs(p);
  if (a >= 1000) return p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  if (a >= 1) return p.toFixed(2);
  if (a >= 0.01) return p.toFixed(4);
  return p.toPrecision(4);
}
const fmtPct = (v, d = 2) => v == null || isNaN(v) ? '–' : (v > 0 ? '+' : '') + v.toFixed(d) + '%';
const cls = v => v > 0 ? 'up' : v < 0 ? 'down' : '';

async function api(path, opts) {
  if (window.TORUS_DEMO) return window.TORUS_DEMO.handle(path, opts && opts.body ? JSON.parse(opts.body) : null);
  if (window.TORUS_PHP) {
    const [p, q] = path.split('?'), parts = p.replace(/^\/api\//, '').split('/');
    path = 'api.php?r=' + parts[0] + (parts[1] ? '&sym=' + parts.slice(1).join('/') : '') + (q ? '&' + q : '');
  }
  const r = await fetch(path, opts);
  if (!r.ok) throw new Error(path + ' → ' + r.status);
  return r.json();
}

const S = { rows: [], meta: {}, brief: {}, bySym: {}, sel: null, tf: '1D', sig: 'ALL', lastBrief: '', scrSort: { k: 'strength', dir: -1 } };

/* ---------------- tooltip ---------------- */
const tip = $('#tip');
function showTip(html, e) {
  tip.innerHTML = html; tip.hidden = false;
  const x = Math.min(e.clientX + 14, innerWidth - tip.offsetWidth - 8);
  const y = Math.min(e.clientY + 14, innerHeight - tip.offsetHeight - 8);
  tip.style.left = x + 'px'; tip.style.top = y + 'px';
}
const hideTip = () => { tip.hidden = true; };

/* ---------------- router ---------------- */
const VIEWS = ['command', 'heatmap', 'analysis', 'lab', 'screener'];
function route() {
  let [v, arg] = (location.hash || '#command').slice(1).split('=');
  if (!VIEWS.includes(v)) v = 'command';
  if (v === 'analysis' && arg) { const sym = decodeURIComponent(arg); if (sym !== S.sel) { S.sel = sym; loadAnalysis(); } }
  $$('.view').forEach(el => el.classList.toggle('active', el.id === 'view-' + v));
  $$('.rail-links a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
  if (v === 'analysis' && !S.sel && S.rows.length) openSymbol(topRows(1)[0].symbol, false);
  if (v === 'analysis' && anChart) requestAnimationFrame(() => anChart.fit());
  if (v === 'lab' && labChart) requestAnimationFrame(() => labChart.fit());
  if (v === 'lab' && S.labRan && $('#labSym').value && $('#labSym').value !== S.labSym) runLab();
  window.scrollTo({ top: 0 });
}
addEventListener('hashchange', route);
document.addEventListener('visibilitychange', () => { if (!document.hidden) loadScan(); });
setInterval(() => { if (S.meta && S.meta.exchanges) CP.worldStrip($('#world'), S.meta.exchanges, S.fetchedAt); }, 30000);
function go(v) { if (location.hash !== '#' + v) location.hash = v; else route(); }

/* ---------------- clock (viewer's own time zone) ---------------- */
function tickClock() {
  const el = $('#railClock'); if (!el) return;
  el.innerHTML = `${CP.fmtTime(Date.now() / 1000)}<br><span style="color:var(--muted)">${esc(CP.tzName())}</span>`;
}
tickClock(); setInterval(tickClock, 15000);

/* ---------------- scan loading ---------------- */
let pollT;
async function loadScan() {
  clearTimeout(pollT);
  try {
    const d = await api('/api/scan');
    d.fetchedAt = Date.now() / 1000; S.fetchedAt = d.fetchedAt; CP.syncClock(d.meta, d.fetchedAt);
    S.meta = d.meta; S.brief = d.brief;
    if (d.rows && d.rows.length) {
      S.rows = d.rows; S.bySym = Object.fromEntries(d.rows.map(r => [r.symbol, r]));
      renderAll();
    }
    renderMeta();
    const next = d.meta.progress < 1 || !d.rows.length ? 3 : (d.meta.next_poll || 20);
    pollT = setTimeout(loadScan, (document.hidden ? Math.max(next, 60) : next) * 1000);
  } catch (e) {
    $('#scanInfo').textContent = 'Server not reachable. Retrying…';
    pollT = setTimeout(loadScan, 5000);
  }
}
function renderMeta() {
  const m = S.meta, pill = $('#modePill');
  pill.className = 'mode-pill ' + (m.mode === 'live' ? 'live' : m.mode === 'demo' ? 'demo' : '');
  pill.textContent = m.mode === 'live' ? '● LIVE DATA' : m.mode === 'demo' ? 'DEMO DATA' : 'FIRST SCAN';
  pill.title = m.mode === 'demo' ? 'Synthetic prices for preview. Not real market data.' : 'Yahoo Finance data';
  const p = $('#scanProgress');
  p.style.width = (m.progress * 100) + '%';
  p.style.opacity = m.progress >= 1 ? 0 : 1;
  $('#scanInfo').textContent = m.progress < 1 ? `First scan ${Math.round(m.progress * 100)}%` : `Updated ${S.fetchedAt ? CP.fmtTime(S.fetchedAt) : '–'} ${CP.tzName()}` + (m.due ? ` · ${m.due} refreshing` : '');
  const ln = $('#liveNow'); if (ln) { $('em', ln).textContent = `${m.live || 0} of ${m.count || 0} live`; ln.classList.toggle('open', (m.live || 0) > 0); }
  $('#footMeta').textContent = `${m.count || 0}/${m.universe || 0} instruments · ${m.live || 0} live · live markets refresh every 4–10 min`;
  CP.worldStrip($('#world'), m.exchanges, S.fetchedAt);
}
$('#refreshBtn').addEventListener('click', async () => {
  try { await api('/api/refresh', { method: 'POST', body: '{}' }); } catch (e) {}
  setTimeout(loadScan, 800);
});

const topRows = (n, pred = () => true) => [...S.rows].filter(pred).sort((a, b) => b.strength - a.strength).slice(0, n);

function renderAll() {
  renderKpis(); renderAiMarket(); renderMix(); renderTop(); renderHist(); renderAlign(); renderSetups();
  renderTape(); fillMarketSelects(); renderHeatmap(); renderScreener(); fillLabSelect();
  if (location.hash === '#analysis' && !S.sel) openSymbol(topRows(1)[0].symbol, false);
}

/* ---------------- KPIs ---------------- */
function renderKpis() {
  const r = S.rows, n = r.length;
  const cnt = s => r.filter(x => x.signal === s).length;
  const avg = n ? r.reduce((a, x) => a + x.strength, 0) / n : 0;
  const up = r.filter(x => x.bias === 'UP').length, br = n ? up / n * 100 : 0;
  const tiles = [
    { l: 'Live now', v: r.filter(x => x.live).length, s: `of ${n} · ${new Set(r.map(x => x.market)).size} markets` },
    { l: 'CALL', v: cnt('CALL'), s: 'Up-bias at a zone', c: 'call', w: cnt('CALL') / n },
    { l: 'PUT', v: cnt('PUT'), s: 'Down-bias at a zone', c: 'put', w: cnt('PUT') / n },
    { l: 'WAIT', v: cnt('WAIT'), s: 'No zone or market closed', c: 'wait', w: cnt('WAIT') / n },
    { l: 'Avg strength', v: avg.toFixed(1), s: 'Score out of 100' },
    { l: 'Breadth', v: br.toFixed(0) + '%', s: `${up} up · ${n - up} down` },
  ];
  $('#kpis').innerHTML = tiles.map(t => `<div class="kpi ${t.c || ''}"><div class="k-lbl">${t.l}</div><div class="k-val">${t.v}</div><div class="k-sub">${t.s}</div>${t.w != null ? `<i class="k-bar" style="width:${(t.w * 100).toFixed(1)}%"></i>` : ''}</div>`).join('');
}

/* ---------------- AI brief (typing) ---------------- */
function typeInto(ul, points) {
  ul.innerHTML = '';
  if (reduceMotion) { ul.innerHTML = points.map(p => `<li>${esc(p)}</li>`).join(''); return; }
  let i = 0;
  const next = () => {
    if (i >= points.length) return;
    const li = document.createElement('li'); li.className = 'typing'; ul.appendChild(li);
    const txt = points[i++]; let j = 0;
    const t = setInterval(() => {
      j += 3; li.textContent = txt.slice(0, j);
      if (j >= txt.length) { clearInterval(t); li.className = ''; next(); }
    }, 12);
  };
  next();
}
function renderAiMarket() {
  const b = S.brief || {};
  $('#aiMarketHead').textContent = b.headline || '';
  const rp = $('#regimePill');
  rp.textContent = (b.regime || '–').toUpperCase() + (b.breadth != null ? ` · ${b.breadth}%` : '');
  rp.className = 'regime ' + (b.regime === 'Risk-on' ? 'on' : b.regime === 'Risk-off' ? 'off' : '');
  const key = JSON.stringify(b.points);
  if (key !== S.lastBrief) { S.lastBrief = key; typeInto($('#aiMarketPts'), b.points || []); }
}

/* ---------------- mix by market ---------------- */
function groupByMarket() {
  const g = {};
  S.rows.forEach(r => { (g[r.market] = g[r.market] || []).push(r); });
  return g;
}
function renderMix() {
  const g = groupByMarket();
  const rows = Object.entries(g).map(([m, v]) => ({ m, n: v.length, c: v.filter(x => x.signal === 'CALL').length, p: v.filter(x => x.signal === 'PUT').length }))
    .sort((a, b) => (b.c + b.p) / b.n - (a.c + a.p) / a.n);
  $('#mixChart').innerHTML = rows.map(r => {
    const w = r.n - r.c - r.p;
    return `<div class="mix-row" data-m="${esc(r.m)}" data-c="${r.c}" data-p="${r.p}" data-w="${w}" data-n="${r.n}">
      <span class="m-name">${esc(r.m)}</span>
      <span class="m-bar">${r.c ? `<i class="i-call" style="width:${r.c / r.n * 100}%"></i>` : ''}${r.p ? `<i class="i-put" style="width:${r.p / r.n * 100}%"></i>` : ''}<i class="i-wait" style="flex:1"></i></span>
      <span class="m-cnt"><span class="up">${r.c}</span> · <span class="down">${r.p}</span> · ${w}</span></div>`;
  }).join('');
  $$('#mixChart .mix-row').forEach(el => {
    el.addEventListener('mousemove', e => showTip(`<b>${esc(el.dataset.m)}</b><br>CALL ${el.dataset.c} · PUT ${el.dataset.p} · WAIT ${el.dataset.w}<br>${el.dataset.n} instruments`, e));
    el.addEventListener('mouseleave', hideTip);
    el.style.cursor = 'pointer';
    el.addEventListener('click', () => { $('#mktFilter').value = el.dataset.m; renderHeatmap(); go('heatmap'); });
  });
}

/* ---------------- sparkline ---------------- */
function spark(vals, w = 88, h = 26, color) {
  if (!vals || vals.length < 2) return '';
  const mn = Math.min(...vals), mx = Math.max(...vals), rg = mx - mn || 1;
  const pts = vals.map((v, i) => [i / (vals.length - 1) * (w - 4) + 2, h - 3 - (v - mn) / rg * (h - 6)]);
  const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join('');
  const col = color || (vals[vals.length - 1] >= vals[0] ? COL.call : COL.put);
  const last = pts[pts.length - 1];
  return `<svg class="spark" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true"><path d="${d}L${last[0]} ${h}L2 ${h}Z" fill="${col}" fill-opacity=".1"/><path d="${d}" fill="none" stroke="${col}" stroke-width="1.5" stroke-linejoin="round"/><circle cx="${last[0]}" cy="${last[1]}" r="2.2" fill="${col}"/></svg>`;
}
const sbar = (v, sig) => `<span class="sbar"><i><b style="width:${v}%;background:${SIGCOL[sig] || COL.accent}"></b></i><span>${Math.round(v)}</span></span>`;
const tfdots = tf => `<span class="tfdots">${['1H', '4H', '1D', '1W'].map(k => `<i class="${tf[k] === 1 ? 'u' : 'd'}" title="${k} ${tf[k] === 1 ? 'up' : 'down'}">${k}</i>`).join('')}</span>`;

/* ---------------- top table ---------------- */
function renderTop() {
  const rows = topRows(9, r => r.live).concat(topRows(9, r => !r.live)).slice(0, 9);
  $('#topTable').innerHTML = `<thead><tr><th>Symbol</th><th>Signal</th><th>Strength</th><th>TF</th><th class="num">Price</th><th class="num">Day</th><th>30d</th></tr></thead><tbody>` +
    rows.map(r => `<tr data-sym="${esc(r.symbol)}"><td class="sym-cell"><b>${esc(r.name)}</b><small>${esc(r.market)} · ${r.live ? '<span class="up">live</span>' : esc((CP.STATUS_LBL[r.status] || '').toLowerCase())}</small></td>
      <td><span class="sig ${r.signal}">${r.signal}</span></td><td>${sbar(r.strength, r.signal)}</td><td>${tfdots(r.tf)}</td>
      <td class="num">${fmtP(r.price)}</td><td class="num ${cls(r.change_pct)}">${fmtPct(r.change_pct)}</td><td>${spark(r.spark)}</td></tr>`).join('') + '</tbody>';
  bindRowClicks('#topTable');
}
function bindRowClicks(sel) { $$(sel + ' tbody tr[data-sym]').forEach(tr => tr.addEventListener('click', () => openSymbol(tr.dataset.sym))); }

/* ---------------- histogram ---------------- */
function renderHist() {
  const bins = Array.from({ length: 10 }, () => ({ CALL: 0, PUT: 0, WAIT: 0 }));
  S.rows.forEach(r => { bins[Math.min(9, Math.floor(r.strength / 10))][r.signal]++; });
  const W = 460, H = 190, pl = 28, pb = 22, pt = 8, pw = W - pl - 6, ph = H - pb - pt;
  const mx = Math.max(1, ...bins.map(b => b.CALL + b.PUT + b.WAIT));
  const tstep = Math.max(1, niceStep(mx / 4)), nice = Math.ceil(mx / tstep) * tstep, bw = pw / 10;
  let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Strength distribution"><g class="axis">`;
  for (let v = 0; v <= nice; v += tstep) { const y = pt + ph - v / nice * ph; s += `<line x1="${pl}" x2="${W - 6}" y1="${y}" y2="${y}"/><text x="${pl - 6}" y="${y + 3}" text-anchor="end">${v}</text>`; }
  for (let i = 0; i <= 10; i += 2) s += `<text x="${pl + i * bw}" y="${H - 6}" text-anchor="middle">${i * 10}</text>`;
  s += '</g>';
  bins.forEach((b, i) => {
    let y = pt + ph; const x = pl + i * bw + 2, w = bw - 4;
    ['WAIT', 'PUT', 'CALL'].forEach(k => {
      if (!b[k]) return;
      const h = b[k] / nice * ph; y -= h;
      s += `<rect x="${x}" y="${y}" width="${w}" height="${Math.max(h - 2, 1)}" rx="2" fill="${k === 'WAIT' ? '#2C3748' : SIGCOL[k]}"/>`;
    });
    s += `<rect class="hit" data-i="${i}" x="${pl + i * bw}" y="${pt}" width="${bw}" height="${ph}" fill="transparent"/>`;
  });
  s += '</svg>';
  const box = $('#histChart'); box.innerHTML = s;
  $$('.hit', box).forEach(el => {
    const b = bins[el.dataset.i], i = +el.dataset.i;
    el.addEventListener('mousemove', e => showTip(`<b>Strength ${i * 10}–${i * 10 + 10}</b><br><span class="up">CALL ${b.CALL}</span> · <span class="down">PUT ${b.PUT}</span> · WAIT ${b.WAIT}`, e));
    el.addEventListener('mouseleave', hideTip);
  });
}
function renderAlign() {
  const n = S.rows.length || 1;
  $('#alignChart').innerHTML = [4, 3, 2].map(a => {
    const c = S.rows.filter(r => r.alignment === a).length;
    return `<div class="al"><div class="a-top"><span>${a}/4 aligned</span><span>${(c / n * 100).toFixed(0)}%</span></div><div class="a-val">${c}</div><div class="a-cells">${[1, 2, 3, 4].map(k => `<i class="${k <= a ? 'on' : ''}"></i>`).join('')}</div></div>`;
  }).join('');
}

/* ---------------- setups ---------------- */
function renderSetups() {
  const rows = topRows(12, r => r.live && r.signal !== 'WAIT');
  $('#setups').innerHTML = rows.length ? rows.map(r => `<button type="button" class="setup ${r.signal}" data-sym="${esc(r.symbol)}">
    <div class="s-top"><span class="s-name">${esc(r.name)}</span><span class="sig ${r.signal}">${r.signal}</span></div>
    <div class="s-mid"><span class="s-str">${Math.round(r.strength)}<small>/100</small></span>${spark(r.spark, 80, 28, SIGCOL[r.signal])}</div>
    <div class="s-meta"><span>${esc(r.zone_near)} · ${r.alignment}/4</span><span>RSI ${r.rsi != null ? r.rsi.toFixed(0) : '–'}</span></div></button>`).join('')
    : '<div class="empty">No live instrument is at its ATR zone right now. Closed markets never show a signal.</div>';
  $$('#setups .setup').forEach(b => b.addEventListener('click', () => openSymbol(b.dataset.sym)));
}

/* ---------------- tape ---------------- */
function renderTape() {
  const pick = ['^NSEI', '^NSEBANK', '^BSESN', '^GSPC', '^NDX', '^GDAXI', '^FTSE', '^N225', '^HSI', 'BTC-USD', 'ETH-USD', 'GC=F', 'CL=F', 'MCX:GOLD', 'MCX:CRUDEOIL', 'USDINR=X', 'EURUSD=X', 'DX-Y.NYB', 'AAPL', 'NVDA', 'TSLA', 'RELIANCE.NS', 'HDFCBANK.NS', 'SOL-USD'];
  const rows = pick.map(s => S.bySym[s]).filter(Boolean);
  const html = rows.map(r => `<span class="tape-item" data-sym="${esc(r.symbol)}"><b>${esc(r.name)}</b><span>${fmtP(r.price)}</span><em class="${cls(r.change_pct)}" style="font-style:normal">${fmtPct(r.change_pct)}</em><i class="dot ${r.live ? r.signal.toLowerCase() : 'wait'}" title="${r.live ? 'live' : esc(r.status)}"></i></span>`).join('');
  $('#tape').innerHTML = html + html;
  $$('#tape .tape-item').forEach(el => el.addEventListener('click', () => openSymbol(el.dataset.sym)));
}

/* ---------------- heatmap ---------------- */
function fillMarketSelects() {
  const ms = Object.keys(groupByMarket());
  ['#mktFilter', '#scrMkt'].forEach(sel => {
    const el = $(sel), cur = el.value;
    el.innerHTML = '<option value="ALL">All markets</option>' + ms.map(m => `<option>${esc(m)}</option>`).join('');
    el.value = ms.includes(cur) ? cur : 'ALL';
  });
}
function tileBg(r) {
  const a = 0.08 + (r.strength / 100) * 0.62;
  const rgb = r.signal === 'CALL' ? '30,211,154' : r.signal === 'PUT' ? '255,90,118' : '125,138,160';
  const aa = r.signal === 'WAIT' ? a * 0.45 : a;
  return `linear-gradient(160deg, rgba(${rgb},${aa.toFixed(3)}), rgba(${rgb},${(aa * 0.45).toFixed(3)}))`;
}
function renderHeatmap() {
  const mk = $('#mktFilter').value, sort = $('#hmSort').value;
  const lo = $('#hmLive').checked;
  let rows = S.rows.filter(r => (S.sig === 'ALL' || r.signal === S.sig) && (mk === 'ALL' || r.market === mk) && (!lo || r.live));
  const g = {};
  rows.forEach(r => (g[r.market] = g[r.market] || []).push(r));
  const sorter = sort === 'change' ? (a, b) => b.change_pct - a.change_pct : sort === 'name' ? (a, b) => a.name.localeCompare(b.name) : (a, b) => b.strength - a.strength;
  const order = Object.keys(g).sort((a, b) => g[b].length - g[a].length);
  $('#heatmap').innerHTML = order.length ? order.map(m => {
    const v = g[m].sort(sorter);
    const c = v.filter(x => x.signal === 'CALL').length, p = v.filter(x => x.signal === 'PUT').length;
    const lv = v.filter(x => x.live).length;
    return `<section class="hm-sec"><h3>${esc(m)} <small>${v.length} · ${lv} live · <span class="up">${c} CALL</span> · <span class="down">${p} PUT</span></small></h3><div class="hm-grid">` +
      v.map(r => `<button type="button" class="tile ${r.signal} ${r.live ? '' : 'closed'}" data-sym="${esc(r.symbol)}" style="background:${tileBg(r)}">
        ${r.zone_touch ? '<i class="t-zone" title="At zone"></i>' : ''}
        <span class="t-sym">${esc(r.name)}</span><span class="t-str">${Math.round(r.strength)}</span>
        <span class="t-row"><span class="t-sig">${r.live ? r.signal : (CP.STATUS_LBL[r.status] || 'CLOSED')}</span><span class="${cls(r.change_pct)}">${fmtPct(r.change_pct, 1)}</span></span></button>`).join('') + '</div></section>';
  }).join('') : '<div class="empty">No instruments match these filters.</div>';
  $$('#heatmap .tile').forEach(t => {
    const r = S.bySym[t.dataset.sym];
    t.addEventListener('click', () => openSymbol(r.symbol));
    t.addEventListener('mousemove', e => showTip(`<b>${esc(r.name)}</b> · ${esc(r.symbol)}<br>${esc(r.exchange || '')} · ${r.live ? '<span class="up">live</span>' : esc(CP.STATUS_LBL[r.status] || r.status)}<br>${r.signal} · strength ${r.strength}<br>Bias ${r.bias} · ${r.alignment}/4 aligned<br>ADX ${r.adx_value} · ATR ${r.atr_pct}%<br>Price ${fmtP(r.price)} (${fmtPct(r.change_pct)})`, e));
    t.addEventListener('mouseleave', hideTip);
  });
}
$$('#sigChips .chip').forEach(b => b.addEventListener('click', () => {
  S.sig = b.dataset.sig; $$('#sigChips .chip').forEach(x => x.classList.toggle('on', x === b)); renderHeatmap();
}));
$('#mktFilter').addEventListener('change', renderHeatmap);
$('#hmSort').addEventListener('change', renderHeatmap);
$('#hmLive').addEventListener('change', renderHeatmap);

/* ---------------- screener ---------------- */
const SCR_COLS = [
  { k: 'name', l: 'Symbol' }, { k: 'market', l: 'Market' }, { k: 'signal', l: 'Signal' }, { k: 'strength', l: 'Strength' },
  { k: 'alignment', l: 'TF align' }, { k: 'price', l: 'Price', num: 1 }, { k: 'change_pct', l: 'Day', num: 1 },
  { k: 'ret_1m', l: '1M', num: 1 }, { k: 'adx_value', l: 'ADX', num: 1 }, { k: 'atr_pct', l: 'ATR %', num: 1 },
  { k: 'rsi', l: 'RSI 1H', num: 1 }, { k: 'status', l: 'Status' }, { k: 'ts', l: 'Updated' },
];
function renderScreener() {
  const q = $('#scrQuery').value.trim().toLowerCase(), mk = $('#scrMkt').value, sg = $('#scrSig').value, al = $('#scrAligned').checked, lo = $('#scrLive').checked;
  const { k, dir } = S.scrSort;
  const rows = S.rows.filter(r => (!q || r.symbol.toLowerCase().includes(q) || r.name.toLowerCase().includes(q)) && (mk === 'ALL' || r.market === mk) && (sg === 'ALL' || r.signal === sg) && (!al || r.alignment === 4) && (!lo || r.live))
    .sort((a, b) => { const x = a[k], y = b[k]; if (x == null) return 1; if (y == null) return -1; return (typeof x === 'string' ? x.localeCompare(y) : x - y) * dir; });
  $('#scrTable').innerHTML = `<thead><tr>${SCR_COLS.map(c => `<th data-k="${c.k}" class="${c.num ? 'num' : ''} ${c.k === k ? 'sorted' : ''}">${c.l}${c.k === k ? (dir > 0 ? ' ↑' : ' ↓') : ''}</th>`).join('')}</tr></thead><tbody>` +
    (rows.map(r => `<tr data-sym="${esc(r.symbol)}"><td class="sym-cell"><b>${esc(r.name)}</b><small>${esc(r.symbol)}</small></td><td>${esc(r.market)}</td>
      <td><span class="sig ${r.signal}">${r.signal}</span></td><td>${sbar(r.strength, r.signal)}</td><td>${tfdots(r.tf)}</td>
      <td class="num">${fmtP(r.price)}</td><td class="num ${cls(r.change_pct)}">${fmtPct(r.change_pct)}</td><td class="num ${cls(r.ret_1m)}">${fmtPct(r.ret_1m, 1)}</td>
      <td class="num">${r.adx_value.toFixed(1)}</td><td class="num">${r.atr_pct.toFixed(2)}</td><td class="num">${r.rsi != null ? r.rsi.toFixed(1) : '–'}</td><td>${CP.stBadge(r)}</td><td class="mono">${r.ts ? CP.fmtTime(r.ts) : '–'}</td></tr>`).join('')
      || `<tr><td colspan="${SCR_COLS.length}" style="text-align:center;color:var(--muted);padding:24px">No instruments match these filters.</td></tr>`) + '</tbody>';
  $$('#scrTable th[data-k]').forEach(th => th.addEventListener('click', () => {
    S.scrSort = { k: th.dataset.k, dir: S.scrSort.k === th.dataset.k ? -S.scrSort.dir : (['name', 'market', 'signal', 'status'].includes(th.dataset.k) ? 1 : -1) };
    renderScreener();
  }));
  bindRowClicks('#scrTable');
}
['#scrQuery', '#scrMkt', '#scrSig', '#scrAligned', '#scrLive'].forEach(s => $(s).addEventListener('input', renderScreener));
if (window.TORUS_DEMO) $('#csvBtn').hidden = true;

/* ---------------- search ---------------- */
const sBox = $('#searchBox'), sPop = $('#searchPop');
let sIdx = 0, sRes = [];
function doSearch() {
  const q = sBox.value.trim().toLowerCase();
  if (!q) { sPop.hidden = true; return; }
  sRes = S.rows.filter(r => r.symbol.toLowerCase().includes(q) || r.name.toLowerCase().includes(q) || r.market.toLowerCase().includes(q)).slice(0, 8);
  sIdx = 0;
  sPop.innerHTML = sRes.length ? sRes.map((r, i) => `<button type="button" data-sym="${esc(r.symbol)}" class="${i === 0 ? 'hl' : ''}"><span><b>${esc(r.name)}</b> <small>${esc(r.symbol)} · ${esc(r.market)}</small></span><span class="mono">${fmtP(r.price)}</span><span class="sig ${r.signal}">${r.signal}</span></button>`).join('') : '<div class="empty">No match</div>';
  sPop.hidden = false;
  $$('button', sPop).forEach(b => b.addEventListener('mousedown', e => { e.preventDefault(); pickSearch(b.dataset.sym); }));
}
function pickSearch(sym) { sBox.value = ''; sPop.hidden = true; sBox.blur(); openSymbol(sym); }
sBox.addEventListener('input', doSearch);
sBox.addEventListener('blur', () => setTimeout(() => (sPop.hidden = true), 120));
sBox.addEventListener('keydown', e => {
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault(); sIdx = (sIdx + (e.key === 'ArrowDown' ? 1 : -1) + sRes.length) % Math.max(sRes.length, 1);
    $$('button', sPop).forEach((b, i) => b.classList.toggle('hl', i === sIdx));
  } else if (e.key === 'Enter' && sRes[sIdx]) pickSearch(sRes[sIdx].symbol);
  else if (e.key === 'Escape') { sPop.hidden = true; sBox.blur(); }
});
addEventListener('keydown', e => { if (e.key === '/' && document.activeElement.tagName !== 'INPUT') { e.preventDefault(); sBox.focus(); } });

/* =====================================================================
   Candlestick chart: TradingView Lightweight Charts + TORUS overlays
   (Supertrend, EMA, ATR day zones with bands, PDH/PDL/Open, Fibonacci)
   ===================================================================== */
const LWC = window.LightweightCharts;
const shiftT = t => t - new Date(t * 1000).getTimezoneOffset() * 60;   // show bars in the viewer's time zone
class LWChart {
  constructor(el, legend) {
    this.el = el; this.leg = legend; this.d = null; this.lv = []; this.pl = []; this.tf = '1D';
    this.opt = { st: true, ema: true, zone: true, fib: true };
    if (!LWC) { el.innerHTML = '<div class="empty" style="margin:40px">Chart library could not load. Check your connection.</div>'; return; }
    const pf = { type: 'custom', formatter: fmtP, minMove: 1e-9 };
    this.ch = LWC.createChart(el, {
      autoSize: true,
      layout: { background: { type: 'solid', color: COL.bg2 }, textColor: COL.muted, fontFamily: 'JetBrains Mono, Consolas, monospace', fontSize: 11 },
      grid: { vertLines: { color: 'rgba(26,35,49,.55)' }, horzLines: { color: 'rgba(26,35,49,.55)' } },
      rightPriceScale: { borderColor: COL.line2, scaleMargins: { top: 0.08, bottom: 0.2 } },
      timeScale: { borderColor: COL.line2, timeVisible: true, secondsVisible: false, rightOffset: 6, barSpacing: 7 },
      crosshair: { mode: LWC.CrosshairMode.Normal, vertLine: { color: 'rgba(169,181,198,.45)', labelBackgroundColor: COL.line2 }, horzLine: { color: 'rgba(169,181,198,.45)', labelBackgroundColor: COL.line2 } },
      localization: { priceFormatter: fmtP },
    });
    this.cs = this.ch.addCandlestickSeries({ upColor: COL.call, downColor: COL.put, borderVisible: false, wickUpColor: COL.call, wickDownColor: COL.put, priceFormat: pf });
    this.vs = this.ch.addHistogramSeries({ priceScaleId: 'vol', priceFormat: { type: 'volume' }, lastValueVisible: false, priceLineVisible: false });
    this.ch.priceScale('vol').applyOptions({ scaleMargins: { top: 0.85, bottom: 0 } });
    const line = (color, w) => this.ch.addLineSeries({ color, lineWidth: w, lastValueVisible: false, priceLineVisible: false, crosshairMarkerVisible: false, priceFormat: pf });
    this.pf = pf; this.stS = []; this.em = line(COL.accent, 1);
    this.ch.subscribeCrosshairMove(p => this.legend(p));
  }
  set(d, levels, tf) {
    if (!this.ch) return;
    this.d = d; this.lv = levels || []; this.tf = tf;
    const n = d.t.length, T = [];
    for (let i = 0; i < n; i++) { let t = shiftT(d.t[i]); if (i && t <= T[i - 1]) t = T[i - 1] + 1; T.push(t); }
    this.T = T;
    this.cs.setData(T.map((t, i) => ({ time: t, open: d.o[i], high: d.h[i], low: d.l[i], close: d.c[i] })));
    this.vs.setData(T.map((t, i) => ({ time: t, value: d.v[i] || 0, color: d.c[i] >= d.o[i] ? 'rgba(30,211,154,.22)' : 'rgba(255,90,118,.22)' })));
    this.stS = CP.stSeries(this.ch, T, d.st, d.dir, this.pf, this.stS);
    this.em.setData(T.map((t, i) => ({ time: t, value: d.ema[i] })));
    const mk = [];
    for (let i = 1; i < n; i++) if (d.dir[i] !== d.dir[i - 1]) mk.push(d.dir[i] === 1 ? { time: T[i], position: 'belowBar', color: COL.call, shape: 'arrowUp' } : { time: T[i], position: 'aboveBar', color: COL.put, shape: 'arrowDown' });
    this.markers = mk;
    this.draw();
    this.fit();
  }
  fit() {
    if (!this.ch || !this.d) return;
    const n = this.d.t.length, show = Math.min(n, this.tf === '1W' ? 104 : 120);
    this.ch.timeScale().setVisibleLogicalRange({ from: n - show, to: n + 4 });
  }
  draw() {   // (re)apply overlay toggles and level lines
    if (!this.ch || !this.d) return;
    const o = this.opt;
    this.stS.forEach(x => x.applyOptions({ visible: o.st })); this.em.applyOptions({ visible: o.ema });
    this.cs.setMarkers(o.st ? this.markers : []);
    this.pl.forEach(l => this.cs.removePriceLine(l)); this.pl = [];
    const LS = LWC.LineStyle;
    this.lv.forEach(l => {
      if ((l.kind === 'zone' && !o.zone) || (l.kind === 'fib' && !o.fib)) return;
      const style = l.kind === 'fib' ? LS.Dotted : l.kind === 'zone' ? LS.Solid : LS.Dashed;
      this.pl.push(this.cs.createPriceLine({ price: l.price, color: l.color, lineWidth: l.kind === 'zone' ? 2 : 1, lineStyle: style, axisLabelVisible: l.kind !== 'fib', title: l.label }));
      if (l.band) [l.price + l.band, l.price - l.band].forEach(p => this.pl.push(this.cs.createPriceLine({ price: p, color: l.fill.replace(/[\d.]+\)$/, '.55)'), lineWidth: 1, lineStyle: LS.SparseDotted, axisLabelVisible: false, title: '' })));
    });
    this.legend(null);
  }
  fmtT(t) {   // t is already shifted to local time: format as UTC
    const o = this.tf === '1H' || this.tf === '4H' ? { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false } : this.tf === '1W' ? { day: '2-digit', month: 'short', year: '2-digit' } : { day: '2-digit', month: 'short', year: 'numeric' };
    return new Date(t * 1000).toLocaleString('en-GB', { timeZone: 'UTC', ...o });
  }
  legend(p) {
    const d = this.d; if (!d || !this.leg) return;
    let i = d.t.length - 1;
    if (p && p.time != null) { const k = this.T.indexOf(p.time); if (k >= 0) i = k; }
    const ch = (d.c[i] / d.o[i] - 1) * 100;
    this.leg.innerHTML = `<span>${this.fmtT(this.T[i])}</span><span>O <b>${fmtP(d.o[i])}</b></span><span>H <b>${fmtP(d.h[i])}</b></span><span>L <b>${fmtP(d.l[i])}</b></span><span>C <b class="${cls(ch)}">${fmtP(d.c[i])}</b></span>` +
      (this.opt.st ? `<span style="color:${d.dir[i] === 1 ? COL.call : COL.put}">ST ${fmtP(d.st[i])}</span>` : '') + (this.opt.ema ? `<span style="color:${COL.accent}">EMA ${fmtP(d.ema[i])}</span>` : '');
  }
}
function niceStep(raw) { const p = Math.pow(10, Math.floor(Math.log10(raw))), f = raw / p; return (f < 1.5 ? 1 : f < 3 ? 2 : f < 7 ? 5 : 10) * p; }

function buildLevels(r) {
  const band = r.zone_band_px, L = [];
  L.push({ price: r.atr_low, label: 'ATR Low', color: COL.call, dash: [], kind: 'zone', band, fill: 'rgba(30,211,154,.09)' });
  L.push({ price: r.atr_high, label: 'ATR High', color: COL.put, dash: [], kind: 'zone', band, fill: 'rgba(255,90,118,.09)' });
  L.push({ price: r.day_high, label: 'PDH', color: COL.ink2, dash: [6, 4], kind: 'level' });
  L.push({ price: r.day_low, label: 'PDL', color: COL.ink2, dash: [6, 4], kind: 'level' });
  L.push({ price: r.today_open, label: 'Open', color: COL.accent, dash: [2, 4], kind: 'level' });
  r.fib_levels.forEach(f => L.push({ price: f.price, label: 'Fib ' + f.ratio, color: COL.ai, dash: [2, 3], kind: 'fib' }));
  return L;
}

/* =====================================================================
   Analysis view
   ===================================================================== */
let anChart = null, labChart = null;
async function openSymbol(sym, nav = true) {
  S.sel = sym;
  if (nav) { const h = '#analysis=' + encodeURIComponent(sym); if (location.hash !== h) location.hash = h; else route(); }
  await loadAnalysis();
}
S.engine = 'torus';
function mountTv() {
  if (S.engine !== 'tv' || !S.sel) return;
  CP.tvWidget($('#anTv'), 'advanced-chart', { autosize: true, symbol: CP.tvSymbol(S.sel), interval: CP.TV_INTERVAL[S.tf] || 'D', timezone: CP.TZ, style: '1',
    allow_symbol_change: true, withdateranges: true, backgroundColor: 'rgba(9,13,19,1)', gridColor: 'rgba(26,35,49,0.6)', support_host: 'https://www.tradingview.com' });
}
$$('#anEngine button').forEach(b => b.addEventListener('click', () => {
  S.engine = b.dataset.e; $$('#anEngine button').forEach(x => x.classList.toggle('on', x === b));
  const tv = S.engine === 'tv';
  $('#anWrap').hidden = tv; $('#anTv').hidden = !tv; $('#anOverlays').style.visibility = tv ? 'hidden' : '';
  $('#anHint').textContent = tv ? 'TradingView chart with its own data feed · some exchanges may show delayed data' : 'Scroll to zoom · drag to pan · ATR zone = today\'s open ± daily ATR(14)';
  if (tv) mountTv(); else if (anChart) anChart.fit();
}));
async function loadAnalysis() {
  if (!S.sel) return;
  let d;
  try { d = await api(`/api/symbol/${encodeURIComponent(S.sel)}?tf=${S.tf}`); } catch (e) { $('#anName').textContent = 'Data not ready yet'; return; }
  const r = d.result, st = d.stats || {};
  if (r.symbol !== S.sel) return;   // a newer request replaced this one
  $('#anMarket').innerHTML = `${esc(r.market)} · ${esc(r.symbol)} · ${esc(r.exchange || '')} · ${r.live ? '<span class="up">● live</span>' : esc(CP.STATUS_LBL[r.status] || 'closed')}`;
  $('#anName').textContent = r.name;
  $('#anPrice').textContent = fmtP(r.price);
  const chg = $('#anChg'); chg.textContent = fmtPct(r.change_pct); chg.className = 'chg ' + cls(r.change_pct);
  $('#anSignal').innerHTML = `${r.components.bonus4 ? '<span class="meta-chip"><b class="up">4/4</b> +10</span>' : ''}${r.components.confluence > 0 ? `<span class="meta-chip">Confluence <b>+${r.components.confluence.toFixed(1)}</b></span>` : ''}<span class="meta-chip">Bias <b class="${r.bias === 'UP' ? 'up' : r.bias === 'DOWN' ? 'down' : ''}">${r.bias}</b></span><span class="meta-chip">ADX <b>${r.adx_value}</b></span><span class="meta-chip">RSI 1H <b>${r.rsi}</b></span><span class="meta-chip">ATR <b>${r.atr_pct}%</b></span>${r.overextended ? '<span class="meta-chip"><b class="down">Overextended</b></span>' : ''}${r.live ? '' : `<span class="meta-chip" title="Signals are only produced while the market is live"><b>${esc(CP.STATUS_LBL[r.status] || 'CLOSED')}</b></span>`}<span class="big-sig ${r.signal}">${r.signal}</span>`;
  if (!anChart) anChart = new LWChart($('#anChart'), $('#anLegend'));
  syncOverlays();
  anChart.set(d.candles, buildLevels(r), d.tf);
  mountTv();
  renderGauge($('#anGauge'), r);
  $('#anConf').textContent = `${d.brief.confidence} confidence`;
  renderBreakdown($('#anBreak'), r.components);
  $('#anMatrix').innerHTML = ['1H', '4H', '1D', '1W'].map(k => { const t = r.tf[k]; return `<div class="mx ${t.dir === 1 ? 'u' : 'd'}"><div class="mx-tf">${k === '1H' ? '1H · at open' : k}</div><div class="mx-dir">${t.dir === 1 ? '▲ UP' : '▼ DN'}</div><div class="mx-bars">${t.bars_in_trend} bars · ST ${fmtP(t.st)}</div></div>`; }).join('');
  $('#anAiHead').textContent = d.brief.headline;
  typeInto($('#anAiPts'), d.brief.points);
  const best = r.fib_levels.reduce((a, b) => (b.score > a.score ? b : a));
  $('#anLevels').innerHTML = `<thead><tr><th>Level</th><th class="num">Price</th><th class="num">Distance</th></tr></thead><tbody>` +
    [['ATR Day High (Open + ATR)', r.atr_high], ['ATR Day Low (Open − ATR)', r.atr_low], ['Today open', r.today_open], ['Previous day high (PDH)', r.day_high], ['Previous day low (PDL)', r.day_low], [`Best Fib ${best.ratio} (20-day swing)`, best.price], ['Daily ATR (14)', null, fmtP(r.atr)], ['Zone band', null, '± ' + fmtP(r.zone_band_px)], ['RSI 1H (14)', null, r.rsi.toFixed(1)]]
      .map(([l, p, raw]) => `<tr><td>${l}</td><td class="num">${raw || fmtP(p)}</td><td class="num ${p ? cls(p - r.price) : ''}">${p ? fmtPct((p / r.price - 1) * 100) : ''}</td></tr>`).join('') + '</tbody>';
  const pos = st.hi_52w > st.lo_52w ? (r.price - st.lo_52w) / (st.hi_52w - st.lo_52w) * 100 : 50;
  const stat = (l, v, c = '') => `<div class="st"><div class="st-l">${l}</div><div class="st-v ${c}">${v}</div></div>`;
  $('#anStats').innerHTML = stat('1 week', fmtPct(st.ret_1w), cls(st.ret_1w)) + stat('1 month', fmtPct(st.ret_1m), cls(st.ret_1m)) + stat('3 months', fmtPct(st.ret_3m), cls(st.ret_3m)) + stat('1 year', fmtPct(st.ret_1y), cls(st.ret_1y)) +
    stat('Volatility 20d', (st.vol_20d ?? 0).toFixed(1) + '%') + stat('ST flips (1y)', st.st_flips_1y ?? '–') + stat('Time in uptrend', (st.uptrend_share_1y ?? 0).toFixed(0) + '%') +
    `<div class="st range52"><div class="st-l">52-week range</div><div class="r-track"><i style="left:${Math.max(0, Math.min(100, pos))}%"></i></div><div class="r-lbl"><span>${fmtP(st.lo_52w)}</span><span>${fmtPct(st.from_hi_pct, 1)} from high</span><span>${fmtP(st.hi_52w)}</span></div></div>`;
  $('#labSym').value = S.sel;
}
function syncOverlays() { if (anChart) anChart.opt = { st: $('#ovST').checked, ema: $('#ovEMA').checked, zone: $('#ovZone').checked, fib: $('#ovFib').checked }; }
$$('#anOverlays input').forEach(i => i.addEventListener('change', () => { syncOverlays(); anChart && anChart.draw(); }));
$$('#anTfs button').forEach(b => b.addEventListener('click', () => { S.tf = b.dataset.tf; $$('#anTfs button').forEach(x => x.classList.toggle('on', x === b)); loadAnalysis(); }));

function renderGauge(el, r) {
  const v = r.strength, col = SIGCOL[r.signal] === COL.wait ? COL.accent : SIGCOL[r.signal];
  const cx = 130, cy = 120, R = 92, a0 = Math.PI * 0.8, a1 = Math.PI * 2.2;
  const pt = a => [cx + R * Math.cos(a), cy + R * Math.sin(a)];
  const arc = (from, to) => { const [x0, y0] = pt(from), [x1, y1] = pt(to); return `M${x0.toFixed(1)} ${y0.toFixed(1)}A${R} ${R} 0 ${to - from > Math.PI ? 1 : 0} 1 ${x1.toFixed(1)} ${y1.toFixed(1)}`; };
  const av = a0 + (a1 - a0) * v / 100;
  let ticks = '';
  [0, 25, 50, 75, 100].forEach(t => { const a = a0 + (a1 - a0) * t / 100, [x0, y0] = [cx + (R + 10) * Math.cos(a), cy + (R + 10) * Math.sin(a)], [x1, y1] = [cx + (R + 16) * Math.cos(a), cy + (R + 16) * Math.sin(a)]; ticks += `<line x1="${x0}" y1="${y0}" x2="${x1}" y2="${y1}" stroke="${COL.muted}"/><text x="${cx + (R + 27) * Math.cos(a)}" y="${cy + (R + 27) * Math.sin(a) + 3}" text-anchor="middle" fill="${COL.muted}" font-family="JetBrains Mono, Consolas, monospace" font-size="9">${t}</text>`; });
  el.innerHTML = `<svg viewBox="0 -14 260 234" role="img" aria-label="Strength ${v} of 100">
    <path d="${arc(a0, a1)}" fill="none" stroke="${COL.line2}" stroke-width="12" stroke-linecap="round"/>
    <path d="${arc(a0, Math.max(a0 + 0.01, av))}" fill="none" stroke="${col}" stroke-width="12" stroke-linecap="round" style="filter:drop-shadow(0 0 8px ${col}66)"/>
    ${ticks}
    <text x="${cx}" y="${cy + 6}" text-anchor="middle" fill="${COL.ink}" font-family="Chakra Petch, Segoe UI, sans-serif" font-weight="700" font-size="46">${Math.round(v)}</text>
    <text x="${cx}" y="${cy + 28}" text-anchor="middle" fill="${COL.muted}" font-family="JetBrains Mono, Consolas, monospace" font-size="11">/ 100 · ${r.alignment}/4 aligned</text>
    <text x="${cx}" y="${cy + 70}" text-anchor="middle" fill="${SIGCOL[r.signal]}" font-family="Chakra Petch, Segoe UI, sans-serif" font-weight="600" font-size="15" letter-spacing="2">${r.signal === 'WAIT' ? 'WAIT FOR ZONE' : r.signal + ' SETUP'}</text></svg>`;
}
const BD = [['alignment', 'Trend (10 per timeframe)', 'alignment_max'], ['bonus4', '4/4 alignment bonus', 10], ['pdhl', 'PDH / PDL near ATR zone', 10], ['fib', 'Fibonacci near ATR zone', 10], ['confluence', 'Major Zone bonus', 10], ['adx', 'ADX (trend direction)', 10], ['rsi', 'RSI 1H (dip / rally)', 10]];
function renderBreakdown(el, c, base) {
  el.innerHTML = (base ? `<div class="bd-legend"><span><i style="background:var(--accent)"></i>Tuned</span><span><i style="background:var(--muted)"></i>Baseline</span></div>` : '') +
    BD.map(([k, l, m]) => {
      const max = typeof m === 'string' ? Math.max(c[m], base ? base[m] : 0, 1) : m;
      return `<div class="bd-row"><div class="bd-top"><span>${l}</span><b>${c[k].toFixed(1)} <span style="color:var(--muted);font-weight:400">/ ${typeof m === 'string' ? c[m] : m}</span></b></div>
      <div class="bd-track"><i style="width:${Math.min(100, c[k] / max * 100)}%"></i>${base ? `<i class="base" style="width:${Math.min(100, base[k] / max * 100)}%"></i>` : ''}</div></div>`;
    }).join('');
}

/* =====================================================================
   Strategy Lab
   ===================================================================== */
const KN = [['k_atr', 'o_atr', 'atr_multiplier', 1], ['k_per', 'o_per', 'st_period', 0], ['k_ema', 'o_ema', 'ema_span', 0], ['k_aw', 'o_aw', 'alignment_weight', 1], ['k_zb', 'o_zb', 'zone_band', 2]];
const KDEF = { k_atr: 3, k_per: 10, k_ema: 5, k_aw: 10, k_zb: 0.25 };
KN.forEach(([i, o, , dp]) => { const el = $('#' + i); const upd = () => ($('#' + o).textContent = (+el.value).toFixed(dp)); el.addEventListener('input', upd); upd(); });
function fillLabSelect() {
  const el = $('#labSym'), cur = el.value || S.sel || (topRows(1, r => r.signal !== 'WAIT')[0] || topRows(1)[0]).symbol;
  const g = groupByMarket();
  el.innerHTML = Object.entries(g).map(([m, v]) => `<optgroup label="${esc(m)}">${v.sort((a, b) => a.name.localeCompare(b.name)).map(r => `<option value="${esc(r.symbol)}">${esc(r.name)}</option>`).join('')}</optgroup>`).join('');
  el.value = cur;
  if (!S.labRan) { S.labRan = true; runLab(); }
}
$('#labReset').addEventListener('click', () => { Object.entries(KDEF).forEach(([k, v]) => { $('#' + k).value = v; $('#' + k).dispatchEvent(new Event('input')); }); runLab(); });
$('#labForm').addEventListener('submit', e => { e.preventDefault(); runLab(); });
$('#labSym').addEventListener('change', runLab);
async function runLab() {
  const sym = $('#labSym').value; if (!sym) return;
  S.labSym = sym;
  const knobs = Object.fromEntries(KN.map(([i, , k]) => [k, +$('#' + i).value]));
  let d;
  try { d = await api('/api/lab', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ symbol: sym, knobs, tf: '1D' }) }); } catch (e) { return; }
  const r = d.result, b = d.baseline;
  $('#labSymLbl').textContent = `${r.name} · ${r.market}`;
  const dS = r.strength - b.strength;
  const cmp = (l, v, sub) => `<div class="cmp"><div class="c-l">${l}</div><div class="c-v">${v}</div><div class="c-d">${sub}</div></div>`;
  $('#labCompare').innerHTML =
    cmp('Signal', `<span class="sig ${r.signal}">${r.signal}</span>`, `baseline ${b.signal}`) +
    cmp('Strength', r.strength.toFixed(1), `<span class="${cls(dS)}">${dS >= 0 ? '+' : ''}${dS.toFixed(1)}</span> vs ${b.strength.toFixed(1)}`) +
    cmp('Alignment', `${r.alignment}/4 ${r.bias}`, `baseline ${b.alignment}/4 ${b.bias}`) +
    cmp(r.zone_near, fmtP(r.zone), `price ${r.zone_dist_atr > 0 ? '+' : ''}${r.zone_dist_atr} ATR away`);
  renderBreakdown($('#labBreak'), r.components, b.components);
  if (!labChart) { labChart = new LWChart($('#labChart'), $('#labLegend')); }
  labChart.set(d.candles, buildLevels(r), '1D');
  $('#labAiHead').textContent = d.brief.headline;
  const k = d.knobs;
  typeInto($('#labAiPts'), [`Settings: ATR × ${k.atr_multiplier}, period ${k.st_period}, EMA ${k.ema_span}, weight ${k.alignment_weight}, zone band ${k.zone_band} ATR.`,
    `Price is ${r.price >= r.ema ? 'above' : 'below'} EMA ${k.ema_span} (${fmtP(r.ema)}), which ${(r.price >= r.ema) === (r.bias === 'UP') ? 'agrees with' : 'argues against'} the ${r.bias} bias.`, ...d.brief.points.slice(0, 3)]);
}

/* ---------------- boot ---------------- */
route();
loadScan();
})();
