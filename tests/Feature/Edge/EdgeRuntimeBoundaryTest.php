<?php

namespace Tests\Feature\Edge;

use App\Http\Middleware\EnsureEdgeRuntimeRouteAllowed;
use App\Support\EdgeRuntime;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * EDGE-RUNTIME-BOUNDARY-1 (Q) — the security route boundary. Exercises the REAL middleware
 * (EnsureEdgeRuntimeRouteAllowed) against representative route names from every cloud-only group, in
 * both runtime modes. Proves default-DENY on a Branch Server and full pass-through on Cloud. This is
 * not a menu-visibility test — it drives the actual boundary code.
 */
class EdgeRuntimeBoundaryTest extends TestCase
{
    /** Representative cloud-only route names that MUST be denied on a Branch Server. */
    public static function cloudOnlyRoutes(): array
    {
        return array_map(fn ($n) => [$n], [
            // central / SaaS admin, provisioning, billing, plans, upgrades, master tenant mgmt
            'central.tenants.index', 'central.tenants.provision', 'central.invoices.index',
            'central.plans.index', 'central.subscription-requests.approve',
            // backup / restore / reset / sync administration
            'central.tenants.sync-all', 'central.tenants.backup-all', 'central.tenant-backups.restore',
            // tenant cloud billing / upgrades
            'tenant.billing.index', 'tenant.billing.upgrade.store',
            // purchasing / GRN / AP
            'tenant.purchase-orders.index', 'tenant.goods-receipts.store', 'tenant.supplier-payments.store',
            // AR / GL / chart of accounts / finance posting
            'tenant.finance.accounts.index', 'tenant.finance.journal-entries.index',
            'tenant.finance.general-ledger.index', 'tenant.finance.customer-payments.store',
            // manufacturing
            'tenant.manufacturing.production-orders.store', 'tenant.manufacturing.bom.index',
            // cloud-side edge administration + marketing + central pairing API
            'tenant.offline-edge.index', 'public.home', 'edge.api.pair',
            // future POS surface is NOT allowlisted yet this sprint
            'tenant.pos.store', 'tenant.shifts.store', 'tenant.sales-orders.split-bill.store',
            // an anonymous (unnamed) route
            null,
        ]);
    }

    public static function edgeAllowedRoutes(): array
    {
        return [['edge.local.health'], ['edge.local.ready'], ['edge.local.build-info']];
    }

    private function runBoundary(?string $routeName): mixed
    {
        $request = Request::create('/x', 'GET');
        $route = (new Route(['GET'], '/x', []))->name($routeName ?? '');
        if ($routeName === null) {
            $route = new Route(['GET'], '/x', []); // no name
        }
        $request->setRouteResolver(fn () => $route);

        return (new EnsureEdgeRuntimeRouteAllowed())->handle($request, fn () => response('PASS'));
    }

    #[DataProvider('cloudOnlyRoutes')]
    public function test_branch_server_denies_cloud_only_routes(?string $routeName): void
    {
        config(['app.role' => 'branch_server']);
        $this->assertTrue(EdgeRuntime::isBranchServer());

        try {
            $this->runBoundary($routeName);
            $this->fail('Branch Server must DENY cloud-only route: ' . ($routeName ?? '(unnamed)'));
        } catch (NotFoundHttpException $e) {
            $this->assertStringContainsStringIgnoringCase('Edge', $e->getMessage());
        }
    }

    #[DataProvider('edgeAllowedRoutes')]
    public function test_branch_server_allows_edge_local_routes(string $routeName): void
    {
        config(['app.role' => 'branch_server']);
        $resp = $this->runBoundary($routeName);
        $this->assertSame('PASS', $resp->getContent(), "Edge route must be allowed on a Branch Server: $routeName");
    }

    #[DataProvider('cloudOnlyRoutes')]
    public function test_cloud_runtime_is_unaffected(?string $routeName): void
    {
        config(['app.role' => 'cloud']);
        $this->assertTrue(EdgeRuntime::isCloud());
        // On cloud the boundary is a pure pass-through — even cloud-only routes go straight through.
        $this->assertSame('PASS', $this->runBoundary($routeName)->getContent());
    }

