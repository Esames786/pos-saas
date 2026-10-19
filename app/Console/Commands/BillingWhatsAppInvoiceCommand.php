<?php

namespace App\Console\Commands;

use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use App\Models\Master\WhatsAppMessage;
use App\Services\Saas\SubscriptionBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SAAS-BILLING-WHATSAPP-1 — WhatsApp usage ki KHULI invoice, jo roz barhti hai.
 *
 * Malik ka faisla: subscription aur WhatsApp ke invoice ALAG. Plan pakka hai, usage badalta hai —
 * usage par koi ikhtelaf ho to plan ki adaegi us me nahi phansti, aur plan hi asal paisa hai
 * (105,000 vs ~900).
 *
 * Shakl: har mahine ki AIK `draft` invoice jo roz barhti hai, aur mahina khatam hone par band hoti
 * hai. Do ghaltiyan is se bachti hain:
 *
 *  - Aik invoice jo kabhi band na ho, kabhi qabil-e-adaegi nahi hoti: na due date, na overdue, aur
 *    adaegi ke baad bhi barhti rahe to `paid_amount` kabhi `total_amount` ko nahi chhuyega.
 *  - Har din ki alag invoice = mahine me 30 invoice per tenant — wohi "bht sari invoice" jis se
 *    malik bachna chahte hain.
 *
 * Yani: data roz, invoice mahine me aik.
 *
 * Har message ki row apne invoice se `invoice_id` ke zariye bandh jati hai. Isi se ye command dobara
 * chalane par ginti dugni nahi hoti, aur isi se kal koi poochhe "ye 925.90 kahan se aaye" to jawab
 * row-dar-row mojood hota hai.
 */
class BillingWhatsAppInvoiceCommand extends Command
{
    protected $signature = 'billing:whatsapp-invoice
        {--month= : YYYY-MM. Default: har wo mahina jis ki koi row abhi kisi invoice se nahi juRi}
        {--close : Us mahine ki draft invoice band kar do (draft → issued)}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'WhatsApp usage ki mahana draft invoice kholta/barhata hai (default: dry run).';

