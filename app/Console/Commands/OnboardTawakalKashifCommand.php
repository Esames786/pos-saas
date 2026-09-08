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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TAWAKAL + THE KASHIF FOODS — a BRAND-NEW two-branch restaurant tenant (`tawakalkashif`).
 *
 * Data provisioning only. It never touches another tenant. Spec:
 * docs/plans/tawakkal-kashif-onboarding-2026-09-01.md
 *
 * What makes this one different from every tenant before it:
 *
 *  - TWO BRANCHES SELLING DIFFERENT MENUS. Categories carry `branch_id`
 *    (CATEGORY-BRANCH-SCOPE-1), so each counter's POS shows only its own card. Two categories are
 *    deliberately shared (`branch_id` NULL): Beverages and Cherry Crunch.
 *  - TWO URLS, one tenant. `tenant_domains` allows any number of domains per tenant and
 *    `IdentifyTenant` resolves by host, so this needs no code. The URL does NOT pick the branch —
 *    the logged-in user does, through `default_branch_id`.
 *  - POS + FINANCE ONLY. No inventory, kitchen inventory, purchasing or stock count. Every product
 *    is service-based (`is_stock_tracked` = 0, consumption `none`), so nothing can touch stock.
 *  - EVERYTHING PRINTS AT THE OPERATOR'S OWN COUNTER, and there are NO reminder slips. That is why
 *    this command writes ZERO `category_printer_mappings`: with no category rule, routing falls
 *    through to the terminal's own KOT printer, which is exactly the requirement — and a category
 *    added next month needs no mapping and cannot silently print somewhere else.
 *  - OPERATOR PERMISSIONS ARE A LIST, NOT A PREFIX RULE. The 76 names below were read out of Kashif
 *    Food's LIVE `role_has_permissions` on 2026-09-01, after the owner's 31 August cutback. A
 *    prefix allow/deny list is what drifted last time; a literal list cannot drift.
 */
class OnboardTawakalKashifCommand extends Command
{
    protected $signature = 'onboard:tawakal-kashif
        {--owner-password= : Owner password (required on first provision)}
        {--owner-email= : Tenant owner/default report email}
        {--counter-password= : Password for the two counter users (default: password)}
        {--pin-kf= : Manager PIN for the Kashif Foods counter}
        {--pin-tb= : Manager PIN for the Tawakkal counter}
        {--printer-ip-kf= : LAN IP of the Kashif Foods counter printer}
        {--printer-ip-tb= : LAN IP of the Tawakkal counter printer}
        {--yes : Confirm execution}';

    protected $description = 'Onboard the Tawakal + The Kashif Foods tenant (two branches, brand-new, idempotent).';

    private const PLAN_CODE   = 'tawakal_restaurant';
    private const TENANT_CODE = 'tawakalkashif';
    private const OWNER_EMAIL = 'owner_tk@bingoopos.com';

    /** POS + Finance. The whole stock side stays off — owner-confirmed twice. */
    private const PLAN_MODULES = [
        'pos', 'catalog', 'restaurant', 'printing', 'reports',
        'sales_controls', 'multi_branch', 'users_roles', 'finance',
    ];

    private const PLAN_FEATURES = [
        'branch_limit' => 2, 'terminal_limit' => 2, 'user_limit' => 5, 'product_limit' => null,
    ];

    /** [branch key, domain label]. The first is primary. */
    private const BRANCHES = [
        'KF' => ['The Kashif Foods', 'thekashiffoods'],
        'TB' => ['Tawakkal Biryani', 'tawakkalbiryani'],
    ];

    /** [terminal code, name, branch key, printer code, printer name]. */
    private const TERMINALS = [
        ['T1', 'Kashif Foods Counter', 'KF', 'TK-P-KF', 'Kashif Foods Counter Printer'],
        ['T2', 'Tawakkal Counter',     'TB', 'TK-P-TB', 'Tawakkal Counter Printer'],
    ];

