//+------------------------------------------------------------------+
//| ChartPulse_AutoEntry.mq5   v3.0  "HTF Day-Extreme Reversal"        |
//| Bias: Daily+Weekly (server). Bear = SELL from day high, Bull = BUY |
//| from day low, at a ChartPulse zone, on a confirmed candle pattern. |
//| Auto ENTRY from ChartPulse signals + candle-pattern confirmation. |
//| Scans M5, M10, M15, M30 on every signal symbol in the background. |
//| One position per symbol (no duplicates across timeframes).        |
//| Exit is MANUAL (no take-profit). Stop-loss = pattern SL + buffer. |
//|                                                                    |
//| SETUP (2 minutes):                                                 |
//|  1. Tools > Options > Expert Advisors > tick "Allow WebRequest for |
//|     listed URL" > add  https://chartpulse.io                       |
//|  2. Put this file in MQL5\Experts, open in MetaEditor, Compile.    |
//|  3. Drag it onto ANY one chart, paste your key in ApiKey, OK.      |
//|  Run on a DEMO account first. Not financial advice.                |
//+------------------------------------------------------------------+
#property copyright "ChartPulse"
#property link      "https://chartpulse.io"
#property version   "3.00"
#property strict
#include <Trade\Trade.mqh>

input string ApiKey          = "";                              // ChartPulse API key (from chartpulse.io/api/)
input bool   TradingEnabled  = true;                            // kill switch: false = watch and log only
input double RiskPercent     = 0.5;                             // % of equity risked per trade
input bool   TF_M5           = true;
input bool   TF_M10          = true;
input bool   TF_M15          = true;
input bool   TF_M30          = true;
input double BufferATR       = 0.25;                            // SL buffer = BufferATR x ATR(14) of the pattern TF + spread
input double MaxStopATRDaily = 0.6;                             // skip if stop is wider than this x daily ATR
input double MaxSpreadATR    = 0.25;                            // skip if spread > this x ATR(14) of the pattern TF
input int    MaxTradesPerDay = 0;                               // 0 = no limit
input int    MaxOpenTrades   = 0;                               // 0 = no limit (still one per symbol)
input int    CooldownMin     = 60;                              // no new trade on the same symbol for this long after an entry
input int    MinSources      = 1;                               // min ChartPulse scanners agreeing
input bool   UseConfluence   = true;
input bool   UseGodfather    = true;
input bool   UseDayDrive     = true;
input bool   UseReversal     = true;
input bool   UsePinBar       = true;
input bool   UseEngulfing    = true;
input bool   UseStar         = true;
input bool   UseDoubleDenial = true;
input bool   Use3BarPlay     = true;
input bool   DayExtremeOnly  = true;                            // SELL only near today's HIGH, BUY only near today's LOW
input double DayExtremeATR   = 0.3;                             // "near" = pattern extreme within this x daily ATR of the day high/low
input int    RolloverSkipMin = 15;                              // no entries this many minutes either side of server midnight
input string SymbolSuffix    = "";                              // broker suffix, e.g. ".m" or "pro"
input string SymbolMap       = "";                              // overrides, e.g. "XAUUSD=GOLD,US500=SPX500"
input long   Magic           = 260112;
input int    EntryWindowSec  = 120;                             // enter only this soon after the pattern candle closed
input bool   LogCsv          = true;                            // MQL5\Files\ChartPulse_log.csv: every pattern found (traded or skipped)

const string API_URL = "https://chartpulse.io/api/v1/signals";
CTrade   trade;
string   g_json = "";
datetime g_fetched = 0;
ENUM_TIMEFRAMES g_tf[4] = {PERIOD_M30, PERIOD_M15, PERIOD_M10, PERIOD_M5};   // higher timeframe checked first = wins a tie

