<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS-REMINDER-PAUSE-1 — a counter can pause its Reminder slips until its shift closes.
 *
 * The pause lives on the SHIFT row, so the next shift starts with Reminder ON again: a switch
 * forgotten OFF cannot silently carry into tomorrow.
 *
 * The toggle endpoint is `tenant.api.pos.*`, which EnsureRoutePermission exempts, so the controller
 * gates on this synthetic permission itself (same pattern as QUICK-REPORT-SEND-1). It goes to Owner
 * and to every role that can hold a POS order — the people standing at the counter.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.pos.pause-reminders';

    private const HOLDERS_OF = 'tenant.held-sales.store';

    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasColumn('shifts', 'reminders_paused_at')) {
            $schema->table('shifts', function (Blueprint $table) {
                $table->timestamp('reminders_paused_at')->nullable()->after('closed_at');
                $table->foreignId('reminders_paused_by_user_id')->nullable()->after('reminders_paused_at')
                    ->constrained('users')->nullOnDelete();
            });
        }

        $conn = DB::connection('tenant');

        $permId = $conn->table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id')
            ?: $conn->table('permissions')->insertGetId([
                'name' => self::PERMISSION, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        $holderPermId = $conn->table('permissions')->where('name', self::HOLDERS_OF)->where('guard_name', 'tenant')->value('id');

        $roleIds = $conn->table('roles')->where('guard_name', 'tenant')
            ->where(function ($q) use ($conn, $holderPermId) {
                $q->where('name', 'Owner');
                if ($holderPermId) {
                    $q->orWhereIn('id', $conn->table('role_has_permissions')
                        ->where('permission_id', $holderPermId)->select('role_id'));
                }
            })
            ->pluck('id');

        // Additive only (insertOrIgnore): nothing is revoked from anyone. A migration runs once, so a
        // role the owner later strips of this permission in the Permission Center stays stripped.
        foreach ($roleIds as $roleId) {
            $conn->table('role_has_permissions')->insertOrIgnore(['permission_id' => $permId, 'role_id' => $roleId]);
        }

        // Stale spatie cache rows would 403 the new permission (PROD-READINESS-1).
        if ($schema->hasTable('cache')) {
            $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');
        if ($schema->hasColumn('shifts', 'reminders_paused_at')) {
            $schema->table('shifts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reminders_paused_by_user_id');
                $table->dropColumn('reminders_paused_at');
            });
        }

        $conn = DB::connection('tenant');
        $ids = $conn->table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'tenant')->pluck('id');
        $conn->table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        $conn->table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        $conn->table('permissions')->whereIn('id', $ids)->delete();
    }
};
