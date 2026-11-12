<?php
// ChartPulse · Affiliates (admin only). Password = config.php 'admin_password'; log in on reports.php first (same cookie).
// This page moves no money. It shows who is owed what; after you send the USDT yourself, press "Mark paid".
declare(strict_types=1);
require dirname(__DIR__) . '/torus_engine.php';
require dirname(__DIR__) . '/torus_pricing.php';
require dirname(__DIR__) . '/torus_access.php';
require dirname(__DIR__) . '/torus_aff.php';
header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin');

$pw = (string)t_cfg('admin_password', '');
$tok = $pw !== '' ? hash_hmac('sha256', 'cp-admin-v1', $pw) : '';
$authed = $tok !== '' && hash_equals($tok, (string)($_COOKIE['cp_admin'] ?? ''));   // money page: the gate is always on here
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$css = '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Affiliates · admin</title><style>body{background:#0b0c0f;color:#e8e8ea;font:14px/1.5 system-ui,sans-serif;margin:0;padding:24px}h1{font-size:22px}table{border-collapse:collapse;width:100%;margin:12px 0 28px}td,th{padding:8px 10px;border-bottom:1px solid #23252b;text-align:left;vertical-align:top}th{color:#9a9ca6;font-weight:600;font-size:12px}code{font-size:12px;word-break:break-all}.g{color:#22AB94}.y{color:#E9C46A}.r{color:#F7525F}.m{color:#9a9ca6}input,button{font:inherit;padding:6px 9px;border-radius:7px;border:1px solid #33363d;background:#15171c;color:inherit}button{cursor:pointer}form{display:inline}.box{border:1px solid #23252b;border-radius:10px;padding:12px 14px;max-width:760px;margin-bottom:18px}</style>';
if (!$authed) {
    http_response_code(403);
    echo $css . '<h1>Affiliates</h1><div class="box">' . ($tok === '' ? "config.php mein 'admin_password' set nahi hai. Pehle woh set karein, phir <a href=\"reports.php\">reports.php</a> par login karein."
        : 'Pehle <a href="reports.php" style="color:#E9C46A">reports.php</a> par admin password se login karein, phir yeh page kholein. (Is page par password gate hamesha on rehta hai, kyunki yahan paise ka hisaab hai.)') . '</div>';
    exit;
}
$csrf = hash_hmac('sha256', 'aff-admin', $tok); $msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $act = (string)($_POST['act'] ?? ''); $code = af_code((string)($_POST['code'] ?? '')); $now = time();
    $msg = (string)af_store(function (array &$S) use ($act, $code, $now) {
        if ($act === 'paid' && isset($S['affs'][$code])) {
            $sum = 0.0; $n = 0;
            foreach ($S['ledger'] as &$L) if ($L['code'] === $code && af_status($L, $now) === 'payable') { $L['paid'] = $now; $sum += (float)$L['comm']; $n++; }
            unset($L);
            if (!$n) return 'Is affiliate ka koi payable commission nahi hai.';
            $S['payouts'][] = ['code' => $code, 'usd' => round($sum, 2), 'net' => (string)$S['affs'][$code]['net'], 'addr' => (string)$S['affs'][$code]['addr'],
                'tx' => substr(preg_replace('/[^0-9a-zA-Z]/', '', (string)($_POST['tx'] ?? '')), 0, 80), 'at' => $now];
            return "$code: $" . number_format($sum, 2) . " paid mark ho gaya ($n commissions).";
        }
        if ($act === 'void') {
            foreach ($S['ledger'] as &$L) if ($L['id'] === (string)($_POST['id'] ?? '') && empty($L['paid'])) { $L['void'] = !$L['void']; return 'Commission ' . ($L['void'] ? 'cancel' : 'wapas chalu') . ' ho gaya.'; }
            unset($L); return 'Row nahi mili (ya already paid hai).';
        }
        if ($act === 'toggle' && isset($S['affs'][$code])) { $S['affs'][$code]['off'] = empty($S['affs'][$code]['off']); return $code . ($S['affs'][$code]['off'] ? ' band' : ' chalu') . ' kar diya.'; }
        return '';
    });
}
$S = af_load(); $now = time();
echo $css . '<h1>Affiliates</h1><p class="m">Commission ' . (int)(AF_COMM * 100) . '% har payment par · buyer ko pehli payment par ' . (int)(AF_DISC * 100) . '% off · ' . intdiv(AF_HOLD, 86400) . ' din hold · minimum payout $' . (int)AF_MIN_PAYOUT . ' · <a href="reports.php" style="color:#E9C46A">Reports</a></p>';
if ($msg !== '') echo '<div class="box g">' . $e($msg) . '</div>';
echo '<div class="box">Yeh page paisa nahi bhejta. "Payable" jab $' . (int)AF_MIN_PAYOUT . ' ya zyada ho: affiliate ke address par USDT khud bhejein, transaction hash yahan daalein aur <b>Mark paid</b> dabayein. Refund hua ho toh us sale ko <b>Cancel</b> karein.</div>';
echo '<table><tr><th>Code</th><th>Affiliate</th><th>Clicks</th><th>Sales</th><th>Pending (hold)</th><th>Payable</th><th>Paid</th><th>USDT address</th><th></th></tr>';
foreach ($S['affs'] as $c => $A) {
    $t = af_summary($S, (string)$c, $now)['totals']; $due = $t['payable'] >= AF_MIN_PAYOUT;
    echo '<tr><td><b>' . $e($c) . '</b>' . (!empty($A['off']) ? ' <span class="r">off</span>' : '') . '</td><td>' . $e($A['name']) . '<br><span class="m">' . $e($A['email']) . '</span></td><td>' . (int)($A['clicks'] ?? 0) . '</td><td>' . $t['sales'] . ' <span class="m">(' . $t['customers'] . ' cust.)</span></td>'
        . '<td class="m">$' . number_format($t['pending'], 2) . '</td><td class="' . ($due ? 'g' : 'y') . '"><b>$' . number_format($t['payable'], 2) . '</b></td><td>$' . number_format($t['paid'], 2) . '</td>'
        . '<td>' . ($A['addr'] !== '' ? $e(AF_NETS[$A['net']] ?? $A['net']) . '<br><code>' . $e($A['addr']) . '</code>' : '<span class="m">nahi diya</span>') . '</td><td>'
        . ($due && $A['addr'] !== '' ? '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="paid"><input type="hidden" name="code" value="' . $e($c) . '"><input name="tx" placeholder="tx hash" size="14"> <button>Mark paid</button></form> ' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="toggle"><input type="hidden" name="code" value="' . $e($c) . '"><button>' . (!empty($A['off']) ? 'Chalu karo' : 'Band karo') . '</button></form></td></tr>';
}
if (!$S['affs']) echo '<tr><td colspan="9" class="m">Abhi koi affiliate nahi hai.</td></tr>';
echo '</table><h1>Sales (latest 300)</h1><table><tr><th>Date</th><th>Code</th><th>Buyer</th><th>Plan</th><th>Paid</th><th>Commission</th><th>Status</th><th>Payment ref</th><th></th></tr>';
foreach (array_slice(array_reverse($S['ledger']), 0, 300) as $L) {
    $st = af_status($L, $now);
    echo '<tr><td>' . gmdate('d M Y', (int)$L['at']) . '</td><td>' . $e($L['code']) . '</td><td>' . $e($L['buyer']) . '</td><td>' . $e($L['sku'] . ' · ' . $L['period']) . '</td><td>' . $e($L['cur'] . ' ' . $L['amt']) . ' <span class="m">($' . number_format((float)$L['usd'], 2) . ')</span></td><td>$' . number_format((float)$L['comm'], 2) . '</td>'
        . '<td class="' . ['paid' => 'g', 'payable' => 'y', 'pending' => 'm', 'void' => 'r'][$st] . '">' . $st . '</td><td><code>' . $e($L['ref']) . '</code></td><td>'
        . ($st !== 'paid' ? '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="void"><input type="hidden" name="id" value="' . $e($L['id']) . '"><button>' . ($st === 'void' ? 'Wapas chalu' : 'Cancel') . '</button></form>' : '') . '</td></tr>';
}
if (!$S['ledger']) echo '<tr><td colspan="9" class="m">Abhi koi sale nahi hai.</td></tr>';
echo '</table>';
