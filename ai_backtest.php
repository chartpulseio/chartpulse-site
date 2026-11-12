<?php
/* ChartPulse AI Backtesting (owner, 12 Nov 2026): type a strategy in plain words, get a backtest.
 *   POST ai_backtest.php  {text, symbol?, timeframe?, years?}
 *   -> { engine: "ai" | "rules", understood: {...}, missed: [...], result: <same shape as backtest.php?a=run>, verdict: {...} }
 *
 * Reading the words: Gemini turns the text into the SAME strategy JSON the Pro Backtesting form produces
 * (key: data/ml/gemini.ini, owner-managed). If the key is not set, the call fails, or its answer does not pass
 * validation, a built-in phrase reader does the job. Either way the strategy goes through bt_validate_strategy()
 * and runs on the existing engine (torus_bt.php): the AI never invents a number in the result.
 * Errors are plain visitor-facing text; no server detail leaves here.
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/torus_bt_api.php';
require_once __DIR__ . '/torus_bt_meta.php';

function aib_out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function aib_fail($m, $code = 422, $x = []) { aib_out(array_merge(['error' => $m], $x), $code); }

/* ---------------------------------------------------------------- markets */
function aib_symbols() {
    static $S = null; if ($S !== null) return $S;
    $S = ['codes' => [], 'names' => []];
    foreach (bt_markets() as $g) foreach ($g[1] as $m) { $S['codes'][$m[0]] = $m[1]; $S['names'][strtolower($m[1])] = $m[0]; }
    $alias = ['bitcoin' => 'BTC-USD', 'btc' => 'BTC-USD', 'ethereum' => 'ETH-USD', 'eth' => 'ETH-USD', 'solana' => 'SOL-USD', 'dogecoin' => 'DOGE-USD', 'xrp' => 'XRP-USD',
        'gold' => 'GC=F', 'xauusd' => 'GC=F', 'silver' => 'SI=F', 'crude oil' => 'CL=F', 'crude' => 'CL=F', 'oil' => 'CL=F', 'natural gas' => 'NG=F', 'copper' => 'HG=F',
        'bank nifty' => '^NSEBANK', 'banknifty' => '^NSEBANK', 'nifty 50' => '^NSEI', 'nifty' => '^NSEI', 'sensex' => '^BSESN', 's&p 500' => '^GSPC', 's&p' => '^GSPC', 'sp500' => '^GSPC', 'spx' => '^GSPC',
        'nasdaq 100' => '^NDX', 'nasdaq' => '^NDX', 'dow jones' => '^DJI', 'dow' => '^DJI', 'dax' => '^GDAXI', 'nikkei' => '^N225', 'ftse' => '^FTSE', 'dollar index' => 'DX-Y.NYB', 'dxy' => 'DX-Y.NYB',
        'apple' => 'AAPL', 'microsoft' => 'MSFT', 'nvidia' => 'NVDA', 'amazon' => 'AMZN', 'google' => 'GOOGL', 'alphabet' => 'GOOGL', 'meta' => 'META', 'facebook' => 'META', 'tesla' => 'TSLA', 'netflix' => 'NFLX',
        'reliance' => 'RELIANCE.NS', 'tcs' => 'TCS.NS', 'hdfc bank' => 'HDFCBANK.NS', 'hdfc' => 'HDFCBANK.NS', 'infosys' => 'INFY.NS', 'icici bank' => 'ICICIBANK.NS', 'icici' => 'ICICIBANK.NS', 'sbi' => 'SBIN.NS',
        'tata motors' => 'TATAMOTORS.NS', 'tata steel' => 'TATASTEEL.NS', 'itc' => 'ITC.NS', 'wipro' => 'WIPRO.NS', 'zomato' => 'ZOMATO.NS', 'airtel' => 'BHARTIARTL.NS', 'maruti' => 'MARUTI.NS',
        'eurusd' => 'EURUSD=X', 'eur/usd' => 'EURUSD=X', 'gbpusd' => 'GBPUSD=X', 'gbp/usd' => 'GBPUSD=X', 'usdjpy' => 'USDJPY=X', 'usd/jpy' => 'USDJPY=X', 'audusd' => 'AUDUSD=X', 'aud/usd' => 'AUDUSD=X',
        'usdcad' => 'USDCAD=X', 'usd/cad' => 'USDCAD=X', 'gbpjpy' => 'GBPJPY=X', 'gbp/jpy' => 'GBPJPY=X', 'eurjpy' => 'EURJPY=X', 'eur/jpy' => 'EURJPY=X', 'nzdusd' => 'NZDUSD=X', 'usdchf' => 'USDCHF=X'];
    foreach ($alias as $k => $v) if (isset($S['codes'][$v])) $S['names'][$k] = $v;
    uksort($S['names'], function ($a, $b) { return strlen($b) - strlen($a); });   // longest name first ("bank nifty" before "nifty")
    return $S;
}
function aib_find_symbol($t) {
    $S = aib_symbols();
    foreach ($S['names'] as $name => $code) if (strlen($name) >= 3 && preg_match('/(?<![a-z0-9])' . preg_quote($name, '/') . '(?![a-z0-9])/', $t)) return $code;
    return null;
}
function aib_find_tf($t) {
    $map = ['1w' => '/\b(weekly|week chart|1 ?w)\b/', '4h' => '/\b(4 ?h(our|r)?s?|four hour)\b/', '1h' => '/\b(1 ?h(our|r)?|hourly|60 ?min)/', '30m' => '/\b30 ?m(in(ute)?s?)?\b/',
            '15m' => '/\b15 ?m(in(ute)?s?)?\b/', '5m' => '/\b5 ?m(in(ute)?s?)?\b/', '1d' => '/\b(daily|day chart|1 ?d|eod)\b/'];
    foreach ($map as $tf => $rx) if (preg_match($rx, $t)) return $tf;
    return null;
}
function aib_find_years($t) {
    if (preg_match('/(\d+(?:\.\d+)?)\s*(years?|yrs?)\b/', $t, $m)) return min(25, max(0.1, (float)$m[1]));
    if (preg_match('/(\d+)\s*months?\b/', $t, $m)) return min(25, max(0.1, (int)$m[1] / 12));
    return null;
}

