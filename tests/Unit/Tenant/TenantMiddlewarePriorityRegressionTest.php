<?php

namespace Tests\Unit\Tenant;

use App\Http\Middleware\IdentifyTenant;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Tests\TestCase;

/**
 * THE "NO DATABASE SELECTED" 500s — five occurrences across 2026-08-10/11 on /login.
 *
 * Session persistence needs the tenant database: the session guard resolves the authenticated
 * user from the TENANT users table, and StartSession saves the session even when an inner
 * middleware threw, because the routing pipeline converts inner exceptions to responses. With
 * IdentifyTenant priority-sorted after StartSession, anything thrown between the two (live case:
 * a stale login page POSTing with an expired CSRF token → 419) reached the session save with the
 * tenant connection never configured — "SQLSTATE[3D000] No database selected".
 *
 * Tenant identification depends on nothing but the request host, so it must simply run first.
 */
class TenantMiddlewarePriorityRegressionTest extends TestCase
{
    public function test_tenant_identification_outranks_the_session_in_middleware_priority(): void
    {
        $priority = (new \ReflectionProperty($kernel = app(\Illuminate\Contracts\Http\Kernel::class), 'middlewarePriority'))
            ->getValue($kernel);

        $tenantAt = array_search(IdentifyTenant::class, $priority, true);
        $sessionAt = array_search(StartSession::class, $priority, true);
        $cookiesAt = array_search(EncryptCookies::class, $priority, true);

        $this->assertNotFalse($tenantAt, 'IdentifyTenant must have an explicit priority.');
        $this->assertNotFalse($sessionAt, 'StartSession must have an explicit priority.');

        $this->assertLessThan(
            $sessionAt,
            $tenantAt,
            'IdentifyTenant must run BEFORE StartSession: the session save resolves the tenant '
            . 'user even on exception paths, and needs the tenant connection configured.'
        );
        $this->assertLessThan(
            $cookiesAt,
            $tenantAt,
            'IdentifyTenant should lead the stateful stack — it depends on nothing but the host.'
        );
    }
}
