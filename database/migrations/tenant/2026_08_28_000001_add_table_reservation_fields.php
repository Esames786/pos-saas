<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TABLE-RESERVATION-1: mark a table reserved with WHO (an attached customer or a typed walk-in) + WHEN
 * + a note. The `reserved` status value already exists on restaurant_tables.status — these are only the
 * details it never had. All nullable / additive; nothing changes for existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('restaurant_tables', function (Blueprint $table) {
            $table->unsignedBigInteger('reserved_customer_id')->nullable()->after('status')->index();
            $table->string('reserved_name')->nullable()->after('reserved_customer_id');
            $table->string('reserved_phone', 40)->nullable()->after('reserved_name');
            $table->dateTime('reserved_for')->nullable()->after('reserved_phone');
            $table->text('reservation_note')->nullable()->after('reserved_for');
            $table->unsignedBigInteger('reserved_by_user_id')->nullable()->after('reservation_note');
            $table->timestamp('reserved_at')->nullable()->after('reserved_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('restaurant_tables', function (Blueprint $table) {
            $table->dropColumn([
                'reserved_customer_id', 'reserved_name', 'reserved_phone',
                'reserved_for', 'reservation_note', 'reserved_by_user_id', 'reserved_at',
            ]);
        });
    }
};
