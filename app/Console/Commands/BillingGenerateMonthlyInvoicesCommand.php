<?php

namespace App\Console\Commands;

use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Services\Saas\SubscriptionBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SAAS-BILLING-AUTO-1 — har tenant ka mahana invoice us ke apne din par khud ban jaye.
 *
 * Is se pehle koi generator tha hi nahi: `routes/console.php` me sirf `saas:subscriptions-expire`
 * tha, jo purane expire karta hai, banata kuch nahi. Yani PKR 105,000/mah ka hisaab har mahine haath
 * se banana paRta — aur jo cheez haath se banti hai wo kisi mahine bhool jati hai.
 *
 * Roz chalti hai aur sirf un subscriptions ko dekhti hai jin ka `invoice_day` AAJ hai. Har tenant ki
 * apni tareekh malik ne di: khatri 15, kashifkitchen 20, kashiffood 25, tawakal 10.
 *
 * Invoice `issued` banta hai (draft nahi): ye mahine ki tay-shuda fees hai, usage ki tarah barhti
 * nahi — bante hi dena hai.
 */
class BillingGenerateMonthlyInvoicesCommand extends Command
{
    protected $signature = 'billing:generate-monthly-invoices
        {--date= : Kis din ke hisaab se chalao (YYYY-MM-DD). Default: aaj}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Jin tenants ka invoice_day aaj hai, un ka mahana subscription invoice banata hai.';

    public function handle(SubscriptionBillingService $billing): int
    {
        $write = (bool) $this->option('yes');
        $today = CarbonImmutable::parse($this->option('date') ?: now()->toDateString());

        $start = $today->startOfMonth();
        $end = $today->endOfMonth();

        // 31 wale din February me 28/29 ko chalte hain, warna un ka invoice us mahine banta hi nahi.
        $dayToday = $today->day;
        $isLastDay = $today->day === $end->day;

        $subs = Subscription::with('tenant')
            ->whereNotNull('invoice_day')
            ->where('status', '!=', 'cancelled')
            ->get()
            ->filter(fn ($s) => (int) $s->invoice_day === $dayToday
                || ($isLastDay && (int) $s->invoice_day > $end->day));

        if ($subs->isEmpty()) {
            $this->info("Aaj ({$today->toDateString()}) kisi tenant ka invoice ka din nahi hai.");

            return self::SUCCESS;
        }

        $planned = [];
        foreach ($subs as $sub) {
            if (! $sub->tenant) {
                continue;
            }

            $fee = (float) ($sub->price_snapshot ?? 0);
            if ($fee <= 0) {
                // Chup-chaap sifar ka invoice banane se behtar hai shor machana: sifar ka invoice
                // kisi ko bhi ghalat nahi lagta aur mahine guzar jate hain.
                $this->warn("  {$sub->tenant->tenant_code}: price_snapshot khaali hai — CHHOD diya.");

                continue;
            }

            // Pehle se bana hua ho to dobara mat banao. Command roz chalti hai; agar kisi din do
            // baar chal jaye to do invoice ban jate aur tenant ko dugna bill nazar aata.
            $exists = SubscriptionInvoice::where('tenant_id', $sub->tenant_id)
                ->where('invoice_type', 'subscription')
                ->where('period_start', $start->toDateString())
                ->exists();

            $planned[] = [
                'sub' => $sub,
                'fee' => $fee,
                'exists' => $exists,
            ];
        }

        foreach ($planned as $p) {
            $this->line(sprintf(
                '  %-16s %s → %s   PKR %s%s',
                $p['sub']->tenant->tenant_code,
                $start->format('d M'), $end->format('d M'),
                number_format($p['fee'], 2),
                $p['exists'] ? '   [PEHLE SE MAUJOOD — chhoR diya jayega]' : '',
            ));
        }

        $new = array_filter($planned, fn ($p) => ! $p['exists']);
        $this->newLine();
        $this->info('Naye invoice: '.count($new).'  ·  PKR '.number_format(array_sum(array_column($new, 'fee')), 2));

        if (! $write) {
            $this->newLine();
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');

            return self::SUCCESS;
        }

        $made = 0;
        foreach ($new as $p) {
            $billing->createInvoice($p['sub']->tenant, [
                'invoice_type' => 'subscription',
                'status' => 'issued',
                'subtotal' => $p['fee'],
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'due_date' => $today->toDateString(),
                'notes' => 'Khud bana — '.$start->format('F Y').'.',
            ]);
            $made++;
        }

        $this->info("{$made} invoice banaye gaye.");

        return self::SUCCESS;
    }
}
