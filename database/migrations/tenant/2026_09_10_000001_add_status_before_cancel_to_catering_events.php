<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-STATUS-ROLLBACK-1 (step 1) — remember where a booking was when it
 * was cancelled, so un-cancelling can put it back.
 *
 * Additive and nullable. Every booking cancelled BEFORE this ships has no
 * record of where it came from, and there is no honest way to work it out
 * afterwards — `cancelled_at` says when, never from what. Those rows keep NULL
 * and un-cancel sends them to `draft`, with the screen saying plainly that the
 * original status is not known rather than pretending it chose one.
 *
 * Nothing reads this column except the un-cancel path, and nothing writes it
 * except cancelEvent(). It is not part of the History snapshot: History records
 * the whole operational state of a booking, and this is a note about the
 * cancellation itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded the way 2026_08_15_000001 guards its own columns: a tenant DB
        // that already carries it must not fail the whole per-tenant loop in
        // deploy.sh step [5] and take the other ten tenants down with it.
        if (Schema::connection('tenant')->hasColumn('catering_events', 'status_before_cancel')) {
            return;
        }

        Schema::connection('tenant')->table('catering_events', function (Blueprint $table) {
            $table->string('status_before_cancel', 32)->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('catering_events', function (Blueprint $table) {
            $table->dropColumn('status_before_cancel');
        });
    }
};
