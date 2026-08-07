<?php

namespace Tests\Feature\Edge;

use Tests\TestCase;

/**
 * EDGE-LOCAL-AUTH-1 — Branch Server HTTP auth contracts (throttle, secure cookie, unauth redirect,
 * crypto/session readiness). Boots AS a branch_server (APP_ROLE forced before boot). Full login
 * success is proven in the MySQL matrix + physical artifact proof.
 */
class EdgeLocalAuthHttpTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = 'branch_server';
        $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $key;
        $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    public function test_login_page_renders_and_status_redirects_when_unauthenticated(): void
    {
        $this->get('/edge/local/login')->assertOk()->assertSee('Branch Server');
        $this->get('/edge/local/status')->assertStatus(302)->assertRedirect('/edge/local/login');
    }

    public function test_login_is_http_throttled_by_employee_code_and_ip(): void
    {
        // 10/min for the same employee_code+IP — the 11th attempt is throttled (even for an unknown code,
        // and regardless of whether the appliance is bound), so brute force is bounded.
        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->post('/edge/local/login', ['employee_code' => 'BRUTE', 'credential' => 'x']);
        }
        $this->assertSame(429, $last->getStatusCode(), 'the 11th rapid login must be throttled');
    }

    public function test_session_cookie_is_secure_httponly_samesite_when_configured(): void
    {
        config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        $response = $this->get('/edge/local/login');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => str_contains($c->getName(), 'session'));
        $this->assertNotNull($cookie, 'a session cookie must be set');
        $this->assertTrue($cookie->isSecure(), 'session cookie must be Secure when configured');
        $this->assertTrue($cookie->isHttpOnly(), 'session cookie must be HttpOnly');
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    public function test_ready_reports_crypto_and_session_contract(): void
    {
        $report = $this->getJson('/edge/local/ready')->json();
        $this->assertArrayHasKey('crypto_ready', $report);
        $this->assertArrayHasKey('enrollment_public_key_ready', $report);
        $this->assertArrayHasKey('session_local', $report);
        $this->assertArrayHasKey('secure_cookie', $report);
        // Uninitialised appliance: local_auth is not_ready and never claims selling readiness.
        $this->assertSame('not_ready', $report['local_auth']);
        $this->assertFalse($report['activation_ready']);
        $this->assertSame('not_ready', $report['operational_stock']);
    }
}
