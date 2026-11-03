<?php
/**
 * ChartPulse Telegram Webhook  (3 Nov 2026)
 * URL: https://chartpulse.io/tg_webhook.php
 *
 * Register with:
 *   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://chartpulse.io/tg_webhook.php
 *
 * Commands:
 *   /start  — subscribe to ChartPulse signal alerts
 *   /stop   — unsubscribe
 *   /status — check subscription status
 */
declare(strict_types=1);
require __DIR__ . '/torus_upstox.php';   // loads t_cfg()
require __DIR__ . '/torus_tg_link.php';  // website account <-> chat linking

// ── Security: only Telegram's IPs should call this ───────────────────────────
// (Optional hard gate — comment out if it causes issues on Hostinger)
// $allowed = ['149.154.', '91.108.'];
// $ip = $_SERVER['REMOTE_ADDR'] ?? '';
// if (!array_filter($allowed, fn($p) => str_starts_with($ip, $p))) { http_response_code(403); exit; }

$tok = trim((string)t_cfg('telegram_bot_token', ''));
if ($tok === '') { http_response_code(500); exit; }

$body = file_get_contents('php://input');
$upd  = json_decode($body, true);
if (!$upd || !isset($upd['message'])) { http_response_code(200); echo 'ok'; exit; }

$msg     = $upd['message'];
$chat_id = (string)($msg['chat']['id'] ?? '');
$text    = trim($msg['text'] ?? '');
$fname   = $msg['from']['first_name'] ?? 'Trader';

if ($chat_id === '') { http_response_code(200); echo 'ok'; exit; }

// ── Subscriber store ──────────────────────────────────────────────────────────
$sub_file = __DIR__ . '/data/tg_subscribers.json';

function tg_load_subs(string $f): array {
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function tg_save_subs(string $f, array $subs): void {
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($f, json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function tg_send(string $tok, string $chat, string $html): void {
    $ch = curl_init('https://api.telegram.org/bot' . $tok . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'                  => $chat,
            'text'                     => $html,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$subs = tg_load_subs($sub_file);
$cmd  = strtolower(strtok($text, ' @'));   // /start, /stop, /status (strip bot mention)

// ── Website link: "/start <token>" from the Connect Telegram button ──────────
if ($cmd === '/start' && preg_match('/^\/start\s+([a-f0-9]{24})$/i', $text, $m)) {
    $acc = tgl_consume(strtolower($m[1]), $chat_id);
    if ($acc !== null) {
        $subs[$chat_id] = ['name' => $fname, 'chat_id' => $chat_id, 'joined' => date('Y-m-d H:i:s')];
        tg_save_subs($sub_file, $subs);
        tg_send($tok, $chat_id,
            "✅ <b>Connected to ChartPulse, $fname!</b>\n\n" .
            "You'll get a message here when a <b>FLY</b> signal fires.\n\n" .
            "Use /stop to unsubscribe anytime.\n" .
            "<i>Not investment advice.</i>"
        );
    } else {
        tg_send($tok, $chat_id,
            "⚠️ This connect link has expired or was already used.\n\n" .
            "Go back to chartpulse.io and press <b>Connect Telegram</b> again."
        );
    }
    http_response_code(200);
    echo 'ok';
    exit;
}

// ── Handle commands ───────────────────────────────────────────────────────────
if ($cmd === '/start') {
    if (isset($subs[$chat_id])) {
        tg_send($tok, $chat_id,
            "✅ <b>Already subscribed!</b>\n\n" .
            "Hi $fname, you're already getting ChartPulse signal alerts.\n\n" .
            "Use /stop to unsubscribe anytime."
        );
    } else {
        $subs[$chat_id] = [
            'name'    => $fname,
            'chat_id' => $chat_id,
            'joined'  => date('Y-m-d H:i:s'),
        ];
        tg_save_subs($sub_file, $subs);
        tg_send($tok, $chat_id,
            "🚀 <b>Welcome to ChartPulse, $fname!</b>\n\n" .
            "You're now subscribed to live signal alerts.\n\n" .
            "You'll get notified the moment a signal fires:\n" .
            "• 🟡 GATE OPEN — setup ready, entry awaited\n" .
            "• 🟢 TICK 1/2/3 — entry ticks confirmed\n" .
            "• ✈️ FLY — full confirmation, strongest signal\n\n" .
            "📊 <a href=\"https://chartpulse.io/scanner.html\">Open Scanner</a>\n\n" .
            "Use /stop to unsubscribe anytime.\n" .
            "<i>Not investment advice.</i>"
        );
    }

} elseif ($cmd === '/stop') {
    if (isset($subs[$chat_id])) {
        unset($subs[$chat_id]);
        tg_save_subs($sub_file, $subs);
        tg_send($tok, $chat_id,
            "👋 <b>Unsubscribed.</b>\n\n" .
            "You won't receive signal alerts anymore.\n\n" .
            "Send /start anytime to re-subscribe."
        );
    } else {
        tg_send($tok, $chat_id,
            "You're not subscribed yet.\n\nSend /start to subscribe to signal alerts."
        );
    }

} elseif ($cmd === '/status') {
    $count = count($subs);
    if (isset($subs[$chat_id])) {
        tg_send($tok, $chat_id,
            "✅ <b>Subscribed</b> since {$subs[$chat_id]['joined']}.\n\n" .
            "Total subscribers: <b>$count</b>\n\n" .
            "Use /stop to unsubscribe."
        );
    } else {
        tg_send($tok, $chat_id,
            "❌ <b>Not subscribed.</b>\n\nSend /start to subscribe to signal alerts."
        );
    }

} else {
    // Unknown command or plain text
    tg_send($tok, $chat_id,
        "👋 Hi $fname! I'm the <b>ChartPulse</b> signal bot.\n\n" .
        "Commands:\n" .
        "/start — subscribe to signal alerts\n" .
        "/stop  — unsubscribe\n" .
        "/status — check your subscription\n\n" .
        "📊 <a href=\"https://chartpulse.io/scanner.html\">Open Scanner</a>"
    );
}

http_response_code(200);
echo 'ok';
