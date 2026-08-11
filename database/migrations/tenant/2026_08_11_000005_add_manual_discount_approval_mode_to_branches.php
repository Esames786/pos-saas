<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('branches', 'manual_discount_approval_mode')) {
            return;
        }

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->string('manual_discount_approval_mode', 30)
                ->default('manager_required')
                ->after('held_kot_line_cancellation_approval_mode');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('branches', 'manual_discount_approval_mode')) {
                $table->dropColumn('manual_discount_approval_mode');
            }
        });
    }
};
