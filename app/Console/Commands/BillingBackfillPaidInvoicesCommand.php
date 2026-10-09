<?php

namespace App\Console\Commands;

use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use App\Services\Saas\SubscriptionBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SAAS-BILLING-BACKFILL-1 — jo invoice pehle hi diye ja chuke hain, unhe record me laata hai.
 *
 * Malik ~PKR 105,000/mah wasool kar rahe hain aur system me poore platform par AIK invoice bani hai
 * (wo bhi demo ki, kabhi paid nahi). Do mahine ka poora hisaab system ke bahar hai: kisi screen par
 * ye nahi dikhta ke kis ne kya diya, aur na hi aage ka balance kisi cheez par khara hai.
 *
 * Ye command wo khala bharti hai. Raqmein MALIK ne batayin (09-10-2026); mai ne kuch nahi nikala —
 * `price_snapshot` charon par khaali hai, to system ko in me se kisi rate ka ilm hi nahi.
 *
 * Default sirf DIKHATA hai. `--yes` ke baghair kuch nahi likha jata, kyunke invoice number tarteeb
 * waar aur nazar aane wala hota hai: ghalat invoice ko mitaya nahi ja sakta, sirf `void` kiya ja
 * sakta hai, aur wo ginti me hamesha ka soorakh chhoR deta hai.
 */
class BillingBackfillPaidInvoicesCommand extends Command
{
    /**
     * Malik ka bataya hua (09-10-2026). Yahan likha hua hai, kahin se nikala nahi gaya — is liye
     * ghalat ho to is aik jagah theek hota hai, aur dry run me poora nazar aata hai.
     *
     * `periods` = calendar mahine jin ka invoice BAN chuka aur DIYA ja chuka hai.
     * `invoice_day` = us mahine ki wo tareekh jab invoice banta hai.
     * `pay_day` = wo tareekh jab adaegi hoti hai.
     *
     * tawakalkashif jaan boojh kar SHAAMIL NAHI: malik ne shart to batayi (20,000, har 20) magar ye
     * nahi bataya ke ab tak kitne invoice diye. Andaazay se adaegi darj karna us tenant ke khate me
     * wo paisa likhna hota jo shayad aaya hi na ho.
     */
    private const HISTORY = [
        'khatribiryani' => ['fee' => 25000, 'invoice_day' => 15, 'pay_day' => 20, 'periods' => ['2026-08', '2026-09']],
        'kashifkitchen' => ['fee' => 25000, 'invoice_day' => 20, 'pay_day' => 20, 'periods' => ['2026-09']],
        'kashiffood' => ['fee' => 35000, 'invoice_day' => 25, 'pay_day' => 1, 'periods' => ['2026-09', '2026-10']],
    ];

    protected $signature = 'billing:backfill-paid-invoices
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Guzre, adaegi-shuda subscription invoice record me laata hai (default: dry run).';

    public function handle(SubscriptionBillingService $billing): int
    {
        $write = (bool) $this->option('yes');
        $plan = [];
        $total = 0.0;

        foreach (self::HISTORY as $code => $h) {
            $tenant = Tenant::where('tenant_code', $code)->first();
            if (! $tenant) {
                $this->error("Tenant nahi mila: {$code}");

                return self::FAILURE;
            }

            foreach ($h['periods'] as $period) {
                $start = CarbonImmutable::parse($period.'-01');
                $end = $start->endOfMonth();
                $issued = $start->day(min($h['invoice_day'], $end->day));
                $due = $start->day(min($h['pay_day'], $end->day));
                // Jo tenant agle mahine ki 1 tareekh ko deta hai, us ki adaegi period ke BAAD hai.
                if ($h['pay_day'] < $h['invoice_day']) {
                    $due = $due->addMonthNoOverflow();
                }

                $exists = SubscriptionInvoice::where('tenant_id', $tenant->id)
                    ->where('period_start', $start->toDateString())
                    ->where('invoice_type', 'subscription')
                    ->exists();

                $plan[] = [
                    'tenant' => $tenant,
                    'code' => $code,
                    'period' => $period,
                    'start' => $start,
                    'end' => $end,
                    'issued' => $issued,
                    'due' => $due,
                    'fee' => (float) $h['fee'],
                    'exists' => $exists,
                ];
                if (! $exists) {
                    $total += (float) $h['fee'];
                }
            }
        }

        $this->info('Jo invoice banenge (sab PAID, kyunke adaegi ho chuki hai):');
        $this->newLine();
        foreach ($plan as $p) {
            $this->line(sprintf(
                '  %-16s %s  muddat %s → %s   bana %s  dena %s   PKR %s%s',
                $p['code'], $p['period'],
                $p['start']->format('d M'), $p['end']->format('d M'),
                $p['issued']->format('d M'), $p['due']->format('d M'),
                number_format($p['fee'], 2),
                $p['exists'] ? '   [PEHLE SE MAUJOOD — chhoR diya jayega]' : '',
            ));
        }
        $this->newLine();
        $this->info('KUL naya: PKR '.number_format($total, 2));
        $this->newLine();
        $this->warn('tawakalkashif SHAAMIL NAHI — malik ne nahi bataya ke us ne ab tak kitne invoice diye.');

        if (! $write) {
            $this->newLine();
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');

            return self::SUCCESS;
        }

        $made = 0;
        foreach ($plan as $p) {
            if ($p['exists']) {
                continue;
            }

            DB::connection('master')->transaction(function () use ($billing, $p, &$made) {
                $invoice = $billing->createInvoice($p['tenant'], [
                    'invoice_type' => 'subscription',
                    'status' => 'issued',
                    'subtotal' => $p['fee'],
                    'period_start' => $p['start']->toDateString(),
                    'period_end' => $p['end']->toDateString(),
                    'due_date' => $p['due']->toDateString(),
                    // Ye likhna zaroori hai. Is ke baghair kal koi ye samjhega ke paisa portal se
                    // aaya tha aur us ka proof dhoondne lagega — jo hai hi nahi.
                    'notes' => 'PEECHHE SE DARJ KIYA GAYA ('.now()->toDateString().'). Asal adaegi system ke bahar hui thi; '
                        .'raqam aur muddat malik ke bataye hue hain. Koi screenshot mojood nahi.',
                ]);

                $billing->recordPayment($invoice, [
                    'amount' => $p['fee'],
                    'payment_date' => $p['due']->toDateString(),
                    'payment_method_code' => 'offline',
                    'status' => 'verified',
                    'notes' => 'Peechhe se darj — adaegi system ke bahar hui thi.',
                ]);

                $made++;
            });
        }

        $this->info("{$made} invoice banaye aur paid kiye gaye.");

        $this->newLine();
        $this->info('Haalat ab:');
        foreach (SubscriptionInvoice::with('tenant')->orderBy('tenant_id')->orderBy('period_start')->get() as $i) {
            $this->line(sprintf(
                '  %-16s %-14s %-10s PKR %12s  diya %12s  baqi %12s',
                $i->tenant?->tenant_code ?? '?', $i->invoice_no, $i->status,
                number_format((float) $i->total_amount, 2),
                number_format((float) $i->paid_amount, 2),
                number_format((float) $i->balance_amount, 2),
            ));
        }

        return self::SUCCESS;
    }
}
