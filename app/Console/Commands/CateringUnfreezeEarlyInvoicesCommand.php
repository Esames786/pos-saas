<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\CateringFinalInvoice;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Tenancy\TenancyManager;
use App\Support\TenantClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-INVOICE-VOID-1 (10 Oct) — wo bill kholna jo banne hi nahi chahiye the.
 *
 * Malik: "jo order ab complete ho gaye hain before date, un ki invoices ko
 * proforma mein change karna hoga taake wo order edit ho sake."
 *
 * 10 Oct ko ye qaida laga ke event ka din guzre baghair bill banta hi nahi
 * (CATERING-NOTHING-FREEZES-BEFORE-EVENT-1). Us se pehle ye rok kahin nahi
 * thi — pehra CLOSE par tha, INVOICE par nahi — aur prod par 33 me se 3
 * bookings apne din se pehle bill ho kar phans gayin.
 *
 * ── KAUN SI BOOKINGS ───────────────────────────────────────────────────────
 *
 * Sirf wo jin ka event ka din AB TAK NAHI GUZRA. Un par bill maujooda qaide ke
 * khilaf khara hai aur order band kar baitha hai.
 *
 * Jis booking ka din GUZAR CHUKA hai, us ka bill ab jaiz hai — chahe wo jaldi
 * bana tha. Use kholna be-matlab churn hai aur khaton me do fazool entries
 * chhorta hai. Aisi booking ko sirf tab chhua jata hai jab us ka number
 * `--event=` se saaf likha jaye.
 *
 * Default DRY RUN. `--yes` ke baghair ek byte nahi likhti.
 */
class CateringUnfreezeEarlyInvoicesCommand extends Command
{
    protected $signature = 'catering:unfreeze-early-invoices {tenant_code} {--event=* : Sirf ye booking numbers} {--yes}';

    protected $description = 'Void final invoices issued before their event day so the order can be edited again. Dry run unless --yes.';

    public function handle(TenancyManager $tenancy, CateringFinalInvoiceService $invoices): int
    {
        $tenant = Tenant::where('tenant_code', $this->argument('tenant_code'))->first();

        if (! $tenant) {
            $this->error("Tenant {$this->argument('tenant_code')} nahi mila.");

            return self::FAILURE;
        }

        $tenancy->activate($tenant);

        $apply = (bool) $this->option('yes');
        $only = array_filter((array) $this->option('event'));
        $today = app(TenantClock::class)->now()->toDateString();

        $this->line($apply ? '=== LIKH RAHA HOON ===' : '=== DRY RUN — kuch nahi likha ja raha ===');
        $this->newLine();

        // `withVoided()` jaan boojh kar: jo pehle hi void ho chuke un ko bhi
        // ginti me laana hai, warna dobara chalane par wo "mil hi nahi rahe"
        // nazar aate aur koi samajhta ke kaam hua hi nahi.
        $candidates = CateringFinalInvoice::query()
            ->withVoided()
            ->with('event')
            ->get()
            ->filter(function (CateringFinalInvoice $invoice) use ($only, $today) {
                $event = $invoice->event;

                if (! $event || ! $event->event_date) {
                    return false;
                }

                if ($only !== []) {
                    return in_array($event->event_no, $only, true);
                }

                // Bill us din se pehle bana jis din event tha — AUR us din abhi
                // tak nahi guzra. Dono sharten, aur dono tareekh ki string par:
                // `event_date` UTC ki aadhi raat hai aur TenantClock ki Karachi
                // ki, is liye lamhe milana yahan jhoot bolta hai.
                $billedOn = $invoice->issued_at?->toDateString();
                $eventOn = $event->event_date->toDateString();

                return $billedOn !== null && $billedOn < $eventOn && $eventOn >= $today;
            });

        if ($candidates->isEmpty()) {
            $this->info('Aisi koi booking nahi mili.');

            return self::SUCCESS;
        }

        $done = 0;

        foreach ($candidates as $invoice) {
            $event = $invoice->event;
            $billedOn = $invoice->issued_at?->toDateString();

            $this->line(sprintf(
                '%-20s %-18s event %s | bill %s | %s',
                $event->event_no,
                $invoice->invoice_no,
                $event->event_date->toDateString(),
                $billedOn,
                $invoice->isVoided() ? 'PEHLE SE VOID' : $event->status
            ));

            if ($invoice->isVoided()) {
                continue;
            }

            $this->line('    GL entries jo ulti hongi: '
                .DB::connection('tenant')->table('journal_entries')
                    ->whereIn('source_type', ['catering_final_invoice', 'catering_advance_application'])
                    ->where('source_id', $invoice->id)
                    ->count());

            if (! $apply) {
                continue;
            }

            $invoices->void(
                $invoice,
                'Event ka din guzre baghair bill bana tha (CATERING-NOTHING-FREEZES-BEFORE-EVENT-1)',
                null
            );

            $this->info('    ✅ void — booking ab '.$event->fresh()->status);
            $done++;
        }

        $this->newLine();
        $this->line($apply
            ? "void hui: {$done}"
            : 'kuch nahi likha gaya — lagane ke liye --yes');

        return self::SUCCESS;
    }
}
