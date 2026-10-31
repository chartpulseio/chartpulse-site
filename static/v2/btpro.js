/* ChartPulse - Pro Backtesting page.
 * Everything that decides a number lives on the server (backtest.php); this file only
 * builds the strategy JSON, posts it, and draws what comes back.
 */
(function () {
'use strict';

var API = '/backtest.php';
var LS = 'cp_bt_v2';
var meta = null, IND = {}, state = null, lastRun = null, busy = false;

/* ------------------------------------------------------------------ utils */
function $(id) { return document.getElementById(id); }
function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
  });
}
function clone(o) { return JSON.parse(JSON.stringify(o)); }
function num(v) { if (v === '' || v === null || v === undefined) return null; var n = +v; return isFinite(n) ? n : null; }
function fmt(v, dp, suffix) {
  if (v === null || v === undefined || !isFinite(v)) return '—';
  var s = (+v).toFixed(dp === undefined ? 2 : dp);
  return s + (suffix || '');
}
function money(v) {
  if (v === null || v === undefined || !isFinite(v)) return '—';
  return (+v).toLocaleString('en-US', { maximumFractionDigits: 0 });
}
function signClass(v) { return (v === null || v === undefined || !isFinite(v)) ? '' : (v > 0 ? 'up' : (v < 0 ? 'down' : '')); }
function ymd(d) { return d.toISOString().slice(0, 10); }
function tsDate(ts) { return new Date((+ts) * 1000); }
function tsShort(ts) {
  var d = tsDate(ts);
  return d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0');
}
function tsLong(ts) {
  var d = tsDate(ts), s = d.toISOString();
  return s.slice(0, 10) + (s.slice(11, 16) === '00:00' ? '' : ' ' + s.slice(11, 16));
}
function save() { try { localStorage.setItem(LS, JSON.stringify(state)); } catch (e) {} }
function load() { try { var s = localStorage.getItem(LS); return s ? JSON.parse(s) : null; } catch (e) { return null; } }

function post(action, body) {
  return fetch(API + '?a=' + action, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
  }).then(function (r) {
    return r.json().catch(function () { return { error: 'The server did not answer properly. Please try again.' }; })
      .then(function (j) {
        if (!r.ok || j.error) throw new Error(j.error || 'Request failed (' + r.status + ')');
        return j;
      });
  });
}

/* --------------------------------------------------------------- defaults */
var BUCKETS = [
  ['entry_long', 'Long entry', 'long', 'all rules must be true'],
  ['exit_long', 'Long exit', 'long', 'leaves the long'],
  ['entry_short', 'Short entry', 'short', 'all rules must be true'],
  ['exit_short', 'Short exit', 'short', 'leaves the short']
];
var SLOTS = [['period', 'period'], ['period2', '2nd period'], ['period3', '3rd period'], ['mult', 'multiplier'], ['value', 'level']];
var RISK_FIELDS = [
  ['stop_loss_pct', 'rSlPct'], ['stop_loss_atr', 'rSlAtr'],
  ['take_profit_pct', 'rTpPct'], ['take_profit_atr', 'rTpAtr'],
  ['trail_pct', 'rTrPct'], ['trail_atr', 'rTrAtr'],
  ['max_bars', 'rMaxBars'], ['atr_period', 'rAtrP']
];

function blankState() {
  var to = new Date(), from = new Date();
  from.setFullYear(from.getFullYear() - 5);
  return {
    symbol: 'AAPL', custom: '', tf: '1d', from: ymd(from), to: ymd(to),
    capital: 10000, sizeMode: 'all', size: 100, comm: 0.1, slip: 0.05,
    preset: 'ema_cross',
    rules: { entry_long: [], exit_long: [], entry_short: [], exit_short: [] },
    risk: {}, wf: { train: 400, test: 100, score: 'sharpe', picks: {} }
  };
}

/* ------------------------------------------------------------------- boot */
function boot() {
  fetch(API + '?a=meta').then(function (r) { return r.json(); }).then(function (j) {
    meta = j;
    (meta.indicators || []).forEach(function (g) {
      g.items.forEach(function (it) { it.group = g.group; IND[it.id] = it; });
    });
    state = load() || blankState();
    if (!state.rules) state = blankState();
    buildSymbols(); buildTimeframes(); buildPresets();
    if (!state.rules.entry_long.length && !state.rules.entry_short.length) applyPreset(state.preset || 'ema_cross');
    bindSettings();
    renderBuckets(); renderRisk(); renderWfGrid();
  }).catch(function () {
    $('resBody').innerHTML = '<div class="btp-err">The backtesting service is unavailable right now. ' +
      'Please try again shortly.</div>';
  });
}

/* --------------------------------------------------------------- controls */
function buildSymbols() {
  var s = $('symSel'), html = '';
  (meta.markets || []).forEach(function (grp) {
    html += '<optgroup label="' + esc(grp[0]) + '">';
    grp[1].forEach(function (p) {
      html += '<option value="' + esc(p[0]) + '">' + esc(p[1]) + '  ·  ' + esc(p[0]) + '</option>';
    });
    html += '</optgroup>';
  });
  s.innerHTML = html;
  s.value = state.symbol;
  if (s.value !== state.symbol) { $('symIn').value = state.symbol; }
  s.onchange = function () { state.symbol = s.value; $('symIn').value = ''; state.custom = ''; save(); };
  $('symIn').value = state.custom || '';
  $('symIn').oninput = function () { state.custom = this.value.trim().toUpperCase(); save(); };
}

