<?php
// ChartPulse · Research reports (admin only). Password = config.php 'admin_password' (set by the owner).
//   ?d=YYYY-MM-DD&v=day|week|month   ·   &csv=1 downloads the trades of the view   ·   POST act=build|send
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
$msg = '';

if (isset($_GET['logout'])) { setcookie('cp_admin', '', ['expires' => 1, 'path' => '/admin/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']); header('Location: reports.php'); exit; }
if ($post && ($_POST['act'] ?? '') === 'login' && $tok !== '' && $sameOrigin) {
    $lk = T_DATA . '/.admin_' . substr(hash('sha256', t_client_ip()), 0, 16);
    $tries = t_read_json($lk, ['n' => 0, 'at' => 0]); if (time() - $tries['at'] > 900) $tries = ['n' => 0, 'at' => time()];
    if ($tries['n'] >= 8) $msg = 'Too many attempts. Try again in 15 minutes.';
    elseif (str_starts_with($pw, '$2y$') ? password_verify((string)($_POST['pw'] ?? ''), $pw) : hash_equals($pw, (string)($_POST['pw'] ?? ''))) {   // plain text or a bcrypt hash (password_hash) in config.php
        setcookie('cp_admin', $tok, ['expires' => time() + 30 * 86400, 'path' => '/admin/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
        header('Location: reports.php'); exit;
    } else { $tries['n']++; t_write_json($lk, $tries); $msg = 'Wrong password.'; }
}

$now = t_now(); $today = tr_today($now);
$d = tr_valid((string)($_GET['d'] ?? '')) ? (string)$_GET['d'] : (tr_dates()[0] ?? tr_shift($today, -1));
$v = in_array($_GET['v'] ?? '', ['day', 'week', 'month'], true) ? $_GET['v'] : 'day';

if ($authed && $post && $sameOrigin) {
    $act = $_POST['act'] ?? '';
    if ($act === 'build') { @set_time_limit(240); tr_build($d); $msg = 'Report for ' . $d . ' rebuilt from the latest data.'; }
    if ($act === 'study') { @set_time_limit(240); $r = tr_load($d) ?: tr_build($d); $miss = ts_run($r, 200); $r['conclusions'] = ts_conclude($r['trades']); if (!$miss) $r['study_at'] = $r['study_at'] ?? time(); tr_save($d, $r);
        $msg = $miss ? "48-hour study done for part of the day; $miss trades left, press again." : '48-hour study done for ' . $d . '.'; }
    if ($act === 'send') { $r = tr_load($d) ?: tr_build($d); [$ok, $info] = tr_send($r); $msg = $ok ? 'Sent to the admin Telegram chat.' : 'Telegram: ' . $info; }
}

/* ---------------- data for the view ---------------- */
if ($authed) {
    if ($v === 'day') { $R = tr_load($d); $T = $R['trades'] ?? []; $S = $R['summary'] ?? tr_summary([]); $from = $to = $d; }
    else { [$from, $to] = $v === 'week' ? tr_week($d) : tr_month($d); $R = tr_range($from, $to); $T = $R['trades']; $S = $R['summary']; }
    if (!empty($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=chartpulse_trades_' . $from . ($to !== $from ? '_' . $to : '') . '.csv');
        $o = fopen('php://output', 'w'); $ist = fn($t) => $t ? t_ist((int)$t, 'Y-m-d H:i') : '';
        fputcsv($o, ['Entry IST', 'Symbol', 'TradingView', 'Name', 'Market', 'Side', 'Setup', 'Alignment', 'Signal IST', 'Signal price', 'Pattern', 'Pattern candle IST', 'Pattern high', 'Pattern low',
            'Confirmed IST', '5m EMA cross IST', '5m EMA cross price', 'Entry price', 'Day extreme', 'ATR5', 'SL', 'Min target', 'Target level', 'Target hit IST', 'Exit IST', 'Exit price', 'Exit reason',
            'Status', 'Result %', 'Result R', 'Pips', 'Points', 'MFE R', 'MAE R', 'Max run R', 'Max run %', 'Max run IST', 'Pivots crossed', 'After SL R', 'Target after SL'], ',', '"', '\\');
        foreach ($T as $x) { $pb = $x['pattern_bar'] ?? []; $c5 = $x['ema5_cross'] ?? [null, null];
            fputcsv($o, [$ist($x['entry_time']), $x['symbol'], t_tv_code($x['symbol']), $x['name'], $x['market'], $x['sig'], tr_setup($x), $x['alignment'] ?? '', $ist($x['signal_time'] ?? null), $x['signal_price'] ?? '',
                $x['pattern'], $ist($pb['t'] ?? null), $pb['h'] ?? '', $pb['l'] ?? '', $ist($x['confirm_time'] ?? null), $ist($c5[0] ?? null), $c5[1] ?? '', $x['entry'], $x['day_ext'] ?? '', $x['atr5'] ?? '',
                $x['sl0'], $x['target'], $x['target_label'], $ist($x['target_time'] ?? null), $ist($x['exit_time'] ?? null), $x['exit'] ?? '', $x['reason'] ?? '', $x['status'], $x['pct'], $x['r'],
                $x['pips'] ?? '', $x['points'] ?? '', $x['mfe_r'] ?? '', $x['mae_r'] ?? '', $x['run_r'] ?? '', $x['run_pct'] ?? '', $ist($x['run_time'] ?? null), implode(' ', $x['pivots_crossed'] ?? []),
                $x['after_sl_r'] ?? '', isset($x['target_after_sl']) ? ($x['target_after_sl'] ? 'yes' : 'no') : ''], ',', '"', '\\'); }
        exit;
    }
}
$sg = fn($x) => $x === null ? '–' : (($x > 0 ? '+' : '') . $x);
$C = $authed ? ($v === 'day' ? ($R['conclusions'] ?? null) : (array_filter($T, fn($x) => !empty($x['study'])) ? ts_conclude($T) : null)) : null;
/** 48-hour study tables (owner, 30 Sep 2026). */
function studyTable(string $title, array $g, callable $sg, bool $trend = true): string {
    $g = array_filter($g, fn($s) => $s['studied'] > 0); if (!$g) return '';
    $pc = fn($v) => $v === null ? '–' : $v . '%';
    $r = ''; foreach ($g as $k => $s) $r .= '<tr><td>' . htmlspecialchars((string)$k) . '</td><td>' . $s['studied'] . '</td><td class="up">' . $pc($s['t1']) . '</td>'
        . ($trend ? '<td>' . $pc($s['t2']) . '</td><td>' . $pc($s['t3']) . '</td>' : '<td>' . ($s['t1_min'] === null ? '–' : $s['t1_min'] . ' min') . '</td>')
        . '<td class="down">' . $pc($s['sl_first']) . '</td><td>+' . ($s['best_sl_r'] ?? '–') . 'R</td><td class="' . ($s['net_r'] > 0 ? 'up' : ($s['net_r'] < 0 ? 'down' : '')) . '">' . $sg($s['net_r']) . 'R</td></tr>';
    return '<section class="card"><h3>' . $title . '</h3><div class="tw"><table><thead><tr><th></th><th>Trades</th><th>T1 before SL</th>'
        . ($trend ? '<th>T2</th><th>T3</th>' : '<th>Time to T1</th>') . '<th>SL first</th><th>Best move</th><th>Net (exits)</th></tr></thead><tbody>' . $r . '</tbody></table></div></section>';
}
$h = fn($t) => $t ? t_ist((int)$t, 'H:i') : '–';
$SLD = $authed && $T ? ts_sl($T) : null;   // stop-loss department, from the trades in view (48 h parts once the study has run)
/** Stop-loss department tables (owner, 30 Sep 2026). */
function slTable(string $title, array $g): string {
    if (!$g) return '';
    $r = ''; foreach ($g as $k => $v) $r .= '<tr><td>' . htmlspecialchars((string)$k) . '</td><td>' . $v['trades'] . '</td><td class="down">' . $v['sl'] . '</td><td>' . ($v['rate'] ?? '–') . '%</td><td>' . ($v['share'] ?? '–') . '%</td></tr>';
    return '<section class="card"><h3>' . $title . '</h3><div class="tw"><table><thead><tr><th></th><th>Trades</th><th>Stop-loss</th><th>SL rate</th><th>Share of all SL</th></tr></thead><tbody>' . $r . '</tbody></table></div></section>';
}
$q = fn(array $p) => 'reports.php?' . http_build_query($p + ['d' => $d, 'v' => $v]);
function tiles(array $a, callable $sg): string {
    $t = [['Trades', $a['n']], ['Won', $a['wins']], ['Stop-loss', $a['sl']], ['Open', $a['open']], ['Win rate', $a['win_rate'] === null ? '–' : $a['win_rate'] . '%'],
        ['Net result', $sg($a['total_r']) . 'R'], ['Average', $sg($a['avg_r']) . 'R'], ['Profit factor', $a['pf'] ?? '–'], ['Target hit', $a['target_rate'] === null ? '–' : $a['target_rate'] . '%'],
        ['Avg max run', '+' . ($a['avg_run_r'] ?? '–') . 'R'], ['Avg worst dip', ($a['avg_mae_r'] ?? '–') . 'R'], ['SL then target', $a['sl_then_target']]];
    return '<div class="tiles">' . implode('', array_map(fn($x) => '<div><span>' . $x[0] . '</span><b>' . $x[1] . '</b></div>', $t)) . '</div>';
}
function splitTable(string $title, array $g, callable $sg): string {
    if (!$g) return '';
    $r = ''; foreach ($g as $k => $a) $r .= '<tr><td>' . htmlspecialchars((string)$k) . '</td><td>' . $a['n'] . '</td><td>' . ($a['win_rate'] === null ? '–' : $a['win_rate'] . '%') . '</td><td class="' . ($a['total_r'] > 0 ? 'up' : ($a['total_r'] < 0 ? 'down' : '')) . '">' . $sg($a['total_r']) . 'R</td><td>' . $sg($a['avg_r']) . 'R</td><td>' . ($a['pf'] ?? '–') . '</td><td>+' . ($a['avg_run_r'] ?? '–') . 'R</td><td>' . ($a['target_rate'] ?? '–') . '%</td></tr>';
    return '<section class="card"><h3>' . $title . '</h3><div class="tw"><table><thead><tr><th></th><th>Trades</th><th>Win rate</th><th>Net</th><th>Avg</th><th>PF</th><th>Avg max run</th><th>Target hit</th></tr></thead><tbody>' . $r . '</tbody></table></div></section>';
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Research reports · ChartPulse</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<style>
:root{--bg:#09090b;--panel:#111114;--panel2:#16161a;--line:rgba(255,255,255,.07);--line2:rgba(255,255,255,.12);--ink:#ececef;--ink2:#b4b4bc;--muted:#80808a;--call:#22AB94;--put:#F7525F;--gold:#F4B740;--blue:#2962FF;color-scheme:dark}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 "IBM Plex Sans",system-ui,sans-serif;padding-inline:20px;padding-block:24px 60px}
.wrap{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:18px}
a{color:var(--gold)}code,.mono{font-family:"IBM Plex Mono",ui-monospace,monospace}
header.top{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap}
.eyebrow{font:500 11px "IBM Plex Mono",monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--gold)}
h1{font-size:26px;margin:4px 0 0;font-weight:600;letter-spacing:-.01em}
h2{font-size:18px;margin:0 0 4px}h3{font-size:13px;margin:0 0 10px;font:600 11.5px "IBM Plex Mono",monospace;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn,.tab{display:inline-flex;align-items:center;gap:6px;padding:8px 13px;border-radius:9px;border:1px solid var(--line2);background:var(--panel2);color:var(--ink);text-decoration:none;font:500 13px "IBM Plex Sans",sans-serif;cursor:pointer}
.btn:hover,.tab:hover{border-color:rgba(255,255,255,.25)}.tab.on{background:linear-gradient(180deg,#FFD27A,var(--gold));color:#1A1203;border-color:transparent}
.btn.gold{background:linear-gradient(180deg,#FFD27A,var(--gold));color:#1A1203;border-color:transparent}
input[type=date],input[type=password]{background:var(--panel2);border:1px solid var(--line2);color:var(--ink);border-radius:9px;padding:8px 10px;font:500 13px "IBM Plex Mono",monospace}
.note{padding:10px 14px;border-radius:10px;border:1px solid rgba(244,183,64,.35);background:rgba(244,183,64,.08);color:var(--ink)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px 18px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.tiles div{background:var(--panel);padding:12px 14px;display:flex;flex-direction:column;gap:2px}.tiles span{font-size:11.5px;color:var(--muted)}.tiles b{font:600 19px "IBM Plex Mono",monospace;font-variant-numeric:tabular-nums}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:14px}
.tw{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
th:first-child,td:first-child{text-align:left}th{font:500 10.5px "IBM Plex Mono",monospace;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}td{font-family:"IBM Plex Mono",monospace}td:first-child{font-family:"IBM Plex Sans",sans-serif}
.up{color:var(--call)}.down{color:var(--put)}.gold{color:var(--gold)}
details.tr{background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden}
details.tr+details.tr{margin-top:8px}
details.tr summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:56px minmax(150px,1.4fr) 70px 110px 1fr 90px;gap:10px;align-items:center;padding:11px 14px}
details.tr summary::-webkit-details-marker{display:none}
details.tr summary:hover{background:rgba(255,255,255,.02)}details.tr[open] summary{border-bottom:1px solid var(--line)}
.side{font:600 11px "IBM Plex Mono",monospace;padding:3px 8px;border-radius:6px;text-align:center}.side.CALL{color:var(--call);background:rgba(34,171,148,.12)}.side.PUT{color:var(--put);background:rgba(247,82,95,.12)}
.nm b{display:block}.nm small{color:var(--muted);font-size:11.5px}.setup{font-size:12px;color:var(--ink2)}
.res{text-align:right;font:600 14px "IBM Plex Mono",monospace}.why{font-size:12px;color:var(--muted)}
.tl{list-style:none;margin:0;padding:14px 18px;display:grid;gap:8px}
.tl li{display:grid;grid-template-columns:150px 1fr;gap:12px;font-size:13.5px}.tl li span{color:var(--muted);font-size:12.5px}.tl li b{font-weight:500}
.tv{font:500 11px "IBM Plex Mono",monospace;color:var(--ink2);background:rgba(41,98,255,.12);border:1px solid rgba(41,98,255,.3);border-radius:4px;padding:0 5px}
.empty{color:var(--muted);padding:12px 0}
.concl ul{margin:0 0 10px;padding-left:18px;display:grid;gap:6px;font-size:14.5px}.concl li::marker{color:var(--gold)}
.st{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line);font-size:12.5px;color:var(--ink2)}
.st .lv{font-family:"IBM Plex Mono",monospace}.st small{color:var(--muted)}.st .btn{padding:5px 10px;font-size:12px}
.login{max-width:360px;margin:12vh auto 0;display:flex;flex-direction:column;gap:12px}
@media (max-width:760px){details.tr summary{grid-template-columns:48px 1fr 70px;row-gap:4px}details.tr summary .setup,details.tr summary .why{display:none}.tl li{grid-template-columns:1fr;gap:2px}}
</style></head><body><div class="wrap">
<?php if (!$authed): ?>
  <form class="login card" method="post" action="reports.php">
    <div class="eyebrow">ChartPulse · Admin</div><h1>Research reports</h1>
    <?php if ($tok === ''): ?>
      <p class="note">Add <code>'admin_password' =&gt; 'your-password'</code> to <code>config.php</code> on the server, then open this page again.</p>
    <?php else: ?>
      <input type="hidden" name="act" value="login"><label for="pw">Password</label><input type="password" id="pw" name="pw" autocomplete="current-password" required autofocus>
      <button class="btn gold" type="submit">Open reports</button>
      <?php if ($msg): ?><p class="note"><?= $e($msg) ?></p><?php endif; ?>
    <?php endif; ?>
  </form>
<?php else:
  $title = $v === 'day' ? (new DateTime($d))->format('l, d M Y') : ((new DateTime($from))->format('d M') . ' – ' . (new DateTime($to))->format('d M Y'));
  $step = $v === 'day' ? 1 : ($v === 'week' ? 7 : 0);
  $prev = $v === 'month' ? (new DateTime($from))->modify('-1 month')->format('Y-m-d') : tr_shift($d, -$step);
  $next = $v === 'month' ? (new DateTime($from))->modify('+1 month')->format('Y-m-d') : tr_shift($d, $step);
?>
  <header class="top">
    <div><div class="eyebrow">ChartPulse · Admin · Tier-2 research</div><h1><?= $e($title) ?></h1>
      <div class="setup"><?= $v === 'day' ? ($R ? 'Report built ' . t_ist((int)$R['built_at'], 'd M H:i') . ' IST' . (empty($R['final']) ? ' · day not final yet' : '') . (!empty($R['sent_at']) ? ' · Telegram ' . (($R['sent_ok'] ?? true) ? 'sent ' : 'not sent (' . $R['send_info'] . ') ') . t_ist((int)$R['sent_at'], 'd M H:i') : '') : 'No report saved for this day yet') : ($R['days'] . ' saved day(s) in this ' . $v) ?> · times in IST</div></div>
    <div class="bar">
      <a class="tab<?= $v === 'day' ? ' on' : '' ?>" href="<?= $e($q(['v' => 'day'])) ?>">Day</a><a class="tab<?= $v === 'week' ? ' on' : '' ?>" href="<?= $e($q(['v' => 'week'])) ?>">Week</a><a class="tab<?= $v === 'month' ? ' on' : '' ?>" href="<?= $e($q(['v' => 'month'])) ?>">Month</a>
      <a class="btn" href="<?= $e($q(['d' => $prev])) ?>" aria-label="Previous">←</a>
      <form method="get" action="reports.php" class="bar"><input type="hidden" name="v" value="<?= $e($v) ?>"><input type="date" name="d" value="<?= $e($d) ?>" max="<?= $e($today) ?>" onchange="this.form.submit()"></form>
      <a class="btn" href="<?= $e($q(['d' => $next])) ?>" aria-label="Next">→</a>
      <a class="btn" href="<?= $e($q(['csv' => 1])) ?>">⬇ CSV</a>
      <a class="btn" href="reports.php?logout=1">Log out</a>
    </div>
  </header>
  <?php if ($msg): ?><p class="note"><?= $e($msg) ?></p><?php endif; ?>
  <?php if ($v === 'day'): ?>
    <form method="post" action="<?= $e($q([])) ?>" class="bar">
      <button class="btn" name="act" value="build" type="submit"><?= $R ? '↻ Rebuild this day' : '＋ Build this day now' ?></button>
      <button class="btn" name="act" value="send" type="submit">✈ Send to Telegram</button>
      <button class="btn" name="act" value="study" type="submit">🧪 Run 48-hour study</button>
      <span class="setup">Trades are closed the next morning; the report with the 48-hour target study is sent two days later at 08:00 IST (Monday's on Wednesday).</span>
    </form>
  <?php endif; ?>

  <?php if ($C): ?>
  <section class="card concl"><h3>Conclusions · 48-hour target study</h3>
    <ul><?php foreach ($C['lines'] as $ln): ?><li><?= $e($ln) ?></li><?php endforeach; ?></ul>
    <p class="setup">T1 = the trade's own minimum target (nearest pivot beyond the entry); T2 and T3 = the next pivots. "Before SL" = reached within 48 hours of the entry without the stop-loss being hit first. Best move = the largest move in the trade's favour before any stop-loss, in R (1R = entry-to-stop distance). Net = the actual result of our exits.</p>
  </section>
  <div class="grid2"><?= studyTable('Markets: where targets come best', $C['market'], $sg) ?><?= studyTable('Trend calls: how far they go', ['All trend' => $C['trend']] + $C['trend_market'], $sg) ?></div>
  <div class="grid2"><?= studyTable('Retracements: single target touch', ['All retracement' => $C['retrace']] + $C['retrace_market'], $sg, false) ?></div>
  <div class="grid2"><?= studyTable('Entry zone: where targets come best', $C['zone'] ?? [], $sg) ?><?= studyTable('Entry tick: which trigger works best', $C['tick'] ?? [], $sg) ?></div>
  <?php elseif ($v === 'day' && $R): ?><p class="note">The 48-hour target study for this day runs two days later at 08:00 IST (or press "Run 48-hour study").</p><?php endif; ?>
  <?php if ($SLD && $SLD['n']): $tl = fn($r) => 'trade.php?' . http_build_query(['d' => $r['d'], 'id' => $r['id']]); ?>
  <section class="card concl" id="sl"><h3>Stop-loss department</h3>
    <ul><?php foreach ($SLD['lines'] as $ln): ?><li><?= $e($ln) ?></li><?php endforeach; ?></ul>
    <p class="setup">"T1 later" = after our stop-loss, price still reached T1 within 48 h of the entry. "T1 then stop-loss" = the trade reached T1 first and, if simply held, came back to the stop within 48 h (our real exit is the 5-EMA after T1). Re-entry = the next Tier-2 trade on the same symbol within 12 h of the stop. The 48 h parts fill in once the study has run.</p>
  </section>
  <div class="grid2"><?= slTable('Stop-losses: trend vs retracement', $SLD['setup']) ?><?= slTable('Stop-losses by market', array_filter($SLD['market'], fn($v) => $v['sl'] > 0)) ?></div>
  <?php if ($SLD['t1_then_sl']['list']): ?>
  <section class="card"><h3>Reached T1, then came back to the stop-loss (if held) · <?= $SLD['t1_then_sl']['back'] ?> of <?= $SLD['t1_then_sl']['hit'] ?> · trend <?= $SLD['t1_then_sl']['trend'] ?> (<?= $SLD['t1_then_sl']['trend_of_back'] ?? '–' ?>% of them)</h3>
    <div class="tw"><table><thead><tr><th>Trade</th><th>Market</th><th>Setup</th><th>Side</th><th>T1 at</th><th>Back to SL at</th><th>Our result</th></tr></thead><tbody>
    <?php foreach ($SLD['t1_then_sl']['list'] as $r): ?><tr><td><a href="<?= $e($tl($r)) ?>"><?= $e($r['name']) ?></a></td><td><?= $e($r['market']) ?></td><td><?= $e($r['setup']) ?></td><td class="<?= $r['sig'] === 'CALL' ? 'up' : 'down' ?>"><?= $e($r['sig']) ?></td><td><?= $e(t_ist((int)$r['t1'], 'd M H:i')) ?></td><td><?= $e(t_ist((int)$r['sl'], 'd M H:i')) ?></td><td class="<?= ($r['r'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $sg($r['r']) ?>R</td></tr><?php endforeach; ?>
    </tbody></table></div></section>
  <?php endif; ?>
  <details class="card"><summary style="cursor:pointer"><b>All <?= $SLD['n'] ?> stop-loss trades</b> <span class="setup">· time to stop, stop width, what happened next</span></summary>
    <div class="tw" style="margin-top:10px"><table><thead><tr><th>Trade</th><th>Entry</th><th>Market</th><th>Setup</th><th>Side</th><th>To SL</th><th>Width</th><th>T1 later</th><th>Re-entry</th></tr></thead><tbody>
    <?php foreach ($SLD['rows'] as $r): $re = $r['reentry']; ?><tr><td><a href="<?= $e($tl($r)) ?>"><?= $e($r['name']) ?></a></td><td><?= $e(t_ist($r['entry_time'], $v === 'day' ? 'H:i' : 'd M H:i')) ?></td><td><?= $e($r['market']) ?></td><td><?= $e($r['setup']) ?></td>
      <td class="<?= $r['sig'] === 'CALL' ? 'up' : 'down' ?>"><?= $e($r['sig']) ?></td><td><?= $r['mins'] ?> min</td><td><?= $r['width_atr'] !== null ? $r['width_atr'] . '× ATR5' : '–' ?></td><td class="<?= $r['t1_later'] ? 'gold' : '' ?>"><?= $r['t1_later'] ? 'yes' : 'no' ?></td>
      <td><?= $re ? $e($re['sig'] . ' · ' . $re['reason'] . ' · ') . '<span class="' . (($re['r'] ?? 0) > 0 ? 'up' : 'down') . '">' . $sg($re['r']) . 'R</span>' : '–' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
  <?php endif; ?>
  <?= tiles($S['all'], $sg) ?>
  <div class="grid2"><?= splitTable('Trend vs retracement', $S['setup'], $sg) ?><?= splitTable('Exit reason', $S['exit'], $sg) ?></div>
  <div class="grid2"><?= splitTable('Entry zone', $S['zone'] ?? [], $sg) ?><?= splitTable('Entry tick', $S['tick'] ?? [], $sg) ?></div>
  <?php if ($v !== 'day'): ?>
    <div class="grid2"><?= splitTable('Market', $S['market'], $sg) ?><?= splitTable('Pattern', $S['pattern'], $sg) ?></div>
    <div class="grid2"><?= splitTable('Side', $S['side'], $sg) ?><?= splitTable('Entry hour (IST)', $S['hour'], $sg) ?></div>
  <?php endif; ?>

  <section><h2>Trades <span class="setup">(<?= count($T) ?>, click a row for the full story)</span></h2>
  <?php if (!$T): ?><p class="empty">No Tier-2 trade in this period.</p><?php endif;
  foreach (array_reverse($T) as $x): $x += array_fill_keys(['signal_time', 'signal_price', 'alignment', 'pattern_bar', 'confirm_time', 'ema5_cross', 'day_ext', 'atr5', 'target_time', 'mfe_r', 'mae_r', 'run_r', 'run_pct', 'run_time', 'pivots_crossed', 'after_sl_r', 'target_after_sl', 'exit', 'exit_time', 'reason'], null);
    $call = $x['sig'] === 'CALL'; $pb = $x['pattern_bar']; $c5 = $x['ema5_cross']; $cls = $x['r'] > 0 ? 'up' : ($x['r'] < 0 ? 'down' : ''); ?>
    <details class="tr"><summary>
      <span class="mono"><?= $v === 'day' ? $h($x['entry_time']) : t_ist((int)$x['entry_time'], 'd M') ?></span>
      <span class="nm"><b><?= $e($x['name']) ?></b><small><span class="tv"><?= $e(t_tv_code($x['symbol'])) ?></span> · <?= $e($x['market']) ?></small></span>
      <span class="side <?= $e($x['sig']) ?>"><?= $e($x['sig']) ?></span>
      <span class="setup"><?= $e(tr_setup($x)) ?> · <?= $e($x['pattern']) ?></span>
      <span class="why"><?= $x['status'] === 'open' ? 'Open' : $e($x['reason']) . ' ' . $h($x['exit_time']) ?><?= !empty($x['touched']) ? ' · target ✓' : '' ?></span>
      <span class="res <?= $cls ?>"><?= $sg($x['r']) ?>R</span>
    </summary>
    <?php $st = $x['study'] ?? null; $u = $st['unit'] ?? ''; ?>
    <div class="st"><a class="btn" href="trade.php?<?= $e(http_build_query(['d' => t_ist((int)$x['entry_time'], 'Y-m-d'), 'id' => $x['id']])) ?>">📈 Chart: the whole picture</a>
      <?php if ($st): ?><span>48 h:</span><?php foreach ($st['lv'] as $j => $lv): ?><span class="lv <?= $lv['t_sl'] ? 'up' : ($lv['t'] ? 'gold' : 'down') ?>">T<?= $j + 1 ?> <?= $e($lv['k']) ?> <?= $lv['t_sl'] ? '✓ ' . $h($lv['t_sl']) : ($lv['t'] ? '✓ after SL' : '✗') ?> <small>(<?= $lv['move'] . ' ' . $u ?>)</small></span><?php endforeach; ?>
      <span>best <b class="up">+<?= $st['best_sl_r'] ?>R</b> (<?= $st['best_sl_move'] . ' ' . $u ?>)<?= $st['sl_t'] ? ' · SL hit ' . $h($st['sl_t']) : ' · no SL in ' . $st['hours'] . ' h' ?></span><?php endif; ?></div>
    <ul class="tl">
      <li><span>Tier-1 signal</span><b><?= $h($x['signal_time']) ?><?= $x['signal_price'] !== null ? ' @ ' . $e(t_fp((float)$x['signal_price'])) : '' ?><?= $x['alignment'] ? ' · ' . (int)$x['alignment'] . '/4 aligned' : '' ?> · <?= $e(tr_setup($x)) ?></b></li>
      <li><span>10m pattern</span><b><?= $e($x['pattern']) ?><?= $pb ? ' · candle ' . $h($pb['t']) . '–' . $h($pb['t'] + 600) . ' · O ' . t_fp((float)$pb['o']) . ' H ' . t_fp((float)$pb['h']) . ' L ' . t_fp((float)$pb['l']) . ' C ' . t_fp((float)$pb['c']) : '' ?></b></li>
      <li><span>Confirmation</span><b>next 10m candle closed <?= $call ? 'higher' : 'lower' ?> at <?= $h($x['confirm_time']) ?></b></li>
      <li><span>5m 5-EMA cross</span><b><?= $c5 ? $h($c5[0]) . ' @ ' . t_fp((float)$c5[1]) : '–' ?></b></li>
      <li><span>Entry (3m 5-EMA cross)</span><b class="gold"><?= $h($x['entry_time']) ?> @ <?= t_fp((float)$x['entry']) ?></b></li>
      <li><span>Stop-loss</span><b><span class="down"><?= t_fp((float)$x['sl0']) ?></span> = <?= $call ? 'Day Low' : 'Day High' ?> <?= $x['day_ext'] !== null ? t_fp((float)$x['day_ext']) : '–' ?> <?= $call ? '−' : '+' ?> 0.4 × ATR5 <?= $x['atr5'] !== null ? t_fp((float)$x['atr5']) : '–' ?></b></li>
      <li><span>Minimum target</span><b><span class="up"><?= $e($x['target_label']) ?> <?= t_fp((float)$x['target']) ?></span> <?= !empty($x['touched']) ? '· reached ' . $h($x['target_time']) : '· not reached' ?></b></li>
      <li><span>Best / worst before exit</span><b>+<?= $x['mfe_r'] ?? '–' ?>R / −<?= $x['mae_r'] ?? '–' ?>R</b></li>
      <li><span>How far it went (session)</span><b>+<?= $x['run_r'] ?? '–' ?>R (<?= $x['run_pct'] ?? '–' ?>%) by <?= $h($x['run_time']) ?><?= $x['pivots_crossed'] ? ' · crossed ' . $e(implode(', ', $x['pivots_crossed'])) : '' ?></b></li>
      <li><span>Exit</span><b class="<?= $cls ?>"><?= $x['status'] === 'open' ? 'Still open · now ' . t_fp((float)$x['last']) : $h($x['exit_time']) . ' @ ' . t_fp((float)$x['exit']) . ' · ' . $e($x['reason']) ?> · <?= $x['pips'] !== null ? $sg($x['pips']) . ' pips' : $sg(t_fp((float)$x['points'])) . ' pts' ?> · <?= $sg(round((float)$x['pct'], 3)) ?>% · <?= $sg($x['r']) ?>R</b></li>
      <?php if (($x['reason'] ?? '') === 'Stop-loss'): ?><li><span>After the stop-loss</span><b>price went +<?= $x['after_sl_r'] ?? 0 ?>R the right way<?= !empty($x['target_after_sl']) ? ' · <span class="gold">the target was reached later</span>' : ' · target not reached' ?></b></li><?php endif; ?>
    </ul></details>
  <?php endforeach; ?>
  </section>

  <?php if ($v === 'day' && !empty($R['signals'])): $NS = array_filter($R['signals'], fn($s) => !$s['trade']); ?>
  <section class="card"><h3>Tier-1 signals of the day (<?= count($R['signals']) ?>) · <?= count($NS) ?> without a Tier-2 entry</h3>
    <div class="tw"><table><thead><tr><th>Symbol</th><th>Signal</th><th>Setup</th><th>Aligned</th><th>From</th><th>To</th><th>Price</th><th>Tier-2 trade</th></tr></thead><tbody>
    <?php foreach ($R['signals'] as $s2): ?><tr><td><?= $e($s2['name']) ?> <span class="tv"><?= $e(t_tv_code($s2['symbol'])) ?></span></td><td class="<?= $s2['signal'] === 'CALL' ? 'up' : 'down' ?>"><?= $e($s2['signal']) ?></td><td><?= $e(tr_setup($s2)) ?></td><td><?= $s2['alignment'] ? (int)$s2['alignment'] . '/4' : '–' ?></td><td><?= $h($s2['start']) ?></td><td><?= $h($s2['end']) ?></td><td><?= $s2['price'] !== null ? t_fp((float)$s2['price']) : '–' ?></td><td><?= $s2['trade'] ? '✓' : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
  <?php endif; ?>
  <p class="setup">Research data from ChartPulse's own replay of Yahoo 1-minute and 5-minute prices. Not investment advice.</p>
<?php endif; ?>
</div></body></html>
