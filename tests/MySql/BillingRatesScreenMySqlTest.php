<?php

namespace Tests\MySql;

use App\Models\Master\CentralUser;
use App\Models\Master\PlatformSetting;
use App\Services\Saas\BillingRates;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ONLINE-ORDERING-1 — platform admin → Billing rates, asli raaste se (route → middleware →
 * controller → Blade). Rates paisa hain: ghalat rate har tenant ke har bill me jata hai.
 */
class BillingRatesScreenMySqlTest extends MySqlTenantTestCase
{
    private string $host;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'http://'.config('tenancy.central_domain');

        DB::connection('master')->table('platform_settings')->whereIn('key', array_keys(BillingRates::KEYS))->delete();
        $this->app->forgetScopedInstances();
    }

    protected function tearDown(): void
    {
        DB::connection('master')->table('platform_settings')->whereIn('key', array_keys(BillingRates::KEYS))->delete();
        foreach ($this->userIds as $id) {
            DB::connection('master')->table('model_has_permissions')->where('model_id', $id)
                ->where('model_type', CentralUser::class)->delete();
            DB::connection('master')->table('central_users')->where('id', $id)->delete();
        }
        parent::tearDown();
    }

    private function admin(array $permissions): CentralUser
    {
        $user = CentralUser::create([
            'name' => 'Rates Admin '.count($this->userIds),
            'email' => 'rates'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'status' => 'active',
        ]);
        $this->userIds[] = $user->id;

        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'central');
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_the_page_shows_the_rates_in_force_and_saves_new_ones(): void
    {
        config()->set('services.whatsapp.rate_pkr', 9.85);
        $admin = $this->admin(['central.billing-rates.edit', 'central.billing-rates.update']);

        // Master me kuch nahi: `.env` ka sahara dikhna chahiye, aur saaf likha ho ke yahan set nahi.
        $html = $this->actingAs($admin, 'central')->get($this->host.'/billing-rates')->assertOk()->getContent();
        $this->assertStringContainsString('value="9.85"', $html);
        $this->assertStringContainsString('Not set here yet', $html);

        $this->actingAs($admin, 'central')->put($this->host.'/billing-rates', [
            BillingRates::WHATSAPP_RATE => '10.50',
            BillingRates::WHATSAPP_COST => '6.00',
            BillingRates::WEBSITE_COMMISSION => '1.25',
        ])->assertRedirect(route('central.billing-rates.edit'));

        $this->assertSame('10.5', PlatformSetting::where('key', BillingRates::WHATSAPP_RATE)->value('value'));
        $this->assertSame('1.25', PlatformSetting::where('key', BillingRates::WEBSITE_COMMISSION)->value('value'));
        $this->assertSame($admin->id, (int) PlatformSetting::where('key', BillingRates::WHATSAPP_RATE)->value('updated_by_user_id'));

        // Jo likha gaya wohi aage parha jata hai.
        $this->app->forgetScopedInstances();
        $this->assertSame(10.5, app(BillingRates::class)->whatsappRatePkr());
        $this->assertSame(1.25, app(BillingRates::class)->websiteCommissionPercent());

        $html = $this->actingAs($admin, 'central')->get($this->host.'/billing-rates')->assertOk()->getContent();
        $this->assertStringContainsString('value="10.5"', $html);
        $this->assertStringContainsString('by '.$admin->name, $html);
    }

    public function test_a_commission_above_100_percent_or_a_negative_rate_is_refused(): void
    {
        $admin = $this->admin(['central.billing-rates.edit', 'central.billing-rates.update']);

        $this->actingAs($admin, 'central')->put($this->host.'/billing-rates', [
            BillingRates::WHATSAPP_RATE => '-1',
            BillingRates::WHATSAPP_COST => '6',
            BillingRates::WEBSITE_COMMISSION => '150',
        ])->assertSessionHasErrors([BillingRates::WHATSAPP_RATE, BillingRates::WEBSITE_COMMISSION]);

        $this->assertSame(0, PlatformSetting::whereIn('key', array_keys(BillingRates::KEYS))->count());
    }

    public function test_viewing_the_rates_does_not_allow_changing_them(): void
    {
        $viewer = $this->admin(['central.billing-rates.edit']);

        $html = $this->actingAs($viewer, 'central')->get($this->host.'/billing-rates')->assertOk()->getContent();
        $this->assertStringNotContainsString('Save rates', $html);

        $this->actingAs($viewer, 'central')->put($this->host.'/billing-rates', [
            BillingRates::WHATSAPP_RATE => '1',
            BillingRates::WHATSAPP_COST => '1',
            BillingRates::WEBSITE_COMMISSION => '1',
        ])->assertForbidden();

        $this->assertSame(0, PlatformSetting::whereIn('key', array_keys(BillingRates::KEYS))->count());
    }

    public function test_super_admin_gets_the_permissions_on_every_deploy(): void
    {
        // deploy.sh [3b] MasterSeeder chalata hai, aur us ka syncPermissions() list se bahar ki har
        // central permission Super Admin se chheen leta hai. Is liye dono isi list me hon.
        $seeder = file_get_contents(database_path('seeders/MasterSeeder.php'));
        $this->assertStringContainsString("'central.billing-rates.edit'", $seeder);
        $this->assertStringContainsString("'central.billing-rates.update'", $seeder);
    }
}