function buildTimeframes() {
  var s = $('tfSel'), html = '';
  (meta.timeframes || []).forEach(function (t) {
    html += '<option value="' + esc(t[0]) + '">' + esc(t[1]) + '</option>';
  });
  s.innerHTML = html;
  s.value = state.tf;
  function note() {
    var t = (meta.timeframes || []).filter(function (x) { return x[0] === s.value; })[0];
    $('tfNote').textContent = t ? 'Provider history on this timeframe: ' + t[2] + '.' : '';
  }
  s.onchange = function () { state.tf = s.value; note(); save(); renderBuckets(); };
  note();
}

function buildPresets() {
  var box = $('presetChips');
  box.innerHTML = '';
  (meta.presets || []).forEach(function (p) {
    var b = el('<button class="btp-chip" data-id="' + esc(p.id) + '">' + esc(p.name) + '</button>');
    b.onclick = function () { applyPreset(p.id); };
    box.appendChild(b);
  });
  var custom = el('<button class="btp-chip" data-id="custom">Blank canvas</button>');
  custom.onclick = function () {
    state.preset = 'custom';
    state.rules = { entry_long: [newRule()], exit_long: [], entry_short: [], exit_short: [] };
    state.risk = {};
    markPreset(); renderBuckets(); renderRisk(); renderWfGrid(); save();
  };
  box.appendChild(custom);
  markPreset();
}

function markPreset() {
  $('presetChips').querySelectorAll('.btp-chip').forEach(function (b) {
    b.classList.toggle('on', b.getAttribute('data-id') === state.preset);
  });
  var p = (meta.presets || []).filter(function (x) { return x.id === state.preset; })[0];
  $('presetAbout').textContent = p ? p.about : (state.preset === 'custom' ? 'Your own rules. Add a long or short entry to begin.' : '');
}

function applyPreset(id) {
  var p = (meta.presets || []).filter(function (x) { return x.id === id; })[0];
  if (!p) return;
  state.preset = id;
  state.rules = { entry_long: [], exit_long: [], entry_short: [], exit_short: [] };
  Object.keys(p.strategy.rules || {}).forEach(function (k) {
    if (state.rules[k]) state.rules[k] = clone(p.strategy.rules[k]);
  });
  state.risk = clone(p.strategy.risk || {});
  state.tf = p.tf || state.tf;
  state.wf.picks = {};
  $('tfSel').value = state.tf;
  $('tfSel').onchange();
  markPreset(); renderBuckets(); renderRisk(); renderWfGrid(); save();
}

function bindSettings() {
  $('sCap').value = state.capital; $('sComm').value = state.comm;
  $('sSlip').value = state.slip; $('sSize').value = state.size;
  $('sSizeMode').value = state.sizeMode;
  $('dFrom').value = state.from; $('dTo').value = state.to;
  $('wfTrain').value = state.wf.train; $('wfTest').value = state.wf.test;
  $('wfScore').value = state.wf.score;

  function bind(id, key, parse) {
    $(id).oninput = function () { state[key] = parse ? parse(this.value) : this.value; save(); };
  }
  bind('sCap', 'capital', num); bind('sComm', 'comm', num);
  bind('sSlip', 'slip', num); bind('sSize', 'size', num);
  bind('dFrom', 'from'); bind('dTo', 'to');
  $('sSizeMode').onchange = function () { state.sizeMode = this.value; sizeLabel(); save(); };
  $('wfTrain').oninput = function () { state.wf.train = num(this.value); save(); };
  $('wfTest').oninput = function () { state.wf.test = num(this.value); save(); };
  $('wfScore').onchange = function () { state.wf.score = this.value; save(); };
  sizeLabel();

  $('rangeChips').querySelectorAll('.btp-chip').forEach(function (b) {
    b.onclick = function () {
      var to = new Date(), from = new Date();
      if (b.dataset.years) from.setFullYear(from.getFullYear() - (+b.dataset.years));
      else from.setDate(from.getDate() - (+b.dataset.days));
      state.from = ymd(from); state.to = ymd(to);
      $('dFrom').value = state.from; $('dTo').value = state.to; save();
    };
  });

  $('runBtn').onclick = runBacktest;
  if ($('runBtn2')) $('runBtn2').onclick = runBacktest;
  $('wfBtn').onclick = runWalkForward;
  $('copyBtn').onclick = function () {
    var txt = JSON.stringify(strategyJson(), null, 2);
    if (navigator.clipboard) navigator.clipboard.writeText(txt).then(function () { flash($('copyBtn'), 'Copied'); });
    else flash($('copyBtn'), 'Copy failed');
  };
  $('pasteBtn').onclick = function () {
    var txt = window.prompt('Paste a strategy JSON (rules + risk):');
    if (!txt) return;
    try {
      var j = JSON.parse(txt);
      if (!j.rules) throw new Error('no rules');
      state.rules = { entry_long: [], exit_long: [], entry_short: [], exit_short: [] };
      Object.keys(j.rules).forEach(function (k) { if (state.rules[k]) state.rules[k] = j.rules[k] || []; });
      state.risk = j.risk || {};
      state.preset = 'custom'; state.wf.picks = {};
      markPreset(); renderBuckets(); renderRisk(); renderWfGrid(); save();
    } catch (e) { window.alert('That JSON could not be read.'); }
  };
  $('resetBtn').onclick = function () {
    state = blankState();
    try { localStorage.removeItem(LS); } catch (e) {}
    buildSymbols(); buildTimeframes(); applyPreset('ema_cross'); bindSettings();
  };
}

