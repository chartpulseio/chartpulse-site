<?php
// ChartPulse · Strategy results (admin only). Password = config.php 'admin_password' (set by the owner).
// One page, one question: is each strategy making money or not?  ?p=7|30|90|all  ·  &csv=1 downloads the trades.
// Rebuilt from scratch 10 Nov 2026 (owner: "bahut complicated hai, delete karo, aisa banao jo conclude kar paye").
// Data = the daily Tier-2 trade records saved by torus_report.php (data/reports/YYYY-MM-DD.json). Nothing is recalculated here.
declare(strict_types=1);
require dirname(__DIR__) . '/torus_engine.php';
require dirname(__DIR__) . '/torus_report.php';
header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin');

$pw = (string)t_cfg('admin_password', '');
$tok = $pw !== '' ? hash_hmac('sha256', 'cp-admin-v1', $pw) : '';
$authed = $tok !== '' && hash_equals($tok, (string)($_COOKIE['cp_admin'] ?? ''));
$authed = true;   // TEMP (owner, 4 Nov 2026): password gate off for now -- remove this line to turn it back on.
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$og = isset($_SERVER['HTTP_ORIGIN']) ? parse_url((string)$_SERVER['HTTP_ORIGIN']) : null;
$sameOrigin = !$og || (($og['host'] ?? '') . (isset($og['port']) ? ':' . $og['port'] : '')) === ($_SERVER['HTTP_HOST'] ?? '');
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$msg = '';
if (isset($_GET['logout'])) { setcookie('cp_admin', '', ['expires' => 1, 'path' => '/admin/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']); header('Location: reports.php'); exit; }
if ($post && ($_POST['act'] ?? '') === 'login' && $tok !== '' && $sameOrigin) {
    $lk = T_DATA . '/.admin_' . substr(hash('sha256', t_client_ip()), 0, 16);
    $tries = t_read_json($lk, ['n' => 0, 'at' => 0]); if (time() - $tries['at'] > 900) $tries = ['n' => 0, 'at' => time()];
    if ($tries['n'] >= 8) $msg = 'Bahut zyada galat koshish. 15 minute baad try karein.';
    elseif (str_starts_with($pw, '$2y$') ? password_verify((string)($_POST['pw'] ?? ''), $pw) : hash_equals($pw, (string)($_POST['pw'] ?? ''))) {
        setcookie('cp_admin', $tok, ['expires' => time() + 30 * 86400, 'path' => '/admin/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        header('Location: reports.php'); exit;
    } else { $tries['n']++; t_write_json($lk, $tries); $msg = 'Password galat hai.'; }
}

const AR_MIN = 20;   // closed trades needed before a strategy gets a verdict
$P = ['7' => 'Pichhle 7 din', '30' => 'Pichhle 30 din', '90' => 'Pichhle 90 din', 'all' => 'Shuru se ab tak'];
$p = isset($P[(string)($_GET['p'] ?? '')]) ? (string)$_GET['p'] : '30';

/** Which of the three strategies a saved trade belongs to. */
function ar_strategy(array $x): string {
    $s = strtolower((string)($x['setup'] ?? '')); $r = (string)($x['remark'] ?? '');
    if ($s === 'midtrend' || $r === 'Mid-trend target') return 'Mid-Trend';
    if ($s === 'trend' || $r === 'Long target') return 'Trend';
    if ($s === 'retrace' || $r === 'Small target') return 'Retracement';
    return 'Other';
}
/** Plain totals of a list of trades. R = result measured in units of the risk taken (1R = one stop-loss). */
function ar_stats(array $X): array {
    $C = array_values(array_filter($X, fn($x) => ($x['status'] ?? '') === 'closed' && isset($x['r']) && $x['r'] !== null));
    $n = count($C); $wins = count(array_filter($C, fn($x) => (float)$x['r'] > 0));
    $sl = count(array_filter($C, fn($x) => ($x['reason'] ?? '') === 'Stop-loss'));
    $net = array_sum(array_map(fn($x) => (float)$x['r'], $C));
    return ['all' => count($X), 'closed' => $n, 'open' => count($X) - $n, 'wins' => $wins, 'sl' => $sl,
        'win_pct' => $n ? round($wins / $n * 100) : null, 'sl_pct' => $n ? round($sl / $n * 100) : null,
        'net' => round($net, 1), 'avg' => $n ? round($net / $n, 2) : null,
        'tgt_pct' => $X ? round(count(array_filter($X, fn($x) => !empty($x['touched']))) / count($X) * 100) : null];
}
function ar_group(array $X, callable $key): array {
    $g = []; foreach ($X as $x) $g[(string)$key($x)][] = $x;
    $o = []; foreach ($g as $k => $v) $o[$k] = ar_stats($v);
    uasort($o, fn($a, $b) => $b['net'] <=> $a['net']);
    return $o;
}
/** The conclusion: [css class, headline, one explaining sentence]. */
function ar_verdict(array $s): array {
    if ($s['closed'] < AR_MIN) return ['wait', 'Abhi faisla nahi', 'Sirf ' . $s['closed'] . ' band trades hain. Kam se kam ' . AR_MIN . ' chahiye, tab tak yeh numbers bharose ke nahi.'];
    $a = (float)$s['avg'];
    if ($a >= 0.10) return ['good', 'Kaam kar rahi hai', 'Har trade par average +' . $a . 'R mil raha hai.'];
    if ($a <= -0.10) return ['bad', 'Nuksaan mein hai', 'Har trade par average ' . $a . 'R ja raha hai.'];
    return ['flat', 'Barabar par hai', 'Na kamai, na nuksaan (average ' . ($a >= 0 ? '+' : '') . $a . 'R per trade).'];
}

$T = []; $days = 0; $from = null; $to = null;
if ($authed) {
    $all = tr_dates(); $cut = $p === 'all' ? '' : tr_shift(tr_today(t_now()), -(int)$p);
    $seen = [];
    foreach ($all as $d) {
        if ($cut !== '' && $d < $cut) continue;
        $r = tr_load($d); if (!$r) continue;
        $days++; $from = $from === null || $d < $from ? $d : $from; $to = $to === null || $d > $to ? $d : $to;
        foreach ($r['trades'] ?? [] as $x) { $id = (string)($x['id'] ?? ($x['symbol'] . '|' . $x['entry_time'])); if (isset($seen[$id])) continue; $seen[$id] = 1; $T[] = $x; }
    }
    usort($T, fn($a, $b) => (int)$b['entry_time'] <=> (int)$a['entry_time']);
    if (!empty($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=chartpulse_trades_' . $p . '.csv');
        $o = fopen('php://output', 'w');
        fputcsv($o, ['Entry IST', 'Symbol', 'Market', 'Strategy', 'Side', 'Entry', 'SL', 'Exit', 'Exit reason', 'Status', 'Result R', 'Result %', 'Target hit'], ',', '"', '\\');
        foreach ($T as $x) fputcsv($o, [t_ist((int)$x['entry_time'], 'Y-m-d H:i'), $x['symbol'], $x['market'] ?? '', ar_strategy($x), $x['sig'] ?? '', $x['entry'] ?? '', $x['sl0'] ?? '',
            $x['exit'] ?? '', $x['reason'] ?? '', $x['status'] ?? '', $x['r'] ?? '', $x['pct'] ?? '', !empty($x['touched']) ? 'yes' : 'no'], ',', '"', '\\');
        exit;
    }
}
$BY = ['Trend' => [], 'Retracement' => [], 'Mid-Trend' => []];
foreach ($T as $x) { $k = ar_strategy($x); if (isset($BY[$k])) $BY[$k][] = $x; }
$NOTE = ['Trend' => 'MTF ke saath, Day High / Day Low se entry. Lamba target.', 'Retracement' => 'MTF ke khilaaf, chhota target.', 'Mid-Trend' => 'MTF ke saath, kisi bade zone par entry.'];
$num = fn($v, $suf = '') => $v === null ? '–' : $e($v) . $suf;
$sgn = fn($v) => $v === null ? '–' : ($v > 0 ? '+' : '') . $e($v);
$cls = fn($v) => $v === null ? '' : ($v > 0 ? 'up' : ($v < 0 ? 'dn' : ''));
?><!doctype html>
<html lang="hi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Strategy results · ChartPulse admin</title>
<style>
:root{--bg:#0b0d10;--panel:#14171c;--line:#252a32;--ink:#eceef1;--ink2:#a4abb6;--up:#22ab94;--dn:#f7525f;--amber:#f4b740}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:1040px;margin:0 auto;padding:24px 16px 64px}
h1{font-size:22px;margin:0 0 4px}h2{font-size:18px;margin:0}p{margin:0}
.sub{color:var(--ink2);font-size:13.5px}
.bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:18px 0 22px}
.bar a{padding:7px 13px;border:1px solid var(--line);border-radius:8px;color:var(--ink2);text-decoration:none;font-size:13.5px}
.bar a.on{background:var(--ink);color:var(--bg);border-color:var(--ink);font-weight:600}.bar a:focus-visible,summary:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--amber);outline-offset:2px}
.bar .sp{flex:1}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px}
.head{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:baseline;justify-content:space-between}
.v{display:inline-block;padding:4px 11px;border-radius:999px;font-weight:700;font-size:13.5px}
.v.good{background:rgba(34,171,148,.16);color:var(--up)}.v.bad{background:rgba(247,82,95,.16);color:var(--dn)}.v.flat,.v.wait{background:rgba(244,183,64,.14);color:var(--amber)}
.why{margin:8px 0 14px;color:var(--ink2)}
.nums{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:10px;margin-bottom:6px}
.nums div{border:1px solid var(--line);border-radius:10px;padding:10px 12px}
.nums b{display:block;font-size:20px;font-variant-numeric:tabular-nums}.nums span{font-size:12.5px;color:var(--ink2)}
.up{color:var(--up)}.dn{color:var(--dn)}
.two{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-top:14px}
h3{font-size:13.5px;color:var(--ink2);font-weight:600;margin:0 0 6px}
.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13.5px;font-variant-numeric:tabular-nums}
th,td{padding:6px 8px;text-align:right;border-bottom:1px solid var(--line);white-space:nowrap}th:first-child,td:first-child{text-align:left}
th{color:var(--ink2);font-weight:500;font-size:12.5px}
summary{cursor:pointer;font-weight:600}details .scroll{margin-top:12px;max-height:520px;overflow:auto}
.note{font-size:13px;color:var(--ink2);margin-top:22px}
form.login{max-width:320px;margin:80px auto;display:grid;gap:10px}input,button{font:inherit;padding:10px 12px;border-radius:8px;border:1px solid var(--line);background:var(--panel);color:var(--ink)}button{background:var(--ink);color:var(--bg);font-weight:600;cursor:pointer}
</style></head><body><main>
<?php if (!$authed): ?>
<form class="login" method="post"><h1>ChartPulse admin</h1><?php if ($msg): ?><p class="dn"><?= $e($msg) ?></p><?php endif; ?>
<?php if ($tok === ''): ?><p class="sub">config.php mein 'admin_password' set nahi hai.</p><?php else: ?>
<input type="hidden" name="act" value="login"><input type="password" name="pw" placeholder="Password" aria-label="Password" autofocus required><button>Login</button><?php endif; ?></form>
<?php else: ?>
<h1>Strategy results</h1>
<p class="sub">Har strategy ka seedha nateeja: kamai ho rahi hai ya nahi. <?= $days ? $e($days) . ' din ka data (' . $e($from) . ' se ' . $e($to) . '), ' . count($T) . ' trades.' : 'Is period ka koi data nahi mila.' ?></p>
<nav class="bar" aria-label="Period"><?php foreach ($P as $k => $lbl): ?><a href="?p=<?= $e($k) ?>"<?= $k === $p ? ' class="on" aria-current="page"' : '' ?>><?= $e($lbl) ?></a><?php endforeach; ?>
<span class="sp"></span><a href="?p=<?= $e($p) ?>&amp;csv=1">CSV download</a></nav>

