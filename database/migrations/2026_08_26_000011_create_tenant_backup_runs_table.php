<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT-AUTO-BACKUP-1 — one claim row per (tenant, local slot date, slot time). The UNIQUE key is
 * the idempotency guard: the dispatcher runs every few minutes, but firstOrCreate on this key fires a
 * given slot's backup EXACTLY ONCE per day no matter how many times the dispatcher runs or overlaps
 * (same pattern as scheduled report periods). Also the auditable history of each scheduled run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_backup_runs')) {
            return;
        }

        Schema::create('tenant_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->date('slot_date');
            $table->string('slot_time', 5);                    // 'HH:MM' local time
            $table->unsignedBigInteger('tenant_backup_id')->nullable();
            $table->string('status', 20)->default('claimed');  // claimed|done|failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slot_date', 'slot_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backup_runs');
    }
};
