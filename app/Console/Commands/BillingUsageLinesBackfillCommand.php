<?php

namespace App\Console\Commands;

use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionInvoiceLine;
use App\Services\Saas\UsageInvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 (Phase 4b) — pehle se bani WhatsApp invoices ko din-wise lines do.
 *
 * Lines aane se pehle invoice ka total seedha messages se likha jata tha. Ab total = SUM(lines). Purani
 * invoices ki lines unhi ke messages se banti hain, aur SABOOT ye ke har invoice ka SUM(lines) us ke
 * maujooda total ke barabar ho — aik bhi na mile to kuch nahi likha jata. Total ko ye command haath
 * nahi lagata; sirf lines likhta hai.
 */
class BillingUsageLinesBackfillCommand extends Command
{
    protected $signature = 'billing:usage-lines-backfill
        {--tenant= : Sirf is tenant_code ki invoices}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Purani usage (WhatsApp) invoices ki din-wise lines — SUM(lines) = total ke saboot ke saath.';

    public function handle(UsageInvoiceService $usage): int
    {
        $invoices = SubscriptionInvoice::with('tenant')->where('invoice_type', 'addon')->where('status', '!=', 'void')
            ->whereDoesntHave('lines')
            ->when($this->option('tenant'), fn ($q, $code) => $q->whereHas('tenant', fn ($t) => $t->where('tenant_code', $code)))
            ->orderBy('id')->get();

        if ($invoices->isEmpty()) {
            $this->info('Har usage invoice ki lines pehle se hain.');

            return self::SUCCESS;
        }

        $bad = 0;
        foreach ($invoices as $i) {
            $fromMessages = round((float) DB::connection('master')->table('whatsapp_messages')->where('invoice_id', $i->id)->sum('rate_charged'), 2);
            $ok = abs($fromMessages - (float) $i->subtotal) < 0.005;
            $bad += $ok ? 0 : 1;
            $this->line(sprintf('  %-16s %-18s %-8s %s   total %10s   messages se %10s   %s',
                $i->tenant?->tenant_code ?? '?', $i->invoice_no, $i->status, $i->period_start?->format('Y-m'),
                number_format((float) $i->subtotal, 2), number_format($fromMessages, 2), $ok ? 'OK' : 'FARQ'));
        }

        if ($bad > 0) {
            $this->error("{$bad} invoice ka total us ke messages se nahi milta — kuch nahi likha. Pehle farq samjho.");

            return self::FAILURE;
        }
        if (! $this->option('yes')) {
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');

            return self::SUCCESS;
        }

        DB::connection('master')->transaction(function () use ($invoices, $usage) {
            foreach ($invoices as $i) {
                $usage->rebuildWhatsAppLines($i);
                $usage->rebuildWebsiteLines($i);
                $sum = round((float) SubscriptionInvoiceLine::where('invoice_id', $i->id)->sum('amount'), 2);
                if (abs($sum - (float) $i->subtotal) >= 0.005) {
                    throw new \RuntimeException("{$i->invoice_no}: SUM(lines) {$sum} ≠ total {$i->subtotal} — sab wapas.");
                }
            }
        });

        $this->info($invoices->count().' invoice ki lines likhi gayin; har aik ka SUM(lines) = total.');

        return self::SUCCESS;
    }
}