function sizeLabel() {
  var m = state.sizeMode, w = $('sizeWrap');
  w.style.display = m === 'all' ? 'none' : '';
  w.querySelector('span').textContent = m === 'risk' ? 'Risk per trade %' : 'Equity used %';
  if (m === 'risk' && !hasStop()) $('sSize').title = 'Risk sizing needs a stop loss';
}
function hasStop() {
  return state.risk.stop_loss_pct != null || state.risk.stop_loss_atr != null;
}
function flash(btn, txt) {
  var old = btn.textContent; btn.textContent = txt;
  setTimeout(function () { btn.textContent = old; }, 1200);
}

/* ---------------------------------------------------------- rule building */
function newRule() {
  return { left: { ind: 'close' }, op: '>', right: { ind: 'ema', period: 20 } };
}

function indOptions(sel) {
  var html = '<option value="__num"' + (sel === '__num' ? ' selected' : '') + '>Number…</option>';
  (meta.indicators || []).forEach(function (g) {
    html += '<optgroup label="' + esc(g.group) + '">';
    g.items.forEach(function (it) {
      html += '<option value="' + esc(it.id) + '"' + (sel === it.id ? ' selected' : '') + '>' + esc(it.label) + '</option>';
    });
    html += '</optgroup>';
  });
  return html;
}

function tfOptions(sel) {
  var html = '<option value="">same TF</option>';
  (meta.htf || []).forEach(function (t) {
    html += '<option value="' + esc(t) + '"' + (sel === t ? ' selected' : '') + '>' + esc(t) + '</option>';
  });
  return html;
}

function srcOptions(sel) {
  var opts = [['', 'close'], ['open', 'open'], ['high', 'high'], ['low', 'low'],
              ['hl2', 'hl2'], ['hlc3', 'hlc3'], ['ohlc4', 'ohlc4']];
  return opts.map(function (o) {
    return '<option value="' + o[0] + '"' + (sel === o[0] || (!sel && !o[0]) ? ' selected' : '') + '>' + o[1] + '</option>';
  }).join('');
}

function operandNode(op, onStructure) {
  var isNum = op.value !== undefined && op.value !== null;
  var box = el('<div class="btp-operand"></div>');
  var sel = el('<select class="oInd">' + indOptions(isNum ? '__num' : op.ind) + '</select>');
  box.appendChild(sel);

  if (isNum) {
    var vi = el('<input type="number" step="any" value="' + esc(op.value) + '">');
    vi.oninput = function () { op.value = num(this.value); save(); renderWfGrid(); };
    box.appendChild(vi);
  } else {
    var m = IND[op.ind] || {};
    [['p', 'period'], ['p2', 'period2'], ['p3', 'period3']].forEach(function (pair) {
      if (!m[pair[0]]) return;
      var i = el('<input type="number" step="1" min="1" title="' + esc(m[pair[0]]) + '" value="' +
        esc(op[pair[1]] != null ? op[pair[1]] : (m[pair[0] + 'd'] || '')) + '">');
      i.oninput = function () { op[pair[1]] = num(this.value); save(); renderWfGrid(); };
      box.appendChild(i);
    });
    if (m.m) {
      var mi = el('<input type="number" step="0.1" min="0.1" title="' + esc(m.m) + '" value="' +
        esc(op.mult != null ? op.mult : (m.md || '')) + '">');
      mi.oninput = function () { op.mult = num(this.value); save(); renderWfGrid(); };
      box.appendChild(mi);
    }
    if (m.src) {
      var ss = el('<select class="tf" title="price source">' + srcOptions(op.src) + '</select>');
      ss.onchange = function () { op.src = this.value || null; save(); };
      box.appendChild(ss);
    }
    var tf = el('<select class="tf" title="higher timeframe">' + tfOptions(op.tf) + '</select>');
    tf.onchange = function () { op.tf = this.value || null; save(); };
    box.appendChild(tf);
  }

  sel.onchange = function () {
    var v = this.value;
    Object.keys(op).forEach(function (k) { delete op[k]; });
    if (v === '__num') op.value = 0;
    else {
      op.ind = v;
      var mm = IND[v] || {};
      if (mm.p) op.period = mm.pd || 14;
      if (mm.p2) op.period2 = mm.p2d;
      if (mm.p3) op.period3 = mm.p3d;
      if (mm.m) op.mult = mm.md;
    }
    save(); onStructure();
  };
  return box;
}

function renderBuckets() {
  var host = $('buckets');
  host.innerHTML = '';
  BUCKETS.forEach(function (b) {
    var key = b[0], list = state.rules[key] || (state.rules[key] = []);
    var card = el('<div class="btp-bucket ' + b[2] + '"><h3>' + esc(b[1]) +
      '<span class="hint">' + esc(b[3]) + '</span></h3><div></div></div>');
    var body = card.querySelector('div');

    list.forEach(function (rule, i) {
      var row = el('<div class="btp-rule"></div>');
      row.appendChild(operandNode(rule.left, renderBuckets));
      var ops = (meta.ops || []).map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (rule.op === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
      }).join('');
      var opSel = el('<select class="btp-op">' + ops + '</select>');
      opSel.onchange = function () { rule.op = this.value; save(); };
      row.appendChild(opSel);
      var x = el('<button class="btp-x" title="remove rule">&times;</button>');
      x.onclick = function () { list.splice(i, 1); state.preset = 'custom'; markPreset(); renderBuckets(); renderWfGrid(); save(); };
      row.appendChild(x);
      row.appendChild(operandNode(rule.right, renderBuckets));
      body.appendChild(row);
    });

    var add = el('<button class="btp-btn sm ghost" style="margin-top:8px">+ rule</button>');
    add.onclick = function () {
      list.push(newRule()); state.preset = 'custom'; markPreset(); renderBuckets(); renderWfGrid(); save();
    };
    body.appendChild(add);
    host.appendChild(card);
  });
}

