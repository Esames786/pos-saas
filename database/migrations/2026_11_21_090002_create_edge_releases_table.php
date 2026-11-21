<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (K6 releases) — the registry of SIGNED Branch Server packages the Cloud can hand out: the "Download Branch
 * Server Setup" button on the Offline Edge page (entitlement gate 3) and the appliance auto-update channel
 * (GET /api/edge/updates/latest + a short-lived signed download URL).
 *
 * A row is written ONLY by `php artisan edge:release:publish <package-dir>`, after the package passed the existing
 * integrity + boundary audit (EdgePackageBuilder::verify) and its signed update package verified against the configured
 * update PUBLIC key (EdgeUpdateVerifier: Ed25519 signature + artifact manifest hash). The zip the Cloud serves is built by
 * that command from the verified directory and is identified by zip_sha256 + size_bytes (re-checked on every download).
 *
 * MASTER DB, global (not per tenant). Additive only.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        if (Schema::connection($this->getConnection())->hasTable('edge_releases')) {
            return;
        }

        Schema::connection($this->getConnection())->create('edge_releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->string('channel', 32)->default('stable');
            $table->string('version', 64);
            $table->string('status', 16)->default('published');      // published | withdrawn
            $table->char('manifest_sha256', 64);                      // the SIGNED artifact_manifest_hash
            $table->char('package_hash', 64);                         // package-manifest.json package_hash
            $table->char('zip_sha256', 64);                           // sha256 of the zip the Cloud serves
            $table->unsignedBigInteger('size_bytes');
            $table->string('signing_key_id', 32)->nullable();
            $table->string('min_from_version', 64)->nullable();       // signed min_previous_edge_version
            $table->string('schema_generation', 64)->nullable();
            $table->string('source_revision', 64)->nullable();
            $table->string('update_package_file', 255);               // e.g. update/edge-update-0.9.1-edge.json (inside the zip)
            $table->string('storage_path', 512);                      // relative to storage/app
            $table->unsignedInteger('file_count')->default(0);
            $table->string('published_by', 190)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'version']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('edge_releases');
    }
};
