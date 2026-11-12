<?php
/* Live AI committee (owner, 5 Oct 2026): 4 Gemini analysts in parallel + 1 judge, built on the AI Scan data.
   Ready in about 20-40 seconds. One shared report per symbol per hour.
   Key: the owner pastes the Gemini API key alone into data/ml/gemini.ini (web access to .ini is denied in .htaccess). */
const LC_TTL = 3600, LC_DAY_CAP = 150, LC_ANALYST = 'gemini-3.5-flash-lite', LC_JUDGE = 'gemini-3.5-flash';

function lc_key(): string {
    $f = ML_DIR . '/gemini.ini'; $k = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    $k = trim(preg_replace('/^[A-Za-z_ ]*=\s*/', '', $k), " \t\r\n\"'");
    return preg_match('/^[A-Za-z0-9_.\-]{20,}$/', $k) ? $k : '';
}
/** Groq key (owner, 13 Nov 2026): data/ml/groq.ini, same format. Used when Gemini does not answer (or has no key). */
function lc_gkey(): string {
    $f = ML_DIR . '/groq.ini'; $k = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    $k = trim(preg_replace('/^[A-Za-z_ ]*=\s*/', '', $k), " \t\r\n\"'");
    return preg_match('/^[A-Za-z0-9_.\-]{20,}$/', $k) ? $k : '';
}
function lc_on(): bool { return lc_key() !== '' || lc_gkey() !== ''; }
function lc_file(string $sym): string { $d = ML_DIR . '/live'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym) . '.json'; }
function lc_get(string $sym): ?array { $r = t_read_json(lc_file($sym), null); return is_array($r) ? $r : null; }

/** Compact context for the analysts: only facts the desk already computed. */
function lc_context(array $d): string {
    $p = $d['params']; $m = $d['memory']; $c = $d['cone']; $last = $c ? end($c) : null;
    $L = ["Instrument: {$d['name']} ({$d['symbol']}), market {$d['market']}, daily chart, last close {$p['price']}.",
        "Trend: {$p['trend']}. EMA5 {$p['ema5']}, EMA20 {$p['ema20']}, EMA50 {$p['ema50']}. RSI {$p['rsi']}. ATR {$p['atr']} ({$p['atr_pct']}% of price, percentile {$p['atr_pctile']}). Annualised volatility {$p['vol_ann']}%.",
        "20-day high {$p['hi20']}, low {$p['lo20']}, position in range {$p['range_pos']}%. Last candle: {$p['candle']}." . ($p['vol_ratio'] !== null ? " Volume vs average {$p['vol_ratio']}x." : ''),
        "Rule-based bias: {$d['bias']['label']} (score {$d['bias']['score']}, conviction {$d['bias']['conviction']}/100, size tier {$d['bias']['tier']})." . ($d['bias']['vetoes'] ? ' Vetoes: ' . implode('; ', $d['bias']['vetoes']) . '.' : ''),
        'Factor scores: ' . implode(', ', array_map(fn($f) => "{$f['name']} {$f['value']}", $d['factors'])) . '.'];
    if ($m['n']) $L[] = "Similar past setups: {$m['n']}, price higher {$m['h']} bars later {$m['up']}% of the time, average move {$m['avg_move']}%.";
    if ($last) $L[] = ($d['modules']['TimesFM'] === 'live' ? 'TimesFM AI' : 'Statistical') . " forecast ({$last['h']} days): median {$last['p50']}, 80% range {$last['p10']} to {$last['p90']}.";
    if ($d['news']['n']) { $L[] = "News: {$d['news']['n']} recent headlines, average tone {$d['news']['tone']} (-1 to +1). Headlines:";
        foreach (array_slice($d['news']['top'] ?? [], 0, 8) as $h) $L[] = '- ' . (is_array($h) ? (string)($h['t'] ?? $h['title'] ?? json_encode($h)) : (string)$h); }
    else $L[] = 'News: no recent headlines.';
    if ($d['plan']) $L[] = "Rule plan: {$d['plan']['direction']} at {$d['plan']['entry']}, stop {$d['plan']['stop']}, targets {$d['plan']['t1']} / {$d['plan']['t2']}.";
    return implode("\n", $L);
}

