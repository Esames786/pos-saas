<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — reservation Local-Mode -> Cloud handback: stamp when an Edge reservation was projected into
 * canonical Cloud state (status -> handed_back). Additive + idempotent (AE12).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('edge_local_table_reservations')
            && ! Schema::connection($this->connection)->hasColumn('edge_local_table_reservations', 'handed_back_at')) {
            Schema::connection($this->connection)->table('edge_local_table_reservations', function (Blueprint $table) {
                $table->timestamp('handed_back_at')->nullable()->after('cancelled_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('edge_local_table_reservations', 'handed_back_at')) {
            Schema::connection($this->connection)->table('edge_local_table_reservations', function (Blueprint $table) {
                $table->dropColumn('handed_back_at');
            });
        }
    }
};
