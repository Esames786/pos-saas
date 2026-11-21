<?php

namespace Tests\MySql\Support;

use App\Services\Edge\MigrationSubstitutes\EdgeMigrationSubstitute;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Test-only substitute for the planted gate migration: creates a probe table the canonical body never would (its body throws). */
final class EdgeGateProbeSubstitute implements EdgeMigrationSubstitute
{
    public const MIGRATION = '2026_12_31_000003_edge_substitute_probe';

    public const TABLE = 'edge_substitute_probe';

    public static function forMigration(): string
    {
        return self::MIGRATION;
    }

    public static function why(): string
    {
        return 'test probe: creates ' . self::TABLE . ' instead of running the planted body';
    }

    public function up(): void
    {
        Schema::connection('tenant')->create(self::TABLE, fn (Blueprint $t) => $t->id());
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists(self::TABLE);
    }
}
