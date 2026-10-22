<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\MySql\Support\RegistersTestTenant;

/**
 * tenant:reset-transactions FAILS CLOSED (22 Oct). It used to print a warning about tables it had no opinion about and
 * carry on deleting — the way nineteen catering tables, and then the two supplier-invoice tables, would have been left
 * pointing at wiped journals and bills. Now one unclassified table stops it before the first destructive statement:
 * every row in every table is exactly where it was, and the message names the table.
 *
 * Only this test's own disposable tenant database is touched. No reset is ever run on a live tenant.
 */
class TenantResetFailClosedMySqlTest extends MySqlTenantTestCase
{
    use RegistersTestTenant;

    private const PROBE = 'zz_reset_probe_unclassified';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['supplier_credit_reallocations']);
        Schema::connection('tenant')->dropIfExists(self::PROBE);
    }

    protected function tearDown(): void
    {
        Schema::connection('tenant')->dropIfExists(self::PROBE);
        parent::tearDown();
    }

    public function test_an_unclassified_table_stops_the_reset_before_anything_is_deleted(): void
    {
        $c = DB::connection('tenant');
        // A row the reset WOULD wipe, and a table it has never heard of.
        $c->table('supplier_credit_reallocations')->insert(['purchase_bill_id' => 1, 'purchase_return_id' => 1, 'from_purchase_bill_id' => 2,
            'amount' => 200, 'from_due_before' => 300, 'from_due_after' => 500, 'created_at' => now(), 'updated_at' => now()]);
        Schema::connection('tenant')->create(self::PROBE, function ($t) {
            $t->id();
            $t->string('note');
        });
        $c->table(self::PROBE)->insert(['note' => 'a table added later']);

        $all = array_map(fn ($row) => array_values((array) $row)[0], $c->select('SHOW TABLES'));
        $before = $this->tableFingerprints($all);

        $code = $this->registerTestTenant('rstfc');
        $exit = Artisan::call('tenant:reset-transactions', ['tenant_code' => $code, '--yes' => true, '--confirm' => $code]);
        $output = Artisan::output();
        DB::setDefaultConnection('tenant');

        $this->assertNotSame(0, $exit, 'an unclassified table must stop the reset');
        $this->assertStringContainsString('Reset stopped before anything was deleted', $output);
        $this->assertStringContainsString(self::PROBE, $output, 'the message names the table');
        $this->assertSame($before, $this->tableFingerprints($all), 'every row of every table is exactly where it was');

        // The guard is the only thing that stopped it: without the stranger, the same reset runs and wipes.
        Schema::connection('tenant')->drop(self::PROBE);
        $exit = Artisan::call('tenant:reset-transactions', ['tenant_code' => $code, '--yes' => true, '--confirm' => $code]);
        DB::setDefaultConnection('tenant');
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(0, $c->table('supplier_credit_reallocations')->count());
    }

    /**
     * Classifying the Branch Server's tables as kept must never make a reset possible ON a Branch Server: the command
     * refuses there before it looks at a single table, Edge schema present or not, and every row stays where it was.
     */
    public function test_a_branch_server_still_refuses_the_reset_and_nothing_moves(): void
    {
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $c = DB::connection('tenant');
        $c->table('supplier_credit_reallocations')->insert(['purchase_bill_id' => 1, 'purchase_return_id' => 1, 'from_purchase_bill_id' => 2,
            'amount' => 200, 'from_due_before' => 300, 'from_due_after' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $all = array_map(fn ($row) => array_values((array) $row)[0], $c->select('SHOW TABLES'));
        $before = $this->tableFingerprints($all);
        $code = $this->registerTestTenant('rstbs');

        config(['app.role' => 'branch_server']);
        try {
            $exit = Artisan::call('tenant:reset-transactions', ['tenant_code' => $code, '--yes' => true, '--confirm' => $code]);
            $output = Artisan::output();
        } finally {
            config(['app.role' => 'cloud']);
        }
        DB::setDefaultConnection('tenant');

        $this->assertNotSame(0, $exit, 'a Branch Server never runs a transaction reset');
        $this->assertStringContainsString('never runs on a Branch Server', $output);
        $this->assertSame($before, $this->tableFingerprints($all), 'every row of every table is exactly where it was');
    }

    /**
     * The Branch Server's own tables (database/migrations/edge) are classified too. The whole-schema check in
     * CateringTenantResetMySqlTest sees only the tables present when it runs, and the Edge tests add these to the
     * shared test database later — the full suite found seven of them unclassified (22 Oct). Built here on purpose,
     * so the answer never depends on test order.
     */
    public function test_the_branch_server_tables_are_classified_too(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]),
            'the Edge schema must build here: ' . Artisan::output());
        $all = array_map(fn ($row) => array_values((array) $row)[0], DB::connection('tenant')->select('SHOW TABLES'));
        $this->assertNotEmpty(array_filter($all, fn ($t) => str_starts_with($t, 'edge_operational_')), 'the Edge tables must exist for this to mean anything');

        $r = new \ReflectionClass(\App\Console\Commands\TenantResetTransactionsCommand::class);
        $classified = array_merge($r->getConstant('WIPE_TABLES'), $r->getConstant('KEEP_SENTINELS'), $r->getConstant('KNOWN_KEPT'));
        $this->assertSame([], array_values(array_diff($all, $classified)), 'every table, Edge ones included, is a deliberate wipe or keep');
    }
}
