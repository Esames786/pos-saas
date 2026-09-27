<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KITCHEN-SHEET-PREVIEW-1 — release se pehle wale kitchen sheet ka permission.
 *
 * Permission ka naam route ka naam hota hai, platform ka usool. `deploy.sh`
 * naye route ka permission SIRF OWNER ko deta hai — magar asli kitchen sheet
 * aksar un logon ke paas hoti hai jo Owner nahi (kitchen/production wale role).
 * Agar sirf Owner ko diya jata to malik ke liye feature chal jata aur jis
 * bande ne maanga tha us ke liye 403 aata. Yehi ghalti memory me darj hai:
 * "NEW ROUTE = CHECK NON-OWNER ROLES".
 *
 * Is liye usool yahan DATA se liya gaya hai, andaze se nahi: **jis role ke
 * paas `tenant.catering.documents.kitchen-sheet` pehle se hai, usay preview
 * bhi milega.** Wohi parcha hai, sirf release se pehle — jo asli dekh sakta
 * hai wo preview bhi dekh sakta hai.
 *
 * Owner ko alag se bhi diya jata hai: agar kisi tenant par abhi tak kisi role
 * ke paas kitchen sheet na ho, to ye migration khamoshi se kuch na kar ke
 * feature ko mara hua chhod deti.
 */
return new class extends Migration
{
    private const PREVIEW = 'tenant.catering.documents.kitchen-sheet-preview';

    private const SOURCE = 'tenant.catering.documents.kitchen-sheet';

    public function up(): void
    {
        $db = DB::connection('tenant');

        $previewId = $db->table('permissions')
            ->where('name', self::PREVIEW)->where('guard_name', 'tenant')->value('id')
            ?: $db->table('permissions')->insertGetId([
                'name' => self::PREVIEW, 'guard_name' => 'tenant',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        // Jin roles ke paas asli kitchen sheet hai.
        $roleIds = collect();
        $sourceId = $db->table('permissions')
            ->where('name', self::SOURCE)->where('guard_name', 'tenant')->value('id');
        if ($sourceId) {
            $roleIds = $db->table('role_has_permissions')
                ->where('permission_id', $sourceId)->pluck('role_id');
        }

        // Aur Owner, har haal me.
        $roleIds = $roleIds->merge(
            $db->table('roles')->where('guard_name', 'tenant')->where('name', 'Owner')->pluck('id')
        )->unique();

        foreach ($roleIds as $roleId) {
            $exists = $db->table('role_has_permissions')
                ->where('permission_id', $previewId)->where('role_id', $roleId)->exists();
            if (! $exists) {
                // Additive — kisi role se kuch cheena nahi jata.
                $db->table('role_has_permissions')->insert([
                    'permission_id' => $previewId, 'role_id' => $roleId,
                ]);
            }
        }

        // Stale spatie cache rows 403 new permissions while old ones work.
        $db->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        $db = DB::connection('tenant');
        $id = $db->table('permissions')
            ->where('name', self::PREVIEW)->where('guard_name', 'tenant')->value('id');
        if ($id) {
            $db->table('role_has_permissions')->where('permission_id', $id)->delete();
            $db->table('permissions')->where('id', $id)->delete();
        }
        $db->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }
};
