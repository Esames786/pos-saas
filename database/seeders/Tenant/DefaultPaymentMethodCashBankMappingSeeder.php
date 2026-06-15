<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\PaymentMethod;

/**
 * Maps each tenant payment method to a default cash/bank account by method_type (FIN-7B).
 * Runs AFTER DefaultCashBankAccountsSeeder. Idempotent and non-destructive: only fills
 * payment methods whose cash_bank_account_id is currently null (never clobbers a manual
 * override).
 */
class DefaultPaymentMethodCashBankMappingSeeder
{
    public function run(): int
    {
        $byCode = CashBankAccount::whereIn('code', ['CASH-MAIN', 'BANK-MAIN', 'UNDEPOSITED'])
            ->pluck('id', 'code');

        $cashMain    = $byCode['CASH-MAIN'] ?? null;
        $bankMain    = $byCode['BANK-MAIN'] ?? null;
        $undeposited = $byCode['UNDEPOSITED'] ?? null;

        // method_type → cash/bank account id
        $map = [
            'cash'          => $cashMain,
            'bank_transfer' => $bankMain,
            'card'          => $undeposited,
            'cheque'        => $undeposited,
            'wallet'        => $undeposited,
            'other'         => $undeposited,
        ];

        $count = 0;

        foreach (PaymentMethod::whereNull('cash_bank_account_id')->get() as $method) {
            $target = $map[$method->method_type] ?? $undeposited;

            if ($target) {
                $method->update(['cash_bank_account_id' => $target]);
                $count++;
            }
        }

        return $count;
    }
}
