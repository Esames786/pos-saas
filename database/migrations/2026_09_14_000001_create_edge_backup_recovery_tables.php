<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5B §3 — the Cloud BACKUP RECOVERY AUTHORITY lives in the MASTER DB (beside edge_devices): a dead appliance must
 * never be the only holder of the key that opens its own backups.
 *
 * edge_backup_recovery_keys   one row per (tenant, branch, key_id); the 32-byte wrapping key is escrowed ENCRYPTED
 *                             under the Cloud APP_KEY (never plaintext, never logged). status active|retired —
 *                             rotation retires, it never deletes, so older backups stay recoverable.
 * edge_backup_recovery_audits every issue / retrieval / rotation / refusal with actor + scope + key_id (no material).
 *
 * `branch_id` is a TENANT-DB branch id — deliberately no cross-DB foreign key (same rule as edge_devices).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::create('edge_backup_recovery_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('key_id', 40)->unique();
            $table->text('key_ciphertext');                       // Crypt::encryptString(base64 32-byte key) under the Cloud APP_KEY
            $table->string('status', 16)->default('active');      // active|retired
            $table->string('issued_by', 190);                     // device:<uuid> | user:<id> | admin:<who> | system:<reason>
            $table->string('issue_reason', 190)->nullable();
            $table->string('retire_reason', 190)->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id', 'status'], 'ebrk_scope_status_idx');
        });

        Schema::create('edge_backup_recovery_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('action', 32);                         // issued|retrieved|rotated|refused
            $table->string('outcome', 16);                        // ok|refused
            $table->string('key_id', 40)->nullable();
            $table->string('actor', 190);
            $table->string('detail', 190)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'branch_id', 'created_at'], 'ebra_scope_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_backup_recovery_audits');
        Schema::dropIfExists('edge_backup_recovery_keys');
    }
};