    /**
     * The menu, exactly as the two cards read.
     *
     * [branch key or null for shared, category name, [[product name, price, hidden?], ...]]
     * `null` = a category every branch shows. A hidden product (`is_pos_visible` = 0) exists only
     * to be named inside a combo.
     */
    private const CATALOGUE = [
        // ── The Kashif Foods (the red card) ──────────────────────────────────
        ['KF', 'Singaporean Rice', [
            ['Singaporean Rice', 500], ['Singaporean Rice (Large)', 950],
            ['Singaporean Rice (Family Pack Small)', 2350], ['Singaporean Rice (Family Pack Large)', 3450],
            ['Extra Sauce', 130],
        ]],
        ['KF', 'Singaporean Rice Khas', [
            ['Singaporean Rice Khas (2 Persons)', 1550], ['Singaporean Rice Khas (4 Persons)', 2500],
        ]],
        ['KF', 'Chicken Biryani', [
            ['Sadi Biryani', 200], ['Sadi Biryani (1 KG)', 400],
            ['Chicken Biryani', 280], ['Chicken Biryani (1 KG)', 550],
            ['Chicken Biryani (6 Pcs Family Pack)', 1600], ['Extra Piece', 120],
            // Named by the Classic Platter, sold nowhere on its own.
            ['Platter Rice', 0, true],
        ]],
        ['KF', 'Chicken Pulao', [
            ['Sada Pulao', 200], ['Sada Pulao (1 KG)', 400],
            ['Chicken Pulao', 260], ['Chicken Pulao (1 KG)', 500],
            ['Chicken Pulao (6 Pcs Family Pack)', 1520], ['Extra Piece (Pulao)', 120],
        ]],
        ['KF', 'BBQ', [
            ['Chicken Tikka (Chest)', 450], ['Chicken Tikka (Leg)', 420],
            ['Chicken Malai Tikka (Chest)', 500], ['Chicken Malai Tikka (Leg)', 480],
            ['Chicken Bihari Tikka (Chest)', 460], ['Chicken Bihari Tikka (Leg)', 430],
            ['Chicken Malai Boti', 550], ['Chicken Shahi Chatakh', 580],
            ['Chicken Boti Boneless', 500], ['Chicken Dhaga Kabab', 500],
            ['Chicken Reshmi Kabab', 500], ['Chicken Malai Kabab', 550],
            ['Beef Dhaga Kabab (Fry)', 550], ['Beef Dhaga Kabab', 500],
            ['Beef Seekh Kabab', 500], ['Beef Bihari Boti', 550],
            ['Chandan Kabab', 500], ['Paratha (Small)', 60], ['Paratha (Large)', 120],
            // Named by the Classic Platter, sold nowhere on its own.
            ['Shashlik Stick', 0, true],
        ]],
        ['KF', 'Roll', [
            ['Chicken Chatni Roll', 220], ['Chicken Mayo Garlic Roll', 250],
            ['Chicken Malai Boti Roll', 250], ['Chicken Malai Boti Garlic Roll', 280],
            ['Beef Boti Chatni Roll', 240], ['Beef Boti Mayo Garlic Roll', 270],
        ]],
        // Holds the seven combos. No products of its own — the pill logic renders a tab for a
        // category that has combos, so this is not an empty tab.
        ['KF', 'Deals', []],

        // ── Tawakkal Biryani (the black-and-white card) ──────────────────────
        ['TB', 'Chicken Biryani', [
            ['Sadi Biryani', 180], ['Sadi Biryani (1 KG)', 340],
            ['Chicken Biryani Single', 180], ['Chicken Biryani (Half KG)', 220],
            ['Chicken Biryani (1 KG)', 440],
        ]],
        ['TB', 'Chana Pulao', [
            ['Chana Pulao (1½ Pao)', 120], ['Chana Pulao (Half KG)', 160], ['Chana Pulao (1 KG)', 320],
        ]],
        ['TB', 'Beef Pulao', [
            ['Beef Pulao Sada', 200], ['Beef Pulao Single', 250],
            ['Beef Pulao (Half KG)', 350], ['Beef Pulao (1 KG)', 700],
        ]],

        // ── Both counters (branch_id NULL) ───────────────────────────────────
        // Beverages are shared because the deals price a 500 ml drink at 110 — the same bottle
        // Tawakkal sells — which is how we know The Kashif Foods sells drinks its card never prints.
        [null, 'Beverages', [
            ['Soft Drink 300 ml', 80], ['Soft Drink 500 ml', 110], ['Soft Drink 1 Ltr', 150],
            ['Soft Drink 1.5 Ltr', 180], ['Soft Drink Jumbo', 240],
            ['Mineral Water (Small)', 50], ['Mineral Water (Large)', 100], ['Raita', 50],
        ]],
        [null, 'Cherry Crunch', [
            ['Cherry Crunch (Cup)', 120], ['Cherry Crunch (250 g)', 280],
            ['Cherry Crunch (Half Pack)', 550], ['Cherry Crunch (Full Pack)', 1100],
        ]],
    ];

