{{--
    WEBSITE-I18N-GEO-1 P2 — the per-branch arithmetic for the browser, shared by the plan builder and
    the checkout so the two can never disagree. It mirrors PlanPricingService::quote() (cents, discount
    steps, ten-month year) for DISPLAY only; every figure that matters is priced again on the server.
    Pushed once per page.
--}}
@once
@push('scripts')
<script>
window.BingooQuote = (function () {
    function fmt(n) {
        var cents = Math.round(n * 100);
        return new Intl.NumberFormat('en-US', { minimumFractionDigits: cents % 100 ? 2 : 0, maximumFractionDigits: 2 }).format(cents / 100);
    }
    // Same shape as App\Support\PublicMoney::format(): "SAR 549", "$169", "549 ر.س", isolated for RTL.
    function money(D, n, neg) {
        var body = D.label.prefix ? D.label.text + (D.label.text === '$' ? '' : ' ') + fmt(n) : fmt(n) + ' ' + D.label.text;
        return '⁨' + (neg ? '−' : '') + body + '⁩';
    }
    function percent(D, branches) {
        var p = 0;
        D.discounts.forEach(function (d) { if (branches >= d[0]) p = Math.max(p, d[2]); });
        return p;
    }
    function quote(D, plan, branches, extra) {
        var branchC = Math.round(plan.unit * 100) * branches, extraC = Math.round(plan.extra * 100) * extra;
        var subC = branchC + extraC, pct = percent(D, branches), discC = Math.round(subC * pct / 100), monthC = subC - discC;
        return {
            branch: branchC / 100, extra: extraC / 100, sub: subC / 100, pct: pct, disc: discC / 100,
            month: monthC / 100, year: monthC * D.yearlyMonths / 100, saving: monthC * (12 - D.yearlyMonths) / 100,
            terminals: branches * plan.tpb + extra, users: branches * plan.upb
        };
    }
    function t(D, key, vars) {
        var s = D.labels[key] || key;
        Object.keys(vars || {}).forEach(function (k) { s = s.split(':' + k).join(vars[k]); });
        return s;
    }
    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }
    return { fmt: fmt, money: money, percent: percent, quote: quote, t: t, esc: esc };
})();
</script>
@endpush
@endonce