<?php foreach ($BY as $name => $X): $s = ar_stats($X); [$vc, $vh, $vw] = ar_verdict($s); ?>
<section class="card">
  <div class="head"><div><h2><?= $e($name) ?></h2><p class="sub"><?= $e($NOTE[$name]) ?></p></div><span class="v <?= $vc ?>"><?= $e($vh) ?></span></div>
<?php if (!$X): ?>
  <p class="why">Is period mein is strategy ka koi trade record nahi hua.</p>
<?php else: ?>
  <p class="why"><?= $e($vw) ?></p>
  <div class="nums">
    <div><b><?= $e($s['closed']) ?></b><span>band trades<?= $s['open'] ? ' (+' . $e($s['open']) . ' open)' : '' ?></span></div>
    <div><b><?= $num($s['win_pct'], '%') ?></b><span>profit mein band</span></div>
    <div><b><?= $num($s['sl_pct'], '%') ?></b><span>stop-loss hit</span></div>
    <div><b><?= $num($s['tgt_pct'], '%') ?></b><span>target chhua</span></div>
    <div><b class="<?= $cls($s['net']) ?>"><?= $sgn($s['net']) ?>R</b><span>kul nateeja</span></div>
    <div><b class="<?= $cls($s['avg']) ?>"><?= $sgn($s['avg']) ?>R</b><span>average per trade</span></div>
  </div>
  <div class="two">
