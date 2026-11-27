<?php
/**
 * God-father v2 (owner, 27 Nov 2026)                                       chartpulse.io
 * Built in NEW files: it only READS the existing God-father board (gf_scan), Trendline Hero (tlh_scan),
 * the Confluence Scanner (cf_scan) and GEX labels. The locked files (gf_engine.php, Day Drive) are not changed.
 *
 * One vote per group (research: fixed, roughly equal weights beat tuned weights out of sample):
 *   BIAS      Daily + Weekly trend agree (hard gate)
 *   LOCATION  best of: Confluence zone (2-4 charts) / Hero line (week/month anchor) / God-father Power Zone   -> one vote
 *   SETUP     trap (sweep / fake breakout) 3 > rejection 2.5 > retest 2 > at the line 1.5 > near 1
 *   TRIGGER   a turn has printed: Confluence GO, Hero live alert, God-father trigger check
 *   FLOW      extras, only lift A -> A+, never B -> A, never lower: candle delta with the side, big volume,
 *             GEX +gamma (reversal-friendly) or a GEX wall at the zone. Missing data = nothing added, nothing taken.
 *   NEWS      a high-impact story against the side overrides everything (NEWS RISK)
 * Every A+ / A is logged once per symbol, side and day with entry / stop / targets frozen, and graded later
 * (target 1 first = win, stop first = loss, same bar = loss, 24 h time limit = result in R at the last close).
 */
declare(strict_types=1);
require_once __DIR__ . '/gf_engine.php';
require_once __DIR__ . '/cf_engine.php';

const GF2_DIR_NAME = 'gf2';
const GF2_TTL = 60;
const GF2_HOLD = 86400;          // time limit for the result, seconds
const GF2_MIN_N = 100;
const GF2_VER = 'gf2.3';        // stats count only the current rule version           // closed trades before a win rate is shown as a number

function gf2_dir(): string { $d = T_DATA . '/' . GF2_DIR_NAME; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }

/** Order flow from the 5m bars (only where volume exists). Returns null when there is no usable volume. */
function gf2_flow(string $sym, string $side): ?array {
    $fr = t_read_frames($sym); $F = $fr['5m'] ?? null; if (!$F || count($F['t'] ?? []) < 70) return null;
    $v = $F['v'] ?? []; $n = count($F['t']); if (!$v || array_sum(array_slice($v, -60)) <= 0) return null;
    $sg = $side === 'PUT' ? -1 : 1; $dl = 0.0;
    for ($i = $n - 6; $i < $n; $i++) { $r = $F['h'][$i] - $F['l'][$i]; if ($r > 0) $dl += $v[$i] * (2 * $F['c'][$i] - $F['h'][$i] - $F['l'][$i]) / $r; }
    $avg = array_sum(array_slice($v, -60)) / 60; $mx = max(array_slice($v, -12));
    return ['delta' => $dl * $sg > 0, 'bigvol' => $avg > 0 && $mx >= 2 * $avg];
}

function gf2_wilson(int $w, int $n): array {
    if ($n <= 0) return [0, 0]; $z = 1.96; $p = $w / $n; $d = 1 + $z * $z / $n;
    $c = ($p + $z * $z / (2 * $n)) / $d; $h = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $d;
    return [round(max(0, $c - $h) * 100, 1), round(min(1, $c + $h) * 100, 1)];
}

