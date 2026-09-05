<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Master\TenantDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-LEGACY-SUPPLIERS-1 + KASHIF-LEGACY-PHONE-SPLIT-1.
 *
 * Both commands move MASTER data on a live tenant, so what these tests pin is
 * mostly what they must NOT do: never touch money, never invent a balance,
 * never delete a customer, never merge two people because their phones matched.
 *
 * They run the real commands through Artisan against a seeded master tenant —
 * a test that rebuilt the splitting rule itself would only prove the copy.
 */
class CateringLegacyMasterDataMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'supplier_ledgers', 'supplier_payments', 'suppliers',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'customers',
        ]);

        foreach (Tenant::whereIn('tenant_code', ['kashifkitchen', 'notallowed'])->get() as $t) {
            TenantDatabase::where('tenant_id', $t->id)->delete();
            $t->delete();
        }
        TenantDatabase::where('db_database', $this->tenantDb)->delete();

        $this->seedMasterTenant('kashifkitchen');
        // No database row for this one: the allowlist refuses before the tenant is
        // ever activated, and db_database is unique across tenants.
        Tenant::create(['tenant_code' => 'notallowed', 'business_name' => 'Not Allowed', 'status' => 'active']);
    }

    private function seedMasterTenant(string $code): Tenant
    {
        $tenant = Tenant::create([
            'tenant_code' => $code, 'business_name' => 'Tenant '.$code, 'status' => 'active',
        ]);
        $config = config('database.connections.tenant');
        TenantDatabase::create([
            'tenant_id' => $tenant->id, 'db_host' => $config['host'], 'db_port' => $config['port'],
            'db_database' => $this->tenantDb, 'db_username' => $config['username'],
            'db_password' => $config['password'] ?? '', 'migration_status' => 'completed',
        ]);

        return $tenant->fresh();
    }

    private function ledgerFingerprint(): array
    {
        $db = DB::connection('tenant');

        return [
            $db->table('journal_entries')->count(),
            $db->table('journal_lines')->count(),
            $db->table('stock_ledgers')->count(),
            $db->table('supplier_ledgers')->count(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Supplier import
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_supplier_import_refuses_a_tenant_outside_the_allowlist(): void
    {
        $exit = Artisan::call('catering:import-legacy-suppliers', [
            'tenant_code' => 'notallowed', '--yes' => true,
        ]);

        $this->assertSame(1, $exit, 'one client\'s customer and vendor book must not reach another tenant');
        $this->assertSame(0, DB::connection('tenant')->table('suppliers')->count());
    }

    public function test_the_supplier_import_refuses_without_an_explicit_flag(): void
    {
        $exit = Artisan::call('catering:import-legacy-suppliers', ['tenant_code' => 'kashifkitchen']);

        $this->assertSame(1, $exit);
        $this->assertSame(0, DB::connection('tenant')->table('suppliers')->count());
    }

    public function test_the_supplier_import_writes_names_and_phones_but_never_money(): void
    {
        $before = $this->ledgerFingerprint();

        $exit = Artisan::call('catering:import-legacy-suppliers', [
            'tenant_code' => 'kashifkitchen', '--yes' => true,
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $db = DB::connection('tenant');
        $count = $db->table('suppliers')->count();
        $this->assertGreaterThan(200, $count, 'the legacy vendor book should arrive');

        // The workbook holds ~6.49M of opening credit. NONE of it may land here
        // without a journal entry behind it.
        $this->assertSame(0.0, (float) $db->table('suppliers')->sum('opening_balance'),
            'an opening balance is money, and money needs a posting step');
        $this->assertSame(0.0, (float) $db->table('suppliers')->sum('current_balance'));

        $this->assertSame($before, $this->ledgerFingerprint(),
            'a master-data import must not move accounting or stock');

        $this->assertSame($count, $db->table('suppliers')->where('code', 'like', 'LEG-%')->count(),
            'every imported row is traceable to the legacy account it came from');
    }

    public function test_the_supplier_import_can_be_run_twice(): void
    {
        Artisan::call('catering:import-legacy-suppliers', ['tenant_code' => 'kashifkitchen', '--yes' => true]);
        $first = DB::connection('tenant')->table('suppliers')->count();

        Artisan::call('catering:import-legacy-suppliers', ['tenant_code' => 'kashifkitchen', '--yes' => true]);
        $second = DB::connection('tenant')->table('suppliers')->count();

        $this->assertSame($first, $second, 'a re-run updates; it must never duplicate the vendor book');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Customer phone repair
    // ─────────────────────────────────────────────────────────────────────────

    private function makeCustomer(string $code, string $name, string $phone, ?string $address = null): int
    {
        return DB::connection('tenant')->table('customers')->insertGetId([
            'customer_uuid' => strtoupper(bin2hex(random_bytes(13))),
            'code' => $code, 'name' => $name, 'phone' => $phone, 'address' => $address,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_it_splits_only_the_shape_the_evidence_supports(): void
    {
        $fused = $this->makeCustomer('C-1', 'MR FUSED', '0300040210303219200260', 'GULSHAN');
        $typo = $this->makeCustomer('C-2', 'MR TYPO', '030196155011');
        $fine = $this->makeCustomer('C-3', 'MR FINE', '03001234567');

        $exit = Artisan::call('catering:repair-legacy-customer-phones', [
            'tenant_code' => 'kashifkitchen', '--yes' => true,
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $db = DB::connection('tenant');

        $repaired = $db->table('customers')->find($fused);
        $this->assertSame('03000402103', $repaired->phone, '22 digits = two 11-digit numbers');
        $this->assertStringContainsString('GULSHAN', $repaired->address, 'the address it already had survives');
        $this->assertStringContainsString('Alt phone: 03219200260', $repaired->address,
            'the second number is kept — it is a way to reach the customer');

        // A 12-digit number with one digit left over is a typo. Splitting it
        // would turn a real number into a wrong one.
        $this->assertSame('030196155011', $db->table('customers')->find($typo)->phone,
            'anything but the proven shape is left for a human');
        $this->assertSame('03001234567', $db->table('customers')->find($fine)->phone);
    }

    public function test_it_never_adds_or_removes_a_customer_and_never_merges(): void
    {
        // The exact situation on the live tenant: a repaired phone that turns
        // out to belong to somebody already in the book.
        $existing = $this->makeCustomer('C-A', 'MR,MUBASHSHIR', '03002133798');
        $fused = $this->makeCustomer('C-B', 'MR,MUBASHSHIR', '0300213379803480080888');

        $before = DB::connection('tenant')->table('customers')->count();

        Artisan::call('catering:repair-legacy-customer-phones', [
            'tenant_code' => 'kashifkitchen', '--yes' => true,
        ]);
        $output = Artisan::output();

        $db = DB::connection('tenant');
        $this->assertSame($before, $db->table('customers')->count(),
            'a repair must never delete a customer');
        $this->assertNotNull($db->table('customers')->find($existing));
        $this->assertSame('03002133798', $db->table('customers')->find($fused)->phone);

        $this->assertStringContainsString('VISIBLY DUPLICATED', $output,
            'the operator is told the duplicate exists rather than having it merged for them');
    }

    public function test_running_the_repair_twice_does_not_stack_the_note(): void
    {
        $id = $this->makeCustomer('C-1', 'MR FUSED', '0300040210303219200260', 'GULSHAN');

        Artisan::call('catering:repair-legacy-customer-phones', ['tenant_code' => 'kashifkitchen', '--yes' => true]);
        $once = DB::connection('tenant')->table('customers')->find($id)->address;

        Artisan::call('catering:repair-legacy-customer-phones', ['tenant_code' => 'kashifkitchen', '--yes' => true]);
        $twice = DB::connection('tenant')->table('customers')->find($id)->address;

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'Alt phone:'));
    }

    public function test_the_repair_refuses_a_tenant_outside_the_allowlist(): void
    {
        $id = $this->makeCustomer('C-1', 'MR FUSED', '0300040210303219200260');

        $exit = Artisan::call('catering:repair-legacy-customer-phones', [
            'tenant_code' => 'notallowed', '--yes' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame('0300040210303219200260', DB::connection('tenant')->table('customers')->find($id)->phone);
    }
}
