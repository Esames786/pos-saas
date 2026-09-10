<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\PrintJob;
use App\Services\Printing\PrintJobService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * PRINT-AUTOCLOSE-STUCK-1 — jo parchi ek ghante se atki rahe, usay band kar do.
 *
 * ZAROORAT KAHAN SE AAYI: The Kashif Foods par teen Report Center ki parchiyan galti se
 * DOOSRI branch ki printer (Tawakkal, 192.168.100.240) par bhej di gayi thin. Wo printer
 * agli gali ke doosre network par hai, is liye wahan se pahunch hi nahi sakti thi — aur
 * `deferForRetry()` jaan-boojh kar "kabhi haar na maano" ke usool par chalta hai. Nateeja
 * ek na-khatam hone wala chakkar: har ~45 second par agent 16 second us gum printer par
 * atka rehta, aur usi dauran banne wali har receipt/KOT 12-17 second intezar karti. Ek
 * parchi 09 September se ghoom rahi thi.
 *
 * DEFER KA FAISLA GHALAT NAHI THA — uski HADD nahi thi. Kitchen ki parchi is liye gum nahi
 * honi chahiye ke printer ek minute band tha; magar ek ghante baad wo parchi bemani ho
 * chuki hoti hai (khana ja chuka, grahak chala gaya) aur sirf baqi sab ko rok rahi hoti
 * hai. Ye command wohi hadd hai — defer ka contract bilkul nahi chhera gaya.
 *
 * KAAM USI EK AUTHORITY SE: `PrintJobService::cancelObsolete()` — wohi jo screen ka Dismiss
 * button chalata hai. `markPrinted()` KABHI nahi: wo jismani chhapai ki kamyabi ka maani
 * rakhta hai (counters, last_*_printed_at, KOT bookkeeping) aur us se ginti jhoot bolti.
 * cancelObsolete `printed_at` NULL rakhta hai, `claimed_at` saaf karta hai, aur sabab
 * `error_message` me likh deta hai — yani Printing screen par wajah nazar aati hai aur
 * banda chahe to Retry bhi kar sakta hai (`requeueFailed` cancelled→queued leta hai).
 *
 * Cloud-only: `routes/console.php` ka poora scheduler `EdgeRuntime::isCloudSafe()` ke andar
 * hai, aur branch appliance ko Cloud ke scheduled kaam khud nahi chalane chahiye.
 */
class AutocloseStuckPrintJobsCommand extends Command
{
    protected $signature = 'printing:autoclose-stuck
        {--tenant= : Sirf is tenant_code par chalao}
        {--minutes=60 : Itne minute se purani atki parchi band hogi}
        {--dry-run : Kuch band na karo — sirf batao kya band hota}';

    protected $description = 'Ek ghante (ya di gayi hadd) se atki hui print parchiyan band kar do — har active tenant par.';

    /**
     * Agent ka claim lease THEEK 2 minute hai — `PrintAgentApiController` pending fetch me
     * `claimed_at < now()->subMinutes(2)` par job dobara uthata hai.
     *
     * ⚠️ Jo parchi is window ke andar claim hui hai wo IS WAQT printer se nikal rahi ho
     * sakti hai. `cancelObsolete()` `printed` ko rok deta hai magar "chhap rahi hai" ko
     * nahi pehchan sakta, is liye ye faasla khud rakhna parta hai. Agar kabhi wo lease
     * badle to ye qeemat bhi saath badalni hai.
     */
    private const IN_FLIGHT_LEASE_MINUTES = 2;

    public function handle(TenancyManager $tenancy, PrintJobService $jobs): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $dryRun  = (bool) $this->option('dry-run');

        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_code', $this->option('tenant')))
            ->get();

        $totals = ['closed' => 0, 'in_flight_skipped' => 0, 'errors' => 0];

        foreach ($tenants as $tenant) {
            try {
                $tenancy->activate($tenant);
            } catch (\Throwable $e) {
                $this->warn("[{$tenant->tenant_code}] activate failed: {$e->getMessage()}");
                $totals['errors']++;

                continue;
            }

            if (! Schema::connection('tenant')->hasTable('print_jobs')) {
                continue;
            }

            $cutoff   = now()->subMinutes($minutes);
            $inFlight = now()->subMinutes(self::IN_FLIGHT_LEASE_MINUTES);

            // Sirf wo do haalatein jo cancelObsolete() qabool karta hai. `printed_at` ki
            // shart bhi saath rakhi hai: status aur stamp me kabhi farq aa jaye to bhi ek
            // chhapi hui parchi galti se band na ho.
            $stuck = PrintJob::query()
                ->whereIn('print_status', ['queued', 'failed'])
                ->whereNull('printed_at')
                ->where('created_at', '<=', $cutoff)
                ->orderBy('created_at')
                ->get();

            foreach ($stuck as $job) {
                // Abhi chhap rahi ho to chhoro — agla tick isay le lega.
                if ($job->claimed_at && $job->claimed_at->greaterThan($inFlight)) {
                    $totals['in_flight_skipped']++;
                    $this->line("[{$tenant->tenant_code}] #{$job->id} chhori — abhi claim hui hai ({$job->claimed_at}).");

                    continue;
                }

                $age    = (int) $job->created_at->diffInMinutes(now());
                $reason = "Khud band ki gayi: {$age} minute se atki thi (hadd {$minutes} minute), "
                    . "printer #{$job->printer_id}, document {$job->document_type}. Kuch chhapa NAHI — "
                    . 'zaroorat ho to Printing screen se Retry karein.';

                if ($dryRun) {
                    $this->line("[{$tenant->tenant_code}] BAND HOTI: #{$job->id} {$job->document_type} "
                        . "prn={$job->printer_id} umar={$age}m status={$job->print_status}");
                    $totals['closed']++;

                    continue;
                }

                try {
                    $jobs->cancelObsolete($job, $reason);
                    $totals['closed']++;
                    $this->info("[{$tenant->tenant_code}] #{$job->id} band ({$age}m purani, prn={$job->printer_id}).");
                } catch (\Throwable $e) {
                    // Ek parchi ki nakaami poore tenant ya baqi tenants ko na rokay.
                    $totals['errors']++;
                    $this->warn("[{$tenant->tenant_code}] #{$job->id} band nahi hui: {$e->getMessage()}");
                }
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')
            . "done: {$totals['closed']} band, {$totals['in_flight_skipped']} chhori (abhi chhap rahi), "
            . "{$totals['errors']} nakaam.");

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
