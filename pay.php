<?php
// Razorpay checkout + trial / waitlist requests.
//   POST pay.php?a=order  {sku, period, currency, name, email, phone}  -> Razorpay order (amount computed here, never taken from the page)
//   POST pay.php?a=verify {razorpay_order_id, razorpay_payment_id, razorpay_signature}
//   POST pay.php?a=lead   {kind: trial|waitlist|courses, sku, name, email, phone}  -> logged to data/leads.log
//   POST pay.php?a=recover {payment_id, email}  -> same access code again for a paid order (buyer lost the page)
//   POST pay.php?a=webhook  Razorpay order.paid event, signed with config razorpay_webhook_secret
declare(strict_types=1);
require __DIR__ . '/torus_engine.php';
require __DIR__ . '/torus_pricing.php';
require __DIR__ . '/torus_access.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pay_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; }
function pay_log(string $file, array $row): void { t_ensure_dirs(); @file_put_contents(T_DATA . '/' . $file, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX); }
function pay_person(array $in): array {
    $name = trim(mb_substr(strip_tags((string)($in['name'] ?? '')), 0, 80));
    $email = filter_var(trim((string)($in['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $phone = mb_substr(preg_replace('/[^0-9+]/', '', (string)($in['phone'] ?? '')), 0, 16);
    if ($name === '' || !$email || strlen($phone) < 10) pay_out(['error' => 'Please enter your name, a valid email and a mobile number.'], 400);
    return [$name, (string)$email, $phone];
}
function pay_rz_get(string $path, string $kid, string $ksec): array {
    $ch = curl_init('https://api.razorpay.com/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "$kid:$ksec", CURLOPT_TIMEOUT => 20]);
    $j = json_decode((string)curl_exec($ch), true); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code === 200 && is_array($j) ? $j : [];
}
/** Unlocks a paid order once (owner, 29 Sep 2026). The same payment always gets the same code and end date, whether it
    arrives through verify, the webhook or recover. $notes = the Razorpay order notes (sku, period), never the page. */
function pay_unlock(string $pid, string $oid, array $notes, bool $cookie, string $via): array {
    t_ensure_dirs(); $fh = fopen(T_DATA . '/.pay_lock', 'c'); if ($fh) flock($fh, LOCK_EX);
    try {
        $prev = ta_code_by_ref($pid);
        if ($prev) { if ($cookie) ta_grant((string)$prev['tier'], (int)$prev['exp'], 'pay:' . $pid); return ['code' => $prev['code'], 'sku' => (string)$prev['tier']]; }
        $sku = (string)($notes['sku'] ?? ''); $period = (string)($notes['period'] ?? 'monthly');
        if (!isset(TA_RANK[$sku])) return ['code' => null, 'sku' => $sku];
        $exp = ta_period_exp($period); if ($cookie) ta_grant($sku, $exp, 'pay:' . $pid); $code = ta_issue_code($sku, $exp, $pid);
        pay_log('payments.log', ['type' => 'access', 'via' => $via, 'order_id' => $oid, 'sku' => $sku, 'until' => gmdate('Y-m-d', $exp), 'code' => $code, 'at' => time()]);
        return ['code' => $code, 'sku' => $sku];
    } finally { if ($fh) { flock($fh, LOCK_UN); fclose($fh); } }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pay_out(['error' => 'POST only'], 405);
if (($_GET['a'] ?? '') === 'webhook') {   // Razorpay server-to-server: unlocks the plan even if the buyer's page closed before verify
    if (!t_rl('pay:webhook', 600, 600)) pay_out(['error' => 'busy'], 429);
    $ws = trim((string)t_cfg('razorpay_webhook_secret', ''));
    if ($ws === '') pay_out(['error' => 'Webhook not configured'], 503);
    $raw = (string)file_get_contents('php://input', false, null, 0, 262144);
    $sig = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
    if ($sig === '' || !hash_equals(hash_hmac('sha256', $raw, $ws), $sig)) { pay_log('payments.log', ['type' => 'webhook_bad_signature', 'at' => time()]); pay_out(['error' => 'Bad signature'], 400); }
    $ev = json_decode($raw, true) ?: [];
    $pay = (array)($ev['payload']['payment']['entity'] ?? []); $ord = (array)($ev['payload']['order']['entity'] ?? []);
    $pid = (string)($pay['id'] ?? ''); $oid = (string)($pay['order_id'] ?? ($ord['id'] ?? '')); $r = null;
    if (($ev['event'] ?? '') === 'order.paid' && $pid !== '' && ($pay['status'] ?? '') === 'captured') $r = pay_unlock($pid, $oid, (array)($ord['notes'] ?? []), false, 'webhook');
    pay_log('payments.log', ['type' => 'webhook', 'event' => (string)($ev['event'] ?? ''), 'order_id' => $oid, 'payment_id' => $pid, 'code' => $r['code'] ?? null, 'at' => time()]);
    pay_out(['ok' => true]);
}
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true) ?: [];
$a = (string)($_GET['a'] ?? '');
// Rate limits per visitor (owner, 29 Sep 2026): stops order / lead spam. Real buyers never get near these.
$lim = ['usdt_order' => [8, 600], 'usdt_check' => [150, 600], 'usdt_recover' => [8, 600], 'order' => [10, 600], 'verify' => [20, 600], 'lead' => [5, 600], 'recover' => [6, 600]][$a] ?? [10, 600];
if (!t_rl('pay:' . $a, $lim[0], $lim[1])) pay_out(['error' => 'Too many requests. Please wait a few minutes and try again.'], 429);

if ($a === 'lead') {   // free trial, Algo waitlist, course enquiry: no payment keys needed
    if (!empty($in['website'])) pay_out(['ok' => true]);   // honeypot
    $kind = in_array($in['kind'] ?? '', ['trial', 'waitlist', 'courses'], true) ? (string)$in['kind'] : 'trial';
    [$name, $email, $phone] = pay_person($in);
    $sku = preg_replace('/[^a-z0-9_]/', '', (string)($in['sku'] ?? ''));
    pay_log('leads.log', ['type' => $kind, 'sku' => $sku, 'name' => $name, 'email' => $email, 'phone' => $phone, 'at' => time()]);
    pay_out(['ok' => true, 'kind' => $kind]);
}

// USDT checkout (owner, 6 Oct 2026): works without Razorpay keys. See torus_usdt.php.
if (str_starts_with($a, 'usdt_')) {
    require_once __DIR__ . '/torus_usdt.php';
    if (!tu_on()) pay_out(['error' => 'USDT payments are not active.'], 503);
    if ($a === 'usdt_order') {
        if (!empty($in['website'])) pay_out(['error' => 'Please try again.'], 400);
        $sku = (string)($in['sku'] ?? ''); $period = (string)($in['period'] ?? 'monthly'); $net = (string)($in['net'] ?? '');
        if (!isset(TU_NETS[$net])) pay_out(['error' => 'Choose a network.'], 400);
        if ($sku === 'courses') { $usd = tp_courses('USD'); $period = 'one_time'; }
        else {
            if (!tp_valid($sku, $period, 'USD')) pay_out(['error' => 'This plan is not available.'], 400);
            if (empty(tp_cfg()['products'][$sku]['available'])) pay_out(['error' => 'This plan opens soon. Join the waitlist instead.'], 400);
            $usd = tp_price($sku, $period, 'USD');
        }
        $who = pay_person($in);
        $o = tu_create($sku, $period, $net, $usd, $who);
        if (isset($o['error'])) pay_out($o, 502);
        pay_log('payments.log', ['type' => 'usdt_order', 'order_id' => $o['id'], 'sku' => $sku, 'period' => $period, 'net' => $net, 'amount' => $o['amount'],
            'name' => $who[0], 'email' => $who[1], 'phone' => $who[2], 'at' => time()]);
        pay_out(['ok' => true] + tu_view($o));
    }
    $settle = function (array $o, string $via): array {   // paid order -> access code (same code every time), this browser unlocked
        $first = !ta_code_by_ref('usdt:' . $o['tx']);
        $r = pay_unlock('usdt:' . $o['tx'], $o['id'], ['sku' => $o['sku'], 'period' => $o['period']], true, $via);
        if ($first) { pay_log('payments.log', ['type' => 'usdt_paid', 'via' => $via, 'order_id' => $o['id'], 'net' => $o['net'], 'amount' => $o['amount'], 'tx' => $o['tx'], 'email' => $o['email'], 'code' => $r['code'], 'at' => time()]); }
        return $r;
    };
    if ($a === 'usdt_check') {
        $id = (string)($in['order'] ?? ''); if (!preg_match('/^u[0-9a-f]{16}$/', $id)) pay_out(['error' => 'Unknown order.'], 400);
        $o = tu_check($id); if (!$o) pay_out(['error' => 'Unknown order.'], 404);
        $out = ['ok' => true] + tu_view($o);
        if ($o['status'] === 'paid') { $r = $settle($o, 'usdt_auto'); $out['access_code'] = $r['code']; $out['sku'] = $r['sku']; }
        pay_out($out);
    }
    if ($a === 'usdt_recover') {
        $net = (string)($in['net'] ?? ''); $tx = trim((string)($in['tx'] ?? '')); $email = strtolower(trim((string)($in['email'] ?? '')));
        if (!isset(TU_NETS[$net])) pay_out(['error' => 'Choose the network you paid on.'], 400);
        $ok = $net === 'trc20' ? preg_match('/^[0-9a-fA-F]{64}$/', $tx) : preg_match('/^0x[0-9a-fA-F]{64}$/', $tx);
        if (!$ok || !filter_var($email, FILTER_VALIDATE_EMAIL)) pay_out(['error' => 'Enter the transaction hash and the email you used at checkout.'], 400);
        $tx = strtolower($tx);
        $o = tu_recover($net, $tx, $email);
        pay_log('payments.log', ['type' => 'usdt_recover', 'net' => $net, 'tx' => $tx, 'ok' => empty($o['error']), 'unmatched' => $o['unmatched'] ?? null, 'at' => time()]);
        if (isset($o['error'])) pay_out(['error' => $o['error']], 404);
        $r = $settle($o, 'usdt_recover');
        pay_out(['ok' => true, 'payment_id' => $o['tx'], 'access_code' => $r['code'], 'sku' => $r['sku']]);
    }
    pay_out(['error' => 'Unknown action'], 400);
}

$kid = trim((string)t_cfg('razorpay_key_id', '')); $ksec = trim((string)t_cfg('razorpay_key_secret', ''));
if ($kid === '' || $ksec === '') pay_out(['error' => 'Payments are not active yet.'], 503);

if ($a === 'order') {
    $sku = (string)($in['sku'] ?? ''); $period = (string)($in['period'] ?? 'monthly'); $cur = strtoupper((string)($in['currency'] ?? 'INR'));
    if ($cur === 'USD' && !tp_cfg()['usd_checkout']) pay_out(['error' => 'Card payments in USD open soon. Please choose INR.'], 400);
    if ($sku === 'courses') {
        if (!in_array($cur, ['USD', 'INR'], true)) pay_out(['error' => 'Unknown currency.'], 400);
        $price = tp_courses($cur); $period = 'one_time'; $desc = 'Courses · one-time';
    } else {
        if (!tp_valid($sku, $period, $cur)) pay_out(['error' => 'This plan is not available.'], 400);
        $p = tp_cfg()['products'][$sku];
        if (empty($p['available'])) pay_out(['error' => 'This plan opens soon. Join the waitlist instead.'], 400);
        $price = tp_price($sku, $period, $cur); $desc = trim($p['tier'] . ' · ' . $p['label'] . ' · ' . TP_LABELS[$period]);
    }
    [$name, $email, $phone] = pay_person($in);
    $amount = $price * 100;   // integer paise / cents
    $receipt = 'cp_' . gmdate('ymdHis') . '_' . bin2hex(random_bytes(3));
    $body = json_encode(['amount' => $amount, 'currency' => $cur, 'receipt' => $receipt,
        'notes' => ['sku' => $sku, 'period' => $period, 'name' => $name, 'email' => $email, 'phone' => $phone]]);
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_USERPWD => "$kid:$ksec",
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $o = json_decode((string)$res, true);
    if ($code !== 200 || empty($o['id'])) {
        pay_log('payments.log', ['type' => 'order_error', 'http' => $code, 'sku' => $sku, 'currency' => $cur, 'err' => $o['error']['description'] ?? null, 'at' => time()]);
        pay_out(['error' => $cur === 'USD' ? 'USD checkout is not available right now. Please switch to INR or try again.' : 'Could not start the payment. Please try again.'], 502);
    }
    pay_log('payments.log', ['type' => 'order', 'order_id' => $o['id'], 'receipt' => $receipt, 'sku' => $sku, 'period' => $period, 'currency' => $cur, 'amount' => $amount,
        'name' => $name, 'email' => $email, 'phone' => $phone, 'at' => time()]);
    pay_out(['key' => $kid, 'order_id' => $o['id'], 'amount' => $amount, 'currency' => $cur, 'name' => (string)t_cfg('business_name', 'ChartPulse'),
        'description' => $desc, 'prefill' => ['name' => $name, 'email' => $email, 'contact' => $phone]]);
}
if ($a === 'verify') {
    $oid = (string)($in['razorpay_order_id'] ?? ''); $pid = (string)($in['razorpay_payment_id'] ?? ''); $sig = (string)($in['razorpay_signature'] ?? '');
    $ok = $oid !== '' && $pid !== '' && $sig !== '' && hash_equals(hash_hmac('sha256', "$oid|$pid", $ksec), $sig);
    pay_log('payments.log', ['type' => 'verify', 'order_id' => $oid, 'payment_id' => $pid, 'ok' => $ok, 'at' => time()]);
    if (!$ok) pay_out(['ok' => false, 'error' => 'Payment could not be verified. If money was debited, contact support with your payment ID.'], 400);
    // unlock the plan: read what was bought from Razorpay itself (the order notes), not from the page
    $o = pay_rz_get('orders/' . rawurlencode($oid), $kid, $ksec);
    ['code' => $code, 'sku' => $sku] = pay_unlock($pid, $oid, (array)($o['notes'] ?? []), true, 'verify');
    pay_out(['ok' => true, 'payment_id' => $pid, 'order_id' => $oid, 'access_code' => $code, 'sku' => $sku]);
}
if ($a === 'recover') {   // buyer closed the page before seeing the code: payment ID + checkout email -> same code, this browser unlocked
    $pid = (string)($in['payment_id'] ?? ''); $email = strtolower(trim((string)($in['email'] ?? '')));
    if (!preg_match('/^pay_[A-Za-z0-9]{8,30}$/', $pid) || $email === '') pay_out(['error' => 'Enter the payment ID (it starts with pay_) and the email used at checkout.'], 400);
    $p = pay_rz_get('payments/' . $pid, $kid, $ksec);
    $okp = $p && ($p['status'] ?? '') === 'captured' && !empty($p['order_id']) && hash_equals(strtolower((string)($p['email'] ?? '')), $email);
    pay_log('payments.log', ['type' => 'recover', 'payment_id' => $pid, 'ok' => $okp, 'at' => time()]);
    if (!$okp) pay_out(['error' => 'We could not match that payment ID and email to a completed payment. Check both, or contact support.'], 404);
    $o = pay_rz_get('orders/' . rawurlencode((string)$p['order_id']), $kid, $ksec);
    $r = pay_unlock($pid, (string)$p['order_id'], (array)($o['notes'] ?? []), true, 'recover');
    if (!$r['code']) pay_out(['error' => 'This payment is not for a plan with online access. Contact support with your payment ID.'], 400);
    pay_out(['ok' => true, 'payment_id' => $pid, 'access_code' => $r['code'], 'sku' => $r['sku']]);
}
pay_out(['error' => 'Unknown action'], 400);
