<?php
/**
 * Shared indicators + scan runner for /ema-strategies and /institutional (daily bars).
 * Data = the daily frames the site already caches (t_read_frames), so this makes no market-data calls.
 * Results are cached per folder and rebuilt at most every TA_STALE seconds, on the first visit after expiry.
 */
if (defined('TA_LOADED')) return;
define('TA_LOADED', 1);
const TA_STALE = 900;
const TA_SORRY = 'This scanner is temporarily unavailable. Sorry for the inconvenience. Please check back in a little while.';

// ---- indicators -------------------------------------------------------------------------------
function ta_ema_series(array $x, int $p): array {
    $n = count($x); $o = array_fill(0, $n, null);
    if ($n < $p) return $o;
    $k = 2 / ($p + 1); $e = array_sum(array_slice($x, 0, $p)) / $p; $o[$p - 1] = $e;
    for ($i = $p; $i < $n; $i++) { $e = $x[$i] * $k + $e * (1 - $k); $o[$i] = $e; }
    return $o;
}
function ta_rsi_series(array $c, int $p = 14): array {
    $n = count($c); $o = array_fill(0, $n, null);
    if ($n <= $p) return $o;
    $g = 0.0; $l = 0.0;
    for ($i = 1; $i <= $p; $i++) { $d = $c[$i] - $c[$i - 1]; if ($d > 0) $g += $d; else $l -= $d; }
    $ag = $g / $p; $al = $l / $p;
    $o[$p] = $al == 0 ? 100.0 : 100 - 100 / (1 + $ag / $al);
    for ($i = $p + 1; $i < $n; $i++) {
        $d = $c[$i] - $c[$i - 1];
        $ag = ($ag * ($p - 1) + max($d, 0)) / $p; $al = ($al * ($p - 1) + max(-$d, 0)) / $p;
        $o[$i] = $al == 0 ? 100.0 : 100 - 100 / (1 + $ag / $al);
    }
    return $o;
}
function ta_atr_series(array $h, array $l, array $c, int $p = 14): array {
    $n = count($c); $o = array_fill(0, $n, null);
    if ($n <= $p) return $o;
    $tr = [0.0];
    for ($i = 1; $i < $n; $i++) $tr[$i] = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1]));
    $a = array_sum(array_slice($tr, 1, $p)) / $p; $o[$p] = $a;
    for ($i = $p + 1; $i < $n; $i++) { $a = ($a * ($p - 1) + $tr[$i]) / $p; $o[$i] = $a; }
    return $o;
}
/** Supertrend direction per bar: 1 up, -1 down, 0 not yet defined. */
function ta_supertrend(array $h, array $l, array $c, int $p = 10, float $m = 3.0): array {
    $n = count($c); $atr = ta_atr_series($h, $l, $c, $p);
    $dir = array_fill(0, $n, 0); $fu = array_fill(0, $n, null); $fl = array_fill(0, $n, null);
    for ($i = $p; $i < $n; $i++) {
        $hl2 = ($h[$i] + $l[$i]) / 2; $bu = $hl2 + $m * $atr[$i]; $bl = $hl2 - $m * $atr[$i];
        if ($i == $p) { $fu[$i] = $bu; $fl[$i] = $bl; $dir[$i] = $c[$i] >= $hl2 ? 1 : -1; continue; }
        $fu[$i] = ($bu < $fu[$i - 1] || $c[$i - 1] > $fu[$i - 1]) ? $bu : $fu[$i - 1];
        $fl[$i] = ($bl > $fl[$i - 1] || $c[$i - 1] < $fl[$i - 1]) ? $bl : $fl[$i - 1];
        $dir[$i] = $dir[$i - 1] == 1 ? ($c[$i] < $fl[$i] ? -1 : 1) : ($c[$i] > $fu[$i] ? 1 : -1);
    }
    return $dir;
}
/** Confirmed swing points (k bars each side). Returns bar indexes. */
function ta_pivots(array $x, int $k, bool $high, int $from = 0): array {
    $n = count($x); $o = [];
    for ($i = max($k, $from); $i < $n - $k; $i++) {
        $ok = true;
        for ($j = 1; $j <= $k; $j++) {
            if ($high ? ($x[$i] <= $x[$i - $j] || $x[$i] < $x[$i + $j]) : ($x[$i] >= $x[$i - $j] || $x[$i] > $x[$i + $j])) { $ok = false; break; }
        }
        if ($ok) $o[] = $i;
    }
    return $o;
}
/** Volume-weighted price from bar $a to $b inclusive; plain typical-price mean when the symbol has no volume. */
function ta_vwap(array $B, int $a, int $b): ?float {
    if ($a < 0 || $b < $a) return null;
    $pv = 0.0; $vv = 0.0; $tp = 0.0;
    for ($i = $a; $i <= $b; $i++) {
        $t = ($B['h'][$i] + $B['l'][$i] + $B['c'][$i]) / 3; $v = $B['v'][$i];
        $pv += $t * $v; $vv += $v; $tp += $t;
    }
    return $vv > 0 ? $pv / $vv : $tp / ($b - $a + 1);
}
function ta_minr(array $x, int $a, int $b): float { return min(array_slice($x, $a, $b - $a + 1)); }
function ta_maxr(array $x, int $a, int $b): float { return max(array_slice($x, $a, $b - $a + 1)); }
function ta_argmin(array $x, int $a, int $b): int { $s = array_slice($x, $a, $b - $a + 1, true); return (int)array_search(min($s), $s, true); }
function ta_argmax(array $x, int $a, int $b): int { $s = array_slice($x, $a, $b - $a + 1, true); return (int)array_search(max($s), $s, true); }

