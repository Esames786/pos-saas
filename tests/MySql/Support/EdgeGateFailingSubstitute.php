<?php

namespace Tests\MySql\Support;

use App\Services\Edge\MigrationSubstitutes\EdgeMigrationSubstitute;
use RuntimeException;

/** Test-only substitute whose body fails: proves the canonical name is recorded in the ledger ONLY after a successful substitute execution. */
final class EdgeGateFailingSubstitute implements EdgeMigrationSubstitute
{
    public const MIGRATION = '2026_12_31_000004_edge_failing_substitute_probe';

    public static function forMigration(): string
    {
        return self::MIGRATION;
    }

    public static function why(): string
    {
        return 'test probe: the substitute body throws (schema precondition not met)';
    }

    public function up(): void
    {
        throw new RuntimeException('SUBSTITUTE_PRECONDITION_FAILED: probe — the expected schema is not there');
    }

    public function down(): void
    {
    }
}
