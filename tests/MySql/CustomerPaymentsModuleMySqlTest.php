<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;

/**
 * CUSTOMER-PAYMENTS-MODULE-1 — Customer Payments Kashif Kitchen se hata hua
 * rehna chahiye.
 *
 * Malik (28 Sep): "customer payment catering ka hissa nahi, is module ki
 * permission aur visibility Kashif Kitchen se hata do."
 *
 * ⚠ SAB SE AHEM BAAT, aur wohi sab se aasani se bhooli jane wali:
 * PERMISSION HATANA KAAM NAHI KARTA. `deploy.sh` ka qadam [5] har deploy par
 * HAR tenant ke Owner ko `route_catalogs` ki saari `tenant.%` permissions
 * dobara de deta hai. Jo bhi is masle ko `revokePermissionTo` se hal karne
 * ki koshish karega, us ka hal agle deploy tak zinda rahega — aur wapas
 * aane par koi wajah nazar nahi aayegi.
 *
 * Is liye hal MODULE par hai, aur ye test un teen jaalon par khara hai jin
 * me se koi ek bhi tootne par screen chup chaap wapas aa jati hai:
 *   1. sidebar ka item `$hasModule('customer_payments')` ke andar ho;
 *   2. Kashif Kitchen ke plan seeder me wo module sarih tor par EXCLUDED ho;
 *   3. module master me darj ho AUR us route key ka dawa kare jo
 *      `route_catalogs` me likhi jati hai — donon ka milna zaroori hai,
 *      warna `TenantSubscriptionAccessService` "no_module_key" keh kar
 *      raasta khol deta hai (fail-open).
 */
class CustomerPaymentsModuleMySqlTest extends MySqlTenantTestCase
{
    private const MODULE_KEY = 'customer_payments';

    private const ROUTE_KEY = 'tenant.finance.customer-payments';

    /**
     * Sidebar par module ka gate ho, sirf `@can` nahi.
     *
     * Jaanch ULTI bhi hai: bina gate wali purani shakl mojood na ho. Sirf
     * "gate mojood hai?" poochhne par wo din nahi pakra jata jis din koi
     * doosri jagah wohi item bina gate ke dobara likh de.
     */
    public function test_the_sidebar_item_sits_behind_the_module_gate(): void
    {
        $blade = file_get_contents(resource_path('views/partials/sidebar.blade.php'));

        $this->assertStringContainsString("\$hasModule('customer_payments')", $blade,
            'sidebar par module ka gate hona chahiye');

        // Item aur us ka gate ek hi sans me: gate kahin aur para ho aur item
        // khula ho, ye soorat bhi pakri jani chahiye.
        $this->assertMatchesRegularExpression(
            '/@if\(\$hasModule\(\x27customer_payments\x27\)\).*?Customer Payments.*?@endif/s',
            $blade,
            'Customer Payments ka item usi gate ke ANDAR hona chahiye');
    }

    /** Kashif Kitchen ka plan seeder isay sarih tor par band rakhe. */
    public function test_the_kashif_kitchen_plan_excludes_it(): void
    {
        $this->assertContains(self::MODULE_KEY, \Database\Seeders\KashifKitchenPlanSeeder::EXCLUDED,
            'Kashif Kitchen ke plan se ye module sarih tor par bahar hona chahiye');

        $this->assertNotContains(self::MODULE_KEY, \Database\Seeders\KashifKitchenPlanSeeder::MODULES,
            'aur MODULES me nahi — dono jagah hona khud se takrao hai');
    }