function renderRisk() {
  RISK_FIELDS.forEach(function (f) {
    var v = state.risk[f[0]];
    $(f[1]).value = (v === null || v === undefined) ? '' : v;
    $(f[1]).oninput = function () {
      var n = num(this.value);
      if (n === null) delete state.risk[f[0]]; else state.risk[f[0]] = n;
      sizeLabel(); save(); renderWfGrid();
    };
  });
}

/* ------------------------------------------------------------- strategy IO */
function cleanOperand(o) {
  var out = {};
  if (o.value !== undefined && o.value !== null && o.value !== '') { out.value = +o.value; return out; }
  out.ind = o.ind;
  ['period', 'period2', 'period3', 'mult'].forEach(function (k) {
    if (o[k] !== undefined && o[k] !== null && o[k] !== '') out[k] = +o[k];
  });
  if (o.src) out.src = o.src;
  if (o.tf) out.tf = o.tf;
  return out;
}

function strategyJson() {
  var rules = {};
  Object.keys(state.rules).forEach(function (k) {
    rules[k] = (state.rules[k] || []).map(function (r) {
      return { left: cleanOperand(r.left), op: r.op, right: cleanOperand(r.right) };
    });
  });
  var risk = {};
  Object.keys(state.risk).forEach(function (k) {
    if (state.risk[k] !== null && state.risk[k] !== undefined && state.risk[k] !== '') risk[k] = +state.risk[k];
  });
  if (state.sizeMode === 'risk' && state.size) risk.risk_pct = +state.size;
  return { rules: rules, risk: risk };
}

function settingsJson() {
  return {
    symbol: (state.custom || state.symbol || '').toUpperCase(),
    timeframe: state.tf,
    start: state.from, end: state.to,
    capital: +state.capital || 10000,
    commission: (+state.comm || 0) / 100,
    slippage: (+state.slip || 0) / 100,
    size: state.sizeMode === 'pct' ? Math.min(1, Math.max(0.01, (+state.size || 100) / 100)) : 1
  };
}

/* ---------------------------------------------------------------- running */
function setBusy(btn, on, label) {
  busy = on;
  $('runBtn').disabled = on; $('wfBtn').disabled = on;
  if ($('runBtn2')) $('runBtn2').disabled = on;
  btn.innerHTML = on ? '<span class="btp-spin"></span> ' + label : label;
}

function showError(msg) {
  $('emptyState').style.display = 'none';
  var v = $('resView');
  v.style.display = '';
  v.innerHTML = '<div class="btp-err">' + esc(msg) + '</div>';
}

function runBacktest() {
  if (busy) return;
  var body = settingsJson();
  body.strategy = strategyJson();
  if (!body.strategy.rules.entry_long.length && !body.strategy.rules.entry_short.length) {
    return showError('Add at least one entry rule — a long entry, a short entry, or both.');
  }
  setBusy($('runBtn'), true, 'Running');
  $('resTitle').textContent = 'Running backtest…';
  post('run', body).then(function (j) {
    lastRun = j;
    renderRun(j);
  }).catch(function (e) {
    $('resTitle').textContent = 'Result';
    showError(e.message);
  }).then(function () { setBusy($('runBtn'), false, 'Run backtest'); });
}

/* ------------------------------------------------------------- render run */
var KPIS = [
  ['total_return_pct', 'Total return', 2, '%', 1],
  ['cagr_pct', 'CAGR', 2, '%', 1],
  ['max_drawdown_pct', 'Max drawdown', 1, '%', 1],
  ['sharpe', 'Sharpe', 2, '', 1],
  ['sortino', 'Sortino', 2, '', 1],
  ['profit_factor', 'Profit factor', 2, '', 1],
  ['win_rate_pct', 'Win rate', 1, '%', 0],
  ['trades', 'Trades', 0, '', 0],
  ['payoff_ratio', 'Payoff ratio', 2, '', 0],
  ['avg_trade_pct', 'Avg trade', 2, '%', 1],
  ['exposure_pct', 'Time in market', 0, '%', 0],
  ['max_consecutive_losses', 'Worst losing run', 0, '', 0]
];

