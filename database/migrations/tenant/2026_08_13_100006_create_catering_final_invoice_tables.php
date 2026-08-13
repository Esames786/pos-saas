<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-V1-CLOSURE-1 (§5): final invoice + event closure.
 *
 * catering_final_invoices is the immutable event-day commercial document —
 * totals, advances and balance frozen at issue time with a full JSON snapshot.
 * It is NOT a sales_order and posts NO GL in V1: safe advance/final-settlement
 * posting needs a customer-advance liability account + catering revenue
 * mapping on the chart of accounts — a finance design that goes through
 * JournalPostingService translator methods later, never a homemade ledger.
 *
 * Also seeds the closure/print permissions added in this slice
 * (permission name == route name; Owner-granted; pattern 2026_08_13_100003).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'tenant.catering.final-invoices.store',
        'tenant.catering.events.close',
        'tenant.catering.documents.final-invoice',
        'tenant.catering.production-releases.print',
        'tenant.catering.production-releases.reprint',
    ];

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_final_invoices')) {
            Schema::connection('tenant')->create('catering_final_invoices', function (Blueprint $table) {
                $table->id();
                $table->char('invoice_uuid', 26)->nullable()->unique();
                $table->string('invoice_no', 50)->unique();
                $table->foreignId('catering_event_id')->constrained('catering_events')->cascadeOnDelete();
                $table->foreignId('catering_estimate_id')->nullable()->constrained('catering_estimates')->nullOnDelete();
                $table->json('snapshot'); // event header + lines + totals + advances at issue
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('service_charge_amount', 14, 2)->default(0);
                $table->string('other_charge_label')->nullable();
                $table->decimal('other_charge_amount', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('grand_total', 14, 2)->default(0);
                $table->decimal('advance_total', 14, 2)->default(0);
                $table->decimal('balance_due', 14, 2)->default(0);
                $table->string('status', 20)->default('issued'); // issued (void policy deferred)
                $table->timestamp('issued_at');
                $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique('catering_event_id', 'catering_final_invoices_event_unique');
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

        Schema::connection('tenant')->dropIfExists('catering_final_invoices');
    }
};
