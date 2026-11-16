<?php
/** Binance liquidations relay (owner, 16 Nov 2026). Binance futures refuses the VPS region, so the VPS collector asks
 *  this host (which Binance accepts) to listen to the public !forceOrder@arr stream for up to 50 s and return the events.
 *  Called from api.php?r=liqbn with the collector's key. Minimal websocket client: TLS socket, text/ping/close frames. */
declare(strict_types=1);
function lbn_listen(int $secs = 50): array {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $s = @stream_socket_client('ssl://fstream.binance.com:443', $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) return ['error' => "connect: $es"];
    $key = base64_encode(random_bytes(16));
    fwrite($s, "GET /market/ws/!forceOrder@arr HTTP/1.1\r\nHost: fstream.binance.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\nUser-Agent: chartpulse-liq\r\n\r\n");
    $hdr = ''; while (!feof($s) && !str_contains($hdr, "\r\n\r\n")) { $c = fread($s, 1024); if ($c === false || $c === '') break; $hdr .= $c; }
    if (!preg_match('~^HTTP/1\.1 101~', $hdr)) { fclose($s); return ['error' => 'handshake: ' . strtok($hdr, "\r\n")]; }
    $buf = substr($hdr, strpos($hdr, "\r\n\r\n") + 4); $ev = []; $end = microtime(true) + $secs;
    stream_set_blocking($s, false);
    $send = function (int $op, string $p) use ($s) { $m = random_bytes(4); $len = strlen($p); $h = chr(0x80 | $op) . ($len < 126 ? chr(0x80 | $len) : chr(0x80 | 126) . pack('n', $len)) . $m; $x = ''; for ($i = 0; $i < $len; $i++) $x .= $p[$i] ^ $m[$i % 4]; @fwrite($s, $h . $x); };
    while (microtime(true) < $end) {
        $r = [$s]; $w = $e = null; if (@stream_select($r, $w, $e, 1) > 0) { $c = fread($s, 65536); if ($c === false || ($c === '' && feof($s))) break; $buf .= $c; }
        while (strlen($buf) >= 2) {
            $op = ord($buf[0]) & 15; $len = ord($buf[1]) & 127; $o = 2;
            if ($len === 126) { if (strlen($buf) < 4) break; $len = unpack('n', substr($buf, 2, 2))[1]; $o = 4; }
            elseif ($len === 127) { if (strlen($buf) < 10) break; $len = (int)unpack('J', substr($buf, 2, 8))[1]; $o = 10; }
            if (strlen($buf) < $o + $len) break;
            $p = substr($buf, $o, $len); $buf = substr($buf, $o + $len);
            if ($op === 9) $send(10, $p);
            elseif ($op === 8) { fclose($s); return ['ev' => $ev, 'closed' => true]; }
            elseif ($op === 1) { $j = json_decode($p, true); $q = $j['o'] ?? null;
                if (is_array($q) && str_ends_with((string)($q['s'] ?? ''), 'USDT')) { $px = (float)($q['ap'] ?? 0) ?: (float)($q['p'] ?? 0); $qty = (float)($q['z'] ?? 0) ?: (float)($q['q'] ?? 0);
                    $ev[] = [(int)(($q['T'] ?? 0) / 1000), $q['s'], ($q['S'] ?? '') === 'SELL' ? 'long' : 'short', $px, round($px * $qty, 2)]; } }
        }
    }
    @fclose($s); return ['ev' => $ev];
}
