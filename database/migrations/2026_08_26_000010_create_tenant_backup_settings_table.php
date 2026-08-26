<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT-AUTO-BACKUP-1 — per-tenant automatic backup schedule (master DB).
 *
 * One row per tenant: whether auto-backup is on, up to 3 daily times (HH:MM in `timezone`,
 * default Pakistan time), and how many days of SCHEDULED backups to keep. Absent row = off.
 * The central `tenants:auto-backup` dispatcher reads this to decide when to snapshot each tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_backup_settings')) {
            return;
        }

        Schema::create('tenant_backup_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->json('times')->nullable();                 // e.g. ["14:30","19:30","02:30"]
            $table->string('timezone', 64)->default('Asia/Karachi');
            $table->unsignedTinyInteger('retention_days')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backup_settings');
    }
};
