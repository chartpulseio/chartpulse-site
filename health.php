<?php
declare(strict_types=1);
/**
 * Health endpoint. 200 "ok" when PHP runs and the cache folder is writable.
 * No versions, paths or secrets are ever revealed.
 */
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=30');

$ok = true;

// PHP is obviously running if we got here. Check a writable working folder.
require_once __DIR__ . '/torus_paths.php';
$cache = cp_data_dir();
if (!is_dir($cache) || !is_writable($cache)) {
    $cache = sys_get_temp_dir();
    if (!is_dir($cache) || !is_writable($cache)) {
        $ok = false;
    }
}

// Optional data-freshness check. 0 disables it.
$maxAgeMin = 0;
if ($ok && $maxAgeMin > 0) {
    $newest = 0;
    foreach (glob(cp_data_dir() . '/*') ?: [] as $f) {
        $newest = max($newest, (int)@filemtime($f));
    }
    if ($newest === 0 || (time() - $newest) > $maxAgeMin * 60) {
        $ok = false;
    }
}

http_response_code($ok ? 200 : 503);
echo $ok ? 'ok' : 'fail';