    public function handle(SubscriptionBillingService $billing): int
    {
        $write = (bool) $this->option('yes');
        $close = (bool) $this->option('close');

        // Jo rows abhi kisi invoice se nahi juRin. Rate row par likha hua hai, settings se nahi
        // parha jata — kal rate badle to purana invoice nahi hilna chahiye.
        $q = WhatsAppMessage::billable()->whereNull('invoice_id');
        if ($month = $this->option('month')) {
            $start = CarbonImmutable::parse($month.'-01');
            $q->whereBetween('usage_date', [$start->toDateString(), $start->endOfMonth()->toDateString()]);
        }

        $groups = $q->get()->groupBy(fn ($m) => $m->tenant_id.'|'.$m->usage_date->format('Y-m'));

        if ($groups->isEmpty()) {
            $this->info('Koi nayi usage nahi — har row pehle se kisi invoice se juRi hui hai.');
        }

        $planned = [];
        foreach ($groups as $key => $rows) {
            [$tenantId, $ym] = explode('|', $key);
            $tenant = Tenant::find((int) $tenantId);
            if (! $tenant) {
                continue;
            }

            $start = CarbonImmutable::parse($ym.'-01');
            $planned[] = [
                'tenant' => $tenant,
                'ym' => $ym,
                'start' => $start,
                'end' => $start->endOfMonth(),
                'rows' => $rows,
                'count' => $rows->count(),
                // Har row ka apna rate jorha jata hai, ginti × aik rate NAHI. Beech mahine rate
                // badle to wo farq yahan apne aap theek baithta hai.
                'amount' => (float) $rows->sum('rate_charged'),
                'cost' => (float) $rows->sum('provider_cost'),
            ];
        }

        foreach ($planned as $p) {
            $this->line(sprintf(
                '  %-16s %s   %3d messages   PKR %10s   (laagat %s, margin %s)',
                $p['tenant']->tenant_code, $p['ym'], $p['count'],
                number_format($p['amount'], 2),
                number_format($p['cost'], 2),
                number_format($p['amount'] - $p['cost'], 2),
            ));
        }

        if ($planned !== []) {
            $this->newLine();
            $this->info('KUL: PKR '.number_format(array_sum(array_column($planned, 'amount')), 2));
        }

        if (! $write) {
            $this->newLine();
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');

            return self::SUCCESS;
        }

        foreach ($planned as $p) {
            DB::connection('master')->transaction(function () use ($billing, $p) {
                // Us mahine ki khuli invoice dhoondo; na mile to kholo. `draft` jaan boojh kar:
                // mahina chal raha hai, raqam abhi barhegi, aur jo cheez abhi deni nahi us par
                // "due" ka lafz nahi aana chahiye.
                $invoice = SubscriptionInvoice::where('tenant_id', $p['tenant']->id)
                    ->where('invoice_type', 'addon')
                    ->where('period_start', $p['start']->toDateString())
                    ->whereIn('status', ['draft', 'issued'])
                    ->first();

                if (! $invoice) {
                    $invoice = $billing->createInvoice($p['tenant'], [
                        'invoice_type' => 'addon',
                        'status' => 'draft',
                        'subtotal' => 0,
                        'period_start' => $p['start']->toDateString(),
                        'period_end' => $p['end']->toDateString(),
                        'notes' => 'WhatsApp report messages — '.$p['start']->format('F Y')
                            .'. Mahine ke dauran roz barhti hai; mahina khatam hone par band hoti hai.',
                    ]);
                }

                WhatsAppMessage::whereIn('id', $p['rows']->pluck('id'))->update(['invoice_id' => $invoice->id]);

                // Total hamesha JURI HUI rows se dobara nikala jata hai, purane total me joRa nahi
                // jata. Agar ye command kabhi aadhe me ruk jaye to joRne wala tareeqa raqam ko aage
                // peechhe kar deta; dobara nikalne wala tareeqa hamesha un rows ke barabar rehta hai
                // jo waqai is invoice se bandhi hain.
                $total = (float) WhatsAppMessage::where('invoice_id', $invoice->id)->sum('rate_charged');
                $invoice->update([
                    'subtotal' => $total,
                    'total_amount' => $total,
                    'balance_amount' => $total - (float) $invoice->paid_amount,
                ]);
            });
        }

        $this->info(count($planned).' invoice khole/barhaye gaye.');

        if ($close) {
            $month = $this->option('month');
            if (! $month) {
                $this->error('--close ke saath --month dena zaroori hai: kaun sa mahina band karna hai.');

                return self::FAILURE;
            }
            $start = CarbonImmutable::parse($month.'-01');
            $n = SubscriptionInvoice::where('invoice_type', 'addon')
                ->where('period_start', $start->toDateString())
                ->where('status', 'draft')
                ->get()
                ->each(function ($i) {
                    $i->update([
                        'status' => 'issued',
                        'issued_at' => now(),
                        'due_date' => now()->addDays(7)->toDateString(),
                    ]);
                })->count();
            $this->info("{$n} invoice band kar diye gaye (draft → issued).");
        }

        $this->newLine();
        $this->info('WhatsApp ke invoice ab:');
        foreach (SubscriptionInvoice::with('tenant')->where('invoice_type', 'addon')->orderBy('tenant_id')->get() as $i) {
            $this->line(sprintf(
                '  %-16s %-18s %-8s %s → %s   PKR %10s   (%d messages)',
                $i->tenant?->tenant_code ?? '?', $i->invoice_no, $i->status,
                $i->period_start, $i->period_end,
                number_format((float) $i->total_amount, 2),
                WhatsAppMessage::where('invoice_id', $i->id)->count(),
            ));
        }

        return self::SUCCESS;
    }
}
