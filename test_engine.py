import sys; sys.path.insert(0,'.')
import numpy as np, pandas as pd, traceback
import engine as E
from engine import pattern_at, scan, CFG

def arr(c):
    a=np.array(c,float); return a[:,0],a[:,1],a[:,2],a[:,3]
tests={
 'hammer':   ([(10,10.1,9.0,10.05)], 0, 'hammer'),
 'engulfing':([(10,10.2,9.4,9.5),(9.45,10.5,9.4,10.4)], 1, 'engulfing'),
 'piercing': ([(10,10.1,9.0,9.1),(9.1,10.0,9.0,9.8)], 1, 'piercing'),
 'morning':  ([(10,10.1,8.9,9.0),(8.95,9.05,8.8,8.9),(8.95,9.8,8.9,9.7)], 2, 'morning_star'),
 'none':     ([(10,10.5,9.8,10.4),(10.4,10.9,10.3,10.5)], 1, None),
}
for k,(c,p,exp) in tests.items():
    got=pattern_at(*arr(c),p); print(f"pattern {k:10s} -> {got}  {'OK' if got==exp else 'FAIL expected '+str(exp)}")

def daily(n=300, seed=1):
    rng=np.random.default_rng(seed)
    idx=pd.date_range(end="2026-10-21", periods=n, freq="1D")
    c=np.linspace(200,100,n)*np.exp(rng.normal(0,.012,n)); o=np.r_[c[0],c[:-1]]
    h=np.maximum(o,c)*(1+abs(rng.normal(0,.006,n))); l=np.minimum(o,c)*(1-abs(rng.normal(0,.006,n)))
    return pd.DataFrame(dict(open=o,high=h,low=l,close=c,volume=rng.uniform(100,200,n)),index=idx)
def weekly(n=150,green=True):
    rng=np.random.default_rng(5); idx=pd.date_range(end="2026-10-19",periods=n,freq="7D")
    c=np.linspace(210,100,n)*np.exp(rng.normal(0,.03,n)); o=np.r_[c[0],c[:-1]]
    o[-2]= c[-2]*(0.97 if green else 1.03)
    h=np.maximum(o,c)*1.02; l=np.minimum(o,c)*.98
    return pd.DataFrame(dict(open=o,high=h,low=l,close=c,volume=np.ones(n)),index=idx)
def h4(n=300,seed=7):
    rng=np.random.default_rng(seed); idx=pd.date_range(end="2026-10-22",periods=n,freq="4h")
    c=100*np.exp(np.cumsum(rng.normal(0,.004,n))); o=np.r_[c[0],c[:-1]]
    return pd.DataFrame(dict(open=o,high=np.maximum(o,c)*1.003,low=np.minimum(o,c)*.997,close=c,volume=np.ones(n)),index=idx)
def with_live(d):
    live=d.iloc[-1:].copy(); live.index=live.index+pd.Timedelta(days=1); return pd.concat([d,live])

d=daily(); m=len(d); pm=d.low.iloc[m-24:m-4].min()
d.iloc[m-4]=[pm*1.005, pm*1.02, pm*0.97, pm*1.012, 150]   # liquidity sweep
d.iloc[m-3]=[pm*1.01,  pm*1.03, pm*0.965,pm*1.02, 150]
d.iloc[m-2]=[pm*1.02,  pm*1.03, pm*0.96, pm*1.028,160]    # hammer
d.iloc[m-1]=[pm*1.028, pm*1.07, pm*1.025,pm*1.06, 170]    # green, closes above hammer high
t={'1d':with_live(d),'1w':weekly(),'4h':h4(),'1h':h4(seed=8)}
fmt=lambda r:[(x['setup'],x['stage'],x.get('pattern'),x.get('gate'),x.get('bottom_pos'),round(x['cancel_below'],2) if x.get('cancel_below') else None) for x in r]
print('A weekly green  ->',fmt(scan("T/USDT",t)))
print('B weekly RED    ->',fmt(scan("T/USDT",{**t,'1w':weekly(green=False)})))
# C: confirm candle red -> must NOT be ENTRY
d2=d.copy(); d2.iloc[m-1]=[pm*1.028, pm*1.03, pm*1.0, pm*1.005, 170]
print('C confirm RED   ->',fmt(scan("T/USDT",{**t,'1d':with_live(d2)})))
# D: confirm green but closes BELOW hammer high -> must NOT be ENTRY
d3=d.copy(); d3.iloc[m-1]=[pm*1.02, pm*1.029, pm*1.015, pm*1.026, 170]
print('D below pat.high->',fmt(scan("T/USDT",{**t,'1d':with_live(d3)})))
# E: cancel - last closed bar closes below the bottom
d4=d.copy(); d4.iloc[m-1]=[pm*1.0, pm*1.0, pm*0.80, pm*0.82, 200]
print('E cancel        ->',fmt(scan("T/USDT",{**t,'1d':with_live(d4)})))
# F: same pattern but price NOT at bottom (uptrend year) -> must be empty
dn=daily(); dn[['open','high','low','close']]=dn[['open','high','low','close']].values[::-1]
print('F not at bottom ->',fmt(scan("T/USDT",{**t,'1d':with_live(dn)})))

# fuzz: random symbols, both modes
for f4 in (False,True):
    CFG['fast_4h']=f4; ok=err=0; st={}; 
    for s in range(300):
        rng=np.random.default_rng(s)
        dd=daily(300,s); dd[['open','high','low','close']]*=1+rng.normal(0,.02)
        tt={'1d':with_live(dd),'1w':weekly(),'4h':h4(300,s),'1h':h4(300,s+1000)}
        try:
            for r in scan("X/USDT",tt): st[(r['setup'],r['stage'])]=st.get((r['setup'],r['stage']),0)+1
            ok+=1
        except Exception: 
            err+=1
            if err<3: traceback.print_exc(limit=3)
    print('fuzz fast_4h=',f4,'ok',ok,'err',err,st)
CFG['fast_4h']=False
