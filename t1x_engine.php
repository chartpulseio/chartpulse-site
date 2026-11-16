<?php
/**
 * Tier-1 scanner extras (owner, 16 Nov 2026)                    chartpulse.io
 *
 * Two extra readings for every shortlisted Tier-1 row, added to api.php?r=scan:
 *   tlx : the trend line sitting at the day's extreme. Row at the Day High (Pulse Ceiling) -> a RESISTANCE line,
 *         row at the Day Low (Pulse Floor) -> a SUPPORT line, on any timeframe 10m and up (10m 15m 30m 1H 4H 1D 1W;
 *         5m and below never count). "At" = the line is within 0.2 x daily ATR of price or of the day-extreme zone.
 *   e2x : timeframes (15m 30m 1H 4H 1D) where price is within 1 x that timeframe's ATR of its 200 EMA -- the same
 *         reading the 200 EMA Scanner uses. A label only.
 * Neither one filters or re-ranks the list.
 *
 * Trend lines come from the Trend Line Scanner's own row cache (kept warm by God-father / the TL page); a symbol
 * missing or stale there is read here with the same tl_row(), inside a small time budget. Results are cached in
 * data/t1x.json; one request steps the cache at a time.
 */
declare(strict_types=1);
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/torus_engine.php';
require_once __DIR__ . '/tl_engine.php';
require_once __DIR__ . '/ema200_engine.php';

const T1X_TL_TFS = ['1W', '1D', '4H', '1H', '30m', '15m', '10m'];   // no 5m or lower (owner)
const T1X_E2_TFS = ['1D', '4H', '1H', '30m', '15m'];
const T1X_NEAR = 0.2;       // x daily ATR
const T1X_TTL = 240;        // a symbol's reading is redone after this long
const T1X_TL_OK = 900;      // a Trend Line Scanner row this fresh is reused as is
const T1X_BUDGET = 5.0;     // seconds of work per scan request

/** Rows worth the extra reading: at a day extreme and shortlisted (signal, or within 2 ATR5 of the zone). */
function t1x_want(array $r): bool {
    $z = $r['zone_near'] ?? null; if ($z !== 'ATR High' && $z !== 'ATR Low') return false;
    if (($r['signal'] ?? 'WAIT') !== 'WAIT') return true;
    $d = $r['zone_dist_atr'] ?? null; return $d !== null && abs((float)$d) <= 2.0;
}

/** Trend lines at the extreme, from a tl_row()-style row. */
function t1x_lines(array $tl, array $r): array {
    $hi = ($r['zone_near'] ?? '') === 'ATR High'; $side = $hi ? 'res' : 'sup';
    $px = (float)($r['price'] ?? 0); $zone = (float)($r['zone'] ?? 0);
    $atrD = $px * (float)($r['atr_pct'] ?? 0) / 100; if ($atrD <= 0) return [];
    $tol = T1X_NEAR * $atrD; $out = [];
    foreach (T1X_TL_TFS as $tf) {
        $L = $tl['tf'][$tf][$side] ?? null; if (!is_array($L) || !isset($L['now'])) continue;
        $lv = (float)$L['now'];
        $d = min(abs($lv - $px), $zone > 0 ? abs($lv - $zone) : INF);
        if ($d > $tol) continue;
        $out[] = ['tf' => $tf, 'n' => (int)($L['n'] ?? 2), 'st' => (string)($L['st'] ?? ''), 'lv' => $lv] + (!empty($L['z']) ? ['z' => $L['z']] : []);
    }
    return $out;
}

/** 200 EMA timeframes price is near. */
function t1x_ema(string $sym, ?array $m15): array {
    $fr = t_read_frames($sym); if (!$fr) return [];
    $S = e2_tfs($fr, $m15); $out = [];
    foreach (T1X_E2_TFS as $tf) { $x = $S[$tf] ?? null; if (is_array($x) && !empty($x['in'])) $out[] = ['tf' => $tf, 'd' => (float)$x['d']]; }
    return $out;
}

/** sym => ['tl' => [...], 'e2' => [...]] for the wanted rows of the raw scan. */
function t1x_get(array $scan): array {
    $p = T_DATA . '/t1x.json'; $C = t_read_json($p, []); if (!is_array($C)) $C = [];
    $now = t_now(); $want = [];
    foreach (($scan['rows'] ?? []) as $s => $r) if (is_array($r) && t1x_want($r)) $want[$s] = $r;
    $out = [];
    if ($now - (int)($C['_step'] ?? 0) >= 15) {   // only one request does the work at a time
        $C['_step'] = $now; t_write_json($p, $C);
        $TL = t_read_json(T_DATA . '/tl/_rows6.json', []); if (!is_array($TL)) $TL = [];
        $M15 = t_read_json(T_DATA . '/ema200_m15.json', []); if (!is_array($M15)) $M15 = [];
        $due = [];
        foreach ($want as $s => $r) { $c = $C[$s] ?? null; if (!is_array($c) || $now - (int)($c['at'] ?? 0) >= T1X_TTL || ($c['zn'] ?? '') !== $r['zone_near']) $due[$s] = (int)($c['at'] ?? 0); }
        asort($due); $t0 = microtime(true); $ch = false;
        foreach (array_keys($due) as $s) {
            if (microtime(true) - $t0 > T1X_BUDGET) break;
            $r = $want[$s];
            try {
                $tl = $TL[$s] ?? null;
                if (!is_array($tl) || !empty($tl['none']) || $now - (int)($tl['at'] ?? 0) > T1X_TL_OK) $tl = tl_row($s, $r, $now);
                $lines = is_array($tl) ? t1x_lines($tl, $r) : [];
            } catch (Throwable $e) { $lines = []; }
            try { $ema = t1x_ema($s, is_array($M15[$s] ?? null) ? $M15[$s] : null); } catch (Throwable $e) { $ema = []; }
            $C[$s] = ['at' => $now, 'zn' => $r['zone_near'], 'tl' => $lines, 'e2' => $ema]; $ch = true;
        }
        foreach ($C as $s => $c) if ($s !== '_step' && (!is_array($c) || $now - (int)($c['at'] ?? 0) > 3600)) unset($C[$s]);
        if ($ch) t_write_json($p, $C);
    }
    foreach ($want as $s => $r) {
        $c = $C[$s] ?? null;
        if (is_array($c) && ($c['zn'] ?? '') === $r['zone_near'] && $now - (int)($c['at'] ?? 0) < 900) $out[$s] = ['tl' => $c['tl'] ?? [], 'e2' => $c['e2'] ?? []];
    }
    return $out;
}
