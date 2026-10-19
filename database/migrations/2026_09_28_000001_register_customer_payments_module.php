<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CUSTOMER-PAYMENTS-MODULE-1 — Customer Payments ko apna module do, aur
 * Kashif Kitchen se hata do.
 *
 * Malik (28 Sep): "customer payment catering ka hissa nahi, is module ki
 * permission aur visibility Kashif Kitchen se hata do."
 *
 * SIRF PERMISSION HATANA KAAM NAHI KARTA, aur ye baat yahan likhna zaroori
 * hai: `deploy.sh` ka qadam [5] har deploy par HAR tenant ke Owner ko
 * `route_catalogs` ki SAARI `tenant.%` permissions dobara de deta hai
 * (`$owner->givePermissionTo($names)`). Yani jo permission aaj haath se
 * hataayi jaye wo agle deploy par khud wapas aa jati hai. Isi liye ye kaam
 * permission se nahi, MODULE se ho raha hai — module ka faisla plan par hai
 * aur deploy usay nahi chhoota.
 *
 * Teen cheezein, teenon zaroori:
 *   1. module `customer_payments` banao, jo route key `tenant.finance.customer-payments`
 *      ka dawa kare;
 *   2. `route_catalogs` ki chaaron rows ka `module_key` us par mor do.
 *      Abhi wo `tenant.finance` par hain, yani `finance` module par — aur
 *      finance Kashif Kitchen ke plan me chaalu hai (ledger ke liye
 *      zaroori bhi hai). Isi liye screen dikhti hai. Jab tak ye mapping
 *      nahi badalti, plan me kuch bhi karne se farq nahi parega;
 *   3. HAR mojooda plan par ye module CHAALU karo siwaye `kashif-catering`
 *      ke. Ye qadam sab se ahem hai — is ke baghair ye migration har tenant
 *      se Customer Payments cheen leti.
 *
 * Sidebar bhi isi module par band hota hai; wahan pehle se `$hasModule()`
 * mojood hai aur us file me likha hai ke `@can` akela entitlement ka faisla
 * nahi.
 *
 * Additive aur idempotent: dobara chalane par kuch naya nahi hota.
 */
return new class extends Migration
{
    private const MODULE_KEY = 'customer_payments';

    private const ROUTE_KEY = 'tenant.finance.customer-payments';

    /** Jis plan ko ye module NAHI milta. Sirf Kashif Kitchen is par hai. */
    private const EXCLUDED_PLAN_CODES = ['kashif-catering'];

    public function up(): void
    {
        $master = DB::connection('master');

        $attributes = [
            'name' => 'Customer Payments',
            'category' => 'Finance',
            'description' => 'Receipts recorded against customer receivables. Separate from catering advances, which post through the catering event.',
            'route_module_keys' => json_encode([self::ROUTE_KEY]),
            'sort_order' => 141,
            'is_core' => false,
            'is_active' => true,
            'updated_at' => now(),
        ];

        $moduleId = $master->table('modules')->where('key', self::MODULE_KEY)->value('id');

        if ($moduleId) {
            $master->table('modules')->where('id', $moduleId)->update($attributes);
        } else {
            $moduleId = $master->table('modules')->insertGetId(
                $attributes + ['key' => self::MODULE_KEY, 'created_at' => now()]
            );
        }

        // Har plan par chaalu, siwaye un ke jo upar excluded hain. Pehle ye
        // karo aur PHIR route_catalogs likho: agar migration beech me ruk
        // jaye to behtar hai ke module kisi ka raasta na roke.
        // KIS PLAN PAR CHAALU — aur ye faisla naap ke baghair badalna nahi
        // chahiye. Qaida: JAHAN `finance` hai WAHAN, siwaye kashif-catering ke.
        //
        // Pehle yahan "har plan par" likha tha. Wo GHALAT tha: prod ke 13 me se
        // 4 plans par finance hai hi nahi — inventory_store, quick_sale,
        // restaurant_starter, retail_starter — aur un tenants ne ye screen
        // kabhi dekhi hi nahi. Un par module chaalu kar dena unhe ek nayi
        // screen DE deta, chupke se, bina kisi ke maange. Ek feature ka bila
        // wajah aa jana utna hi ghalat hai jitna us ka chale jana.
        //
        // Ye chaar routes abhi `tenant.finance` par mapped hain, is liye "jis
        // ke paas finance hai" theek wohi jamaat hai jo aaj ye screen dekhti
        // hai. Yani is migration ke baad kisi ke liye kuch nahi badalta —
        // siwaye Kashif Kitchen ke, jo maanga gaya tha.
        $financeId = $master->table('modules')->where('key', 'finance')->value('id');
        $excludedIds = $master->table('plans')
            ->whereIn('code', self::EXCLUDED_PLAN_CODES)->pluck('id')->all();

        foreach ($master->table('plans')->pluck('id') as $planId) {
            $hasFinance = $financeId && (bool) $master->table('plan_modules')
                ->where('plan_id', $planId)->where('module_id', $financeId)->value('is_enabled');

            if (! $hasFinance || in_array($planId, $excludedIds, true)) {
                // Jaan-boojh kar `is_enabled = false` likha ja raha hai, row
                // chhoro nahi. Ek na-mojood row aur ek band row ek jaisi
                // dikhti hain magar ek jaisi nahi: band row batati hai ke ye
                // faisla kisi ne kiya tha.
                $this->setPlanModule($master, $planId, $moduleId, false);

                continue;
            }

            $this->setPlanModule($master, $planId, $moduleId, true);
        }

        $master->table('route_catalogs')
            ->where('route_name', 'like', self::ROUTE_KEY.'.%')
            ->update(['module_key' => self::ROUTE_KEY, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $master = DB::connection('master');

        $master->table('route_catalogs')
            ->where('module_key', self::ROUTE_KEY)
            ->update(['module_key' => null, 'updated_at' => now()]);

        $moduleId = $master->table('modules')->where('key', self::MODULE_KEY)->value('id');
        if ($moduleId) {
            $master->table('plan_modules')->where('module_id', $moduleId)->delete();
            $master->table('modules')->where('id', $moduleId)->delete();
        }
    }

    private function setPlanModule($master, int $planId, int $moduleId, bool $enabled): void
    {
        $existing = $master->table('plan_modules')
            ->where('plan_id', $planId)->where('module_id', $moduleId)->first();

        if ($existing) {
            $master->table('plan_modules')->where('id', $existing->id)
                ->update(['is_enabled' => $enabled, 'updated_at' => now()]);

            return;
        }

        $master->table('plan_modules')->insert([
            'plan_id' => $planId,
            'module_id' => $moduleId,
            'is_enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
