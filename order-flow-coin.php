<?php
/** Per-coin Order Flow pages for search (owner, 15 Nov 2026): /scanners/order-flow/<coin>/ serves order-flow.html
 *  with that coin's title, description, canonical and heading. The page script reads the coin from the path. */
declare(strict_types=1);
const OF_SEO_COINS = ['btc'=>'Bitcoin','eth'=>'Ethereum','sol'=>'Solana','xrp'=>'XRP','bnb'=>'BNB','doge'=>'Dogecoin','hype'=>'Hyperliquid','sui'=>'Sui','ada'=>'Cardano','link'=>'Chainlink','avax'=>'Avalanche','ltc'=>'Litecoin','dot'=>'Polkadot','trx'=>'TRON','ton'=>'Toncoin','pepe'=>'Pepe','shib'=>'Shiba Inu','bch'=>'Bitcoin Cash','near'=>'NEAR','apt'=>'Aptos','arb'=>'Arbitrum','op'=>'Optimism','uni'=>'Uniswap','aave'=>'Aave','ena'=>'Ethena','wif'=>'dogwifhat','tia'=>'Celestia','inj'=>'Injective','fil'=>'Filecoin','etc'=>'Ethereum Classic','wld'=>'Worldcoin','bonk'=>'Bonk','trump'=>'TRUMP','ondo'=>'Ondo','fartcoin'=>'Fartcoin','xlm'=>'Stellar','hbar'=>'Hedera','atom'=>'Cosmos','pengu'=>'Pudgy Penguins','virtual'=>'Virtuals Protocol'];
$c = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)($_GET['c'] ?? '')));
if (!isset(OF_SEO_COINS[$c])) { header('Location: /scanners/order-flow/?s=' . strtoupper($c), true, 302); exit; }
$C = strtoupper($c); $N = OF_SEO_COINS[$c]; $nm = $N === $C ? $C : "$N ($C)";
$h = (string)file_get_contents(__DIR__ . '/order-flow.html');
$t = "$C Order Flow: Live Liquidations, CVD, Open Interest &amp; Heatmap · ChartPulse";
$d = "Free $nm order flow: live liquidations from Binance, Bybit and OKX, CVD and buy vs sell delta, open interest, long/short ratios, order book walls and an estimated liquidation heatmap. No sign-up.";
$u = "https://chartpulse.io/scanners/order-flow/$c/";
$h = preg_replace('~<title>.*?</title>~s', "<title>$t</title>", $h, 1);
$h = preg_replace('~<meta name="description" content="[^"]*">~', '<meta name="description" content="' . $d . '">', $h, 1);
$h = preg_replace('~<meta property="og:title" content="[^"]*"><meta property="og:description" content="[^"]*">~', '<meta property="og:title" content="' . "$C Order Flow · ChartPulse" . '"><meta property="og:description" content="' . $d . '">', $h, 1);
$h = str_replace('<link rel="canonical" href="https://chartpulse.io/scanners/order-flow/">', '<link rel="canonical" href="' . $u . '">', $h);
$h = str_replace('<h1>Crypto <span>Order Flow</span></h1>', "<h1>$C <span>Order Flow</span></h1>", $h);
$h = str_replace('"name":"Crypto Order Flow","item":"https://chartpulse.io/scanners/order-flow/"}]', '"name":"Crypto Order Flow","item":"https://chartpulse.io/scanners/order-flow/"},{"@type":"ListItem","position":4,"name":"' . $C . '","item":"' . $u . '"}]', $h);
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: public, max-age=300');
echo $h;
