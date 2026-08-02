<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            $table->json('allowed_order_types')->nullable()->after('default_terminal_id');
            $table->string('default_order_type', 30)->nullable()->after('allowed_order_types');
        });

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->string('held_kot_cancellation_approval_mode', 30)
                ->default('manager_required')
                ->after('allow_negative_stock');
        });

        Schema::connection('tenant')->table('manager_approvals', function (Blueprint $table) {
            $table->timestamp('consumed_at')->nullable()->after('approved_at');
            $table->foreignId('consumed_by_user_id')->nullable()->after('consumed_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::connection('tenant')->create('kot_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->string('event_type', 30);
            $table->foreignId('reprint_of_batch_id')->nullable()->constrained('kot_batches')->nullOnDelete();
            $table->unsignedInteger('copy_no')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sales_order_id', 'sequence_no']);
            $table->index(['sales_order_id', 'event_type']);
        });

        Schema::connection('tenant')->create('kot_batch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kot_batch_id')->constrained('kot_batches')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('unit_code', 50)->nullable();
            $table->string('line_kind', 30)->default('standard');
            $table->foreignId('combo_id')->nullable()->constrained('combos')->nullOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->json('modifiers')->nullable();
            $table->string('kitchen_note')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('sales_order_line_cancellations', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
            $table->foreignId('void_reason_id')->constrained('void_reasons')->restrictOnDelete();
            $table->foreignId('manager_approval_id')->nullable()->constrained('manager_approvals')->nullOnDelete();
            $table->foreignId('kot_batch_id')->nullable()->constrained('kot_batches')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_mode', 30);
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->decimal('quantity', 14, 6);
            $table->json('policy_snapshot')->nullable();
            $table->timestamp('cancelled_at');
            $table->timestamps();

            $table->index(['sales_order_id', 'sales_order_line_id'], 'sol_cancel_sale_line_idx');
        });

        $permissionId = DB::connection('tenant')->table('permissions')
            ->where('name', 'tenant.pos.void-kot-item')->where('guard_name', 'tenant')->value('id');
        if (!$permissionId) {
            $permissionId = DB::connection('tenant')->table('permissions')->insertGetId([
                'name' => 'tenant.pos.void-kot-item',
                'guard_name' => 'tenant',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $existingCancelPermissionId = DB::connection('tenant')->table('permissions')
            ->where('name', 'tenant.held-sales.cancel')->where('guard_name', 'tenant')->value('id');
        $roleIds = DB::connection('tenant')->table('roles')
            ->where('guard_name', 'tenant')
            ->where(function ($query) use ($existingCancelPermissionId) {
                $query->where('name', 'Owner');
                if ($existingCancelPermissionId) {
                    $query->orWhereIn('id', DB::connection('tenant')->table('role_has_permissions')
                        ->where('permission_id', $existingCancelPermissionId)
                        ->select('role_id'));
                }
            })
            ->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::connection('tenant')->table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sales_order_line_cancellations');
        Schema::connection('tenant')->dropIfExists('kot_batch_lines');
        Schema::connection('tenant')->dropIfExists('kot_batches');

        $permissionIds = DB::connection('tenant')->table('permissions')
            ->where('name', 'tenant.pos.void-kot-item')->where('guard_name', 'tenant')->pluck('id');
        DB::connection('tenant')->table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::connection('tenant')->table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::connection('tenant')->table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::connection('tenant')->table('manager_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consumed_by_user_id');
            $table->dropColumn('consumed_at');
        });

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->dropColumn('held_kot_cancellation_approval_mode');
        });

        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            $table->dropColumn(['allowed_order_types', 'default_order_type']);
        });
    }
};
