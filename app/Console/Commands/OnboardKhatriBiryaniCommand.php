<?php

namespace App\Console\Commands;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;
use App\Services\Tenancy\TenancyManager;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KHATRI BIRYANI ONBOARDING (docs/onboarding/khatri-biryani-2026-08.md — decisions D1–D4).
 *
 * Idempotent end-to-end onboarding of the Khatri Biryani tenant:
 *  - master: `erp_extensions` module row (closes the quotations/purchase-requisitions unmapped
 *    fail-open; enabled ONLY for the plan codes whose sidebar already shows the group);
 *  - master: custom non-public plan `khatri_restaurant` — restaurant_pro's module set (= everything
 *    except manufacturing + offline_edge + erp_extensions) with branch_limit=1, terminal_limit=4,
 *    user_limit=10, product_limit unlimited;
 *  - master: tenant `khatribiryani` + domain + ACTIVE subscription;
 *  - provision (existing TenantProvisioner — DB, migrations, base seed, Owner);
 *  - tenant: terminals T1..T4 (T1/T2 active, T3/T4 inactive per contract), menu categories +
 *    products (ALL SERVICE-BASED: is_stock_tracked=0, consumption 'none' — no inventory deduction),
 *    Manager role (enabled-module permissions minus manufacturing/ERP/roles/permissions/billing).
 *
 * NOTHING here hardcodes Khatri into permission/entitlement CODE — this is data provisioning only.
 */
class OnboardKhatriBiryaniCommand extends Command
{
    protected $signature = 'onboard:khatri-biryani
        {--owner-password= : Owner password (required on first provision)}
        {--owner-email= : Tenant owner/default report email (can be set later)}
        {--delivery-password= : Password for the delivery counter user (generated when omitted)}
        {--manager-pin= : Manager PIN for the delivery counter user (defaults to the agreed value)}
        {--yes : Confirm execution}';

    protected $description = 'Onboard the Khatri Biryani tenant (idempotent; see docs/onboarding/khatri-biryani-2026-08.md).';

    private const PLAN_CODE = 'khatri_restaurant';
    private const TENANT_CODE = 'khatribiryani';
    private const OWNER_EMAIL = 'owner_kb@bingoopos.com';

    /** restaurant_pro's module set = all standard modules minus manufacturing/offline_edge/erp_extensions. */
    private const PLAN_MODULES = [
        'pos', 'catalog', 'restaurant', 'kitchen_display', 'kitchen_inventory', 'inventory',
        'purchasing', 'stock_count', 'printing', 'reports', 'sales_controls', 'multi_branch',
        'users_roles', 'finance',
    ];

    private const PLAN_FEATURES = ['branch_limit' => 1, 'terminal_limit' => 4, 'user_limit' => 10, 'product_limit' => null];