//================ JSON (fixed simple format from the API) ================
string JStr(const string o, const string k) {
   int p = StringFind(o, "\"" + k + "\":"); if (p < 0) return "";
   p += StringLen(k) + 3; if (StringGetCharacter(o, p) != '"') return "";
   int e = StringFind(o, "\"", p + 1); return e < 0 ? "" : StringSubstr(o, p + 1, e - p - 1);
}
bool JBool(const string o, const string k) { return StringFind(o, "\"" + k + "\":true") >= 0; }
bool JZone(const string o, double &lo, double &hi) {
   int p = StringFind(o, "\"zone\":["); if (p < 0) return false;
   int e = StringFind(o, "]", p); string a[];
   if (StringSplit(StringSubstr(o, p + 8, e - p - 8), ',', a) != 2) return false;
   double x = StringToDouble(a[0]), y = StringToDouble(a[1]); lo = MathMin(x, y); hi = MathMax(x, y); return lo > 0;
}
int JSignals(const string js, string &out[]) {
   ArrayResize(out, 0); int p = StringFind(js, "\"signals\":["); if (p < 0) return 0;
   int depth = 0, st = -1, n = StringLen(js);
   for (int i = p + 11; i < n; i++) {
      ushort ch = StringGetCharacter(js, i);
      if (ch == '"') { int e = StringFind(js, "\"", i + 1); if (e < 0) break; i = e; continue; }
      if (ch == '{') { if (depth == 0) st = i; depth++; }
      else if (ch == '}') { depth--; if (depth == 0 && st >= 0) { int k = ArraySize(out); ArrayResize(out, k + 1); out[k] = StringSubstr(js, st, i - st + 1); st = -1; } }
      else if (ch == ']' && depth == 0) break;
   }
   return ArraySize(out);
}
bool Fetch() {
   if (TimeCurrent() - g_fetched < 60 && g_json != "") return true;
   char post[], res[]; string hdr;
   ResetLastError();
   int code = WebRequest("GET", API_URL, "X-API-Key: " + ApiKey + "\r\nAccept: application/json\r\n", 8000, post, res, hdr);
   if (code != 200) {
      if (code == -1) Print("ChartPulse: add https://chartpulse.io in Tools > Options > Expert Advisors > Allow WebRequest (error ", GetLastError(), ")");
      else if (code == 401) Print("ChartPulse: API key not valid or expired. Get a new one at chartpulse.io/api/");
      else Print("ChartPulse: API error ", code);
      return false;
   }
   g_json = CharArrayToString(res, 0, WHOLE_ARRAY, CP_UTF8); g_fetched = TimeCurrent(); return true;
}

//================ helpers ================
string MapSymbol(const string api) {
   string m[]; int n = StringSplit(SymbolMap, ',', m);
   for (int i = 0; i < n; i++) { string kv[]; if (StringSplit(m[i], '=', kv) == 2 && kv[0] == api) return kv[1]; }
   return api + SymbolSuffix;
}
string g_hk[]; int g_hv[];                                                   // ATR handle cache (a fresh handle needs time to calculate)
double ATR(const string sym, ENUM_TIMEFRAMES tf) {
   string k = sym + "|" + (string)(int)tf; int h = INVALID_HANDLE, n = ArraySize(g_hk);
   for (int i = 0; i < n; i++) if (g_hk[i] == k) { h = g_hv[i]; break; }
   if (h == INVALID_HANDLE) { h = iATR(sym, tf, 14); if (h == INVALID_HANDLE) return 0; ArrayResize(g_hk, n + 1); ArrayResize(g_hv, n + 1); g_hk[n] = k; g_hv[n] = h; }
   double b[]; return CopyBuffer(h, 0, 1, 1, b) == 1 ? b[0] : 0;
}
int Positions(const string sym, int &total) {
   int mine = 0; total = 0;
   for (int i = PositionsTotal() - 1; i >= 0; i--) {
      ulong t = PositionGetTicket(i); if (!PositionSelectByTicket(t) || PositionGetInteger(POSITION_MAGIC) != Magic) continue;
      total++; if (PositionGetString(POSITION_SYMBOL) == sym) mine++;
   }
   return mine;
}
string DayKey() { MqlDateTime d; TimeToStruct(TimeCurrent(), d); return StringFormat("CP_day_%04d%02d%02d", d.year, d.mon, d.day); }
bool Rollover() { MqlDateTime d; TimeToStruct(TimeCurrent(), d); int m = d.hour * 60 + d.min; return m < RolloverSkipMin || m >= 1440 - RolloverSkipMin; }
string TfName(ENUM_TIMEFRAMES tf) { return tf == PERIOD_M5 ? "M5" : tf == PERIOD_M10 ? "M10" : tf == PERIOD_M15 ? "M15" : "M30"; }
bool TfOn(ENUM_TIMEFRAMES tf) { return (tf == PERIOD_M5 && TF_M5) || (tf == PERIOD_M10 && TF_M10) || (tf == PERIOD_M15 && TF_M15) || (tf == PERIOD_M30 && TF_M30); }
void Log(const string sym, const string tf, const string side, const string pat, const string result, double entry, double sl, double lots) {
   if (!LogCsv) return;
   int f = FileOpen("ChartPulse_log.csv", FILE_READ | FILE_WRITE | FILE_CSV | FILE_ANSI | FILE_SHARE_READ, ',');
   if (f == INVALID_HANDLE) return;
   if (FileSize(f) == 0) FileWrite(f, "time", "symbol", "tf", "side", "pattern", "result", "entry", "sl", "lots");
   FileSeek(f, 0, SEEK_END); FileWrite(f, TimeToString(TimeCurrent()), sym, tf, side, pat, result, entry, sl, lots); FileClose(f);
}

