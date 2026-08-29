<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-PRODUCT-UX-1 (item 9) — record WHY an event was cancelled.
 *
 * Cancellation was a status flip with no explanation attached, so a cancelled
 * booking carrying a received advance left no record of what had been agreed
 * with the customer.
 *
 * Strictly additive and nullable. Historical rows keep a NULL reason and stay
 * valid — backfilling an invented reason onto past cancellations would be
 * fabricating a record. The requirement to supply one is enforced going
 * forward, in the service, not retroactively by the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('catering_events', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('catering_events', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable()->after('notes');
            }
            if (! Schema::connection('tenant')->hasColumn('catering_events', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancel_reason');
            }
            if (! Schema::connection('tenant')->hasColumn('catering_events', 'cancelled_by_user_id')) {
                $table->unsignedBigInteger('cancelled_by_user_id')->nullable()->after('cancelled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('catering_events', function (Blueprint $table) {
            foreach (['cancel_reason', 'cancelled_at', 'cancelled_by_user_id'] as $column) {
                if (Schema::connection('tenant')->hasColumn('catering_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
