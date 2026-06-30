<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER-TENANT-OPS-1 — metadata for per-tenant SQL backups created from the master
 * admin. Files live on a private disk (storage/app/backups/...); this table is the
 * authoritative record for history / download / restore. Master DB (default connection).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_backups')) {
            return;
        }

        Schema::create('tenant_backups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('tenant_code')->index();
            $table->string('database_name');
            $table->string('disk')->default('local');
            $table->string('path');           // relative path on the disk (never absolute)
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('backup_type', 30)->default('manual'); // manual|pre_reset|pre_restore|bulk
            $table->string('status', 20)->default('completed');   // completed|failed
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->unsignedBigInteger('restored_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backups');
    }
};