function gf2_scan(): array {
    $dir = gf2_dir(); $now = t_now(); $ck = $dir . '/_board1.json';
    $c = t_read_json($ck, null); if (is_array($c) && ($c['v'] ?? '') === GF2_VER && $now - (int)($c['at'] ?? 0) < GF2_TTL && isset($c['rows'])) return $c;
    $gf = []; $he = []; $cf = [];
    try { $gf = gf_scan()['rows'] ?? []; } catch (Throwable $e) {}
    try { $he = tlh_scan(true)['rows'] ?? []; } catch (Throwable $e) {}
    try { $cf = cf_scan()['rows'] ?? []; } catch (Throwable $e) {}
    $by = [];
    foreach ($gf as $r) { $s = gf_side_of($r['side'] ?? null); if ($s) $by[$r['symbol'] . '|' . $s]['gf'] = $r; }
    foreach ($he as $r) if (!empty($r['bias'])) $by[$r['symbol'] . '|' . $r['bias']]['he'] = $r;
    foreach ($cf as $r) if (!empty($r['bias'])) $by[$r['symbol'] . '|' . $r['bias']]['cf'] = $r;
    $fc = t_read_json($dir . '/_flow.json', []); if (!is_array($fc)) $fc = []; $t0 = microtime(true); $fnew = 0;
    $rows = [];
    foreach ($by as $k => $x) {
        [$sym, $side] = explode('|', $k, 2); $g = $x['gf'] ?? null; $h = $x['he'] ?? null; $c = $x['cf'] ?? null;
        $base = $c ?? $h ?? $g;
        // BIAS: Daily + Weekly (from whichever input carries the per-chart trend)
        $d = $c['mtf']['d'] ?? $h['mtf']['d'] ?? $g['mtf'] ?? []; $D = (int)($d['1D'] ?? 0); $W = (int)($d['1W'] ?? 0);
        $want = $side === 'PUT' ? -1 : 1; if ($D !== $want || $W !== $want) continue;
        // LOCATION: one vote, the strongest source wins
        $loc = 0.0; $locw = '';
        if ($c) { $nT = count($c['tfs'] ?? []); $v = $nT >= 4 ? 3.0 : ($nT === 3 ? 2.5 : 2.0); if ($v > $loc) { $loc = $v; $locw = "Confluence · $nT charts"; } }
        if ($h) { $big = in_array($h['tf'] ?? '', ['1W', '1D'], true) || in_array($h['anchor'] ?? '', ['Week High', 'Week Low', 'Month High', 'Month Low'], true); $v = $big ? 2.5 : 2.0;
                  if ($v > $loc) { $loc = $v; $locw = 'Hero · ' . ($h['tf'] ?? '') . ' line' . (!empty($h['anchor']) ? ' + ' . $h['anchor'] : ''); } }
        if ($g) { $pz = (int)($g['pz'] ?? 0); $v = $pz >= 4 ? 2.5 : ($pz >= 2 ? 2.0 : ($pz >= 1 ? 1.5 : 0)); if ($v > $loc) { $loc = $v; $locw = "Power Zone · $pz levels"; } }
        if ($loc < 1.5) continue;
        // SETUP
        $set = 0.0; $setw = '';
        $cand = [];
        if ($c) { if (!empty($c['sweep'])) $cand[] = [3.0, 'Trap (sweep) at the zone']; if ($c['stage'] >= 4) $cand[] = [2.5, 'Rejection at the zone']; elseif ($c['stage'] >= 2) $cand[] = [1.5, 'At the zone']; else $cand[] = [1.0, 'Approaching the zone']; }
        if ($g) { $sk = $g['skind'] ?? ''; $cand[] = [['trap' => 3.0, 'rej' => 2.5, 'retest' => 2.0, 'smc' => 2.0, 'line' => 1.5, 'near' => 1.0][$sk] ?? 1.0, (string)($g['setup'] ?? '')]; }
        if ($h) { $st = (int)($h['stage'] ?? 0); $cand[] = [$st === 5 ? 3.0 : ($st === 4 ? 2.5 : ($st === 3 ? 1.5 : 1.0)), (string)($h['stage_name'] ?? '')]; }
        foreach ($cand as [$v, $w]) if ($v > $set) { $set = $v; $setw = $w; }
        // TRIGGER
        $trig = []; if ($c && $c['stage'] >= 5) $trig[] = 'Confluence GO (5m turn)';
        if ($h && (int)($h['stage'] ?? 0) >= 4 && !empty($h['live'])) $trig[] = 'Hero alert';
        if ($g && in_array($g['grade'] ?? '', ['A+', 'A'], true) && empty($g['late'])) foreach ($g['checks'] ?? [] as $ch) if (($ch['k'] ?? '') === 'trig' && !empty($ch['ok'])) $trig[] = (string)$ch['d'];   // God-father's own trigger only when its board graded it A+/A with fresh data
        $trigOk = (bool)$trig;
        // NEWS
        $news = false; if ($g) foreach ($g['checks'] ?? [] as $ch) if (($ch['k'] ?? '') === 'news' && empty($ch['ok'])) $news = true;
        if ($g && ($g['grade'] ?? '') === 'NEWS RISK') $news = true;
        // FLOW (extras): only lifts A -> A+
        $fl = []; $fk = $sym . '|' . $side; $f = $fc[$fk] ?? null;
        if ((!is_array($f) || $now - (int)($f['at'] ?? 0) > 120) && $loc >= 2 && $set >= 1.5 && microtime(true) - $t0 < 6) { try { $f = ['at' => $now, 'x' => gf2_flow($sym, $side)]; } catch (Throwable $e) { $f = ['at' => $now, 'x' => null]; } $fc[$fk] = $f; $fnew++; }
        if (!empty($f['x']['delta'])) $fl[] = ($side === 'PUT' ? 'Selling' : 'Buying') . ' at the zone (delta)';
        if (!empty($f['x']['bigvol'])) $fl[] = 'Big volume';
        $gx = $base['gex'] ?? ($g['gex'] ?? null);
        if ($gx && (int)($gx['rg'] ?? 0) > 0) $fl[] = '+γ: dealers fade moves';
        foreach (($c['labels'] ?? []) as $lb) if (str_starts_with((string)$lb, 'GEX ')) $fl[] = $lb;
        // GRADE (rules fixed in advance)
        $nsrc = (int)(bool)$c + (int)(bool)$h + (int)(bool)$g;
        if ($loc >= 2.5 && $set >= 2.5 && $trigOk && $nsrc >= 2) $grade = 'A+';   // A+ also needs two independent inputs agreeing
        elseif ($loc >= 2.0 && $set >= 2.0 && $trigOk) $grade = 'A';
        elseif ($loc >= 2.0 && $set >= 1.5) $grade = $trigOk ? 'B' : 'READY';
        else $grade = 'WATCH';
        $boost = false; if ($grade === 'A' && count($fl) >= 2) { $grade = 'A+'; $boost = true; }
        if ($news) $grade = 'NEWS RISK';
        // PLAN: confluence > hero > god-father
        $p = null;
        if ($c && !empty($c['plan'])) $p = ['entry' => $c['plan']['entry'], 'sl' => $c['plan']['stop'], 't1' => $c['plan']['t1'], 't2' => $c['plan']['t2'], 'from' => 'Confluence'];
        elseif ($h && !empty($h['plan'])) $p = ['entry' => $h['plan']['entry'], 'sl' => $h['plan']['sl'], 't1' => $h['plan']['t1'], 't2' => $h['plan']['t2'], 'from' => 'Hero'];
        elseif ($g && !empty($g['plan'])) $p = ['entry' => $g['plan']['entry'], 'sl' => $g['plan']['sl'], 't1' => $g['plan']['t1'], 't2' => $g['plan']['t2'], 'from' => 'God-father'];
        $rank = ['A+' => 6, 'A' => 5, 'B' => 4, 'READY' => 3, 'WATCH' => 2, 'NEWS RISK' => 1][$grade];
        $rows[] = ['symbol' => $sym, 'name' => $base['name'] ?? $sym, 'market' => $base['market'] ?? '', 'class' => $base['class'] ?? '', 'price' => $base['price'] ?? null, 'dec' => $base['dec'] ?? 2,
                   'side' => $side, 'grade' => $grade, 'boost' => $boost, 'loc' => $loc, 'loc_from' => $locw, 'setup' => $set, 'setup_name' => $setw, 'trigger' => $trig, 'flow' => array_values(array_unique($fl)),
                   'news' => $news, 'plan' => $p, 'sources' => array_values(array_filter([$c ? 'Confluence' : null, $h ? 'Hero' : null, $g ? 'God-father' : null])),
                   'zone' => $c['zone'] ?? null, 'gex' => $gx, 'key' => $rank * 100 + $loc * 10 + $set * 5 + count($fl)];
    }
    if ($fnew) { foreach ($fc as $k => $f) if ($now - (int)($f['at'] ?? 0) > 3600) unset($fc[$k]); t_write_json($dir . '/_flow.json', $fc); }
    usort($rows, fn($a, $b) => $b['key'] <=> $a['key']);
    gf2_log($rows, $now);
    $cnt = []; foreach ($rows as $r) $cnt[$r['grade']] = ($cnt[$r['grade']] ?? 0) + 1;
    $out = ['v' => GF2_VER, 'at' => $now, 'rows' => $rows, 'meta' => ['counts' => $cnt, 'inputs' => ['god-father' => count($gf), 'hero' => count($he), 'confluence' => count($cf)]], 'stats' => gf2_stats()];
    t_write_json($ck, $out);
    return $out;
}

