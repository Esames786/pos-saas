<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 security (approver eligibility, bootstrap v8) — EDGE-ONLY migration (database/migrations/edge; never a
 * Cloud tenant DB).
 *
 * The appliance stores the Cloud-authoritative manager-approval eligibility of every bootstrapped user as
 * `users.may_approve_pos` (edge-bootstrap-v8 users[].may_approve_pos: active manager PIN AND active user on the
 * Cloud). The importer writes it on bootstrap and the config-refresh applier rewrites it with every applied revision,
 * so a PIN removed on the Cloud revokes offline approval rights at the next refresh. EdgeLocalAuthService::verifyManager
 * requires it (EdgeUserAuthz::mayApprovePos) — a permission alone (tenant.pos.void-kot-item) is no longer an approver
 * marker. Default FALSE = fail closed. Additive, idempotent (hasColumn guard) — safe for the forward-only appliance
 * schema upgrader. On the Cloud the flag is DERIVED from manager_pins at export time; no Cloud column exists.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('users', 'may_approve_pos')) {
            return;
        }
        Schema::connection($this->connection)->table('users', function (Blueprint $table) {
            $table->boolean('may_approve_pos')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('users', 'may_approve_pos')) {
            return;
        }
        Schema::connection($this->connection)->table('users', function (Blueprint $table) {
            $table->dropColumn('may_approve_pos');
        });
    }
};
