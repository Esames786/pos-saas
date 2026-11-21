<?php

namespace Tests;

use App\Services\Edge\EdgeTenantMigrationManifest;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * OWNER DECISION (16 Nov 2026, item 5): an OWNER_REVIEW_REQUIRED tenant migration whose OWNER_APPROVAL is still
     * PENDING is a fail-closed RELEASE BLOCKER (plan, db-init, upgrade, artifact build, update install). The suites
     * exercise an INSTALLABLE tree, so every test starts with the real manifest's pending approvals granted by a
     * test-only marker (never an owner approval; ignored outside PHPUnit). The REAL approval state is asserted by
     * EdgeTenantMigrationManifestTest and EdgeTenantMigrationGateMySqlTest, which clear this seam explicitly.
     */
    protected function setUp(): void
    {
        parent::setUp();
        EdgeTenantMigrationManifest::overrideForTests(EdgeTenantMigrationManifest::load(base_path(), ignoreTestOverride: true)->withOwnerApprovalsGrantedForTests());
    }

    protected function tearDown(): void
    {
        EdgeTenantMigrationManifest::overrideForTests(null);
        parent::tearDown();
    }
}
