<?php
// ---------------------------------------------------------------------------
// ChartPulse affiliate program (owner, 12 Nov 2026).
//   - Anyone joins instantly and picks a code (also their coupon).
//   - Buyer with the code: 20% off the FIRST payment only (AF_DISC). Plans only (Starter / Core), not courses or add-ons.
//   - Affiliate: 20% of every payment that buyer makes, renewals included (AF_COMM). The first code a buyer uses sticks.
//   - A commission is held 30 days (refund window), then becomes payable. Payout = USDT, monthly, minimum $50,
//     sent by the owner by hand; this file only keeps the books (admin/affiliates.php marks rows paid).
// Store: data/affiliates.json  { affs: {CODE: {...}}, buyers: {emailHash: {c: CODE|null, n: paidCount}},
//                                ledger: [ {...} ], pend: {usdtOrderId: CODE}, payouts: [ {...} ] }
// Money in the ledger is USD (INR payments converted at the pricing reference rate).
// ---------------------------------------------------------------------------
declare(strict_types=1);

const AF_COMM = 0.20;
const AF_DISC = 0.20;
const AF_HOLD = 30 * 86400;
const AF_MIN_PAYOUT = 50.0;
const AF_COOKIE = 'cp_aff';
const AF_NETS = ['trc20' => 'TRC20 (Tron)', 'bep20' => 'BEP20 (BNB Smart Chain)', 'polygon' => 'Polygon', 'erc20' => 'ERC20 (Ethereum)'];
const AF_RESERVED = ['ADMIN', 'CHARTPULSE', 'SUPPORT', 'OWNER', 'TEST', 'NULL', 'FREE', 'OFFICIAL'];

function af_path(): string { return T_DATA . '/affiliates.json'; }
/** Read-modify-write under a lock. $fn(array &$S) returns the value af_store returns. */
function af_store(callable $fn) {
    t_ensure_dirs(); $fh = fopen(T_DATA . '/.aff_lock', 'c'); if ($fh) flock($fh, LOCK_EX);
    try {
        $S = t_read_json(af_path(), []); if (!is_array($S)) $S = [];
        $S += ['affs' => [], 'buyers' => [], 'ledger' => [], 'pend' => [], 'payouts' => []];
        $before = json_encode($S); $r = $fn($S);
        if (json_encode($S) !== $before) t_write_json(af_path(), $S);
        return $r;
    } finally { if ($fh) { flock($fh, LOCK_UN); fclose($fh); } }
}
function af_load(): array { $S = t_read_json(af_path(), []); return (is_array($S) ? $S : []) + ['affs' => [], 'buyers' => [], 'ledger' => [], 'pend' => [], 'payouts' => []]; }
function af_code(string $c): string { return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($c)), 0, 16); }
function af_ek(string $email): string { return substr(hash('sha256', strtolower(trim($email))), 0, 24); }
function af_mask(string $email): string {
    [$u, $d] = array_pad(explode('@', $email, 2), 2, '');
    return substr($u, 0, 2) . '***@' . $d;
}
function af_usd(float $amt, string $cur): float {
    if ($cur === 'USD') return $amt;
    $rate = (float)(tp_cfg()['usd_inr'] ?? 0);
    return $rate > 0 ? $amt / $rate : 0.0;
}

/**
 * Price for a buyer who typed a code. Returns null when the code is unknown / switched off / not usable on this product.
 * ['code', 'price' => what to charge, 'full' => list price, 'off' => discount taken, 'first' => bool]
 */
function af_quote(string $code, string $sku, string $period, string $cur, string $email, int $full): ?array {
    $code = af_code($code); if ($code === '' || !isset(TA_RANK[$sku])) return null;
    $S = af_load(); $a = $S['affs'][$code] ?? null;
    if (!$a || !empty($a['off'])) return null;
    if ($email !== '' && strtolower(trim($email)) === strtolower((string)$a['email'])) return null;      // no self-referral
    $b = $email !== '' ? ($S['buyers'][af_ek($email)] ?? null) : null;
    $first = !$b || (int)($b['n'] ?? 0) === 0;
    $price = $first ? tp_clean($full * (1 - AF_DISC), $cur) : $full;
    return ['code' => $code, 'price' => $price, 'full' => $full, 'off' => $full - $price, 'first' => $first];
}
/** Remember which code a USDT order was created with (the USDT order record itself is not touched). */
function af_pend_set(string $orderId, string $code): void {
    af_store(function (array &$S) use ($orderId, $code) {
        foreach ($S['pend'] as $k => $v) if ((int)($v['at'] ?? 0) < time() - 14 * 86400) unset($S['pend'][$k]);
        $S['pend'][$orderId] = ['c' => $code, 'at' => time()];
    });
}
function af_pend_get(string $orderId): string { return (string)(af_load()['pend'][$orderId]['c'] ?? ''); }

