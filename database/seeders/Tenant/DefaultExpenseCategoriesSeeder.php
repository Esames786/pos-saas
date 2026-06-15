<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Account;
use App\Models\Tenant\ExpenseCategory;

/**
 * Seeds default expense categories into the CURRENTLY ACTIVATED tenant DB.
 * Must run AFTER DefaultChartOfAccountsSeeder (links to expense CoA codes 6100-6800).
 * Idempotent: keyed on `code`. All defaults are is_system.
 */
class DefaultExpenseCategoriesSeeder
{
    /**
     * [code, name, coa_code, sort_order]
     */
    private function definitions(): array
    {
        return [
            ['RENT',      'Rent Expense',          '6100', 10],
            ['SALARY',    'Salaries & Wages',      '6200', 20],
            ['UTILITIES', 'Utilities',             '6300', 30],
            ['REPAIRS',   'Repairs & Maintenance', '6400', 40],
            ['PACKAGING', 'Packaging Expense',     '6500', 50],
            ['DELIVERY',  'Delivery Expense',      '6600', 60],
            ['BANKCHG',   'Bank Charges',          '6700', 70],
            ['MISC',      'Miscellaneous Expense', '6800', 80],
        ];
    }

    public function run(): int
    {
        $accountIdByCode = Account::whereIn('code', ['6100', '6200', '6300', '6400', '6500', '6600', '6700', '6800'])
            ->pluck('id', 'code');

        $count = 0;

        foreach ($this->definitions() as [$code, $name, $coaCode, $sortOrder]) {
            ExpenseCategory::updateOrCreate(
                ['code' => $code],
                [
                    'account_id' => $accountIdByCode[$coaCode] ?? null,
                    'name'       => $name,
                    'is_system'  => true,
                    'is_active'  => true,
                    'sort_order' => $sortOrder,
                ]
            );

            $count++;
        }

        return $count;
    }
}
