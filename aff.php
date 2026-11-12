<?php
// ChartPulse affiliate API (owner, 12 Nov 2026). See torus_aff.php for the rules.
//   POST aff.php?a=join    {name, email, code, password}       -> account + session
//   POST aff.php?a=login   {email, password}
//   POST aff.php?a=logout
//   POST aff.php?a=me                                          -> dashboard data (session)
//   POST aff.php?a=wallet  {net, addr}                         -> USDT payout address (session)
//   POST aff.php?a=claim                                       -> free plan for the month, when earned (session)
//   POST aff.php?a=click   {code}                              -> counts a visit from a ?ref= link
//   POST aff.php?a=avail   {code}                              -> is this code free to take?
declare(strict_types=1);
require __DIR__ . '/torus_engine.php';
require __DIR__ . '/torus_pricing.php';
require __DIR__ . '/torus_access.php';
require __DIR__ . '/torus_aff.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function af_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') af_out(['error' => 'POST only'], 405);
$og = isset($_SERVER['HTTP_ORIGIN']) ? parse_url((string)$_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : null;
if ($og !== null && $og !== ($_SERVER['HTTP_HOST'] ?? '')) af_out(['error' => 'Bad origin'], 403);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true) ?: [];
$a = (string)($_GET['a'] ?? '');
$lim = ['join' => [4, 3600], 'login' => [10, 900], 'me' => [120, 600], 'wallet' => [10, 600], 'claim' => [6, 600], 'click' => [20, 600], 'avail' => [40, 600], 'logout' => [20, 600]][$a] ?? null;
if (!$lim) af_out(['error' => 'Unknown action'], 400);
if (!t_rl('aff:' . $a, $lim[0], $lim[1])) af_out(['error' => 'Too many requests. Please wait a few minutes and try again.'], 429);

$codeOk = function (string $c): ?string {
    if (!preg_match('/^[A-Z0-9]{4,16}$/', $c)) return 'Use 4 to 16 letters or numbers, no spaces.';
    if (in_array($c, AF_RESERVED, true) || str_starts_with($c, 'CHARTPULSE')) return 'That code is reserved. Please pick another.';
    return null;
};