/** Append every new A+ / A (one per symbol, side and day) with the plan frozen. */
function gf2_log(array $rows, int $now): void {
    $dir = gf2_dir(); $op = $dir . '/open.json'; $open = t_read_json($op, []); if (!is_array($open)) $open = []; $add = 0;
    $day = gmdate('Ymd', $now); $done = t_read_json($dir . '/done.json', []); if (!is_array($done)) $done = [];
    foreach ($rows as $r) {
        if (!in_array($r['grade'], ['A+', 'A'], true) || empty($r['plan']['entry']) || empty($r['plan']['sl'])) continue;
        $id = $r['symbol'] . '|' . $r['side'] . '|' . $day . '|' . GF2_VER; if (isset($open[$id]) || isset($done[$id])) continue;
        $e = ['id' => $id, 'at' => $now, 'v' => GF2_VER, 'symbol' => $r['symbol'], 'side' => $r['side'], 'grade' => $r['grade'], 'boost' => $r['boost'], 'loc_from' => $r['loc_from'],
              'setup' => $r['setup_name'], 'flow' => $r['flow'], 'sources' => $r['sources'], 'plan' => $r['plan']];
        $open[$id] = $e; @file_put_contents($dir . '/log_' . gmdate('Ym', $now) . '.jsonl', json_encode($e) . "\n", FILE_APPEND); $add++;
    }
    if ($add) t_write_json($op, $open);
}

