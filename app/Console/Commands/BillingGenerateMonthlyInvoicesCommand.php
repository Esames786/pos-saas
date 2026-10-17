<?php

namespace App\Console\Commands;

use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionInvoiceLine;
use App\Models\Master\WebsiteOrderCharge;
use App\Models\Master\WhatsAppMessage;
use App\Services\Saas\SubscriptionBillingService;
use App\Services\Saas\UsageInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    public function handle(SubscriptionBillingService $billing, UsageInvoiceService $usage): int
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
            // BILLING-TENANT-CYCLE-1 — cycle ka nichla kinara GINA nahi jata.
            //
            // Pehle yahan calendar mahina tha. Malik ne saaf kiya ke aisa koi concept hai hi nahi:
            // khatri 15 se 15, kisi ka 1 se 1, kisi ka 20 se 20. Aur un ke apne alfaaz ne hal bhi de
            // diya — "15 ko banti hai kyunke pichhli tareekhon ka invoice ban chuka hai".
            //
            // Yani seema ginne ki zaroorat hi nahi: jo usage abhi tak KISI invoice se bandhi nahi,
            // wohi is invoice me jati hai. Pichhla lock khud nichla kinara hai. Is se na koi din
            // chhootta hai, na do baar ginta — aur 14 ya 15 ka sawal hi paida nahi hota.
            $last = SubscriptionInvoice::where('tenant_id', $sub->tenant_id)
                ->where('invoice_type', 'subscription')
                ->whereNotIn('status', ['void'])
                ->orderByDesc('period_end')->first();

            $periodStart = $last?->period_end
                ? \Carbon\CarbonImmutable::parse($last->period_end)->addDay()
                : $today->subMonthNoOverflow()->addDay();
            $periodEnd = $today;

            // Aik cycle ka aik hi invoice. Command roz chalti hai; dobara chale to kuch na bane.
            $exists = SubscriptionInvoice::where('tenant_id', $sub->tenant_id)
                ->where('invoice_type', 'subscription')
                ->where('period_end', $periodEnd->toDateString())
                ->exists();

            $planned[] = [
                'sub' => $sub,
                'fee' => $fee,
                'exists' => $exists,
                'from' => $periodStart,
                'to' => $periodEnd,
                'usage' => (float) WhatsAppMessage::where('tenant_id', $sub->tenant_id)->whereNull('invoice_id')->sum('rate_charged')
                    + (float) WebsiteOrderCharge::where('tenant_id', $sub->tenant_id)->whereNull('invoice_id')->sum('amount'),
            ];
        }

        foreach ($planned as $p) {
            $this->line(sprintf(
                '  %-16s %s → %s   plan %s + usage %s = PKR %s%s',
                $p['sub']->tenant->tenant_code,
                $p['from']->format('d M'), $p['to']->format('d M'),
                number_format($p['fee'], 2),
                number_format($p['usage'], 2),
                number_format($p['fee'] + $p['usage'], 2),
                $p['exists'] ? '   [PEHLE SE MAUJOOD — chhoR diya jayega]' : '',
            ));
        }

        $new = array_filter($planned, fn ($p) => ! $p['exists']);
        $this->newLine();
        $this->info('Naye invoice: '.count($new).'  ·  PKR '
            .number_format(array_sum(array_column($new, 'fee')) + array_sum(array_column($new, 'usage')), 2));

        if (! $write) {
            $this->newLine();
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');

            return self::SUCCESS;
        }

        $made = 0;
        foreach ($new as $p) {
            // Sab kuch AIK transaction me: invoice, lines, aur usage ka lock. Agar beech me ruk
            // jaye to aadha bill reh jata — invoice ban chuki hoti aur usage khuli, yani agle din
            // wohi usage DOBARA kisi aur invoice me chali jati.
            DB::connection('master')->transaction(function () use ($billing, $usage, $p, &$made) {
                $tenant = $p['sub']->tenant;

                $invoice = $billing->createInvoice($tenant, [
                    'invoice_type' => 'subscription',
                    'status' => 'issued',
                    'subtotal' => 0,
                    'period_start' => $p['from']->toDateString(),
                    'period_end' => $p['to']->toDateString(),
                    'due_date' => $p['to']->toDateString(),
                    'notes' => 'Khud bana — '.$p['from']->format('d M Y').' se '.$p['to']->format('d M Y').'.',
                ]);

                // Plan ki APNI line. Ye bhoolna mehnga hai: UsageInvoiceService::refresh() total ko
                // LINES se nikalta hai, to line ke baghair plan ki fees total se ghayab ho jati.
                SubscriptionInvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'line_type' => 'plan',
                    'usage_date' => null,
                    'description' => 'Plan'.($p['sub']->plan?->name ? ': '.$p['sub']->plan->name : '')
                        .' — '.$p['from']->format('d M').' – '.$p['to']->format('d M Y'),
                    'quantity' => 1,
                    'base_amount' => null,
                    'rate' => $p['fee'],
                    // enum: per_message | percent | FIXED. Plan ki muqarrar mahana fees fixed hai.
                    // Pehle maine bina dekhe per_month likha tha aur MySQL ne chup-chaap truncate kar
                    // ke insert gira diya — prod par bhi yehi hota.
                    'rate_unit' => 'fixed',
                    'amount' => $p['fee'],
                ]);

                // Jo abhi tak kisi invoice se bandhi nahi — wohi is me, aur ab LOCK.
                WhatsAppMessage::where('tenant_id', $tenant->id)->whereNull('invoice_id')
                    ->update(['invoice_id' => $invoice->id]);
                WebsiteOrderCharge::where('tenant_id', $tenant->id)->whereNull('invoice_id')
                    ->update(['invoice_id' => $invoice->id]);

                // Din-wise lines + total = SUM(lines). Plan ki line bach jati hai: har builder sirf
                // apni line_type mitata hai.
                $usage->refresh($invoice);

                $made++;
            });
        }

        $this->info("{$made} invoice banaye gaye.");

        return self::SUCCESS;
    }
}
