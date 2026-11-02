/* =====================================================================
   ChartPulse shared runtime: header, local time, data, formatting,
   world-markets strip, TradingView embeds. Exposed as window.CP.
   ===================================================================== */
(() => {
'use strict';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const CSSV = getComputedStyle(document.documentElement);
const C = k => CSSV.getPropertyValue(k).trim();
const COL = { call: C('--call'), put: C('--put'), wait: C('--wait'), accent: C('--accent'), ai: C('--ai'), ink: C('--ink'), ink2: C('--ink2'),
  muted: C('--muted'), line: C('--line'), line2: C('--line2'), bg2: C('--bg2'), panel: C('--panel') };

function fmtP(p) {
  if (p == null || isNaN(p)) return '–';
  const a = Math.abs(p);
  if (a >= 1000) return p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  if (a >= 1) return p.toFixed(2);
  if (a >= 0.01) return p.toFixed(4);
  return p.toPrecision(4);
}
const fmtPct = (v, d = 2) => v == null || isNaN(v) ? '–' : (v > 0 ? '+' : '') + (+v).toFixed(d) + '%';
const cls = v => v > 0 ? 'up' : v < 0 ? 'down' : '';

/* ---------- viewer-local time ---------- */
const TZ = (Intl.DateTimeFormat().resolvedOptions().timeZone) || 'UTC';
const TZ_ABBR = { 'Asia/Kolkata': 'IST', 'Asia/Calcutta': 'IST', 'Asia/Dubai': 'GST', 'Asia/Singapore': 'SGT', 'Asia/Hong_Kong': 'HKT', 'Asia/Tokyo': 'JST', 'Asia/Shanghai': 'CST', 'Australia/Sydney': 'AEST' };
function tzName(d = new Date()) {
  const part = loc => { try { return new Intl.DateTimeFormat(loc, { timeZoneName: 'short' }).formatToParts(d).find(p => p.type === 'timeZoneName').value; } catch (e) { return ''; } };
  const a = part(navigator.language) || part('en-US');
  return /^(GMT|UTC)[+-]/.test(a) && TZ_ABBR[TZ] ? TZ_ABBR[TZ] : (a || TZ);
}
const fmtTime = (ts, tz) => new Date(ts * 1000).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, ...(tz ? { timeZone: tz } : {}) });
const fmtDT = ts => new Date(ts * 1000).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false });
function fmtDur(s) {
  if (s == null) return '';
  s = Math.max(0, Math.round(s / 60));
  const d = Math.floor(s / 1440), h = Math.floor((s % 1440) / 60), m = s % 60;
  return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
}

/* ---------- header ---------- */
function initHeader() {
  const page = document.body.dataset.page;
  $$('.site-nav a').forEach(a => a.classList.toggle('on', a.dataset.p === page));
  const t = $('.nav-toggle'), nav = $('.site-nav');
  if (t) t.addEventListener('click', () => { const o = nav.classList.toggle('open'); t.setAttribute('aria-expanded', o); });
  const tick = () => { const el = $('#cpClock'); if (el) el.innerHTML = `${fmtTime(Date.now() / 1000)}<small>${esc(tzName())} · your time</small>`; };
  tick(); setInterval(tick, 10000);
  $$('[data-year]').forEach(el => (el.textContent = new Date().getFullYear()));
}

/* ---------- data ---------- */
async function api(route, params = {}, opts) {
  const q = new URLSearchParams({ r: route, ...params });
  const r = await fetch('api.php?' + q, opts);
  if (!r.ok) throw new Error(route + ' ' + r.status);
  return r.json();
}
/** Polls the scan endpoint; calls cb(data). minGap = slowest cadence for non-terminal pages. */
function pollScan(cb, { minGap = 15 } = {}) {
  let timer = null, busy = false;
  const run = async () => {
    clearTimeout(timer);
    if (busy) return; busy = true;
    let next = 20;
    try {
      const d = await api('scan'); d.fetchedAt = Date.now() / 1000; syncClock(d.meta, d.fetchedAt); cb(d);
      next = Math.max(d.meta.next_poll || 20, d.meta.progress < 1 ? 3 : minGap);
      if (d.meta.progress < 1) next = 3;
    } catch (e) { next = 8; cb(null, e); }
    busy = false;
    timer = setTimeout(run, (document.hidden ? Math.max(next, 60) : next) * 1000);
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) run(); });
  run();
  return { now: run };
}

