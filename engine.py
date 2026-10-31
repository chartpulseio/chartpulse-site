"""ChartPulse engine v3: Bottom Reversal (gate -> pattern -> next-candle confirm -> cancel) + Ground (zone) score + zone-sweep stages E0-E3.
Input per symbol: dict of OHLCV DataFrames {'1h','4h','1d','1w'} (cols: open high low close volume).
1h/4h = closed bars only. 1d/1w: last row is the live (forming) bar.
All thresholds are starting hypotheses - tune with backtests. Not financial advice."""
import numpy as np, pandas as pd

CFG = dict(tol=0.5, smin=0.1, smax=1.5, vol=1.3, rec_h=12, age=4, ground_min=30, entry_min=25,
           # --- Bottom Reversal (daily, closed candles only) ---
           bottom_pct=0.33,   # price must be in the lowest third of the 1y range
           range_bars=252, gate_bars=5, gate_bars_4h=18,  # 'thode dino ke andar' = 5 daily bars (18 x 4h)
           bb_week_lb=6, liq_lb=20, choch_win=40, cancel_lb=30,
           fast_4h=False,     # True = run the same setup on 4h too (signals in hours, less reliable)
           h4_gate=True)      # 4h gate applies ONLY to the old zone-sweep path, not to Bottom Reversal
ema = lambda s, n: s.ewm(span=n, adjust=False).mean()

def atr(d, n=14):
    pc = d.close.shift()
    tr = pd.concat([d.high - d.low, (d.high - pc).abs(), (d.low - pc).abs()], axis=1).max(axis=1)
    return tr.ewm(alpha=1 / n, adjust=False).mean()

def pivots(v, n, low=True):
    f = np.min if low else np.max
    return [i for i in range(n, len(v) - n) if v[i] == f(v[i - n:i + n + 1])]

def choch_up(df, win=40):
    """Bullish CHoCH: after the window's lowest low, a close above the last pivot high before it."""
    d = df.iloc[-win:]; li = int(np.argmin(d.low.values))
    ph = [i for i in pivots(d.high.values, 2, False) if i < li]
    return bool(ph and li < len(d) - 1 and (d.close.values[li + 1:] > d.high.values[ph[-1]]).any())

def htf_gate(w, h4):
    """Weekly: last CLOSED weekly candle is green (EMA21 / higher-low filter removed). 4H: 'already' bullish or 'flipped' (CHoCH)."""
    w = w.iloc[:-1]; wk = bool(w.close.iloc[-1] > w.open.iloc[-1])
    p4 = pivots(h4.low.values, 2)
    already = (h4.close.iloc[-1] > ema(h4.close, 50).iloc[-1] and len(p4) >= 2
               and h4.low.values[p4[-1]] > h4.low.values[p4[-2]])
    return wk, ("already" if already else "flipped" if choch_up(h4, 30) else "no")

def levels(h, wc):
    """Level families: struct, prior (PDL/PDC/PWL/PML), pivot (weekly+monthly), fib, round. -> (price, family, age)"""
    L = []
    for n in (20, 50):
        x = h.low.iloc[-n:]; L.append((x.min(), 'struct', len(x) - 1 - int(np.argmin(x.values))))
    v = h.low.values[-150:]
    L += [(v[i], 'struct', len(v) - 1 - i) for i in pivots(v, 5)]
    L += [(h.low.iloc[-1], 'prior', 0), (h.close.iloc[-1], 'prior', 0), (wc.low.iloc[-1], 'prior', 0)]
    m = h.resample('MS').agg({'high': 'max', 'low': 'min', 'close': 'last'}).iloc[:-1]
    refs = [wc.iloc[-1]] + ([m.iloc[-1]] if len(m) else [])
    if len(m): L.append((m.low.iloc[-1], 'prior', 0))
    for r in refs:
        P = (r.high + r.low + r.close) / 3
        L += [(P, 'pivot', 0), (2 * P - r.high, 'pivot', 0), (P - (r.high - r.low), 'pivot', 0)]
    w52 = wc.iloc[-52:]; hi = int(np.argmax(w52.high.values)); lo = int(np.argmin(w52.low.values[:hi + 1]))
    if hi - lo >= 4:
        hh, ll = w52.high.values[hi], w52.low.values[lo]
        L += [(hh - r * (hh - ll), 'fib', 0) for r in (.382, .5, .618, .786)]
    p = h.close.iloc[-1]; step = 10 ** (np.floor(np.log10(p)) - 1) / 2; b = round(p / step)
    L += [((b + k) * step, 'round', 0) for k in range(-12, 13)]
    return L

