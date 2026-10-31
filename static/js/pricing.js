/* ChartPulse · Pricing + Razorpay checkout */
(() => {
'use strict';
const { $, esc } = window.CP;
const S = { plans: [], payments: false, plan: null };
const inr = v => '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });

async function load() {
  let d;
  try { d = await CP.api('plans'); } catch (e) { $('#plans').innerHTML = '<div class="empty" style="grid-column:1/-1">Plans could not be loaded. Please refresh.</div>'; return; }
  S.plans = d.plans || []; S.payments = !!d.payments;
  $('#payMode').textContent = !S.payments ? 'Online payments open soon.' : d.mode === 'test' ? 'Test mode: no real money is charged.' : '';
  $('#plans').innerHTML = S.plans.map(p => {
    const priced = p.price != null && p.price !== '' && Number(p.price) > 0, can = priced && S.payments;
    return `<article class="plan ${p.highlight ? 'hot' : ''}">${p.badge ? `<span class="badge">${esc(p.badge)}</span>` : ''}
      <div><div class="p-tier">${esc(p.tier)}</div><h3>${esc(p.name)}</h3></div>
      <p class="p-desc">${esc(p.desc || '')}</p>
      <div class="p-price ${priced ? '' : 'soon'}">${priced ? `${inr(p.price)}<small> / ${esc(p.period || 'month')}</small>` : 'Price coming soon'}</div>
      <ul>${(p.features || []).map(f => `<li class="${/\(coming\)/i.test(f) ? 'off' : ''}">${esc(f)}</li>`).join('')}</ul>
      <button type="button" class="btn ${p.highlight ? 'primary' : 'ghost'} lg" data-id="${esc(p.id)}" ${can ? '' : 'disabled'}>${can ? 'Choose ' + esc(p.name) : priced ? 'Payments open soon' : 'Coming soon'}</button></article>`;
  }).join('');
  document.querySelectorAll('#plans button[data-id]').forEach(b => b.addEventListener('click', () => openModal(b.dataset.id)));
}

const modal = $('#payModal');
function openModal(id) {
  S.plan = S.plans.find(p => p.id === id); if (!S.plan) return;
  $('#payTitle').textContent = `${S.plan.tier} · ${S.plan.name}`;
  $('#paySub').textContent = `${inr(S.plan.price)} per ${S.plan.period || 'month'}. You'll finish on Razorpay's secure checkout.`;
  $('#payErr').textContent = ''; modal.hidden = false; $('#pName').focus();
}
function closeModal() { modal.hidden = true; }
$('#payCancel').addEventListener('click', closeModal);
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

async function post(a, body) {
  const r = await fetch('pay.php?a=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(j.error || 'Something went wrong. Please try again.');
  return j;
}
function success(pid) {
  $('#payCard').innerHTML = `<div class="ok-box"><div class="tick">✓</div><h3>Payment received</h3>
    <p class="m-sub">Payment ID <b class="mono">${esc(pid)}</b>. Keep it for your records. Access details will reach your email shortly.</p>
    <a class="btn primary" href="scanner.html">Open the scanner</a></div>`;
}
$('#payForm').addEventListener('submit', async e => {
  e.preventDefault();
  const go = $('#payGo'); go.disabled = true; $('#payErr').textContent = '';
  try {
    if (!window.Razorpay) throw new Error('Checkout is still loading. Please try again in a moment.');
    const o = await post('order', { plan: S.plan.id, name: $('#pName').value, email: $('#pEmail').value, phone: $('#pPhone').value });
    const rz = new window.Razorpay({
      key: o.key, order_id: o.order_id, amount: o.amount, currency: o.currency, name: o.name, description: o.description, prefill: o.prefill,
      theme: { color: '#F4B740' },
      handler: async resp => {
        try { const v = await post('verify', resp); success(v.payment_id); }
        catch (err) { $('#payErr').textContent = err.message; go.disabled = false; }
      },
      modal: { ondismiss: () => { go.disabled = false; } },
    });
    rz.on('payment.failed', r => { $('#payErr').textContent = (r.error && r.error.description) || 'Payment failed. No money was taken.'; go.disabled = false; });
    rz.open();
  } catch (err) { $('#payErr').textContent = err.message; go.disabled = false; }
});
load();
})();
