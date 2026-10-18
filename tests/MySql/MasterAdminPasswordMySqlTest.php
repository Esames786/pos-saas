<?php

namespace Tests\MySql;

use App\Models\Master\CentralUser;
use Database\Seeders\MasterSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * MASTER-ADMIN-PASSWORD-1 — deploy.sh runs MasterSeeder on every deploy. It used to reset the Master
 * super admin's password to "password" each time (found on production 19 Oct). A changed password must
 * now survive the seeder, and the account must still get every central permission.
 */
class MasterAdminPasswordMySqlTest extends MySqlTenantTestCase
{
    public function test_a_changed_master_password_survives_the_deploy_seeder(): void
    {
        DB::setDefaultConnection('master');
        (new MasterSeeder)->run();

        $admin = CentralUser::where('email', 'superadmin@mywebsite.com')->firstOrFail();
        $admin->forceFill(['password' => Hash::make('A-new-strong-one-19')])->save();

        (new MasterSeeder)->run();   // what every deploy does

        $admin->refresh();
        $this->assertTrue(Hash::check('A-new-strong-one-19', $admin->password), 'the owner\'s new password is kept');
        $this->assertFalse(Hash::check('password', $admin->password), 'never reset to "password" again');
        $this->assertTrue($admin->hasRole('Super Admin'));
        $this->assertTrue($admin->can('central.tenants.business.update'), 'new Master permissions still reach the Super Admin');
    }
}
