<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        if (! Schema::connection('master')->hasColumn('tenants', 'is_demo')) {
            Schema::connection('master')->table('tenants', function (Blueprint $table) {
                $table->boolean('is_demo')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('master')->hasColumn('tenants', 'is_demo')) {
            Schema::connection('master')->table('tenants', function (Blueprint $table) {
                $table->dropColumn('is_demo');
            });
        }
    }
};