    /**
     * Module aur route key ka MILAN. Ye jori toot jaye to kuch nazar nahi
     * aata: screen chhup jati hai magar URL khula reh jata hai.
     */
    public function test_the_module_claims_the_route_key_that_the_routes_carry(): void
    {
        $master = DB::connection('master');

        $module = $master->table('modules')->where('key', self::MODULE_KEY)->first();
        if (! $module) {
            $this->markTestSkipped('module abhi is test master DB me nahi — migration chalayein');
        }

        $claimed = json_decode((string) $module->route_module_keys, true) ?: [];
        $this->assertContains(self::ROUTE_KEY, $claimed,
            'module ko wohi route key claim karni chahiye jo route_catalogs me likhi hai');

        // Probe zinda hai? Ye chaar routes mojood hain.
        $rows = $master->table('route_catalogs')
            ->where('route_name', 'like', self::ROUTE_KEY.'.%')->get();
        $this->assertGreaterThan(0, $rows->count(),
            'customer-payments ke routes catalog me hone chahiyen — warna neeche wali jaanch bemani hai');

        foreach ($rows as $row) {
            $this->assertSame(self::ROUTE_KEY, $row->module_key,
                "{$row->route_name} par module key nahi lagi — is par gate fail-open ho jata hai");
        }
    }

    /**
     * KHATARNAK SOORAT KA PEHRA: ye module HAR plan par chaalu hona chahiye
     * siwaye Kashif Kitchen ke.
     *
     * Ye test us liye likha gaya hai ke is kaam ki sab se buri surat "Kashif
     * Kitchen par abhi bhi dikh raha hai" NAHI hai — wo sirf ek shikayat
     * hai. Sab se buri surat ye hai ke routes ka `module_key` to badal jaye
     * magar plan_modules ki rows na banen: tab HAR tenant se Customer
     * Payments chup chaap gayab ho jayega, aur kisi ko pata nahi chalega
     * jab tak koi paisa darj karne na jaye.
     */
    public function test_the_migration_only_touches_plans_that_already_had_finance(): void
    {
        $master = DB::connection('master');

        $financeId = $master->table('modules')->where('key', 'finance')->value('id');
        if (! $financeId) {
            $this->markTestSkipped('finance module abhi is test master DB me nahi');
        }

        // Teen plans, teen surtein. Ye test master DB ki MOJOODA haalat nahi
        // jaanchta — wo kai tests ka sanjha maidan hai aur us me har koi apne
        // plans chhor jata hai. Ye QAIDA jaanchta hai, apne banaye hue plans
        // par, is liye ye kisi bhi haalat me sach rehta hai.
        $plans = [
            'cp-with-finance' => true,
            'cp-no-finance' => false,
            'kashif-catering' => true,
        ];
        $ids = [];
        foreach ($plans as $code => $withFinance) {
            $ids[$code] = $master->table('plans')->where('code', $code)->value('id')
                ?: $master->table('plans')->insertGetId([
                    'code' => $code, 'name' => $code, 'price' => 0,
                    'billing_period' => 'yearly', 'is_active' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

            $master->table('plan_modules')->where('plan_id', $ids[$code])->where('module_id', $financeId)->delete();
            if ($withFinance) {
                $master->table('plan_modules')->insert([
                    'plan_id' => $ids[$code], 'module_id' => $financeId, 'is_enabled' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Migration idempotent hai — dobara chalana mehfooz hai.
        (require database_path('migrations/2026_09_28_000001_register_customer_payments_module.php'))->up();

        $moduleId = $master->table('modules')->where('key', self::MODULE_KEY)->value('id');
        $this->assertNotNull($moduleId, 'migration ko module banana chahiye');

        $enabled = fn (string $code) => (bool) $master->table('plan_modules')
            ->where('plan_id', $ids[$code])->where('module_id', $moduleId)->value('is_enabled');

        $this->assertTrue($enabled('cp-with-finance'),
            'jis plan par finance hai wo ye screen aaj bhi dekhta hai — usay chheena nahi jana chahiye');

        $this->assertFalse($enabled('cp-no-finance'),
            'jis plan par finance hai hi nahi us ne ye screen kabhi dekhi nahi — usay DE dena bhi utna hi ghalat hai');

        $this->assertFalse($enabled('kashif-catering'),
            'aur Kashif Kitchen par band — yehi to maanga gaya tha');
    }
}