/* ------------------------------------------------- built-in phrase reader */
function aib_operand($s) {
    $s = trim(preg_replace('/\b(the|its|it\'s|a|an|is|goes|moves|gets|of|line|level|value|indicator|then|also|day|days|period|periods|bar|bars|candle|candles)\b/', ' ', ' ' . $s . ' '));
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if ($s === '') return null;
    if (preg_match('/^-?\d+(\.\d+)?$/', $s)) return ['value' => (float)$s];
    if (preg_match('/(\d+)\s*-?\s*(ema|sma|wma)\b/', $s, $m) || preg_match('/\b(ema|sma|wma)\s*-?\s*\(?(\d+)\)?/', $s, $m)) {
        $ind = is_numeric($m[1]) ? $m[2] : $m[1]; $p = is_numeric($m[1]) ? $m[1] : $m[2];
        return ['ind' => $ind, 'period' => max(1, min(500, (int)$p))];
    }
    if (preg_match('/(\d+)\s*-?\s*(?:moving average|ma)\b/', $s, $m) || preg_match('/\b(?:moving average|ma)\s*-?\s*\(?(\d+)\)?/', $s, $m)) return ['ind' => 'sma', 'period' => max(1, min(500, (int)$m[1]))];
    if (preg_match('/\b(ema)\b/', $s)) return ['ind' => 'ema', 'period' => 20];
    if (preg_match('/\b(sma|moving average)\b/', $s)) return ['ind' => 'sma', 'period' => 50];
    if (preg_match('/\brsi\s*\(?(\d+)?\)?/', $s, $m)) return ['ind' => 'rsi', 'period' => !empty($m[1]) && (int)$m[1] <= 100 && !preg_match('/rsi\s+\d+$/', $s) ? (int)$m[1] : 14];
    if (preg_match('/macd\s*hist/', $s)) return ['ind' => 'macd_hist', 'period' => 12, 'period2' => 26, 'period3' => 9];
    if (preg_match('/(macd\s*signal|signal)/', $s)) return ['ind' => 'macd_signal', 'period' => 12, 'period2' => 26, 'period3' => 9];
    if (preg_match('/\bmacd\b/', $s)) return ['ind' => 'macd', 'period' => 12, 'period2' => 26, 'period3' => 9];
    if (preg_match('/upper\s*(bollinger|bb)?\s*band|bollinger\s*upper|upper\s*bollinger/', $s)) return ['ind' => 'bb_upper', 'period' => 20, 'mult' => 2];
    if (preg_match('/lower\s*(bollinger|bb)?\s*band|bollinger\s*lower|lower\s*bollinger/', $s)) return ['ind' => 'bb_lower', 'period' => 20, 'mult' => 2];
    if (preg_match('/(middle|mid)\s*(bollinger|bb)?\s*band/', $s)) return ['ind' => 'bb_mid', 'period' => 20, 'mult' => 2];
    if (preg_match('/(\d+)\s*-?\s*(?:week\s*)?(high|low)\b/', $s, $m)) { $n = (int)$m[1] * (strpos($s, 'week') !== false ? 5 : 1); return ['ind' => $m[2] === 'high' ? 'highest' : 'lowest', 'period' => max(2, min(500, $n))]; }
    if (preg_match('/\bvwap\b/', $s)) return ['ind' => 'vwap', 'period' => 20];
    if (preg_match('/\badx\b/', $s)) return ['ind' => 'adx', 'period' => 14];
    if (preg_match('/\b(stochastic|stoch)\b/', $s)) return ['ind' => 'stoch_k', 'period' => 14, 'period2' => 1, 'period3' => 3];
    if (preg_match('/\bsupertrend\b/', $s)) return ['ind' => 'supertrend', 'period' => 10, 'mult' => 3];
    if (preg_match('/(average|avg)\s*volume|volume\s*(average|avg)/', $s)) return ['ind' => 'sma', 'period' => 20, 'src' => 'volume'];
    if (preg_match('/\bvolume\b/', $s)) return ['ind' => 'volume'];
    if (preg_match('/\batr\b/', $s)) return ['ind' => 'atr', 'period' => 14];
    if (preg_match('/\b(price|close|closes|closing|market|stock|candle)\b/', $s)) return ['ind' => 'close'];
    if (preg_match('/\bhigh\b/', $s)) return ['ind' => 'high'];
    if (preg_match('/\blow\b/', $s)) return ['ind' => 'low'];
    return null;
}
/** One phrase ("rsi below 30") -> rule(s), or null when it cannot be read. */
function aib_rule($c) {
    $c = trim($c); if ($c === '') return null;
    $R = function ($l, $op, $r) { return [['left' => $l, 'op' => $op, 'right' => $r]]; };
    if (preg_match('/golden cross/', $c)) return $R(['ind' => 'sma', 'period' => 50], 'crosses_above', ['ind' => 'sma', 'period' => 200]);
    if (preg_match('/death cross/', $c)) return $R(['ind' => 'sma', 'period' => 50], 'crosses_below', ['ind' => 'sma', 'period' => 200]);
    if (preg_match('/supertrend/', $c) && preg_match('/\b(bullish|green|up|buy|positive|bearish|red|down|sell|negative)\b/', $c, $m)) {
        $up = in_array($m[1], ['bullish', 'green', 'up', 'buy', 'positive'], true); $turn = preg_match('/(turn|flip|chang|becom|switch|cross)/', $c);
        return $R(['ind' => 'supertrend_dir', 'period' => 10, 'mult' => 3], $turn ? ($up ? 'crosses_above' : 'crosses_below') : ($up ? '>' : '<'), ['value' => 0]);
    }
    if (preg_match('/macd/', $c) && preg_match('/(bullish|bearish)\s*(cross|crossover)/', $c, $m))
        return $R(['ind' => 'macd', 'period' => 12, 'period2' => 26, 'period3' => 9], $m[1] === 'bullish' ? 'crosses_above' : 'crosses_below', ['ind' => 'macd_signal', 'period' => 12, 'period2' => 26, 'period3' => 9]);
    if (preg_match('/\brsi\b/', $c) && preg_match('/\b(oversold|overbought)\b/', $c, $m)) { $o = aib_operand(preg_replace('/\b(oversold|overbought)\b/', '', $c)) ?: ['ind' => 'rsi', 'period' => 14]; return $R($o, $m[1] === 'oversold' ? '<' : '>', ['value' => $m[1] === 'oversold' ? 30 : 70]); }
    if (preg_match('/\b(stochastic|stoch)\b/', $c) && preg_match('/\b(oversold|overbought)\b/', $c, $m)) return $R(['ind' => 'stoch_k', 'period' => 14, 'period2' => 1, 'period3' => 3], $m[1] === 'oversold' ? '<' : '>', ['value' => $m[1] === 'oversold' ? 20 : 80]);
    if (preg_match('/(new|fresh|makes?|hits?|breakout (?:of|above)|breaks? out (?:of|above)|breaks?(?: above)?)\s+(?:a |the )?(\d+)\s*-?\s*(day|bar|candle|week)s?\s*(high|low)/', $c, $m)) {
        $n = (int)$m[2] * ($m[3] === 'week' ? 5 : 1); $hi = $m[4] === 'high';
        return $R(['ind' => 'close'], $hi ? '>' : '<', ['ind' => $hi ? 'highest' : 'lowest', 'period' => max(2, min(500, $n))]);
    }
    $ops = ['crosses_above' => '(cross(?:es|ed|ing)?\s*(?:back\s*)?(?:above|over|up through)|breaks?\s*(?:out\s*)?above|moves?\s*back\s*above|reclaims?)',
            'crosses_below' => '(cross(?:es|ed|ing)?\s*(?:back\s*)?(?:below|under|down through)|breaks?\s*(?:down\s*)?below|falls?\s*back\s*below|loses?)',
            '>' => '(>=?|(?:is\s*|are\s*|stays?\s*|closes?\s*|trading\s*|trades?\s*)?(?:above|over|greater than|more than|higher than|exceeds?))',
            '<' => '(<=?|(?:is\s*|are\s*|stays?\s*|closes?\s*|trading\s*|trades?\s*|drops?\s*|falls?\s*|dips?\s*)?(?:below|under|less than|lower than))'];
    foreach ($ops as $op => $rx) {
        if (!preg_match('/^(.*?)(?<![a-z])' . $rx . '\s+(.*)$/', $c, $m)) continue;
        $l = aib_operand($m[1]); $r = aib_operand($m[count($m) - 1]);
        if (!$l && $r && !isset($r['value'])) $l = ['ind' => 'close'];
        if (!$l || !$r) continue;
        if (isset($l['value'])) continue;
        return $R($l, $op, $r);
    }
    return null;
}
function aib_mirror($rules) {
    $sw = ['>' => '<', '<' => '>', '>=' => '<=', '<=' => '>=', 'crosses_above' => 'crosses_below', 'crosses_below' => 'crosses_above']; $out = [];
    foreach ($rules as $r) {
        $neutral = ['volume', 'adx', 'atr', 'atr_pct', 'bb_width', 'stdev'];   // not directional: the same condition serves both sides
        if (in_array($r['left']['ind'] ?? '', $neutral, true) || ($r['left']['src'] ?? '') === 'volume') { $out[] = $r; continue; }
        $n = $r; $n['op'] = $sw[$r['op']];
        foreach (['left', 'right'] as $k) {
            $o = $n[$k];
            if (isset($o['ind'])) { $flip = ['highest' => 'lowest', 'lowest' => 'highest', 'bb_upper' => 'bb_lower', 'bb_lower' => 'bb_upper', 'plus_di' => 'minus_di', 'minus_di' => 'plus_di']; if (isset($flip[$o['ind']])) $o['ind'] = $flip[$o['ind']]; }
            $n[$k] = $o;
        }
        $li = $n['left']['ind'] ?? ''; if (isset($n['right']['value']) && in_array($li, ['rsi', 'stoch_k', 'stoch_d'], true)) $n['right']['value'] = 100 - $n['right']['value'];
        $out[] = $n;
    }
    return $out;
}
function aib_risk(&$t, &$used) {
    $risk = []; $num = '(\d+(?:\.\d+)?)';
    $grab = function ($rx, $key, $idx = 1) use (&$t, &$risk, &$used) { if (!isset($risk[$key]) && preg_match($rx, $t, $m)) { $risk[$key] = (float)$m[$idx]; $used[] = $m[0]; $t = str_replace($m[0], ' ', $t); return true; } return false; };
    $grab('/trail(?:ing)?\s*(?:stop(?:\s*loss)?)?\s*(?:of|at|by|=|:)?\s*' . $num . '\s*(?:x|times)?\s*atr/', 'trail_atr') || $grab('/' . $num . '\s*(?:x|times)?\s*atr\s*trail(?:ing)?/', 'trail_atr');
    $grab('/trail(?:ing)?\s*(?:stop(?:\s*loss)?)?\s*(?:of|at|by|=|:)?\s*' . $num . '\s*(?:%|percent)/', 'trail_pct') || $grab('/' . $num . '\s*(?:%|percent)\s*trail(?:ing)?/', 'trail_pct');
    $grab('/(?:stop\s*loss|stoploss|stop|sl)\s*(?:of|at|is|=|:)?\s*' . $num . '\s*(?:x|times)?\s*atr/', 'stop_loss_atr') || $grab('/' . $num . '\s*(?:x|times)?\s*atr\s*(?:stop\s*loss|stoploss|stop|sl)/', 'stop_loss_atr');
    $grab('/(?:stop\s*loss|stoploss|stop|sl)\s*(?:of|at|is|=|:)?\s*' . $num . '\s*(?:%|percent)/', 'stop_loss_pct') || $grab('/' . $num . '\s*(?:%|percent)\s*(?:stop\s*loss|stoploss|stop|sl)/', 'stop_loss_pct');
    $grab('/(?:take\s*profit|target|tp|book\s*profit)\s*(?:of|at|is|=|:)?\s*' . $num . '\s*(?:x|times)?\s*atr/', 'take_profit_atr') || $grab('/' . $num . '\s*(?:x|times)?\s*atr\s*(?:take\s*profit|target|tp)/', 'take_profit_atr');
    $grab('/(?:take\s*profit|target|tp|book\s*profit)\s*(?:of|at|is|=|:)?\s*' . $num . '\s*(?:%|percent)/', 'take_profit_pct') || $grab('/' . $num . '\s*(?:%|percent)\s*(?:take\s*profit|target|tp|profit)/', 'take_profit_pct');
    if (preg_match('/(?:exit|close|sell|hold)\s*(?:the trade\s*)?(?:after|for)\s*(\d+)\s*(?:bars?|days?|candles?|sessions?)/', $t, $m)) { $risk['max_bars'] = (int)$m[1]; $used[] = $m[0]; }
    // reward:risk ("1:2", "2R", "risk reward 3") scales the target from the stop
    $rr = null;
    if (preg_match('/\b1\s*:\s*' . $num . '\b/', $t, $m)) $rr = (float)$m[1]; elseif (preg_match('/\b' . $num . '\s*r\b(?!\w)/', $t, $m)) $rr = (float)$m[1]; elseif (preg_match('/(?:risk\s*(?:to\s*)?reward|reward\s*(?:to\s*)?risk|rr)\s*(?:of|is|=|:)?\s*' . $num . '/', $t, $m)) $rr = (float)$m[1];
    if ($rr && $rr > 0 && $rr <= 20) {
        $used[] = $m[0];
        if (isset($risk['stop_loss_pct']) && !isset($risk['take_profit_pct'])) $risk['take_profit_pct'] = round($risk['stop_loss_pct'] * $rr, 2);
        elseif (isset($risk['stop_loss_atr']) && !isset($risk['take_profit_atr'])) $risk['take_profit_atr'] = round($risk['stop_loss_atr'] * $rr, 2);
    }
    if (isset($risk['stop_loss_atr']) || isset($risk['take_profit_atr']) || isset($risk['trail_atr'])) $risk['atr_period'] = 14;
    return $risk;
}
/** Simple Hinglish -> English, so "jab rsi 30 se neeche ho tab buy karo" reads like "buy when rsi below 30". */
function aib_hinglish($t) {
    $w = ['khareedo' => 'buy', 'kharido' => 'buy', 'khareed lo' => 'buy', 'kharid lo' => 'buy', 'khareedna' => 'buy', 'becho' => 'sell', 'bech do' => 'sell', 'bechna' => 'sell',
          'nikal jao' => 'exit', 'nikalo' => 'exit', 'bahar niklo' => 'exit', 'aur' => 'and', 'or bhi' => 'and', 'jab bhi' => 'when', 'jab' => 'when', 'jb' => 'when', 'agar' => 'if',
          'ghante' => 'hour', 'ghanta' => 'hour', 'din' => 'day', 'saal' => 'years', 'mahine' => 'months', 'pichle' => 'last', 'dono side' => 'both sides', 'dono taraf' => 'both sides'];
    foreach ($w as $k => $v) $t = preg_replace('/(?<![a-z])' . preg_quote($k, '/') . '(?![a-z])/', $v, $t);
    // "X se/ke upar" -> "above X", "X se/ke neeche" -> "below X"
    $opd = '(\d+\s*(?:ema|sma|wma|moving average)|(?:ema|sma|wma)\s*\d+|ema|sma|wma|vwap|supertrend|\d+(?:\.\d+)?)';
    // "A B ke upar cross kare" -> "A crosses above B" (before the plain above/below rules)
    $t = preg_replace('/' . $opd . '\s+' . $opd . '\s+(?:ke\s+|se\s+)?(?:upar|uppar|oopar)\s+(?:cross|cut)\w*\s*(?:kare|kre|karta|kar de|ho)?/', ' $1 crosses above $2 ', $t);
    $t = preg_replace('/' . $opd . '\s+' . $opd . '\s+(?:ke\s+|se\s+)?(?:neeche|niche)\s+(?:cross|cut)\w*\s*(?:kare|kre|karta|kar de|ho)?/', ' $1 crosses below $2 ', $t);
    $t = preg_replace('/' . $opd . '\s+(?:ke\s+|ki\s+|se\s+)+(?:upar|uppar|oopar|zyada|jyada|adhik)\b/', ' above $1 ', $t);
    $t = preg_replace('/' . $opd . '\s+(?:ke\s+|ki\s+|se\s+)+(?:neeche|niche|nichay|kam)\b/', ' below $1 ', $t);
    $t = preg_replace('/\b(?:upar|uppar|oopar)\s+(?:cross|cut)\s*(?:kare|kre|karta|kar de|ho)?/', ' crosses above ', $t);
    $t = preg_replace('/\b(?:neeche|niche)\s+(?:cross|cut)\s*(?:kare|kre|karta|kar de|ho)?/', ' crosses below ', $t);
    $t = preg_replace('/\b(?:ho jaye|ho jaaye|ho jata|ho jaata|ban jaye|bane|hote hi|turn ho)\b/', ' becomes ', $t);
    // "when COND tab/to ACTION karo" -> "ACTION when COND"
    $act = '(buy|sell|exit|short|long|entry|cover|close)';
    for ($k = 0; $k < 4; $k++) {
        $n = preg_replace('/\b(when|if)\s+([^.;,]+?)\s+(?:tab|to|toh|then)\s+' . $act . '\b\s*(?:karo|kro|kar do|krdo|karna|kare|lo|le lo|lena|lenge|karenge)?/', ' $3 when $2 ', $t);
        if ($n === $t) break; $t = $n;
    }
    // a clause with no "when": "rsi 70 se upar ho to becho" -> "sell when rsi above 70"
    $t = preg_replace('/(^|[.;,]\s*)(?!\s*(?:buy|sell|exit|short|long|entry|cover|close)\b)([^.;,]+?)\s+(?:to|toh|tab|then)\s+' . $act . '\b\s*(?:karo|kro|kar do|krdo|karna|kare|lo|le lo|lena|lenge|karenge)?/', '$1 $3 when $2 ', $t);
    $t = preg_replace('/\b(karo|kro|karna|kar do|krdo|lo|lena|lenge|karenge|chahiye|hai|ho|hon|me|mein|par|pe|ka|ki|ke|ko)\b/', ' ', $t);
    return preg_replace('/\s+/', ' ', $t);
}
function aib_parse_local($text) {
    $t = ' ' . strtolower($text) . ' ';
    $t = str_replace(['’', '‘', '“', '”', '–', '—'], ["'", "'", '"', '"', '-', '-'], $t);
    $t = aib_hinglish($t);
    $t = preg_replace('/\b(\d+)\s*-\s*(day|period|bar|week)\b/', '$1 $2', $t);
    $t = preg_replace('/\b(exponential moving average)\b/', 'ema', $t); $t = preg_replace('/\b(simple moving average)\b/', 'sma', $t);
    $t = preg_replace('/\brelative strength index\b/', 'rsi', $t); $t = preg_replace('/\bbollinger bands?\b/', 'bollinger band', $t);
    $used = []; $risk = aib_risk($t, $used);
    foreach ($used as $u) $t = str_replace($u, ' ', $t);
    $rules = ['entry_long' => [], 'exit_long' => [], 'entry_short' => [], 'exit_short' => []]; $missed = [];
    $both = (bool)preg_match('/(long and short|both (sides|directions|ways)|opposite for (short|sell)|vice versa|reverse for short)/', $t);
    $t = preg_replace('/(and |, )?(do |use |take )?(the )?(opposite|reverse|vice versa)( rules?)?( for (the )?(shorts?|short side|sell side|sells?))?/', ' ', $t);
    $hasShort = (bool)preg_match('/\b(go short|short when|short if|sell short|enter short|short entry|shorting)\b/', $t);
    // cut the text into chunks, each starting at an action word
    $mk = '(?:go long|enter long|long entry|long when|long if|buy|enter|entry|go short|enter short|short entry|sell short|short when|short if|short|exit long|exit short|exit|close|sell|cover|book profit|take profit)';
    $parts = preg_split('/(?=\b' . $mk . '\b)/', $t, -1, PREG_SPLIT_NO_EMPTY);
    $side = 'entry_long';
    foreach ($parts as $p) {
        $p = trim($p); if ($p === '') continue;
        if (preg_match('/^(go short|enter short|short entry|sell short|short when|short if|short)\b/', $p)) $side = 'entry_short';
        elseif (preg_match('/^(exit short|cover)\b/', $p)) $side = 'exit_short';
        elseif (preg_match('/^(exit long)\b/', $p)) $side = 'exit_long';
        elseif (preg_match('/^(exit|close|book profit|take profit)\b/', $p)) $side = ($side === 'entry_short' || $side === 'exit_short') ? 'exit_short' : 'exit_long';
        elseif (preg_match('/^sell\b/', $p)) $side = $hasShort ? 'exit_long' : ($rules['entry_long'] ? 'exit_long' : 'entry_short');
        elseif (preg_match('/^(go long|enter long|long entry|long when|long if|buy|enter|entry)\b/', $p)) $side = 'entry_long';
        $body = preg_replace('/^' . $mk . '\b/', '', $p);
        $body = preg_replace('/\b(when|if|once|as soon as|whenever|only|the trade|trade|position|a position|me|i|we|you|should|want to|will|it|on)\b/', ' ', $body);
        foreach (preg_split('/\s*(?:,|;|\.(?!\d)|\band\b|\bwith\b|\bwhile\b|\bbut\b|\bplus\b|&|\n)\s*/', $body) as $c) {
            $c = trim(preg_replace('/\s+/', ' ', $c)); if (strlen($c) < 3) continue;
            $r = aib_rule($c);
            if ($r) { foreach ($r as $x) if (count($rules[$side]) < 10) $rules[$side][] = $x; }
            elseif (preg_match('/(ema|sma|rsi|macd|vwap|adx|atr|bollinger|supertrend|stoch|above|below|cross|high|low|volume|price|close)/', $c)) $missed[] = $c;
        }
    }
    if ($both) {
        if ($rules['entry_long'] && !$rules['entry_short']) $rules['entry_short'] = aib_mirror($rules['entry_long']);
        if ($rules['exit_long'] && !$rules['exit_short']) $rules['exit_short'] = aib_mirror($rules['exit_long']);
    }
    return ['strategy' => ['rules' => $rules, 'risk' => $risk], 'missed' => array_slice(array_values(array_unique($missed)), 0, 6),
            'symbol' => aib_find_symbol($t), 'timeframe' => aib_find_tf($t), 'years' => aib_find_years($t)];
}

