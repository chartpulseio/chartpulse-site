<?php
// ---------------------------------------------------------------------------
// ChartPulse USDT checkout (owner, 6 Oct 2026). Buyer sends USDT from Trust Wallet (or any wallet)
// to the owner's address; the server finds the transfer on-chain by its unique amount and unlocks the plan.
//   Networks: TRC20 (Tron), BEP20 (BNB Chain), Polygon, ERC20 (Ethereum).
//   No keys, no third-party processor: TronGrid for Tron, public JSON-RPC eth_getLogs for the EVM chains.
// Each order gets a unique amount (plan price + a few hundredths of a cent-range offset) so a transfer
// maps to exactly one order. A tx hash can only ever unlock one order.
// Optional config.php overrides: 'usdt' => ['enabled' => true, 'tron' => 'T…', 'evm' => '0x…', 'rpc' => ['bsc' => [...]]]
// ---------------------------------------------------------------------------
declare(strict_types=1);

const TU_TTL = 3600;          // order shown as payable for 60 min
const TU_LATE = 86400;        // a late transfer is still matched for 24 h (amount stays reserved)
const TU_NETS = [
    'trc20' => ['label' => 'TRC20 · Tron', 'fee' => '~1 USDT', 'dec' => 6, 'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'tw' => 'c195_tTR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'],
    'bep20' => ['label' => 'BEP20 · BNB Chain', 'fee' => '< $0.10', 'dec' => 18, 'contract' => '0x55d398326f99059ff775485246999027b3197955', 'tw' => 'c20000714_t0x55d398326f99059fF775485246999027B3197955', 'conf' => 6, 'chunk' => 3000, 'span' => 9000],
    'polygon' => ['label' => 'Polygon', 'fee' => '< $0.05', 'dec' => 6, 'contract' => '0xc2132d05d31c914a87c6611c10748aeb04b58e8f', 'tw' => 'c966_t0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'conf' => 20, 'chunk' => 2000, 'span' => 6000],
    'erc20' => ['label' => 'ERC20 · Ethereum', 'fee' => '$1–5, can be higher', 'dec' => 6, 'contract' => '0xdac17f958d2ee523a2206206994597c13d831ec7', 'tw' => 'c60_t0xdAC17F958D2ee523a2206206994597C13D831ec7', 'conf' => 3, 'chunk' => 1000, 'span' => 1500],
];
const TU_RPC = [
    'bep20' => ['https://bsc-rpc.publicnode.com', 'https://bsc-dataseed.bnbchain.org', 'https://bsc-dataseed1.defibit.io'],
    'polygon' => ['https://polygon-bor-rpc.publicnode.com', 'https://polygon-rpc.com'],
    'erc20' => ['https://ethereum-rpc.publicnode.com', 'https://eth.llamarpc.com', 'https://rpc.ankr.com/eth'],
];
const TU_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';   // Transfer(address,address,uint256)

function tu_cfg(): array {
    $c = (array)t_cfg('usdt', []);
    return ['enabled' => (bool)($c['enabled'] ?? true),
        'tron' => (string)($c['tron'] ?? 'TMwkttg75kuY2jnoK3hyqr1CAwrbceFpUc'),
        'evm' => strtolower((string)($c['evm'] ?? '0xcCbB942775a198028eC2F8371cC8f7092E3a03e0')),
        'rpc' => (array)($c['rpc'] ?? [])];
}
function tu_on(): bool { $c = tu_cfg(); return $c['enabled'] && $c['tron'] !== '' && $c['evm'] !== ''; }
function tu_addr(string $net): string { $c = tu_cfg(); return $net === 'trc20' ? $c['tron'] : $c['evm']; }
function tu_path(): string { return T_DATA . '/usdt_orders.json'; }

function tu_http(string $url, ?array $post = null): ?array {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'ChartPulse/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($post); }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$r, true);
    return $code === 200 && is_array($j) ? $j : null;
}
/** JSON-RPC with fallback across public endpoints. */
function tu_rpc(string $net, string $method, array $params) {
    $list = array_merge((array)(tu_cfg()['rpc'][$net] ?? []), TU_RPC[$net] ?? []);
    foreach ($list as $u) {
        $j = tu_http($u, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        if ($j && array_key_exists('result', $j) && !isset($j['error'])) return $j['result'];
    }
    return null;
}
/** Token units (hex or decimal string) -> amount string with 4 decimals, exact (no floats). */
function tu_units4(string $v, int $dec): string {
    if (str_starts_with($v, '0x')) { $h = ltrim(substr($v, 2), '0'); $d = '0'; foreach (str_split($h === '' ? '0' : $h) as $c) $d = tu_addmul($d, 16, hexdec($c)); $v = $d; }
    $v = ltrim($v, '0'); if ($v === '') $v = '0';
    $v = str_pad($v, $dec + 1, '0', STR_PAD_LEFT);
    $int = substr($v, 0, -$dec); $fr = substr($v, -$dec);
    return ltrim($int, '0') === '' ? '0.' . substr($fr, 0, 4) : ltrim($int, '0') . '.' . substr($fr, 0, 4);
}
function tu_addmul(string $d, int $m, int $add): string {   // decimal string * m + add
    $out = ''; $carry = $add;
    for ($i = strlen($d) - 1; $i >= 0; $i--) { $x = (int)$d[$i] * $m + $carry; $out = ($x % 10) . $out; $carry = intdiv($x, 10); }
    while ($carry > 0) { $out = ($carry % 10) . $out; $carry = intdiv($carry, 10); }
    return ltrim($out, '0') ?: '0';
}

/** Locked read-modify-write of the order store. */
function tu_store(callable $fn) {
    t_ensure_dirs(); $fh = fopen(T_DATA . '/.usdt_lock', 'c'); if ($fh) flock($fh, LOCK_EX);
    try { $S = t_read_json(tu_path(), ['orders' => [], 'used' => []]); $r = $fn($S); t_write_json(tu_path(), $S); return $r; }
    finally { if ($fh) { flock($fh, LOCK_UN); fclose($fh); } }
}
function tu_prune(array &$S): void {
    $cut = time() - 30 * 86400;
    foreach ($S['orders'] as $id => $o) if (($o['at'] ?? 0) < $cut) unset($S['orders'][$id]);
    foreach ($S['used'] as $tx => $at) if ($at < $cut - 60 * 86400) unset($S['used'][$tx]);
}

/** New order: unique amount on that network among orders still matchable. */
function tu_create(string $sku, string $period, string $net, int $usd, array $who): array {
    $start = null;
    if ($net !== 'trc20') { $bn = tu_rpc($net, 'eth_blockNumber', []); if (!is_string($bn)) return ['error' => 'That network is busy right now. Please pick another network or try again.']; $start = max(0, hexdec($bn) - 30); }
    return tu_store(function (array &$S) use ($sku, $period, $net, $usd, $who, $start) {
        tu_prune($S);
        $taken = [];
        foreach ($S['orders'] as $o) if ($o['net'] === $net && $o['status'] === 'pending' && $o['at'] > time() - TU_LATE) $taken[$o['amount']] = 1;
        for ($k = 0; $k < 400; $k++) { $amt = sprintf('%d.%04d', $usd, random_int(11, 2999)); if (!isset($taken[$amt])) break; }
        $id = 'u' . bin2hex(random_bytes(8));
        $S['orders'][$id] = ['id' => $id, 'sku' => $sku, 'period' => $period, 'usd' => $usd, 'amount' => $amt, 'net' => $net, 'addr' => tu_addr($net),
            'name' => $who[0], 'email' => $who[1], 'phone' => $who[2], 'at' => time(), 'block' => $start, 'scan' => $start, 'status' => 'pending', 'tx' => null];
        return $S['orders'][$id];
    });
}

/** Incoming USDT transfers to our address on a network: [[tx, amount4, ts|block, confirmed], ...] */
function tu_tron_in(int $sinceMs): ?array {
    $u = 'https://api.trongrid.io/v1/accounts/' . tu_addr('trc20') . '/transactions/trc20?only_to=true&only_confirmed=true&limit=200&contract_address='
        . TU_NETS['trc20']['contract'] . '&min_timestamp=' . $sinceMs;
    $j = tu_http($u); if (!$j || !isset($j['data'])) return null;
    $o = [];
    foreach ($j['data'] as $t) {
        if (($t['token_info']['address'] ?? '') !== TU_NETS['trc20']['contract'] || ($t['to'] ?? '') !== tu_addr('trc20')) continue;
        $o[] = [(string)$t['transaction_id'], tu_units4((string)$t['value'], 6)];
    }
    return $o;
}
function tu_evm_in(string $net, int $from, int $to): ?array {
    $n = TU_NETS[$net]; $o = [];
    for ($a = $from; $a <= $to; $a += $n['chunk']) {
        $b = min($to, $a + $n['chunk'] - 1);
        $logs = tu_rpc($net, 'eth_getLogs', [['fromBlock' => '0x' . dechex($a), 'toBlock' => '0x' . dechex($b), 'address' => $n['contract'],
            'topics' => [TU_TOPIC, null, '0x' . str_pad(substr(tu_addr($net), 2), 64, '0', STR_PAD_LEFT)]]]);
        if (!is_array($logs)) return null;
        foreach ($logs as $l) if (strtolower((string)$l['address']) === $n['contract'] && empty($l['removed'])) $o[] = [strtolower((string)$l['transactionHash']), tu_units4((string)$l['data'], $n['dec'])];
    }
    return $o;
}

/** Checks one pending order on-chain. Returns the order (status 'paid' with 'tx' when matched). */
function tu_check(string $id): ?array {
    $S = t_read_json(tu_path(), ['orders' => [], 'used' => []]); $o = $S['orders'][$id] ?? null;
    if (!$o || $o['status'] !== 'pending' || $o['at'] < time() - TU_LATE) return $o;
    $net = $o['net']; $hits = null; $scanTo = null;
    if ($net === 'trc20') $hits = tu_tron_in(($o['at'] - 300) * 1000);
    else {
        $bn = tu_rpc($net, 'eth_blockNumber', []); if (!is_string($bn)) return $o;
        $scanTo = hexdec($bn) - TU_NETS[$net]['conf'];
        $from = max((int)$o['scan'] - 5, $scanTo - TU_NETS[$net]['span']);   // never scan more than ~span blocks per poll
        if ($scanTo >= $from) $hits = tu_evm_in($net, $from, $scanTo);
    }
    if ($hits === null) { if ($scanTo === null) return $o; $hits = []; }
    return tu_store(function (array &$S) use ($id, $hits, $scanTo) {
        $o = &$S['orders'][$id];
        if ($o['status'] !== 'pending') return $o;
        foreach ($hits as [$tx, $amt]) {
            if ($amt !== $o['amount'] || isset($S['used'][$tx])) continue;
            $S['used'][$tx] = time(); $o['status'] = 'paid'; $o['tx'] = $tx; $o['paid_at'] = time(); break;
        }
        if ($scanTo !== null && $o['status'] === 'pending') $o['scan'] = $scanTo;
        return $o;
    });
}

/** Buyer gives a tx hash: confirm it on-chain and match it to their order (same email, same amount). */
function tu_tx_lookup(string $net, string $tx): ?array {   // -> [amount4] of the USDT sent to us in that tx, or null
    if ($net === 'trc20') {
        $j = tu_http('https://api.trongrid.io/v1/transactions/' . $tx . '/events?only_confirmed=true');
        foreach ((array)($j['data'] ?? []) as $e) {
            if (($e['event_name'] ?? '') !== 'Transfer' || ($e['contract_address'] ?? '') !== TU_NETS['trc20']['contract']) continue;
            $to = (string)($e['result']['to'] ?? ''); if ($to !== '' && $to !== tu_addr('trc20') && tu_tron_hex($to) !== tu_tron_hex(tu_addr('trc20'))) continue;
            return [tu_units4((string)($e['result']['value'] ?? '0'), 6)];
        }
        return null;
    }
    $r = tu_rpc($net, 'eth_getTransactionReceipt', [$tx]); if (!is_array($r) || ($r['status'] ?? '') !== '0x1') return null;
    $bn = tu_rpc($net, 'eth_blockNumber', []); if (!is_string($bn) || hexdec($bn) - hexdec((string)$r['blockNumber']) < TU_NETS[$net]['conf']) return null;
    $me = '0x' . str_pad(substr(tu_addr($net), 2), 64, '0', STR_PAD_LEFT);
    foreach ((array)$r['logs'] as $l) {
        if (strtolower((string)$l['address']) !== TU_NETS[$net]['contract'] || strtolower((string)($l['topics'][0] ?? '')) !== TU_TOPIC || strtolower((string)($l['topics'][2] ?? '')) !== $me) continue;
        return [tu_units4((string)$l['data'], TU_NETS[$net]['dec'])];
    }
    return null;
}
/** Tron base58 or hex address -> lowercase 20-byte hex (events may return either form). */
function tu_tron_hex(string $a): string {
    if (preg_match('/^(0x|41)?([0-9a-fA-F]{40})$/', $a, $m)) return strtolower($m[2]);
    $al = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'; $n = '0';
    foreach (str_split($a) as $c) { $p = strpos($al, $c); if ($p === false) return ''; $n = tu_addmul($n, 58, $p); }
    $h = ''; while ($n !== '0') { [$n, $r] = tu_divmod($n, 16); $h = dechex($r) . $h; }
    $h = str_pad($h, 50, '0', STR_PAD_LEFT);   // 21-byte address + 4-byte checksum
    return strtolower(substr($h, 2, 40));
}
function tu_divmod(string $d, int $m): array { $q = ''; $r = 0; foreach (str_split($d) as $c) { $x = $r * 10 + (int)$c; $q .= intdiv($x, $m); $r = $x % $m; } return [ltrim($q, '0') ?: '0', $r]; }

function tu_recover(string $net, string $tx, string $email): array {
    $hit = tu_tx_lookup($net, $tx);
    if (!$hit) return ['error' => 'We could not find a confirmed USDT payment to our address in that transaction on ' . TU_NETS[$net]['label'] . '. Check the network and the hash, or wait a minute and try again.'];
    [$amt] = $hit;
    return tu_store(function (array &$S) use ($net, $tx, $email, $amt) {
        if (isset($S['used'][$tx])) {   // already settled: return that order so the buyer sees the same code
            foreach ($S['orders'] as $o) if ($o['tx'] === $tx) return hash_equals(strtolower($o['email']), $email) ? $o : ['error' => 'This transaction is already linked to another order.'];
            return ['error' => 'This transaction was already used.'];
        }
        foreach ($S['orders'] as $id => $o) {
            if ($o['net'] !== $net || $o['status'] !== 'pending' || $o['amount'] !== $amt || !hash_equals(strtolower($o['email']), $email) || $o['at'] < time() - 7 * 86400) continue;
            $S['used'][$tx] = time(); $S['orders'][$id]['status'] = 'paid'; $S['orders'][$id]['tx'] = $tx; $S['orders'][$id]['paid_at'] = time(); $S['orders'][$id]['via'] = 'recover';
            return $S['orders'][$id];
        }
        return ['error' => 'We found the payment (' . $amt . ' USDT) but no open order with that exact amount and email. Contact support with the transaction hash.', 'unmatched' => $amt];
    });
}

/** Public view of an order for the page (no other buyers' data). */
function tu_view(array $o): array {
    $n = TU_NETS[$o['net']];
    return ['order' => $o['id'], 'net' => $o['net'], 'net_label' => $n['label'], 'fee' => $n['fee'], 'amount' => $o['amount'], 'address' => $o['addr'],
        'status' => $o['status'], 'expires' => $o['at'] + TU_TTL, 'now' => time(), 'tx' => $o['tx'],
        'tw' => 'https://link.trustwallet.com/send?asset=' . $n['tw'] . '&address=' . $o['addr'] . '&amount=' . $o['amount']];
}
