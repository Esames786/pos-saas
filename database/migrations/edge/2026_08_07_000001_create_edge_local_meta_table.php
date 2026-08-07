<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section D + G) — EDGE-ONLY migration.
 *
 * Lives in database/migrations/edge (NOT database/migrations/tenant), so deploy.sh — which migrates
 * every Cloud tenant with --path=database/migrations/tenant — never creates this table in a Cloud
 * tenant database. It is applied only by the appliance's guarded `edge:local:db-init` against the
 * dedicated Edge-local database.
 *
 * `edge_local_meta` is the appliance's single immutable binding + runtime-state record: which
 * tenant / branch / device this box is, which activation generation (epoch) it belongs to, which
 * bootstrap snapshot was imported, and how far the local runtime has progressed. There is exactly
 * ONE row (singleton_guard unique).
 *
 * Runs on the `tenant` connection, which on a branch_server runtime and in the MySQL test harness is
 * resolved to the Edge-local database (see App\Support\EdgeLocalDatabase).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection($this->connection)->create('edge_local_meta', function (Blueprint $table) {
            $table->id();

            // Exactly one binding row on the appliance.
            $table->unsignedTinyInteger('singleton_guard')->default(1)->unique();

            // Immutable identity (set at first successful bootstrap import, never silently changed).
            $table->string('tenant_code', 190)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();      // Cloud tenant id
            $table->unsignedBigInteger('branch_id')->nullable();      // Cloud/tenant branch id
            $table->string('device_uuid', 64)->nullable();            // edge_devices.public_uuid
            $table->unsignedBigInteger('activation_epoch')->nullable(); // branch activation generation

            // Which bootstrap was imported.
            $table->string('bootstrap_snapshot_uuid', 64)->nullable();
            $table->string('bootstrap_schema', 64)->nullable();
            $table->string('source_revision', 128)->nullable();       // config revision watermark
            $table->string('manifest_hash', 128)->nullable();

            $table->timestamp('imported_at')->nullable();

            // uninitialized | importing | bootstrapped | error  (NOT active/selling/synced — those
            // capabilities do not exist yet).
            $table->string('runtime_state', 32)->default('uninitialized');
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_local_meta');
    }
};