function renderRun(j) {
  var m = j.metrics || {};
  $('resTitle').textContent = j.symbol + ' · ' + j.timeframe;
  var rm = $('resMeta');
  rm.style.display = '';
  rm.textContent = j.bars + ' bars · ' + j.from + ' → ' + j.to + ' · ' + j.source;

  var html = '<div class="btp-kpis">';
  KPIS.forEach(function (k) {
    var v = m[k[0]], cls = k[4] ? signClass(v) : '';
    html += '<div class="btp-kpi"><span>' + esc(k[1]) + '</span><b class="' + cls + '">' +
      (k[0] === 'trades' || k[0] === 'max_consecutive_losses' ? (v == null ? '—' : v) : fmt(v, k[2], k[3])) +
      '</b></div>';
  });
  html += '</div>';

  if (m.buy_hold_return_pct !== null && m.buy_hold_return_pct !== undefined) {
    var diff = (m.total_return_pct || 0) - m.buy_hold_return_pct;
    html += '<p class="btp-help" style="margin-top:10px">Buy &amp; hold over the same window: <b class="' +
      signClass(m.buy_hold_return_pct) + '">' + fmt(m.buy_hold_return_pct, 2, '%') + '</b> — the strategy is ' +
      (diff >= 0 ? 'ahead by ' : 'behind by ') + fmt(Math.abs(diff), 2, '%') + '.</p>';
  }

  html += '<div class="btp-chartbox"><canvas id="eqChart"></canvas><div class="btp-tip" id="eqTip"></div></div>' +
    '<div class="btp-legend"><span><i style="background:#2962ff"></i>Strategy equity</span>' +
    '<span><i style="background:#8b9bb4"></i>Buy &amp; hold</span>' +
    '<span><i style="background:#ef5350"></i>Drawdown</span></div>' +
    '<div class="btp-chartbox"><canvas id="ddChart" style="height:140px"></canvas></div>';

  if ((j.notes || []).length) {
    html += '<div class="btp-notes">';
    j.notes.forEach(function (n) {
      html += '<div class="btp-note' + (n[0] === 'warn' ? ' warn' : '') + '">' + esc(n[1]) + '</div>';
    });
    html += '</div>';
  }

  html += '<div class="btp-tabs">' +
    '<button class="btp-tab on" data-p="trades">Trades</button>' +
    '<button class="btp-tab" data-p="mc">Monte Carlo</button>' +
    '<button class="btp-tab" data-p="wf">Walk-forward</button></div>' +
    '<div class="btp-pane on" data-p="trades">' + tradesHtml(j) + '</div>' +
    '<div class="btp-pane" data-p="mc">' + mcHtml(j) + '</div>' +
    '<div class="btp-pane" data-p="wf" id="wfPane">' +
      '<div class="btp-empty"><h3>Not run yet</h3><p>Pick the numbers to tune in step 4 on the left, then press <b>Run walk-forward</b>.</p></div></div>';

  $('emptyState').style.display = 'none';
  var v = $('resView');
  v.style.display = '';
  v.innerHTML = html;

  v.querySelectorAll('.btp-tab').forEach(function (t) {
    t.onclick = function () {
      v.querySelectorAll('.btp-tab').forEach(function (x) { x.classList.toggle('on', x === t); });
      v.querySelectorAll('.btp-pane').forEach(function (p) { p.classList.toggle('on', p.dataset.p === t.dataset.p); });
    };
  });
  var dl = $('csvBtn');
  if (dl) dl.onclick = downloadCsv;

  drawEquity(j.equity || []);
  drawDrawdown(j.equity || []);
}