/* ---------- world markets strip ---------- */
const STATE_TXT = { open: 'Open', closed: 'Closed', break: 'Break', holiday: 'Holiday' };
/** Seconds between the server clock and this device (device clocks can be wrong). */
let skew = 0;
const serverNow = () => Date.now() / 1000 + skew;
function syncClock(meta, fetchedAt) { if (meta && meta.now && fetchedAt) skew = meta.now - fetchedAt; }
function worldStrip(el, exch, fetchedAt) {
  if (!el || !exch) return;
  const drift = Date.now() / 1000 - (fetchedAt || Date.now() / 1000);
  const order = { open: 0, holiday: 1, break: 2, closed: 3 };
  const list = [...exch].sort((a, b) => order[a.state] - order[b.state] || (a.opens_in ?? 9e9) - (b.opens_in ?? 9e9));
  el.innerHTML = list.map(x => {
    const tz = x.tz || null;
    let when = '';
    if (x.state === 'open' && x.closes_in != null && x.closes_in < 86400) when = 'closes in ' + fmtDur(x.closes_in - drift);
    else if (x.state === 'open') when = x.key === 'CRYPTO' ? '24×7' : '24h session';
    else if (x.opens_in != null) when = 'opens in ' + fmtDur(x.opens_in - drift);
    return `<div class="xchip ${x.state}" title="${esc(x.venue)} · exchange time UTC${esc(x.utc_offset)}">
      <div class="x-top"><i></i>${esc(x.name)}</div><div class="x-venue">${esc(x.venue)}</div>
      <div class="x-time">${tz ? fmtTime(serverNow(), tz) : esc(x.local || '')}<span style="font-size:10.5px;color:var(--muted);font-weight:500"> local</span></div>
      <div class="x-state">${STATE_TXT[x.state] || x.state}${when ? ' · <span style="text-transform:none;letter-spacing:0;font-weight:500">' + when + '</span>' : ''}</div>
      <div class="x-live">${x.live}/${x.total} live</div></div>`;
  }).join('');
}

/* ---------- small charts ---------- */
function spark(vals, w = 88, h = 26, color) {
  if (!vals || vals.length < 2) return '';
  const mn = Math.min(...vals), mx = Math.max(...vals), rg = mx - mn || 1;
  const pts = vals.map((v, i) => [i / (vals.length - 1) * (w - 4) + 2, h - 3 - (v - mn) / rg * (h - 6)]);
  const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join('');
  const col = color || (vals[vals.length - 1] >= vals[0] ? COL.call : COL.put);
  const last = pts[pts.length - 1];
  return `<svg class="spark" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true"><path d="${d}L${last[0]} ${h}L2 ${h}Z" fill="${col}" fill-opacity=".1"/><path d="${d}" fill="none" stroke="${col}" stroke-width="1.5" stroke-linejoin="round"/><circle cx="${last[0]}" cy="${last[1]}" r="2.2" fill="${col}"/></svg>`;
}
const STATUS_LBL = { live: 'LIVE', closed: 'CLOSED', break: 'BREAK', quiet: 'NO TRADES', stale: 'UPDATING', pending: 'PENDING' };
const stBadge = r => `<span class="stbadge ${esc(r.status)}">${STATUS_LBL[r.status] || esc(r.status)}</span>`;

/* ---------- tooltip ---------- */
let tipEl = null;
function showTip(html, e) {
  if (!tipEl) { tipEl = document.createElement('div'); tipEl.className = 'tip'; document.body.appendChild(tipEl); }
  tipEl.innerHTML = html; tipEl.hidden = false;
  tipEl.style.left = Math.min(e.clientX + 14, innerWidth - tipEl.offsetWidth - 8) + 'px';
  tipEl.style.top = Math.min(e.clientY + 14, innerHeight - tipEl.offsetHeight - 8) + 'px';
}
const hideTip = () => { if (tipEl) tipEl.hidden = true; };