function lc_req(string $model, string $sys, string $user, bool $json): array {
    $b = ['systemInstruction' => ['parts' => [['text' => $sys]]], 'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => $json ? 4096 : 2048] + ($json ? ['responseMimeType' => 'application/json'] : [])];
    return ['url' => "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent", 'body' => json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'sys' => $sys, 'user' => $user, 'json' => $json];
}
/** Run several calls at once: Gemini first, then Groq for any that came back empty. Returns the text of each (or ''). */
function lc_multi(array $reqs, int $timeout = 40): array {
    $out = lc_key() !== '' ? lc_multi_gemini($reqs, $timeout) : array_fill_keys(array_keys($reqs), '');
    $miss = array_filter($reqs, fn($k) => ($out[$k] ?? '') === '', ARRAY_FILTER_USE_KEY);
    if ($miss && lc_gkey() !== '') foreach (lc_multi_groq($miss, $timeout) as $k => $v) if ($v !== '') $out[$k] = $v;
    return $out;
}
/** Groq (OpenAI-style API). Each call in a batch goes to a different model, because Groq's free limits are per model. */
function lc_multi_groq(array $reqs, int $timeout): array {
    $models = ['openai/gpt-oss-120b', 'llama-3.3-70b-versatile', 'openai/gpt-oss-20b', 'llama-3.1-8b-instant'];
    $mh = curl_multi_init(); $H = []; $key = lc_gkey(); $i = 0;
    foreach ($reqs as $k => $q) {
        $m = $models[$i++ % count($models)];
        $b = ['model' => $m, 'temperature' => 0.3, 'max_tokens' => $q['json'] ? 3000 : 1500,
              'messages' => [['role' => 'system', 'content' => $q['sys']], ['role' => 'user', 'content' => $q['user']]]];
        if ($q['json']) $b['response_format'] = ['type' => 'json_object'];
        if (str_starts_with($m, 'openai/gpt-oss')) $b['reasoning_effort'] = 'low';
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key]]);
        curl_multi_add_handle($mh, $ch); $H[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($H as $k => $ch) { $j = json_decode((string)curl_multi_getcontent($ch), true);
        if (!empty($j['error'])) $GLOBALS['lc_err'] = 'groq ' . (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . mb_substr((string)($j['error']['message'] ?? ''), 0, 200);
        $out[$k] = trim((string)($j['choices'][0]['message']['content'] ?? ''));
        curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh); return $out;
}
function lc_multi_gemini(array $reqs, int $timeout = 40): array {
    $mh = curl_multi_init(); $H = []; $key = lc_key();
    foreach ($reqs as $k => $q) { $ch = curl_init($q['url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $q['body'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key]]);
        curl_multi_add_handle($mh, $ch); $H[$k] = $ch; }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($H as $k => $ch) { $j = json_decode((string)curl_multi_getcontent($ch), true);
        if (!empty($j['error'])) $GLOBALS['lc_err'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . mb_substr((string)($j['error']['message'] ?? ''), 0, 200);
        $out[$k] = trim(implode('', array_map(fn($p) => (string)($p['text'] ?? ''), $j['candidates'][0]['content']['parts'] ?? [])));
        curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh); return $out;
}

/** Build (or return the cached) live report. Status: done | running | off | limit | error. */
function lc_run(string $sym): array {
    $now = t_now(); $old = lc_get($sym);
    if ($old && ($old['status'] ?? '') === 'done' && $now - (int)$old['at'] < LC_TTL) return $old;
    if (!lc_on()) return ['status' => 'off'];
    if ($old && ($old['status'] ?? '') === 'running' && $now - (int)$old['at'] < 120) return $old;
    $cp = ML_DIR . '/live/_count.json'; lc_file($sym); $C = t_read_json($cp, []); $day = gmdate('Y-m-d');
    if ((int)($C[$day] ?? 0) >= LC_DAY_CAP) return ($old && ($old['status'] ?? '') === 'done') ? $old + ['stale' => 1] : ['status' => 'limit'];
    t_write_json($cp, [$day => (int)($C[$day] ?? 0) + 1]);
    t_write_json(lc_file($sym), ['status' => 'running', 'at' => $now]);
    @set_time_limit(120); ignore_user_abort(true);
    require_once __DIR__ . '/torus_desk.php';
    $d = td_cached($sym, '1D');
    if (isset($d['error'])) { t_write_json(lc_file($sym), ['status' => 'error', 'at' => $now]); return ['status' => 'error', 'error' => $d['error']]; }
    $ctx = lc_context($d);
    $base = 'You are part of a professional trading research desk. Use ONLY the data given; never invent prices, events or numbers. Plain English, no hype, no guarantees. Max 120 words, 3-5 short bullet points starting with "- ".';
    $roles = ['market' => 'You are the technical/market analyst. Read trend, momentum, volatility and levels.',
        'news' => 'You are the news analyst. Judge what the headlines and their tone mean for the next 1-2 weeks. If there is no news, say so briefly.',
        'bull' => 'You are the bull researcher. Make the strongest honest case for going long, and name the level that would prove you wrong.',
        'bear' => 'You are the bear researcher. Make the strongest honest case for going short or staying out, and name the level that would prove you wrong.'];
    $R = []; foreach ($roles as $k => $s) $R[$k] = lc_req(LC_ANALYST, "$s $base", $ctx, false);
    $A = lc_multi($R, 35);
    if (count(array_filter($A)) < 2) { $R = []; foreach ($roles as $k => $s) $R[$k] = lc_req('gemini-3.1-flash-lite', "$s $base", $ctx, false); $A = lc_multi($R, 35); }   // free-tier quota fallback
    if (count(array_filter($A)) < 2) { t_write_json(lc_file($sym), ['status' => 'error', 'at' => $now]); return ['status' => 'error', 'error' => 'The AI service did not answer. Please try again in a minute.', 'detail' => $GLOBALS['lc_err'] ?? null]; }
    $jsys = 'You are the risk manager who makes the final call for a trading desk. Weigh the analysts and the data; respect the rule vetoes. Use ONLY the data given. '
        . 'Reply as JSON: {"decision":"BUY"|"HOLD"|"SELL","confidence":"low"|"medium"|"high","summary":"2-3 sentences","risks":["..."],"invalidation":"price level and why"}';
    $ju = $ctx . "\n\nAnalyst notes:\n" . implode("\n", array_map(fn($k) => strtoupper($k) . ":\n" . ($A[$k] ?: '(no answer)'), array_keys($A)));
    $jt = (string)(lc_multi(['j' => lc_req(LC_JUDGE, $jsys, $ju, true)], 45)['j'] ?? '');
    $jt2 = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($jt)); if (($p0 = strpos($jt2, '{')) !== false) $jt2 = substr($jt2, $p0, strrpos($jt2, '}') - $p0 + 1);
    $J = json_decode($jt2, true);
    foreach (['gemini-3.1-flash-lite', LC_ANALYST] as $fb) {   // judge quota (20/day on the free tier) used up: fall back to a lite model
        if (is_array($J) && !empty($J['decision'])) break;
        $jt = (string)(lc_multi(['j' => lc_req($fb, $jsys, $ju, true)], 45)['j'] ?? '');
        $jt2 = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($jt)); if (($p0 = strpos($jt2, '{')) !== false) $jt2 = substr($jt2, $p0, strrpos($jt2, '}') - $p0 + 1);
        $J = json_decode($jt2, true);
    }
    if (!is_array($J) && empty($GLOBALS['lc_err'])) $GLOBALS['lc_err'] = 'judge text: ' . mb_substr($jt, 0, 150);
    $dec = strtoupper((string)($J['decision'] ?? ''));
    if (!in_array($dec, ['BUY', 'HOLD', 'SELL'], true)) { t_write_json(lc_file($sym), ['status' => 'error', 'at' => $now]); return ['status' => 'error', 'error' => 'The AI judge did not answer. Please try again in a minute.', 'detail' => $GLOBALS['lc_err'] ?? null]; }
    $cut = fn($s, $n) => mb_substr(trim((string)$s), 0, $n);
    $r = ['status' => 'done', 'at' => t_now(), 'price' => $d['params']['price'], 'decision' => $dec, 'confidence' => in_array($J['confidence'] ?? '', ['low', 'medium', 'high'], true) ? $J['confidence'] : 'low',
        'summary' => $cut($J['summary'] ?? '', 600), 'risks' => array_map(fn($x) => $cut($x, 200), array_slice((array)($J['risks'] ?? []), 0, 4)), 'invalidation' => $cut($J['invalidation'] ?? '', 250),
        'analysts' => array_map(fn($x) => $cut($x, 1500), $A)];
    t_write_json(lc_file($sym), $r); return $r;
}
