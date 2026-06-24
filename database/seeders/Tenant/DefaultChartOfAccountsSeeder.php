<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Account;

/**
 * Seeds a default Chart of Accounts into the CURRENTLY ACTIVATED tenant DB.
 * Idempotent: keyed on account `code`, so re-running updates in place.
 * Must be called after TenancyManager::activate($tenant) (FIN-2).
 *
 * No journals/postings are created here — this is the account skeleton only.
 */
class DefaultChartOfAccountsSeeder
{
    /**
     * Default accounts as [code, name, type, parent_code, normal_balance|null, sort_order].
     * normal_balance null → derived from type (asset/expense=debit, else credit).
     * Parents are listed before their children so parent ids resolve in order.
     */
    private function definitions(): array
    {
        return [
            // ── Assets ──
            ['1000', 'Assets',              'asset', null,   null, 10],
            ['1100', 'Cash on Hand',        'asset', '1000', null, 20],
            ['1110', 'Main Cash Drawer',    'asset', '1100', null, 30],
            ['1120', 'Petty Cash',          'asset', '1100', null, 40],
            ['1200', 'Bank Accounts',       'asset', '1000', null, 50],
            ['1210', 'Main Bank Account',   'asset', '1200', null, 60],
            ['1300', 'Accounts Receivable', 'asset', '1000', null, 70],
            ['1400', 'Inventory Asset',     'asset', '1000', null, 80],
            // Manufacturing inventory sub-accounts (MFG-FIN-A) — children of Inventory Asset.
            ['1410', 'Raw Material Inventory',          'asset', '1400', null, 81],
            ['1420', 'Work In Process Inventory',       'asset', '1400', null, 82],
            ['1430', 'Finished Goods Inventory',        'asset', '1400', null, 83],
            ['1490', 'Manufacturing Overhead Clearing', 'asset', '1400', null, 84],
            ['1500', 'Undeposited Funds',   'asset', '1000', null, 90],

            // ── Liabilities ──
            ['2000', 'Liabilities',         'liability', null,   null, 100],
            ['2100', 'Accounts Payable',    'liability', '2000', null, 110],
            ['2200', 'Sales Tax Payable',   'liability', '2000', null, 120],
            ['2300', 'Customer Advances',   'liability', '2000', null, 130],

            // ── Equity ──
            ['3000', 'Equity',              'equity', null,   null, 140],
            ['3100', 'Owner Capital',       'equity', '3000', null, 150],
            ['3200', 'Retained Earnings',   'equity', '3000', null, 160],

            // ── Income ──
            ['4000', 'Income',              'income', null,   null, 170],
            ['4100', 'Sales Revenue',       'income', '4000', null, 180],
            ['4110', 'Retail Sales',        'income', '4100', null, 190],
            ['4120', 'Restaurant Sales',    'income', '4100', null, 200],
            ['4130', 'Service Charges',     'income', '4100', null, 210],
            ['4140', 'Tips Income',         'income', '4100', null, 220],
            // Contra-income: reduces revenue, so debit-normal under the Income tree.
            ['4200', 'Sales Discounts',     'income', '4000', 'debit', 230],

            // ── Cost of Goods Sold (expense type) ──
            ['5000', 'Cost of Goods Sold',         'expense', null,   null, 240],
            ['5100', 'Product COGS',               'expense', '5000', null, 250],
            ['5200', 'Recipe / Ingredient COGS',   'expense', '5000', null, 260],
            // Manufacturing COGS / variance (MFG-FIN-A) — children of COGS.
            ['5300', 'Production Variance',        'expense', '5000', null, 262],
            ['5310', 'Manufactured Goods COGS',    'expense', '5000', null, 264],

            // ── Expenses ──
            ['6000', 'Expenses',                'expense', null,   null, 270],
            ['6100', 'Rent Expense',            'expense', '6000', null, 280],
            ['6200', 'Salaries & Wages',        'expense', '6000', null, 290],
            // Manufacturing direct labour (MFG-FIN-A) — child of Salaries & Wages.
            ['6210', 'Direct Labour',           'expense', '6200', null, 295],
            ['6300', 'Utilities',               'expense', '6000', null, 300],
            ['6400', 'Repairs & Maintenance',   'expense', '6000', null, 310],
            ['6500', 'Packaging Expense',       'expense', '6000', null, 320],
            ['6600', 'Delivery Expense',        'expense', '6000', null, 330],
            ['6700', 'Bank Charges',            'expense', '6000', null, 340],
            ['6800', 'Miscellaneous Expense',   'expense', '6000', null, 350],
            // Manufacturing expenses (MFG-FIN-A) — children of Expenses.
            ['6900', 'Scrap / Waste Expense',     'expense', '6000', null, 360],
            ['6910', 'Rework Expense',            'expense', '6000', null, 370],
            ['6920', 'Inventory Adjustment Expense', 'expense', '6000', null, 380],
        ];
    }

    public function run(): int
    {
        $idByCode = [];
        $count = 0;

        foreach ($this->definitions() as [$code, $name, $type, $parentCode, $normalBalance, $sortOrder]) {
            $account = Account::updateOrCreate(
                ['code' => $code],
                [
                    'name'           => $name,
                    'type'           => $type,
                    'normal_balance' => $normalBalance ?: Account::normalBalanceForType($type),
                    'parent_id'      => $parentCode ? ($idByCode[$parentCode] ?? null) : null,
                    'is_system'      => true,
                    'is_active'      => true,
                    'sort_order'     => $sortOrder,
                ]
            );

            $idByCode[$code] = $account->id;
            $count++;
        }

        return $count;
    }
}