/* ---------- TradingView ---------- */
const TV_IDX = { '^NSEI': 'NSE:NIFTY', '^NSEBANK': 'NSE:BANKNIFTY', '^BSESN': 'BSE:SENSEX', 'NIFTY_FIN_SERVICE.NS': 'NSE:CNXFINANCE', 'NIFTY_MID_SELECT.NS': 'NSE:NIFTY_MID_SELECT',
  '^CNXIT': 'NSE:CNXIT', '^CNXAUTO': 'NSE:CNXAUTO', '^CNXPHARMA': 'NSE:CNXPHARMA', '^CNXFMCG': 'NSE:CNXFMCG', '^CNXMETAL': 'NSE:CNXMETAL', '^CNXREALTY': 'NSE:CNXREALTY',
  '^CNXENERGY': 'NSE:CNXENERGY', '^CNXPSUBANK': 'NSE:CNXPSUBANK', '^GSPC': 'SP:SPX', '^NDX': 'NASDAQ:NDX', '^IXIC': 'NASDAQ:IXIC', '^DJI': 'DJ:DJI', '^RUT': 'TVC:RUT',
  '^GSPTSE': 'TSX:TSX', '^FTSE': 'TVC:UKX', '^GDAXI': 'XETR:DAX', '^FCHI': 'EURONEXT:PX1', '^AEX': 'EURONEXT:AEX', '^SSMI': 'SIX:SMI', 'FTSEMIB.MI': 'TVC:FTSEMIB',
  '^IBEX': 'BME:IBC', '^STOXX50E': 'TVC:SX5E', '^N225': 'TVC:NI225', '^HSI': 'TVC:HSI', '000001.SS': 'SSE:000001', '000300.SS': 'SSE:000300', '399001.SZ': 'SZSE:399001',
  '^KS11': 'KRX:KOSPI', '^TWII': 'TWSE:TAIEX', '^STI': 'TVC:STI', '^AXJO': 'ASX:XJO', '^BVSP': 'BMFBOVESPA:IBOV', '^MXX': 'BMV:ME', '^TASI.SR': 'TADAWUL:TASI',
  'DX-Y.NYB': 'TVC:DXY', 'ES=F': 'CME_MINI:ES1!', 'NQ=F': 'CME_MINI:NQ1!', 'YM=F': 'CBOT_MINI:YM1!', 'RTY=F': 'CME_MINI:RTY1!', 'NKD=F': 'CME:NKD1!', 'ZN=F': 'CBOT:ZN1!',
  'ZB=F': 'CBOT:ZB1!', 'GC=F': 'COMEX:GC1!', 'SI=F': 'COMEX:SI1!', 'HG=F': 'COMEX:HG1!', 'PL=F': 'NYMEX:PL1!', 'PA=F': 'NYMEX:PA1!', 'CL=F': 'NYMEX:CL1!', 'BZ=F': 'NYMEX:BB1!',
  'NG=F': 'NYMEX:NG1!', 'HO=F': 'NYMEX:HO1!', 'RB=F': 'NYMEX:RB1!', 'ZC=F': 'CBOT:ZC1!', 'ZS=F': 'CBOT:ZS1!', 'ZW=F': 'CBOT:ZW1!', 'KC=F': 'ICEUS:KC1!', 'SB=F': 'ICEUS:SB1!',
  'CT=F': 'ICEUS:CT1!', 'CC=F': 'ICEUS:CC1!', 'LE=F': 'CME:LE1!', 'HE=F': 'CME:HE1!' };
const TV_SUF = { '.NS': 'NSE', '.BO': 'BSE', '.TO': 'TSX', '.L': 'LSE', '.DE': 'XETR', '.PA': 'EURONEXT', '.AS': 'EURONEXT', '.SW': 'SIX', '.MI': 'MIL', '.MC': 'BME',
  '.CO': 'OMXCOP', '.ST': 'OMXSTO', '.T': 'TSE', '.HK': 'HKEX', '.SS': 'SSE', '.SZ': 'SZSE', '.KS': 'KRX', '.TW': 'TWSE', '.SI': 'SGX', '.AX': 'ASX', '.SA': 'BMFBOVESPA',
  '.MX': 'BMV', '.SR': 'TADAWUL', '.JO': 'JSE' };
function tvSymbol(s) {
  if (TV_IDX[s]) return TV_IDX[s];
  if (s.startsWith('MCX:')) return s + '1!';
  if (s.endsWith('=X')) return 'FX_IDC:' + s.slice(0, -2);
  if (s.endsWith('-USD')) return 'CRYPTO:' + s.slice(0, -4).replace(/\d+$/, '') + 'USD';
  const i = s.lastIndexOf('.');
  if (i > 0 && TV_SUF[s.slice(i)]) {
    let base = s.slice(0, i).replace(/-/g, '_');
    if (s.endsWith('.HK')) base = String(+base);
    return TV_SUF[s.slice(i)] + ':' + base;
  }
  return s.replace('-', '.');
}
/** Mounts a TradingView widget. If the embed is blocked (ad-blocker, network) or never starts,
 *  onFail(el) runs; without onFail a short notice replaces the widget. */