if ($a === 'avail') {
    $c = af_code((string)($in['code'] ?? '')); $e = $codeOk($c);
    af_out(['code' => $c, 'ok' => !$e && !isset(af_load()['affs'][$c]), 'msg' => $e]);
}
if ($a === 'click') {
    $c = af_code((string)($in['code'] ?? ''));
    $ok = af_store(function (array &$S) use ($c) { if (!isset($S['affs'][$c]) || !empty($S['affs'][$c]['off'])) return false; $S['affs'][$c]['clicks'] = (int)($S['affs'][$c]['clicks'] ?? 0) + 1; return true; });
    af_out(['ok' => (bool)$ok]);
}
if ($a === 'join') {
    if (!empty($in['website'])) af_out(['error' => 'Please try again.'], 400);   // honeypot
    $name = trim(mb_substr(strip_tags((string)($in['name'] ?? '')), 0, 80));
    $email = filter_var(trim((string)($in['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $pw = (string)($in['password'] ?? ''); $c = af_code((string)($in['code'] ?? ''));
    if ($name === '' || !$email) af_out(['error' => 'Please enter your name and a valid email.'], 400);
    if (strlen($pw) < 8 || strlen($pw) > 100) af_out(['error' => 'Choose a password with at least 8 characters.'], 400);
    if ($e = $codeOk($c)) af_out(['error' => $e], 400);
    $email = strtolower((string)$email);
    $r = af_store(function (array &$S) use ($name, $email, $pw, $c) {
        if (isset($S['affs'][$c])) return 'That code is already taken. Please pick another.';
        foreach ($S['affs'] as $x) if ($x['email'] === $email) return 'This email already has an affiliate account. Please log in.';
        $S['affs'][$c] = ['code' => $c, 'name' => $name, 'email' => $email, 'pw' => password_hash($pw, PASSWORD_DEFAULT), 'net' => '', 'addr' => '', 'clicks' => 0, 'off' => false, 'at' => time()];
        return null;
    });
    if ($r) af_out(['error' => $r], 400);
    af_login_cookie($c);
    af_out(['ok' => true, 'code' => $c]);
}
if ($a === 'login') {
    $email = strtolower(trim((string)($in['email'] ?? ''))); $pw = (string)($in['password'] ?? ''); $hit = null;
    foreach (af_load()['affs'] as $x) if ($x['email'] === $email) { $hit = $x; break; }
    if (!$hit || !password_verify($pw, (string)$hit['pw'])) af_out(['error' => 'Email or password is not right.'], 401);
    af_login_cookie((string)$hit['code']);
    af_out(['ok' => true, 'code' => $hit['code']]);
}
if ($a === 'logout') { af_logout_cookie(); af_out(['ok' => true]); }

$me = af_session();
if (!$me) af_out(['error' => 'Please log in.', 'login' => true], 401);

if ($a === 'wallet') {
    $net = (string)($in['net'] ?? ''); $addr = trim((string)($in['addr'] ?? ''));
    if (!isset(AF_NETS[$net])) af_out(['error' => 'Choose the network for your USDT address.'], 400);
    $ok = $net === 'trc20' ? preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $addr) : preg_match('/^0x[0-9a-fA-F]{40}$/', $addr);
    if (!$ok) af_out(['error' => $net === 'trc20' ? 'A TRC20 address starts with T and has 34 characters.' : 'This address should start with 0x and have 42 characters.'], 400);
    af_store(function (array &$S) use ($me, $net, $addr) { $S['affs'][$me]['net'] = $net; $S['affs'][$me]['addr'] = $addr; });
    af_out(['ok' => true]);
}

$now = time(); $S = af_load(); $A = $S['affs'][$me]; $sum = af_summary($S, $me, $now);
// Free plan (owner: "free subscription toh denge hi"): earned by paid sales in the last 31 days. 1+ = Starter, 3+ = Core.
$recent = (int)$sum['totals']['recent']; $freeTier = $recent >= 3 ? 'tier2' : ($recent >= 1 ? 'tier1' : null);

if ($a === 'claim') {
    if (!$freeTier) af_out(['error' => 'Your free plan unlocks with your first paid customer in the last 31 days.'], 400);
    $ref = 'aff:' . $me . ':' . gmdate('Ym', $now) . ':' . $freeTier; $exp = $now + 31 * 86400;
    $prev = ta_code_by_ref($ref);
    if ($prev) { ta_grant((string)$prev['tier'], (int)$prev['exp'], $ref); af_out(['ok' => true, 'access_code' => $prev['code'], 'tier' => $prev['tier'], 'until' => gmdate('Y-m-d', (int)$prev['exp'])]); }
    $code = ta_issue_code($freeTier, $exp, $ref); ta_grant($freeTier, $exp, $ref);
    af_out(['ok' => true, 'access_code' => $code, 'tier' => $freeTier, 'until' => gmdate('Y-m-d', $exp)]);
}

// a === 'me'
$paidOut = [];
foreach ($S['payouts'] as $p) if ($p['code'] === $me) $paidOut[] = ['date' => gmdate('Y-m-d', (int)$p['at']), 'usd' => (float)$p['usd'], 'net' => (string)$p['net'], 'tx' => (string)($p['tx'] ?? '')];
af_out(['ok' => true, 'code' => $me, 'name' => $A['name'], 'email' => $A['email'], 'off' => !empty($A['off']),
    'link' => 'https://chartpulse.io/pricing.html?ref=' . $me, 'clicks' => (int)($A['clicks'] ?? 0),
    'net' => (string)$A['net'], 'addr' => (string)$A['addr'], 'nets' => AF_NETS,
    'rules' => ['comm_pct' => (int)round(AF_COMM * 100), 'disc_pct' => (int)round(AF_DISC * 100), 'hold_days' => intdiv(AF_HOLD, 86400), 'min_payout' => AF_MIN_PAYOUT],
    'totals' => $sum['totals'], 'rows' => array_slice($sum['rows'], 0, 200), 'payouts' => array_reverse($paidOut),
    'free' => ['tier' => $freeTier, 'recent' => $recent]]);
