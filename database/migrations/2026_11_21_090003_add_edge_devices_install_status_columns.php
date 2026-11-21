<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (K4 device status, K5 LAN profile, K6 update-now + maintenance window) — new NULLABLE columns on the master
 * edge_devices row. Informational / operator-facing only: nothing here is read by pairing, the authority lease, the
 * Cloud fence or ingestion.
 *
 *   install_*            the wizard / auto-updater's last reported phase (installing … ready, updating … rolled_back)
 *   reported_version     the version the appliance says it now runs (K4); falls back to app_version for the update check
 *   lan_*                the PUBLIC LAN profile (hostname, IP, HTTPS port, branch CA certificate + its SHA-256) the Cloud
 *                        renders the counter setup script and the phone page from (K5/K7). Never a private key.
 *   update_now_requested_at   set by the owner's "Update now" button; returned by GET /api/edge/updates/latest (K6)
 *   maintenance_window_*      per-device auto-update window "HH:MM" in the branch business timezone (NULL = 03:00–05:00)
 *   live_state_*         K1 — the MONOTONIC live-state revision counter of this device + the sha256 of the state it numbers
 *                        (bumped by compare-and-set only when the branch's live state actually changed)
 *
 * Additive only (hasColumn guarded so a partially applied run can be re-run).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'install_phase', 'install_ok', 'install_message', 'install_reported_at', 'reported_version',
        'lan_hostname', 'lan_ip', 'lan_https_port', 'lan_ca_pem', 'lan_ca_sha256', 'lan_reported_at',
        'update_now_requested_at', 'maintenance_window_start', 'maintenance_window_end',
        'live_state_revision', 'live_state_hash', 'live_state_changed_at',
    ];

    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $has = fn (string $c) => $schema->hasColumn('edge_devices', $c);

        $schema->table('edge_devices', function (Blueprint $table) use ($has) {
            if (! $has('install_phase')) {
                $table->string('install_phase', 32)->nullable();
            }
            if (! $has('install_ok')) {
                $table->boolean('install_ok')->nullable();
            }
            if (! $has('install_message')) {
                $table->string('install_message', 500)->nullable();
            }
            if (! $has('install_reported_at')) {
                $table->timestamp('install_reported_at')->nullable();
            }
            if (! $has('reported_version')) {
                $table->string('reported_version', 64)->nullable();
            }
            if (! $has('lan_hostname')) {
                $table->string('lan_hostname', 253)->nullable();
            }
            if (! $has('lan_ip')) {
                $table->string('lan_ip', 45)->nullable();
            }
            if (! $has('lan_https_port')) {
                $table->unsignedInteger('lan_https_port')->nullable();
            }
            if (! $has('lan_ca_pem')) {
                $table->text('lan_ca_pem')->nullable();
            }
            if (! $has('lan_ca_sha256')) {
                $table->char('lan_ca_sha256', 64)->nullable();
            }
            if (! $has('lan_reported_at')) {
                $table->timestamp('lan_reported_at')->nullable();
            }
            if (! $has('update_now_requested_at')) {
                $table->timestamp('update_now_requested_at')->nullable();
            }
            if (! $has('maintenance_window_start')) {
                $table->string('maintenance_window_start', 5)->nullable();
            }
            if (! $has('maintenance_window_end')) {
                $table->string('maintenance_window_end', 5)->nullable();
            }
            if (! $has('live_state_revision')) {
                $table->unsignedBigInteger('live_state_revision')->nullable();
            }
            if (! $has('live_state_hash')) {
                $table->char('live_state_hash', 64)->nullable();
            }
            if (! $has('live_state_changed_at')) {
                $table->timestamp('live_state_changed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $present = array_values(array_filter(self::COLUMNS, fn ($c) => $schema->hasColumn('edge_devices', $c)));
        if ($present !== []) {
            $schema->table('edge_devices', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