function tvWidget(el, kind, cfg, onFail) {
  if (!el) return;
  const tok = el._tv = (el._tv || 0) + 1;
  if (el._lwc) { el._lwc.remove(); el._lwc = null; }
  el.innerHTML = '<div class="tradingview-widget-container"><div class="tradingview-widget-container__widget"></div></div>';
  const s = document.createElement('script');
  s.src = `https://s3.tradingview.com/external-embedding/embed-widget-${kind}.js`; s.async = true;
  s.innerHTML = JSON.stringify(Object.assign({ colorTheme: 'dark', theme: 'dark', locale: 'en', isTransparent: false }, cfg));
  let done = false;
  const fail = () => {
    if (done || el._tv !== tok || el.querySelector('iframe')) return;
    done = true;
    if (onFail) onFail(el);
    else el.innerHTML = '<div class="tv-fail"><b>TradingView could not load here</b><span>This browser or an ad-blocker is blocking tradingview.com widgets. Allow them for this site' + (kind === 'advanced-chart' ? ', or use the ChartPulse chart.' : '.') + '</span></div>';
  };
  s.onerror = fail;
  s.onload = () => setTimeout(fail, 8000);
  setTimeout(fail, 25000);
  el.firstChild.appendChild(s);
}
const TV_INTERVAL = { '1H': '60', '4H': '240', '1D': 'D', '1W': 'W' };

/* ---------- ChartPulse chart (TradingView Lightweight Charts, loaded on demand) ---------- */
const LWC_SRC = ['https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js',
  'https://unpkg.com/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js'];
