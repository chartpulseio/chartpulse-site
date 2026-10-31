<?php
// ---------------------------------------------------------------------------
// ChartPulse pricing: the same rules as pricing_config.py, and the only place the
// server takes a price from (the page shows it, pay.php charges it).
// Optional overrides in config.php under 'pricing' (see tp_cfg()).
// ---------------------------------------------------------------------------
declare(strict_types=1);

const TP_PERIODS = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];
const TP_LABELS = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'yearly' => 'Yearly'];

/** Pricing settings: defaults below, any key can be overridden in config.php 'pricing'. */
function tp_cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $d = [
        'usd_inr' => 96.5,                 // reference USD->INR rate, update periodically
        'region_gap' => 0.25,              // India discount off the direct conversion
        'discounts' => ['monthly' => 0.0, 'quarterly' => 0.10, 'half_yearly' => 0.20, 'yearly' => 0.30],
        'products' => [
            'tier1' => ['label' => 'Starter', 'tier' => 'Tier 1', 'usd' => 49, 'kind' => 'plan', 'available' => true],
            'tier2' => ['label' => 'Core', 'tier' => 'Tier 2', 'usd' => 99, 'kind' => 'plan', 'available' => true],
            'tier3_algo' => ['label' => 'Algo', 'tier' => 'Tier 3', 'usd' => 270, 'kind' => 'plan', 'available' => false],
            'tv_setup' => ['label' => 'TradingView Setup', 'tier' => 'Add-on', 'usd' => 98, 'kind' => 'addon', 'available' => true],
        ],
        'courses_usd' => 2500,
        'courses_inr' => 200000,           // null = derive from the formula
        'trial_signals' => null,           // e.g. 10 -> "your first 10 live signals free"; null = count not shown
        'usd_checkout' => true,            // needs International payments enabled on Razorpay
    ];
    $o = function_exists('t_cfg') ? (array)t_cfg('pricing', []) : [];
    foreach ($o as $k => $v) {
        if ($k === 'products' && is_array($v)) { foreach ($v as $id => $p) if (is_array($p)) $d['products'][$id] = array_merge($d['products'][$id] ?? [], $p); }
        elseif ($k === 'discounts' && is_array($v)) $d['discounts'] = array_merge($d['discounts'], $v);
        else $d[$k] = $v;
    }
    return $c = $d;
}
/** Round once to a clean price: whole dollars for USD, rupees ending in 9 for INR. */
function tp_clean(float $a, string $cur): int { return $cur === 'USD' ? (int)round($a) : max(9, (int)round($a / 10) * 10 - 1); }
function tp_valid(string $id, string $period, string $cur): bool {
    return isset(tp_cfg()['products'][$id], TP_PERIODS[$period]) && in_array($cur, ['USD', 'INR'], true);
}
function tp_monthly(string $id, string $cur): int {
    $c = tp_cfg(); $usd = (float)$c['products'][$id]['usd'];
    return $cur === 'USD' ? tp_clean($usd, 'USD') : tp_clean($usd * (float)$c['usd_inr'] * (1 - (float)$c['region_gap']), 'INR');
}
/** Total charged for the whole billing period. */
function tp_price(string $id, string $period, string $cur): int {
    $m = TP_PERIODS[$period]; $base = tp_monthly($id, $cur);
    return $m === 1 ? $base : tp_clean($base * $m * (1 - (float)(tp_cfg()['discounts'][$period] ?? 0)), $cur);
}
function tp_courses(string $cur): int {
    $c = tp_cfg();
    if ($cur === 'USD') return (int)$c['courses_usd'];
    return $c['courses_inr'] !== null ? (int)$c['courses_inr'] : tp_clean((float)$c['courses_usd'] * (float)$c['usd_inr'] * (1 - (float)$c['region_gap']), 'INR');
}
/** Full table for the page. */
function tp_table(): array {
    $c = tp_cfg(); $out = ['periods' => [], 'products' => [], 'courses' => ['USD' => tp_courses('USD'), 'INR' => tp_courses('INR')],
        'trial_signals' => $c['trial_signals'], 'usd_checkout' => (bool)$c['usd_checkout']];
    foreach (TP_PERIODS as $p => $m) $out['periods'][] = ['id' => $p, 'label' => TP_LABELS[$p], 'months' => $m, 'discount' => (float)($c['discounts'][$p] ?? 0)];
    foreach ($c['products'] as $id => $p) {
        $row = ['id' => $id, 'label' => (string)$p['label'], 'tier' => (string)$p['tier'], 'kind' => (string)$p['kind'], 'available' => (bool)$p['available'], 'prices' => []];
        foreach (['USD', 'INR'] as $cur) foreach (TP_PERIODS as $per => $m) {
            $tot = tp_price($id, $per, $cur); $list = tp_monthly($id, $cur) * $m;
            $row['prices'][$cur][$per] = ['total' => $tot, 'per_month' => round($tot / $m, 2), 'list' => $list, 'save' => $list - $tot,
                'save_pct' => $list ? (int)round(($list - $tot) / $list * 100) : 0];
        }
        $out['products'][] = $row;
    }
    return $out;
}
