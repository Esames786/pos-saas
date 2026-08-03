<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $table->json('direct_pay_print_state')->nullable()->after('client_payload_hash');
            $table->timestamp('direct_pay_print_orchestrated_at')->nullable()->after('direct_pay_print_state');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['direct_pay_print_state', 'direct_pay_print_orchestrated_at']);
        });
    }
};