/** Per-symbol precomputed context shared by every strategy. */
function ta_ctx(array $B): array {
    $c = $B['c']; $n = count($c);
    $vs = array_slice($B['v'], -20);
    $avgv = $vs ? array_sum($vs) / count($vs) : 0.0;
    return [
        'n' => $n, 'e21' => ta_ema_series($c, 21), 'e50' => ta_ema_series($c, 50), 'e200' => ta_ema_series($c, 200),
        'rsi' => ta_rsi_series($c, 14), 'atr' => ta_atr_series($B['h'], $B['l'], $c, 14),
        'avgv' => $avgv, 'hasv' => $avgv > 0,
    ];
}
function ta_r(float $p): float { return round($p, $p >= 100 ? 2 : ($p >= 1 ? 4 : 6)); }

/** Builds a signal row body. SL = 1.5 ATR, TP = 3 ATR (1:2) unless a nearer reversion target is given. */
function ta_sig(string $bias, array $B, array $X, int $score, string $setup, string $tag, ?float $tp = null): array {
    $n = $X['n']; $c = $B['c'][$n - 1]; $a = (float)$X['atr'][$n - 1]; $up = $bias === 'CALL';
    return [
        'bias' => $bias, 'entry' => ta_r($c), 'sl' => ta_r($up ? $c - 1.5 * $a : $c + 1.5 * $a),
        'tp' => ta_r($tp ?? ($up ? $c + 3 * $a : $c - 3 * $a)),
        'score' => min(99, $score), 'setup' => $setup, 'tag' => $tag,
    ];
}

