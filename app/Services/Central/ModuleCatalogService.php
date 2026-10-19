<?php

namespace App\Services\Central;

use App\Models\Master\Module;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MASTER-UNIVERSE-1 — modules in plain words for Bingoo Master.
 *
 * A module is a set of screens. Which screens belong to it comes from the route catalogue
 * (`route_catalogs.module_key`, filled from the routes on every deploy) matched against the module's
 * `route_module_keys`. Every route name is one permission; every GET route is a screen.
 * Read-only: this class never changes a module, a plan or a permission.
 */
class ModuleCatalogService
{
    /** Plain descriptions — what the tenant gets and what its staff see. */
    public const SUMMARIES = [
        'pos' => [
            'description' => 'Checkout, sales orders, payments, returns, customers, and POS screen.',
            'sees' => 'POS checkout, sales orders, held sales, returns, customers, payment methods, and sales ledger.',
        ],
        'catalog' => [
            'description' => 'Products, categories, units, modifiers, barcodes, and branch pricing.',
            'sees' => 'Product catalog, units, categories, modifiers, and barcode tools.',
        ],
        'inventory' => [
            'description' => 'Stock on hand, adjustments, transfers, and inventory movement control.',
            'sees' => 'Inventory dashboard, stock adjustments, and stock transfers.',
        ],
        'stock_count' => [
            'description' => 'Physical stock count sessions and variance review.',
            'sees' => 'Stock count sessions and count review screens.',
        ],
        'purchasing' => [
            'description' => 'Suppliers, purchase orders, receipts, bills, and supplier payments.',
            'sees' => 'Purchasing documents, suppliers, receipts, and payables.',
        ],
        'restaurant' => [
            'description' => 'Floors, tables, waiters, dine-in sessions, and restaurant operations.',
            'sees' => 'Restaurant floors, tables, waiters, and table sessions.',
        ],
        'kitchen_display' => [
            'description' => 'Live kitchen preparation queues.',
            'sees' => 'Kitchen display and order preparation screens.',
        ],
        'kitchen_inventory' => [
            'description' => 'Kitchen recipes, ingredient usage, and consumption reports.',
            'sees' => 'Kitchen recipes, ingredients, production, and wastage tools.',
        ],
        'printing' => [
            'description' => 'Print agents, KOT, receipts, and print job monitoring.',
            'sees' => 'Printing setup, print agents, and print job status.',
        ],
        'reports' => [
            'description' => 'Operational dashboards and report menus.',
            'sees' => 'Analytics dashboards and report menus.',
        ],
        'sales_controls' => [
            'description' => 'Promotions, combos/deals, void reasons, and service charges.',
            'sees' => 'Promotions, deals, void controls, and service-charge setup.',
        ],
        'multi_branch' => [
            'description' => 'Branches, terminals, shifts, daily closings, and currencies.',
            'sees' => 'Branch, terminal, shift, daily closing, and currency setup.',
        ],
        'users_roles' => [
            'description' => 'Tenant users, roles, and permission administration.',
            'sees' => 'User, role, and permission administration.',
        ],
        'finance' => [
            'description' => 'Chart of accounts, cash/bank, expenses, journals, and financial statements.',
            'sees' => 'Finance ledgers, cash/bank, journals, expenses, and statements.',
        ],
        'manufacturing' => [
            'description' => 'BOM, production orders, WIP, finished goods, consumption, and reports.',
            'sees' => 'BOM, production orders, WIP, receipts, requisitions, and manufacturing reports.',
        ],
        'catering' => [
            'description' => 'Catering events, estimates/quotations, material rate book, recipe costing, and production releases.',
            'sees' => 'Catering events, estimates, material rates, rate impact, production sheets, and catering settings.',
        ],
    ];

    /** Plain description for a module (the summary above, else what is saved on the module). */
    public static function describe(Module $module): string
    {
        return self::SUMMARIES[$module->key]['description']
            ?? ($module->description ?: 'Business feature controlled by this module.');
    }

    /** Public URL of the module's picture (public/assets/img/modules/<key>.jpg), or null. */
    public static function image(string $key): ?string
    {
        $rel = 'assets/img/modules/' . $key . '.jpg';

        return is_file(public_path($rel)) ? asset($rel) : null;
    }

