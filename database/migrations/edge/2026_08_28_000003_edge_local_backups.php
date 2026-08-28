<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE PRODUCTIZATION — the appliance's local BACKUP audit log (EDGE-ONLY).
 *
 * One immutable row per completed backup: where it landed, its integrity checksum, size, the software +
 * schema generation it was taken under, the binding it belongs to, and the row counts it captured. This is
 * what the health/support bundle reads for "last successful backup", and what restore consults. Additive +
 * idempotent (AE12). No secrets — the checksum is over the (pre-encryption) logical snapshot.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('edge_local_backups')) {
            return;
        }
        Schema::connection($this->connection)->create('edge_local_backups', function (Blueprint $table) {
            $table->id();
            $table->char('backup_uuid', 26)->unique();
            $table->string('path', 500);
            $table->string('format_version', 40);
            $table->string('software_version', 64)->nullable();
            $table->string('schema_generation', 64)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('device_uuid', 64)->nullable();
            $table->unsignedBigInteger('activation_epoch')->nullable();
            $table->string('checksum', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->json('table_counts')->nullable();
            $table->string('status', 20)->default('completed');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['branch_id', 'created_at'], 'elb_branch_created_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_local_backups');
    }
};
