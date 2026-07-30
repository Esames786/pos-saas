<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRANCH-DEVICE-PAIRING-1 — pairing lives in the MASTER DB because pairing happens
 * before an Edge device has any tenant credentials or a local tenant DB.
 *
 * `branch_id` references a TENANT-DB branch id — there is deliberately NO cross-DB
 * foreign key. One active device per (tenant, branch) is enforced by a unique index on
 * (tenant_id, branch_id, active_slot) where active_slot is 1 for the current device and
 * NULL for revoked/replaced rows (MySQL allows multiple NULLs → history preserved).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::create('edge_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();          // exposed to the device
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');        // tenant-DB branch id; no cross-DB FK
            $table->uuid('installation_uuid')->unique();    // installer-generated, once
            $table->string('device_name', 190)->nullable();
            $table->string('device_secret_hash', 64);       // sha256(device_secret) — never plaintext
            $table->string('status', 32)->default('pending_bootstrap'); // paired|pending_bootstrap|ready|active|revoked
            $table->unsignedTinyInteger('active_slot')->nullable();     // 1 = current device; NULL = revoked/replaced
            $table->string('app_version', 40)->nullable();
            $table->string('schema_version', 40)->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->string('revoke_reason', 190)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
            $table->unique(['tenant_id', 'branch_id', 'active_slot'], 'edge_devices_active_slot_unique');
        });

        Schema::create('edge_pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('code_hash', 64);                // hmac_sha256(public_uuid|code, app key) — never plaintext
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('paired_device_id')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable(); // 1 = the branch's live code; NULL once used/cancelled/expired
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
            $table->unique(['tenant_id', 'branch_id', 'active_slot'], 'edge_pairing_codes_active_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_pairing_codes');
        Schema::dropIfExists('edge_devices');
    }
};
