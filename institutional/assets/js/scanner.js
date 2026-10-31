let ROWS = [], TIMER = null;
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function msg(t) { document.getElementById('tbody').innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px">' + esc(t) + '</td></tr>'; }
function render() {
  const q = document.getElementById('search').value.trim().toLowerCase();
  const rows = q ? ROWS.filter(r => (r.symbol + ' ' + (r.market || '') + ' ' + (r.tv || '')).toLowerCase().includes(q)) : ROWS;
  document.getElementById('liveCount').textContent = rows.length + ' signals';
  if (!rows.length) return msg(ROWS.length ? 'No symbol matches your filter.' : 'No setup right now. The scanner refreshes automatically.');
  document.getElementById('tbody').innerHTML = rows.map((r, i) => {
    const call = r.bias === 'CALL';
    const chg = (r.change || 0) >= 0 ? '<span style="color:#10b981">+' + esc(r.change) + '%</span>' : '<span style="color:#ef4444">' + esc(r.change) + '%</span>';
    return '<tr' + (r.score >= 80 ? ' class="highlight"' : '') + '><td>' + (i + 1) + '</td><td><b>' + esc(r.symbol) + '</b></td><td>' +
      (call ? '<span class="call">&#9650; CALL</span>' : '<span class="put">&#9660; PUT</span>') + '</td><td>' + esc(r.entry) + '</td><td>' + esc(r.sl) + '</td><td><span class="target">' + esc(r.tp) +
      '</span></td><td><div class="bar"><div style="width:' + Math.min(100, +r.score || 0) + '%"></div></div> ' + esc(r.score) + '</td><td><span class="setup">' + esc(r.setup) + '</span> <span class="tag">' + esc(r.tag) + '</span></td><td>' + chg + '</td></tr>';
  }).join('');
}
async function loadData() {
  clearTimeout(TIMER);
  try {
    const r = await fetch('api/scan.php?id=' + encodeURIComponent(STRAT_ID), { cache: 'no-store' });
    const j = await r.json();
    if (j.error) { msg(j.error); document.getElementById('updated').textContent = ''; return; }
    if (j.status === 'updating') { msg('Building the first scan, this takes a few seconds...'); TIMER = setTimeout(loadData, 8000); return; }
    document.getElementById('updated').textContent = j.updated || '';
    ROWS = j.data || []; render();
  } catch (e) { msg('This scanner is temporarily unavailable. Sorry for the inconvenience. Please check back in a little while.'); }
  TIMER = setTimeout(loadData, 120000);
}
document.getElementById('search').addEventListener('input', render);
loadData();
