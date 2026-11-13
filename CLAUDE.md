# ChartPulse repo — rules for every session

## DAY DRIVE IS LOCKED (owner, 14 Nov 2026)
The correct Day Drive is the merged version: trend setups + Bounce (top/bottom reversal) in ONE list.
Do NOT split it, do NOT remove Bounce, do NOT restore the trend-only version (commit 227df03) or the split version (e56e556).
Do NOT rewrite day-drive.html for SEO in a way that changes the scanner.

Owner-approved rule 14 Nov 2026 (01:30 IST, replaces the 01:05 and 01:14 ATR rules): a bounce needs a DAILY trend line (resistance for PUT, support for CALL) within 1 x daily ATR of today's high/low, wide so the signal lasts 2-3 days. The ATR zone (open +/- 1 ATR, 0.5 wide) and a weekly line at the same spot are labels only. Key zones are labels, never filters.
These files must stay byte-identical to the commit that made that change unless the owner explicitly asks for a Day Drive change:

    dd_engine.php    a42656bb5594...
    dd_api.php       3a7e1ce6cbf8...
    day-drive.html   2b60ac29cbb3...
    gf_engine.php    2db702737873...   (reads cp_data/daydrive/rows.json)
    bp_engine.php    106aa7e02e12...   (reads cp_data/daydrive/rows.json, uses status BOUNCE GO)

Check with: `sha256sum dd_engine.php dd_api.php day-drive.html gf_engine.php bp_engine.php`
If an owner request seems to need a change here, ask the owner first and quote this note.
Full note: claude.ai Project "HOST" → claude/chartpulse-day-drive-LOCKED-14nov.md
