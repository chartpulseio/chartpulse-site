<?php
declare(strict_types=1);
/**
 * ChartPulse: link a website account to a Telegram chat (4 Nov 2026).
 *
 * Flow: site "Connect Telegram" -> POST api.php?r=tg_link returns https://t.me/<bot>?start=<token>
 *       -> user taps Start in Telegram -> tg_webhook.php receives "/start <token>"
 *       -> tgl_consume() stores account => chat_id in data/tg_links.json.
 * Tokens are one-time and expire after 30 minutes.
 */

const TGL_TOKEN_TTL = 1800;

function tgl_path(string $name): string { return __DIR__ . '/data/' . $name; }

function tgl_read(string $f): array {
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function tgl_write(string $f, array $d): void {
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Create a one-time link token for a website account. */
function tgl_create(string $acc): string {
    $f = tgl_path('tg_link_tokens.json');
    $now = time();
    $tokens = array_filter(tgl_read($f), fn($v) => ($v['created'] ?? 0) > $now - TGL_TOKEN_TTL);
    $tok = bin2hex(random_bytes(12));
    $tokens[$tok] = ['acc' => $acc, 'created' => $now];
    tgl_write($f, $tokens);
    return $tok;
}

/** Consume a token sent by Telegram. Returns the account id, or null if invalid or expired. */
function tgl_consume(string $tok, string $chat_id): ?string {
    $f = tgl_path('tg_link_tokens.json');
    $tokens = tgl_read($f);
    $entry = $tokens[$tok] ?? null;
    if (!$entry || ($entry['created'] ?? 0) <= time() - TGL_TOKEN_TTL) return null;
    unset($tokens[$tok]);
    tgl_write($f, $tokens);
    $acc = (string)$entry['acc'];
    $links = tgl_read(tgl_path('tg_links.json'));
    $links[$acc] = $chat_id;
    tgl_write(tgl_path('tg_links.json'), $links);
    return $acc;
}

/** Telegram chat id linked to an account, or '' when not linked. */
function tgl_chat(string $acc): string {
    $links = tgl_read(tgl_path('tg_links.json'));
    return isset($links[$acc]) ? (string)$links[$acc] : '';
}