    /** Menu (Z-report prices authoritative — see the onboarding doc for ⚠ client-verification flags). */
    /**
     * KHATRI-MENU-3 (2026-08-11): mirrors the LIVE tenant after the client renamed the child
     * categories and added items on site. 'Saada/Saadi' = plain / no meat in Urdu, so each meat
     * parent carries a plain child beside it; 'Matka' is its own child. Every list runs
     * SMALL → LARGE. '_children' marks a parent with child categories; product order = display order.
     *
     * Kept deliberately in step with production: the child names here are the client's own
     * (Beef Khatri / Khatri Sadi / Beef Changezi / Changezi Saadi / Biryani Chicken / Biryani
     * Saadi), NOT the original Non-Saada/Saada wording, so a rebuild reproduces what staff use.
     * NOTE: adding a category here also needs KOT routing for it — a category with no mapping
     * falls back to the default printer instead of its parent's station.
     */
    private const MENU = [
        'Beef Khatri Biryani' => ['_children' => [
            'Beef Khatri' => [
                ['Beef Khatri Biryani (1/2 kg)', 450], ['Beef Khatri Biryani (1 kg)', 900],
                ['Beef Khatri Biryani Special (1/2 kg)', 600], ['Beef Khatri Biryani Special (1 kg)', 1200],
            ],
            'Khatri Sadi' => [
                ['Saadi Biryani (1/2 kg)', 200], ['Saadi Khatri Biryani (1/2 kg)', 250], ['Saadi Khatri Biryani (1 kg)', 500],
            ],
            'Matka' => [
                ['Matka Biryani Beef', 4000], ['Beef Biryani Matka (1.5 kg)', 4750],
            ],
        ]],
        'Beef Changezi Pulao' => ['_children' => [
            'Beef Changezi' => [
                ['Beef Changezi Pulao (1/2 kg)', 450], ['Beef Changezi Pulao (1 kg)', 900],
                ['Beef Changezi Pulao Special (1/2 kg)', 600], ['Beef Changezi Pulao Special (1 kg)', 1200],
            ],
            'Changezi Saadi' => [
                ['Saada Beef Changezi Pulao (1/2 kg)', 250], ['Saada Beef Changezi Pulao (1 kg)', 500],
            ],
        ]],
        'Chicken Biryani' => ['_children' => [
            'Biryani Chicken' => [
                ['Chicken Biryani (1/2 kg)', 330], ['Chicken Biryani (1 kg)', 650],
                ['Chicken Biryani Family Pack', 1600], ['Chicken Extra Piece', 150],
            ],
            'Biryani Saadi' => [
                ['Saadi Chicken Biryani (1/2 kg)', 200], ['Saadi Chicken Biryani (1 kg)', 400],
            ],
        ]],
        'Singaporean Rice' => [
            ['Singaporean Rice (Small)', 550], ['Singaporean Rice (Large)', 1000],
            ['Singaporean Rice Family Pack (Small)', 2500], ['Singaporean Rice Family Pack (Large)', 3500],
            ['Extra Sauce', 130],
        ],
        'Haleem' => [
            ['Haleem (Plate)', 300], ['Haleem (1/2 kg)', 400], ['Haleem (1 kg)', 800],
        ],
        'Desserts' => [
            ['Cream Cocktail (Cup)', 120], ['Cream Cocktail (Half Pack)', 600], ['Cream Cocktail (Full Pack)', 1000],
            ['Mango Delight (Cup)', 130], ['Mango Delight (Half)', 650], ['Mango Delight (Full)', 1300],
        ],
        'Beverages' => [
            ['Mineral Water (Small)', 60], ['Pakola 300 ml', 90], ['Cola Next 300 ml', 90],
            ['Cola Next 500 ml', 110], ['1 Ltr Coldrink', 160], ['Mineral Water (Large)', 120],
            ['Cola Next 1.5 Ltr', 180], ['Coldrink Jumbo', 240],
        ],
        'Extras' => [
            ['Raita', 70], ['750 ML Box', 30], ['1500 ML Box', 50],
        ],
    ];

