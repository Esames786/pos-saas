<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userCols = Schema::connection('tenant')->getColumnListing('users');

        Schema::connection('tenant')->table('users', function (Blueprint $table) use ($userCols) {
            if (!in_array('employee_code', $userCols)) {
                $table->string('employee_code', 50)->nullable()->unique()->after('id');
            }
            if (!in_array('phone', $userCols)) {
                $table->string('phone', 30)->nullable()->after('name');
            }
            if (!in_array('default_branch_id', $userCols)) {
                $table->foreignId('default_branch_id')->nullable()
                    ->after('phone')
                    ->constrained('branches')->nullOnDelete();
            }
            if (!in_array('default_terminal_id', $userCols)) {
                $table->foreignId('default_terminal_id')->nullable()
                    ->after('default_branch_id')
                    ->constrained('terminals')->nullOnDelete();
            }
            if (!in_array('force_password_change', $userCols)) {
                $table->boolean('force_password_change')->default(false)->after('locale');
            }
            if (!in_array('last_login_at', $userCols)) {
                $table->timestamp('last_login_at')->nullable()->after('force_password_change');
            }
        });

        if (!Schema::connection('tenant')->hasColumn('branch_user', 'is_default')) {
            Schema::connection('tenant')->table('branch_user', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('user_id');
            });
        }

        if (!Schema::connection('tenant')->hasTable('terminal_user')) {
            Schema::connection('tenant')->create('terminal_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('terminal_id')->constrained('terminals')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('is_default')->default(false);
                $table->timestamps();

                $table->unique(['terminal_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('terminal_user');
    }
};
