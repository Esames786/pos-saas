<?php

namespace Tests\Unit;

use App\Support\TenantFeatureDefaults;
use App\Support\TenantFeatures;
use PHPUnit\Framework\TestCase;

/**
 * ROLLOUT-1 — the switches a new tenant starts with, per business and plan (the plans' real module lists).
 */
class TenantFeatureDefaultsTest extends TestCase
{
    private const RETAIL_STARTER = ['pos', 'catalog', 'printing', 'reports', 'users_roles'];
    private const INVENTORY_STORE = ['pos', 'catalog', 'inventory', 'stock_count', 'purchasing', 'printing', 'reports', 'users_roles'];
    private const RESTAURANT_STARTER = ['pos', 'catalog', 'restaurant', 'printing', 'reports', 'sales_controls', 'users_roles'];
    private const RESTAURANT_PRO = ['pos', 'catalog', 'restaurant', 'kitchen_display', 'kitchen_inventory', 'inventory', 'purchasing', 'stock_count', 'printing', 'reports', 'sales_controls', 'multi_branch', 'users_roles', 'finance', 'customer_payments'];
    private const FINANCE_ERP = ['pos', 'catalog', 'inventory', 'stock_count', 'purchasing', 'printing', 'reports', 'sales_controls', 'multi_branch', 'users_roles', 'finance', 'customer_payments', 'manufacturing'];
    private const PHARMACY = ['pos', 'catalog', 'inventory', 'stock_count', 'purchasing', 'printing', 'reports', 'multi_branch', 'users_roles'];
    private const CATERING = ['catalog', 'catering', 'finance', 'inventory', 'kitchen_inventory', 'multi_branch', 'printing', 'purchasing', 'users_roles'];

    private function on(?string $business, array $modules, bool $demo = false): array
    {
        $keys = array_keys(TenantFeatureDefaults::for($business, $modules, $demo));
        sort($keys);

        return $keys;
    }

    private function keys(string ...$keys): array
    {
        sort($keys);

        return $keys;
    }

    public function test_screens_follow_the_plan_when_the_business_is_not_known(): void
    {
        // A signup or a Master-created tenant: its business is chosen later, so only the plan decides.
        $this->assertSame($this->keys('product_screen_v2', 'purchasing_v2', 'purchase_workspace'), $this->on(null, self::RESTAURANT_PRO));
        $this->assertSame($this->keys('product_screen_v2', 'purchasing_v2', 'purchase_workspace'), $this->on(null, self::INVENTORY_STORE), 'no business rules without a business');
        $this->assertSame(['product_screen_v2'], $this->on(null, self::RETAIL_STARTER), 'no purchasing module, no purchase screens');
    }

    public function test_whole_numbers_are_a_business_policy_not_a_purchasing_one(): void
    {
        // Restaurants, caterers and factories have purchasing but buy counted items in parts: whole numbers stay off.
        $this->assertSame($this->keys('product_screen_v2', 'purchasing_v2', 'purchase_workspace'), $this->on('restaurant', self::RESTAURANT_PRO));
        $this->assertSame($this->keys('product_screen_v2', 'purchasing_v2', 'purchase_workspace'), $this->on('catering', self::CATERING));
        $this->assertSame($this->keys('product_screen_v2', 'purchasing_v2', 'purchase_workspace'), $this->on('industry', self::FINANCE_ERP));
        $this->assertSame(['product_screen_v2'], $this->on('restaurant', self::RESTAURANT_STARTER));
    }

    public function test_a_mart_with_stock_gets_packs_counter_packs_and_whole_numbers(): void
    {
        $this->assertSame($this->keys('product_screen_v2', 'purchasing_v2', 'purchase_workspace', 'whole_counted_stock', 'packs', 'counter_packs'),
            $this->on('mart', self::INVENTORY_STORE));
        // Without stock there are no packs, so no carton at the counter either (its needs are not on).
        $this->assertSame(['product_screen_v2'], $this->on('mart', self::RETAIL_STARTER));
    }

    public function test_a_live_pharmacy_opts_in_to_refunds_without_a_bill_a_demo_shows_them(): void
    {
        $live = $this->keys('product_screen_v2', 'purchasing_v2', 'purchase_workspace', 'whole_counted_stock', 'packs', 'medical_counter');
        $this->assertSame($live, $this->on('pharmacy', self::PHARMACY));
        $this->assertNotContains('return_no_bill', $this->on('pharmacy', self::PHARMACY), 'the medical counter does not authorise refunds nobody can check');
        $this->assertSame($this->keys(...array_merge($live, ['return_no_bill'])), $this->on('pharmacy', self::PHARMACY, demo: true));
        $this->assertNotContains('counter_packs', $this->on('pharmacy', self::PHARMACY), 'a medical store has its own counter');
    }

    public function test_permanent_delete_and_report_delivery_are_never_a_default(): void
    {
        foreach ([null, 'restaurant', 'catering', 'pharmacy', 'mart', 'industry'] as $business) {
            foreach ([self::RETAIL_STARTER, self::INVENTORY_STORE, self::RESTAURANT_PRO, self::FINANCE_ERP, self::PHARMACY, self::CATERING] as $modules) {
                foreach ([false, true] as $demo) {
                    $on = $this->on($business, $modules, $demo);
                    $this->assertNotContains('void_purge', $on);
                    $this->assertNotContains('report_delivery_v2', $on);
                }
            }
        }
    }

    public function test_every_default_has_its_needs_on(): void
    {
        $all = TenantFeatures::all();
        foreach ([null, 'restaurant', 'catering', 'pharmacy', 'mart', 'industry'] as $business) {
            foreach ([self::RETAIL_STARTER, self::INVENTORY_STORE, self::RESTAURANT_PRO, self::PHARMACY, ['pos', 'inventory'], ['inventory', 'purchasing']] as $modules) {
                $on = TenantFeatureDefaults::for($business, $modules, true);
                foreach (array_keys($on) as $key) {
                    foreach ($all[$key]['needs'] ?? [] as $need) {
                        $this->assertArrayHasKey($need, $on, "{$key} is on without {$need} ({$business})");
                    }
                }
            }
        }
        // A pharmacy plan with no catalog: packs need the product screen, the medical counter needs packs — both dropped.
        $this->assertSame(['whole_counted_stock'], $this->on('pharmacy', ['pos', 'inventory']));
    }

    public function test_filling_keeps_every_switch_the_tenant_already_set(): void
    {
        $current = ['packs' => false, 'purchasing_v2' => true, 'void_purge' => true];
        [$after, $added] = TenantFeatureDefaults::fill($current, ['packs' => true, 'purchasing_v2' => true, 'whole_counted_stock' => true]);

        $this->assertFalse($after['packs'], 'explicitly off stays off');
        $this->assertTrue($after['void_purge'], 'a switch outside the defaults is untouched');
        $this->assertTrue($after['whole_counted_stock']);
        $this->assertSame(['whole_counted_stock'], $added, 'only the switch never set is added');
    }
}
