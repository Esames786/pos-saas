<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;

/**
 * Seeds default Cash & Bank accounts into the CURRENTLY ACTIVATED tenant DB.
 * Must run AFTER DefaultChartOfAccountsSeeder (links to CoA codes 1110/1120/1210/1500).
 * Idempotent: keyed on `code`. All defaults start at zero balance (no opening txn).
 */
class DefaultCashBankAccountsSeeder
{
    /**
     * [code, name, account_type, coa_code, is_default]
     */
    private function definitions(): array
    {
        return [
            ['CASH-MAIN',   'Main Cash Drawer',  'cash',  '1110', true],
            ['CASH-PETTY',  'Petty Cash',        'cash',  '1120', false],
            ['BANK-MAIN',   'Main Bank Account', 'bank',  '1210', false],
            ['UNDEPOSITED', 'Undeposited Funds', 'other', '1500', false],
        ];
    }

    public function run(): int
    {
        $accountIdByCode = Account::whereIn('code', ['1110', '1120', '1210', '1500'])
            ->pluck('id', 'code');

        $count = 0;

        foreach ($this->definitions() as [$code, $name, $type, $coaCode, $isDefault]) {
            CashBankAccount::updateOrCreate(
                ['code' => $code],
                [
                    'account_id'      => $accountIdByCode[$coaCode] ?? null,
                    'name'            => $name,
                    'account_type'    => $type,
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'is_default'      => $isDefault,
                    'is_system'       => true,
                    'is_active'       => true,
                ]
            );

            $count++;
        }

        return $count;
    }
}
