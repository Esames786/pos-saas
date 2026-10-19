<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Saas\BillingRates;
use App\Services\Saas\UsageInvoiceService;
use App\Services\Tenancy\TenancyManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 (Phase 4b) — Bingoo ka 1%: website orders ki net sale, usi mahana usage invoice me
 * jis me WhatsApp hai (alag lines, alag subtotal).
 *
 * Malik (14 Oct): sirf website ke orders; poori raqam (items + delivery + tax); returns minus. Har raat
 * khule mahinon ka record dobara banta hai — order cancel ho ya return aaye to khula mahina khud theek.
 * Band mahina nahi hilta; us ke baad ka return apne din (agle mahine) par minus.
 */
class BillingWebsiteCommissionCommand extends Command
{
    protected $signature = 'billing:website-commission
        {--month= : YYYY-MM. Default: pichhla aur chalta mahina}
        {--tenant= : Sirf is tenant_code par}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Website orders ka 1% mahana usage invoice me (default: dry run).';

    public function handle(TenancyManager $tenancy, UsageInvoiceService $usage, BillingRates $rates): int
    {
        $write = (bool) $this->option('yes');
        if ($month = $this->option('month')) {
            $from = CarbonImmutable::parse($month.'-01');
            $to = $from->endOfMonth();
        } else {
            // Tenant ka business din UTC se aage ho sakta hai (Karachi +5) — aaj ka poora mahina.
            $from = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
            $to = CarbonImmutable::now()->addDay()->endOfMonth();
        }
        $percent = $rates->websiteCommissionPercent();

        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $code) => $q->where('tenant_code', $code))
            ->orderBy('id')->get();

        $grand = 0.0;
        foreach ($tenants as $tenant) {
            try {
                $tenancy->activate($tenant);
                if (! Schema::connection('tenant')->hasColumn('sales_orders', 'web_order_key')) {
                    continue;
                }
                ['rows' => $rows, 'late' => $late] = $usage->websiteChargesFor($tenant, $from, $to, $percent);
                if ($rows->isEmpty() && $late === 0 && ! $write) {
                    continue;
                }

                foreach ($rows->groupBy(fn ($r) => substr($r['business_date'], 0, 7)) as $ym => $m) {
                    $sales = $m->where('kind', 'sale');
                    $returns = $m->where('kind', 'return');
                    $this->line(sprintf('  %-16s %s   %3d orders  sale %12s   returns %10s   net %12s   × %s%% = %10s',
                        $tenant->tenant_code, $ym, $sales->count(),
                        number_format($sales->sum('net_amount'), 2), number_format(-$returns->sum('net_amount'), 2),
                        number_format($m->sum('net_amount'), 2), rtrim(rtrim(number_format($percent, 3), '0'), '.'),
                        number_format($m->sum('amount'), 2)));
                    $grand += (float) $m->sum('amount');
                }
                if ($late > 0) {
                    $this->warn("  {$tenant->tenant_code}: {$late} row(s) band mahine ke din par — likhi nahi gayin.");
                }

                if ($write) {
                    $usage->writeWebsiteCharges($tenant, $from, $to, $rows);
                }
            } catch (\Throwable $e) {
                // Aik tenant ki kharabi baqi ko na roke.
                report($e);
                $this->warn("{$tenant->tenant_code}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info('KUL commission: PKR '.number_format($grand, 2));
        if (! $write) {
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');
        }

        return self::SUCCESS;
    }
}
