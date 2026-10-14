<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\WebSetting;
use App\Services\Tenancy\TenancyManager;
use App\Support\OnlineOrdering;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 (Phase 3) — confirm na hua website order N minute baad khud Cancelled.
 *
 * Malik: "auto cancel ki setting client khud dale ga time ke saath; off karde to auto cancel na ho"
 * (aksar order taker khud phone kar ke Held karta hai). Default OFF.
 *
 * Sirf woh order jo abhi bhi DRAFT hai (kitchen tak kuch nahi gaya). Row lock ke andar dobara dekha jata
 * hai: usi lamhe cashier ne Hold kiya to Hold jeetta hai — Held order kabhi auto-cancel nahi hota. Draft
 * ka cancel POS ke cancel jaisa hi hai jab KOT na gayi ho: status `cancelled`, koi line-cancellation ya
 * kitchen slip nahi (kuch bheja hi nahi gaya tha).
 */
class OnlineOrderingAutoCancelCommand extends Command
{
    protected $signature = 'online-ordering:auto-cancel {--tenant= : Sirf is tenant_code par}';

    protected $description = 'Confirm na hue website orders (sirf Draft) client ki setting ke mutabiq cancel karta hai.';

    public function handle(TenancyManager $tenancy): int
    {
        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $code) => $q->where('tenant_code', $code))
            ->get();

        $total = 0;
        foreach ($tenants as $tenant) {
            try {
                $tenancy->activate($tenant);
                if (! OnlineOrdering::enabledForTenant() || ! Schema::connection('tenant')->hasTable('web_settings')) {
                    continue;
                }
                $minutes = WebSetting::tenantDefault()->auto_cancel_minutes;
                if (! $minutes) {
                    continue;   // OFF — kabhi khud cancel nahi
                }

                $ids = SalesOrder::where('order_source', 'website')->where('status', 'held')->where('is_draft', true)
                    ->where('created_at', '<=', now()->subMinutes($minutes))->pluck('id');

                foreach ($ids as $id) {
                    $done = DB::connection('tenant')->transaction(function () use ($id, $minutes) {
                        $sale = SalesOrder::whereKey($id)->lockForUpdate()->first();
                        if (! $sale || $sale->status !== 'held' || ! $sale->is_draft || $sale->lines()->where('kot_sent_quantity', '>', 0)->exists()) {
                            return false;   // cashier ne beech me Hold kiya / kuch bhej diya — haath nahi lagana
                        }
                        $sale->update([
                            'status' => 'cancelled',
                            'web_meta' => array_merge($sale->web_meta ?: [], [
                                'cancelled_by' => 'auto', 'cancelled_at' => now()->toIso8601String(), 'auto_cancel_minutes' => $minutes,
                            ]),
                        ]);

                        return true;
                    });
                    if ($done) {
                        $total++;
                        $this->line("{$tenant->tenant_code}: order #{$id} auto-cancelled ({$minutes} min bina confirm)");
                    }
                }
            } catch (\Throwable $e) {
                // Aik tenant ki kharabi baqi ko na roke.
                report($e);
                $this->warn("{$tenant->tenant_code}: ".$e->getMessage());
            }
        }

        $this->info("Auto-cancelled: {$total}");

        return self::SUCCESS;
    }
}