let lwcP = null;
function loadLwc() {
  if (window.LightweightCharts) return Promise.resolve(window.LightweightCharts);
  return lwcP || (lwcP = new Promise((res, rej) => {
    const add = i => {
      if (i >= LWC_SRC.length) { lwcP = null; rej(new Error('chart library')); return; }
      const s = document.createElement('script'); s.src = LWC_SRC[i];
      s.onload = () => (window.LightweightCharts ? res(window.LightweightCharts) : add(i + 1)); s.onerror = () => add(i + 1);
      document.head.appendChild(s);
    };
    add(0);
  }));
}
const shiftT = t => t - new Date(t * 1000).getTimezoneOffset() * 60;   // bars in the viewer's time zone
/** Supertrend drawn as one line series per trend run, so the line breaks at each flip instead of bridging the gap. */
function stSeries(ch, T, st, dir, pf, old) {
  (old || []).forEach(x => { try { ch.removeSeries(x); } catch (e) { /* already gone */ } });
  const out = []; let a = 0;
  for (let i = 1; i <= T.length; i++) {
    if (i < T.length && dir[i] === dir[a]) continue;
    const s = ch.addLineSeries({ color: dir[a] === 1 ? COL.call : COL.put, lineWidth: 2, lastValueVisible: false, priceLineVisible: false, crosshairMarkerVisible: false, priceFormat: pf });
    const pts = []; for (let k = a; k < i; k++) pts.push({ time: T[k], value: st[k] });
    s.setData(pts); out.push(s); a = i;
  }
  return out;
}
/** Candles + Supertrend + EMA + ATR day zones for one of our symbols, from our own data. */
async function miniChart(el, sym, tf = '1H') {
  if (!el || !sym) return;
  const tok = el._tv = (el._tv || 0) + 1;
  if (el._lwc) { el._lwc.remove(); el._lwc = null; }
  el.innerHTML = '<div class="tv-fail"><span>Loading chart\u2026</span></div>';
  let L, d;
  try { L = await loadLwc(); d = await api('symbol', { sym, tf }); }
  catch (e) {
    if (el._tv === tok) el.innerHTML = `<div class="tv-fail"><b>Chart unavailable</b><span>${L ? 'Data for this symbol is not ready yet. Try again in a minute.' : 'The chart library could not load. Check your connection and reload.'}</span></div>`;
    return;
  }
  if (el._tv !== tok) return;
  el.innerHTML = `<div class="lwc"></div><div class="c-legend mono"></div><a class="cp-badge" href="scanner.html#analysis=${encodeURIComponent(sym)}">Full analysis \u2192</a>`;
  const c = d.candles, r = d.result, n = c.t.length, pf = { type: 'custom', formatter: fmtP, minMove: 1e-9 }, leg = el.children[1];
  const ch = L.createChart(el.firstChild, {
    autoSize: true,
    layout: { background: { type: 'solid', color: COL.bg2 }, textColor: COL.muted, fontFamily: 'JetBrains Mono, Consolas, monospace', fontSize: 11 },
    grid: { vertLines: { color: 'rgba(26,35,49,.55)' }, horzLines: { color: 'rgba(26,35,49,.55)' } },
    rightPriceScale: { borderColor: COL.line2, scaleMargins: { top: 0.1, bottom: 0.2 } },
    timeScale: { borderColor: COL.line2, timeVisible: tf === '1H' || tf === '4H', secondsVisible: false, rightOffset: 6, barSpacing: 7 },
    crosshair: { mode: L.CrosshairMode.Normal }, localization: { priceFormatter: fmtP },
  });
  el._lwc = ch;
  const T = []; c.t.forEach((t, i) => { let x = shiftT(t); if (i && x <= T[i - 1]) x = T[i - 1] + 1; T.push(x); });
  const cs = ch.addCandlestickSeries({ upColor: COL.call, downColor: COL.put, borderVisible: false, wickUpColor: COL.call, wickDownColor: COL.put, priceFormat: pf });
  cs.setData(T.map((t, i) => ({ time: t, open: c.o[i], high: c.h[i], low: c.l[i], close: c.c[i] })));
  const vs = ch.addHistogramSeries({ priceScaleId: 'vol', priceFormat: { type: 'volume' }, lastValueVisible: false, priceLineVisible: false });
  ch.priceScale('vol').applyOptions({ scaleMargins: { top: 0.85, bottom: 0 } });
  vs.setData(T.map((t, i) => ({ time: t, value: c.v[i] || 0, color: c.c[i] >= c.o[i] ? 'rgba(30,211,154,.22)' : 'rgba(255,90,118,.22)' })));
  const line = (color, w) => ch.addLineSeries({ color, lineWidth: w, lastValueVisible: false, priceLineVisible: false, crosshairMarkerVisible: false, priceFormat: pf });
  stSeries(ch, T, c.st, c.dir, pf);
  line(COL.accent, 1).setData(T.map((t, i) => ({ time: t, value: c.ema[i] })));
  const LS = L.LineStyle;
  [[r.atr_low, 'ATR Low', COL.call, 2, LS.Solid], [r.atr_high, 'ATR High', COL.put, 2, LS.Solid], [r.day_high, 'PDH', COL.ink2, 1, LS.Dashed], [r.day_low, 'PDL', COL.ink2, 1, LS.Dashed]]
    .forEach(([price, title, color, lineWidth, lineStyle]) => price && cs.createPriceLine({ price, title, color, lineWidth, lineStyle, axisLabelVisible: true }));
  ch.timeScale().setVisibleLogicalRange({ from: Math.max(0, n - 120), to: n + 4 });
  const fmtT = t => new Date(t * 1000).toLocaleString('en-GB', Object.assign({ timeZone: 'UTC', day: '2-digit', month: 'short' }, tf === '1H' || tf === '4H' ? { hour: '2-digit', minute: '2-digit', hour12: false } : { year: 'numeric' }));
  const legend = p => {
    let i = n - 1; if (p && p.time != null) { const k = T.indexOf(p.time); if (k >= 0) i = k; }
    leg.innerHTML = `<span><b>${esc(r.name)}</b> ${tf} \u00b7 ${fmtT(T[i])}</span><span>O <b>${fmtP(c.o[i])}</b></span><span>H <b>${fmtP(c.h[i])}</b></span><span>L <b>${fmtP(c.l[i])}</b></span><span>C <b class="${cls(c.c[i] - c.o[i])}">${fmtP(c.c[i])}</b></span>` +
      `<span style="color:${c.dir[i] === 1 ? COL.call : COL.put}">ST ${fmtP(c.st[i])}</span><span style="color:${COL.accent}">EMA ${fmtP(c.ema[i])}</span>`;
  };
  ch.subscribeCrosshairMove(legend); legend();
}

window.CP = { $, $$, esc, COL, fmtP, fmtPct, cls, TZ, tzName, fmtTime, fmtDT, fmtDur, api, pollScan, serverNow, syncClock, worldStrip, spark, stBadge, STATUS_LBL,
  showTip, hideTip, tvSymbol, tvWidget, TV_INTERVAL, loadLwc, miniChart, stSeries };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initHeader); else initHeader();
})();
