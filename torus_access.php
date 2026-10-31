<?php
// ---------------------------------------------------------------------------
// ChartPulse access (paywall). A signed cookie says which plan this browser has and until when.
// Granted two ways:
//   1) A verified Razorpay payment (pay.php) -> cookie + a personal access code for other devices.
//   2) An access code: generated on payment (data/access_codes.json) or added by the owner in
//      config.php 'access_codes' => ['CODE' => ['tier' => 'tier2', 'until' => '2026-12-31', 'name' => '...']].
// Nothing here trusts the browser: the cookie is HMAC-signed with a server-only secret.
// ---------------------------------------------------------------------------
declare(strict_types=1);

const TA_COOKIE = 'cp_access';
const TA_RANK = ['tier1' => 1, 'tier2' => 2, 'tier3_algo' => 3];

function ta_secret(): string {
    $c = trim((string)t_cfg('access_secret', ''));
    if ($c !== '') return $c;
    t_ensure_dirs(); $p = T_DATA . '/.access_secret';
    if (!is_file($p)) @file_put_contents($p, bin2hex(random_bytes(32)), LOCK_EX);
    return trim((string)@file_get_contents($p));
}
function ta_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function ta_unb64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }
function ta_sign(array $d): string { $p = ta_b64(json_encode($d)); return $p . '.' . ta_b64(hash_hmac('sha256', $p, ta_secret(), true)); }
function ta_read(?string $tok): ?array {
    if (!$tok || !str_contains($tok, '.')) return null;
    [$p, $s] = explode('.', $tok, 2);
    if (!hash_equals(ta_b64(hash_hmac('sha256', $p, ta_secret(), true)), $s)) return null;
    $d = json_decode(ta_unb64($p), true);
    return is_array($d) && ($d['exp'] ?? 0) > time() ? $d : null;
}
/** Current access for this request: ['tier' => 'tier2', 'exp' => ts, 'id' => ...] or null. */
function ta_current(): ?array { return ta_read($_COOKIE[TA_COOKIE] ?? null); }
function ta_has(string $tier): bool { $a = ta_current(); return $a && (TA_RANK[$a['tier']] ?? 0) >= (TA_RANK[$tier] ?? 99); }
function ta_grant(string $tier, int $exp, string $id): void {
    $cur = ta_current();
    if ($cur && (TA_RANK[$cur['tier']] ?? 0) >= (TA_RANK[$tier] ?? 0) && $cur['exp'] >= $exp) return;   // never downgrade
    setcookie(TA_COOKIE, ta_sign(['tier' => $tier, 'exp' => $exp, 'id' => $id]),
        ['expires' => $exp, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https', 'httponly' => true, 'samesite' => 'Lax']);
}
function ta_codes_path(): string { return T_DATA . '/access_codes.json'; }
/** Personal code issued after a payment, usable on any device until the plan ends. */
function ta_issue_code(string $tier, int $exp, string $ref): string {
    t_ensure_dirs(); $all = t_read_json(ta_codes_path(), []);
    do { $c = strtoupper(substr(bin2hex(random_bytes(5)), 0, 4) . '-' . substr(bin2hex(random_bytes(5)), 0, 4)); } while (isset($all[$c]));
    $all[$c] = ['tier' => $tier, 'exp' => $exp, 'ref' => $ref, 'at' => time()];
    t_write_json(ta_codes_path(), $all);
    return $c;
}
/** The code already issued for a payment reference, so a replayed verify can never mint a new code or extend access. */
function ta_code_by_ref(string $ref): ?array {
    foreach (t_read_json(ta_codes_path(), []) as $c => $v) if (($v['ref'] ?? '') === $ref) return ['code' => (string)$c] + $v;
    return null;
}
/** Redeem a code: returns the grant or null. Owner codes in config.php win over issued ones. */
function ta_redeem(string $code): ?array {
    $code = strtoupper(trim($code)); if ($code === '' || strlen($code) > 40) return null;
    foreach ((array)t_cfg('access_codes', []) as $k => $v) {
        if (strtoupper((string)$k) !== $code || !is_array($v)) continue;
        $tier = (string)($v['tier'] ?? 'tier2'); $exp = strtotime((string)($v['until'] ?? '')) ?: time() + 30 * 86400;
        if ($exp <= time() || !isset(TA_RANK[$tier])) return null;
        return ['tier' => $tier, 'exp' => $exp + 86399, 'id' => 'code:' . substr(hash('sha256', $code), 0, 10)];
    }
    $all = t_read_json(ta_codes_path(), []); $v = $all[$code] ?? null;
    if (!$v || ($v['exp'] ?? 0) <= time()) return null;
    return ['tier' => (string)$v['tier'], 'exp' => (int)$v['exp'], 'id' => 'code:' . substr(hash('sha256', $code), 0, 10)];
}
/** Months of access a billing period buys, plus 3 days of grace. */
function ta_period_exp(string $period): int {
    $m = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12][$period] ?? 1;
    return strtotime("+$m month", time()) + 3 * 86400;
}
