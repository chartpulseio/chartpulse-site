<?php
// ---------------------------------------------------------------------------
// TORUS ML bridge (owner, 4 Oct 2026): the AI Scan's real models, run outside the shared PHP host.
//   TimesFM 2.5 + FinBERT : the owner's Hugging Face Space (FastAPI, free CPU)  -> config 'ml_space_url', 'ml_space_key'
//   Qdrant                : Qdrant Cloud free cluster (REST)                     -> config 'qdrant_url', 'qdrant_key'
// Every call has a short timeout and a cache; when a service is off or slow, the AI Scan uses its built-in
// statistical stand-ins and labels that module "fallback". Keys stay in config.php and never leave the server.
// ---------------------------------------------------------------------------
declare(strict_types=1);

const ML_DIR = T_DATA . '/ml';
const QD_COLL = 'cp_setups_v1';   // feature schema v1: 8 per-symbol standardised features (see td_prep)

function ml_space_on(): bool { return trim((string)t_cfg('ml_space_url', '')) !== '' && trim((string)t_cfg('ml_space_key', '')) !== ''; }
/** Push mode (owner, 4 Oct 2026): a scheduled GitHub Actions job runs TimesFM / FinBERT / TradingAgents and pushes results here (config 'ml_push_key'). */
function ml_push_on(): bool { return strlen(trim((string)t_cfg('ml_push_key', ''))) >= 20 || is_file(ML_DIR . '/push_state.json'); }   // key in config, or the GitHub job has pushed once
function ml_on(): bool { return ml_space_on() || ml_push_on(); }
function qd_on(): bool { return trim((string)t_cfg('qdrant_url', '')) !== '' && trim((string)t_cfg('qdrant_key', '')) !== ''; }
function ml_dir(): string { if (!is_dir(ML_DIR)) @mkdir(ML_DIR, 0755, true); return ML_DIR; }

/** JSON request. Returns [httpCode, decoded|null]. */
function ml_http(string $method, string $url, ?array $body, array $hdr, int $timeout): array {
    $ch = curl_init($url);
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $hdr);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, is_string($r) ? json_decode($r, true) : null];
}
function ml_space(string $path, ?array $body, int $timeout = 8): array {
    if (!ml_space_on()) return [0, null];
    $u = rtrim((string)t_cfg('ml_space_url', ''), '/') . $path;
    return ml_http($body === null ? 'GET' : 'POST', $u, $body, ['X-CP-Key: ' . (string)t_cfg('ml_space_key', '')], $timeout);
}
function ml_health(): array {
    if (!ml_on()) return ['configured' => false];
    [$c, $j] = ml_space('/health', null, 10);
    return ['configured' => true, 'http' => $c, 'timesfm' => (bool)($j['timesfm'] ?? false), 'finbert' => (bool)($j['finbert'] ?? false), 'errors' => $j['errors'] ?? null];
}

/** TimesFM 2.5 quantile path for the last $closes (oldest first). Cached per symbol / timeframe / last bar. null on failure. */
function ml_forecast(string $sym, string $tf, array $closes, int $lastTs, int $h = 10): ?array {
    if (!ml_on() || count($closes) < 64) return null;
    $p = ml_dir() . '/fc_' . preg_replace('/[^A-Za-z0-9._-]/', '_', "$sym-$tf") . '.json';
    $c = t_read_json($p, null);
    if (is_array($c) && ($c['last'] ?? 0) === $lastTs && !empty($c['q'])) return $c['q'];
    if (is_array($c) && !empty($c['q']) && !empty($c['push']) && t_now() - (int)($c['at'] ?? 0) < 36 * 3600) return $c['q'];   // pushed by the GitHub job
    if (!ml_space_on()) return null;
    if (is_array($c) && t_now() - (int)($c['fail'] ?? 0) < 300) return null;   // back off 5 min after a failure
    [$code, $j] = ml_space('/forecast', ['series' => [array_map('floatval', array_slice($closes, -1024))], 'horizon' => $h], 12);
    $q = $code === 200 ? ($j['forecasts'][0] ?? null) : null;
    if (!$q) { t_write_json($p, ['fail' => t_now(), 'code' => $code]); return null; }
    t_write_json($p, ['last' => $lastTs, 'q' => $q, 'at' => t_now()]);
    return $q;
}

