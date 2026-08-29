<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QUICK-REPORT-SEND-1 — the single synthetic permission gating the POS "Quick Report" modal (a trusted
 * counter user may email/print/network an UNSCOPED Sales-Report of the whole tenant). Seeded + granted
 * to Owner; other users get it via the Permission Center. Non-route (the modal endpoints are exempted
 * in EnsureRoutePermission and gate on THIS permission in the controller).
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.pos.quick-report-send';

    public function up(): void
    {
        $conn = DB::connection('tenant');

        $permId = $conn->table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id')
            ?: $conn->table('permissions')->insertGetId([
                'name' => self::PERMISSION, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        $ownerId = $conn->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id');
        if ($ownerId && ! $conn->table('role_has_permissions')->where('permission_id', $permId)->where('role_id', $ownerId)->exists()) {
            $conn->table('role_has_permissions')->insert(['permission_id' => $permId, 'role_id' => $ownerId]);
        }

        // stale spatie cache rows would 403 the new permission (PROD-READINESS-1).
        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        DB::connection('tenant')->table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'tenant')->delete();
    }
};