// ---- runner -----------------------------------------------------------------------------------
function ta_boot(): bool {
    if (function_exists('t_symbols') && function_exists('t_read_frames')) return true;
    $p = dirname(__DIR__, 2) . '/torus_engine.php';
    if (!is_file($p)) return false;
    require_once $p;
    return function_exists('t_symbols') && function_exists('t_read_frames');
}
function ta_bars(string $sym, int $minBars, string $tf = '1D'): ?array {
    $fr = t_read_frames($sym);
    if ($tf === '15m') $d = !empty($fr['5m']['t']) ? t_resample($fr['5m'], 900) : null;
    else $d = $fr[$tf === '1H' ? '1H' : '1D'] ?? null;
    if (!$d || empty($d['c']) || empty($d['h']) || empty($d['l'])) return null;
    $c = array_map('floatval', array_values($d['c'])); $n = count($c);
    if ($n < $minBars) return null;
    $h = array_map('floatval', array_values($d['h'])); $l = array_map('floatval', array_values($d['l']));
    $o = !empty($d['o']) ? array_map('floatval', array_values($d['o'])) : $c;
    $v = !empty($d['v']) ? array_map('floatval', array_values($d['v'])) : array_fill(0, $n, 0.0);
    if (count($h) < $n || count($l) < $n || count($o) < $n || count($v) < $n) return null;
    $h = array_slice($h, -$n); $l = array_slice($l, -$n); $o = array_slice($o, -$n); $v = array_slice($v, -$n);
    if (min($l) <= 0 || min($c) <= 0) return null;   // broken / synthetic series
    return ['o' => $o, 'h' => $h, 'l' => $l, 'c' => $c, 'v' => $v];
}
/** Runs every strategy over the whole universe. $map: scanner id => callable(B, X): ?array */
function ta_scan_all(array $map, int $minBars): array {
    $res = array_fill_keys(array_keys($map), []); $scanned = 0; $err = null;
    foreach (t_symbols() as $sym => $market) {
        $B = ta_bars((string)$sym, $minBars); if (!$B) continue;
        $scanned++;
        $X = ta_ctx($B); $n = $X['n']; if ($X['atr'][$n - 1] === null) continue;
        $pc = $B['c'][$n - 2]; $chg = $pc > 0 ? round(($B['c'][$n - 1] - $pc) / $pc * 100, 2) : 0.0;
        foreach ($map as $id => $fn) {
            try { $s = $fn($B, $X); } catch (Throwable $e) { $s = null; $err ??= $id . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
            if (!$s) continue;
            $tv = null; if (function_exists('t_tv_code')) { try { $tv = t_tv_code($sym, $market); } catch (Throwable $e) {} }
            $res[$id][] = $s + ['symbol' => (string)$sym, 'market' => $market, 'price' => ta_r($B['c'][$n - 1]), 'change' => $chg, 'tv' => $tv];
        }
    }
    foreach ($res as $id => $rows) { usort($rows, fn($a, $b) => $b['score'] <=> $a['score']); $res[$id] = $rows; }
    return [$res, $scanned, $err];
}
/** Returns the cached scan for one scanner id, rebuilding the whole folder's cache when stale. Never throws. */
function ta_get(string $dir, array $map, int $minBars, string $id): array {
    $f = "$dir/scan_$id.json";
    $read = function () use ($f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : null; };
    $age = is_file($f) ? time() - filemtime($f) : PHP_INT_MAX;
    if ($age < TA_STALE) return $read() ?? ['error' => TA_SORRY];
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $lock = @fopen("$dir/.lock", 'c');
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
        try {
            @set_time_limit(90); @ini_set('memory_limit', '256M');
            if (!ta_boot()) throw new RuntimeException('data layer not found');
            [$res, $scanned, $err] = ta_scan_all($map, $minBars);
            if ($err) @file_put_contents("$dir/errors.log", date('c') . ' strategy error ' . $err . "\n", FILE_APPEND);
            $when = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('d M H:i') . ' IST';
            foreach ($res as $sid => $rows) {
                $out = ['updated' => $when, 'count' => count($rows), 'scanned' => $scanned, 'data' => array_slice($rows, 0, 100)];
                @file_put_contents("$dir/scan_$sid.json.tmp", json_encode($out)); @rename("$dir/scan_$sid.json.tmp", "$dir/scan_$sid.json");
            }
        } catch (Throwable $e) {
            @file_put_contents("$dir/errors.log", date('c') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
        }
        flock($lock, LOCK_UN);
    }
    if ($lock) fclose($lock);
    return $read() ?? ['updated' => null, 'count' => 0, 'data' => [], 'status' => 'updating'];
}

// ---- God-father feed (owner, 12 Nov 2026): the FULL result list (not the page's top 100), on 1D, 1H or 15m bars.
const TA_FULL_TTL = ['1D' => 900, '1H' => 600, '15m' => 300];
/** Scans only $ids on timeframe $tf; caches {at, tf, sig: {id: [[symbol, bias, score, setup], ...]}}. Never throws. */
function ta_get_full(string $dir, array $map, int $minBars, array $ids, string $tf): array {
    if (!isset(TA_FULL_TTL[$tf])) $tf = '1D';
    $f = "$dir/full_$tf.json";
    $read = function () use ($f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : null; };
    if (is_file($f) && time() - filemtime($f) < TA_FULL_TTL[$tf]) return $read() ?? ['sig' => []];
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $lock = @fopen("$dir/.lock_full_$tf", 'c');
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
        try {
            @set_time_limit(170); @ini_set('memory_limit', '256M');
            if (!ta_boot()) throw new RuntimeException('data layer not found');
            $min = $tf === '1D' ? $minBars : max($minBars, 80);
            $fns = array_intersect_key($map, array_flip($ids)); $out = array_fill_keys(array_keys($fns), []); $n = 0;
            foreach (t_symbols() as $sym => $_) {
                $B = ta_bars((string)$sym, $min, $tf); if (!$B) continue;
                $X = ta_ctx($B); if ($X['atr'][$X['n'] - 1] === null) continue; $n++;
                foreach ($fns as $id => $fn) {
                    try { $s = $fn($B, $X); } catch (Throwable $e) { $s = null; }
                    if ($s) $out[$id][] = [(string)$sym, $s['bias'], (int)$s['score'], (string)$s['setup']];
                }
            }
            $p = ['at' => time(), 'tf' => $tf, 'scanned' => $n, 'sig' => $out];
            @file_put_contents("$f.tmp", json_encode($p)); @rename("$f.tmp", $f);
        } catch (Throwable $e) {
            @file_put_contents("$dir/errors.log", date('c') . ' full ' . $tf . ' ' . $e->getMessage() . "\n", FILE_APPEND);
        }
        flock($lock, LOCK_UN);
    }
    if ($lock) fclose($lock);
    return $read() ?? ['sig' => []];
}
