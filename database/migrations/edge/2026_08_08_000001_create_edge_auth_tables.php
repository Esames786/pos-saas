<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-AUTH-1 — EDGE-ONLY tables (database/migrations/edge, never run against a Cloud tenant DB).
 *
 * The Branch Server authenticates users with an Edge-SPECIFIC credential — NOT the Cloud
 * users.password (never shipped). These tables hold: the local credential verifier (Argon2id hash,
 * bound to the appliance's activation epoch), the one-time enrollment-assertion replay store (jti
 * single-use), and a non-secret local auth audit trail.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection($this->connection)->create('edge_local_user_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');              // Tenant\User id (bootstrapped locally)
            $table->unsignedBigInteger('branch_id');            // bound branch
            $table->unsignedBigInteger('activation_epoch');     // appliance generation the credential belongs to
            $table->string('credential_hash', 255);            // Argon2id — NEVER the Cloud password
            $table->string('credential_type', 16)->default('password'); // password | pin
            $table->unsignedInteger('credential_version')->default(1);
            $table->string('status', 16)->default('active');   // active | disabled
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamps();

            $table->unique('user_id', 'edge_cred_user_unique');
            $table->index(['branch_id', 'activation_epoch'], 'edge_cred_branch_epoch_idx');
        });

        // One-time enrollment-assertion replay store: a jti may be consumed exactly once.
        Schema::connection($this->connection)->create('edge_consumed_assertions', function (Blueprint $table) {
            $table->id();
            $table->string('jti', 64)->unique('edge_assertion_jti_unique');
            $table->string('purpose', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('activation_epoch')->nullable();
            $table->timestamp('consumed_at');
            $table->timestamps();
        });

        // Non-secret local auth audit (identifiers + event metadata only — never credential material).
        Schema::connection($this->connection)->create('edge_auth_audit', function (Blueprint $table) {
            $table->id();
            $table->string('event', 48);                       // login_success, login_failure, lockout, …
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('activation_epoch')->nullable();
            $table->unsignedBigInteger('issuer_user_id')->nullable(); // enrollment issuer (Cloud actor)
            $table->string('jti', 64)->nullable();
            $table->string('detail', 190)->nullable();         // non-secret reason/context
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event', 'created_at'], 'edge_auth_audit_event_idx');
            $table->index('user_id', 'edge_auth_audit_user_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_auth_audit');
        Schema::connection($this->connection)->dropIfExists('edge_consumed_assertions');
        Schema::connection($this->connection)->dropIfExists('edge_local_user_credentials');
    }
};
