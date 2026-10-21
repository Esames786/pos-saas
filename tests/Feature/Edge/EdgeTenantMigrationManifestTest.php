<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgePackageBuilder;
use App\Services\Edge\EdgeTenantMigrationManifest;
use App\Services\Edge\EdgeUpdatePackageService;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * OWNER DECISION D7 — CI GATE for the Edge tenant-migration manifest.
 *
 * Proves, on the real tree: the manifest loads and validates; EVERY tenant migration file has an entry with the
 * owner's ten fields; every entry points to an existing file or is marked "pending merge from canonical <hash>"
 * (and the marker is rejected once the file exists); no CLOUD_ONLY migration is in the artifact plan while the manifest
 * itself ships; every EDGE_REQUIRED / EDGE_SCHEMA_SAFE migration names an upgrade test that exists; destructive /
 * data-rewrite migrations carry OWNER_REVIEW_REQUIRED. Plus the mechanics on synthetic trees: unclassified = refused
 * artifact plan (RELEASE BLOCKER), CLOUD_ONLY = excluded, package audit flags both, plan decisions + stable hash.
 */
class EdgeTenantMigrationManifestTest extends TestCase
{
    private static ?array $plan = null;

    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $d) {
            $this->rrmdir($d);
        }
        parent::tearDown();
    }

    private function manifest(): EdgeTenantMigrationManifest
    {
        return EdgeTenantMigrationManifest::load(base_path());
    }

    /** @return array<string,true> artifact-relative paths of the real plan (computed once — it walks vendor) */
    private function artifactPlan(): array
    {
        return self::$plan ??= array_flip(EdgeArtifactBuilder::fromConfig()->plan(base_path()));
    }

    private function tenantMigrationNames(): array
    {
        $names = array_map(fn ($f) => basename($f, '.php'), glob(base_path('database/migrations/tenant/*.php')) ?: []);
        sort($names);

        return $names;
    }

    // ── the real manifest ────────────────────────────────────────────────────

    public function test_the_manifest_loads_and_validates_with_no_structural_problems(): void
    {
        $data = include base_path(EdgeTenantMigrationManifest::FILE);
        $this->assertSame([], EdgeTenantMigrationManifest::problems($data), 'the manifest must validate (every field, every enum)');
        $m = $this->manifest();
        $this->assertMatchesRegularExpression('/^edge-tenant-migration-manifest@\d{4}-\d{2}-\d{2}\.\d+$/', $m->version());
        $this->assertSame('database/migrations/tenant', $m->meta()['governs']);
    }

    public function test_every_tenant_migration_file_in_the_tree_has_a_manifest_entry_with_all_ten_fields(): void
    {
        $m = $this->manifest();
        $missing = [];
        foreach ($this->tenantMigrationNames() as $name) {
            $entry = $m->entry($name);
            if ($entry === null) {
                $missing[] = $name;
                continue;
            }
            foreach (EdgeTenantMigrationManifest::FIELDS as $field) {
                $this->assertArrayHasKey($field, $entry, "{$name} lacks {$field}");
            }
            $this->assertContains($entry['CLASSIFICATION'], EdgeTenantMigrationManifest::CLASSES, $name);
        }
        $this->assertSame([], $missing, 'UNCLASSIFIED tenant migration(s) = RELEASE BLOCKER: ' . implode(', ', $missing));
        $this->assertGreaterThanOrEqual(173, count($this->tenantMigrationNames()));
    }

    public function test_every_manifest_entry_points_to_an_existing_file_or_is_marked_pending_merge_and_the_marker_is_dropped_once_the_file_lands(): void
    {
        $m = $this->manifest();
        $present = array_flip($this->tenantMigrationNames());
        $stale = $unknown = [];
        foreach ($m->entries() as $name => $entry) {
            $pending = $m->pendingMergeFrom($name);
            if (isset($present[$name])) {
                if ($pending !== null) {
                    $stale[] = "{$name} (file present but still PENDING_MERGE_FROM {$pending})";
                }
            } elseif ($pending === null) {
                $unknown[] = $name;
            }
        }
        $this->assertSame([], $stale, 'drop the PENDING_MERGE_FROM marker once the canonical merge lands the file: ' . implode(', ', $stale));
        $this->assertSame([], $unknown, 'manifest entries without a file and without a pending-merge marker: ' . implode(', ', $unknown));
    }

    public function test_the_pending_canonical_set_is_exactly_the_33_migrations_the_merge_brings(): void
    {
        $m = $this->manifest();
        $pending = array_filter(array_keys($m->entries()), fn ($n) => $m->pendingMergeFrom($n) !== null && ! is_file(base_path("database/migrations/tenant/{$n}.php")));
        $this->assertLessThanOrEqual(33, count($pending), 'pending entries shrink as canonical files land; never grow without a new reconciliation');
        foreach ($pending as $n) {
            $this->assertSame('99e9365', $m->pendingMergeFrom($n), $n);
        }
    }

    public function test_no_cloud_only_migration_is_in_the_artifact_plan_and_the_manifest_itself_ships(): void
    {
        $m = $this->manifest();
        $plan = $this->artifactPlan();
        $this->assertArrayHasKey(EdgeTenantMigrationManifest::FILE, $plan, 'the manifest must ship with the appliance (the new runtime enforces it)');
        foreach ($m->packageExcludedPaths() as $rel) {
            $this->assertArrayNotHasKey($rel, $plan, "CLOUD_ONLY migration must not ship: {$rel}");
        }
        foreach ($this->tenantMigrationNames() as $name) {
            $rel = "database/migrations/tenant/{$name}.php";
            if ($m->classification($name) === EdgeTenantMigrationManifest::CLOUD_ONLY) {
                $this->assertArrayNotHasKey($rel, $plan, $rel);
            } else {
                $this->assertArrayHasKey($rel, $plan, "SHIP_AND_RUN migration missing from the artifact plan: {$rel}");
            }
        }
        $this->assertSame([], $m->artifactProblems(array_keys($plan)));
    }

    public function test_every_shipped_migration_names_an_upgrade_test_that_exists_and_cloud_only_names_none(): void
    {
        $m = $this->manifest();
        foreach ($m->entries() as $name => $entry) {
            if ($entry['CLASSIFICATION'] === EdgeTenantMigrationManifest::CLOUD_ONLY) {
                $this->assertSame('n/a: CLOUD_ONLY', $entry['UPGRADE_TEST'], $name);
                $this->assertSame(EdgeTenantMigrationManifest::PACKAGE_EXCLUDE, $entry['PACKAGE_DECISION'], $name);
                continue;
            }
            $this->assertSame(EdgeTenantMigrationManifest::PACKAGE_SHIP_AND_RUN, $entry['PACKAGE_DECISION'], $name);
            foreach ((array) $entry['UPGRADE_TEST'] as $ref) {
                [$class, $method] = array_pad(explode('::', $ref, 2), 2, null);
                $this->assertTrue(class_exists($class), "{$name}: upgrade test class {$class} does not exist");
                if ($method !== null) {
                    $this->assertTrue(method_exists($class, $method), "{$name}: upgrade test {$class}::{$method} does not exist");
                }
            }
        }
    }

    public function test_destructive_or_data_rewrite_migrations_carry_owner_review_required(): void
    {
        $m = $this->manifest();
        foreach ($m->entries() as $name => $entry) {
            if ($entry['ADDITIVE_ONLY'] === false || $entry['DATA_REWRITE'] === true) {
                $this->assertTrue($entry['OWNER_REVIEW_REQUIRED'] ?? false, "{$name} is destructive/rewrites data and must be OWNER_REVIEW_REQUIRED");
            }
        }
        $this->assertNotEmpty($m->ownerReviewRequired());
        // The loader itself refuses a destructive entry without the flag.
        $bad = ['manifest_version' => 'x@2026-01-01.1', 'migrations' => ['2026_01_01_000001_probe' => $this->entry('2026_01_01_000001_probe', EdgeTenantMigrationManifest::EDGE_REQUIRED, ['ADDITIVE_ONLY' => false, 'OWNER_REVIEW_REQUIRED' => false])]];
        $this->assertNotEmpty(array_filter(EdgeTenantMigrationManifest::problems($bad), fn ($p) => str_contains($p, 'OWNER_REVIEW_REQUIRED')));
    }

    public function test_cloud_only_entries_never_pull_a_cloud_only_class_into_the_package_and_shipped_entries_depend_on_none(): void
    {
        $m = $this->manifest();
        foreach ($m->entries() as $name => $entry) {
            $dep = (string) $entry['CLOUD_ONLY_CLASS_DEPENDENCY'];
            if ($entry['CLASSIFICATION'] !== EdgeTenantMigrationManifest::CLOUD_ONLY && $dep !== 'none') {
                // The only allowed non-none dependency of a SHIPPED migration is the kept catering preset seeder (P5).
                $this->assertStringContainsString('CateringServiceTimePresetSeeder', $dep, "{$name}: a shipped migration may not depend on a Cloud-only class");
                $this->assertArrayHasKey('database/seeders/Tenant/CateringServiceTimePresetSeeder.php', $this->artifactPlan());
            }
        }
        $this->assertStringContainsString('AreaLocator', $m->entry('2026_10_16_000006_create_delivery_zone_places_table')['CLOUD_ONLY_CLASS_DEPENDENCY']);
        $this->assertArrayNotHasKey('app/Services/OnlineOrdering/AreaLocator.php', array_filter($this->artifactPlan(), fn ($v, $k) => str_contains($k, 'OnlineOrdering/AreaLocator'), ARRAY_FILTER_USE_BOTH));
    }

    public function test_the_counts_per_class_match_the_report(): void
    {
        $m = $this->manifest();
        $counts = $m->counts();
        // CANONICAL MERGE (D8, 20 Oct 2026): tree (173) + canonical 99e9365 (33) + canonical after 99e9365 classified as the merge
        // landed them (3 in chunk 11: send_trigger CLOUD_ONLY, product_pack_units + purchase_discounts EDGE_REQUIRED).
        // chunk 12: + add_sale_line_batch (EDGE_REQUIRED).
        // chunk 13: + add_bill_barcode_toggle (EDGE_REQUIRED).
        // chunk 14: + add_refund_shift_to_sales_returns (EDGE_REQUIRED).
        // CANONICAL_RELEASE_FREEZE 025728d8 (22 Oct 2026): + restocked, ask_prescription, return_without_bill_schema (EDGE_REQUIRED ×3).
        $this->assertSame(215, array_sum($counts), 'tree (173) + canonical pending (33) + post-99e9365 (6) + 025728d8 (3)');
        $this->assertSame(24, $counts[EdgeTenantMigrationManifest::CLOUD_ONLY]);
        $baselineCloudOnly = array_filter($m->entries(), fn ($e) => ($e['BASELINE'] ?? false) && $e['CLASSIFICATION'] === EdgeTenantMigrationManifest::CLOUD_ONLY);
        $this->assertSame([], array_keys($baselineCloudOnly), 'baseline rule: no 0.7.0 migration is CLOUD_ONLY in this manifest version');
    }

    // ── plan decisions + hash ────────────────────────────────────────────────

    public function test_the_plan_runs_classified_skips_cloud_only_and_blocks_unclassified_with_a_stable_hash(): void
    {
        $m = $this->manifest();
        $required = $m->ofClass(EdgeTenantMigrationManifest::EDGE_REQUIRED)[0];
        $cloudOnly = $m->ofClass(EdgeTenantMigrationManifest::CLOUD_ONLY)[0];
        $plan = $m->plan(['tenant' => [$cloudOnly, $required, '2026_12_31_000001_unclassified_probe'], 'edge' => ['2026_12_31_000002_edge_probe']]);

        $this->assertSame(EdgeTenantMigrationManifest::PLAN_FORMAT, $plan['format']);
        $this->assertSame([$required, '2026_12_31_000002_edge_probe'], $plan['run']);
        $this->assertSame([$cloudOnly], $plan['skip']);
        $this->assertSame(['2026_12_31_000001_unclassified_probe'], $plan['blockers']);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_RELEASE_BLOCKER, $plan['verdict']);
        $byName = array_column($plan['entries'], null, 'migration');
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_SKIP_CLOUD_ONLY, $byName[$cloudOnly]['decision']);
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_ABORT_UNCLASSIFIED, $byName['2026_12_31_000001_unclassified_probe']['decision']);
        $this->assertSame(EdgeTenantMigrationManifest::UNCLASSIFIED, $byName['2026_12_31_000001_unclassified_probe']['classification']);
        $this->assertSame(EdgeTenantMigrationManifest::EDGE_OWNED, $byName['2026_12_31_000002_edge_probe']['classification']);
        $this->assertSame(['migration', 'group', 'classification', 'decision', 'tables', 'owner_review_required'], array_keys($plan['entries'][0]), 'stable entry shape');

        // Deterministic: order-independent input, same hash; any change in the set changes the hash; empty plan hashes too.
        $again = $m->plan(['tenant' => [$required, '2026_12_31_000001_unclassified_probe', $cloudOnly], 'edge' => ['2026_12_31_000002_edge_probe']]);
        $this->assertSame($plan['plan_hash'], $again['plan_hash']);
        $this->assertStringStartsWith('sha256:', $plan['plan_hash']);
        $this->assertNotSame($plan['plan_hash'], $m->plan(['tenant' => [$required], 'edge' => []])['plan_hash']);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $m->plan(['tenant' => [], 'edge' => []])['verdict']);
        $this->assertSame($m->plan(['tenant' => [], 'edge' => []])['plan_hash'], EdgeTenantMigrationManifest::planHash([]));
    }

    // ── artifact builder + package audit mechanics on synthetic trees ─────────

    public function test_the_artifact_builder_refuses_a_tree_whose_tenant_migration_is_unclassified_and_excludes_cloud_only(): void
    {
        $root = $this->syntheticTree(['2026_01_01_000001_probe_a', '2026_01_01_000002_probe_b', '2026_01_01_000003_probe_c']);
        $builder = new EdgeArtifactBuilder(['include' => ['database/migrations'], 'exclude' => [], 'forbidden' => []]);

        // no manifest at all → refused
        try {
            $builder->plan($root);
            $this->fail('a tree with tenant migrations and no manifest must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ARTIFACT_MIGRATION_MANIFEST_MISSING', $e->getMessage());
        }

        // manifest lacking probe_c → RELEASE BLOCKER
        $this->writeManifest($root, [
            '2026_01_01_000001_probe_a' => $this->entry('2026_01_01_000001_probe_a', EdgeTenantMigrationManifest::EDGE_REQUIRED),
            '2026_01_01_000002_probe_b' => $this->entry('2026_01_01_000002_probe_b', EdgeTenantMigrationManifest::CLOUD_ONLY),
        ]);
        try {
            $builder->plan($root);
            $this->fail('an unclassified tenant migration must refuse the artifact plan');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ARTIFACT_UNCLASSIFIED_TENANT_MIGRATION', $e->getMessage());
            $this->assertStringContainsString('probe_c', $e->getMessage());
        }

        // fully classified → CLOUD_ONLY dropped, the rest + the manifest ship
        $this->writeManifest($root, [
            '2026_01_01_000001_probe_a' => $this->entry('2026_01_01_000001_probe_a', EdgeTenantMigrationManifest::EDGE_REQUIRED),
            '2026_01_01_000002_probe_b' => $this->entry('2026_01_01_000002_probe_b', EdgeTenantMigrationManifest::CLOUD_ONLY),
            '2026_01_01_000003_probe_c' => $this->entry('2026_01_01_000003_probe_c', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE),
        ]);
        $plan = $builder->plan($root);
        $this->assertContains('database/migrations/tenant/2026_01_01_000001_probe_a.php', $plan);
        $this->assertNotContains('database/migrations/tenant/2026_01_01_000002_probe_b.php', $plan, 'CLOUD_ONLY is excluded from the package');
        $this->assertContains('database/migrations/tenant/2026_01_01_000003_probe_c.php', $plan);
        $this->assertContains(EdgeTenantMigrationManifest::FILE, $plan);
        $this->assertContains('database/migrations/edge/2026_01_01_000001_edge_probe.php', $plan, 'edge migrations are not governed');
    }

    public function test_the_package_audit_flags_a_cloud_only_or_unclassified_tenant_migration_inside_a_package(): void
    {
        $pkg = $this->tmp();
        $m = $this->manifest();
        $cloudOnly = $m->ofClass(EdgeTenantMigrationManifest::CLOUD_ONLY)[0];
        mkdir($pkg . '/app/database/migrations/tenant', 0775, true);
        copy(base_path(EdgeTenantMigrationManifest::FILE), $pkg . '/app/' . EdgeTenantMigrationManifest::FILE);
        file_put_contents($pkg . '/app/database/migrations/tenant/' . $cloudOnly . '.php', '<?php // planted');
        file_put_contents($pkg . '/app/database/migrations/tenant/2026_12_31_000001_unclassified_probe.php', '<?php // planted');

        $packages = new EdgePackageBuilder(EdgeArtifactBuilder::fromConfig(), app(EdgeUpdatePackageService::class));
        $audit = $packages->audit($pkg);
        $this->assertFalse($audit['ok']);
        $this->assertSame(2, $audit['tenant_migrations_audited']);
        $this->assertCount(2, $audit['tenant_migration_problems']);
        $this->assertStringContainsString($cloudOnly, implode("\n", $audit['tenant_migration_problems']));
        $this->assertStringContainsString('UNCLASSIFIED', implode("\n", $audit['tenant_migration_problems']));

        // Without a manifest in the package the audit says so (RELEASE BLOCKER).
        unlink($pkg . '/app/' . EdgeTenantMigrationManifest::FILE);
        $this->assertStringContainsString('without', implode("\n", $packages->audit($pkg)['tenant_migration_problems']));
    }

    public function test_the_loader_rejects_ship_no_run_and_wrong_enums(): void
    {
        $base = ['manifest_version' => 'x@2026-01-01.1'];
        $p = EdgeTenantMigrationManifest::problems($base + ['migrations' => ['2026_01_01_000001_probe' => $this->entry('2026_01_01_000001_probe', EdgeTenantMigrationManifest::CLOUD_ONLY, ['PACKAGE_DECISION' => 'SHIP_AND_RUN'])]]);
        $this->assertNotEmpty(array_filter($p, fn ($x) => str_contains($x, 'EXCLUDE')));
        $p = EdgeTenantMigrationManifest::problems($base + ['migrations' => ['2026_01_01_000001_probe' => $this->entry('2026_01_01_000001_probe', 'SHIP_NO_RUN')]]);
        $this->assertNotEmpty(array_filter($p, fn ($x) => str_contains($x, 'CLASSIFICATION')));
        $p = EdgeTenantMigrationManifest::problems($base + ['migrations' => ['2026_01_01_000001_probe' => $this->entry('2026_01_01_000001_probe', EdgeTenantMigrationManifest::EDGE_REQUIRED, ['LOCK_RISK' => 'huge'])]]);
        $this->assertNotEmpty(array_filter($p, fn ($x) => str_contains($x, 'LOCK_RISK')));
        $entry = $this->entry('2026_01_01_000001_probe', EdgeTenantMigrationManifest::EDGE_REQUIRED);
        unset($entry['UPGRADE_TEST']);
        $p = EdgeTenantMigrationManifest::problems($base + ['migrations' => ['2026_01_01_000001_probe' => $entry]]);
        $this->assertNotEmpty(array_filter($p, fn ($x) => str_contains($x, 'UPGRADE_TEST missing')));
        $this->assertSame([], EdgeTenantMigrationManifest::problems($base + ['migrations' => ['2026_01_01_000001_probe' => $this->entry('2026_01_01_000001_probe', EdgeTenantMigrationManifest::EDGE_REQUIRED)]]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function entry(string $name, string $class, array $overrides = []): array
    {
        $cloud = $class === EdgeTenantMigrationManifest::CLOUD_ONLY;

        return array_merge([
            'MIGRATION' => $name,
            'CLASSIFICATION' => $class,
            'TABLES_CHANGED' => ['probe'],
            'EDGE_CODE_REFERENCES' => ['none'],
            'ADDITIVE_ONLY' => true,
            'DATA_REWRITE' => false,
            'LOCK_RISK' => 'none',
            'CLOUD_ONLY_CLASS_DEPENDENCY' => 'none',
            'PACKAGE_DECISION' => $cloud ? EdgeTenantMigrationManifest::PACKAGE_EXCLUDE : EdgeTenantMigrationManifest::PACKAGE_SHIP_AND_RUN,
            'UPGRADE_TEST' => $cloud ? 'n/a: CLOUD_ONLY' : 'Tests\Feature\Edge\EdgeTenantMigrationManifestTest::test_the_manifest_loads_and_validates_with_no_structural_problems',
            'OWNER_REVIEW_REQUIRED' => false,
        ], $overrides);
    }

    private function syntheticTree(array $tenantNames): string
    {
        $root = $this->tmp();
        mkdir($root . '/database/migrations/tenant', 0775, true);
        mkdir($root . '/database/migrations/edge', 0775, true);
        foreach ($tenantNames as $n) {
            file_put_contents("{$root}/database/migrations/tenant/{$n}.php", '<?php // probe');
        }
        file_put_contents($root . '/database/migrations/edge/2026_01_01_000001_edge_probe.php', '<?php // probe');

        return $root;
    }

    private function writeManifest(string $root, array $migrations): void
    {
        file_put_contents($root . '/' . EdgeTenantMigrationManifest::FILE, '<?php return ' . var_export(['manifest_version' => 'x@2026-01-01.1', 'migrations' => $migrations], true) . ';');
    }

    private function tmp(): string
    {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-mig-test-' . Str::lower(Str::random(8));
        mkdir($d, 0775, true);
        $this->tempDirs[] = $d;

        return $d;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