function tradesHtml(j) {
  var t = j.trades || [];
  if (!t.length) return '<div class="btp-empty"><h3>No trades</h3><p>These rules never fired on this data.</p></div>';
  var head = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">' +
    '<span class="btp-help" style="margin:0">Showing ' + t.length + ' of ' + j.trades_total + ' trades. ' +
    'MFE / MAE are the best and worst the trade ever was, in percent.</span>' +
    '<button class="btp-chip" id="csvBtn" style="margin-left:auto">Download CSV</button></div>';
  var rows = t.map(function (x) {
    return '<tr><td class="t">' + esc(x.entry_time) + '</td><td class="t">' + esc(x.exit_time) + '</td>' +
      '<td>' + (x.side === 'long' ? '<span class="btp-pill target">LONG</span>' : '<span class="btp-pill stop">SHORT</span>') + '</td>' +
      '<td>' + x.entry + '</td><td>' + x.exit + '</td>' +
      '<td class="' + signClass(x.ret_pct) + '">' + fmt(x.ret_pct, 2, '%') + '</td>' +
      '<td class="' + signClass(x.pnl) + '">' + money(x.pnl) + '</td>' +
      '<td>' + x.bars + '</td><td>' + fmt(x.mfe_pct, 2, '%') + '</td><td>' + fmt(x.mae_pct, 2, '%') + '</td>' +
      '<td><span class="btp-pill ' + (x.reason === 'stop' ? 'stop' : (x.reason === 'target' ? 'target' : '')) + '">' +
      esc(x.reason) + '</span></td></tr>';
  }).join('');
  return head + '<div class="btp-scroll"><table class="btp-table"><thead><tr>' +
    '<th>Entry</th><th>Exit</th><th>Side</th><th>In</th><th>Out</th><th>Return</th><th>P&amp;L</th>' +
    '<th>Bars</th><th>MFE</th><th>MAE</th><th>Closed by</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function downloadCsv() {
  if (!lastRun) return;
  var cols = ['entry_time', 'exit_time', 'side', 'entry', 'exit', 'ret_pct', 'pnl', 'bars', 'mfe_pct', 'mae_pct', 'reason'];
  var lines = [cols.join(',')];
  (lastRun.trades || []).forEach(function (t) {
    lines.push(cols.map(function (c) { return '"' + String(t[c]).replace(/"/g, '""') + '"'; }).join(','));
  });
  var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'chartpulse-' + lastRun.symbol + '-' + lastRun.timeframe + '-trades.csv';
  a.click();
  setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
}

function mcHtml(j) {
  var mc = j.monte_carlo;
  if (!mc) return '<div class="btp-empty"><h3>Needs 10+ trades</h3><p>Monte Carlo reshuffles your own trades thousands of times to show how much of the result could be luck. With fewer than ten trades there is nothing to reshuffle.</p></div>';
  var cap = (settingsJson().capital);
  return '<p class="btp-help" style="margin:0 0 12px">' + mc.runs + ' runs, each one your trades drawn again at random and compounded. ' +
    'It answers one question: if the same edge played out in a different order, how bad could it have looked?</p>' +
    '<div class="btp-mc">' +
    '<div><span>Bad case (5th pct) final</span><b class="' + signClass(mc.final_equity_p5 - cap) + '">' + money(mc.final_equity_p5) + '</b></div>' +
    '<div><span>Median final</span><b class="' + signClass(mc.final_equity_median - cap) + '">' + money(mc.final_equity_median) + '</b></div>' +
    '<div><span>Good case (95th pct) final</span><b class="' + signClass(mc.final_equity_p95 - cap) + '">' + money(mc.final_equity_p95) + '</b></div>' +
    '<div><span>Median drawdown</span><b class="down">' + fmt(mc.median_drawdown_pct, 1, '%') + '</b></div>' +
    '<div><span>Bad-case drawdown</span><b class="down">' + fmt(mc.bad_case_drawdown_pct, 1, '%') + '</b></div>' +
    '<div><span>Chance of ending down</span><b>' + fmt(mc.prob_loss_pct, 1, '%') + '</b></div>' +
    '</div>' +
    '<div class="btp-notes"><div class="btp-note">Trades are treated as independent here, so real losing streaks can run longer than this suggests.</div></div>';
}

/* ----------------------------------------------------------------- charts */
function fitCanvas(c) {
  var r = c.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
  c.width = Math.max(1, Math.round(r.width * dpr));
  c.height = Math.max(1, Math.round(r.height * dpr));
  var ctx = c.getContext('2d');
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  return { ctx: ctx, w: r.width, h: r.height };
}

function niceTicks(lo, hi, n) {
  var span = (hi - lo) || 1, step = Math.pow(10, Math.floor(Math.log(span / n) / Math.LN10));
  [1, 2, 2.5, 5, 10].some(function (m) { if (step * m >= span / n) { step = step * m; return true; } return false; });
  var out = [], v = Math.ceil(lo / step) * step;
  for (; v <= hi + step * 0.001 && out.length < 12; v += step) out.push(v);
  return out;
}

function drawEquity(pts) {
  var c = $('eqChart'); if (!c || !pts.length) return;
  var g = fitCanvas(c), ctx = g.ctx, W = g.w, H = g.h;
  var padL = 58, padR = 10, padT = 12, padB = 24;
  var vals = [];
  pts.forEach(function (p) { vals.push(p.v, p.bh); });
  var lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
  if (lo === hi) { lo -= 1; hi += 1; }
  var pad = (hi - lo) * 0.06; lo -= pad; hi += pad;
  var X = function (i) { return padL + (W - padL - padR) * (pts.length < 2 ? 0.5 : i / (pts.length - 1)); };
  var Y = function (v) { return padT + (H - padT - padB) * (1 - (v - lo) / (hi - lo)); };

  ctx.clearRect(0, 0, W, H);
  ctx.font = '10px "IBM Plex Mono", monospace';
  ctx.textBaseline = 'middle';
  niceTicks(lo, hi, 5).forEach(function (t) {
    var y = Y(t);
    ctx.strokeStyle = 'rgba(31,40,56,0.9)'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(W - padR, y); ctx.stroke();
    ctx.fillStyle = '#5e6e86'; ctx.textAlign = 'right';
    ctx.fillText(t >= 1000 ? Math.round(t / 1000) + 'k' : Math.round(t), padL - 7, y);
  });
  [0, Math.floor(pts.length / 2), pts.length - 1].forEach(function (i, k) {
    if (!pts[i]) return;
    ctx.textAlign = k === 0 ? 'left' : (k === 2 ? 'right' : 'center');
    ctx.fillStyle = '#5e6e86';
    ctx.fillText(tsShort(pts[i].t), X(i), H - padB / 2 + 2);
  });

  function line(key, color, width, fill) {
    ctx.beginPath();
    pts.forEach(function (p, i) { i ? ctx.lineTo(X(i), Y(p[key])) : ctx.moveTo(X(i), Y(p[key])); });
    ctx.strokeStyle = color; ctx.lineWidth = width; ctx.lineJoin = 'round'; ctx.stroke();
    if (fill) {
      ctx.lineTo(X(pts.length - 1), Y(lo)); ctx.lineTo(X(0), Y(lo)); ctx.closePath();
      var grd = ctx.createLinearGradient(0, padT, 0, H - padB);
      grd.addColorStop(0, 'rgba(41,98,255,0.22)'); grd.addColorStop(1, 'rgba(41,98,255,0)');
      ctx.fillStyle = grd; ctx.fill();
    }
  }
  line('bh', '#8b9bb4', 1.2, false);
  line('v', '#2962ff', 1.8, true);

  var tip = $('eqTip');
  c.onmousemove = function (ev) {
    var r = c.getBoundingClientRect();
    var i = Math.round((ev.clientX - r.left - padL) / Math.max(1, (W - padL - padR)) * (pts.length - 1));
    i = Math.max(0, Math.min(pts.length - 1, i));
    var p = pts[i];
    tip.innerHTML = tsLong(p.t) + '<br>equity ' + money(p.v) + '<br>b&amp;h ' + money(p.bh);
    tip.style.opacity = 1;
    var left = Math.min(W - 150, Math.max(0, X(i) + 10));
    tip.style.left = left + 'px'; tip.style.top = Math.max(0, Y(p.v) - 50) + 'px';
  };
  c.onmouseleave = function () { tip.style.opacity = 0; };
}

function drawDrawdown(pts) {
  var c = $('ddChart'); if (!c || !pts.length) return;
  var g = fitCanvas(c), ctx = g.ctx, W = g.w, H = g.h;
  var padL = 58, padR = 10, padT = 10, padB = 16;
  var peak = -Infinity, dd = pts.map(function (p) { peak = Math.max(peak, p.v); return peak > 0 ? (p.v / peak - 1) * 100 : 0; });
  var lo = Math.min.apply(null, dd.concat([-1]));
  var X = function (i) { return padL + (W - padL - padR) * (pts.length < 2 ? 0.5 : i / (pts.length - 1)); };
  var Y = function (v) { return padT + (H - padT - padB) * (v / lo); };

  ctx.clearRect(0, 0, W, H);
  ctx.font = '10px "IBM Plex Mono", monospace'; ctx.textBaseline = 'middle';
  [0, lo / 2, lo].forEach(function (t) {
    var y = Y(t);
    ctx.strokeStyle = 'rgba(31,40,56,0.9)'; ctx.beginPath();
    ctx.moveTo(padL, y); ctx.lineTo(W - padR, y); ctx.stroke();
    ctx.fillStyle = '#5e6e86'; ctx.textAlign = 'right';
    ctx.fillText(t.toFixed(0) + '%', padL - 7, y);
  });
  ctx.beginPath();
  dd.forEach(function (v, i) { i ? ctx.lineTo(X(i), Y(v)) : ctx.moveTo(X(i), Y(v)); });
  ctx.strokeStyle = '#ef5350'; ctx.lineWidth = 1.3; ctx.stroke();
  ctx.lineTo(X(dd.length - 1), Y(0)); ctx.lineTo(X(0), Y(0)); ctx.closePath();
  ctx.fillStyle = 'rgba(239,83,80,0.16)'; ctx.fill();
  ctx.fillStyle = '#8b9bb4'; ctx.textAlign = 'right';
  ctx.fillText('Drawdown from peak', W - padR - 6, padT + 6);
}

/* ----------------------------------------------------------- walk-forward */
function tunables() {
  var out = [];
  BUCKETS.forEach(function (b) {
    (state.rules[b[0]] || []).forEach(function (rule, i) {
      ['left', 'right'].forEach(function (side) {
        var op = rule[side];
        SLOTS.forEach(function (s) {
          var k = s[0], v = op[k];
          if (v === undefined || v === null || v === '') return;
          var name = op.ind ? ((IND[op.ind] || {}).label || op.ind) : 'number';
          out.push({
            id: b[0] + '.' + i + '.' + side + '.' + k,
            label: b[1] + ' #' + (i + 1) + ' · ' + name + ' ' + s[1] + ' (' + v + ')',
            value: +v, isInt: k !== 'mult' && k !== 'value'
          });
        });
      });
    });
  });
  RISK_FIELDS.forEach(function (f) {
    if (f[0] === 'atr_period') return;
    var v = state.risk[f[0]];
    if (v === undefined || v === null || v === '') return;
    out.push({ id: 'risk.' + f[0], label: 'Risk · ' + f[0].replace(/_/g, ' ') + ' (' + v + ')', value: +v, isInt: f[0] === 'max_bars' });
  });
  return out;
}

function renderWfGrid() {
  var host = $('wfGrid'); if (!host) return;
  var list = tunables();
  host.innerHTML = '';
  if (!list.length) {
    host.innerHTML = '<p class="btp-help" style="margin:0">No numbers to tune yet — add a rule with a period or a level.</p>';
    $('wfCount').textContent = '';
    return;
  }
  host.appendChild(el('<div class="btp-wfrow" style="color:#5e6e86;font-size:11px"><label>parameter</label><span>from</span><span>to</span><span>step</span></div>'));
  list.forEach(function (t) {
    var p = state.wf.picks[t.id];
    var def = defaultSweep(t);
    var row = el('<div class="btp-wfrow"></div>');
    var lab = el('<label title="' + esc(t.label) + '"><input type="checkbox"' + (p ? ' checked' : '') + '> <span>' + esc(t.label) + '</span></label>');
    var from = el('<input type="number" step="any" value="' + (p ? p.from : def.from) + '">');
    var to = el('<input type="number" step="any" value="' + (p ? p.to : def.to) + '">');
    var step = el('<input type="number" step="any" value="' + (p ? p.step : def.step) + '">');
    function sync() {
      if (lab.querySelector('input').checked) {
        state.wf.picks[t.id] = { from: num(from.value), to: num(to.value), step: num(step.value), isInt: t.isInt };
      } else delete state.wf.picks[t.id];
      save(); wfCount();
    }
    lab.querySelector('input').onchange = sync;
    [from, to, step].forEach(function (i) { i.oninput = sync; });
    row.appendChild(lab); row.appendChild(from); row.appendChild(to); row.appendChild(step);
    host.appendChild(row);
  });
  wfCount();
}

function defaultSweep(t) {
  var v = t.value;
  if (t.isInt) {
    var lo = Math.max(1, Math.round(v * 0.5)), hi = Math.round(v * 1.5) || 2;
    return { from: lo, to: hi, step: Math.max(1, Math.round((hi - lo) / 4)) };
  }
  var a = +(v * 0.5).toFixed(2), b = +(v * 1.5).toFixed(2);
  return { from: a, to: b, step: +(((b - a) / 4) || 0.1).toFixed(2) };
}

function sweepValues(p) {
  var out = [], from = p.from, to = p.to, step = Math.abs(p.step) || 1;
  if (to < from) { var t = from; from = to; to = t; }
  for (var v = from; v <= to + 1e-9 && out.length < 20; v += step) {
    out.push(p.isInt ? Math.round(v) : +v.toFixed(4));
  }
  out = out.filter(function (x, i, a) { return a.indexOf(x) === i; });
  return out;
}

function wfCount() {
  var ids = Object.keys(state.wf.picks);
  if (!ids.length) { $('wfCount').textContent = 'Tick 1 to 4 parameters above.'; return; }
  var combos = 1;
  ids.forEach(function (id) { combos *= Math.max(1, sweepValues(state.wf.picks[id]).length); });
  var lim = (meta.limits || {}).max_combinations || 120;
  $('wfCount').textContent = ids.length + ' parameter(s), ' + combos + ' combinations' +
    (combos > lim ? ' — over the limit of ' + lim + ', narrow a range.' : '.');
}

function applyPath(obj, path, val) {
  var parts = path.split('.');
  if (parts[0] === 'risk') { obj.risk[parts[1]] = val; return; }
  obj.rules[parts[0]][+parts[1]][parts[2]][parts[3]] = val;
}

function runWalkForward() {
  if (busy) return;
  var ids = Object.keys(state.wf.picks);
  if (!ids.length) return showError('Tick at least one parameter in step 4 to tune.');
  if (ids.length > 4) return showError('A walk-forward can tune at most 4 parameters.');
  var tpl = strategyJson(), grid = {};
  var bad = null;
  ids.forEach(function (id, i) {
    var name = 'p' + (i + 1);
    var vals = sweepValues(state.wf.picks[id]);
    if (!vals.length) bad = 'One of the ranges produces no values — check from / to / step.';
    grid[name] = vals;
    applyPath(tpl, id, '$' + name);
  });
  if (bad) return showError(bad);

  var body = settingsJson();
  body.strategy = tpl; body.grid = grid;
  body.train_bars = +state.wf.train || 400;
  body.test_bars = +state.wf.test || 100;
  body.score = state.wf.score;
  body.min_trades = 5;

  setBusy($('wfBtn'), true, 'Running');
  post('forward', body).then(function (j) {
    renderWf(j, ids);
  }).catch(function (e) {
    var pane = $('wfPane');
    if (pane) { pane.innerHTML = '<div class="btp-err">' + esc(e.message) + '</div>'; activateWfTab(); }
    else showError(e.message);
  }).then(function () { setBusy($('wfBtn'), false, 'Run walk-forward'); });
}

function activateWfTab() {
  var v = $('resView');
  v.querySelectorAll('.btp-tab').forEach(function (x) { x.classList.toggle('on', x.dataset.p === 'wf'); });
  v.querySelectorAll('.btp-pane').forEach(function (p) { p.classList.toggle('on', p.dataset.p === 'wf'); });
}

function renderWf(j, ids) {
  var pane = $('wfPane');
  if (!pane) {
    $('emptyState').style.display = 'none';
    $('resView').style.display = '';
    $('resView').innerHTML = '<div id="wfPane"></div>';
    pane = $('wfPane');
  }
  var names = {};
  ids.forEach(function (id, i) {
    var t = tunables().filter(function (x) { return x.id === id; })[0];
    names['p' + (i + 1)] = t ? t.label : id;
  });

  var good = j.profitable_windows, tested = j.tested_windows;
  var verdict = !tested ? 'No window had enough trades to judge.' :
    (j.oos_compound_return_pct > 0 && good / tested >= 0.5
      ? 'This held up out of sample — but keep the costs honest before trusting it.'
      : 'This did not hold up out of sample. The in-sample result was most likely fitted to the past.');

  var html = '<div class="btp-mc" style="margin-bottom:14px">' +
    '<div><span>Windows tested</span><b>' + tested + ' / ' + j.n_windows + '</b></div>' +
    '<div><span>Profitable windows</span><b class="' + (good > tested / 2 ? 'up' : 'down') + '">' + good + '</b></div>' +
    '<div><span>Out-of-sample compound</span><b class="' + signClass(j.oos_compound_return_pct) + '">' +
      fmt(j.oos_compound_return_pct, 2, '%') + '</b></div>' +
    '<div><span>Combinations per window</span><b>' + j.combinations + '</b></div></div>' +
    '<div class="btp-notes"><div class="btp-note' + (j.oos_compound_return_pct > 0 ? '' : ' warn') + '"><b>Verdict.</b> ' + esc(verdict) + '</div>' +
    (j.cut_short ? '<div class="btp-note warn">The run hit its time budget and stopped early, so the later windows are missing. Use a smaller grid or larger test bars.</div>' : '') +
    '</div>';

  var rows = (j.windows || []).map(function (w) {
    if (!w.params) {
      return '<tr><td class="t">' + esc(w.train_end) + '</td><td class="t" colspan="6">' + esc(w.note || 'skipped') + '</td></tr>';
    }
    var prm = Object.keys(w.params).map(function (k) { return (names[k] || k).split(' · ').pop() + ' ' + w.params[k]; }).join(', ');
    return '<tr><td class="t">' + esc(w.train_end) + '</td><td class="t">' + esc(prm) + '</td>' +
      '<td class="' + signClass(w.total_return_pct) + '">' + fmt(w.total_return_pct, 2, '%') + '</td>' +
      '<td>' + fmt(w.sharpe, 2) + '</td><td class="down">' + fmt(w.max_drawdown_pct, 1, '%') + '</td>' +
      '<td>' + (w.trades == null ? '—' : w.trades) + '</td><td>' + fmt(w.win_rate_pct, 0, '%') + '</td></tr>';
  }).join('');

  html += '<div class="btp-scroll"><table class="btp-table"><thead><tr><th>Trained to</th><th>Chosen params</th>' +
    '<th>OOS return</th><th>Sharpe</th><th>Max DD</th><th>Trades</th><th>Win rate</th></tr></thead><tbody>' +
    rows + '</tbody></table></div>' +
    '<p class="btp-help">Each row is a window the optimiser never saw. The parameters were picked on the bars before it, ' +
    'by ' + esc(state.wf.score) + '.</p>';

  pane.innerHTML = html;
  activateWfTab();
}

window.addEventListener('resize', function () {
  if (lastRun) { drawEquity(lastRun.equity || []); drawDrawdown(lastRun.equity || []); }
});

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();

})();
