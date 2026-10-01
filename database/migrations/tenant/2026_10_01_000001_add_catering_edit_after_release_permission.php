<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-EDIT-AFTER-RELEASE-1 — `tenant.catering.estimates.edit-after-release`
 * (synthetic, non-route).
 *
 * Kaun quotation badal sakta hai JAB production release ho chuki ho.
 *
 * Pehle ye mumkin hi nahi tha: `released` booking "commercially open" nahi
 * mani jati thi. Malik (1 Oct) ne ijazat maangi, aur wajah waajib hai —
 * graahak aksar usi din item barha deta hai, aur us waqt tak parcha nikal
 * chuka hota hai.
 *
 * Magar us ki ek qeemat hai jo chhupayi nahi ja sakti: nikla hua kitchen
 * sheet purani quotation ka hai. Bawarchi 10 KG pakayega aur bill 12 KG ka
 * banega. Is liye ye apni ijazat ke peeche hai — har wo shaks jo quotation
 * badal sakta hai, zaroori nahi ke RELEASE KE BAAD badalne ka bhi mujaz ho.
 * (Aur jahan dono alag hon, screen aur parcha dono tanbeeh karte hain.)
 *
 * OWNER KO YAHIN DI JA RAHI HAI, aur ye jaan-boojh kar hai: `deploy.sh` ka
 * qadam [5] aur `TenantOpsService::syncTenant()` dono Owner ka grant master
 * ke `route_catalogs` se banate hain, aur jis permission ke peeche koi route
 * na ho wo unhe NAZAR HI NAHI AATI. Ise yahan dena hi wo akela raasta hai jis
 * se ye feature kisi live tenant par pahunchti hai. (Yehi sabaq
 * 2026_09_09_000004 me likha hua hai — wo migration isi liye likhni pari thi
 * ke us se pehle wali ne ye farz kar liya tha ke deploy.sh sambhal lega.)
 *
 * Har DOOSRA role sarih faisla rahega:
 *
 *   php artisan tinker
 *   >>> Role::findByName('Manager', 'tenant')->givePermissionTo('tenant.catering.estimates.edit-after-release');
 *   php artisan system:clear-tenant-permission-cache
 *
 * givePermissionTo, kabhi syncPermissions nahi — doosra us role ka baqi sab
 * kuch chup chaap uda deta hai.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.catering.estimates.edit-after-release';

    public function up(): void
    {
        $conn = DB::connection('tenant');

        $permissionId = $conn->table('permissions')
            ->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id');

        if (! $permissionId) {
            $permissionId = $conn->table('permissions')->insertGetId([
                'name' => self::PERMISSION, 'guard_name' => 'tenant',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $ownerId = $conn->table('roles')
            ->where('name', 'Owner')->where('guard_name', 'tenant')->value('id');

        // Naye tenant ke roles migrations ke BAAD bante hain, is liye wahan
        // yahan koi Owner milta hi nahi. TenantProvisioner apni list me ye naam
        // rakhta hai — dono raaste dhake hue hain.
        if ($ownerId) {
            $held = $conn->table('role_has_permissions')
                ->where('permission_id', $permissionId)->where('role_id', $ownerId)->exists();

            if (! $held) {
                $conn->table('role_has_permissions')->insert([
                    'permission_id' => $permissionId, 'role_id' => $ownerId,
                ]);
            }
        }

        // Purani spatie cache rows nayi permission par 403 deti hain jab ke
        // purani chalti rehti hain — ye "kuch logon ke liye button toota hai"
        // jaisa nazar aata hai.
        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        $conn = DB::connection('tenant');

        $permissionId = $conn->table('permissions')
            ->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id');

        if ($permissionId) {
            $conn->table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            $conn->table('permissions')->where('id', $permissionId)->delete();
        }

        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }
};
