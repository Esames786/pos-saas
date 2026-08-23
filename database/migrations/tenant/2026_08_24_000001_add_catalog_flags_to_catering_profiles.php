<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-ORDER-PUNCH plan §A — the legacy per-ITEM switches, on the profile
 * where the item's other catering truth already lives.
 *
 * allow_party_supply DEFAULTS TRUE: today every material may be customer-
 * supplied, and a default of false would have silently removed live controls
 * on deploy day. The owner turns it OFF per item (legacy "Allow Party Meat").
 * is_complimentary defaults FALSE: nothing becomes free by migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('catering_product_profiles', function (Blueprint $table) {
            $table->boolean('allow_party_supply')->default(true)->after('catering_enabled');
            $table->boolean('is_complimentary')->default(false)->after('allow_party_supply');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('catering_product_profiles', function (Blueprint $table) {
            $table->dropColumn(['allow_party_supply', 'is_complimentary']);
        });
    }
};
