/* ChartPulse · Home dashboard */
(() => {
'use strict';
const { $, $$, esc, COL, fmtP, fmtPct, cls, spark, stBadge, showTip, hideTip, tvWidget, TZ, tzName, fmtTime } = window.CP;

// old terminal links (chartpulse.io/#heatmap) now live on the scanner page
if (/^#(command|heatmap|analysis|lab|screener)$/.test(location.hash)) location.replace('scanner.html' + location.hash);

const S = { rows: [], by: {}, meta: {}, mv: 'gain' };
const open = sym => { location.href = 'scanner.html#analysis=' + encodeURIComponent(sym); };

const INDICES = ['^NSEI', '^NSEBANK', '^BSESN', '^GSPC', '^NDX', '^DJI', '^GDAXI', '^FTSE', '^N225', '^HSI', '000001.SS', '^STOXX50E', '^KS11', '^AXJO', '^GSPTSE', '^BVSP'];
const FX = ['USDINR=X', 'EURUSD=X', 'GBPUSD=X', 'USDJPY=X', 'AUDUSD=X', 'USDCAD=X', 'USDCHF=X', 'EURINR=X'];
const CMD = ['GC=F', 'SI=F', 'CL=F', 'BZ=F', 'NG=F', 'HG=F', 'MCX:GOLD', 'MCX:CRUDEOIL', 'MCX:SILVER', 'MCX:NATURALGAS'];
const CR = ['BTC-USD', 'ETH-USD', 'SOL-USD', 'XRP-USD', 'BNB-USD', 'DOGE-USD', 'ADA-USD', 'HYPE32196-USD'];

function render(d) {
  S.rows = d.rows || []; S.meta = d.meta || {}; S.fetched = d.fetchedAt; S.by = Object.fromEntries(S.rows.map(r => [r.symbol, r]));
  const rows = S.rows, live = rows.filter(r => r.live), sig = live.filter(r => r.signal !== 'WAIT');
  const ex = S.meta.exchanges || [];
  $('#hUni').textContent = S.meta.universe || rows.length;
  $('#hUniTxt').textContent = (S.meta.universe || rows.length) + '';
  $('#hEx').textContent = ex.length;
  $('#hTz').textContent = tzName() || TZ;
  $('#hLive').textContent = live.length;
  $('#hLiveS').textContent = `of ${rows.length} scanned`;
  $('#hSet').textContent = sig.length;
  $('#hSetS').innerHTML = `<span class="up">${sig.filter(r => r.signal === 'CALL').length} CALL</span> · <span class="down">${sig.filter(r => r.signal === 'PUT').length} PUT</span>`;
  const openN = ex.filter(x => x.state === 'open').length;
  $('#hOpen').textContent = openN;
  $('#hOpenS').textContent = `of ${ex.length} venues`;
  const up = rows.filter(r => r.bias === 'UP').length;
  $('#hBr').textContent = rows.length ? Math.round(up / rows.length * 100) + '%' : '–';
  $('#hBrS').textContent = `${up} up · ${rows.length - up} down`;
  $('#hUpd').textContent = S.meta.progress < 1 ? `Scanning ${Math.round(S.meta.progress * 100)}%` : `Updated ${fmtTime(d.fetchedAt)}`;
  // hero: strongest live setups, else strongest live scores
  const top = (sig.length ? sig : live).sort((a, b) => b.strength - a.strength).slice(0, 4);
  $('#hTop').innerHTML = top.map(r => `<a class="pc-row" href="scanner.html#analysis=${encodeURIComponent(r.symbol)}"><span><b>${esc(r.name)}</b><small>${esc(r.exchange || r.market)}</small></span><span class="mono ${cls(r.change_pct)}">${fmtPct(r.change_pct)}</span><span class="sig ${r.signal}">${r.signal === 'WAIT' ? Math.round(r.strength) : r.signal}</span></a>`).join('')
    || '<div class="empty">Waiting for live markets…</div>';
  CP.worldStrip($('#world'), ex, d.fetchedAt);
  renderIndices(); renderSetups(live); renderBreadth(); renderMovers(); renderBoards();
}

function renderIndices() {
  const list = INDICES.map(s => S.by[s]).filter(Boolean);
  $('#indices').innerHTML = list.map(r => `<a class="icard" href="scanner.html#analysis=${encodeURIComponent(r.symbol)}" data-sym="${esc(r.symbol)}">
    <div class="i-top"><span class="i-name">${esc(r.name)}</span><span class="i-st ${r.live ? 'live' : ''}">${r.live ? 'LIVE' : CP.STATUS_LBL[r.status] || ''}</span></div>
    <div class="i-mid"><span class="i-px">${fmtP(r.price)}</span>${spark(r.spark, 80, 28)}</div>
    <div class="i-foot"><span class="i-chg ${cls(r.change_pct)}">${fmtPct(r.change_pct)}</span><span>1M <span class="${cls(r.ret_1m)}">${fmtPct(r.ret_1m, 1)}</span></span><span class="${r.bias === 'UP' ? 'up' : r.bias === 'DOWN' ? 'down' : ''}">${r.bias === 'UP' ? '▲' : r.bias === 'DOWN' ? '▼' : '■'} ${r.alignment}/4</span></div></a>`).join('')
    || '<div class="empty">Indices appear after the first scan.</div>';
}

function renderSetups(live) {
  const sig = live.filter(r => r.signal !== 'WAIT').sort((a, b) => b.strength - a.strength).slice(0, 6);
  const near = live.filter(r => r.signal === 'WAIT' && r.bias !== 'NEUTRAL' && Math.abs(r.zone_dist_atr) <= 0.6 && !r.overextended).sort((a, b) => Math.abs(a.zone_dist_atr) - Math.abs(b.zone_dist_atr)).slice(0, 6 - sig.length);
  const row = (r, lbl) => `<a class="pc-row" href="scanner.html#analysis=${encodeURIComponent(r.symbol)}"><span><b>${esc(r.name)}</b><small>${esc(r.zone_near)} · ${r.alignment}/4 · ${lbl}</small></span><span class="mono">${fmtP(r.price)}</span><span class="sig ${r.signal}">${r.signal === 'WAIT' ? 'NEAR' : r.signal}</span></a>`;
  $('#setupsHome').innerHTML = sig.map(r => row(r, 'score ' + Math.round(r.strength))).join('') + near.map(r => row(r, `${Math.abs(r.zone_dist_atr).toFixed(2)} ATR away`)).join('')
    || '<div class="empty">No live instrument is at or near its ATR zone right now.</div>';
}

function renderBreadth() {
  const g = {};
  S.rows.forEach(r => (g[r.market] = g[r.market] || []).push(r));
  const list = Object.entries(g).map(([m, v]) => ({ m, n: v.length, up: v.filter(r => r.bias === 'UP').length / v.length * 100, ch: v.reduce((a, r) => a + (r.change_pct || 0), 0) / v.length, live: v.filter(r => r.live).length }))
    .sort((a, b) => b.ch - a.ch);
  const mx = Math.max(0.5, ...list.map(x => Math.abs(x.ch)));
  $('#breadth').innerHTML = list.map(x => {
    const w = Math.abs(x.ch) / mx * 50, left = x.ch >= 0 ? 50 : 50 - w;
    return `<div class="dbar-row" data-m="${esc(x.m)}" data-t="${x.n} instruments · ${x.live} live · ${x.up.toFixed(0)}% bias up · avg day ${fmtPct(x.ch)}"><span class="n">${esc(x.m)}</span>
      <span class="t"><i style="left:${left}%;width:${Math.max(w, 0.6)}%;background:${x.ch >= 0 ? COL.call : COL.put}"></i></span><span class="v ${cls(x.ch)}">${fmtPct(x.ch)}</span><span class="b">${x.up.toFixed(0)}% ▲</span></div>`;
  }).join('');
  $$('#breadth .dbar-row').forEach(el => { el.addEventListener('mousemove', e => showTip(`<b>${esc(el.dataset.m)}</b><br>${esc(el.dataset.t)}`, e)); el.addEventListener('mouseleave', hideTip); });
}

function renderMovers() {
  const sel = $('#mvMkt'), cur = sel.value, ms = [...new Set(S.rows.map(r => r.market))];
  sel.innerHTML = '<option value="ALL">All markets</option>' + ms.map(m => `<option>${esc(m)}</option>`).join('');
  sel.value = ms.includes(cur) ? cur : 'ALL';
  const liveOnly = $('#mvLive').checked, mk = sel.value;
  let rows = S.rows.filter(r => (!liveOnly || r.live) && (mk === 'ALL' || r.market === mk) && r.change_pct != null);
  rows.sort(S.mv === 'gain' ? (a, b) => b.change_pct - a.change_pct : S.mv === 'lose' ? (a, b) => a.change_pct - b.change_pct : (a, b) => b.strength - a.strength);
  rows = rows.slice(0, 12);
  $('#movers').innerHTML = `<thead><tr><th>Symbol</th><th>Market</th><th>Status</th><th class="num">Price</th><th class="num">Day</th><th class="num">1M</th><th>Bias</th><th>Signal</th><th>30d</th></tr></thead><tbody>` +
    (rows.map(r => `<tr data-sym="${esc(r.symbol)}"><td class="sym-cell"><b>${esc(r.name)}</b><small>${esc(r.symbol)}</small></td><td>${esc(r.market)}</td><td>${stBadge(r)}</td>
      <td class="num">${fmtP(r.price)}</td><td class="num ${cls(r.change_pct)}">${fmtPct(r.change_pct)}</td><td class="num ${cls(r.ret_1m)}">${fmtPct(r.ret_1m, 1)}</td>
      <td class="${r.bias === 'UP' ? 'up' : r.bias === 'DOWN' ? 'down' : ''}">${r.bias} ${r.alignment}/4</td><td><span class="sig ${r.signal}">${r.signal}</span></td><td>${spark(r.spark)}</td></tr>`).join('')
      || '<tr><td colspan="9" style="text-align:center;color:var(--muted);padding:22px">Nothing matches. Untick "Live only" to include closed markets.</td></tr>') + '</tbody>';
  $$('#movers tbody tr[data-sym]').forEach(tr => tr.addEventListener('click', () => open(tr.dataset.sym)));
}

function board(el, syms) {
  const rows = syms.map(s => S.by[s]).filter(Boolean);
  el.innerHTML = `<thead><tr><th>Name</th><th class="num">Price</th><th class="num">Day</th><th class="num">Score</th></tr></thead><tbody>` +
    rows.map(r => `<tr data-sym="${esc(r.symbol)}"><td class="nm"><b>${esc(r.name)}</b><small>${r.live ? '<span class="up">● live</span>' : esc(CP.STATUS_LBL[r.status] || '').toLowerCase()}</small></td><td class="num">${fmtP(r.price)}</td>
      <td class="num ${cls(r.change_pct)}">${fmtPct(r.change_pct)}</td><td class="num"><span class="sig ${r.signal}" style="padding:1px 7px">${r.signal === 'WAIT' ? Math.round(r.strength) : r.signal}</span></td></tr>`).join('') + '</tbody>';
  $$('tbody tr', el).forEach(tr => tr.addEventListener('click', () => open(tr.dataset.sym)));
}
function renderBoards() { board($('#bFx'), FX); board($('#bCmd'), CMD); board($('#bCr'), CR); }

$$('#mvTab button').forEach(b => b.addEventListener('click', () => { S.mv = b.dataset.k; $$('#mvTab button').forEach(x => x.classList.toggle('on', x === b)); renderMovers(); }));
$('#mvMkt').addEventListener('change', renderMovers);
$('#mvLive').addEventListener('change', renderMovers);

// TradingView chart; falls back to the ChartPulse chart when the embed is blocked
const tvTz = TZ;
const TV2Y = { 'NSE:NIFTY': '^NSEI', 'BSE:SENSEX': '^BSESN', 'FOREXCOM:SPXUSD': '^GSPC', 'FOREXCOM:NSXUSD': '^NDX', 'OANDA:XAUUSD': 'GC=F', 'BITSTAMP:BTCUSD': 'BTC-USD', 'FX:EURUSD': 'EURUSD=X' };
let tvOk = true;
function useOwn(el, sym) {
  tvOk = false;
  $('.tv-copy').innerHTML = 'ChartPulse chart (hourly): Supertrend, EMA and ATR day zones from our own data. TradingView\'s embed is blocked in this browser. Charting by <a href="https://www.tradingview.com/" target="_blank" rel="noopener">TradingView</a> Lightweight Charts.';
  CP.miniChart(el, TV2Y[sym], '1H');
}
function mountTv(sym) {
  if (!tvOk) return useOwn($('#tvHome'), sym);
  tvWidget($('#tvHome'), 'advanced-chart', { autosize: true, symbol: sym, interval: '60', timezone: tvTz, style: '1', allow_symbol_change: true, hide_side_toolbar: false, withdateranges: true, backgroundColor: 'rgba(9,13,19,1)', gridColor: 'rgba(26,35,49,0.6)', support_host: 'https://www.tradingview.com' }, el => useOwn(el, sym));
}
$$('#tvPick button').forEach(b => b.addEventListener('click', () => { $$('#tvPick button').forEach(x => x.classList.toggle('on', x === b)); mountTv(b.dataset.s); }));
mountTv($('#tvPick button.on').dataset.s);

CP.pollScan((d, err) => { if (d) render(d); else $('#hUpd').textContent = 'Server not reachable, retrying…'; }, { minGap: 20 });
setInterval(() => { if (S.meta.exchanges) CP.worldStrip($('#world'), S.meta.exchanges, S.fetched); }, 30000);
})();
