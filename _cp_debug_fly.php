<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
require __DIR__.'/torus_engine.php';
require_once __DIR__.'/torus_tier2.php';
header('Content-Type: application/json');
$sym = $_GET['s'] ?? 'AUDCAD=X';
$out = [];

// 1. t1sticky.json raw state for this symbol
$S = t_read_json(T_DATA . '/t1sticky.json', []);
$out['sticky_S'] = $S[$sym] ?? null;
$out['sticky_end'] = $S['_end'][$sym] ?? null;
$out['sticky_gc'] = $S['_gc'][$sym] ?? null;

// 2. scan.json row
$scan = t_scan_load();
$r = $scan['rows'][$sym] ?? null;
if ($r) {
    $out['scan_row'] = [
        'ts'=>$r['ts'] ?? null, 'rmt'=>$r['rmt'] ?? null, 'signal'=>$r['signal'] ?? null, 'setup'=>$r['setup'] ?? null,
        'flow'=>$r['flow'] ?? null, 'flow_at'=>$r['flow_at'] ?? null, 'v10'=>$r['v10'] ?? null, 'tk'=>$r['tk'] ?? null,
        'tk_at'=>$r['tk_at'] ?? null, 'zone_dist_atr'=>$r['zone_dist_atr'] ?? null, 'price'=>$r['price'] ?? null,
        'atr5'=>$r['atr5'] ?? null, 'atr_high'=>$r['atr_high'] ?? null, 'atr_low'=>$r['atr_low'] ?? null,
        'flow_sl'=>$r['flow_sl'] ?? null, 'gc_until'=>$r['gc_until'] ?? null, 'sticky'=>$r['sticky'] ?? null,
    ];
}

// 3. open Tier-2 trades for this symbol
$t2 = t_read_json(T_DATA . '/t2trades.json', ['items'=>[]]);
$opens = [];
foreach (($t2['items'] ?? []) as $t) {
    if (($t['symbol'] ?? '') === $sym) {
        $opens[] = ['status'=>$t['status'] ?? null, 'sig'=>$t['sig'] ?? null, 'entry_time'=>$t['entry_time'] ?? null,
            'pattern_time'=>$t['pattern_time'] ?? null, 'setup'=>$t['setup'] ?? null, 'exit_time'=>$t['exit_time'] ?? null,
            'day_ext'=>$t['day_ext'] ?? null];
    }
}
$out['t2trades_for_symbol'] = $opens;
$out['t2_open_map_entry'] = t_t2_open_map()[$sym] ?? null;

echo json_encode($out, JSON_PRETTY_PRINT);