/** Worker step: close open trades by first touch (5m bars, 1H where there are none). */
function gf2_eval(float $budget = 4.0): int {
    $dir = gf2_dir(); $op = $dir . '/open.json'; $open = t_read_json($op, []); if (!is_array($open) || !$open) return 0;
    $t0 = microtime(true); $now = t_now(); $done = 0; $dn = t_read_json($dir . '/done.json', []); if (!is_array($dn)) $dn = [];
    foreach ($open as $id => $e) {
        if (microtime(true) - $t0 > $budget) break;
        $fr = t_read_frames($e['symbol']); if (!$fr) continue; $B = !empty($fr['5m']['t']) ? $fr['5m'] : $fr['1H'];
        $p = $e['plan']; $put = $e['side'] === 'PUT'; $en = (float)$p['entry']; $sl = (float)$p['sl']; $t1 = (float)$p['t1']; $risk = abs($en - $sl); if ($risk <= 0) { unset($open[$id]); continue; }
        $res = null; $last = null;
        foreach ($B['t'] as $i => $t) {
            if ($t <= $e['at']) continue; $last = (float)$B['c'][$i];
            $hitS = $put ? $B['h'][$i] >= $sl : $B['l'][$i] <= $sl; $hitT = $put ? $B['l'][$i] <= $t1 : $B['h'][$i] >= $t1;
            if ($hitS) { $res = ['out' => 'stop', 'r' => -1.0, 't' => $t]; break; }       // same bar as target = loss
            if ($hitT) { $res = ['out' => 'target', 'r' => round(abs($t1 - $en) / $risk, 2), 't' => $t]; break; }
            if ($t - $e['at'] >= GF2_HOLD) { $res = ['out' => 'time', 'r' => round(($put ? $en - $last : $last - $en) / $risk, 2), 't' => $t]; break; }
        }
        if (!$res && $now - $e['at'] > GF2_HOLD + 7200) $res = ['out' => 'time', 'r' => $last !== null ? round(($put ? $en - $last : $last - $en) / $risk, 2) : 0.0, 't' => $now, 'stale' => 1];
        if (!$res) continue;
        @file_put_contents($dir . '/results.jsonl', json_encode($e + ['result' => $res]) . "\n", FILE_APPEND); $dn[$id] = $now;
        unset($open[$id]); $done++;
    }
    if ($done) { foreach ($dn as $k => $t) if ($now - (int)$t > 7 * 86400) unset($dn[$k]); t_write_json($dir . '/done.json', $dn); t_write_json($op, $open); @unlink($dir . '/_stats.json'); }
    return $done;
}

/** Win rate (target first) and average R per grade, with a Wilson 95% interval. */
function gf2_stats(): array {
    $dir = gf2_dir(); $sp = $dir . '/_stats.json'; $c = t_read_json($sp, null); if (is_array($c) && time() - (int)($c['at'] ?? 0) < 600) return $c;
    $g = []; $f = $dir . '/results.jsonl';
    if (is_file($f)) foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) { $e = json_decode($ln, true); if (!$e || ($e['v'] ?? '') !== GF2_VER) continue; $k = $e['grade'];
        $g[$k]['n'] = ($g[$k]['n'] ?? 0) + 1; $g[$k]['w'] = ($g[$k]['w'] ?? 0) + (($e['result']['out'] ?? '') === 'target' ? 1 : 0); $g[$k]['r'] = ($g[$k]['r'] ?? 0) + (float)($e['result']['r'] ?? 0); }
    $out = ['at' => time(), 'min_n' => GF2_MIN_N, 'open' => count(array_filter(t_read_json($dir . '/open.json', []) ?: [], fn($e) => ($e['v'] ?? '') === GF2_VER)), 'grades' => []];
    foreach (['A+', 'A'] as $k) { $n = (int)($g[$k]['n'] ?? 0); $w = (int)($g[$k]['w'] ?? 0);
        $out['grades'][$k] = ['n' => $n, 'wins' => $w, 'win_pct' => $n ? round($w / $n * 100, 1) : null, 'ci' => gf2_wilson($w, $n), 'avg_r' => $n ? round($g[$k]['r'] / $n, 2) : null, 'ready' => $n >= GF2_MIN_N]; }
    t_write_json($sp, $out);
    return $out;
}
