/* ChartPulse · Market insights */
(() => {
'use strict';
const { $, $$, esc, COL, fmtP, fmtPct, cls, showTip, hideTip, tvWidget, TZ, tzName, fmtTime } = window.CP;
const S = { rows: [], meta: {}, perf: 'ret_1m', vol: 'atr_pct', fetched: 0 };
const openSym = sym => { location.href = 'scanner.html#analysis=' + encodeURIComponent(sym); };
const scoped = () => { const s = $('#scope').value; return S.rows.filter(r => s === 'ALL' || r.market === s); };
const TF = ['1H', '4H', '1D', '1W'];

function render(d) {
  S.rows = d.rows || []; S.meta = d.meta || {}; S.fetched = d.fetchedAt;
  const sel = $('#scope'), cur = sel.value, ms = [...new Set(S.rows.map(r => r.market))];
  sel.innerHTML = '<option value="ALL">All markets</option>' + ms.map(m => `<option>${esc(m)}</option>`).join('');
  sel.value = ms.includes(cur) ? cur : 'ALL';
  $('#iUpd').textContent = S.meta.progress < 1 ? `Scanning ${Math.round(S.meta.progress * 100)}%` : `Updated ${fmtTime(d.fetchedAt)} ${tzName()}`;
  renderAll();
}
function renderAll() { kpis(); matrix(); timeline(); perf(); extremes(); vol(); rsi(); strong(); sectors(); }

function kpis() {
  const r = scoped(), n = r.length || 1, live = r.filter(x => x.live);
  const up = r.filter(x => x.bias === 'UP').length, trend = r.filter(x => x.adx_value >= 25).length;
  const atr = live.length ? live.reduce((a, x) => a + x.atr_pct, 0) / live.length : 0;
  const hi = r.filter(x => x.from_hi_pct != null && x.from_hi_pct >= -3).length, lo = r.filter(x => x.from_lo_pct != null && x.from_lo_pct <= 3).length;
  const t = [
    { l: 'Live now', v: live.length, s: `of ${r.length} in scope` },
    { l: 'Breadth', v: Math.round(up / n * 100) + '%', s: `${up} bias up · ${r.length - up} down`, c: up / n >= 0.6 ? 'call' : up / n <= 0.4 ? 'put' : '' },
    { l: 'Trending', v: Math.round(trend / n * 100) + '%', s: 'daily ADX ≥ 25' },
    { l: 'Avg ATR %', v: atr.toFixed(2), s: 'live instruments, daily' },
    { l: 'Near 52w high', v: hi, s: 'within 3%', c: 'call' },
    { l: 'Near 52w low', v: lo, s: 'within 3%', c: 'put' },
  ];
  $('#iKpis').innerHTML = t.map(x => `<div class="kpi ${x.c || ''}"><div class="k-lbl">${x.l}</div><div class="k-val">${x.v}</div><div class="k-sub">${x.s}</div></div>`).join('');
}

// diverging colour: red (0%) - neutral (50%) - green (100%)
function heat(p) {
  const a = Math.min(1, Math.abs(p - 50) / 50);
  const rgb = p >= 50 ? '30,211,154' : '255,90,118';
  return `background:rgba(${rgb},${(0.1 + a * 0.55).toFixed(3)});color:${a > 0.55 ? '#06080C' : 'var(--ink)'}`;
}
function matrix() {
  const g = {};
  S.rows.forEach(r => (g[r.market] = g[r.market] || []).push(r));
  const all = { m: 'All markets', v: S.rows };
  const list = [all, ...Object.entries(g).map(([m, v]) => ({ m, v }))];
  $('#matrix').innerHTML = `<thead><tr><th>Market</th>${TF.map(k => `<th>${k}</th>`).join('')}<th>4/4 up</th><th>4/4 down</th></tr></thead><tbody>` +
    list.map(({ m, v }) => {
      const n = v.length || 1;
      const cells = TF.map(k => { const p = v.filter(r => r.tf[k] === 1).length / n * 100; return `<td style="${heat(p)}" data-t="${esc(m)} · ${k}: ${p.toFixed(0)}% of ${v.length} in uptrend">${p.toFixed(0)}%</td>`; }).join('');
      const a4u = v.filter(r => TF.every(k => r.tf[k] === 1)).length, a4d = v.filter(r => TF.every(k => r.tf[k] === -1)).length;
      return `<tr><td class="m">${esc(m)}<small>${v.length} instruments</small></td>${cells}<td class="up">${a4u}</td><td class="down">${a4d}</td></tr>`;
    }).join('') + '</tbody>';
  $$('#matrix td[data-t]').forEach(td => { td.addEventListener('mousemove', e => showTip(esc(td.dataset.t), e)); td.addEventListener('mouseleave', hideTip); });
}

function timeline() {
  const ex = S.meta.exchanges || []; if (!ex.length) return;
  const now = CP.serverNow();
  const d0 = new Date(now * 1000); d0.setHours(0, 0, 0, 0); const a = d0.getTime() / 1000, b = a + 86400;
  $('#tlSub').textContent = `Today in your time (${tzName()})`;
  const pct = t => (Math.min(b, Math.max(a, t)) - a) / 86400 * 100;
  let axis = ''; for (let h = 0; h <= 24; h += 3) axis += `<span style="left:${h / 24 * 100}%">${String(h % 24).padStart(2, '0')}:00</span>`;
  const rows = ex.map(x => {
    const bars = (x.iv || []).filter(([s, e]) => e > a && s < b).map(([s, e]) => `<b style="left:${pct(s)}%;width:${Math.max(0.4, pct(e) - pct(s))}%" data-t="${esc(x.name)} ${fmtTime(s)}–${fmtTime(e)}"></b>`).join('');
    return `<div class="tl-row ${x.state === 'open' ? 'open' : ''}"><span class="n"><i></i>${esc(x.name)}</span><div class="tl-track">${bars}</div></div>`;
  }).join('');
  const nowX = pct(now);
  $('#timeline').innerHTML = `<div class="tl-axis">${axis}</div>${rows}<div class="tl-now" style="left:calc(150px + (100% - 150px) * ${nowX / 100})"><span>${fmtTime(now)}</span></div>`;
  if (innerWidth <= 720) $('#timeline .tl-now').style.left = `calc(106px + (100% - 106px) * ${nowX / 100})`;
  $$('#timeline .tl-track b').forEach(el => { el.addEventListener('mousemove', e => showTip(esc(el.dataset.t), e)); el.addEventListener('mouseleave', hideTip); });
}

function lb(el, rows, key, fmt, colorFn) {
  const mx = Math.max(1e-9, ...rows.map(r => Math.abs(r[key])));
  el.innerHTML = rows.map((r, i) => `<div class="lb-row" data-sym="${esc(r.symbol)}"><span class="r">${i + 1}</span><span class="lb-n"><b>${esc(r.name)}</b><small>${esc(r.market)}${r.live ? ' · <span class="up">live</span>' : ''}</small></span>
    <span class="lb-bar"><i style="width:${Math.abs(r[key]) / mx * 100}%;background:${colorFn(r)}"></i></span><span class="v ${key.startsWith('ret') ? cls(r[key]) : ''}">${fmt(r[key])}</span></div>`).join('')
    || '<div class="empty">Nothing in this scope yet.</div>';
  $$('.lb-row', el).forEach(x => x.addEventListener('click', () => openSym(x.dataset.sym)));
}
function perf() {
  const k = S.perf, r = scoped().filter(x => x[k] != null);
  const top = [...r].sort((a, b) => b[k] - a[k]).slice(0, 10), bot = [...r].sort((a, b) => a[k] - b[k]).slice(0, 10);
  const f = v => fmtPct(v, 1);
  lb($('#perfTop'), top, k, f, x => x[k] >= 0 ? COL.call : COL.put);
  lb($('#perfBot'), bot, k, f, x => x[k] >= 0 ? COL.call : COL.put);
}
function extremes() {
  const r = scoped();
  const hi = r.filter(x => x.from_hi_pct != null && x.from_hi_pct >= -3).sort((a, b) => b.from_hi_pct - a.from_hi_pct).slice(0, 10)
    .map(x => ({ ...x, _d: Math.max(0.05, 3 + x.from_hi_pct) }));
  const lo = r.filter(x => x.from_lo_pct != null && x.from_lo_pct <= 3).sort((a, b) => a.from_lo_pct - b.from_lo_pct).slice(0, 10)
    .map(x => ({ ...x, _d: Math.max(0.05, 3 - x.from_lo_pct) }));
  lb($('#hiList'), hi, '_d', (v) => '', () => COL.call);
  lb($('#loList'), lo, '_d', (v) => '', () => COL.put);
  $$('#hiList .lb-row').forEach((el, i) => { el.querySelector('.v').textContent = fmtPct(hi[i].from_hi_pct, 1); });
  $$('#loList .lb-row').forEach((el, i) => { el.querySelector('.v').textContent = '+' + lo[i].from_lo_pct.toFixed(1) + '%'; });
}
function vol() {
  const k = S.vol, r = scoped().filter(x => x[k] != null && x[k] > 0);
  const f = v => v.toFixed(2) + '%';
  lb($('#volTop'), [...r].sort((a, b) => b[k] - a[k]).slice(0, 10), k, f, () => COL.accent);
  lb($('#volBot'), [...r].sort((a, b) => a[k] - b[k]).slice(0, 10), k, f, () => COL.ai);
}
function rsi() {
  const r = scoped().filter(x => x.rsi != null);
  lb($('#obList'), r.filter(x => x.rsi > 70).sort((a, b) => b.rsi - a.rsi).slice(0, 10), 'rsi', v => v.toFixed(1), () => COL.put);
  lb($('#osList'), r.filter(x => x.rsi < 30).sort((a, b) => a.rsi - b.rsi).slice(0, 10).map(x => ({ ...x, _inv: 100 - x.rsi })), '_inv', v => (100 - v).toFixed(1), () => COL.call);
}
function strong() {
  const r = scoped().filter(x => x.adx_value >= 30 && x.alignment === 4 && x.bias !== 'NEUTRAL').sort((a, b) => b.adx_value - a.adx_value).slice(0, 14);
  $('#strong').innerHTML = `<thead><tr><th>Symbol</th><th>Trend</th><th class="num">ADX</th><th class="num">1M</th><th class="num">Day</th><th>Signal</th></tr></thead><tbody>` +
    (r.map(x => `<tr data-sym="${esc(x.symbol)}"><td class="nm"><b>${esc(x.name)}</b><small>${esc(x.market)}</small></td><td class="${x.bias === 'UP' ? 'up' : 'down'}">${x.bias === 'UP' ? '▲ Up' : '▼ Down'} 4/4</td>
      <td class="num">${x.adx_value.toFixed(1)}</td><td class="num ${cls(x.ret_1m)}">${fmtPct(x.ret_1m, 1)}</td><td class="num ${cls(x.change_pct)}">${fmtPct(x.change_pct)}</td><td><span class="sig ${x.signal}">${x.signal}</span></td></tr>`).join('')
      || '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px">No strong 4/4 trends in this scope.</td></tr>') + '</tbody>';
  $$('#strong tbody tr[data-sym]').forEach(tr => tr.addEventListener('click', () => openSym(tr.dataset.sym)));
}
function sectors() {
  const SEC = ['^NSEI', '^NSEBANK', 'NIFTY_FIN_SERVICE.NS', '^CNXIT', '^CNXAUTO', '^CNXPHARMA', '^CNXFMCG', '^CNXMETAL', '^CNXREALTY', '^CNXENERGY', '^CNXPSUBANK', 'NIFTY_MID_SELECT.NS'];
  const by = Object.fromEntries(S.rows.map(r => [r.symbol, r]));
  const list = SEC.map(s => by[s]).filter(Boolean).sort((a, b) => b.change_pct - a.change_pct);
  const mx = Math.max(0.5, ...list.map(x => Math.abs(x.change_pct)));
  $('#sectors').innerHTML = list.map(x => {
    const w = Math.abs(x.change_pct) / mx * 50, left = x.change_pct >= 0 ? 50 : 50 - w;
    return `<div class="dbar-row" data-sym="${esc(x.symbol)}" style="cursor:pointer"><span class="n">${esc(x.name)}</span><span class="t"><i style="left:${left}%;width:${Math.max(w, 0.6)}%;background:${x.change_pct >= 0 ? COL.call : COL.put}"></i></span>
      <span class="v ${cls(x.change_pct)}">${fmtPct(x.change_pct)}</span><span class="b ${cls(x.ret_1m)}">1M ${fmtPct(x.ret_1m, 1)}</span></div>`;
  }).join('') || '<div class="empty">Sector indices appear after the first scan.</div>';
  $$('#sectors .dbar-row').forEach(el => el.addEventListener('click', () => openSym(el.dataset.sym)));
}

$('#scope').addEventListener('change', renderAll);
$$('#perfTab button').forEach(b => b.addEventListener('click', () => { S.perf = b.dataset.k; $$('#perfTab button').forEach(x => x.classList.toggle('on', x === b)); perf(); }));
$$('#volTab button').forEach(b => b.addEventListener('click', () => { S.vol = b.dataset.k; $$('#volTab button').forEach(x => x.classList.toggle('on', x === b)); vol(); }));
tvWidget($('#calendar'), 'events', { width: '100%', height: '100%', importanceFilter: '0,1', countryFilter: 'in,us,eu,gb,jp,cn,au,ca,de', isTransparent: true });
CP.pollScan((d) => { if (d) render(d); }, { minGap: 30 });
setInterval(timeline, 60000);
})();
