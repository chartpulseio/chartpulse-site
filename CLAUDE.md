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

These files must stay byte-identical to the commit that made that change unless the owner explicitly asks for a Day Drive change:

    dd_engine.php    65292eb5e406...
    dd_api.php       3a7e1ce6cbf8...
    day-drive.html   9f76d41293ab...
    gf_engine.php    2db702737873...   (reads cp_data/daydrive/rows.json)
    bp_engine.php    106aa7e02e12...   (reads cp_data/daydrive/rows.json, uses status BOUNCE GO)

Check with: `sha256sum dd_engine.php dd_api.php day-drive.html gf_engine.php bp_engine.php`
If an owner request seems to need a change here, ask the owner first and quote this note.
Full note: claude.ai Project "HOST" → claude/chartpulse-day-drive-LOCKED-14nov.md
