<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-DELIVERY-V2-1 — a schedule can go out when the day's last shift closes.
 *
 * Kashif Food's last counter closes between 01:17 and 02:58 PKT, and its report left at 02:30 — so on
 * the late nights the owner's report was missing the last half hour of sales. `send_trigger` says what
 * starts the send:
 *   'time'        — the schedule's own send_time, as every schedule has always worked (the default, so
 *                   no existing row changes behaviour);
 *   'shift_close' — the moment no shift of that business day is still open; send_time is then only the
 *                   safety net for a day whose shifts are never closed.
 *
 * `report_schedule_runs.via` records which of the two actually sent it, for the delivery history.
 * Both columns are additive; nothing reads them unless the tenant's new delivery card is switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasTable('report_schedules') && ! $schema->hasColumn('report_schedules', 'send_trigger')) {
            $schema->table('report_schedules', function (Blueprint $table) {
                $table->string('send_trigger', 20)->default('time')->after('send_time');
            });
        }

        if ($schema->hasTable('report_schedule_runs') && ! $schema->hasColumn('report_schedule_runs', 'via')) {
            $schema->table('report_schedule_runs', function (Blueprint $table) {
                $table->string('via', 20)->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasTable('report_schedules') && $schema->hasColumn('report_schedules', 'send_trigger')) {
            $schema->table('report_schedules', fn (Blueprint $table) => $table->dropColumn('send_trigger'));
        }
        if ($schema->hasTable('report_schedule_runs') && $schema->hasColumn('report_schedule_runs', 'via')) {
            $schema->table('report_schedule_runs', fn (Blueprint $table) => $table->dropColumn('via'));
        }
    }
};