    /**
     * The seven combos, all at The Kashif Foods. [code, name, price, [[product name, qty], ...]]
     *
     * The deal arithmetic is what settled the card's shorthand — "(reg)" is the 280 biryani and the
     * 500 rice, "Drink 500ml" is the 110 bottle:
     *   D1 220x2=440→400 · D2 610→560 · D3 810→760 · D4 860→810 · D5 890→840 · D6 1030→950
     */
    private const COMBOS = [
        ['TK-D1', 'Deal 1', 400, [['Chicken Chatni Roll', 2]]],
        ['TK-D2', 'Deal 2', 560, [['Chicken Biryani', 1], ['Chicken Chatni Roll', 1], ['Soft Drink 500 ml', 1]]],
        ['TK-D3', 'Deal 3', 760, [['Chicken Biryani', 1], ['Chicken Tikka (Leg)', 1], ['Soft Drink 500 ml', 1]]],
        ['TK-D4', 'Deal 4', 810, [['Singaporean Rice', 1], ['Chicken Mayo Garlic Roll', 1], ['Soft Drink 500 ml', 1]]],
        ['TK-D5', 'Deal 5', 840, [['Chicken Biryani', 1], ['Singaporean Rice', 1], ['Soft Drink 500 ml', 1]]],
        ['TK-D6', 'Deal 6', 950, [['Singaporean Rice', 1], ['Chicken Tikka (Leg)', 1], ['Soft Drink 500 ml', 1]]],
        // Owner-confirmed as a combo, not a plain product. Its five named parts come to 2,580
        // against a 2,300 platter, so these are platter portions — which costs nothing, because a
        // combo's components carry 0.00 and the header holds the money, and since
        // COMBO-KOT-DEAL-NAME-1 the ticket names the deal beside each part.
        ['TK-PLATTER', 'Classic Platter', 2300, [
            ['Chicken Tikka (Chest)', 1], ['Shashlik Stick', 1], ['Chicken Malai Boti', 1],
            ['Chicken Shahi Chatakh', 1], ['Chicken Reshmi Kabab', 1], ['Beef Seekh Kabab', 1],
            ['Platter Rice', 1],
        ]],
    ];

