<?php

namespace App\Console\Commands;

use App\Models\Master\GeoArea;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DeliveryZone;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 — purani ordering website ki area list (fee, minimum, free-delivery) se tenant ke
 * Delivery Areas bharo. Aik dafa ka kaam, client ki di hui JSON se.
 *
 * Shakl: `{"details":{"areas":{"1":[{"area_name","city_name","fee","minimum","min_for_free_delivery",
 * "delivery_estimation"}, …]}}}` (purani website ka area API).
 *
 * Default DRY RUN — `--yes` ke baghair kuch nahi likhta. Naam master `geo_areas` se milaye jate hain;
 * jo na mile wo sirf report hota hai (master me khud nahi banta). File me aik naam do dafa aaye to
 * PEHLA rakha jata hai aur farq report hota hai — fee ka faisla client ka hai.
 */
class OnlineOrderingImportAreasCommand extends Command
{
    protected $signature = 'online-ordering:import-areas
        {tenant_code}
        {file : Purani website ki area JSON}
        {--branch= : Branch ka id ya code (sab areas isi branch se)}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Delivery areas + fees purani ordering website ki JSON se bharta hai (default: dry run).';

    public function handle(TenancyManager $tenancy): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant nahi mila.');

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $groups = $json['details']['areas'] ?? null;
        if (! is_array($groups)) {
            $this->error('File parhi nahi ja saki, ya us me details.areas nahi.');

            return self::FAILURE;
        }

        $tenancy->activate($tenant);

        $branchOpt = (string) $this->option('branch');
        $branch = Branch::where('status', 'active')
            ->where(fn ($q) => $q->where('id', (int) $branchOpt)->orWhere('code', $branchOpt))
            ->first();
        if (! $branch) {
            $this->error('--branch do (active branch ka id ya code).');

            return self::FAILURE;
        }

        $norm = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));

        $plan = [];
        $conflicts = [];
        $missing = [];
        foreach ($groups as $rows) {
            foreach ((array) $rows as $a) {
                $name = trim(preg_replace('/\s+/', ' ', (string) ($a['area_name'] ?? '')));
                $city = trim((string) ($a['city_name'] ?? ''));
                if ($name === '' || $city === '') {
                    continue;
                }

                $area = GeoArea::where('city', $city)->get()->first(fn ($g) => $norm($g->name) === $norm($name));
                if (! $area) {
                    $missing[] = "$city / $name";

                    continue;
                }

                $row = [
                    'fee' => (float) ($a['fee'] ?? 0),
                    'min_order' => (float) ($a['minimum'] ?? 0) > 0 ? (float) $a['minimum'] : null,
                    'free_delivery_above' => (float) ($a['min_for_free_delivery'] ?? 0) > 0 ? (float) $a['min_for_free_delivery'] : null,
                    'eta_minutes' => (int) ($a['delivery_estimation'] ?? 0) > 0 ? (int) $a['delivery_estimation'] : null,
                ];

                if (isset($plan[$area->id])) {
                    if ($plan[$area->id]['fee'] !== $row['fee']) {
                        $conflicts[] = sprintf('%s: %s rakha, %s chhoRa', $area->name, $plan[$area->id]['fee'], $row['fee']);
                    }

                    continue;
                }

                $plan[$area->id] = $row + ['area' => $area];
            }
        }

        $this->table(['Fee', 'Areas'], collect($plan)->groupBy(fn ($p) => number_format($p['fee'], 0))
            ->sortKeys(SORT_NATURAL)->map(fn ($g, $fee) => [$fee, $g->count()])->values()->all());
        $this->info(count($plan).' areas → branch '.$branch->name.' ('.$branch->code.')');
        foreach ($conflicts as $c) {
            $this->warn('Do dafa aaya — '.$c);
        }
        foreach ($missing as $m) {
            $this->warn('Master list me nahi (chhoRa): '.$m);
        }

        if (! $this->option('yes')) {
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');

            return self::SUCCESS;
        }

        DB::connection('tenant')->transaction(function () use ($plan, $branch) {
            foreach ($plan as $areaId => $p) {
                DeliveryZone::updateOrCreate(['geo_area_id' => $areaId], [
                    'area_name' => $p['area']->name,
                    'city' => $p['area']->city,
                    'branch_id' => $branch->id,
                    'fee' => $p['fee'],
                    'min_order' => $p['min_order'],
                    'free_delivery_above' => $p['free_delivery_above'],
                    'eta_minutes' => $p['eta_minutes'],
                    'is_active' => true,
                ]);
            }
        });

        $this->info('Likh diya: '.count($plan).' delivery areas.');

        return self::SUCCESS;
    }
}
