<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TABLE-RESERVATION-2b: when a reserved table is opened, carry the reservation's customer (an attached
 * customer id, or a typed walk-in name/phone) onto the session so the POS pre-attaches it to the first
 * order — the operator does not re-search a customer they already booked. All nullable / additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('restaurant_table_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('restaurant_waiter_id')->index();
            $table->string('customer_name')->nullable()->after('customer_id');
            $table->string('customer_phone', 40)->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('restaurant_table_sessions', function (Blueprint $table) {
            $table->dropColumn(['customer_id', 'customer_name', 'customer_phone']);
        });
    }
};