<?php foreach (['Market ke hisaab se' => ar_group($X, fn($x) => ($x['market'] ?? '') ?: 'Other'), 'CALL / PUT ke hisaab se' => ar_group($X, fn($x) => $x['sig'] ?? '?')] as $ttl => $G): ?>
    <div><h3><?= $e($ttl) ?></h3><div class="scroll"><table><thead><tr><th scope="col"><?= $ttl[0] === 'M' ? 'Market' : 'Side' ?></th><th scope="col">Trades</th><th scope="col">Profit %</th><th scope="col">SL %</th><th scope="col">Kul R</th><th scope="col">Avg R</th></tr></thead><tbody>
<?php foreach ($G as $k => $g): ?>      <tr><td><?= $e($k) ?></td><td><?= $e($g['closed']) ?></td><td><?= $num($g['win_pct'], '%') ?></td><td><?= $num($g['sl_pct'], '%') ?></td><td class="<?= $cls($g['net']) ?>"><?= $sgn($g['net']) ?></td><td class="<?= $cls($g['avg']) ?>"><?= $sgn($g['avg']) ?></td></tr>
<?php endforeach; ?>    </tbody></table></div></div>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
<?php endforeach; ?>

<details class="card"><summary>Saare trades dekho (<?= count($T) ?>)</summary>
<div class="scroll"><table><thead><tr><th scope="col">Entry (IST)</th><th scope="col">Symbol</th><th scope="col">Strategy</th><th scope="col">Side</th><th scope="col">Nateeja R</th><th scope="col">Kaise band hua</th></tr></thead><tbody>
<?php foreach ($T as $x): $r = isset($x['r']) && $x['r'] !== null ? (float)$x['r'] : null; ?>
<tr><td><?= $e(t_ist((int)$x['entry_time'], 'd M H:i')) ?></td><td><?= $e($x['symbol']) ?></td><td><?= $e(ar_strategy($x)) ?></td><td><?= $e($x['sig'] ?? '') ?></td><td class="<?= $cls($r) ?>"><?= $sgn($r) ?></td><td><?= $e(($x['status'] ?? '') === 'open' ? 'Abhi open' : ($x['reason'] ?? '–')) ?></td></tr>
<?php endforeach; ?></tbody></table></div></details>

<p class="note">R ka matlab: 1R = ek stop-loss jitna risk. +2R = risk ka dugna kamaya, −1R = poora stop-loss gaya. Faisla tabhi dikhta hai jab kam se kam <?= AR_MIN ?> band trades hon.</p>
<?php endif; ?>
</main></body></html>
