"""ChartPulse backend v3. Run: uvicorn app:api --host 0.0.0.0 --port 8000
Env: EXCHANGE (default binance), TOP_N (default 150), SCAN_EVERY (seconds, default 900)."""
import os, json, time, threading, traceback
import ccxt, pandas as pd
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from engine import scan

EX = getattr(ccxt, os.getenv("EXCHANGE", "binance"))({"enableRateLimit": True})
TOP_N, EVERY = int(os.getenv("TOP_N", 150)), int(os.getenv("SCAN_EVERY", 900))
LIMITS = {"1h": 300, "4h": 300, "1d": 300, "1w": 150}
STATE = {"updated": None, "signals": []}

def df(rows):
    d = pd.DataFrame(rows, columns=["t", "open", "high", "low", "close", "volume"])
    d.index = pd.to_datetime(d.pop("t"), unit="ms"); return d

STABLE = {"USDC", "FDUSD", "TUSD", "USDP", "DAI", "BUSD", "USDE", "EUR", "EURI", "AEUR", "USD1", "XUSD", "PYUSD"}

def universe():
    tk = EX.fetch_tickers()
    u = [(s, v.get("quoteVolume") or 0) for s, v in tk.items()
         if s.endswith("/USDT") and ":" not in s and s.split("/")[0] not in STABLE]
    return [s for s, _ in sorted(u, key=lambda x: -x[1])[:TOP_N]]

def fetch(sym):
    t = {tf: df(EX.fetch_ohlcv(sym, tf, limit=n)) for tf, n in LIMITS.items()}
    t["1h"], t["4h"] = t["1h"].iloc[:-1], t["4h"].iloc[:-1]  # drop forming bars (no repaint)
    return t  # crypto daily/weekly close = 00:00 UTC, matches exchange candles

SHOW = ("ENTRY", "PATTERN", "GATE", "E0", "E1", "E2", "E3", "SWEPT")

def _py(o):  # numpy -> plain python so FastAPI/json never choke
    return o.item() if hasattr(o, "item") else str(o)

def run_once():
    out = []
    for s in universe():
        try:
            for r in scan(s, fetch(s)):  # scan() returns a LIST (Bottom Reversal and/or zone-sweep)
                if r["alert"] or r["stage"] in SHOW: out.append(r)
        except Exception: print(s, traceback.format_exc(limit=1))
    out = json.loads(json.dumps(sorted(out, key=lambda r: -r["rank"]), default=_py))
    STATE.update(updated=pd.Timestamp.utcnow().isoformat(), signals=out)
    with open("signals.json", "w") as f: json.dump(STATE, f)

def loop():
    while True:
        try: run_once()
        except Exception: traceback.print_exc()
        time.sleep(EVERY)

api = FastAPI(title="ChartPulse scanner")
api.add_middleware(CORSMiddleware, allow_origins=["https://chartpulse.io", "https://www.chartpulse.io"], allow_methods=["GET"])
threading.Thread(target=loop, daemon=True).start()

@api.get("/api/signals")
def signals(): return STATE

@api.get("/api/health")
def health(): return {"ok": True, "updated": STATE["updated"]}
