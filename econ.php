<?php
/**
 * Economic calendar (owner, 28 Nov 2026, Terminal phase 2): this week's scheduled events, cached 30 min.
 *   GET /econ.php            -> {at, events:[{t (unix UTC), cur, title, impact High|Medium|Low|Holiday, forecast, previous}]}
 * Source: the public weekly calendar feed (faireconomy.media, ForexFactory data). Check its terms before any paid use.
 * Library use: econ_risk($symbol, $now, $min) -> the High-impact event within +/- $min minutes for that symbol's currencies, or null.
 */
declare(strict_types=1);
require_once __DIR__ . '/torus_engine.php';

const ECON_URL = 'https://nfs.faireconomy.media/ff_calendar_thisweek.json';
const ECON_CACHE = T_DATA . '/econ_week.json';

function econ_events(): array {
    $c = t_read_json(ECON_CACHE, null); $now = time();
    if (is_array($c) && $now - (int)($c['at'] ?? 0) < 1800) return $c;
    $raw = null;
    try { $raw = t_fetch(['e' => ECON_URL])['e'] ?? null; } catch (Throwable $e) {}
    $j = $raw ? json_decode((string)$raw, true) : null;
    if (!is_array($j)) return is_array($c) ? $c : ['at' => $now, 'events' => [], 'error' => 'calendar source unavailable'];
    $ev = [];
    foreach ($j as $x) {
        $t = strtotime((string)($x['date'] ?? '')); if (!$t) continue;
        $ev[] = ['t' => $t, 'cur' => strtoupper((string)($x['country'] ?? '')), 'title' => (string)($x['title'] ?? ''), 'impact' => (string)($x['impact'] ?? ''),
                 'forecast' => (string)($x['forecast'] ?? ''), 'previous' => (string)($x['previous'] ?? '')];
    }
    usort($ev, fn($a, $b) => $a['t'] <=> $b['t']);
    $p = ['at' => $now, 'events' => $ev];
    t_write_json(ECON_CACHE, $p);
    return $p;
}
/** Currencies whose scheduled news moves this symbol. */
function econ_curs(string $s): array {
    if (preg_match('/^([A-Z]{3})([A-Z]{3})=X$/', $s, $m)) return [$m[1], $m[2]];
    if (preg_match('/\.NS$|^\^NSE|^\^CNX|NIFTY/', $s)) return ['INR'];
    if (preg_match('/\.L$|^\^FTSE/', $s)) return ['GBP'];
    if (preg_match('/\.DE$|^\^GDAXI|^\^STOXX/', $s)) return ['EUR'];
    if (preg_match('/\.T$|^\^N225/', $s)) return ['JPY'];
    return ['USD'];   // US stocks/indices, gold, silver, oil, crypto all react to USD data
}
function econ_risk(string $s, int $now, int $min = 20): ?array {
    $cur = econ_curs($s);
    foreach (econ_events()['events'] ?? [] as $e)
        if ($e['impact'] === 'High' && in_array($e['cur'], $cur, true) && abs($e['t'] - $now) <= $min * 60) return $e;
    return null;
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'econ.php') {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: public, max-age=300');
    echo json_encode(econ_events(), JSON_UNESCAPED_SLASHES);
}
