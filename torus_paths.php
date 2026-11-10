<?php
// Where ChartPulse keeps everything it records: the scan, signal log, Tier-2 trades, daily reports, sticky
// entry state, Telegram subscribers, mover archives, candle cache.
//
// 10 Nov 2026: this used to be <site>/data, INSIDE the folder that git deploys into. A deploy replaces that
// folder with the repository's files, and anything not in the repository goes -- so every push wiped the whole
// record (no trade history, empty admin report, empty mover archive, "First scan" after each deploy, Telegram
// subscribers lost). The data now lives next to the site folder, where a deploy never touches it and where it
// is not reachable from the web at all. If that folder cannot be used the old location is kept.
declare(strict_types=1);
if (!function_exists('cp_data_dir')) {
    function cp_data_dir(): string {
        static $d = null; if ($d !== null) return $d;
        $in = __DIR__ . '/data'; $out = dirname(__DIR__) . '/cp_data';
        if (!@is_dir($out)) {
            if (@is_dir($in)) @rename($in, $out);        // first run: carry over whatever is there
            if (!@is_dir($out)) @mkdir($out, 0755, true);
        }
        return $d = (@is_dir($out) && @is_writable($out)) ? $out : $in;
    }
}