def find_zone(h, wc, bar):
    """Best confluence cluster (most distinct families) around bar.low."""
    a = atr(h).iloc[-1]; lo = bar.low
    c = sorted(x for x in levels(h, wc) if lo - .3 * a <= x[0] <= lo + CFG['smax'] * a)
    best, i = None, 0
    while i < len(c):
        j = i
        while j + 1 < len(c) and c[j + 1][0] - c[i][0] <= CFG['tol'] * a: j += 1
        g = c[i:j + 1]; fam = {x[1] for x in g}
        if best is None or len(fam) > len(best['fam']):
            best = dict(lo=g[0][0], hi=g[-1][0], fam=fam, lv=g, atr=a)
        i = j + 1
    return best

def ground(h, wc, z):
    """Ground score 0-50: confluence 15, age/touch 10, context 10, weekly EMA21 10, volume node 5."""
    a, n = z['atr'], len(z['fam'])
    s = {'confluence': 15 if n >= 3 else 5 * n}
    age = any(f == 'struct' and ag >= CFG['age'] for _, f, ag in z['lv'])
    l = h.low.iloc[-60:]; t = ((l >= z['lo'] - .25 * a) & (l <= z['hi'] + .25 * a)).sum()
    s['age_touch'] = 5 * age + 5 * (t >= 2)
    rg = (h.high - h.low).iloc[-5:]; cl = ((h.close - h.low) / (h.high - h.low).replace(0, np.nan)).iloc[-5:]
    waterfall = ((rg > a) & (cl < .3)).sum() >= 4 and rg.iloc[-1] > rg.iloc[0]
    s['context'] = 0 if waterfall else 10
    s['htf'] = 10 if abs(ema(wc.close, 21).iloc[-1] - (z['lo'] + z['hi']) / 2) <= a else 0
    v = h.volume.iloc[-60:]; ov = (h.low.iloc[-60:] <= z['hi']) & (h.high.iloc[-60:] >= z['lo'])
    s['volume'] = 5 if ov.any() and v[ov].mean() >= 1.2 * v.mean() else 0
    return sum(s.values()), s, bool(waterfall)

def entry(h, live, h1, h4, z):
    """Entry score 0-50 + stage (WATCH/SWEPT/E0/E1/E2). Handles sweep path and touch path."""
    a = z['atr']; depth = (z['lo'] - live.low) / a
    swept = CFG['smin'] <= depth <= CFG['smax']
    touch = depth < CFG['smin'] and live.low <= z['hi'] + .25 * a
    rng = live.high - live.low; body = abs(live.close - live.open)
    pin = rng > 0 and (min(live.open, live.close) - live.low >= 2 * body or live.close >= live.low + .5 * rng)
    upper = rng > 0 and live.close >= live.low + .5 * rng
    t = h1.iloc[-24:]; li = int(np.argmin(t.low.values))
    after = np.where(t.close.values[li:] > z['lo'])[0]; hrs = int(after[0]) if len(after) else None
    reclaimed = hrs is not None and t.close.iloc[-1] > z['lo']
    vol_ok = t.volume.values[li] >= CFG['vol'] * h1.volume.iloc[-100:].mean()
    ch = choch_up(h1)
    lb = h.close.rolling(20).mean() - 2 * h.close.rolling(20).std()
    walk = (h.close.iloc[-5:] < lb.iloc[-5:]).sum() >= 3
    bb = bool(live.low < lb.iloc[-1] and live.close > lb.iloc[-1] and not walk)
    s = {'sweep': 5 * (swept or touch) + 5 * bool(pin), 'absorption': 8 * bool(vol_ok) + 7 * bool(upper),
         'reclaim': 10 if (hrs is not None and hrs <= CFG['rec_h']) else 5 if (hrs is not None and hrs <= 24) else 0,
         'choch': 10 * ch, 'bb_candle': 3 * bb + 2 * (live.close > live.open)}
    stage = 'WATCH'
    if swept and not reclaimed: stage = 'SWEPT'
    if (swept and reclaimed) or (touch and not swept and live.close > z['lo']): stage = 'E0'
    if stage == 'E0' and ch and s['absorption'] >= 7: stage = 'E1'
    if stage == 'E1':
        t4 = h4.iloc[-6:]; k = int(np.argmin(t4.low.values)); post = t4.iloc[k + 1:]
        if len(post) >= 3 and post.volume.mean() < t4.volume.iloc[k] and post.close.iloc[-1] > z['lo']: stage = 'E2'
    return sum(s.values()), s, stage, float(depth), bool(swept)