    /**
     * Kashif Food's LIVE "Dine In" role, read on 2026-09-01 — 76 rows, after the owner's 31 August
     * cutback. Delivery (78) only adds two delivery-report screens, useless where there is no
     * delivery; Restricted (62) removes shift open/close, Review & Pay and returns, which the lone
     * operator at each counter cannot do without.
     *
     * A permission that does not exist on this tenant is skipped, never invented.
     */
    private const OPERATOR_PERMISSIONS = [
        'tenant.dashboard',
        'tenant.pos.index', 'tenant.pos.store', 'tenant.pos.change-terminal', 'tenant.pos.void-kot-item',
        'tenant.pos.printing.retry', 'tenant.pos.customers.quick-store', 'tenant.pos.customers.addresses.store',
        'tenant.held-sales.index', 'tenant.held-sales.create', 'tenant.held-sales.store', 'tenant.held-sales.cancel',
        'tenant.sales-orders.index', 'tenant.sales-orders.create', 'tenant.sales-orders.store',
        'tenant.sales-orders.show', 'tenant.sales-orders.cancel', 'tenant.sales-orders.rider.update',
        'tenant.sales-orders.split-bill', 'tenant.sales-orders.split-bill.store',
        'tenant.sales-returns.index', 'tenant.sales-returns.create', 'tenant.sales-returns.store',
        'tenant.sales-returns.show', 'tenant.sales-ledger.index',
        'tenant.shifts.index', 'tenant.shifts.create', 'tenant.shifts.store', 'tenant.shifts.show',
        'tenant.shifts.close-form', 'tenant.shifts.close',
        'tenant.shifts.close-branch-form', 'tenant.shifts.close-branch',
        'tenant.printing.documents.kot', 'tenant.printing.documents.receipt',
        'tenant.printing.documents.reminder', 'tenant.printing.documents.preview',
        'tenant.printing.jobs.index', 'tenant.printing.jobs.queue-kot', 'tenant.printing.jobs.queue-receipt',
        'tenant.printing.jobs.mark-printed', 'tenant.printing.jobs.retry',
        'tenant.printing.jobs.reprint-reminder', 'tenant.printing.jobs.confirm-reminders',
        'tenant.api.pos.bill-preview', 'tenant.api.pos.held-sales', 'tenant.api.pos.recent-sales',
        'tenant.api.pos.totals.quote', 'tenant.api.pos.shift-status', 'tenant.api.pos.print-jobs',
        'tenant.api.pos.promotions.quote', 'tenant.api.pos.table-board', 'tenant.api.pos.table-sessions',
        'tenant.api.pos.table-sessions.open-orders', 'tenant.api.catalog.barcode.lookup',
        'tenant.api.manager-approvals.verify',
        'tenant.ajax.products', 'tenant.ajax.customers', 'tenant.ajax.sales',
        // Owner's call: kept for exact parity with the reference. Inert here — allowed_order_types
        // is [quick_sale, takeaway] and POSController refuses a table session to a user not
        // allowed dine-in. The only visible effect is a Restaurant entry in the sidebar.
        'tenant.restaurant.board',
        'tenant.restaurant.tables.index', 'tenant.restaurant.tables.store', 'tenant.restaurant.tables.update',
        'tenant.restaurant.floors.index', 'tenant.restaurant.floors.store', 'tenant.restaurant.floors.update',
        'tenant.restaurant.waiters.index', 'tenant.restaurant.waiters.store', 'tenant.restaurant.waiters.update',
        'tenant.restaurant.table-sessions.open', 'tenant.restaurant.table-sessions.show',
        'tenant.restaurant.table-sessions.close', 'tenant.restaurant.table-sessions.move',
        'tenant.restaurant.table-sessions.merge', 'tenant.restaurant.table-sessions.bill-preview',
        'tenant.restaurant.table-sessions.bill-requested',
    ];

    public function handle(TenantProvisioner $provisioner, TenancyManager $tenancy): int
    {
        if (! $this->option('yes')) {
            $this->error('Refusing without --yes (creates a NEW tenant + master plan + tenant data).');

            return self::FAILURE;
        }

        $plan   = $this->seedPlan();
        $tenant = $this->seedTenantRow($plan);

        if (! $this->provision($provisioner, $tenancy, $tenant)) {
            return self::FAILURE;
        }

        $tenancy->activate($tenant->fresh());

        $branchIds  = $this->seedBranches();
        $terminals  = $this->seedTerminalsAndPrinters($branchIds);
        [$categoryIds, $productIds] = $this->seedCatalogue($branchIds);
        $this->seedCombos($branchIds['KF'], $categoryIds['KF|Deals'], $productIds);
        $this->seedRolesAndUsers($branchIds, $terminals);

        $this->newLine();
        $this->info('DONE. ' . self::TENANT_CODE . ' is provisioned.');
        foreach (self::BRANCHES as [$name, $label]) {
            $this->line('  ' . str_pad($name, 20) . 'https://' . $label . '.' . config('tenancy.tenant_base_domain'));
        }

        return self::SUCCESS;
    }

    /* ── master ──────────────────────────────────────────────────────────── */

