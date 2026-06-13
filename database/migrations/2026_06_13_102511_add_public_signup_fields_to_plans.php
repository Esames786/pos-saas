<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::connection('master')->table('plans', function (Blueprint $table) {
            if (! Schema::connection('master')->hasColumn('plans', 'is_public')) {
                $table->boolean('is_public')->default(false)->after('is_active');
            }

            if (! Schema::connection('master')->hasColumn('plans', 'trial_days')) {
                $table->unsignedInteger('trial_days')->nullable()->after('is_public');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('plans', function (Blueprint $table) {
            if (Schema::connection('master')->hasColumn('plans', 'trial_days')) {
                $table->dropColumn('trial_days');
            }

            if (Schema::connection('master')->hasColumn('plans', 'is_public')) {
                $table->dropColumn('is_public');
            }
        });
    }
};
