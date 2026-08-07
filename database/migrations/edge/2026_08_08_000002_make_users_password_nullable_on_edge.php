<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-AUTH-1 — on the Edge-local database ONLY, make users.password AND users.email nullable.
 *
 * The bootstrap ships NEITHER the Cloud users.password (a forbidden secret) NOR email (PII kept off
 * the appliance) — so appliance users are imported with neither. The appliance authenticates ONLY via
 * edge_local_user_credentials and identifies users by employee_code, so both Cloud columns are left
 * NULL (the strongest statement that the appliance holds no Cloud credential). Edge migration path
 * only — never against a Cloud tenant DB.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // doctrine/dbal-free: raw MODIFY (edge-local is always MySQL/MariaDB).
        if (Schema::connection($this->connection)->hasColumn('users', 'password')) {
            DB::connection($this->connection)->statement('ALTER TABLE `users` MODIFY `password` VARCHAR(255) NULL');
        }
        if (Schema::connection($this->connection)->hasColumn('users', 'email')) {
            DB::connection($this->connection)->statement('ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        // Best-effort restore (rows with NULL password/email would block a NOT NULL restore; leave nullable).
    }
};