def e3(d, wc, closed=False):
    """E3 = next-candle confirmation of yesterday's sweep/touch candle (strict: close > its high; soft: > midpoint)."""
    sig, cur = d.iloc[-2], d.iloc[-1]; zs = find_zone(d.iloc[:-2], wc, sig)
    if not zs: return None
    a = zs['atr']; ok = (CFG['smin'] <= (zs['lo'] - sig.low) / a <= CFG['smax'] and sig.close > zs['lo']) \
        or (sig.low <= zs['hi'] + .25 * a and sig.low >= zs['lo'] - CFG['smin'] * a)
    lvl = sig.high if closed else (sig.high + sig.low) / 2
    if ok and cur.low > sig.low and cur.close > cur.open and cur.close > lvl:
        return zs, ground(d.iloc[:-2], wc, zs), ('strict' if closed else 'soft')

# ---------------- Bottom Reversal setup ----------------
def bb_lower(c, n=20, k=2): return c.rolling(n).mean() - k * c.rolling(n).std()

def pattern_at(O, H, L, C, p):
    """Bullish reversal candle ending at index p -> name or None. Crypto has ~no gaps, so open/close checks use a small tolerance."""
    o, h, l, c = O[p], H[p], L[p], C[p]; rng = h - l
    if rng <= 0: return None
    body = abs(c - o); lower = min(o, c) - l; upper = h - max(o, c)
    if lower >= 2 * body and lower >= .5 * rng and upper <= .25 * rng: return 'hammer'
    if p >= 1 and C[p - 1] < O[p - 1] and c > o:
        po, pc = O[p - 1], C[p - 1]; pb = po - pc; tol = .1 * pb
        if o <= pc + tol and c >= po: return 'engulfing'
        if pb >= .4 * (H[p - 1] - L[p - 1]) and o <= pc + tol and (po + pc) / 2 < c < po: return 'piercing'
    if p >= 2 and C[p - 2] < O[p - 2] and c > o:
        o1, c1 = O[p - 2], C[p - 2]; b1 = o1 - c1
        if b1 >= .5 * (H[p - 2] - L[p - 2]) and abs(O[p - 1] - C[p - 1]) <= .3 * b1 and c > (o1 + c1) / 2: return 'morning_star'
    return None

def gate_events(x, wc, n):
    """Gate = at least 2 of {liquidity sweep, BB sweep, CHoCH} inside the last n bars. -> dict(events, gate_abs) or None."""
    m = len(x); lb = bb_lower(x.close); pm = x.low.shift(1).rolling(CFG['liq_lb']).min()
    liq = ((x.low < pm) & (x.close > pm)).values[-n:]
    walk = (x.close < lb).astype(int).rolling(5).sum() >= 3
    bb = ((x.low < lb) & (x.close > lb) & ~walk).values[-n:].copy()
    wl = bb_lower(wc.close)
    if ((wc.low < wl) & (wc.close > wl)).iloc[-CFG['bb_week_lb']:].any(): bb[0] = True  # weekly BB wick counts (up to 6 weeks)
    ch = [choch_up(x.iloc[:m - j], CFG['choch_win']) for j in range(n, -1, -1)]
    chev = np.array([ch[i] and not ch[i - 1] for i in range(1, n + 1)])  # CHoCH 'event' = flips to true inside the window
    first = {k: int(np.argmax(v)) for k, v in (('liq', liq), ('bb', bb), ('choch', chev)) if v.any()}
    if len(first) < 2: return None
    return dict(events=sorted(first, key=first.get), gate_abs=m - n + sorted(first.values())[1])

