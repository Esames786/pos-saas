<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\OnlineOrdering\PlaceLearner;
use App\Services\Tenancy\TenancyManager;
use App\Support\OnlineOrdering;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * PLACE-LEARNING-1 — delivered website orders teach the delivery areas their places (owner, 2 Nov). Har ghante
 * chalti hai; POS ko nahi chhuti — sirf paid orders ka web_meta parhti hai aur delivery_place_sightings /
 * delivery_zone_places likhti hai. Plan: docs/plans/place-learning-2026-11-02.md.
 */
class OnlineOrderingLearnPlacesCommand extends Command
{
    protected $signature = 'online-ordering:learn-places
        {--tenant= : Sirf is tenant_code par}
        {--pause=1100 : Do OpenStreetMap sawalon ke beech ms (un ka qaida ~1/second)}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Deliver hue website orders se delivery areas ki jagahen seekho (default: dry run).';

    public function handle(TenancyManager $tenancy): int
    {
        $write = (bool) $this->option('yes');
        $pause = max(0, (int) $this->option('pause'));
        $learned = $skipped = 0;

        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $code) => $q->where('tenant_code', $code))
            ->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            try {
                $tenancy->activate($tenant);
                if (! OnlineOrdering::enabledForTenant() || ! Schema::connection('tenant')->hasTable('delivery_place_sightings')) {
                    continue;
                }
                $learner = app(PlaceLearner::class);
                foreach ($learner->pending()->values() as $i => $sale) {
                    if ($i > 0 && $pause > 0) {
                        usleep($pause * 1000);
                    }
                    $r = $learner->learn($sale, $write);
                    $r['name'] !== null ? $learned++ : $skipped++;
                    $this->line(sprintf('  %-16s %-26s %s', $tenant->tenant_code, $sale->sale_no,
                        $r['name'] !== null ? 'learned: '.$r['name'] : 'skipped: '.$r['skipped']));
                }
            } catch (\Throwable $e) {
                // Aik tenant ki kharabi baqi ko na roke.
                report($e);
                $this->warn("{$tenant->tenant_code}: ".$e->getMessage());
            }
        }

        $this->info("Learned from {$learned} order(s), {$skipped} without a place.");
        if (! $write) {
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');
        }

        return self::SUCCESS;
    }
}
