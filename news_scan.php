<?php
/**
 * News Scanner (owner, 12 Nov 2026): every market that is in the news, one row per market.
 * Built on the news feed that already powers /news.html (torus_news.php): RSS + Finnhub + Marketaux when keys are set.
 * Per market, last 48 hours: how many stories, their tone (keyword model; Marketaux sentiment where present),
 * the strongest impact grade, the price move since the first of those stories, and the trend (Supertrend 10,3 on
 * 30m 1H 4H 1D 1W) as a LABEL: does the news point the same way as the trend. Nothing here is a filter.
 */
declare(strict_types=1);
require_once __DIR__ . '/torus_news.php';
require_once __DIR__ . '/tl_engine.php';

const NS_WINDOW = 48 * 3600;

function ns_mtf(string $sym, int $now): ?array {
    static $C = null; $p = T_DATA . '/tl/_newsmtf1.json';
    if ($C === null) { $C = t_read_json($p, []); if (!is_array($C)) $C = []; }
    if (isset($C[$sym]) && $now - (int)$C[$sym]['at'] < 900) return $C[$sym]['m'];
    $m = null;
    try { $fr = t_read_frames($sym); if ($fr) { $x = tl_mtf(tl_src($fr, $fr['5m'] ?? null)); $m = ['d' => $x['d'], 'bias' => tlh_bias($x)]; } } catch (Throwable $e) {}
    $C[$sym] = ['at' => $now, 'm' => $m];
    foreach ($C as $k => $v) if ($now - (int)$v['at'] > 86400) unset($C[$k]);
    if (!is_dir(T_DATA . '/tl')) @mkdir(T_DATA . '/tl', 0755, true);
    t_write_json($p, $C);
    return $m;
}

function ns_scan(): array {
    $now = t_now(); $ck = T_DATA . '/news_scan1.json';
    $c = t_read_json($ck, null);
    if (is_array($c) && $now - (int)($c['at'] ?? 0) < 60) return $c;
    $N = t_news_load($now); $scan = t_scan_load(); $R = [];
    foreach (t_view_rows($scan, $now) as $vr) $R[$vr['symbol']] = $vr;
    $rank = ['high' => 3, 'medium' => 2, 'low' => 1]; $by = [];
    foreach ($N['items'] ?? [] as $x) {
        if ($now - (int)$x['ts'] > NS_WINDOW) continue;
        foreach ($x['tags'] ?? [] as $t) {
            $s = (string)$t['symbol']; if (!isset($R[$s])) continue;
            $b = &$by[$s]; $b = $b ?? ['n' => 0, 'bull' => 0, 'bear' => 0, 'neu' => 0, 'ss' => 0.0, 'imp' => 0, 'last' => 0, 'first' => PHP_INT_MAX, 'base' => null, 'n24' => 0, 'st' => []];
            $b['n']++; $b[$x['sen']]++; $b['ss'] += (float)$x['ss']; $b['imp'] = max($b['imp'], $rank[$x['imp']] ?? 1); $b['last'] = max($b['last'], (int)$x['ts']);
            if ($now - (int)$x['ts'] <= 86400) $b['n24']++;
            if ((int)$x['ts'] < $b['first'] && !empty($t['base'])) { $b['first'] = (int)$x['ts']; $b['base'] = (float)$t['base']; }
            if (count($b['st']) < 4) $b['st'][] = ['t' => $x['t'], 'u' => $x['u'], 'src' => $x['src'], 'ts' => (int)$x['ts'], 'sen' => $x['sen'], 'imp' => $x['imp']];
            unset($b);
        }
    }
    $rows = [];
    foreach ($by as $s => $b) {
        $r = $R[$s]; $px = (float)($r['price'] ?? 0); $tone = $b['ss'] / $b['n'];
        $side = $b['bull'] > $b['bear'] && $tone >= 0.15 ? 'UP' : ($b['bear'] > $b['bull'] && $tone <= -0.15 ? 'DOWN' : ($b['bull'] && $b['bear'] ? 'MIXED' : 'NEUTRAL'));
        $since = $b['base'] && $px ? round(($px / $b['base'] - 1) * 100, 2) : null; if ($since !== null && abs($since) < 0.005) $since = null;
        $m = ns_mtf($s, $now); $bias = $m['bias'] ?? null;
        $agree = !$bias || $side === 'MIXED' || $side === 'NEUTRAL' ? null : (($side === 'UP') === ($bias === 'CALL'));
        // has price already moved the way the news points?
        $moved = $since === null || $side === 'MIXED' || $side === 'NEUTRAL' ? null : (($side === 'UP' ? $since : -$since) >= 0.3);
        $rows[] = ['symbol' => $s, 'name' => t_name($s), 'market' => $r['market'] ?? '', 'class' => tl_class($s), 'price' => $px, 'dec' => tl_dec($px ?: 1),
            'chg' => $r['change_pct'] ?? null, 'n' => $b['n'], 'n24' => $b['n24'], 'bull' => $b['bull'], 'bear' => $b['bear'], 'neu' => $b['neu'], 'tone' => round($tone, 2), 'side' => $side,
            'imp' => array_search($b['imp'], $rank, true), 'last' => $b['last'], 'since' => $since, 'moved' => $moved, 'mtf' => $m, 'agree' => $agree, 'stories' => $b['st'],
            'key' => [$b['imp'], $side === 'UP' || $side === 'DOWN' ? 1 : 0, $agree === true ? 1 : 0, min(9, $b['n']), $b['last']]];
    }
    usort($rows, fn($a, $b) => $b['key'] <=> $a['key']);
    $cnt = ['up' => 0, 'down' => 0, 'mixed' => 0, 'high' => 0, 'agree' => 0];
    foreach ($rows as $r) { if ($r['side'] === 'UP') $cnt['up']++; elseif ($r['side'] === 'DOWN') $cnt['down']++; else $cnt['mixed']++; if ($r['imp'] === 'high') $cnt['high']++; if ($r['agree'] === true) $cnt['agree']++; }
    $src = []; foreach ($N['sources'] ?? [] as $x) if (!empty($x['ok'])) $src[] = (string)$x['name'];
    $p = ['at' => $now, 'feed_at' => (int)($N['at'] ?? 0), 'rows' => $rows, 'meta' => ['stories' => count($N['items'] ?? []), 'markets' => count($rows), 'counts' => $cnt, 'sources' => $src,
          'sentiment' => trim((string)t_cfg('marketaux_key', '')) !== '' ? 'keywords + Marketaux' : 'keywords']];
    t_write_json($ck, $p);
    return $p;
}