/* ----------------------------------------------------------- Gemini reader */
function aib_key($name = 'gemini') {
    require_once __DIR__ . '/torus_paths.php';
    $f = cp_data_dir() . '/ml/' . $name . '.ini'; $k = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    $k = trim(preg_replace('/^[A-Za-z_ ]*=\s*/', '', $k), " \t\r\n\"'");
    return preg_match('/^[A-Za-z0-9_.\-]{20,}$/', $k) ? $k : '';
}
/** Tidy a model's strategy so small slips do not throw the whole answer away. */
function aib_clean($st) {
    if (!is_array($st)) return $st;
    $def = ['sma' => 50, 'ema' => 20, 'wma' => 20, 'rsi' => 14, 'atr' => 14, 'atr_pct' => 14, 'stdev' => 20, 'bb_upper' => 20, 'bb_lower' => 20, 'bb_mid' => 20, 'bb_width' => 20,
            'highest' => 20, 'lowest' => 20, 'macd' => 12, 'macd_signal' => 12, 'macd_hist' => 12, 'adx' => 14, 'plus_di' => 14, 'minus_di' => 14, 'supertrend' => 10, 'supertrend_dir' => 10,
            'stoch_k' => 14, 'stoch_d' => 14, 'vwap' => 20, 'roc' => 10];
    $op = function ($o) use ($def) {
        if (!is_array($o)) return $o;
        if (isset($o['value']) && is_numeric($o['value'])) return ['value' => (float)$o['value']];   // a number side carries nothing else
        if (!isset($o['ind'])) return $o;
        $o['ind'] = strtolower((string)$o['ind']);
        $al = ['price' => 'close', 'supertrend_direction' => 'supertrend_dir', 'bollinger_upper' => 'bb_upper', 'bollinger_lower' => 'bb_lower', 'stochastic' => 'stoch_k', 'macd_line' => 'macd'];
        if (isset($al[$o['ind']])) $o['ind'] = $al[$o['ind']];
        if (in_array($o['ind'], ['open', 'high', 'low', 'close', 'volume', 'hl2', 'hlc3', 'ohlc4'], true)) { unset($o['period'], $o['period2'], $o['period3'], $o['mult'], $o['src']); }
        elseif (empty($o['period']) && isset($def[$o['ind']])) $o['period'] = $def[$o['ind']];
        foreach (['period', 'period2', 'period3', 'mult', 'src', 'tf', 'back', 'offset'] as $k) if (array_key_exists($k, $o) && ($o[$k] === null || $o[$k] === '')) unset($o[$k]);
        if (isset($o['src']) && $o['src'] === 'close') unset($o['src']);
        if (isset($o['tf'])) { $m = ['1d' => '1D', '1w' => '1W', '5m' => '5min', '15m' => '15min', '30m' => '30min']; $o['tf'] = $m[strtolower((string)$o['tf'])] ?? $o['tf']; if (!isset(BT_TF_SEC[$o['tf']])) unset($o['tf']); }
        foreach (array_keys($o) as $k) if (!in_array($k, ['ind', 'period', 'period2', 'period3', 'mult', 'src', 'tf', 'back', 'offset'], true)) unset($o[$k]);
        return $o;
    };
    foreach (['entry_long', 'exit_long', 'entry_short', 'exit_short'] as $k) {
        $L = $st['rules'][$k] ?? []; if (!is_array($L)) $L = [];
        $out = [];
        foreach ($L as $r) if (is_array($r) && isset($r['op'], $r['left'], $r['right'])) {
            $r['op'] = ['above' => '>', 'below' => '<', 'cross_above' => 'crosses_above', 'cross_below' => 'crosses_below', '==' => '>=', 'crossover' => 'crosses_above', 'crossunder' => 'crosses_below'][$r['op']] ?? $r['op'];
            $out[] = ['left' => $op($r['left']), 'op' => $r['op'], 'right' => $op($r['right'])];
        }
        $st['rules'][$k] = $out;
    }
    if (isset($st['risk']) && is_array($st['risk'])) foreach ($st['risk'] as $k => $v) if ($v === null || $v === '' || $v === 0 || $v === false) unset($st['risk'][$k]);
    return $st;
}
/** Model answer text -> our parse shape, or null. */
function aib_read_json($txt) {
    $txt = trim((string)$txt);
    if (preg_match('/\{.*\}/s', $txt, $m)) $txt = $m[0];
    $o = json_decode($txt, true);
    if (!is_array($o) || !isset($o['strategy']['rules'])) return null;
    return ['strategy' => $o['strategy'], 'missed' => array_slice(array_values(array_filter(array_map('strval', (array)($o['missed'] ?? [])))), 0, 6),
            'symbol' => is_string($o['symbol'] ?? null) ? strtoupper($o['symbol']) : null, 'timeframe' => is_string($o['timeframe'] ?? null) ? strtolower($o['timeframe']) : null,
            'years' => is_numeric($o['years'] ?? null) ? (float)$o['years'] : null];
}
function aib_post($url, $body, $hdr, $timeout = 14) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $hdr)]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if ($code !== 200) {
        $msg = is_array($j) ? (string)($j['error']['message'] ?? json_encode($j['error'] ?? '')) : ($cerr ?: substr((string)$raw, 0, 160));
        @file_put_contents(bt_dir('ai') . '/last_error.json', json_encode(['at' => gmdate('c'), 'host' => parse_url($url, PHP_URL_HOST), 'code' => $code, 'msg' => mb_substr($msg, 0, 240)]), LOCK_EX);
    }
    return $j;
}
/** Readers in order: Groq (most free answers), then Gemini (backup). Returns [parse, reader name] or [null, null]. */
function aib_parse_ai($text) {
    $groq = aib_key('groq'); $gem = aib_key('gemini');
    if ($groq === '' && $gem === '') return [null, null];
    $cf = bt_dir('ai') . '/p_' . md5($text) . '.json';
    if (is_file($cf) && time() - filemtime($cf) < 30 * 86400) { $j = json_decode((string)@file_get_contents($cf), true); if (is_array($j) && isset($j['p'])) return [$j['p'], $j['by']]; }
    $capf = bt_dir('ai') . '/cap_' . gmdate('Ymd') . '.txt'; $n = (int)@file_get_contents($capf);
    if ($n >= 1500) return [null, null]; @file_put_contents($capf, (string)($n + 1), LOCK_EX);
    $codes = implode(', ', array_keys(aib_symbols()['codes']));
    $sys = "You convert a trader's plain-language strategy into JSON for a backtesting engine. Output ONLY one JSON object, no prose.\n"
      . "Shape: {\"symbol\": code or null, \"timeframe\": one of 5m 15m 30m 1h 4h 1d 1w or null, \"years\": number or null, \"strategy\": {\"rules\": {\"entry_long\": [rule], \"exit_long\": [rule], \"entry_short\": [rule], \"exit_short\": [rule]}, \"risk\": {optional keys}}, \"missed\": [short strings for parts you could not express]}\n"
      . "rule = {\"left\": operand, \"op\": one of > < >= <= crosses_above crosses_below, \"right\": operand}. All rules in one list must be true together (AND). Max 10 per list.\n"
      . "operand = {\"value\": number} OR {\"ind\": name, \"period\": int, optional \"period2\", \"period3\", \"mult\", \"src\", \"tf\"}.\n"
      . "ind names: open high low close volume hl2 hlc3 ohlc4 (no period); sma ema wma rsi atr atr_pct stdev bb_upper bb_lower bb_mid bb_width highest lowest macd macd_signal macd_hist adx plus_di minus_di supertrend supertrend_dir stoch_k stoch_d vwap roc (period required).\n"
      . "Defaults: rsi 14; macd period 12 period2 26 period3 9; bollinger period 20 mult 2; supertrend period 10 mult 3; stoch period 14 period2 1 period3 3; adx 14; vwap 20. supertrend_dir is 1 (up) or -1 (down): bullish = supertrend_dir > 0; turns bullish = supertrend_dir crosses_above 0. highest/lowest = highest high / lowest low of the previous n bars (breakout: close > highest n). src may be volume (e.g. average volume = sma period 20 src volume). tf (higher timeframe) is one of 5min 15min 30min 1h 4h 1D 1W and only when the user asks for it.\n"
      . "risk keys (all optional, numbers): stop_loss_pct, take_profit_pct, stop_loss_atr, take_profit_atr, trail_pct, trail_atr, max_bars, atr_period. Percent values are plain numbers (2 = 2%). A reward:risk of 1:2 with a 2% stop means take_profit_pct 4.\n"
      . "Only add short rules if the user asks to short or to trade both sides. 'sell' after a buy rule means exit_long. Never invent conditions the user did not state. If the text has no usable entry rule, return entry lists empty.\n"
      . "symbol must be one of: $codes (or null if the user named none). The user may write in English, Hindi or Hinglish.";
    $user = mb_substr($text, 0, 1500);
    $got = null; $by = null;
    if ($groq !== '') foreach (['openai/gpt-oss-120b', 'llama-3.3-70b-versatile'] as $model) {
        $j = aib_post('https://api.groq.com/openai/v1/chat/completions', json_encode(['model' => $model, 'temperature' => 0, 'max_tokens' => 2048,
            'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['Authorization: Bearer ' . $groq]);
        if ($got = aib_read_json($j['choices'][0]['message']['content'] ?? '')) { $by = 'groq'; break; }
    }
    if (!$got && $gem !== '') foreach (['gemini-3.5-flash-lite', 'gemini-3.5-flash'] as $model) {
        $j = aib_post("https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent", json_encode(['systemInstruction' => ['parts' => [['text' => $sys]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]], 'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 2048, 'responseMimeType' => 'application/json']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['x-goog-api-key: ' . $gem]);
        $txt = ''; foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) $txt .= (string)($p['text'] ?? '');
        if ($got = aib_read_json($txt)) { $by = 'gemini'; break; }
    }
    if ($got) @file_put_contents($cf, json_encode(['p' => $got, 'by' => $by]), LOCK_EX);
    return [$got, $by];
}

/* --------------------------------------------- say it back in plain words */
function aib_say_operand($o) {
    if (isset($o['value'])) return rtrim(rtrim(number_format((float)$o['value'], 2, '.', ''), '0'), '.');
    $p = $o['period'] ?? null; $i = $o['ind'];
    $n = ['close' => 'Price (close)', 'open' => 'Open', 'high' => 'High', 'low' => 'Low', 'volume' => 'Volume', 'hl2' => '(High+Low)/2', 'hlc3' => 'Typical price', 'ohlc4' => 'Average price',
          'ema' => "EMA $p", 'sma' => "SMA $p", 'wma' => "WMA $p", 'rsi' => "RSI $p", 'atr' => "ATR $p", 'atr_pct' => "ATR% $p", 'stdev' => "Std dev $p",
          'bb_upper' => "Upper Bollinger band ($p)", 'bb_lower' => "Lower Bollinger band ($p)", 'bb_mid' => "Middle Bollinger band ($p)", 'bb_width' => "Bollinger width ($p)",
          'highest' => "the highest high of the last $p bars", 'lowest' => "the lowest low of the last $p bars",
          'macd' => 'MACD line', 'macd_signal' => 'MACD signal line', 'macd_hist' => 'MACD histogram', 'adx' => "ADX $p", 'plus_di' => "+DI $p", 'minus_di' => "-DI $p",
          'supertrend' => 'Supertrend line', 'supertrend_dir' => 'Supertrend direction', 'stoch_k' => "Stochastic %K ($p)", 'stoch_d' => "Stochastic %D ($p)", 'vwap' => "VWAP ($p bars)", 'roc' => "Rate of change $p"][$i] ?? $i;
    if (($o['src'] ?? '') === 'volume' && in_array($i, ['sma', 'ema', 'wma'], true)) $n = "Average volume ($p bars)";
    if (!empty($o['tf'])) $n .= ' on the ' . $o['tf'] . ' chart';
    return $n;
}
function aib_say_rule($r) {
    if (($r['left']['ind'] ?? '') === 'supertrend_dir' && isset($r['right']['value']) && (float)$r['right']['value'] == 0.0) {
        return ['crosses_above' => 'Supertrend turns bullish', 'crosses_below' => 'Supertrend turns bearish', '>' => 'Supertrend is bullish', '>=' => 'Supertrend is bullish', '<' => 'Supertrend is bearish', '<=' => 'Supertrend is bearish'][$r['op']];
    }
    $op = ['>' => 'is above', '<' => 'is below', '>=' => 'is at or above', '<=' => 'is at or below', 'crosses_above' => 'crosses above', 'crosses_below' => 'crosses below'][$r['op']];
    return aib_say_operand($r['left']) . ' ' . $op . ' ' . aib_say_operand($r['right']);
}
function aib_say_risk($k) {
    $o = []; $f = function ($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };
    if (isset($k['stop_loss_pct'])) $o[] = 'Stop-loss ' . $f($k['stop_loss_pct']) . '% from entry';
    if (isset($k['stop_loss_atr'])) $o[] = 'Stop-loss ' . $f($k['stop_loss_atr']) . ' x ATR from entry';
    if (isset($k['take_profit_pct'])) $o[] = 'Take profit ' . $f($k['take_profit_pct']) . '% from entry';
    if (isset($k['take_profit_atr'])) $o[] = 'Take profit ' . $f($k['take_profit_atr']) . ' x ATR from entry';
    if (isset($k['trail_pct'])) $o[] = 'Trailing stop ' . $f($k['trail_pct']) . '%';
    if (isset($k['trail_atr'])) $o[] = 'Trailing stop ' . $f($k['trail_atr']) . ' x ATR';
    if (isset($k['max_bars'])) $o[] = 'Exit after ' . (int)$k['max_bars'] . ' bars at the latest';
    return $o;
}
function aib_verdict($m, $n) {
    $ret = (float)($m['total_return_pct'] ?? 0); $bh = $m['buy_hold_return_pct'] ?? null; $dd = (float)($m['max_drawdown_pct'] ?? 0); $pf = $m['profit_factor'] ?? null;
    if ($n === 0) return ['grade' => 'none', 'title' => 'No trades were taken', 'text' => 'The rules never came true together in this period. Loosen a condition, or test a longer period.'];
    $beat = $bh !== null && $ret > (float)$bh;
    if ($n < 30) return ['grade' => 'thin', 'title' => 'Too few trades to judge', 'text' => "Only $n trades. The result (" . ($ret >= 0 ? '+' : '') . round($ret, 1) . '%) can easily be luck. Test a longer period, a smaller timeframe, or more markets before trusting it.'];
    if ($ret <= 0) return ['grade' => 'bad', 'title' => 'This strategy lost money in the test', 'text' => 'It ended ' . round($ret, 1) . "% over $n trades" . ($bh !== null ? ', while simply holding the market made ' . ((float)$bh >= 0 ? '+' : '') . round((float)$bh, 1) . '%' : '') . '. As written, the rules did not work on this market and period.'];
    if (!$beat) return ['grade' => 'weak', 'title' => 'Profitable, but holding the market did better', 'text' => 'It made +' . round($ret, 1) . "% over $n trades, but buy and hold made +" . round((float)$bh, 1) . '%. ' . ($dd > -25 && $bh !== null ? 'Its worst fall was ' . round($dd, 1) . '%, so it may still suit someone who wants a smoother ride.' : 'The extra effort did not pay here.')];
    return ['grade' => $pf !== null && $pf >= 1.3 && $dd > -35 ? 'good' : 'ok', 'title' => 'It beat buy and hold in this test',
            'text' => 'It made +' . round($ret, 1) . "% over $n trades against +" . round((float)$bh, 1) . '% for holding, with a worst fall of ' . round($dd, 1) . '%. This is one market and one period: check it on other markets and run a walk-forward before trusting it.'];
}

/* -------------------------------------------------------------------- run */
try {
    if (($_GET['a'] ?? '') === 'status') {   // which readers are set up, and the last provider error (no key is ever shown)
        $e = json_decode((string)@file_get_contents(bt_dir('ai') . '/last_error.json'), true);
        aib_out(['groq' => aib_key('groq') !== '', 'gemini' => aib_key('gemini') !== '', 'last_error' => is_array($e) ? $e : null]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') aib_fail('That method is not allowed', 405);
    $og = isset($_SERVER['HTTP_ORIGIN']) ? parse_url((string)$_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : null;
    if ($og !== null && $og !== ($_SERVER['HTTP_HOST'] ?? '')) aib_fail('Bad request', 403);
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 8000) aib_fail('The text is too long. Keep it under 1,500 characters.', 413);
    $q = json_decode($raw, true); if (!is_array($q)) aib_fail('The request could not be read', 400);
    $text = trim((string)($q['text'] ?? ''));
    if (mb_strlen($text) < 8) aib_fail('Describe your strategy in a sentence or two. Example: "Buy when RSI is below 30 and price is above the 200 EMA. Sell when RSI is above 70. Stop loss 5%."');
    if (mb_strlen($text) > 1500) aib_fail('The text is too long. Keep it under 1,500 characters.', 413);
    bt_rate('aibt', 20, 300);
    @set_time_limit(45); @ini_set('memory_limit', '256M'); $t0 = microtime(true);

    $engine = 'rules'; $P = null; $strategy = null;
    [$ai, $aiBy] = aib_parse_ai($text);
    if ($ai) {
        try { $strategy = bt_validate_strategy(aib_clean($ai['strategy'])); $P = $ai; $engine = $aiBy; }
        catch (Throwable $e) {
            $strategy = null;
            @file_put_contents(bt_dir('ai') . '/last_error.json', json_encode(['at' => gmdate('c'), 'host' => $aiBy, 'code' => 'invalid', 'msg' => mb_substr($e->getMessage(), 0, 200),
                'got' => mb_substr(json_encode($ai['strategy']), 0, 900)]), LOCK_EX);
        }
    }
    if (!$strategy) {
        $P = aib_parse_local($text);
        try { $strategy = bt_validate_strategy($P['strategy']); }
        catch (BtError $e) {
            aib_fail('I could not find a clear entry rule in that. Say when to buy in simple words, for example: "Buy when the 20 EMA crosses above the 50 EMA" or "Buy when RSI is below 30".',
                422, ['missed' => $P['missed'], 'engine' => 'rules']);
        }
    }
    // market, timeframe, period: the form wins, then the text, then a sensible default
    $S = aib_symbols();
    $sym = strtoupper(trim((string)($q['symbol'] ?? ''))); if ($sym === '' || $sym === 'AUTO') $sym = (string)($P['symbol'] ?? '');
    $symGuess = false; if ($sym === '') { $sym = 'SPY'; $symGuess = true; }
    $tf = strtolower(trim((string)($q['timeframe'] ?? ''))); if ($tf === '' || $tf === 'auto') $tf = (string)($P['timeframe'] ?? '');
    if (!isset(BT_BASE_TF[$tf])) $tf = '1d';
    $cap = ['1w' => 25, '1d' => 25, '4h' => 2, '1h' => 2, '30m' => 0.16, '15m' => 0.16, '5m' => 0.16][$tf];
    $def = ['1w' => 15, '1d' => 10, '4h' => 2, '1h' => 2, '30m' => 0.16, '15m' => 0.16, '5m' => 0.16][$tf];
    $yrs = is_numeric($q['years'] ?? null) && (float)$q['years'] > 0 ? (float)$q['years'] : ($P['years'] ?? null);
    $yrs = $yrs ? min($cap, max(0.08, (float)$yrs)) : $def;
    $end = time(); $start = max(strtotime('2000-01-02 UTC'), $end - (int)round($yrs * 365.25 * 86400));
    $req = ['symbol' => $sym, 'timeframe' => $tf, 'start' => gmdate('Y-m-d', $start), 'end' => gmdate('Y-m-d', $end), 'strategy' => $strategy,
            'capital' => is_numeric($q['capital'] ?? null) ? (float)$q['capital'] : 10000, 'commission' => 0.001, 'slippage' => 0.0005, 'size' => 1.0];
    bt_check_tfs($strategy, $tf);
    $res = bt_handle_backtest($req);

    $lines = [];
    foreach (['entry_long' => 'Buy (go long) when', 'exit_long' => 'Sell (close the long) when', 'entry_short' => 'Go short when', 'exit_short' => 'Close the short when'] as $k => $lab) {
        if ($strategy['rules'][$k]) $lines[] = ['k' => $k, 'label' => $lab, 'rules' => array_map('aib_say_rule', $strategy['rules'][$k])];
    }
    $n = (int)($res['trades_total'] ?? 0);
    $warn = [];
    if ($symGuess) $warn[] = 'You did not name a market, so this ran on SPY (S&P 500 ETF). Pick a market above to change it.';
    if (!$strategy['rules']['exit_long'] && $strategy['rules']['entry_long'] && !$strategy['risk']) $warn[] = 'You gave no exit and no stop-loss, so a trade only closes at the end of the data. Add "sell when ..." or a stop-loss.';
    if ($P['missed']) $warn[] = 'Some parts could not be turned into a rule and were left out: "' . implode('", "', $P['missed']) . '".';
    aib_out(['ok' => true, 'engine' => $engine,
        'understood' => ['symbol' => $sym, 'name' => $S['codes'][$sym] ?? $sym, 'timeframe' => $tf, 'from' => $res['from'] ?? $req['start'], 'to' => $res['to'] ?? $req['end'],
                         'lines' => $lines, 'risk' => aib_say_risk($strategy['risk']), 'costs' => 'Costs included: 0.1% commission and 0.05% slippage per side. Full capital on each trade.'],
        'missed' => $P['missed'], 'warnings' => $warn, 'strategy' => $strategy, 'request' => ['symbol' => $sym, 'timeframe' => $tf, 'start' => $req['start'], 'end' => $req['end']],
        'verdict' => aib_verdict($res['metrics'] ?? [], $n), 'result' => $res, 'took_ms' => (int)round((microtime(true) - $t0) * 1000)]);

} catch (BtLimit $e) {
    header('Retry-After: ' . (int)$e->retry); aib_fail($e->getMessage(), 429, ['retry_after' => (int)$e->retry]);
} catch (BtError $e) {
    aib_fail($e->getMessage(), 422);
} catch (BtData $e) {
    $m = $e->getMessage();
    if ($m === 'not-found' || $m === 'crypto-not-found') aib_fail('That market was not found. Pick one from the list.', 404);
    if ($m === 'crypto-blocked' || $m === 'crypto-error') aib_fail('Crypto data is unavailable right now. Please try again shortly.', 503);
    aib_fail($m, 503);
} catch (Throwable $e) {
    @error_log('[aibt] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    aib_fail('Sorry for the inconvenience - AI Backtesting is unavailable right now. Please try again shortly.', 503);
}