    /**
     * Every module with its numbers, in display order.
     *
     * @return Collection<int, array{module:Module, description:string, screens:int, permissions:int,
     *   main_screens:array<int,string>, plans:int, tenants:int, image:?string}>
     */
    public function all(bool $activeOnly = false): Collection
    {
        $modules = Module::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();

        $routes = $this->routesByModuleKey();
        $plans = $this->plansPerModule();
        $tenants = $this->tenantsPerModule();

        return $modules->map(function (Module $m) use ($routes, $plans, $tenants) {
            $screens = 0;
            $permissions = 0;
            $main = [];
            foreach ((array) ($m->route_module_keys ?? []) as $key) {
                $r = $routes[$key] ?? null;
                if (! $r) {
                    continue;
                }
                $screens += $r['get'];
                $permissions += $r['all'];
                $main = array_merge($main, $r['main']);
            }
            $main = array_values(array_unique($main));
            sort($main);

            return [
                'module'       => $m,
                'description'  => self::describe($m),
                'screens'      => $screens,
                'permissions'  => $permissions,
                'main_screens' => $main,
                'plans'        => $plans[$m->id] ?? 0,
                'tenants'      => $tenants[$m->id] ?? 0,
                'image'        => self::image($m->key),
            ];
        });
    }

    /**
     * @return array<string, array{all:int, get:int, main:array<int,string>}> route module key → counts
     */
    private function routesByModuleKey(): array
    {
        $out = [];
        $rows = DB::connection('master')->table('route_catalogs')
            ->where('route_name', 'like', 'tenant.%')
            ->get(['route_name', 'uri', 'method', 'module_key']);
        foreach ($rows as $r) {
            $k = (string) $r->module_key;
            $out[$k] ??= ['all' => 0, 'get' => 0, 'main' => []];
            $out[$k]['all']++;
            if (str_starts_with((string) $r->method, 'GET')) {
                $out[$k]['get']++;
                // A list screen a person opens from the sidebar: "<something>.index" without a {parameter}.
                if (str_ends_with($r->route_name, '.index') && ! str_contains((string) $r->uri, '{')
                    && ! str_starts_with((string) $r->uri, 'api/') && ! str_contains($r->route_name, '.ajax.')) {
                    $out[$k]['main'][] = self::screenLabel($r->route_name);
                }
            }
        }

        return $out;
    }

    /** Short words people say: POS, KOT … */
    private const GENERIC = ['center', 'dashboard', 'display', 'settings', 'board', 'home', 'overview', 'list'];

    private const ACRONYMS = ['pos' => 'POS', 'bom' => 'BOM', 'kot' => 'KOT', 'grn' => 'GRN', 'wip' => 'WIP', 'fg' => 'FG'];

    /**
     * The name a person sees for a list screen: the last part of the route, in plain words.
     * "tenant.catering.material-rates.index" → "Material rates"; "tenant.pos.index" → "POS".
     */
    public static function screenLabel(string $routeName): string
    {
        $parts = explode('.', Str::after($routeName, 'tenant.'));
        if (end($parts) === 'index' && count($parts) > 1) {
            array_pop($parts);
        }
        $word = fn (string $p) => self::ACRONYMS[$p] ?? str_replace(['-', '_'], ' ', $p);
        $last = (string) array_pop($parts);
        // "center", "display" … say nothing alone: keep the part before ("Reports center", "Kitchen display").
        $label = (in_array($last, self::GENERIC, true) && $parts !== []) ? $word((string) end($parts)) . ' ' . $word($last) : $word($last);

        return Str::ucfirst($label);
    }

    /** @return array<int,int> module id → plans that switch it on */
    private function plansPerModule(): array
    {
        return DB::connection('master')->table('plan_modules')->where('is_enabled', true)
            ->groupBy('module_id')->selectRaw('module_id, COUNT(DISTINCT plan_id) AS n')
            ->pluck('n', 'module_id')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int,int> module id → tenants (not cancelled) whose plan has it on */
    private function tenantsPerModule(): array
    {
        return DB::connection('master')->table('tenants as t')
            ->join('subscriptions as s', 's.tenant_id', '=', 't.id')
            ->join('plan_modules as pm', function ($j) {
                $j->on('pm.plan_id', '=', 's.plan_id')->where('pm.is_enabled', '=', true);
            })
            ->where('t.status', '!=', 'cancelled')
            ->groupBy('pm.module_id')->selectRaw('pm.module_id, COUNT(DISTINCT t.id) AS n')
            ->pluck('n', 'module_id')->map(fn ($n) => (int) $n)->all();
    }
}
