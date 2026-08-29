<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-STORE-2 — the many-booking migration, run for real.
 *
 * This migration runs against all nine tenant databases. Only Kashif has any
 * catering rows at all, so on the other eight — Khatri included — it must sail
 * through a table that exists and is empty. A migration that assumes rows are
 * there is one that fails on the tenants nobody is changing.
 *
 * It also has to be re-runnable. A deployment that half-applies, or a re-run
 * after multi-booking rows already exist, must add nothing and break nothing.
 *
 * The migration is executed directly here rather than described, so what is
 * proved is what will actually happen on deployment night.
 */
class CateringStoreIssueMigrationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PIVOT = 'catering_material_issue_events';

    private object $migration;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->migration = require base_path(
            'database/migrations/tenant/2026_08_18_000001_let_a_store_issue_reference_many_bookings.php'
        );

        // These tests roll the migration back and forward, so the table's very
        // existence is the thing under test. Put the schema back before cleaning
        // it, or a previous case's down() leaves nothing to clean.
        $this->migration->up();

        $this->cleanTenant([
            self::PIVOT, 'catering_material_issue_lines', 'catering_material_issues',
            'catering_estimate_lines', 'catering_estimates', 'catering_refunds',
            'catering_advances', 'catering_events',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'products', 'categories', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
    }

    /**
     * Leave the schema as the rest of the suite expects to find it. A test that
     * rolls a migration back must not hand the next file a missing table.
     */
    protected function tearDown(): void
    {
        try {
            $this->migration->up();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** A historical issue carrying the legacy single-booking column. */
    private function legacyIssue(string $issueNo, ?int $eventId): int
    {
        return DB::connection('tenant')->table('catering_material_issues')->insertGetId([
            'issue_no' => $issueNo,
            'catering_production_release_id' => null,
            'catering_event_id' => $eventId,
            'branch_id' => $this->branchId,
            'status' => 'issued',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEvent(string $eventNo): int
    {
        return DB::connection('tenant')->table('catering_events')->insertGetId([
            'event_no' => $eventNo,
            'branch_id' => $this->branchId,
            'customer_name' => 'Migration Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 100,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pivotCount(): int
    {
        return (int) DB::connection('tenant')->table(self::PIVOT)->count();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** History is not lost: every issue that named a booking still names it. */
    public function test_up_backfills_every_existing_single_booking_reference(): void
    {
        $this->migration->down();

        $eventA = $this->makeEvent('EV-MIG-0001');
        $eventB = $this->makeEvent('EV-MIG-0002');
        $issueA = $this->legacyIssue('MI-MIG-0001', $eventA);
        $issueB = $this->legacyIssue('MI-MIG-0002', $eventB);
        $general = $this->legacyIssue('MI-MIG-0003', null);

        $this->migration->up();

        $this->assertTrue(Schema::connection('tenant')->hasTable(self::PIVOT));
        $this->assertSame(2, $this->pivotCount(), 'two referenced issues, one general one');

        $rows = DB::connection('tenant')->table(self::PIVOT)
            ->pluck('catering_event_id', 'catering_material_issue_id');

        $this->assertSame($eventA, (int) $rows[$issueA]);
        $this->assertSame($eventB, (int) $rows[$issueB]);
        $this->assertArrayNotHasKey($general, $rows->all(),
            'an issue that referenced nothing still references nothing');
    }

    /** Running it twice adds nothing — a half-applied deploy can be re-run. */
    public function test_up_is_idempotent(): void
    {
        $this->migration->down();
        $event = $this->makeEvent('EV-MIG-0010');
        $this->legacyIssue('MI-MIG-0010', $event);

        $this->migration->up();
        $first = $this->pivotCount();

        $this->migration->up();

        $this->assertSame($first, $this->pivotCount(), 'the same reference is not written twice');
        $this->assertSame(1, $first);
    }

    /**
     * Eight of nine tenants have the catering tables and no catering rows. The
     * migration must be a no-op there rather than an incident.
     */
    public function test_up_succeeds_on_a_tenant_with_no_catering_data_at_all(): void
    {
        $this->migration->down();

        DB::connection('tenant')->table('catering_material_issues')->delete();
        DB::connection('tenant')->table('catering_events')->delete();

        $this->migration->up();

        $this->assertTrue(Schema::connection('tenant')->hasTable(self::PIVOT));
        $this->assertSame(0, $this->pivotCount());
    }

    /** Rollback drops the relation and leaves the legacy column in place. */
    public function test_down_removes_the_pivot_and_keeps_the_legacy_column(): void
    {
        $this->migration->down();
        $event = $this->makeEvent('EV-MIG-0020');
        $issue = $this->legacyIssue('MI-MIG-0020', $event);
        $this->migration->up();

        $this->migration->down();

        $this->assertFalse(Schema::connection('tenant')->hasTable(self::PIVOT));
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('catering_material_issues', 'catering_event_id'),
            'the legacy column is what a rollback has left to remember bookings by'
        );
        $this->assertSame($event, (int) DB::connection('tenant')->table('catering_material_issues')
            ->where('id', $issue)->value('catering_event_id'));
    }

    /**
     * And re-applying rebuilds from that column. This is why new writes still
     * mirror the first booking into it — without the mirror, a rollback would
     * leave every issue pointing at nothing at all.
     */
    public function test_re_up_rebuilds_the_relations_from_the_legacy_column(): void
    {
        $this->migration->down();
        $event = $this->makeEvent('EV-MIG-0030');
        $issue = $this->legacyIssue('MI-MIG-0030', $event);

        $this->migration->up();
        $this->migration->down();
        $this->migration->up();

        $this->assertSame(1, $this->pivotCount());
        $this->assertSame($event, (int) DB::connection('tenant')->table(self::PIVOT)
            ->where('catering_material_issue_id', $issue)->value('catering_event_id'));
    }

    /** Structure, not business. The migration posts nothing and moves no stock. */
    public function test_the_migration_touches_no_finance_or_stock(): void
    {
        $this->migration->down();
        $event = $this->makeEvent('EV-MIG-0040');
        $this->legacyIssue('MI-MIG-0040', $event);

        $before = $this->ledgerCounts();

        $this->migration->up();
        $this->migration->down();
        $this->migration->up();

        $this->assertSame($before, $this->ledgerCounts());
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        $c = DB::connection('tenant');

        return [
            'journal_entries' => (int) $c->table('journal_entries')->count(),
            'journal_lines' => (int) $c->table('journal_lines')->count(),
            'stock_ledgers' => (int) $c->table('stock_ledgers')->count(),
        ];
    }
}
