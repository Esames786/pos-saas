<?php

namespace Tests\MySql;

use App\Http\Controllers\Edge\EdgeLocalSelfEnrollController;
use App\Services\Edge\EdgeEnrollmentCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE 0.9 (stream B, lead decisions §5 + stream D contract) — POST /edge/local/enroll for the counter wizard, and the public
 * counter-setup facts on /edge/local/health:
 *   - OFF by default (404); ON: a Cloud-signed assertion + the cashier's PIN → 201 {"ok":true,"user_id":N} through the EXISTING
 *     EdgeEnrollmentConsumer (Argon2 verifier of exactly that PIN);
 *   - refusals are 4xx {"reason"} — replay contains "replay", expiry contains "expire", a weak PIN is plain text;
 *   - the PIN never reaches a log line; 5/min/IP (the route's throttle);
 *   - /edge/local/health carries enrollment_key_sha256 (uppercase hex of the raw public key) and terminals [{id, active}] only.
 */
class EdgeWizardSelfEnrollEndpointMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private array $keys;
    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => config('database.connections.tenant.database'), 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_consumed_assertions', 'edge_local_user_credentials', 'terminals', 'branches', 'users']);
        config(['app.role' => 'branch_server', 'cache.default' => 'array']);
        $this->keys = EdgeEnrollmentCrypto::generateKeypair();
        config(['edge.enrollment.public_key' => $this->keys['public'], 'edge.local_enroll_endpoint.enabled' => true]);
        $this->branchId = $this->makeBranch(['name' => 'Self Enroll Branch']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'SE' . Str::random(4), 'name' => 'Counter Self']);
        $this->makeTerminal($this->branchId, ['name' => 'Counter Secret Name']);
        $this->bindEdgeLocalMeta($this->branchId, 2, 42, 'self-enroll-device');
        if (! Route::has('edge.local.enroll')) {
            // The route line the lead adds (unauthenticated, CSRF-exempt JSON, 5/min/IP).
            Route::post('/edge/local/enroll', [EdgeLocalSelfEnrollController::class, 'store'])
                ->middleware(['web', 'throttle:5,1'])->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->name('edge.local.enroll');
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->refreshActionLookups();
        }
        config(['edge.route_allowlist' => array_merge((array) config('edge.route_allowlist'), ['edge.local.enroll'])]);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function assertion(array $overrides = []): array
    {
        $now = time();

        return EdgeEnrollmentCrypto::sign(array_merge([
            'version' => EdgeEnrollmentCrypto::ASSERTION_VERSION, 'purpose' => EdgeEnrollmentCrypto::PURPOSE,
            'tenant_id' => 42, 'tenant_code' => 'edgepos', 'branch_id' => $this->branchId, 'device_public_uuid' => 'self-enroll-device',
            'activation_epoch' => 2, 'user_id' => $this->userId, 'jti' => (string) Str::ulid(), 'issuer_user_id' => $this->userId,
            'issued_at' => $now, 'expires_at' => $now + 900,
        ], $overrides), $this->keys['secret']);
    }

    private function enroll(array $assertion, string $pin)
    {
        return $this->postJson('/edge/local/enroll', ['assertion' => $assertion, 'credential' => $pin, 'type' => 'pin']);
    }

    public function test_off_by_default(): void
    {
        config(['edge.local_enroll_endpoint.enabled' => false]);
        $this->enroll($this->assertion(), '482913')->assertStatus(404)->assertJsonStructure(['reason']);
        $this->assertSame(0, DB::connection('tenant')->table('edge_local_user_credentials')->count());
    }

    public function test_a_signed_assertion_and_pin_enroll_the_cashier_and_every_refusal_is_a_reason(): void
    {
        Log::spy();
        $a = $this->assertion();
        $this->enroll($a, '482913')->assertStatus(201)->assertExactJson(['ok' => true, 'user_id' => $this->userId]);
        $row = DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->userId)->first();
        $this->assertSame('pin', $row->credential_type);
        $this->assertTrue(password_verify('482913', $row->credential_hash));

        $r = $this->enroll($a, '739154')->assertStatus(422);
        $this->assertStringContainsString('replay', $r->json('reason'));
        $r = $this->enroll($this->assertion(['issued_at' => time() - 1200, 'expires_at' => time() - 300]), '739154')->assertStatus(422);
        $this->assertStringContainsString('expire', $r->json('reason'));
        $r = $this->enroll($this->assertion(), '222222')->assertStatus(422);
        $this->assertStringContainsString('PIN', $r->json('reason'));
        $this->enroll(['payload' => ['user_id' => 1]], '482913')->assertStatus(422)->assertJsonStructure(['reason']);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            $this->assertStringNotContainsString('482913', $message . json_encode($context));
            $this->assertStringNotContainsString('739154', $message . json_encode($context));

            return true;
        });
    }

    public function test_five_per_minute_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->enroll(['payload' => []], '1')->getStatusCode());
        }
        $this->enroll(['payload' => []], '1')->assertStatus(429);
    }

    public function test_health_publishes_the_enrollment_key_hash_and_terminal_ids_only(): void
    {
        $doc = $this->getJson('/edge/local/health')->assertOk()->json();
        $this->assertSame(strtoupper(hash('sha256', base64_decode($this->keys['public']))), $doc['enrollment_key_sha256']);
        $this->assertCount(1, $doc['terminals']);
        $this->assertSame(['id', 'active'], array_keys($doc['terminals'][0]));
        $this->assertTrue($doc['terminals'][0]['active']);
        $this->assertStringNotContainsString('Counter Secret Name', json_encode($doc));
    }
}