    public function handle(TenantProvisioner $provisioner, TenancyManager $tenancy): int
    {
        if (! $this->option('yes')) {
            $this->error('Refusing without --yes (creates/updates master plan + tenant + tenant data).');

            return self::FAILURE;
        }

        // ── 1. master: erp_extensions module (D2) ──
        $erp = Module::updateOrCreate(['key' => 'erp_extensions'], [
            'name' => 'ERP Extensions', 'category' => 'Operations',
            'description' => 'Bank reconciliation, quotations, purchase requisitions (coming soon).',
            'route_module_keys' => ['tenant.quotations', 'tenant.purchase-requisitions'],
            'sort_order' => 90, 'is_core' => false, 'is_active' => true,
        ]);
        // preserve today's effective access: only the plan codes whose sidebar shows the ERP group.
        foreach (Plan::whereIn('code', ['enterprise', 'standard', 'finance_erp'])->get() as $erpPlan) {
            PlanModule::updateOrCreate(['plan_id' => $erpPlan->id, 'module_id' => $erp->id], ['is_enabled' => true]);
        }
        $this->info('erp_extensions module mapped (quotations/purchase-requisitions fail-open closed).');

        // ── 2. master: custom plan (D1) ──
        $plan = Plan::updateOrCreate(['code' => self::PLAN_CODE], [
            'name' => 'Khatri Restaurant (Custom)', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_public' => false, 'is_custom' => true,
        ]);
        $moduleIds = Module::whereIn('key', self::PLAN_MODULES)->pluck('id', 'key');
        foreach ($moduleIds as $key => $id) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $id], ['is_enabled' => true]);
        }
        // everything NOT in the set is explicitly disabled (manufacturing, offline_edge, erp_extensions).
        foreach (Module::whereNotIn('key', self::PLAN_MODULES)->get() as $off) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $off->id], ['is_enabled' => false]);
        }
        foreach (self::PLAN_FEATURES as $key => $value) {
            PlanFeature::updateOrCreate(['plan_id' => $plan->id, 'feature_key' => $key], ['feature_value' => $value]);
        }
        $this->info('plan khatri_restaurant ready (branch 1 / terminal 4 / user 10; no MFG/ERP/edge).');

        // ── 3. master: tenant + domain + subscription ──
        $existingTenant = Tenant::where('tenant_code', self::TENANT_CODE)->first();
        $previousOwnerEmail = $existingTenant?->owner_email ?: 'owner@' . self::TENANT_CODE . '.local';
        $requestedOwnerEmail = $this->option('owner-email') ?: self::OWNER_EMAIL;

        $tenant = Tenant::updateOrCreate(['tenant_code' => self::TENANT_CODE], [
            'business_name' => 'Khatri Biryani', 'owner_name' => 'Khatri Biryani',
            'owner_email' => $requestedOwnerEmail,
            'currency_code' => 'PKR', 'status' => 'pending',
        ]);
        TenantDomain::updateOrCreate(
            ['domain' => self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain')],
            ['tenant_id' => $tenant->id, 'is_primary' => true, 'status' => 'active']
        );
        Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $plan->id, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(),
        ]);

        // ── 4. provision (existing idempotent pipeline: DB + migrations + base seed + Owner) ──
        $alreadyProvisioned = DB::connection('master')->table('tenant_databases')
            ->where('tenant_id', $tenant->id)->where('migration_status', 'completed')->exists();
        $password = $this->option('owner-password');
        if (! $alreadyProvisioned && ! $password) {
            $this->error('First provision requires --owner-password.');

            return self::FAILURE;
        }
        // provisionTenant's base seed updateOrCreate's the Owner WITH a password hash, so a
        // re-run without --owner-password would silently rotate the live credential. Capture
        // the existing hash first and restore it after, so re-runs never change the password.
        $ownerEmail = $tenant->owner_email ?: self::OWNER_EMAIL;
        $existingHash = null;
        if ($alreadyProvisioned) {
            $tenancy->activate($tenant->fresh());
            $ownerUsers = DB::connection('tenant')->table('users')
                ->whereIn('email', array_values(array_unique([$previousOwnerEmail, $ownerEmail])))
                ->get();
            if ($ownerUsers->count() > 1) {
                $this->error('Cannot migrate Owner email: both the old and new Owner emails already exist. Resolve the duplicate tenant users first.');

                return self::FAILURE;
            }
            $ownerUser = $ownerUsers->first();

            if ($ownerUser && $ownerUser->email !== $ownerEmail) {
                $emailInUse = DB::connection('tenant')->table('users')
                    ->where('email', $ownerEmail)
                    ->where('id', '!=', $ownerUser->id)
                    ->exists();
                if ($emailInUse) {
                    $this->error("Cannot migrate Owner email: {$ownerEmail} is already used by another tenant user.");

                    return self::FAILURE;
                }
                DB::connection('tenant')->table('users')->where('id', $ownerUser->id)->update([
                    'email' => $ownerEmail,
                    'updated_at' => now(),
                ]);
                $this->info("owner email migrated: {$previousOwnerEmail} -> {$ownerEmail}");
            }

            if (! $password) {
                if (! $ownerUser) {
                    $this->error('Cannot preserve the Owner password because the existing Owner account was not found. Re-run with --owner-password.');

                    return self::FAILURE;
                }
                $existingHash = $ownerUser?->password;
            }
        }
        $provisioner->provisionTenant($tenant->fresh(), $password ?: Str::random(24));
        if ($existingHash !== null) {
            DB::connection('tenant')->table('users')->where('email', $ownerEmail)->update(['password' => $existingHash]);
            $this->info('owner password preserved (re-run without --owner-password never rotates it).');
        }
        $this->info('tenant provisioned: ' . self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain'));

        // ── 5. tenant data: terminals + menu + Manager role ──
        $tenancy->activate($tenant->fresh());
        $branchId = (int) DB::connection('tenant')->table('branches')->orderBy('id')->value('id');
        // The branch carries the shop's name on every screen and printed document.
        // Cancellation policy (client 2026-08-11): cancelling a WHOLE order needs a manager PIN
        // + reason; reducing a quantity after the KOT is allowed at the counter (reason + Cancel
        // KOT + audit trail still always happen — auto-approve only skips the PIN).
        DB::connection('tenant')->table('branches')->where('id', $branchId)->update([
            'name' => 'Khatri Biryani',
            'held_kot_cancellation_approval_mode' => \App\Models\Tenant\Branch::KOT_CANCELLATION_MANAGER_REQUIRED,
            'held_kot_line_cancellation_approval_mode' => \App\Models\Tenant\Branch::KOT_CANCELLATION_AUTO_APPROVE,
            'manual_discount_approval_mode' => \App\Models\Tenant\Branch::MANUAL_DISCOUNT_AUTO_APPROVE,
            'updated_at' => now(),
        ]);

        // The contract is ONE branch. An earlier provisioner bug (branch keyed on name) created a
        // stray "Main Branch" after the rename; remove any extra branch, but ONLY when it carries
        // no operational data — a branch with real activity is never touched.
        foreach (DB::connection('tenant')->table('branches')->where('id', '!=', $branchId)->get() as $stray) {
            // Fail closed: inspect every tenant table carrying branch_id. A fixed shortlist can
            // silently miss finance, purchasing, inventory, or manufacturing activity added later.
            $scaffolding = ['restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
                'receipt_layout_settings', 'branch_user'];
            $database = DB::connection('tenant')->getDatabaseName();
            $branchTables = collect(DB::connection('tenant')->select(
                'SELECT TABLE_NAME AS table_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ?',
                [$database, 'branch_id']
            ))->pluck('table_name')->map(fn ($table) => (string) $table)->unique();

            $activity = [];
            foreach ($branchTables->reject(fn ($table) => $table === 'branches' || in_array($table, $scaffolding, true)) as $table) {
                $count = DB::connection('tenant')->table($table)->where('branch_id', $stray->id)->count();
                if ($count > 0) {
                    $activity[$table] = $count;
                }
            }
            if ($activity !== []) {
                $summary = collect($activity)->map(fn ($count, $table) => "{$table}:{$count}")->implode(', ');
                $this->warn("branch #{$stray->id} ({$stray->name}) has operational rows ({$summary}) — left in place.");
                continue;
            }
            // detach the empty scaffolding the provisioner seeded for it
            $tableIds = DB::connection('tenant')->table('restaurant_tables')->where('branch_id', $stray->id)->pluck('id');
            if ($tableIds->isNotEmpty()) {
                DB::connection('tenant')->table('restaurant_tables')->whereIn('id', $tableIds)->delete();
            }
            foreach (['restaurant_floors', 'restaurant_waiters', 'receipt_layout_settings', 'branch_user'] as $table) {
                DB::connection('tenant')->table($table)->where('branch_id', $stray->id)->delete();
            }
            DB::connection('tenant')->table('users')->where('default_branch_id', $stray->id)->update(['default_branch_id' => $branchId]);
            DB::connection('tenant')->table('branches')->where('id', $stray->id)->delete();
            $this->info("removed stray empty branch #{$stray->id} ({$stray->name}).");
        }

        // GO-LIVE (2026-08-10): the shop runs THREE named terminals. The legacy Counter 1/2 rows are
        // renamed in place (same ids → existing shifts/sales keep pointing at the right terminal);
        // any older extra terminal is retired to 'inactive' so it never consumes an active slot.
        foreach ([['T1', 'Delivery'], ['T2', 'Takeaway'], ['T3', 'Dine In']] as [$code, $name]) {
            DB::connection('tenant')->table('terminals')->updateOrInsert(
                ['code' => $code],
                ['branch_id' => $branchId, 'name' => $name, 'requires_shift' => 1, 'status' => 'active',
                 'created_at' => now(), 'updated_at' => now()]
            );
        }
        DB::connection('tenant')->table('terminals')->whereNotIn('code', ['T1', 'T2', 'T3'])
            ->update(['status' => 'inactive', 'updated_at' => now()]);
        $this->info('terminals: Delivery / Takeaway / Dine In active (plan allows 4 — one slot spare).');

        $unitId = DB::connection('tenant')->table('units')->where('code', 'EA')->value('id')
            ?: DB::connection('tenant')->table('units')->insertGetId([
                'code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1,
                'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $sort = 0;
        $productCount = 0;
        $seedCategory = function (string $name, ?int $parentId, int $sortOrder, ?string $slug = null): int {
            $slug = $slug ?: Str::slug($name);
            $existing = DB::connection('tenant')->table('categories')->where('slug', $slug)->value('id');
            if ($existing) {
                DB::connection('tenant')->table('categories')->where('id', $existing)
                    ->update(['name' => $name, 'parent_id' => $parentId, 'sort_order' => $sortOrder, 'is_active' => 1, 'updated_at' => now()]);

                return (int) $existing;
            }

            return (int) DB::connection('tenant')->table('categories')->insertGetId([
                'name' => $name, 'slug' => $slug, 'code' => strtoupper(Str::slug($slug, '_')),
                'parent_id' => $parentId, 'sort_order' => $sortOrder, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $seedProducts = function (int $categoryId, array $products) use (&$productCount, $unitId): void {
            foreach ($products as $idx => [$name, $price]) {
                DB::connection('tenant')->table('products')->updateOrInsert(
                    ['slug' => Str::slug($name)],
                    [
                        'category_id' => $categoryId, 'unit_id' => $unitId,
                        'sku' => strtoupper(Str::slug($name, '-')),
                        'name' => $name, 'product_type' => 'simple',
                        // SERVICE-BASED (D4): no inventory deduction, ever.
                        'is_stock_tracked' => 0, 'inventory_consumption_method' => 'none',
                        'is_sellable' => 1, 'is_pos_visible' => 1,
                        'default_selling_price' => $price, 'status' => 'active',
                        // KHATRI-MENU-2: display order = list order (small → large).
                        'sort_order' => $idx + 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]
                );
                $productCount++;
            }
        };
        $childCount = 0;
        foreach (self::MENU as $categoryName => $definition) {
            $parentCategoryId = $seedCategory($categoryName, null, ++$sort);
            if (isset($definition['_children'])) {
                $childSort = 0;
                foreach ($definition['_children'] as $childName => $childProducts) {
                    // child slugs are namespaced by parent ("Saada" exists under BOTH biryani parents)
                    $childId = $seedCategory($childName, $parentCategoryId, ++$childSort, Str::slug($categoryName) . '-' . Str::slug($childName));
                    $seedProducts($childId, $childProducts);
                    $childCount++;
                }
            } else {
                $seedProducts($parentCategoryId, $definition);
            }
        }
        $this->info('menu seeded: ' . count(self::MENU) . " parents + {$childCount} child categories, {$productCount} service-based products (small→large ordering).");

        // GO-LIVE printers: BlackCopper BC97AC at delivery plus an XPrinter at the side station.
        // P1 "Delivery Printer" sits at the delivery counter: reachable over the LAN (our agent
        // prints raw ESC/POS to ip:9100) AND by USB for the POS "print here" preview button. It is
        // the DEFAULT printer and prints the receipt/bill plus the KOT for every category.
        // P2 is network-only and takes the drinks/sweets side: Beverages, Desserts, Extras.
        // IPs are placeholders — set the real ones on site (Printing → Printers).
        // P3 "BlackCopper - Dine In Receipt + KOT" (2026-08-12): dine-in opened as its own terminal
        // with its own receipt/KOT device. Dine-in MAIN food KOTs + receipts print here; dine-in
        // Beverages/Desserts/Extras still share the one XPrinter (P2) with delivery.
        $printers = [
            'PRINTER-1' => ['BlackCopper BC97AC - Delivery Receipt + KOT', '192.168.1.50', 'both', 1],
            'PRINTER-2' => ['XPrinter - Beverages / Desserts / Extras KOT', '192.168.1.51', 'kot', 0],
            'PRINTER-3' => ['BlackCopper - Dine In Receipt + KOT', '192.168.1.87', 'both', 0],
        ];
        $printerIds = [];
        foreach ($printers as $code => [$name, $ip, $role, $isDefault]) {
            $existing = DB::connection('tenant')->table('printers')->where('code', $code)->first();
            $attributes = ['branch_id' => $branchId, 'name' => $name, 'printer_type' => 'network',
                'print_role' => $role, 'supports_reminder' => 0, 'port' => $existing->port ?? 9100,
                'paper_size' => '80mm', 'characters_per_line' => 42,
                'is_default' => $isDefault, 'is_active' => 1, 'updated_at' => now()];
            // NEVER overwrite an IP the shop has already set on site — the seeded address is only
            // a placeholder for the first run (re-running this command must stay safe on a live till).
            if (! $existing) {
                $attributes['ip_address'] = $ip;
                $attributes['created_at'] = now();
            }
            DB::connection('tenant')->table('printers')->updateOrInsert(['code' => $code], $attributes);
            $printerIds[$code] = (int) DB::connection('tenant')->table('printers')->where('code', $code)->value('id');
        }
        // Retire the earlier trial printers (incl. the reminder unit) — this restaurant runs two
        // devices only. Their mappings go with them so nothing routes to a dead destination.
        $retired = DB::connection('tenant')->table('printers')->whereNotIn('code', array_keys($printers))->pluck('id');
        if ($retired->isNotEmpty()) {
            DB::connection('tenant')->table('category_printer_mappings')->whereIn('printer_id', $retired)->delete();
            DB::connection('tenant')->table('terminal_printer_settings')->whereIn('receipt_printer_id', $retired)->update(['receipt_printer_id' => null]);
            DB::connection('tenant')->table('terminal_printer_settings')->whereIn('kot_printer_id', $retired)->update(['kot_printer_id' => null]);
            DB::connection('tenant')->table('printers')->whereIn('id', $retired)->update(['is_active' => 0, 'is_default' => 0, 'updated_at' => now()]);
        }
        // No reminder route at all for this restaurant (client decision) — reminders are off.
        DB::connection('tenant')->table('category_printer_mappings')->where('print_role', 'reminder')->delete();

        // Category → printer: Beverages / Desserts / Extras (and any children) go to P2; everything
        // else to P1. Mapped for ALL order types so routing is right whichever terminal takes the
        // order. KOT routing keys on the product's OWN category, so children need their own rows.
        $p2Parents = ['Beverages', 'Desserts', 'Extras'];
        $mapRow = function (int $categoryId, int $printerId, string $orderType) use ($branchId) {
            DB::connection('tenant')->table('category_printer_mappings')->updateOrInsert(
                ['branch_id' => $branchId, 'category_id' => $categoryId, 'print_role' => 'kot', 'order_type' => $orderType],
                ['printer_id' => $printerId, 'reminder_confirm_on_addition' => 0, 'is_active' => 1,
                 'created_at' => now(), 'updated_at' => now()]
            );
        };
        $mapped = 0;
        foreach (DB::connection('tenant')->table('categories')->whereNull('parent_id')->orderBy('sort_order')->get(['id', 'name']) as $parent) {
            $isBeverageSide = in_array($parent->name, $p2Parents, true);
            $printerId = $isBeverageSide ? $printerIds['PRINTER-2'] : $printerIds['PRINTER-1'];
            $familyIds = DB::connection('tenant')->table('categories')
                ->where('parent_id', $parent->id)->pluck('id')->prepend($parent->id);
            foreach ($familyIds as $categoryId) {
                foreach (['dine_in', 'takeaway', 'delivery', 'quick_sale'] as $ot) {
                    // dine_in MAIN food prints at the Dine In counter's own BlackCopper (P3); its
                    // Beverages/Desserts/Extras stay on the shared XPrinter (P2), and every other
                    // order type routes to the delivery devices exactly as before.
                    $target = ($ot === 'dine_in' && ! $isBeverageSide) ? $printerIds['PRINTER-3'] : $printerId;
                    $mapRow($categoryId, $target, $ot);
                    $mapped++;
                }
            }
        }
        // report the CURRENT addresses (a re-run keeps whatever the shop configured on site)
        $liveIps = DB::connection('tenant')->table('printers')->whereIn('code', array_keys($printers))->pluck('ip_address', 'code');
        $this->info("printers: PRINTER-1 {$liveIps['PRINTER-1']} (default, receipt + all KOT) / PRINTER-2 {$liveIps['PRINTER-2']} (Beverages, Desserts, Extras) — {$mapped} category routes, no reminder printer.");

        // ── Auto-print ON for all three terminals; the Delivery terminal is BOUND to P1 today ──
        // (Takeaway / Dine In keep auto-print on but no explicit binding yet — their KOTs still
        // route by category and receipts fall back to the default printer.)
        $deliveryTerminalId = (int) DB::connection('tenant')->table('terminals')->where('code', 'T1')->value('id');
        $dineInTerminalId = (int) DB::connection('tenant')->table('terminals')->where('code', 'T3')->value('id');
        $terminalIds = DB::connection('tenant')->table('terminals')->where('status', 'active')->pluck('id');
        foreach ($terminalIds as $tid) {
            // Delivery terminal → its BlackCopper (P1); Dine In terminal → its own BlackCopper (P3)
            // for receipts + main KOTs. Takeaway keeps auto-print on with no explicit binding
            // (KOTs route by category, receipt falls back to the default printer).
            $binding = match ((int) $tid) {
                $deliveryTerminalId => ['receipt_printer_id' => $printerIds['PRINTER-1'], 'kot_printer_id' => $printerIds['PRINTER-1']],
                $dineInTerminalId   => ['receipt_printer_id' => $printerIds['PRINTER-3'], 'kot_printer_id' => $printerIds['PRINTER-3']],
                default             => [],
            };
            DB::connection('tenant')->table('terminal_printer_settings')->updateOrInsert(
                ['terminal_id' => $tid],
                array_merge(['auto_print_receipt' => 1, 'auto_print_kot' => 1, 'updated_at' => now(), 'created_at' => now()], $binding)
            );
        }
        // Owner reaches every terminal (open/close any shift, unscoped reports) — must include the
        // Dine In terminal + the spare, or the per-terminal shift guard would fence the Owner out.
        $ownerUser = \App\Models\Tenant\User::where('email', self::OWNER_EMAIL)->first();
        if ($ownerUser) {
            $ownerUser->terminals()->sync(
                DB::connection('tenant')->table('terminals')->pluck('id')->all()
            );
        }
        $this->info('auto-print: KOT + Receipt ON for ' . $terminalIds->count() . ' terminals; Delivery→P1, Dine In→P3.');

        // Manager role: every synced permission belonging to an ENABLED plan module, minus admin/owner
        // concerns. Data-driven from the plan's module route keys — nothing Khatri-specific in code.
        $routeKeys = Module::whereIn('key', self::PLAN_MODULES)->pluck('route_module_keys')->flatten()->all();
        $exclude = ['tenant.roles', 'tenant.permissions', 'tenant.billing', 'tenant.manufacturing',
            'tenant.offline-edge', 'tenant.quotations', 'tenant.purchase-requisitions',
            'tenant.finance.bank-reconciliation', 'tenant.system-reset'];
        $manager = \Spatie\Permission\Models\Role::findOrCreate('Manager', 'tenant');
        $names = \Spatie\Permission\Models\Permission::where('guard_name', 'tenant')->pluck('name')
            ->filter(function (string $name) use ($routeKeys, $exclude) {
                foreach ($exclude as $ex) {
                    if (str_starts_with($name, $ex)) {
                        return false;
                    }
                }
                foreach ($routeKeys as $key) {
                    if (str_starts_with($name, $key)) {
                        return true;
                    }
                }

                return false;
            })->values()->all();
        $manager->syncPermissions($names);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info('Manager role synced: ' . count($names) . ' permissions (no MFG/ERP/roles/billing).');

        $this->seedDeliveryUser($branchId, $deliveryTerminalId);

        $this->info('DONE. Verify prices flagged ⚠ in docs/onboarding/khatri-biryani-2026-08.md before go-live.');

        return self::SUCCESS;
    }

    /**
     * GO-LIVE (2026-08-10): the delivery counter operator. Runs the POS end to end (customers,
     * delivery charge, hold, bill preview, pay, print) but sees NO master-data administration and
     * has NO delete permission anywhere. His Sales Orders / Ledger / Report Center are additionally
     * locked to HIS terminal + delivery order type by UserDataScope (see that service).
     */
    private function seedDeliveryUser(int $branchId, int $deliveryTerminalId): void
    {
        // Everything the POS screen and its linked flows touch, plus the operational screens the
        // client listed. Prefix match against the synced route-permission catalog.
        $allow = [
            'tenant.dashboard',
            'tenant.pos', 'tenant.api.pos', 'tenant.api.catalog',
            'tenant.held-sales', 'tenant.sales-orders', 'tenant.sales-returns', 'tenant.sales-ledger',
            'tenant.customers', 'tenant.delivery-channels', 'tenant.delivery-riders', 'tenant.payment-methods',
            'tenant.restaurant',
            'tenant.shifts',
            // catalog — view/add/edit only (delete is stripped globally below)
            'tenant.products', 'tenant.product-variants', 'tenant.product-barcodes', 'tenant.product-branch-prices',
            'tenant.categories', 'tenant.units', 'tenant.unit-conversions', 'tenant.modifier-groups', 'tenant.combos',
            // POS lookups + printing of the documents he raises
            'tenant.ajax.products', 'tenant.ajax.customers', 'tenant.ajax.sales',
            'tenant.printing.documents', 'tenant.printing.jobs',
            // Sales Report Center (sections are granted separately below)
            'tenant.reports.center.index', 'tenant.reports.center.print', 'tenant.reports.center.export',
        ];
        // Administration / money / stock stays invisible even if a prefix above would match.
        $deny = [
            'tenant.branches', 'tenant.terminals', 'tenant.users', 'tenant.roles', 'tenant.permissions',
            'tenant.billing', 'tenant.settings', 'tenant.system-reset', 'tenant.currencies',
            'tenant.finance', 'tenant.inventory', 'tenant.stock', 'tenant.purchas', 'tenant.suppliers',
            'tenant.goods-receipts', 'tenant.departments', 'tenant.manufacturing', 'tenant.offline-edge',
            'tenant.quotations', 'tenant.kitchen', 'tenant.promotions', 'tenant.daily-closing',
            'tenant.reports.center.schedules', 'tenant.reports.center.email',
        ];
        // Report sections he may see/print. Overview, Details and Cash & Bank are deliberately OUT:
        // Overview would expose the restaurant's overall sales (client decision).
        $sections = ['categories', 'items', 'waiters', 'order-types', 'order-type-combos', 'cancellations'];

        $names = \Spatie\Permission\Models\Permission::where('guard_name', 'tenant')->pluck('name')
            ->filter(function (string $name) use ($allow, $deny) {
                // NO DELETE ANYWHERE — the single hard rule for this account.
                if (str_ends_with($name, '.destroy') || str_contains($name, '.delete')) {
                    return false;
                }
                foreach ($deny as $d) {
                    if (str_starts_with($name, $d)) {
                        return false;
                    }
                }
                foreach ($allow as $a) {
                    if (str_starts_with($name, $a)) {
                        return true;
                    }
                }

                return false;
            })->values()->all();

        foreach ($sections as $section) {
            $key = 'tenant.reports.center.sections.' . $section;
            \Spatie\Permission\Models\Permission::findOrCreate($key, 'tenant');
            $names[] = $key;
        }

        $role = \Spatie\Permission\Models\Role::findOrCreate('Delivery', 'tenant');
        $role->syncPermissions(array_values(array_unique($names)));

        // The delivery counter (delivery orders, Delivery terminal) and the dine-in counter
        // (2026-08-12: dine_in orders, Dine In terminal) share this SAME role — identical
        // permissions — and differ only in the order type + terminal each is scoped to.
        $dineInTerminalId = (int) DB::connection('tenant')->table('terminals')->where('code', 'T3')->value('id');
        $this->seedCounterUser($branchId, $role, 'delivery_kb@bingoopos.com', 'Delivery Counter', 'delivery', $deliveryTerminalId, count($names));
        $this->seedCounterUser($branchId, $role, 'dinein_kb@bingoopos.com', 'Dine In Counter', 'dine_in', $dineInTerminalId, count($names));

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A counter operator: the shared Delivery role, scoped to ONE order type + ONE terminal. The
     * terminal binding is what UserDataScope anchors on — it locks POS, reports AND the per-terminal
     * shift open/close guard to this operator's own terminal.
     */
    private function seedCounterUser(int $branchId, $role, string $email, string $name, string $orderType, int $terminalId, int $permCount): void
    {
        $existing = \App\Models\Tenant\User::where('email', $email)->first();
        $password = $existing ? null : ($this->option('delivery-password') ?: Str::random(16));

        $user = \App\Models\Tenant\User::updateOrCreate(
            ['email' => $email],
            array_merge([
                'name' => $name,
                'status' => 'active',
                'locale' => 'en',
                'default_branch_id' => $branchId,
                'allowed_order_types' => [$orderType],
            ], $password ? ['password' => \Illuminate\Support\Facades\Hash::make($password)] : [])
        );
        $user->syncRoles([$role]);
        $user->branches()->syncWithoutDetaching([$branchId]);
        if ($terminalId) {
            $user->terminals()->sync([$terminalId]);
        }
        // Manager PIN so whole-order cancellations can be approved on site.
        $pin = $this->option('manager-pin') ?: 'password@';
        DB::connection('tenant')->table('manager_pins')->updateOrInsert(
            ['user_id' => $user->id],
            ['pin_hash' => \Illuminate\Support\Facades\Hash::make($pin), 'is_active' => 1,
             'created_at' => now(), 'updated_at' => now()]
        );

        $this->info("counter user {$email} ready: role Delivery = {$permCount} permissions, locked to its terminal + {$orderType} orders.");
        if ($password) {
            $this->warn(ucfirst($name) . ' password (store securely, shown once): ' . $password);
        }
    }
}
