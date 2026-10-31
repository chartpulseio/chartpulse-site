<?php
// Optional warm-up. The API rebuilds the cache itself when it is older than 15 minutes, so no cron job is required.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/strategies.php';
ta_get(dirname(__DIR__) . '/cache', scan_map(), SCAN_MIN_BARS, array_key_first($SCANNERS));
echo "ok\n";