/** FinBERT scores (positive - negative, -1..1) for headlines; cached per headline. Returns [text => score] (missing on failure). */
function ml_sentiment(array $texts): array {
    if (!ml_on() || !$texts) return [];
    $p = ml_dir() . '/finbert.json'; $C = t_read_json($p, []); $out = []; $need = [];
    foreach ($texts as $t) { $k = md5($t); if (isset($C[$k])) $out[$t] = (float)$C[$k][0]; else $need[$k] = $t; }
    if ($need && ml_space_on() && t_now() - (int)($C['_fail'][0] ?? 0) > 300) {
        [$code, $j] = ml_space('/sentiment', ['texts' => array_values($need)], 15);
        if ($code === 200 && isset($j['scores']) && count($j['scores']) === count($need)) {
            $i = 0; foreach ($need as $k => $t) { $s = (float)$j['scores'][$i++]['score']; $C[$k] = [$s, t_now()]; $out[$t] = $s; }
        } else $C['_fail'] = [t_now(), 0];
        if (count($C) > 4000) { uasort($C, fn($a, $b) => $b[1] <=> $a[1]); $C = array_slice($C, 0, 3000, true); }
        t_write_json($p, $C);
    }
    return $out;
}

/* ---------------- Qdrant setup memory ---------------- */
function qd(string $method, string $path, ?array $body, int $timeout = 8): array {
    return ml_http($method, rtrim((string)t_cfg('qdrant_url', ''), '/') . $path, $body, ['api-key: ' . (string)t_cfg('qdrant_key', '')], $timeout);
}
function qd_ensure(): bool {
    [$c] = qd('GET', '/collections/' . QD_COLL, null);
    if ($c === 200) return true;
    [$c] = qd('PUT', '/collections/' . QD_COLL, ['vectors' => ['size' => 8, 'distance' => 'Euclid']]);
    if ($c !== 200) return false;
    foreach (['sym' => 'keyword', 'mkt' => 'keyword', 'tf' => 'keyword', 'ts' => 'integer'] as $f => $t) qd('PUT', '/collections/' . QD_COLL . '/index', ['field_name' => $f, 'field_schema' => $t]);
    return true;
}
/** Per-symbol standardised feature vectors (mean / sd over the symbol's own history), so symbols are comparable. */
function qd_vectors(array $I): array {
    $F = array_filter($I['F']); if (count($F) < 60) return [];
    $d = 8; $mu = array_fill(0, $d, 0.0); $sd = array_fill(0, $d, 0.0); $n = count($F);
    foreach ($F as $f) for ($k = 0; $k < $d; $k++) $mu[$k] += $f[$k] / $n;
    foreach ($F as $f) for ($k = 0; $k < $d; $k++) $sd[$k] += ($f[$k] - $mu[$k]) ** 2 / $n;
    for ($k = 0; $k < $d; $k++) $sd[$k] = sqrt($sd[$k]) ?: 1.0;
    $V = []; foreach ($I['F'] as $i => $f) if ($f) { $v = []; for ($k = 0; $k < $d; $k++) $v[] = round(($f[$k] - $mu[$k]) / $sd[$k], 4); $V[$i] = $v; }
    return $V;
}
/** Upsert one symbol's history (bars whose 10-bar outcome is known). Deterministic ids, so re-runs are idempotent. */
function qd_index_symbol(string $sym, string $tf = '1D'): int {
    $fr = t_read_frames($sym); if (!$fr) return 0;
    $B = $tf === '1H' ? $fr['1H'] : $fr['1D']; if (count($B['c']) < 130) return 0;
    if (count($B['c']) > 1300) foreach ($B as $k => $v) $B[$k] = array_values(array_slice($v, -1300));
    $I = td_prep($B); $V = qd_vectors($I); $n = count($B['c']); $mkt = t_symbols()[$sym] ?? 'Other'; $pts = [];
    foreach ($V as $i => $v) {
        if ($i + 2 * TD_H >= $n) continue;
        $a = max($I['A'][$i], 1e-12); $mv = ($B['c'][$i + TD_H] - $B['c'][$i]) / $a; $hi = 0.0; $lo = 0.0; $res = 0; $rs = 0;
        for ($q = $i + 1; $q <= $i + 2 * TD_H; $q++) {
            $f = ($B['h'][$q] - $B['c'][$i]) / $a; $b = ($B['c'][$i] - $B['l'][$q]) / $a; $hi = max($hi, $f); $lo = max($lo, $b);
            if ($res === 0) { if ($b >= 1.0) $res = -1; elseif ($f >= 1.5) $res = 1; }    // long: +1.5 ATR before -1 ATR
            if ($rs === 0) { if ($f >= 1.0) $rs = -1; elseif ($b >= 1.5) $rs = 1; }      // short: -1.5 ATR before +1 ATR
        }
        $id = hexdec(substr(md5("$sym|$tf|" . $B['t'][$i]), 0, 13));
        $pts[] = ['id' => $id, 'vector' => $v, 'payload' => ['sym' => $sym, 'mkt' => $mkt, 'tf' => $tf, 'ts' => (int)$B['t'][$i], 'mv' => round($mv, 3), 'mfe' => round($hi, 3), 'mae' => round($lo, 3), 'tb' => $res, 'ts2' => $rs]];
    }
    $ok = 0;
    foreach (array_chunk($pts, 400) as $ch) { [$c] = qd('PUT', '/collections/' . QD_COLL . '/points?wait=false', ['points' => $ch], 20); if ($c === 200) $ok += count($ch); }
    return $ok;
}
/** Cron step: index a few symbols per run (whole universe in ~1 day), refresh each symbol weekly. */
function qd_backfill_step(int $max = 6, int $budget = 25): string {
    if (!qd_on()) return '';
    $st = t_read_json(ml_dir() . '/qd_state.json', ['done' => []]);
    if (empty($st['coll']) && !qd_ensure()) return 'qdrant: collection error';
    $st['coll'] = 1; $t0 = time(); $n = 0; $pts = 0;
    foreach (array_keys(t_symbols()) as $s) {
        if (time() - (int)($st['done'][$s] ?? 0) < 7 * 86400) continue;
        if ($n >= $max || time() - $t0 > $budget) break;
        $pts += qd_index_symbol($s); $st['done'][$s] = time(); $n++;
    }
    t_write_json(ml_dir() . '/qd_state.json', $st);
    return $n ? "qdrant +$n symbols, $pts points" : '';
}
/** Nearest past setups across the same market (no look-ahead: only bars older than the outcome window). */
function qd_memory(string $sym, array $I, int $i, int $dir, int $lastTs, string $tf = '1D', int $K = 50): ?array {
    if (!qd_on()) return null;
    $V = qd_vectors($I); if (!isset($V[$i])) return null;
    $mkt = t_symbols()[$sym] ?? 'Other';
    $cut = $lastTs - ($tf === '1H' ? 3600 : 86400) * 3 * TD_H;
    [$c, $j] = qd('POST', '/collections/' . QD_COLL . '/points/search', ['vector' => $V[$i], 'limit' => $K, 'with_payload' => true,
        'filter' => ['must' => [['key' => 'mkt', 'match' => ['value' => $mkt]], ['key' => 'tf', 'match' => ['value' => $tf]], ['key' => 'ts', 'range' => ['lt' => $cut]]]]], 6);
    $R = $c === 200 ? ($j['result'] ?? []) : null;
    if ($R === null || count($R) < 20) return null;
    $sg = $dir ?: 1; $mv = []; $mfe = []; $mae = []; $win = 0; $hist = [0, 0, 0, 0, 0, 0]; $syms = [];
    foreach ($R as $r) {
        $p = $r['payload']; $x = (float)$p['mv']; $mv[] = $x; $syms[$p['sym']] = 1;
        $hist[$x <= -2 ? 0 : ($x <= -1 ? 1 : ($x <= 0 ? 2 : ($x <= 1 ? 3 : ($x <= 2 ? 4 : 5))))]++;
        $mfe[] = $sg > 0 ? (float)$p['mfe'] : (float)$p['mae']; $mae[] = $sg > 0 ? (float)$p['mae'] : (float)$p['mfe'];
        if ((int)($sg > 0 ? $p['tb'] : ($p['ts2'] ?? 0)) === 1) $win++;
    }
    $k = count($R);
    return ['n' => $k, 'up' => count(array_filter($mv, fn($x) => $x > 0)) / $k, 'avg_move' => array_sum($mv) / $k, 'avg_mfe' => array_sum($mfe) / $k,
        'avg_mae' => array_sum($mae) / $k, 'win' => $win / $k, 'avg_run' => 0.0, 'hist' => $hist, 'pool' => count($syms), 'scope' => "$mkt market (Qdrant)"];
}
/** Hourly ping keeps the free Space awake (it sleeps after 48 h without traffic). */
function ml_keepalive(): string {
    if (!ml_space_on()) return '';
    $p = ml_dir() . '/ping.json'; $s = t_read_json($p, ['at' => 0]);
    if (time() - (int)$s['at'] < 3600) return '';
    $h = ml_health(); t_write_json($p, ['at' => time(), 'h' => $h]);
    return 'ml ' . (($h['timesfm'] ?? false) ? 'tfm' : '-') . '/' . (($h['finbert'] ?? false) ? 'fin' : '-');
}

