<?php
/* ChartPulse Pro Backtesting - what the builder UI offers: markets, indicators, presets.
 * Server-only (blocked by .htaccess). Only standard public indicators live here -
 * none of the Tier 1 / Tier 2 logic is exposed.
 */

if (!defined('BT_META')) { define('BT_META', 1);

function bt_markets()
{
    return [
        ['US stocks', [
            ['AAPL', 'Apple'], ['MSFT', 'Microsoft'], ['NVDA', 'NVIDIA'], ['AMZN', 'Amazon'],
            ['GOOGL', 'Alphabet'], ['META', 'Meta'], ['TSLA', 'Tesla'], ['AMD', 'AMD'],
            ['NFLX', 'Netflix'], ['JPM', 'JPMorgan'], ['V', 'Visa'], ['WMT', 'Walmart'],
            ['XOM', 'Exxon Mobil'], ['KO', 'Coca-Cola'], ['DIS', 'Disney'], ['BA', 'Boeing'],
            ['INTC', 'Intel'], ['PLTR', 'Palantir'], ['COIN', 'Coinbase'], ['MSTR', 'MicroStrategy'],
            ['SPY', 'S&P 500 ETF'], ['QQQ', 'Nasdaq 100 ETF'], ['IWM', 'Russell 2000 ETF'],
            ['TQQQ', 'Nasdaq 100 3x ETF'], ['SMH', 'Semiconductor ETF'],
        ]],
        ['Indices', [
            ['^GSPC', 'S&P 500'], ['^NDX', 'Nasdaq 100'], ['^DJI', 'Dow Jones'],
            ['^RUT', 'Russell 2000'], ['^VIX', 'VIX'], ['^FTSE', 'FTSE 100'],
            ['^GDAXI', 'DAX'], ['^FCHI', 'CAC 40'], ['^N225', 'Nikkei 225'],
            ['^HSI', 'Hang Seng'], ['^NSEI', 'Nifty 50'], ['^BSESN', 'Sensex'],
            ['^NSEBANK', 'Bank Nifty'], ['^AXJO', 'ASX 200'], ['^STOXX50E', 'Euro Stoxx 50'],
        ]],
        ['Forex', [
            ['EURUSD=X', 'EUR/USD'], ['GBPUSD=X', 'GBP/USD'], ['USDJPY=X', 'USD/JPY'],
            ['AUDUSD=X', 'AUD/USD'], ['USDCAD=X', 'USD/CAD'], ['USDCHF=X', 'USD/CHF'],
            ['NZDUSD=X', 'NZD/USD'], ['EURGBP=X', 'EUR/GBP'], ['EURJPY=X', 'EUR/JPY'],
            ['GBPJPY=X', 'GBP/JPY'], ['AUDJPY=X', 'AUD/JPY'], ['EURAUD=X', 'EUR/AUD'],
            ['USDMXN=X', 'USD/MXN'], ['USDZAR=X', 'USD/ZAR'], ['DX-Y.NYB', 'Dollar Index'],
        ]],
        ['Crypto', [
            ['BTC-USD', 'Bitcoin'], ['ETH-USD', 'Ethereum'], ['SOL-USD', 'Solana'],
            ['BNB-USD', 'BNB'], ['XRP-USD', 'XRP'], ['ADA-USD', 'Cardano'],
            ['DOGE-USD', 'Dogecoin'], ['AVAX-USD', 'Avalanche'], ['LINK-USD', 'Chainlink'],
            ['DOT-USD', 'Polkadot'], ['MATIC-USD', 'Polygon'], ['LTC-USD', 'Litecoin'],
            ['TRX-USD', 'TRON'], ['ATOM-USD', 'Cosmos'], ['NEAR-USD', 'NEAR'],
            ['APT-USD', 'Aptos'], ['ARB-USD', 'Arbitrum'], ['OP-USD', 'Optimism'],
            ['SUI-USD', 'Sui'], ['INJ-USD', 'Injective'], ['PEPE-USD', 'Pepe'],
            ['SHIB-USD', 'Shiba Inu'], ['TON-USD', 'Toncoin'], ['FIL-USD', 'Filecoin'],
            ['ETC-USD', 'Ethereum Classic'], ['AAVE-USD', 'Aave'], ['UNI-USD', 'Uniswap'],
        ]],
        ['Commodities', [
            ['GC=F', 'Gold (COMEX)'], ['SI=F', 'Silver'], ['CL=F', 'Crude Oil WTI'],
            ['BZ=F', 'Brent Crude'], ['NG=F', 'Natural Gas'], ['HG=F', 'Copper'],
            ['PL=F', 'Platinum'], ['ZC=F', 'Corn'], ['ZW=F', 'Wheat'],
            ['PAXG-USD', 'PAX Gold (spot proxy)'],
        ]],
        ['India', [
            ['RELIANCE.NS', 'Reliance'], ['TCS.NS', 'TCS'], ['HDFCBANK.NS', 'HDFC Bank'],
            ['INFY.NS', 'Infosys'], ['ICICIBANK.NS', 'ICICI Bank'], ['SBIN.NS', 'SBI'],
            ['BHARTIARTL.NS', 'Bharti Airtel'], ['ITC.NS', 'ITC'], ['LT.NS', 'Larsen & Toubro'],
            ['TATAMOTORS.NS', 'Tata Motors'], ['AXISBANK.NS', 'Axis Bank'], ['MARUTI.NS', 'Maruti'],
            ['SUNPHARMA.NS', 'Sun Pharma'], ['ADANIENT.NS', 'Adani Enterprises'],
            ['BAJFINANCE.NS', 'Bajaj Finance'], ['ZOMATO.NS', 'Zomato'], ['DMART.NS', 'DMart'],
            ['TATASTEEL.NS', 'Tata Steel'], ['WIPRO.NS', 'Wipro'], ['HINDUNILVR.NS', 'HUL'],
        ]],
        ['Europe & Asia', [
            ['ASML', 'ASML'], ['SAP', 'SAP'], ['TM', 'Toyota'], ['BABA', 'Alibaba'],
            ['TSM', 'TSMC'], ['SHEL', 'Shell'], ['NVO', 'Novo Nordisk'], ['SONY', 'Sony'],
            ['HSBC', 'HSBC'], ['SAN', 'Santander'], ['MC.PA', 'LVMH'], ['SIE.DE', 'Siemens'],
            ['7203.T', 'Toyota (Tokyo)'], ['0700.HK', 'Tencent'], ['RIO', 'Rio Tinto'],
        ]],
    ];
}

function bt_indicators()
{
    /* p = period label, p2/p3 = extra periods, m = multiplier */
    return [
        ['group' => 'Price', 'items' => [
            ['id' => 'close', 'label' => 'Close'], ['id' => 'open', 'label' => 'Open'],
            ['id' => 'high', 'label' => 'High'], ['id' => 'low', 'label' => 'Low'],
            ['id' => 'volume', 'label' => 'Volume'],
            ['id' => 'hl2', 'label' => '(High+Low)/2'], ['id' => 'hlc3', 'label' => 'Typical price'],
            ['id' => 'ohlc4', 'label' => 'Average price'],
        ]],
        ['group' => 'Moving averages', 'items' => [
            ['id' => 'ema', 'label' => 'EMA', 'p' => 'Period', 'pd' => 20, 'src' => true],
            ['id' => 'sma', 'label' => 'SMA', 'p' => 'Period', 'pd' => 50, 'src' => true],
            ['id' => 'wma', 'label' => 'WMA', 'p' => 'Period', 'pd' => 20, 'src' => true],
            ['id' => 'vwap', 'label' => 'VWAP (rolling)', 'p' => 'Bars', 'pd' => 20],
        ]],
        ['group' => 'Momentum', 'items' => [
            ['id' => 'rsi', 'label' => 'RSI', 'p' => 'Period', 'pd' => 14, 'src' => true],
            ['id' => 'macd', 'label' => 'MACD line', 'p' => 'Fast', 'pd' => 12, 'p2' => 'Slow', 'p2d' => 26, 'p3' => 'Signal', 'p3d' => 9],
            ['id' => 'macd_signal', 'label' => 'MACD signal', 'p' => 'Fast', 'pd' => 12, 'p2' => 'Slow', 'p2d' => 26, 'p3' => 'Signal', 'p3d' => 9],
            ['id' => 'macd_hist', 'label' => 'MACD histogram', 'p' => 'Fast', 'pd' => 12, 'p2' => 'Slow', 'p2d' => 26, 'p3' => 'Signal', 'p3d' => 9],
            ['id' => 'stoch_k', 'label' => 'Stochastic %K', 'p' => 'Period', 'pd' => 14, 'p2' => 'Smooth K', 'p2d' => 1, 'p3' => 'Smooth D', 'p3d' => 3],
            ['id' => 'stoch_d', 'label' => 'Stochastic %D', 'p' => 'Period', 'pd' => 14, 'p2' => 'Smooth K', 'p2d' => 1, 'p3' => 'Smooth D', 'p3d' => 3],
            ['id' => 'roc', 'label' => 'Rate of change %', 'p' => 'Bars', 'pd' => 10, 'src' => true],
        ]],
        ['group' => 'Trend strength', 'items' => [
            ['id' => 'adx', 'label' => 'ADX', 'p' => 'Period', 'pd' => 14],
            ['id' => 'plus_di', 'label' => '+DI', 'p' => 'Period', 'pd' => 14],
            ['id' => 'minus_di', 'label' => '-DI', 'p' => 'Period', 'pd' => 14],
            ['id' => 'supertrend', 'label' => 'Supertrend line', 'p' => 'ATR period', 'pd' => 10, 'm' => 'Factor', 'md' => 3],
            ['id' => 'supertrend_dir', 'label' => 'Supertrend direction (1 / -1)', 'p' => 'ATR period', 'pd' => 10, 'm' => 'Factor', 'md' => 3],
        ]],
        ['group' => 'Volatility & range', 'items' => [
            ['id' => 'atr', 'label' => 'ATR', 'p' => 'Period', 'pd' => 14],
            ['id' => 'atr_pct', 'label' => 'ATR % of price', 'p' => 'Period', 'pd' => 14],
            ['id' => 'stdev', 'label' => 'Std deviation', 'p' => 'Period', 'pd' => 20, 'src' => true],
            ['id' => 'bb_upper', 'label' => 'Bollinger upper', 'p' => 'Period', 'pd' => 20, 'm' => 'Std dev', 'md' => 2, 'src' => true],
            ['id' => 'bb_lower', 'label' => 'Bollinger lower', 'p' => 'Period', 'pd' => 20, 'm' => 'Std dev', 'md' => 2, 'src' => true],
            ['id' => 'bb_mid', 'label' => 'Bollinger middle', 'p' => 'Period', 'pd' => 20, 'm' => 'Std dev', 'md' => 2, 'src' => true],
            ['id' => 'bb_width', 'label' => 'Bollinger width %', 'p' => 'Period', 'pd' => 20, 'm' => 'Std dev', 'md' => 2],
            ['id' => 'highest', 'label' => 'Highest high (last n bars)', 'p' => 'Bars', 'pd' => 20],
            ['id' => 'lowest', 'label' => 'Lowest low (last n bars)', 'p' => 'Bars', 'pd' => 20],
        ]],
    ];
}

function bt_presets()
{
    return [
        [
            'id' => 'ema_cross',
            'name' => 'EMA crossover (trend)',
            'about' => 'Long when the fast EMA crosses above the slow EMA, short when it crosses below. Classic trend following.',
            'tf' => '1d',
            'strategy' => [
                'rules' => [
                    'entry_long'  => [['left' => ['ind' => 'ema', 'period' => 20], 'op' => 'crosses_above', 'right' => ['ind' => 'ema', 'period' => 50]]],
                    'exit_long'   => [['left' => ['ind' => 'ema', 'period' => 20], 'op' => 'crosses_below', 'right' => ['ind' => 'ema', 'period' => 50]]],
                    'entry_short' => [['left' => ['ind' => 'ema', 'period' => 20], 'op' => 'crosses_below', 'right' => ['ind' => 'ema', 'period' => 50]]],
                    'exit_short'  => [['left' => ['ind' => 'ema', 'period' => 20], 'op' => 'crosses_above', 'right' => ['ind' => 'ema', 'period' => 50]]],
                ],
                'risk' => ['stop_loss_atr' => 3, 'atr_period' => 14],
            ],
            'grid' => ['fast' => [10, 20, 30], 'slow' => [50, 100, 200]],
            'template' => [
                'rules' => [
                    'entry_long'  => [['left' => ['ind' => 'ema', 'period' => '$fast'], 'op' => 'crosses_above', 'right' => ['ind' => 'ema', 'period' => '$slow']]],
                    'exit_long'   => [['left' => ['ind' => 'ema', 'period' => '$fast'], 'op' => 'crosses_below', 'right' => ['ind' => 'ema', 'period' => '$slow']]],
                ],
                'risk' => ['stop_loss_atr' => 3, 'atr_period' => 14],
            ],
        ],
        [
            'id' => 'rsi_rev',
            'name' => 'RSI mean reversion',
            'about' => 'Long when RSI drops under 30, out as soon as it gets back above 55. Built for range markets.',
            'tf' => '1d',
            'strategy' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'rsi', 'period' => 14], 'op' => '<', 'right' => ['value' => 30]]],
                    'exit_long'  => [['left' => ['ind' => 'rsi', 'period' => 14], 'op' => '>', 'right' => ['value' => 55]]],
                ],
                'risk' => ['stop_loss_pct' => 5, 'max_bars' => 20],
            ],
            'grid' => ['buy' => [20, 25, 30, 35], 'sell' => [50, 55, 60, 65]],
            'template' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'rsi', 'period' => 14], 'op' => '<', 'right' => ['value' => '$buy']]],
                    'exit_long'  => [['left' => ['ind' => 'rsi', 'period' => 14], 'op' => '>', 'right' => ['value' => '$sell']]],
                ],
                'risk' => ['stop_loss_pct' => 5, 'max_bars' => 20],
            ],
        ],
        [
            'id' => 'donchian',
            'name' => 'Donchian breakout (turtle)',
            'about' => 'Long on a break of the 20-bar high, out on a break of the 10-bar low, with an ATR stop.',
            'tf' => '1d',
            'strategy' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'close'], 'op' => '>', 'right' => ['ind' => 'highest', 'period' => 20]]],
                    'exit_long'  => [['left' => ['ind' => 'close'], 'op' => '<', 'right' => ['ind' => 'lowest', 'period' => 10]]],
                ],
                'risk' => ['stop_loss_atr' => 2, 'atr_period' => 14, 'trail_atr' => 3],
            ],
            'grid' => ['inn' => [10, 20, 40, 55], 'outn' => [5, 10, 20]],
            'template' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'close'], 'op' => '>', 'right' => ['ind' => 'highest', 'period' => '$inn']]],
                    'exit_long'  => [['left' => ['ind' => 'close'], 'op' => '<', 'right' => ['ind' => 'lowest', 'period' => '$outn']]],
                ],
                'risk' => ['stop_loss_atr' => 2, 'atr_period' => 14],
            ],
        ],
        [
            'id' => 'bb_break',
            'name' => 'Bollinger breakout',
            'about' => 'Long on a close above the upper band, out as soon as price closes back under the middle band.',
            'tf' => '1d',
            'strategy' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'close'], 'op' => 'crosses_above', 'right' => ['ind' => 'bb_upper', 'period' => 20, 'mult' => 2]]],
                    'exit_long'  => [['left' => ['ind' => 'close'], 'op' => 'crosses_below', 'right' => ['ind' => 'bb_mid', 'period' => 20, 'mult' => 2]]],
                ],
                'risk' => ['trail_atr' => 2.5, 'atr_period' => 14],
            ],
            'grid' => ['n' => [14, 20, 30], 'k' => [1.5, 2, 2.5]],
            'template' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'close'], 'op' => 'crosses_above', 'right' => ['ind' => 'bb_upper', 'period' => '$n', 'mult' => '$k']]],
                    'exit_long'  => [['left' => ['ind' => 'close'], 'op' => 'crosses_below', 'right' => ['ind' => 'bb_mid', 'period' => '$n', 'mult' => '$k']]],
                ],
                'risk' => ['trail_atr' => 2.5, 'atr_period' => 14],
            ],
        ],
        [
            'id' => 'supertrend',
            'name' => 'Supertrend follow',
            'about' => 'Long on a Supertrend flip up, short on a flip down. Trades both sides.',
            'tf' => '1h',
            'strategy' => [
                'rules' => [
                    'entry_long'  => [['left' => ['ind' => 'supertrend_dir', 'period' => 10, 'mult' => 3], 'op' => 'crosses_above', 'right' => ['value' => 0]]],
                    'exit_long'   => [['left' => ['ind' => 'supertrend_dir', 'period' => 10, 'mult' => 3], 'op' => 'crosses_below', 'right' => ['value' => 0]]],
                    'entry_short' => [['left' => ['ind' => 'supertrend_dir', 'period' => 10, 'mult' => 3], 'op' => 'crosses_below', 'right' => ['value' => 0]]],
                    'exit_short'  => [['left' => ['ind' => 'supertrend_dir', 'period' => 10, 'mult' => 3], 'op' => 'crosses_above', 'right' => ['value' => 0]]],
                ],
                'risk' => [],
            ],
            'grid' => ['n' => [7, 10, 14], 'f' => [2, 3, 4]],
            'template' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'supertrend_dir', 'period' => '$n', 'mult' => '$f'], 'op' => 'crosses_above', 'right' => ['value' => 0]]],
                    'exit_long'  => [['left' => ['ind' => 'supertrend_dir', 'period' => '$n', 'mult' => '$f'], 'op' => 'crosses_below', 'right' => ['value' => 0]]],
                ],
                'risk' => [],
            ],
        ],
        [
            'id' => 'macd_trend',
            'name' => 'MACD + daily trend filter',
            'about' => 'MACD crossing above its signal line, taken only while price is above the daily EMA 200. An example of a higher-timeframe filter.',
            'tf' => '1h',
            'strategy' => [
                'rules' => [
                    'entry_long' => [
                        ['left' => ['ind' => 'macd', 'period' => 12, 'period2' => 26, 'period3' => 9], 'op' => 'crosses_above', 'right' => ['ind' => 'macd_signal', 'period' => 12, 'period2' => 26, 'period3' => 9]],
                        ['left' => ['ind' => 'close'], 'op' => '>', 'right' => ['ind' => 'ema', 'period' => 200, 'tf' => '1D']],
                    ],
                    'exit_long' => [['left' => ['ind' => 'macd', 'period' => 12, 'period2' => 26, 'period3' => 9], 'op' => 'crosses_below', 'right' => ['ind' => 'macd_signal', 'period' => 12, 'period2' => 26, 'period3' => 9]]],
                ],
                'risk' => ['stop_loss_atr' => 2.5, 'take_profit_atr' => 5, 'atr_period' => 14],
            ],
            'grid' => ['sl' => [1.5, 2, 2.5, 3], 'tp' => [3, 4, 5, 6]],
            'template' => [
                'rules' => [
                    'entry_long' => [
                        ['left' => ['ind' => 'macd', 'period' => 12, 'period2' => 26, 'period3' => 9], 'op' => 'crosses_above', 'right' => ['ind' => 'macd_signal', 'period' => 12, 'period2' => 26, 'period3' => 9]],
                        ['left' => ['ind' => 'close'], 'op' => '>', 'right' => ['ind' => 'ema', 'period' => 200, 'tf' => '1D']],
                    ],
                    'exit_long' => [['left' => ['ind' => 'macd', 'period' => 12, 'period2' => 26, 'period3' => 9], 'op' => 'crosses_below', 'right' => ['ind' => 'macd_signal', 'period' => 12, 'period2' => 26, 'period3' => 9]]],
                ],
                'risk' => ['stop_loss_atr' => '$sl', 'take_profit_atr' => '$tp', 'atr_period' => 14],
            ],
        ],
        [
            'id' => 'adx_pullback',
            'name' => 'Trend pullback (ADX + RSI)',
            'about' => 'Enter when the trend is strong (ADX 25+), price is above the EMA 50, and RSI dips.',
            'tf' => '1d',
            'strategy' => [
                'rules' => [
                    'entry_long' => [
                        ['left' => ['ind' => 'adx', 'period' => 14], 'op' => '>', 'right' => ['value' => 25]],
                        ['left' => ['ind' => 'close'], 'op' => '>', 'right' => ['ind' => 'ema', 'period' => 50]],
                        ['left' => ['ind' => 'rsi', 'period' => 14], 'op' => '<', 'right' => ['value' => 45]],
                    ],
                    'exit_long' => [['left' => ['ind' => 'rsi', 'period' => 14], 'op' => '>', 'right' => ['value' => 65]]],
                ],
                'risk' => ['stop_loss_atr' => 2, 'atr_period' => 14, 'max_bars' => 25],
            ],
            'grid' => ['adx' => [20, 25, 30], 'dip' => [40, 45, 50]],
            'template' => [
                'rules' => [
                    'entry_long' => [
                        ['left' => ['ind' => 'adx', 'period' => 14], 'op' => '>', 'right' => ['value' => '$adx']],
                        ['left' => ['ind' => 'close'], 'op' => '>', 'right' => ['ind' => 'ema', 'period' => 50]],
                        ['left' => ['ind' => 'rsi', 'period' => 14], 'op' => '<', 'right' => ['value' => '$dip']],
                    ],
                    'exit_long' => [['left' => ['ind' => 'rsi', 'period' => 14], 'op' => '>', 'right' => ['value' => 65]]],
                ],
                'risk' => ['stop_loss_atr' => 2, 'atr_period' => 14, 'max_bars' => 25],
            ],
        ],
        [
            'id' => 'golden_cross',
            'name' => 'Golden cross (buy & hold style)',
            'about' => 'Long while the SMA 50 sits above the SMA 200. The oldest trend filter there is, and a fair thing to put next to buy & hold.',
            'tf' => '1d',
            'strategy' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'sma', 'period' => 50], 'op' => 'crosses_above', 'right' => ['ind' => 'sma', 'period' => 200]]],
                    'exit_long'  => [['left' => ['ind' => 'sma', 'period' => 50], 'op' => 'crosses_below', 'right' => ['ind' => 'sma', 'period' => 200]]],
                ],
                'risk' => [],
            ],
            'grid' => ['fast' => [20, 50, 100], 'slow' => [100, 150, 200]],
            'template' => [
                'rules' => [
                    'entry_long' => [['left' => ['ind' => 'sma', 'period' => '$fast'], 'op' => 'crosses_above', 'right' => ['ind' => 'sma', 'period' => '$slow']]],
                    'exit_long'  => [['left' => ['ind' => 'sma', 'period' => '$fast'], 'op' => 'crosses_below', 'right' => ['ind' => 'sma', 'period' => '$slow']]],
                ],
                'risk' => [],
            ],
        ],
    ];
}

function bt_meta()
{
    return [
        'markets' => bt_markets(),
        'indicators' => bt_indicators(),
        'presets' => bt_presets(),
        'timeframes' => [
            ['1w', 'Weekly', 'full history'],
            ['1d', 'Daily', 'full history'],
            ['4h', '4 hour', 'last ~2 years'],
            ['1h', '1 hour', 'last ~2 years'],
            ['30m', '30 min', 'last 60 days'],
            ['15m', '15 min', 'last 60 days'],
            ['5m', '5 min', 'last 60 days'],
        ],
        'htf' => ['5min', '15min', '30min', '1h', '4h', '1D', '1W'],
        'ops' => [
            ['crosses_above', 'crosses above'], ['crosses_below', 'crosses below'],
            ['>', 'is greater than'], ['<', 'is less than'],
            ['>=', 'is greater or equal'], ['<=', 'is less or equal'],
        ],
        'limits' => ['max_bars' => BT_MAX_BARS, 'max_rules' => 10, 'max_combinations' => BT_MAX_COMBOS],
    ];
}

}
