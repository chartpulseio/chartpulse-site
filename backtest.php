<?php
/* ChartPulse Pro Backtesting - public endpoint.
 *   POST backtest.php?a=run      {settings + strategy}  -> metrics, equity, trades, monte carlo
 *   POST backtest.php?a=forward  {settings + strategy + grid} -> out-of-sample windows
 *   GET  backtest.php?a=meta     -> symbol list, indicator list, presets
 * Errors are always plain visitor-facing text; no server detail ever leaves here.
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/torus_bt_api.php';
require_once __DIR__ . '/torus_bt_meta.php';

function bt_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function bt_fail($msg, $code = 422, $extra = [])
{
    bt_out(array_merge(['error' => $msg], $extra), $code);
}

$a = $_GET['a'] ?? 'meta';

try {
    if ($a === 'meta') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') bt_fail('That method is not allowed', 405);
        header('Cache-Control: public, max-age=600');
        bt_out(bt_meta());
    }

    if ($a !== 'run' && $a !== 'forward') bt_fail('Bad request', 400);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') bt_fail('That method is not allowed', 405);

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 60000) bt_fail('The request is too large', 413);
    $q = json_decode($raw, true);
    if (!is_array($q)) bt_fail('The request could not be read', 400);

    @set_time_limit($a === 'forward' ? 70 : 30);
    @ini_set('memory_limit', '256M');
    $t0 = microtime(true);

    $res = ($a === 'run') ? bt_handle_backtest($q) : bt_handle_walkforward($q);
    $res['took_ms'] = (int)round((microtime(true) - $t0) * 1000);
    bt_out($res);

} catch (BtLimit $e) {
    header('Retry-After: ' . (int)$e->retry);
    bt_fail($e->getMessage(), 429, ['retry_after' => (int)$e->retry]);
} catch (BtError $e) {
    bt_fail($e->getMessage(), 422);
} catch (BtData $e) {
    $m = $e->getMessage();
    if ($m === 'not-found') {
        bt_fail('That symbol code was not found. Pick one from the list, or check the code.', 404);
    }
    if ($m === 'crypto-not-found') bt_fail('That crypto pair was not found.', 404);
    if ($m === 'crypto-blocked' || $m === 'crypto-error') {
        bt_fail('Crypto data is unavailable right now. Please try again shortly.', 503);
    }
    bt_fail($m, 503);
} catch (Throwable $e) {
    /* never show internals - site rule */
    @error_log('[bt] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    bt_fail('Sorry for the inconvenience - the backtesting service is unavailable right now. Please try again shortly.', 503);
}
