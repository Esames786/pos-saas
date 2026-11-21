<?php

namespace Tests\Feature\Pos;

use App\Http\Controllers\Edge\EdgeLocalCustomerController;
use App\Http\Middleware\EnsureRoutePermission;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * EDGE PHASE 4 F2 — the ONLINE add-address route is gated by the quick-store permission.
 *
 * `POST /pos/customers/{customer}/addresses` (tenant.pos.customers.addresses.store) is gated by EnsureRoutePermission
 * by ROUTE NAME, and no permission of that name is ever seeded (provisioner / migrations), so a cashier could not be
 * granted it and the shared modal's "Save address" answered 403 on Online while the Edge twin was gated by
 * tenant.pos.customers.quick-store. The middleware now aliases the route name to the quick-store permission (any-of
 * with the route's own name, so an explicit route-catalog grant keeps working). Driven through the middleware itself,
 * with a user whose `can()` is exactly the set under test — no database. The MySQL class
 * EdgeCustomerOnlineAddAddressPermissionHttpMySqlTest proves the same over the real Online HTTP route.
 */
class OnlineAddAddressPermissionAliasTest extends TestCase
{
    private const ADD_ADDRESS = 'tenant.pos.customers.addresses.store';
    private const QUICK_STORE = 'tenant.pos.customers.quick-store';

    public function test_the_online_add_address_route_is_aliased_to_the_edge_twin_permission(): void
    {
        $this->assertSame(self::QUICK_STORE, EdgeLocalCustomerController::PERMISSION, 'the Edge twin gates on quick-store');
        $this->assertSame(self::QUICK_STORE, EnsureRoutePermission::ROUTE_PERMISSION_ALIASES[self::ADD_ADDRESS] ?? null);
        $this->assertSame([self::QUICK_STORE, self::ADD_ADDRESS], EnsureRoutePermission::permissionsFor(self::ADD_ADDRESS));
        // a route without an alias is gated by exactly its own name
        $this->assertSame([self::QUICK_STORE], EnsureRoutePermission::permissionsFor(self::QUICK_STORE));
        $this->assertSame(['tenant.pos.store'], EnsureRoutePermission::permissionsFor('tenant.pos.store'));
    }

    public function test_a_cashier_holding_only_quick_store_passes_the_add_address_gate(): void
    {
        $this->assertSame('ok', $this->gate(self::ADD_ADDRESS, [self::QUICK_STORE]));
        $this->assertSame('ok', $this->gate(self::QUICK_STORE, [self::QUICK_STORE]));
    }

    public function test_a_cashier_without_quick_store_is_refused_on_both_twins(): void
    {
        foreach ([self::ADD_ADDRESS, self::QUICK_STORE] as $routeName) {
            try {
                $this->gate($routeName, ['tenant.pos.index', 'tenant.pos.store']);
                $this->fail("{$routeName} must be refused without quick-store");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $this->assertSame('Permission denied.', $e->getMessage());
            }
        }
    }

    /** A tenant that published the route name through the route catalog and granted it explicitly keeps working. */
    public function test_an_explicit_route_name_grant_still_passes(): void
    {
        $this->assertSame('ok', $this->gate(self::ADD_ADDRESS, [self::ADD_ADDRESS]));
    }

    /** The alias never widens another route: quick-store itself does not accept the add-address name. */
    public function test_the_alias_is_one_directional(): void
    {
        $this->expectException(HttpException::class);
        $this->gate(self::QUICK_STORE, [self::ADD_ADDRESS]);
    }

    /** @param list<string> $held */
    private function gate(string $routeName, array $held): mixed
    {
        $user = new class extends AuthUser {
            /** @var list<string> */
            public array $held = [];

            public function can($abilities, $arguments = []): bool
            {
                return in_array($abilities, $this->held, true);
            }
        };
        $user->held = $held;

        // No tenant is bound in the Feature harness, so the middleware resolves the `central` guard.
        $this->assertFalse(app()->bound('tenant'));
        auth('central')->setUser($user);

        $route = (new Route(['POST'], '/pos/customers/{customer}/addresses', fn () => 'ok'))->name($routeName);
        $request = Request::create('/pos/customers/1/addresses', 'POST');
        $request->setRouteResolver(fn () => $route);

        return (new EnsureRoutePermission())->handle($request, fn () => 'ok');
    }
}
