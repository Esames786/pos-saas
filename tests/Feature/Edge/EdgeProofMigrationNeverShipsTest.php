<?php

namespace Tests\Feature\Edge;

use Tests\TestCase;

/**
 * EDGE-UPDATE-SCHEMA-IN-NEW-RUNTIME-1 safety gate: the clean-machine proof plants a fixture migration into
 * database/migrations/edge for the seconds it takes to build its package B and removes it again. If a crashed run ever
 * left it behind, the release builder (which ignores untracked files) would ship it. This gate fails the tree instead.
 */
class EdgeProofMigrationNeverShipsTest extends TestCase
{
    public function test_no_proof_marker_migration_exists_under_the_shipped_migration_paths(): void
    {
        $hits = [];
        foreach (['database/migrations', 'database/migrations/tenant', 'database/migrations/edge'] as $dir) {
            foreach (glob(base_path($dir . '/*.php')) ?: [] as $file) {
                if (str_contains(basename($file), 'clean_machine_proof')) {
                    $hits[] = $file;
                }
            }
        }
        $this->assertSame([], $hits, 'a clean-machine proof fixture migration is present in the tree — remove it; it must never ship');
        $this->assertFileExists(base_path('tests/Fixtures/edge/migrations/2099_09_26_000000_clean_machine_proof_marker.php'), 'the fixture lives under tests/Fixtures only');
    }
}
