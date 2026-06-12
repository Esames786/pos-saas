<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::connection('master')->table('subscription_payments', function (Blueprint $table) {
            if (!Schema::connection('master')->hasColumn('subscription_payments', 'proof_path')) {
                $table->string('proof_path')->nullable()->after('notes');
            }

            if (!Schema::connection('master')->hasColumn('subscription_payments', 'proof_original_name')) {
                $table->string('proof_original_name')->nullable()->after('proof_path');
            }

            if (!Schema::connection('master')->hasColumn('subscription_payments', 'proof_uploaded_by_user_id')) {
                // References a TENANT DB user id — no FK constraint (cross-database).
                $table->unsignedBigInteger('proof_uploaded_by_user_id')->nullable()->after('proof_original_name');
            }

            if (!Schema::connection('master')->hasColumn('subscription_payments', 'proof_uploaded_at')) {
                $table->timestamp('proof_uploaded_at')->nullable()->after('proof_uploaded_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('subscription_payments', function (Blueprint $table) {
            foreach (['proof_uploaded_at', 'proof_uploaded_by_user_id', 'proof_original_name', 'proof_path'] as $col) {
                if (Schema::connection('master')->hasColumn('subscription_payments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
