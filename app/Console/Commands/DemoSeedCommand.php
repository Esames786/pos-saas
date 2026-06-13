<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Database\Seeders\Demos\EnterpriseDemoSeeder;
use Database\Seeders\Demos\InventoryDemoSeeder;
use Database\Seeders\Demos\RestaurantDemoSeeder;
use Database\Seeders\Demos\RestaurantProDemoSeeder;
use Database\Seeders\Demos\RetailDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {industry : retail|inventory|restaurant|restaurant_pro|enterprise} {--fresh-data : Clear demo-created sample data first (not yet implemented)}';

    protected $description = 'Seed rich industry sample data into an existing demo tenant.';

    private const SEEDERS = [
        'retail'         => RetailDemoSeeder::class,
        'inventory'      => InventoryDemoSeeder::class,
        'restaurant'     => RestaurantDemoSeeder::class,
        'restaurant_pro' => RestaurantProDemoSeeder::class,
        'enterprise'     => EnterpriseDemoSeeder::class,
    ];

    public function handle(TenancyManager $tenancy): int
    {
        $industry = $this->argument('industry');

        if (! isset(self::SEEDERS[$industry])) {
            $this->error("Unsupported industry [{$industry}] for demo:seed.");
            $this->line('Allowed in this prompt: ' . implode(', ', array_keys(self::SEEDERS)));
            return self::FAILURE;
        }

        $tenantCode = config("saas.demos.tenant_codes.{$industry}");
        $allowlist  = config('saas.demos.allowlist', []);

        if (! $tenantCode || ! in_array($tenantCode, $allowlist, true)) {
            $this->error("Demo tenant code for [{$industry}] is missing or not allowlisted.");
            return self::FAILURE;
        }

        $tenant = Tenant::where('tenant_code', $tenantCode)->first();

        if (! $tenant) {
            $this->error("Tenant {$tenantCode} does not exist. Run: php artisan demo:provision {$industry}");
            return self::FAILURE;
        }

        if (! $tenant->isDemo()) {
            $this->error("Refusing to seed {$tenantCode} — tenant is not flagged is_demo.");
            return self::FAILURE;
        }

        if ($this->option('fresh-data')) {
            $this->warn('fresh-data cleanup not available yet — continuing idempotently.');
        }

        $this->info("Seeding {$industry} demo data into {$tenantCode}...");

        $tenancy->activate($tenant);

        try {
            $seeder  = app(self::SEEDERS[$industry]);
            $counts  = $seeder->run();
        } catch (Throwable $e) {
            $tenancy->deactivate();
            DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
            $this->error('Seeding failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $tenancy->deactivate();
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));

        foreach ($counts as $key => $value) {
            $this->line(sprintf('  %-16s %s', $key . ':', is_array($value) ? json_encode($value) : $value));
        }

        $this->info("Done. Public login: demo@{$tenantCode}.com / " . config('saas.demos.default_password', 'demo1234'));
        return self::SUCCESS;
    }
}