def br_core(x, ref, wc, wk, n, tf):
    """x = CLOSED bars of the working timeframe, ref = closed daily bars (1y range), wc = closed weekly bars."""
    m = len(x)
    if m < 60 or len(wc) < 25 or len(ref) < 60: return None
    r = ref.iloc[-CFG['range_bars']:]; lo, hi = r.low.min(), r.high.max()
    O, H, L, C = (x[k].values for k in ('open', 'high', 'low', 'close'))

    def build(stage, g, pat):
        ga = g['gate_abs']; pos = (C[ga] - lo) / (hi - lo) if hi > lo else 1.0
        if pos > CFG['bottom_pct']: return None                                  # Step 1: not at the bottom
        bl = float(x.low.iloc[max(0, ga + 1 - CFG['cancel_lb']):ga + 1].min())
        if (x.close.iloc[ga + 1:] < bl).any(): return None                        # Cancel: close below the bottom
        if stage != 'GATE' and not wk: stage = 'GATE'                             # Step 3: weekly green needed before entry
        return dict(setup='bottom_reversal', tf=tf, stage=stage, pattern=pat, gate=g['events'],
                    bottom_pos=int(round(float(pos) * 100)), cancel_below=bl, alert=stage == 'ENTRY')

    out = None
    g = gate_events(x.iloc[:-1], wc, n)                      # gate as of the pattern candle
    pp = pattern_at(O, H, L, C, m - 2)
    if g and pp and C[-1] > O[-1] and C[-1] > H[m - 2]:      # Step 4: next candle green AND closes above pattern high
        out = build('ENTRY', g, pp)
    if out is None:
        g = gate_events(x, wc, n)
        if g:
            pl = pattern_at(O, H, L, C, m - 1)
            out = build('PATTERN' if pl else 'GATE', g, pl)
    return out

def strong_tag(dc, h4):
    """Optional strength: daily close above EMA5 AND 4h EMA9 crossed above EMA21 within the last 12 bars."""
    e9, e21 = ema(h4.close, 9), ema(h4.close, 21)
    cross = e9.iloc[-1] > e21.iloc[-1] and bool((e9.iloc[-13:-1] <= e21.iloc[-13:-1]).any())
    return bool(dc.close.iloc[-1] > ema(dc.close, 5).iloc[-1] and cross)

def bottom_reversal(base, dc, wc, h4, wk):
    res = []; runs = [(dc, '1d', CFG['gate_bars'])] + ([(h4, '4h', CFG['gate_bars_4h'])] if CFG['fast_4h'] else [])
    for x, tf, n in runs:
        r = br_core(x, dc, wc, wk, n, tf)
        if not r: continue
        r.update(ground=0, zone=None, families=[], strong=strong_tag(dc, h4))
        try:
            z = find_zone(dc, wc, pd.Series({'low': r['cancel_below']}))
            if z: g, gs, wf = ground(dc, wc, z); r.update(ground=g, zone=[z['lo'], z['hi']], families=sorted(z['fam']), waterfall=wf)
        except Exception: pass
        r['rank'] = {'ENTRY': 100, 'PATTERN': 60, 'GATE': 30}[r['stage']] + r['ground'] + 10 * r['strong']
        res.append({**base, **r})
    return res

def scan(sym, t, market='crypto', daily_closed=False):
    """Returns a LIST of signals: Bottom Reversal (closed candles, no repaint) and/or the older zone-sweep path (uses the live daily bar)."""
    d, w, h4, h1 = t['1d'], t['1w'], t['4h'], t['1h']
    wk, h4s = htf_gate(w, h4)
    wc, dc, live = w.iloc[:-1], d.iloc[:-1], d.iloc[-1]
    base = dict(symbol=sym, market=market, weekly='bullish' if wk else 'red', h4=h4s,
                price=float(h1.close.iloc[-1]), ts=str(h1.index[-1]))
    res = bottom_reversal(base, dc, wc, h4, wk)
    if not wk or (CFG['h4_gate'] and h4s == 'no'): return res
    out = {**base, 'setup': 'zone_sweep'}
    r3 = e3(d, wc, daily_closed)
    if r3 and r3[1][0] >= CFG['ground_min']:
        zs, (g, gs, wf), mode = r3
        res.append({**out, 'stage': 'E3', 'e3_mode': mode, 'ground': g, 'entry': None, 'zone': [zs['lo'], zs['hi']],
                    'families': sorted(zs['fam']), 'alert': not wf, 'parts': gs, 'rank': g + 25}); return res
    z = find_zone(dc, wc, live)
    if not z: return res
    g, gs, wf = ground(dc, wc, z); e, es, stage, depth, swept = entry(dc, live, h1, h4, z)
    res.append({**out, 'stage': stage, 'ground': g, 'entry': e, 'zone': [z['lo'], z['hi']], 'families': sorted(z['fam']),
                'depth_atr': round(depth, 2), 'swept': swept, 'waterfall': wf, 'parts': {**gs, **es}, 'rank': g + e,
                'alert': g >= CFG['ground_min'] and e >= CFG['entry_min'] and stage in ('E1', 'E2') and not wf})
    return res