//================ candle patterns ================
// r[1] = last CLOSED candle, r[2], r[3], r[4] older. dir +1 buy / -1 sell. a = ATR(14) of this TF, A = daily ATR.
// All thresholds are practitioner defaults (see research report); tune on demo.
bool Strong(const MqlRates &x, int dir, double a, double minAtr) {
   double g = x.high - x.low, b = MathAbs(x.close - x.open); if (g <= 0) return false;
   bool col = dir > 0 ? x.close > x.open : x.close < x.open;
   double pos = dir > 0 ? (x.close - x.low) / g : (x.high - x.close) / g;      // close in the outer 25%
   return col && b >= 0.6 * g && pos >= 0.75 && g >= minAtr * a;
}
string Pattern(const MqlRates &r[], int dir, double a, double zlo, double zhi, double A, double tick, double &stop) {
   bool S = dir < 0;
   double tol = 0.25 * A;                                                        // how close to the zone counts as "at the zone"
   double g1 = r[1].high - r[1].low, b1 = MathAbs(r[1].close - r[1].open);
   double g2 = r[2].high - r[2].low, b2 = MathAbs(r[2].close - r[2].open);
   double g3 = r[3].high - r[3].low, b3 = MathAbs(r[3].close - r[3].open);
   if (g1 <= 0 || g2 <= 0 || g3 <= 0 || a <= 0) return "";
   // rejection-side wick and opposite (nose) wick
   double w1 = S ? r[1].high - MathMax(r[1].open, r[1].close) : MathMin(r[1].open, r[1].close) - r[1].low;
   double w2 = S ? r[2].high - MathMax(r[2].open, r[2].close) : MathMin(r[2].open, r[2].close) - r[2].low;
   double w3 = S ? r[3].high - MathMax(r[3].open, r[3].close) : MathMin(r[3].open, r[3].close) - r[3].low;
   double n1 = S ? MathMin(r[1].open, r[1].close) - r[1].low : r[1].high - MathMax(r[1].open, r[1].close);
   bool col1 = S ? r[1].close < r[1].open : r[1].close > r[1].open;
   double ext2 = S ? MathMax(r[1].high, r[2].high) : MathMin(r[1].low, r[2].low);
   double ext3 = S ? MathMax(ext2, r[3].high) : MathMin(ext2, r[3].low);
   // gate: price reached the zone and the last close is not through it (> 0.1 daily ATR beyond = breakout, skip)
   bool reached = S ? ext3 >= zlo - tol : ext3 <= zhi + tol;
   bool notBroken = S ? r[1].close <= zhi + 0.1 * A : r[1].close >= zlo - 0.1 * A;
   if (!reached || !notBroken) return "";

   // 1) Double Denial: two wick rejections within 3 bars at the same place, bodies back inside
   if (UseDoubleDenial) {
      for (int j = 2; j <= 3; j++) {
         double wj = j == 2 ? w2 : w3, gj = j == 2 ? g2 : g3, bj = j == 2 ? b2 : b3;
         double xj = S ? r[j].high : r[j].low, x1 = S ? r[1].high : r[1].low;
         bool cj = S ? r[j].close <= zhi : r[j].close >= zlo, c1 = S ? r[1].close <= zhi : r[1].close >= zlo;
         if (w1 >= 0.5 * g1 && w1 >= 1.5 * b1 && wj >= 0.5 * gj && wj >= 1.5 * bj && MathAbs(x1 - xj) <= 0.25 * a && cj && c1
             && (S ? x1 >= zlo - tol : x1 <= zhi + tol)) { stop = S ? MathMax(x1, xj) : MathMin(x1, xj); return "Double Denial"; }
      }
   }
   // 2) Morning / Evening Star (no gap needed in 24h markets): strong bar 1, small star, bar 3 closes >= 50% into bar 1 body
   if (UseStar && Strong(r[3], -dir, a, 1.0) && b2 <= 0.3 * b3 && col1 && b1 >= 0.5 * g1) {
      double mid3 = (r[3].open + r[3].close) / 2;
      double starMid = (r[2].open + r[2].close) / 2;
      bool starPlace = S ? starMid >= r[3].close - 0.05 * a : starMid <= r[3].close + 0.05 * a;   // star at/beyond bar 1 close
      if (starPlace && (S ? r[1].close <= mid3 : r[1].close >= mid3)) { stop = ext3; return S ? "Evening Star" : "Morning Star"; }
   }
   // 3) Engulfing: opposite colours, body 2 covers body 1 (1-tick tolerance on the open), B2 >= 1.2 B1, range >= 0.8 ATR
   if (UseEngulfing) {
      bool opp2 = S ? r[2].close > r[2].open : r[2].close < r[2].open;
      double t = MathMax(tick, 0.0);
      bool cover = S ? (r[1].open >= r[2].close - t && r[1].close < r[2].open) : (r[1].open <= r[2].close + t && r[1].close > r[2].open);
      if (opp2 && col1 && cover && b1 >= 1.2 * b2 && g1 >= 0.8 * a) { stop = ext2; return S ? "Bearish Engulfing" : "Bullish Engulfing"; }
   }
   // 4) Pin Bar / Hammer / Shooting Star: body <= 33%, wick >= 2x body and >= 60% range, nose <= 15%, range >= 1 ATR, sticks out past 3 bars
   if (UsePinBar && b1 <= 0.33 * g1 && w1 >= 2 * b1 && w1 >= 0.6 * g1 && n1 <= 0.15 * g1 && g1 >= 1.0 * a) {
      bool out = S ? r[1].high > MathMax(r[2].high, MathMax(r[3].high, r[4].high)) : r[1].low < MathMin(r[2].low, MathMin(r[3].low, r[4].low));
      if (out) { stop = S ? r[1].high : r[1].low; return S ? "Shooting Star" : "Hammer"; }
   }
   // 5) 3-Bar Play: igniting bar (>= 1.5 ATR) in trade direction, 1-2 small pause bars holding its outer half, breakout close
   if (Use3BarPlay && col1 && b1 >= 0.6 * g1) {
      for (int k = 3; k <= 4; k++) {                     // igniting bar at r[k], pause bars r[k-1]..r[2]
         if (!Strong(r[k], dir, a, 1.5)) continue;
         double half = (r[k].high + r[k].low) / 2, gk = r[k].high - r[k].low; bool ok = true; double pHi = -DBL_MAX, pLo = DBL_MAX;
         for (int p = k - 1; p >= 2; p--) {
            if (r[p].high - r[p].low > 0.5 * gk) { ok = false; break; }
            if (S ? r[p].high > half : r[p].low < half) { ok = false; break; }
            pHi = MathMax(pHi, r[p].high); pLo = MathMin(pLo, r[p].low);
         }
         if (!ok) continue;
         bool start = S ? r[k].high >= zlo - tol : r[k].low <= zhi + tol;                      // the move started at the zone
         if (start && (S ? r[1].close < pLo : r[1].close > pHi)) { stop = S ? pHi : pLo; return "3-Bar Play"; }
      }
   }
   return "";
}

