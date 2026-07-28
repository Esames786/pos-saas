<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SALE-IDEMPOTENCY-1: one logical sale carries one client_uuid so a retry /
 * double-click / network-timeout — and future Bingoo Edge offline sync — can
 * never double-post the same sale. client_payload_hash lets a replay be told
 * apart from a genuine conflict (same key, different sale details).
 *
 * UUID uniqueness is naturally tenant-scoped (each tenant has its own DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $sm = Schema::connection('tenant');

            if (! $sm->hasColumn('sales_orders', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('sale_no');
                $table->unique('client_uuid', 'sales_orders_client_uuid_unique');
            }
            if (! $sm->hasColumn('sales_orders', 'client_payload_hash')) {
                $table->char('client_payload_hash', 64)->nullable()->after('client_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $sm = Schema::connection('tenant');

            if ($sm->hasColumn('sales_orders', 'client_uuid')) {
                $table->dropUnique('sales_orders_client_uuid_unique');
                $table->dropColumn('client_uuid');
            }
            if ($sm->hasColumn('sales_orders', 'client_payload_hash')) {
                $table->dropColumn('client_payload_hash');
            }
        });
    }
};
