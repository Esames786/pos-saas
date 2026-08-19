<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-UAT-RESET-1 — the transaction reset must know about catering.
 *
 * tenant:reset-transactions truncates journals and stock ledgers. Every catering
 * table was unclassified, so on a catering tenant it would have removed the
 * accounting and stock rows while leaving the bookings, advances, refunds and
 * material issues that referenced them — a tenant full of documents claiming
 * postings that no longer existed. The command printed a warning about the
 * unclassified tables and carried on.
 *
 * These tests hold the classification in place. The one that matters most is the
 * last: no catering table may be unclassified, so a table added by a future
 * tranche cannot silently drift out of this contract the way these nineteen did.
 */
class CateringTenantResetMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    /** Documents: what the business did. Wiped by a transaction reset. */
    private const DOCUMENT_TABLES = [
        'catering_events', 'catering_estimates', 'catering_estimate_lines',
        'catering_estimate_line_cost_blocks',
        'catering_advances', 'catering_refunds', 'catering_final_invoices',
        'catering_production_releases', 'catering_production_release_lines',
        'catering_material_issues', 'catering_material_issue_lines',
        'catering_material_issue_events', 'catering_cost_snapshots',
        'catering_email_logs', 'catering_event_reminders',
    ];

    /** Configuration: how the business quotes and costs. Kept, like recipes. */
    private const CONFIG_TABLES = [
        'catering_product_profiles', 'catering_product_cost_blocks',
        'catering_material_rates', 'catering_material_commercial_rates',
        'catering_printer_mappings', 'catering_settings',
    ];

    private function commandConstant(string $name): array
    {
        $reflection = new \ReflectionClass(\App\Console\Commands\TenantResetTransactionsCommand::class);

        return $reflection->getConstant($name);
    }

    /**
     * Every catering document must be wiped, or a reset leaves bookings behind
     * whose journals it has just deleted.
     */
    public function test_every_catering_document_table_is_wiped_by_a_transaction_reset(): void
    {
        $wipe = $this->commandConstant('WIPE_TABLES');

        foreach (self::DOCUMENT_TABLES as $table) {
            $this->assertContains($table, $wipe,
                "{$table} holds catering documents — leaving it would orphan them against the wiped journals");
        }
    }

    /**
     * And every piece of catering configuration must survive. The Material Rate
     * Book is a caterer's price list; losing it to a transaction reset would be
     * losing master data, exactly as losing recipes would.
     */
    public function test_catering_configuration_survives_a_transaction_reset(): void
    {
        $wipe = $this->commandConstant('WIPE_TABLES');
        $kept = array_merge(
            $this->commandConstant('KEEP_SENTINELS'),
            $this->commandConstant('KNOWN_KEPT')
        );

        foreach (self::CONFIG_TABLES as $table) {
            $this->assertNotContains($table, $wipe, "{$table} is configuration, not a transaction");
            $this->assertContains($table, $kept, "{$table} must be a deliberate keep, not an omission");
        }
    }

    /** Children before parents, or the truncate order fights its own keys. */
    public function test_documents_are_wiped_children_first(): void
    {
        $wipe = array_values($this->commandConstant('WIPE_TABLES'));
        $position = fn (string $t) => array_search($t, $wipe, true);

        foreach ([
            ['catering_estimate_line_cost_blocks', 'catering_estimate_lines'],
            ['catering_estimate_lines', 'catering_estimates'],
            ['catering_production_release_lines', 'catering_production_releases'],
            ['catering_material_issue_lines', 'catering_material_issues'],
            ['catering_material_issue_events', 'catering_material_issues'],
            ['catering_estimates', 'catering_events'],
            ['catering_advances', 'catering_events'],
            ['catering_refunds', 'catering_events'],
        ] as [$child, $parent]) {
            $this->assertLessThan($position($parent), $position($child),
                "{$child} must be truncated before {$parent}");
        }
    }

    /**
     * THE ONE THAT MATTERS.
     *
     * The command already reports tables it has no opinion about — that warning
     * was printed for all nineteen catering tables and nobody was listening.
     * This turns the warning into a failing test, so the next catering tranche
     * that adds a table cannot quietly leave it outside the reset contract.
     */
    public function test_no_catering_table_is_left_unclassified(): void
    {
        DB::setDefaultConnection('tenant');

        $all = array_map(
            fn ($row) => array_values((array) $row)[0],
            DB::connection('tenant')->select('SHOW TABLES')
        );
        $catering = array_values(array_filter($all, fn ($t) => str_starts_with($t, 'catering_')));

        $this->assertNotEmpty($catering, 'the catering schema must exist for this test to mean anything');

        $classified = array_merge(
            $this->commandConstant('WIPE_TABLES'),
            $this->commandConstant('KEEP_SENTINELS'),
            $this->commandConstant('KNOWN_KEPT')
        );

        $unclassified = array_values(array_diff($catering, $classified));

        $this->assertSame([], $unclassified,
            'every catering table must be a deliberate wipe or a deliberate keep — '
            .'an unclassified one is how a reset silently orphans a tenant');
    }

    /** The tables named in the command must actually exist in the schema. */
    public function test_the_classification_names_real_tables(): void
    {
        foreach (array_merge(self::DOCUMENT_TABLES, self::CONFIG_TABLES) as $table) {
            $this->assertTrue(Schema::connection('tenant')->hasTable($table),
                "{$table} is classified but does not exist — the classification is stale");
        }
    }
}
