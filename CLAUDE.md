# ChartPulse repo — rules for every session

## DAY DRIVE IS LOCKED (owner, 14 Nov 2026; re-locked 17 Nov 2026 after the owner-approved reversal update)
The correct Day Drive is the merged version: trend setups + Bounce (top/bottom reversal) in ONE list.
Do NOT split it, do NOT remove Bounce, do NOT restore the trend-only version (commit 227df03) or the split version (e56e556).
Do NOT rewrite day-drive.html for SEO in a way that changes the scanner.

Owner-approved rule 14 Nov 2026 (01:30 IST, replaces the 01:05 and 01:14 ATR rules): a bounce needs a DAILY trend line (resistance for PUT, support for CALL) within 1 x daily ATR of today's high/low, wide so the signal lasts 2-3 days. The ATR zone (open +/- 1 ATR, 0.5 wide) and a weekly line at the same spot are labels only. Key zones are labels, never filters. Ranking (01:59 IST): all bounces (GO + READY) together, closest to the daily line first.

Owner-approved update 17 Nov 2026 (owner unlocked Day Drive for it): Day Drive's whole attention is the REVERSAL at the daily trend line.
- Touch = today's extreme on the line. Fake-out = extreme went through the line, price now within 0.5 x daily ATR beyond it (or back inside); price more than 0.5 ATR beyond = breakout, NOT ranked (breakouts are not in the syllabus). Coming back inside 0.5 ATR makes it a fake-out again.
- Reversal ladder: 1 at the line, 2 rejection candle (5m/15m long wick or engulfing), 3 5m CHoCH (= BOUNCE GO), 4 retest held (higher low / lower high after the CHoCH).
- Confirmations are labels only (key zone, weekly line, small trend turned, 5m RSI divergence, volume spike). No reward:risk filter: every reversal is ranked.
- Ranking: all reversals above the trend setups. Fake-out+retest > fake-out+CHoCH > touch+retest > touch+CHoCH > rejection (fake, touch) > at line (fake, touch); then confirmations, line touches, distance to the line.
- The bounce card replaces the trend 3-check / x/100 card on reversal rows.

Owner-approved update 26 Nov 2026 (unlocked for it, then re-locked): GEX label only. dd_api.php wraps its output with dd_gex() (adds a 'gex' field from gex_engine.php for SPY/QQQ/IWM/US mega-caps, NIFTY, BANKNIFTY, FINNIFTY) and day-drive.html shows it as one extra chip (green when it fits: +γ for reversals, −γ for trend). It never filters, ranks or changes a signal. dd_engine.php, gf_engine.php and bp_engine.php were NOT touched. gf_api.php / godfather.html got the same label (God-father is not on this lock list).

Owner-approved update 26 Nov 2026, 22:56 IST (owner asked for it): the Day Drive symbol detail page embeds the GEX chart (iframe /scanners/gex/?embed=1#SYM) with the regime chip, call/put wall and zero gamma, for symbols that have a GEX label. Display only; no signal, filter or ranking change.

Owner decision 26 Nov 2026, 23:50 IST (option A): trend lines v3 (tl_engine.php: multi-level zigzag, wick anchors, sweeps, body/2-close break, 20-year history via tl3_long) is the ONLY trend-line engine; v1 and v2 were removed. Day Drive reads its daily line from it through tl_row, so its bounce rows follow v3 lines. No Day Drive file changed.

Owner decision 27 Nov 2026, 21:43 IST: the old God-father (gf_engine.php, gf_api.php, godfather.html) was DELETED permanently. /scanners/godfather/ now serves God-father v2 (gf2_engine.php, gf2_api.php, godfather-v2.html): Daily+Weekly bias, one vote per group (Location = best of Confluence / Hero + day high/low + 1H 200 EMA), trap-first setup, order flow only lifts A to A+, A+/A logged and graded. Telegram God-father alerts now come from v2. Day Drive files untouched.

These files must stay byte-identical to the commit that made that change unless the owner explicitly asks for a Day Drive change:

    dd_engine.php    65292eb5e406...
    dd_api.php       48941d614c63...
    day-drive.html   3782c54ffb97...
    bp_engine.php    106aa7e02e12...   (reads cp_data/daydrive/rows.json, uses status BOUNCE GO)

Check with: `sha256sum dd_engine.php dd_api.php day-drive.html bp_engine.php`
If an owner request seems to need a change here, ask the owner first and quote this note.
Full note: claude.ai Project "HOST" → claude/chartpulse-day-drive-LOCKED-14nov.md