/**
 * A plan payment was confirmed (called once per payment from pay_unlock).
 * $n: ['aff' => code typed at checkout or '', 'email', 'amt' => amount charged, 'cur', 'sku', 'period'].
 */
function af_credit(array $n, string $ref): void {
    $email = strtolower(trim((string)($n['email'] ?? ''))); $amt = (float)($n['amt'] ?? 0); $cur = (string)($n['cur'] ?? '');
    if ($email === '' || $amt <= 0 || !in_array($cur, ['USD', 'INR'], true)) return;
    af_store(function (array &$S) use ($n, $ref, $email, $amt, $cur) {
        foreach ($S['ledger'] as $L) if (($L['ref'] ?? '') === $ref) return;                    // already booked
        $ek = af_ek($email); $b = $S['buyers'][$ek] ?? ['c' => null, 'n' => 0];
        $code = $b['c'] ?: af_code((string)($n['aff'] ?? ''));                                  // the first code sticks
        $a = $code !== '' ? ($S['affs'][$code] ?? null) : null;
        if ($a && strtolower((string)$a['email']) === $email) $a = null;
        $b['n'] = (int)$b['n'] + 1; if ($a && !$b['c']) $b['c'] = $code;
        $S['buyers'][$ek] = $b;
        if (!$a || !empty($a['off'])) return;
        $usd = round(af_usd($amt, $cur), 2);
        $S['ledger'][] = ['id' => 'c' . bin2hex(random_bytes(5)), 'code' => $code, 'ref' => $ref, 'sku' => (string)($n['sku'] ?? ''), 'period' => (string)($n['period'] ?? ''),
            'cur' => $cur, 'amt' => $amt, 'usd' => $usd, 'comm' => round($usd * AF_COMM, 2), 'buyer' => af_mask($email), 'bk' => $ek,
            'first' => (int)$b['n'] === 1, 'at' => time(), 'void' => false, 'paid' => null];
    });
}

/** Ledger status for display: void | paid | payable | pending. */
function af_status(array $L, int $now): string {
    if (!empty($L['void'])) return 'void';
    if (!empty($L['paid'])) return 'paid';
    return $now - (int)$L['at'] >= AF_HOLD ? 'payable' : 'pending';
}
/** Totals + rows for one affiliate. */
function af_summary(array $S, string $code, int $now): array {
    $t = ['pending' => 0.0, 'payable' => 0.0, 'paid' => 0.0, 'sales' => 0, 'customers' => [], 'recent' => 0]; $rows = [];
    foreach ($S['ledger'] as $L) {
        if ($L['code'] !== $code) continue;
        $st = af_status($L, $now);
        if ($st !== 'void') { $t[$st] += (float)$L['comm']; $t['sales']++; $t['customers'][$L['bk']] = 1; if ($now - (int)$L['at'] <= 31 * 86400) $t['recent']++; }
        $rows[] = ['date' => gmdate('Y-m-d', (int)$L['at']), 'plan' => (string)$L['sku'], 'period' => (string)$L['period'], 'buyer' => (string)$L['buyer'],
            'sale_usd' => (float)$L['usd'], 'comm' => (float)$L['comm'], 'status' => $st, 'first' => !empty($L['first']),
            'free_on' => $st === 'pending' ? gmdate('Y-m-d', (int)$L['at'] + AF_HOLD) : null];
    }
    $t['customers'] = count($t['customers']);
    foreach (['pending', 'payable', 'paid'] as $k) $t[$k] = round($t[$k], 2);
    return ['totals' => $t, 'rows' => array_reverse($rows)];
}

/* ---- affiliate session: signed cookie, same HMAC secret as the access cookie ---- */
function af_login_cookie(string $code): void {
    $exp = time() + 30 * 86400;
    setcookie(AF_COOKIE, ta_sign(['aff' => $code, 'exp' => $exp]), ['expires' => $exp, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https', 'httponly' => true, 'samesite' => 'Lax']);
}
function af_logout_cookie(): void { setcookie(AF_COOKIE, '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']); }
function af_session(): ?string {
    $d = ta_read($_COOKIE[AF_COOKIE] ?? null); $c = is_array($d) ? af_code((string)($d['aff'] ?? '')) : '';
    return $c !== '' && isset(af_load()['affs'][$c]) ? $c : null;
}