//================ order ================
double LotsFor(const string sym, int dir, double entry, double sl) {
   double loss1 = 0; ENUM_ORDER_TYPE t = dir > 0 ? ORDER_TYPE_BUY : ORDER_TYPE_SELL;
   if (!OrderCalcProfit(t, sym, 1.0, entry, sl, loss1) || loss1 == 0) return 0;        // money lost by 1 lot at the stop
   double risk = AccountInfoDouble(ACCOUNT_EQUITY) * RiskPercent / 100.0;
   double step = SymbolInfoDouble(sym, SYMBOL_VOLUME_STEP), vmin = SymbolInfoDouble(sym, SYMBOL_VOLUME_MIN), vmax = SymbolInfoDouble(sym, SYMBOL_VOLUME_MAX);
   if (step <= 0) return 0;
   double lots = MathFloor(risk / MathAbs(loss1) / step + 1e-8) * step;
   lots = MathMin(lots, vmax); if (lots < vmin) return 0;
   double margin = 0; if (!OrderCalcMargin(t, sym, lots, entry, margin) || margin > AccountInfoDouble(ACCOUNT_MARGIN_FREE)) return 0;
   return NormalizeDouble(lots, 2);
}

bool TryEntry(const string sym, const string side, const string id, double zlo, double zhi) {
   int dir = side == "SELL" ? -1 : 1;
   double A = ATR(sym, PERIOD_D1); if (A <= 0) return false;
   double tick = SymbolInfoDouble(sym, SYMBOL_TRADE_TICK_SIZE);
   for (int i = 0; i < 4; i++) {
      ENUM_TIMEFRAMES tf = g_tf[i]; if (!TfOn(tf)) continue;
      MqlRates r[]; ArraySetAsSeries(r, true);
      if (CopyRates(sym, tf, 0, 8, r) < 8) continue;                                       // history not ready yet: next timer
      if (TimeCurrent() - r[0].time > EntryWindowSec) continue;                            // this TF did not just close a candle
      string bk = "CP_bar_" + sym + "_" + side + "_" + TfName(tf);
      if (GlobalVariableGet(bk) == (double)r[1].time) continue; GlobalVariableSet(bk, (double)r[1].time);
      double a = ATR(sym, tf); if (a <= 0) continue;
      double pstop = 0; string pat = Pattern(r, dir, a, zlo, zhi, A, tick, pstop);
      if (pat == "") continue;
      string tfn0 = TfName(tf);
      if (DayExtremeOnly) {                                                                // bear: sell from the day high, bull: buy from the day low
         double dh = iHigh(sym, PERIOD_D1, 0), dl = iLow(sym, PERIOD_D1, 0);
         double px = dir < 0 ? MathMax(r[1].high, MathMax(r[2].high, r[3].high)) : MathMin(r[1].low, MathMin(r[2].low, r[3].low));
         bool nearX = dir < 0 ? px >= dh - DayExtremeATR * A : px <= dl + DayExtremeATR * A;
         if (dh <= 0 || dl <= 0 || !nearX) { Log(sym, tfn0, side, pat, "skip not at day " + (dir < 0 ? "high" : "low"), 0, 0, 0); continue; }
      }
      double ask = SymbolInfoDouble(sym, SYMBOL_ASK), bid = SymbolInfoDouble(sym, SYMBOL_BID), spr = ask - bid;
      double entry = dir > 0 ? ask : bid;
      int dg = (int)SymbolInfoInteger(sym, SYMBOL_DIGITS);
      double sl = NormalizeDouble(dir > 0 ? pstop - BufferATR * a - spr : pstop + BufferATR * a + spr, dg);
      double pt = SymbolInfoDouble(sym, SYMBOL_POINT), lvl = (double)SymbolInfoInteger(sym, SYMBOL_TRADE_STOPS_LEVEL) * pt;
      string tfn = TfName(tf);
      if (spr > MaxSpreadATR * a) { Log(sym, tfn, side, pat, "skip spread", entry, sl, 0); continue; }
      if ((dir > 0 && sl >= entry) || (dir < 0 && sl <= entry)) { Log(sym, tfn, side, pat, "skip stop side", entry, sl, 0); continue; }
      if (MathAbs(entry - sl) < lvl) sl = NormalizeDouble(dir > 0 ? entry - lvl - pt : entry + lvl + pt, dg);
      if (MathAbs(entry - sl) > MaxStopATRDaily * A) { Log(sym, tfn, side, pat, "skip stop wide", entry, sl, 0); continue; }
      double lots = LotsFor(sym, dir, entry, sl);
      if (lots <= 0) { Log(sym, tfn, side, pat, "skip size/margin", entry, sl, 0); continue; }
      PrintFormat("ChartPulse: %s %s %s %s | zone %.5f-%.5f | SL %.5f | %.2f lots%s", sym, tfn, side, pat, zlo, zhi, sl, lots, TradingEnabled ? "" : " (watch only)");
      if (!TradingEnabled) { Log(sym, tfn, side, pat, "watch only", entry, sl, lots); return true; }
      trade.SetExpertMagicNumber(Magic); trade.SetDeviationInPoints(30); trade.SetTypeFillingBySymbol(sym);
      string cmt = "CP " + tfn + " " + pat;
      bool ok = dir > 0 ? trade.Buy(lots, sym, 0, sl, 0, cmt) : trade.Sell(lots, sym, 0, sl, 0, cmt);   // TP = 0: exit is manual
      if (ok && (trade.ResultRetcode() == TRADE_RETCODE_DONE || trade.ResultRetcode() == TRADE_RETCODE_PLACED)) {
         GlobalVariableSet("CP_id_" + id, (double)TimeCurrent());
         GlobalVariableSet("CP_cool_" + sym, (double)TimeCurrent());
         GlobalVariableSet(DayKey(), GlobalVariableGet(DayKey()) + 1);
         Log(sym, tfn, side, pat, "ENTERED", entry, sl, lots); return true;
      }
      PrintFormat("ChartPulse: order failed %u %s", trade.ResultRetcode(), trade.ResultRetcodeDescription());
      Log(sym, tfn, side, pat, "order failed " + (string)trade.ResultRetcode(), entry, sl, lots); return false;
   }
   return false;
}

