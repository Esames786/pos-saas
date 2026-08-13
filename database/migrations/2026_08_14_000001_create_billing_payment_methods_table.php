<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLOUD-BILLING-1A — admin-editable directory of MANUAL payment methods (where a tenant
 * sends money). Additive: does NOT touch `payment_gateways` (which stays as the historical
 * gateway registry). Account title/number are NEVER hardcoded in code — they are DB
 * configuration entered by a central admin through the Payment Methods admin screen.
 */
return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        if (Schema::connection('master')->hasTable('billing_payment_methods')) {
            return;
        }

        Schema::connection('master')->create('billing_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();              // easypaisa | jazzcash | nayapay | ...
            $table->string('display_name');                // shown to the tenant
            $table->string('account_title')->nullable();   // e.g. account holder name (admin-entered)
            $table->string('account_number')->nullable();  // account / wallet / reference number
            $table->text('instructions')->nullable();      // free-form "how to pay" note
            $table->boolean('is_active')->default(false);   // only active methods show to tenants
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('billing_payment_methods');
    }
};
