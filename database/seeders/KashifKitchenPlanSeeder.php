<?php

namespace Database\Seeders;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanModule;
use Illuminate\Database\Seeder;

/**
 * KASHIF-CATERING-ONBOARDING-1 — the Kashif Kitchen plan, as code.
 *
 * This tenant was provisioned by hand, which meant its plan existed only in the
 * production database. Nobody could see what it included without querying live,
 * and rebuilding it would have been guesswork. It is defined here instead.
 *
 * Idempotent by design: it updates the plan in place and never touches the
 * tenant's own data. Running it twice does nothing the first run did not.
 *
 * It also does NOT create the tenant, its database, users, products or recipes.
 * Provisioning a tenant is a separate, deliberate act — a seeder that could
 * recreate a live tenant is a seeder that could destroy one.
 */
class KashifKitchenPlanSeeder extends Seeder
{
    public const PLAN_CODE = 'kashif-catering';

    /**
     * What this kitchen actually needs.
     *
     * purchasing is included deliberately. It was missing, so the store could
     * count and issue stock but never record buying any — the one gap that made
     * the inventory story incomplete. Everything here already exists in the
     * platform; nothing is built for this plan.
     */
    public const MODULES = [
        'catalog',           // dishes and materials
        'inventory',         // stock on hand, batches, adjustments
        'purchasing',        // suppliers, purchase orders, bills — ADDED
        'kitchen_inventory', // recipes, unit conversions, productions
        'printing',          // printers, job queue, LAN agents
        'multi_branch',
        'users_roles',
        'finance',           // ledger behind advances and invoices
        'catering',          // the vertical itself
    ];

    /** Deliberately excluded — a caterer has no till and no factory. */
    public const EXCLUDED = [
        'pos', 'restaurant', 'manufacturing', 'reports', 'ecommerce', 'erp_extensions',
    ];

    public function run(): void
    {
        $plan = Plan::updateOrCreate(
            ['code' => self::PLAN_CODE],
            [
                'name' => 'Kashif Catering',
                'public_description' => 'Private plan for Kashif Kitchen — catering, kitchen and store, no POS.',
                'price' => 0,
                'billing_period' => 'yearly',
                'is_active' => true,
                'is_public' => false,
                'is_custom' => true,
            ]
        );

        $missing = [];

        foreach (self::MODULES as $key) {
            $module = Module::where('key', $key)->first();

            if (! $module) {
                // Never silently skip. A missing module means the plan is being
                // built without something it was supposed to have, and the
                // tenant would lose a screen with no explanation.
                $missing[] = $key;

                continue;
            }

            PlanModule::updateOrCreate(
                ['plan_id' => $plan->id, 'module_id' => $module->id],
                ['is_enabled' => true]
            );
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'KashifKitchenPlanSeeder: these modules are not registered, so the plan '
                .'would be incomplete: '.implode(', ', $missing)
                .'. Run MasterSeeder first.'
            );
        }

        // Anything explicitly excluded is disabled rather than left dangling, so
        // re-running after a module was wrongly added puts it back.
        foreach (self::EXCLUDED as $key) {
            if ($module = Module::where('key', $key)->first()) {
                PlanModule::where('plan_id', $plan->id)
                    ->where('module_id', $module->id)
                    ->update(['is_enabled' => false]);
            }
        }

        foreach ([
            'branch_limit' => '1',
            'terminal_limit' => '4',
            'user_limit' => '10',
        ] as $feature => $value) {
            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $feature],
                ['feature_value' => $value]
            );
        }

        $this->command?->info(
            'Kashif Kitchen plan ['.self::PLAN_CODE.'] synced: '
            .count(self::MODULES).' modules enabled, '.count(self::EXCLUDED).' explicitly disabled.'
        );
    }
}
