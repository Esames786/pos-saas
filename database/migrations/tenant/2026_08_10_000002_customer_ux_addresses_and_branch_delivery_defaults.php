<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CUSTOMER-UX-1: per-customer address book (multiple addresses, one default) and
// per-branch delivery charge defaults with an optional cashier lock. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('customer_addresses')) {
            Schema::connection('tenant')->create('customer_addresses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id')->index();
                $table->string('label', 50)->nullable();
                $table->string('address', 500);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('branches', 'default_delivery_charge')) {
                $table->decimal('default_delivery_charge', 14, 2)->default(0)->after('allow_negative_stock');
            }
            if (! Schema::connection('tenant')->hasColumn('branches', 'delivery_charge_locked')) {
                $table->boolean('delivery_charge_locked')->default(false)->after('default_delivery_charge');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('customer_addresses');
        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('branches', 'delivery_charge_locked')) {
                $table->dropColumn('delivery_charge_locked');
            }
            if (Schema::connection('tenant')->hasColumn('branches', 'default_delivery_charge')) {
                $table->dropColumn('default_delivery_charge');
            }
        });
    }
};