/* ---------------- TradingAgents analyst committee (on the Space, free Gemini key) ---------------- */
const TA_DAILY_AUTO = 6;   // top live Tier-1 signals analysed per day by cron
const TA_DAILY_USER = 10;   // extra on-demand runs per day from the AI Scan button
function ta_dir(): string { $d = ml_dir() . '/agents'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function ta_file(string $sym): string { return ta_dir() . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $sym) . '.json'; }
/** Latest finished committee report for a symbol (max 3 days old), or the pending state. */
function ta_get(string $sym): ?array {
    $r = t_read_json(ta_file($sym), null);
    if (!is_array($r)) return null;
    if (($r['status'] ?? '') === 'done' && t_now() - (int)($r['at'] ?? 0) > 3 * 86400) return null;
    return $r;
}
/** Queue a run (returns status text). $user = on-demand from the page (separate daily quota). */
function ta_queue(string $sym, bool $user): string {
    if (!ml_on()) return 'AI committee is not connected yet';
    $cur = ta_get($sym);
    if ($cur && in_array($cur['status'] ?? '', ['queued', 'running'], true)) return 'already running';
    if ($cur && ($cur['status'] ?? '') === 'done' && ($cur['date'] ?? '') === gmdate('Y-m-d')) return 'already analysed today';
    $q = t_read_json(ta_dir() . '/_quota.json', []); $day = gmdate('Y-m-d'); $k = $user ? 'user' : 'auto';
    if (($q['day'] ?? '') !== $day) $q = ['day' => $day, 'user' => 0, 'auto' => 0];
    if ($q[$k] >= ($user ? TA_DAILY_USER : TA_DAILY_AUTO)) return 'daily limit reached';
    if (ml_space_on()) {
        [$c, $j] = ml_space('/agents/run', ['symbol' => $sym, 'date' => $day], 10);
        if ($c !== 200 || empty($j['id'])) return 'could not start (' . $c . ')';
    } else $j = ['id' => 'push'];   // the hourly GitHub job picks it up
    $q[$k]++; t_write_json(ta_dir() . '/_quota.json', $q);
    t_write_json(ta_file($sym), ['status' => 'queued', 'job' => $j['id'], 'date' => $day, 'at' => t_now(), 'by' => $k]);
    return 'queued';
}
/** Cron: poll running jobs, then queue today's strongest live signals. */
function ta_step(): string {
    if (!ml_on()) return '';
    $done = 0;
    foreach (glob(ta_dir() . '/*.json') ?: [] as $p) {
        if (basename($p)[0] === '_') continue;
        $r = t_read_json($p, null); if (!is_array($r) || !in_array($r['status'] ?? '', ['queued', 'running'], true)) continue;
        if (($r['job'] ?? '') === 'push') { if (t_now() - (int)$r['at'] > 30 * 3600) { $r['status'] = 'error'; $r['error'] = 'not picked up'; t_write_json($p, $r); } continue; }
        [$c, $j] = ml_space('/agents/' . rawurlencode((string)$r['job']), null, 8);
        if ($c === 404 || t_now() - (int)$r['at'] > 3 * 3600) { $r['status'] = 'error'; $r['error'] = 'job lost (the Space restarted)'; t_write_json($p, $r); continue; }
        if ($c !== 200) continue;
        $r['status'] = $j['status'];
        if ($j['status'] === 'done') { $r['result'] = $j['result']; $r['at'] = t_now(); $done++; }
        if ($j['status'] === 'error') $r['error'] = $j['error'] ?? 'error';
        t_write_json($p, $r);
    }
    $rows = t_view_rows(t_scan_load(), t_now());
    $sig = array_values(array_filter($rows, fn($r) => ($r['tier1'] ?? '') === 'signal'));
    usort($sig, fn($a, $b) => $b['strength'] <=> $a['strength']); $q = 0;
    foreach (array_slice($sig, 0, TA_DAILY_AUTO) as $r) if (ta_queue($r['symbol'], false) === 'queued') $q++;
    return ($done || $q) ? "agents +$done done, $q queued" : '';
}

