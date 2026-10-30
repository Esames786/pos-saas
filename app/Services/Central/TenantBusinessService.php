<?php

namespace App\Services\Central;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use App\Support\Business;
use App\Support\TenantFeatures;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * PRIMARY-BUSINESS-1 — changing who a tenant is, and its switches, from Bingoo Master.
 *
 * A change never touches the tenant's data: products, sales, stock and accounts stay as they are —
 * only which screens and words the tenant sees. Every change is recorded (who, when, old → new).
 */
class TenantBusinessService
{
    public function __construct(private TenancyManager $tenancy) {}

    /**
     * What the tenant has today — shown BEFORE a business change so the owner sees what he is touching.
     * Read-only; on any failure it says so instead of guessing.
     *
     * @return array{ok:bool, products:array<string,int>, recipes:int, catering_profiles:int, sales:int, error?:string}
     */
    public function impact(Tenant $tenant): array
    {
        $out = ['ok' => false, 'products' => [], 'recipes' => 0, 'catering_profiles' => 0, 'sales' => 0];
        try {
            $this->tenancy->activate($tenant);
            $c = DB::connection('tenant');
            $schema = $c->getSchemaBuilder();
            foreach ($c->table('products')->selectRaw('product_kind, inventory_consumption_method m, COUNT(*) n')->groupBy('product_kind', 'm')->get() as $r) {
                $label = match (true) {
                    $r->product_kind === 'raw_material'                       => 'Ingredients / raw material',
                    $r->product_kind === 'packaging_material'                 => 'Packaging',
                    $r->product_kind === 'finished_good'                      => 'Finished goods',
                    $r->product_kind === 'semi_finished'                      => 'Semi-finished',
                    $r->product_kind === 'service'                            => 'Menu items saved as "Service"',
                    $r->m === 'recipe'                                        => 'Items made from a recipe',
                    default                                                   => 'Items sold',
                };
                $out['products'][$label] = ($out['products'][$label] ?? 0) + (int) $r->n;
            }
            $out['recipes'] = $schema->hasTable('recipes') ? $c->table('recipes')->count() : 0;
            $out['catering_profiles'] = $schema->hasTable('catering_product_profiles') ? $c->table('catering_product_profiles')->count() : 0;
            $out['sales'] = $c->table('sales_orders')->count();
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['error'] = 'Could not read this tenant right now: ' . mb_substr($e->getMessage(), 0, 120);
        } finally {
            $this->tenancy->deactivate();
            DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        }

        return $out;
    }

    /**
     * UB-10 — which of the tenant's items the new screens (purchase in packs, the pack counter) take as they are,
     * shown in Master before a switch is turned on. Read-only: it changes and fixes nothing; an item that is not
     * ready keeps its old screens. At most 200 items are listed (blocked first); the counts cover every item.
     *
     * @return array{ok:bool, checked?:int, summary?:array<string,int>, items?:list<array<string,mixed>>, more?:int, error?:string}
     */
    public function readiness(Tenant $tenant): array
    {
        try {
            $this->tenancy->activate($tenant);
            $r = app(\App\Services\Catalog\ProductReadiness::class)->forTenant();

            return ['ok' => true, 'checked' => $r['checked'], 'summary' => $r['summary'],
                'items' => array_slice($r['items'], 0, 200), 'more' => max(count($r['items']) - 200, 0)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not read this tenant right now: ' . mb_substr($e->getMessage(), 0, 120)];
        } finally {
            $this->tenancy->deactivate();
            DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        }
    }

    /** Modules the tenant's plan includes (what it bought). */
    public function planModules(Tenant $tenant): array
    {
        $tenant->loadMissing('subscription.plan.enabledModules');

        return $tenant->subscription?->plan?->enabledModules?->pluck('key')->all() ?? [];
    }

    public function changeBusiness(Tenant $tenant, string $business, ?int $userId, ?string $note = null): void
    {
        if (! Business::exists($business)) {
            throw new RuntimeException('Unknown business.');
        }
        $missing = Business::missingModules($business, $this->planModules($tenant));
        if ($missing !== []) {
            throw new RuntimeException(Business::label($business) . ' needs these modules in the plan first: ' . implode(', ', $missing) . '. Change the plan, then try again.');
        }
        $old = $tenant->primary_business;
        if ($old === $business) {
            return;
        }
        DB::connection('master')->transaction(function () use ($tenant, $business, $old, $userId, $note) {
            $tenant->update(['primary_business' => $business]);
            $this->record($tenant, 'primary_business', $old, $business, $userId, $note);
        });
    }

    /** @param array<string,bool> $wanted every known switch, on or off */
    public function setFeatures(Tenant $tenant, array $wanted, ?int $userId): int
    {
        // A switch that builds on another (Packs live inside the new product screen) cannot be on alone.
        $all = TenantFeatures::all();
        foreach ($all as $key => $f) {
            foreach ($f['needs'] ?? [] as $need) {
                if (! empty($wanted[$key]) && empty($wanted[$need])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'features' => '"' . $f['label'] . '" needs "' . ($all[$need]['label'] ?? $need) . '" — switch that on too, or leave both off.',
                    ]);
                }
            }
        }

        $current = $tenant->features ?? [];
        $changed = 0;
        DB::connection('master')->transaction(function () use ($tenant, $wanted, $userId, &$current, &$changed) {
            foreach (array_keys(TenantFeatures::all()) as $key) {
                $before = (bool) ($current[$key] ?? false);
                $after = (bool) ($wanted[$key] ?? false);
                if ($before !== $after) {
                    $current[$key] = $after;
                    $this->record($tenant, 'feature:' . $key, $before ? 'on' : 'off', $after ? 'on' : 'off', $userId, null);
                    $changed++;
                }
            }
            if ($changed) {
                $tenant->update(['features' => $current]);
            }
        });

        return $changed;
    }

    public function history(Tenant $tenant, int $limit = 20)
    {
        return DB::connection('master')->table('tenant_setting_changes as c')
            ->leftJoin('central_users as u', 'u.id', '=', 'c.changed_by_central_user_id')
            ->where('c.tenant_id', $tenant->id)->orderByDesc('c.id')->limit($limit)
            ->get(['c.setting', 'c.old_value', 'c.new_value', 'c.note', 'c.created_at', 'u.name as by']);
    }

    private function record(Tenant $tenant, string $setting, ?string $old, ?string $new, ?int $userId, ?string $note): void
    {
        DB::connection('master')->table('tenant_setting_changes')->insert([
            'tenant_id' => $tenant->id, 'setting' => $setting, 'old_value' => $old, 'new_value' => $new,
            'changed_by_central_user_id' => $userId, 'note' => $note, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
