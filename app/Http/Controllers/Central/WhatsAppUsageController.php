<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use App\Models\Master\WhatsAppMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SAAS-BILLING-WHATSAPP-1 — WhatsApp ke paise, aik safhe par.
 *
 * Is se pehle ye ginti sirf aik command se nazar aati thi, yani malik ko har baar poochna paRta.
 * Wohi baat WhatsApp ki settings ke saath bhi hui thi aur malik ne theek hi kaha tha ke "nazar nahi
 * ari" — jo cheez sirf mere chalane se dikhti hai, wo un ki nahi hai.
 *
 * Safha teen sawalon ka jawab deta hai: kitna bana, kitna hamara kharcha hua, aur kiska abhi khula
 * hai. Laagat aur margin jaan boojh kar saath dikhte hain — rate 9.85 hai aur laagat badalti rehti
 * hai (dollar, tax), to margin maana nahi jana chahiye, nazar aana chahiye.
 */
class WhatsAppUsageController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->filled('month')
            ? CarbonImmutable::parse($request->string('month').'-01')
            : CarbonImmutable::now()->startOfMonth();

        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();

        // Per tenant, is mahine ka. Raqam har row ke APNE rate se jurti hai, ginti × aik rate nahi:
        // rate beech mahine badle to purana hisaab nahi hilna chahiye.
        $rows = DB::connection('master')->table('whatsapp_messages as m')
            ->join('tenants as t', 't.id', '=', 'm.tenant_id')
            ->whereBetween('m.usage_date', [$from, $to])
            ->groupBy('t.id', 't.tenant_code', 't.business_name')
            ->selectRaw('t.id, t.tenant_code, t.business_name,
                         COUNT(*) as messages,
                         SUM(m.status = "failed") as failed,
                         SUM(m.rate_charged) as amount,
                         SUM(m.provider_cost) as cost,
                         COUNT(DISTINCT m.usage_date) as days,
                         SUM(m.invoice_id IS NULL) as unbilled')
            ->orderByDesc('amount')
            ->get();

        // Rozana — malik ne "per day ki cost" maangi thi.
        $daily = DB::connection('master')->table('whatsapp_messages as m')
            ->join('tenants as t', 't.id', '=', 'm.tenant_id')
            ->whereBetween('m.usage_date', [$from, $to])
            ->groupBy('m.usage_date', 't.tenant_code')
            ->selectRaw('m.usage_date, t.tenant_code, COUNT(*) as messages,
                         SUM(m.status = "failed") as failed, SUM(m.rate_charged) as amount')
            ->orderByDesc('m.usage_date')
            ->get()
            ->groupBy(fn ($r) => (string) $r->usage_date);

        $invoices = SubscriptionInvoice::with('tenant')
            ->where('invoice_type', 'addon')
            ->orderByDesc('period_start')
            ->get();

        // Kaun kaun sa mahina maujood hai — khaali mahine dikhane ka faida nahi.
        $months = DB::connection('master')->table('whatsapp_messages')
            ->selectRaw('DISTINCT DATE_FORMAT(usage_date, "%Y-%m") as ym')
            ->orderByDesc('ym')->pluck('ym');

        return view('central.whatsapp-usage.index', [
            'month' => $month,
            'months' => $months,
            'rows' => $rows,
            'daily' => $daily,
            'invoices' => $invoices,
            'rate' => (float) config('services.whatsapp.rate_pkr'),
            'totals' => [
                'messages' => (int) $rows->sum('messages'),
                'failed' => (int) $rows->sum('failed'),
                'amount' => (float) $rows->sum('amount'),
                'cost' => (float) $rows->sum('cost'),
                'unbilled' => (int) $rows->sum('unbilled'),
            ],
            'tenantsOn' => Tenant::where('status', 'active')->get()
                ->filter(fn ($t) => in_array('whatsapp', array_map('strtolower', (array) ($t->report_channels ?? [])), true))
                ->map(fn ($t) => [
                    'code' => $t->tenant_code,
                    'numbers' => count((array) ($t->report_whatsapp ?? [])),
                ])->values(),
        ]);
    }
}