void Process(const string o) {
   string api = JStr(o, "mt5"), side = JStr(o, "side"), id = JStr(o, "id");
   double zlo, zhi; if (api == "" || id == "" || !JZone(o, zlo, zhi) || JBool(o, "news_block")) return;
   int src = 0;
   if (UseConfluence && StringFind(o, "\"confluence\"") >= 0) src++;
   if (UseGodfather  && StringFind(o, "\"godfather\"")  >= 0) src++;
   if (UseDayDrive   && StringFind(o, "\"daydrive\"")   >= 0) src++;
   if (UseReversal   && StringFind(o, "\"reversal\"")   >= 0) src++;
   if (src < MathMax(1, MinSources) || GlobalVariableCheck("CP_id_" + id)) return;
   string sym = MapSymbol(api); if (!SymbolSelect(sym, true)) return;
   int total; if (Positions(sym, total) > 0 || (MaxOpenTrades > 0 && total >= MaxOpenTrades)) return;            // one position per symbol, all timeframes
   if (GlobalVariableCheck("CP_cool_" + sym) && TimeCurrent() - (datetime)GlobalVariableGet("CP_cool_" + sym) < CooldownMin * 60) return;
   if (MaxTradesPerDay > 0 && GlobalVariableGet(DayKey()) >= MaxTradesPerDay) return;
   TryEntry(sym, side, id, zlo, zhi);
}

int OnInit() {
   if (StringLen(ApiKey) < 32) { Alert("ChartPulse: paste your API key (chartpulse.io/api/) into the ApiKey input."); return INIT_PARAMETERS_INCORRECT; }
   EventSetTimer(10); Print("ChartPulse Auto-Entry v3 started: D+W bias, sell day high / buy day low, M5-M30 patterns, one position per symbol.");
   return INIT_SUCCEEDED;
}
void OnDeinit(const int reason) { EventKillTimer(); for (int i = 0; i < ArraySize(g_hv); i++) IndicatorRelease(g_hv[i]); }
void OnTimer() {
   if (Rollover() || !Fetch()) return;
   if (StringFind(g_json, "\"halt\":true") >= 0) return;                                  // server kill switch
   string s[]; int n = JSignals(g_json, s);
   for (int i = 0; i < n; i++) Process(s[i]);
}
