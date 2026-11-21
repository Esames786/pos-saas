<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (K2 setup session) — the owner presses "Set up a Branch Server" on the Cloud Offline Edge page and gets a
 * short-lived, single-use SETUP CODE for one tenant+branch. The Branch Server setup wizard redeems it on the central
 * domain (POST /api/edge/setup/redeem) for a fresh pairing code (minted through the EXISTING EdgePairingService) and an
 * opaque SETUP TOKEN that later authorises the bulk enrollment-assertion call (K3).
 *
 * MASTER DB (like edge_pairing_codes: the wizard has no tenant credentials yet). Nothing secret is stored in clear:
 *   code_hash         = HMAC-SHA256(normalised code, app key)   (the code is shown once, never stored)
 *   setup_token_hash  = SHA-256(token)                         (the token is returned once, never stored)
 * One live code per branch via unique(tenant_id, branch_id, active_slot) — active_slot is 1 while the code can still be
 * redeemed and NULL once it is redeemed / cancelled / expired / exhausted (MySQL allows many NULLs → history kept).
 * branch_id is a TENANT-DB id; there is deliberately no cross-DB foreign key. Additive only.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        if (Schema::connection($this->getConnection())->hasTable('edge_setup_sessions')) {
            return;
        }

        Schema::connection($this->getConnection())->create('edge_setup_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');               // tenant-DB branch id; no cross-DB FK
            $table->string('mode', 16)->default('install');        // install | update (the branch already has a device)
            $table->char('code_hash', 64)->unique();               // hmac_sha256(code, app key) — never plaintext
            $table->unsignedTinyInteger('active_slot')->nullable(); // 1 = the branch's live setup code
            $table->timestamp('code_expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->unsignedBigInteger('created_by_user_id');       // the issuer of every assertion of this session
            $table->timestamp('redeemed_at')->nullable();
            $table->uuid('redeemed_installation_uuid')->nullable();
            $table->string('redeemed_device_name', 190)->nullable();
            $table->char('setup_token_hash', 64)->nullable()->unique(); // sha256(token) — never plaintext
            $table->timestamp('token_expires_at')->nullable();
            $table->uuid('pairing_code_uuid')->nullable();          // edge_pairing_codes.public_uuid minted at redeem
            $table->unsignedInteger('assertions_issued')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
            $table->unique(['tenant_id', 'branch_id', 'active_slot'], 'edge_setup_sessions_active_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('edge_setup_sessions');
    }
};