    private function seedPlan(): Plan
    {
        $plan = Plan::updateOrCreate(['code' => self::PLAN_CODE], [
            'name' => 'Tawakal Restaurant (Custom)', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_public' => false, 'is_custom' => true,
        ]);

        foreach (Module::whereIn('key', self::PLAN_MODULES)->pluck('id') as $id) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $id], ['is_enabled' => true]);
        }
        foreach (Module::whereNotIn('key', self::PLAN_MODULES)->get() as $off) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $off->id], ['is_enabled' => false]);
        }
        foreach (self::PLAN_FEATURES as $key => $value) {
            PlanFeature::updateOrCreate(['plan_id' => $plan->id, 'feature_key' => $key], ['feature_value' => $value]);
        }

        $this->info('plan ' . self::PLAN_CODE . ' ready (POS + Finance; no inventory/kitchen inventory; 2 branches / 2 terminals).');

        return $plan;
    }

    /** One tenant, TWO domains — natively supported; the branch still follows the login. */
    private function seedTenantRow(Plan $plan): Tenant
    {
        $tenant = Tenant::updateOrCreate(['tenant_code' => self::TENANT_CODE], [
            'business_name' => 'Tawakal & The Kashif Foods',
            'owner_name'    => 'Tawakal & The Kashif Foods',
            'owner_email'   => $this->option('owner-email') ?: self::OWNER_EMAIL,
            'currency_code' => 'PKR', 'status' => 'pending',
        ]);

        $primary = true;
        foreach (self::BRANCHES as [$name, $label]) {
            TenantDomain::updateOrCreate(
                ['domain' => $label . '.' . config('tenancy.tenant_base_domain')],
                ['tenant_id' => $tenant->id, 'is_primary' => $primary, 'status' => 'active']
            );
            $primary = false;
        }

        Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        return $tenant;
    }

    private function provision(TenantProvisioner $provisioner, TenancyManager $tenancy, Tenant $tenant): bool
    {
        $already = DB::connection('master')->table('tenant_databases')
            ->where('tenant_id', $tenant->id)->where('migration_status', 'completed')->exists();

        $password = $this->option('owner-password');
        if (! $already && ! $password) {
            $this->error('First provision requires --owner-password.');

            return false;
        }

        // Preserve an existing Owner password on re-run — provision updateOrCreate's the Owner.
        $ownerEmail = $tenant->owner_email ?: self::OWNER_EMAIL;
        $existingHash = null;
        if ($already && ! $password) {
            $tenancy->activate($tenant->fresh());
            $existingHash = DB::connection('tenant')->table('users')->where('email', $ownerEmail)->value('password');
        }

        $provisioner->provisionTenant($tenant->fresh(), $password ?: Str::random(24));

        if ($existingHash !== null) {
            DB::connection('tenant')->table('users')->where('email', $ownerEmail)->update(['password' => $existingHash]);
        }

        $this->info('tenant provisioned.');

        return true;
    }

    /* ── tenant data ─────────────────────────────────────────────────────── */

    /** Branch 1 is the one provisioning made; branch 2 is new. Keyed on EXISTENCE, never on name. */
    private function seedBranches(): array
    {
        $c = DB::connection('tenant');
        $ids = [];

        $firstId = (int) $c->table('branches')->orderBy('id')->value('id');
        $c->table('branches')->where('id', $firstId)
            ->update(['name' => self::BRANCHES['KF'][0], 'business_type' => 'restaurant', 'updated_at' => now()]);
        $ids['KF'] = $firstId;

        $secondId = (int) $c->table('branches')->where('name', self::BRANCHES['TB'][0])->value('id');
        if (! $secondId) {
            $secondId = (int) $c->table('branches')->insertGetId([
                'name' => self::BRANCHES['TB'][0], 'business_type' => 'restaurant',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $ids['TB'] = $secondId;

        $this->info("branches: KF #{$ids['KF']} / TB #{$ids['TB']}.");

        return $ids;
    }

    /**
     * One terminal and one printer per counter, and NO category routing rules.
     *
     * Routing resolves a line as: category rule → the terminal's own KOT printer → branch default.
     * With no category rules every line lands on the operator's own printer, which is the whole
     * requirement — and it stays true for any category added later. `supports_reminder` is 0 on
     * both printers, and no reminder rule exists, so no reminder can print: the reminder path has
     * no default-printer or browser fallback to leak through.
     */
    private function seedTerminalsAndPrinters(array $branchIds): array
    {
        $c = DB::connection('tenant');
        $terminals = [];

        foreach (self::TERMINALS as [$code, $name, $branchKey, $printerCode, $printerName]) {
            $branchId = $branchIds[$branchKey];

            $c->table('terminals')->updateOrInsert(
                ['code' => $code],
                ['branch_id' => $branchId, 'name' => $name, 'requires_shift' => 1,
                 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );
            $terminalId = (int) $c->table('terminals')->where('code', $code)->value('id');
            $terminals[$branchKey] = $terminalId;

            $existing = $c->table('printers')->where('code', $printerCode)->first();
            $attrs = [
                'branch_id' => $branchId, 'name' => $printerName, 'printer_type' => 'network',
                'print_role' => 'both',
                // No reminders on this tenant, by the owner's decision.
                'supports_reminder' => 0,
                'port' => $existing->port ?? 9100, 'paper_size' => '80mm', 'characters_per_line' => 42,
                'is_default' => $branchKey === 'KF' ? 1 : 0, 'is_active' => 1, 'updated_at' => now(),
            ];
            if (! $existing) {
                // Never overwrite an IP somebody set on site.
                $attrs['ip_address'] = $this->option('printer-ip-' . strtolower($branchKey)) ?: null;
                $attrs['created_at'] = now();
            }
            $c->table('printers')->updateOrInsert(['code' => $printerCode], $attrs);
            $printerId = (int) $c->table('printers')->where('code', $printerCode)->value('id');

            $c->table('terminal_printer_settings')->updateOrInsert(
                ['terminal_id' => $terminalId],
                ['receipt_printer_id' => $printerId, 'kot_printer_id' => $printerId,
                 'auto_print_receipt' => 1, 'auto_print_kot' => 1, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $keep = array_column(self::TERMINALS, 0);
        $c->table('terminals')->whereNotIn('code', $keep)->update(['status' => 'inactive', 'updated_at' => now()]);

        $mapCount = $c->table('category_printer_mappings')->count();
        $this->info('terminals + printers: T1 Kashif Foods / T2 Tawakkal; receipts AND KOTs at each own counter.');
        $this->info("category routing rules: {$mapCount} (zero is correct — every line falls through to the terminal's own printer).");
        $this->info('reminders: OFF (supports_reminder=0, no reminder rules).');

        return $terminals;
    }

    /**
     * Categories carry the branch; products follow their category. Slugs and SKUs are prefixed
     * because both cards sell a "Sadi Biryani" at different prices and those columns are unique
     * across the whole tenant — the two counters never see each other's grid, so the DISPLAY names
     * stay exactly as the cards print them.
     */
    private function seedCatalogue(array $branchIds): array
    {
        $c = DB::connection('tenant');

        $unitId = (int) ($c->table('units')->where('code', 'EA')->value('id')
            ?: $c->table('units')->insertGetId([
                'code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1,
                'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]));

        $categoryIds = [];
        $productIds  = [];
        $sortCat = 0;
        // SKU sequence runs PER PREFIX across the whole card, not per category. Reset it per
        // category and KF-001 would be claimed by the first item of every KF category in turn, and
        // `updateOrInsert` keyed on the SKU would quietly overwrite each one with the next —
        // twenty BBQ items would come out as fourteen.
        $skuSeq = ['KF' => 0, 'TB' => 0, 'CC' => 0];

        foreach (self::CATALOGUE as [$branchKey, $categoryName, $products]) {
            $prefix   = $branchKey ?: 'CC';
            $branchId = $branchKey ? $branchIds[$branchKey] : null;
            $slug     = Str::slug($categoryName) . '-' . strtolower($prefix);
            $code     = strtoupper($prefix) . '-' . Str::upper(Str::slug($categoryName, ''));

            $existingCat = $c->table('categories')->where('slug', $slug)->value('id');
            $catAttrs = [
                'branch_id' => $branchId, 'name' => $categoryName, 'code' => Str::limit($code, 50, ''),
                'sort_order' => ++$sortCat, 'is_active' => 1, 'updated_at' => now(),
            ];
            if ($existingCat) {
                $c->table('categories')->where('id', $existingCat)->update($catAttrs);
                $categoryId = (int) $existingCat;
            } else {
                $categoryId = (int) $c->table('categories')->insertGetId(
                    $catAttrs + ['slug' => $slug, 'created_at' => now()]
                );
            }
            $categoryIds[$prefix . '|' . $categoryName] = $categoryId;

            $sortProd = 0;
            foreach ($products as $row) {
                [$name, $price] = $row;
                $hidden = (bool) ($row[2] ?? false);
                ++$sortProd;
                $sku  = strtoupper($prefix) . '-' . str_pad((string) ++$skuSeq[$prefix], 3, '0', STR_PAD_LEFT);
                $pSlug = Str::slug($name) . '-' . strtolower($prefix);

                $c->table('products')->updateOrInsert(['sku' => $sku], [
                    'category_id' => $categoryId, 'unit_id' => $unitId,
                    'name' => $name, 'slug' => $pSlug,
                    // Service products throughout: this tenant has no stock modules at all, so
                    // nothing here can create a stock balance or touch a stock GL account.
                    'product_type' => 'service', 'product_kind' => 'service', 'item_kind' => 'finished_good',
                    'is_stock_tracked' => 0, 'inventory_consumption_method' => 'none',
                    'is_sellable' => 1, 'is_purchasable' => 0,
                    'is_pos_visible' => $hidden ? 0 : 1,
                    'default_selling_price' => $price,
                    'sort_order' => $sortProd, 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $productIds[$prefix . '|' . $name] = (int) $c->table('products')->where('sku', $sku)->value('id');
            }
        }

        // Anything this catalogue no longer names is RETIRED, never deleted — a sale line may point
        // at it, and a report must still be able to say what was sold. Hidden and unsellable is
        // enough to keep it off the grid.
        $keepSkus = [];
        foreach (['KF', 'TB', 'CC'] as $p) {
            for ($i = 1; $i <= $skuSeq[$p]; $i++) {
                $keepSkus[] = $p . '-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            }
        }
        $retired = $c->table('products')->whereNotIn('sku', $keepSkus)
            ->where('status', 'active')
            ->update(['status' => 'inactive', 'is_pos_visible' => 0, 'is_sellable' => 0, 'updated_at' => now()]);
        if ($retired) {
            $this->warn("retired {$retired} product(s) no longer on either card (kept for reporting, hidden from the grid).");
        }

        $shared = collect($categoryIds)->keys()->filter(fn ($k) => str_starts_with($k, 'CC|'))->count();
        $this->info('catalogue: ' . count($categoryIds) . " categories ({$shared} shared across both branches), "
            . count($productIds) . ' products — all service-based.');

        return [$categoryIds, $productIds];
    }

    /** Seven combos at The Kashif Foods. A combo needs no product of its own. */
    private function seedCombos(int $branchId, int $dealsCategoryId, array $productIds): void
    {
        $c = DB::connection('tenant');

        // A component may live in a KF category or in a shared one (the 500 ml drink).
        $resolve = function (string $name) use ($productIds): int {
            foreach (['KF', 'CC', 'TB'] as $prefix) {
                if (isset($productIds[$prefix . '|' . $name])) {
                    return $productIds[$prefix . '|' . $name];
                }
            }
            throw new \RuntimeException("Combo component [{$name}] is not in the catalogue — refusing to invent it.");
        };

        $sort = 0;
        foreach (self::COMBOS as [$code, $name, $price, $components]) {
            $comboId = (int) ($c->table('combos')->where('code', $code)->value('id') ?: 0);
            $attrs = [
                'branch_id' => $branchId, 'category_id' => $dealsCategoryId, 'name' => $name,
                'price' => $price, 'sort_order' => ++$sort, 'status' => 'active', 'updated_at' => now(),
            ];
            if ($comboId) {
                $c->table('combos')->where('id', $comboId)->update($attrs);
            } else {
                $comboId = (int) $c->table('combos')->insertGetId($attrs + ['code' => $code, 'created_at' => now()]);
            }

            $c->table('combo_components')->where('combo_id', $comboId)->delete();
            $componentSort = 0;
            foreach ($components as [$componentName, $qty]) {
                // Explicit order so the ticket lists a platter's parts the way the card does,
                // instead of however the rows happen to come back.
                $c->table('combo_components')->insert([
                    'combo_id' => $comboId, 'product_id' => $resolve($componentName),
                    'quantity' => $qty, 'sort_order' => ++$componentSort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $this->info('combos: ' . count(self::COMBOS) . ' at The Kashif Foods (6 deals + the Classic Platter).');
    }

    /**
     * One named role per operator — never a shared role, never another user type's role.
     * The permission set is the literal list read from Kashif Food's live Dine In role.
     */
    private function seedRolesAndUsers(array $branchIds, array $terminals): void
    {
        // A fresh tenant is seeded from a HARD-CODED list inside TenantProvisioner, and that list
        // has fallen behind the routes: this tenant gets 532 permission rows where Kashif Food has
        // 648. Close Branch, the POS print retry, the rider update and the ajax lookups all have
        // real routes but no permission row, so an operator would be 403'd off screens the
        // reference role can reach.
        //
        // So: create the row when — and only when — a route of that exact name is registered. A
        // name with no route behind it is reported and skipped, never invented.
        $routeNames = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($r) => $r->getName())->filter()->flip();

        $created = [];
        $orphans = [];
        foreach (self::OPERATOR_PERMISSIONS as $name) {
            if (\Spatie\Permission\Models\Permission::where('guard_name', 'tenant')->where('name', $name)->exists()) {
                continue;
            }
            if (! $routeNames->has($name)) {
                $orphans[] = $name;
                continue;
            }
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'tenant');
            $created[] = $name;
        }

        if ($created) {
            $this->info('created ' . count($created) . ' missing permission rows for real routes: '
                . implode(', ', $created));
        }
        if ($orphans) {
            $this->warn('no route carries these names, so they were NOT created: ' . implode(', ', $orphans));
        }

        $available = \Spatie\Permission\Models\Permission::where('guard_name', 'tenant')
            ->whereIn('name', self::OPERATOR_PERMISSIONS)->pluck('name')->all();

        $users = [
            ['KF', 'Kashif Foods Counter', 'counter_kf@bingoopos.com', 'Kashif Foods Counter', 'pin-kf'],
            ['TB', 'Tawakkal Counter',     'counter_tb@bingoopos.com', 'Tawakkal Counter',     'pin-tb'],
        ];

        foreach ($users as [$branchKey, $roleName, $email, $userName, $pinOption]) {
            $role = \Spatie\Permission\Models\Role::findOrCreate($roleName, 'tenant');
            // Additive only — this role is ours and brand new, and a fresh role starts empty.
            $role->givePermissionTo($available);

            $branchId   = $branchIds[$branchKey];
            $terminalId = $terminals[$branchKey];

            $existing = \App\Models\Tenant\User::where('email', $email)->first();
            $password = $existing ? null : ($this->option('counter-password') ?: 'password');

            $user = \App\Models\Tenant\User::updateOrCreate(
                ['email' => $email],
                array_merge([
                    'name' => $userName, 'status' => 'active', 'locale' => 'en',
                    'default_branch_id' => $branchId, 'default_terminal_id' => $terminalId,
                    // No dine-in, no delivery — owner's decision.
                    'allowed_order_types' => ['quick_sale', 'takeaway'],
                    'default_order_type'  => 'takeaway',
                ], $password ? ['password' => Hash::make($password)] : [])
            );

            $user->syncRoles([$role]);
            $user->branches()->sync([$branchId]);
            $user->terminals()->sync([$terminalId]);

            // Two DIFFERENT PINs. On Kashif Food all six users share one, so every approval in the
            // audit reads the same name and the trail says nothing. Not repeated here.
            $pin = $this->option($pinOption);
            if ($pin) {
                DB::connection('tenant')->table('manager_pins')->updateOrInsert(
                    ['user_id' => $user->id],
                    ['pin_hash' => Hash::make($pin), 'is_active' => 1,
                     'created_at' => now(), 'updated_at' => now()]
                );
            }

            $this->info("user {$email}: role [{$roleName}] " . count($available)
                . ' perms, branch ' . $branchId . ', terminal ' . $terminalId . ', types [quick_sale,takeaway].'
                . ($pin ? '' : '  (no manager PIN set — pass --' . $pinOption . ')'));
            if ($password) {
                $this->warn("{$userName} password (shown once): {$password}");
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