    public function test_explicit_allowlist_denies_undeclared_edge_local_route(): void
    {
        config(['app.role' => 'branch_server']);
        // The three declared endpoints are allowed...
        $this->assertSame('PASS', $this->runBoundary('edge.local.health')->getContent());
        // ...but a NEW edge.local.* route is NOT auto-exposed (no wildcard) — must be added explicitly.
        try {
            $this->runBoundary('edge.local.dangerous-admin');
            $this->fail('A new edge.local.* route must be denied until explicitly allowlisted.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_cloud_does_not_register_edge_local_but_keeps_saas_routes(): void
    {
        // Default boot is cloud. The appliance-local Edge endpoints are NOT registered on Cloud
        // (so /edge/local/* is a 404 — no runtime/build fingerprint exposed publicly)...
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('edge.local.health'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('edge.local.build-info'));
        // ...while the normal Cloud SaaS routes remain registered.
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('tenant.pos.store'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('central.tenants.index'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('edge.api.pair'));
    }

    public function test_packaged_edge_artifact_on_cloud_fails_closed(): void
    {
        // Simulate a packaged artifact marker while APP_ROLE is (mis)set to cloud.
        config(['app.role' => 'cloud']);
        $marker = base_path('edge-build-manifest.json');
        $pre = is_file($marker);
        if (! $pre) {
            file_put_contents($marker, json_encode(['runtime_mode_supported' => 'branch_server']));
        }
        try {
            $this->assertTrue(\App\Support\EdgeRuntime::isPackagedEdgeArtifact());
            $this->assertNotEmpty(\App\Support\EdgeRuntime::bootProblems(), 'A packaged Edge artifact must not boot as cloud.');
            $this->expectException(RuntimeException::class);
            \App\Support\EdgeRuntime::assertBootConfig();
        } finally {
            if (! $pre) {
                @unlink($marker);
            }
        }
    }

    public function test_mode_fails_closed_on_unrecognized_role(): void
    {
        config(['app.role' => 'branchserver']); // typo — must NOT silently become cloud
        $this->expectException(RuntimeException::class);
        EdgeRuntime::mode();
    }

    public function test_empty_role_defaults_to_cloud(): void
    {
        config(['app.role' => null]);
        $this->assertSame('cloud', EdgeRuntime::mode());
        config(['app.role' => '']);
        $this->assertSame('cloud', EdgeRuntime::mode());
    }

    /**
     * CLOSURE-1 boot-proof regression: isCloudSafe() is used at CONFIG-LOAD time (config/filesystems)
     * and MUST never throw — even for an invalid role — or the framework cannot bootstrap far enough
     * to render a controlled error (the invalid-role artifact leaked a fatal + file path instead of a
     * clean refusal). It fails closed: only a valid cloud role is "cloud".
     */
    public function test_is_cloud_safe_never_throws_and_fails_closed(): void
    {
        config(['app.role' => 'bogus_role']);
        $this->assertFalse(EdgeRuntime::isCloudSafe(), 'An invalid role must not be treated as cloud.');

        config(['app.role' => 'branch_server']);
        $this->assertFalse(EdgeRuntime::isCloudSafe());

        config(['app.role' => 'cloud']);
        $this->assertTrue(EdgeRuntime::isCloudSafe());

        config(['app.role' => null]);
        $this->assertTrue(EdgeRuntime::isCloudSafe());

        config(['app.role' => '']);
        $this->assertTrue(EdgeRuntime::isCloudSafe());
    }

    public function test_branch_server_boot_problems_detect_missing_config(): void
    {
        config(['app.role' => 'branch_server']);
        $this->assertSame([], EdgeRuntime::bootProblems(), 'Healthy config has no boot problems.');

        config(['edge.app_version' => '']);
        $this->assertNotEmpty(EdgeRuntime::bootProblems(), 'Missing app_version must be a boot problem.');

        $this->expectException(RuntimeException::class);
        EdgeRuntime::assertBootConfig();
    }
}
