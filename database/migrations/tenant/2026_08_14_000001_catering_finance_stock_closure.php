<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-GO-LIVE-READINESS-1 (§5–§9): finance + stock integration closure.
 *
 * - catering_advances: GL/cash-bank linkage (write-once) + posting_type
 *   ('advance' = Cr 2300 deposit before invoice; 'settlement' = Cr 1300 AR
 *   payment after invoice) + optional resolved cash_bank_account_id.
 * - catering_final_invoices: write-once accounting linkage (invoice journal,
 *   advance-application journal, gl_posted_at). Commercial fields stay frozen.
 * - catering_material_issues(+lines): immutable material-issue document —
 *   ONE issue per release (retry-idempotent), every stock movement through
 *   InventoryService::postOutFefo (ledger ids + actual FEFO cost recorded per
 *   line), non-stock materials recorded with no movement.
 * - permissions: material issue is a SEPARATE authority from releasing /
 *   printing production (Owner-granted; pattern 2026_08_13_100003).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'tenant.catering.material-issues.store',
        'tenant.catering.material-issues.show',
    ];

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('catering_advances', 'posting_type')) {
            Schema::connection('tenant')->table('catering_advances', function (Blueprint $table) {
                $table->string('posting_type', 20)->nullable()->after('notes'); // advance | settlement
                $table->foreignId('cash_bank_account_id')->nullable()->after('posting_type')
                    ->constrained('cash_bank_accounts')->nullOnDelete();
                $table->foreignId('journal_entry_id')->nullable()->after('cash_bank_account_id')
                    ->constrained('journal_entries')->nullOnDelete();
                $table->timestamp('gl_posted_at')->nullable()->after('journal_entry_id');
            });
        }

        if (! Schema::connection('tenant')->hasColumn('catering_final_invoices', 'journal_entry_id')) {
            Schema::connection('tenant')->table('catering_final_invoices', function (Blueprint $table) {
                $table->foreignId('journal_entry_id')->nullable()->after('status')
                    ->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('advance_application_journal_entry_id')->nullable()->after('journal_entry_id')
                    ->constrained('journal_entries', indexName: 'catering_final_inv_adv_journal_fk')->nullOnDelete();
                $table->timestamp('gl_posted_at')->nullable()->after('advance_application_journal_entry_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_material_issues')) {
            Schema::connection('tenant')->create('catering_material_issues', function (Blueprint $table) {
                $table->id();
                $table->char('issue_uuid', 26)->nullable()->unique();
                $table->string('issue_no', 50)->unique();
                $table->foreignId('catering_production_release_id')
                    ->constrained('catering_production_releases', indexName: 'catering_material_issues_release_fk')
                    ->cascadeOnDelete();
                $table->foreignId('catering_event_id')->constrained('catering_events')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('status', 20)->default('issued'); // issued (reversal policy deferred)
                $table->decimal('total_fefo_cost', 14, 4)->default(0);
                $table->foreignId('cogs_journal_entry_id')->nullable()
                    ->constrained('journal_entries', indexName: 'catering_material_issues_cogs_fk')->nullOnDelete();
                $table->timestamp('issued_at');
                $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // V1: exactly ONE issue per release — the retry-idempotency anchor.
                $table->unique('catering_production_release_id', 'catering_material_issues_release_unique');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_material_issue_lines')) {
            Schema::connection('tenant')->create('catering_material_issue_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_material_issue_id')
                    ->constrained('catering_material_issues', indexName: 'catering_issue_lines_issue_fk')
                    ->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('item_name');
                $table->decimal('required_qty', 14, 3);
                $table->decimal('issued_qty', 14, 3)->default(0);
                $table->string('unit_code', 50)->nullable();
                // non_stock = no movement by design; issued = FEFO movement posted.
                $table->string('line_status', 20)->default('issued'); // issued | non_stock
                $table->json('stock_ledger_ids')->nullable();
                $table->decimal('fefo_cost_total', 14, 4)->default(0);
                $table->timestamps();

                $table->index(['catering_material_issue_id'], 'catering_issue_lines_idx');
            });
        }

        $permId = function (string $name): int {
            $id = DB::connection('tenant')->table('permissions')->where('name', $name)->where('guard_name', 'tenant')->value('id');

            return $id ?: DB::connection('tenant')->table('permissions')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $ownerRoleIds = DB::connection('tenant')->table('roles')
            ->where('guard_name', 'tenant')->where('name', 'Owner')->pluck('id');
        foreach (self::PERMISSIONS as $name) {
            $permissionId = $permId($name);
            foreach ($ownerRoleIds as $roleId) {
                $exists = DB::connection('tenant')->table('role_has_permissions')
                    ->where('permission_id', $permissionId)->where('role_id', $roleId)->exists();
                if (! $exists) {
                    DB::connection('tenant')->table('role_has_permissions')->insert([
                        'permission_id' => $permissionId, 'role_id' => $roleId,
                    ]);
                }
            }
        }
        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        $ids = DB::connection('tenant')->table('permissions')
            ->whereIn('name', self::PERMISSIONS)->where('guard_name', 'tenant')->pluck('id');
        DB::connection('tenant')->table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::connection('tenant')->table('permissions')->whereIn('id', $ids)->delete();

        Schema::connection('tenant')->dropIfExists('catering_material_issue_lines');
        Schema::connection('tenant')->dropIfExists('catering_material_issues');

        if (Schema::connection('tenant')->hasColumn('catering_final_invoices', 'journal_entry_id')) {
            Schema::connection('tenant')->table('catering_final_invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('journal_entry_id');
                $table->dropConstrainedForeignId('advance_application_journal_entry_id');
                $table->dropColumn('gl_posted_at');
            });
        }
        if (Schema::connection('tenant')->hasColumn('catering_advances', 'posting_type')) {
            Schema::connection('tenant')->table('catering_advances', function (Blueprint $table) {
                $table->dropConstrainedForeignId('cash_bank_account_id');
                $table->dropConstrainedForeignId('journal_entry_id');
                $table->dropColumn(['posting_type', 'gl_posted_at']);
            });
        }
    }
};