/* ---------------- push mode endpoints (used by api.php r=mlfeed / r=mlpush) ---------------- */
const ML_GH_REPO = 'chartpulseio/chartpulse-ml';   // only this repository's GitHub Actions may push
function ml_push_auth(): bool {
    $k = trim((string)t_cfg('ml_push_key', ''));
    if (strlen($k) >= 20 && hash_equals($k, (string)($_SERVER['HTTP_X_CP_KEY'] ?? ''))) return true;
    $a = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_GH_OIDC'] ?? '');
    $jwt = preg_replace('/^Bearer\s+/i', '', trim($a));
    return $jwt !== '' && ml_gh_oidc_ok($jwt);
}
function ml_b64u(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
/** DER-encoded RSA public key (PEM) from JWK n / e. */
function ml_jwk_pem(string $n, string $e): string {
    $len = function (int $l): string { if ($l < 128) return chr($l); $b = ltrim(pack('N', $l), "\0"); return chr(0x80 | strlen($b)) . $b; };
    $int = function (string $x) use ($len): string { if (ord($x[0]) > 127) $x = "\0" . $x; return "\x02" . $len(strlen($x)) . $x; };
    $seq = function (string $x) use ($len): string { return "\x30" . $len(strlen($x)) . $x; };
    $rsa = $seq($int(ml_b64u($n)) . $int(ml_b64u($e)));
    $bit = "\x03" . $len(strlen($rsa) + 1) . "\0" . $rsa;
    $der = $seq($seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00") . $bit);
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
/** Verify a GitHub Actions OIDC token: RS256 signature (GitHub JWKS), issuer, audience, expiry and repository. */
function ml_gh_oidc_ok(string $jwt): bool {
    $p = explode('.', $jwt); if (count($p) !== 3) return false;
    $h = json_decode(ml_b64u($p[0]), true); $c = json_decode(ml_b64u($p[1]), true);
    if (!is_array($h) || !is_array($c) || ($h['alg'] ?? '') !== 'RS256') return false;
    if (($c['iss'] ?? '') !== 'https://token.actions.githubusercontent.com' || ($c['aud'] ?? '') !== 'chartpulse.io') return false;
    if (($c['repository'] ?? '') !== ML_GH_REPO || (int)($c['exp'] ?? 0) < time() || (int)($c['nbf'] ?? 0) > time() + 60) return false;
    $jp = ml_dir() . '/gh_jwks.json'; $J = t_read_json($jp, null);
    $find = function ($J) use ($h) { foreach (($J['keys'] ?? []) as $k) if (($k['kid'] ?? '') === ($h['kid'] ?? '')) return $k; return null; };
    $k = is_array($J) ? $find($J) : null;
    if (!$k || time() - (int)($J['_at'] ?? 0) > 86400) {
        [$code, $N] = ml_http('GET', 'https://token.actions.githubusercontent.com/.well-known/jwks', null, [], 8);
        if ($code === 200 && is_array($N)) { $N['_at'] = time(); t_write_json($jp, $N); $k = $find($N); }
    }
    if (!$k || empty($k['n']) || empty($k['e'])) return false;
    return openssl_verify($p[0] . '.' . $p[1], ml_b64u($p[2]), ml_jwk_pem($k['n'], $k['e']), OPENSSL_ALGO_SHA256) === 1;
}
/** Work list for the GitHub job: symbols (Yahoo tickers), headlines without a FinBERT score, queued committee runs. */
function ml_feed(): array {
    $now = t_now(); $C = t_read_json(ml_dir() . '/finbert.json', []); $nc = t_read_json(T_DATA . '/news.json', ['items' => []]); $H = [];
    foreach (($nc['items'] ?? []) as $x) { if ($now - (int)($x['ts'] ?? 0) > 259200 || empty($x['tags'])) continue; $t = (string)$x['t']; if (!isset($C[md5($t)])) $H[md5($t)] = $t; }
    $Q = [];
    foreach (glob(ta_dir() . '/*.json') ?: [] as $p) { if (basename($p)[0] === '_') continue; $r = t_read_json($p, null);
        if (is_array($r) && ($r['status'] ?? '') === 'queued' && ($r['job'] ?? '') === 'push') $Q[] = ['symbol' => substr(basename($p), 0, -5), 'date' => $r['date']]; }
    foreach ($Q as &$q) foreach (array_keys(t_symbols()) as $s) if (preg_replace('/[^A-Za-z0-9._-]/', '_', $s) === $q['symbol']) { $q['symbol'] = $s; break; }
    unset($q);
    $st = t_read_json(ml_dir() . '/push_state.json', ['fc_at' => 0]);
    $P = [];   // symbols users opened on the AI Scan whose TimesFM forecast is older than 6h: done on the next 15-min run
    foreach (t_read_json(ml_dir() . '/req.json', []) as $s => $ts) {
        if ($now - (int)$ts > 86400 || !isset(t_symbols()[$s]) || str_starts_with($s, 'MCX:')) continue;
        $f = t_read_json(ml_dir() . '/fc_' . preg_replace('/[^A-Za-z0-9._-]/', '_', "$s-1D") . '.json', null);
        if (!is_array($f) || $now - (int)($f['at'] ?? 0) > 6 * 3600) $P[] = $s;
    }
    return ['symbols' => array_values(array_filter(array_keys(t_symbols()), fn($s) => !str_starts_with($s, 'MCX:'))), 'headlines' => array_values(array_slice($H, 0, 300)),
        'agents' => array_slice($Q, 0, 8), 'need_forecast' => $now - (int)$st['fc_at'] > 11 * 3600, 'priority' => array_slice($P, 0, 120), 'horizon' => 10];
}
/** Remember which symbols users open on the AI Scan (feeds the 15-min priority forecast list). */
function ml_note_request(string $sym): void {
    $p = ml_dir() . '/req.json'; $R = t_read_json($p, []); $now = t_now();
    if ($now - (int)($R[$sym] ?? 0) < 600) return;
    $R[$sym] = $now; arsort($R); t_write_json($p, array_slice($R, 0, 400, true));
}
/** Store pushed results in the same caches the AI Scan already reads. */
function ml_store_push(array $in): array {
    $n = ['fc' => 0, 'sent' => 0, 'agents' => 0]; $now = t_now();
    foreach (($in['forecasts'] ?? []) as $sym => $q) {
        if (!isset(t_symbols()[$sym]) || !is_array($q) || count($q) < 3) continue;
        $q = array_map(fn($x) => ['h' => (int)$x['h'], 'p10' => (float)$x['p10'], 'p25' => (float)$x['p25'], 'p50' => (float)$x['p50'], 'p75' => (float)$x['p75'], 'p90' => (float)$x['p90']], array_slice($q, 0, 16));
        t_write_json(ml_dir() . '/fc_' . preg_replace('/[^A-Za-z0-9._-]/', '_', "$sym-1D") . '.json', ['q' => $q, 'at' => $now, 'push' => 1]); $n['fc']++;
    }
    if ($n['fc'] > 50 && !empty($in['full'])) t_write_json(ml_dir() . '/push_state.json', ['fc_at' => $now]);
    if (!empty($in['sentiment']) && is_array($in['sentiment'])) {
        $p = ml_dir() . '/finbert.json'; $C = t_read_json($p, []);
        foreach ($in['sentiment'] as $text => $sc) { $C[md5((string)$text)] = [max(-1, min(1, (float)$sc)), $now]; $n['sent']++; }
        t_write_json($p, $C);
    }
    foreach (($in['agents'] ?? []) as $sym => $r) {
        if (!isset(t_symbols()[$sym]) || !is_array($r)) continue;
        $keep = []; foreach (['decision', 'market', 'sentiment', 'news', 'fundamentals', 'plan', 'trader', 'final'] as $k) $keep[$k] = mb_substr((string)($r[$k] ?? ''), 0, 6000);
        $ok = trim($keep['decision']) !== '';
        t_write_json(ta_file($sym), $ok ? ['status' => 'done', 'date' => (string)($r['date'] ?? gmdate('Y-m-d')), 'at' => $now, 'result' => $keep, 'job' => 'push']
            : ['status' => 'error', 'error' => 'run failed', 'detail' => mb_substr((string)($r['error'] ?? ''), -1500), 'at' => $now, 'job' => 'push']); $n['agents']++;
    }
    return $n;
}
