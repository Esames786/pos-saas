<?php

namespace Database\Seeders;

use App\Models\Master\BillingPaymentMethod;
use Illuminate\Database\Seeder;

/**
 * CLOUD-BILLING-1A — seed the SET of supported manual payment methods only.
 *
 * Account title / number / instructions are intentionally left blank and are entered by a
 * central admin through the Payment Methods screen (never committed to git). Methods seed as
 * INACTIVE so a half-configured method (no account entered yet) is never shown to a tenant;
 * the admin fills the details and activates. Uses firstOrCreate so re-seeding never clobbers
 * admin-entered account details or the active flag.
 */
class BillingPaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['code' => 'easypaisa', 'display_name' => 'EasyPaisa', 'sort_order' => 1],
            ['code' => 'jazzcash',  'display_name' => 'JazzCash',  'sort_order' => 2],
            ['code' => 'nayapay',   'display_name' => 'NayaPay',   'sort_order' => 3],
        ];

        foreach ($methods as $m) {
            BillingPaymentMethod::firstOrCreate(
                ['code' => $m['code']],
                [
                    'display_name' => $m['display_name'],
                    'account_title' => null,
                    'account_number' => null,
                    'instructions' => null,
                    'is_active' => false,
                    'sort_order' => $m['sort_order'],
                ]
            );
        }
    }
}
