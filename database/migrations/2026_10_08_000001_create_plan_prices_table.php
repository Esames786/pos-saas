<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WEBSITE-I18N-GEO-1 P2 — a plan's price in each market's currency.
 *
 * Pakistan keeps its BUNDLE plans (branches included, PKR — exactly today's numbers, copied in).
 * Saudi Arabia, the UAE, Qatar and the US pay PER BRANCH, priced from the 7 Oct 2026 market
 * research (docs/plans/website-i18n-geo-pricing-2026-10-07.md §2.4). Prices are SET per currency,
 * never converted from PKR: a converted SAR 106.67 reads as a mistake, and the price shown must be
 * the price invoiced.
 *
 * Rows are inserted only when missing, so an owner's later edit is never overwritten by a re-run.
 * plans.monthly_price / yearly_price stay as they are and remain the fallback.
 */
return new class extends Migration
{
    protected $connection = 'master';

    /** [plan code => [currency => monthly per branch]] — research §2.4. */
    private const PER_BRANCH = [
        'retail_starter'     => ['SAR' => 189, 'AED' => 199, 'QAR' => 199, 'USD' => 69],
        'inventory_store'    => ['SAR' => 399, 'AED' => 399, 'QAR' => 399, 'USD' => 139],
        'restaurant_starter' => ['SAR' => 219, 'AED' => 219, 'QAR' => 199, 'USD' => 59],
        'restaurant_pro'     => ['SAR' => 549, 'AED' => 469, 'QAR' => 469, 'USD' => 169],
    ];

    /** Extra terminal, per month — research §2.5. */
    private const EXTRA_TERMINAL = ['SAR' => 59, 'AED' => 59, 'QAR' => 59, 'USD' => 19];

    /** What one purchased branch brings, per plan — research §2.4. */
    private const PER_BRANCH_LIMITS = [
        'retail_starter'     => ['terminals_per_branch' => 1, 'users_per_branch' => 3],
        'inventory_store'    => ['terminals_per_branch' => 2, 'users_per_branch' => 5],
        'restaurant_starter' => ['terminals_per_branch' => 2, 'users_per_branch' => 8],
        'restaurant_pro'     => ['terminals_per_branch' => 3, 'users_per_branch' => 10],
    ];

    public function up(): void
    {
        $db = Schema::connection('master');
        if (! $db->hasTable('plan_prices')) {
            $db->create('plan_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
                $table->char('currency_code', 3);
                $table->enum('pricing_model', ['bundle', 'per_branch']);
                // bundle: the plan's price · per_branch: the price of ONE branch
                $table->decimal('monthly_price', 12, 2);
                // null = monthly × 10 (two months free), the same rule as BillingPeriodResolver
                $table->decimal('yearly_price', 12, 2)->nullable();
                // null = extra terminals are not sold in this currency
                $table->decimal('extra_terminal_monthly', 12, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['plan_id', 'currency_code']);
            });
        }

        $master = DB::connection('master');
        $plans = $master->table('plans')->whereIn('code', array_keys(self::PER_BRANCH))->get()->keyBy('code');
        $now = now();
        foreach ($plans as $code => $plan) {
            $rows = [];
            if ($plan->monthly_price !== null) {
                $rows[] = ['currency_code' => 'PKR', 'pricing_model' => 'bundle', 'monthly_price' => $plan->monthly_price,
                    'yearly_price' => $plan->yearly_price, 'extra_terminal_monthly' => null];
            }
            foreach (self::PER_BRANCH[$code] as $currency => $monthly) {
                $rows[] = ['currency_code' => $currency, 'pricing_model' => 'per_branch', 'monthly_price' => $monthly,
                    'yearly_price' => null, 'extra_terminal_monthly' => self::EXTRA_TERMINAL[$currency]];
            }
            foreach ($rows as $row) {
                $master->table('plan_prices')->insertOrIgnore($row + ['plan_id' => $plan->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (self::PER_BRANCH_LIMITS[$code] as $key => $value) {
                $master->table('plan_features')->insertOrIgnore(['plan_id' => $plan->id, 'feature_key' => $key, 'feature_value' => (string) $value, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('plan_prices');
        DB::connection('master')->table('plan_features')->whereIn('feature_key', ['terminals_per_branch', 'users_per_branch'])->delete();
    }
};
