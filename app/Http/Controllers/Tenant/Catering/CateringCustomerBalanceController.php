<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringCustomerBalanceService;
use Illuminate\Http\Request;

/**
 * CATERING-CUSTOMER-BALANCES-1 — graahak ka hisaab, booking ke bahar se.
 *
 * Read-only. Ye screen paisa nahi leti: wo `CateringAdvanceService::record()`
 * ka kaam hai aur EVENT ke against hota hai, kyunke posting ka faisla usi par
 * hai (invoice se pehle Cr 2300, baad me Cr 1300). Ek graahak ke khilaf
 * "receipt" lene wali screen wo faisla kar hi nahi sakti.
 *
 * Route ka naam `tenant.catering.*` hai, `tenant.finance.*` nahi — chahe
 * sidebar par ye Finance ke neeche dikhti ho. Naam se module tay hota hai
 * (2-segment derivation), aur ye screen catering ki hai: jis tenant ke paas
 * catering nahi, us ke liye ye ek khali screen hoti.
 */
class CateringCustomerBalanceController extends Controller
{
    public function __construct(private CateringCustomerBalanceService $balances) {}

    public function index(Request $request)
    {
        $branchId = $request->integer('branch_id') ?: null;
        $search = trim((string) $request->query('q', ''));

        $rows = $this->balances->rows($branchId, $search ?: null);

        return view('tenant.catering.customer-balances.index', [
            'rows' => $rows,
            'unlinked' => $this->balances->unlinked($branchId),
            'branches' => Branch::on('tenant')->orderBy('name')->get(['id', 'name']),
            'branchId' => $branchId,
            'search' => $search,
            'totals' => [
                'billed' => round((float) $rows->sum('billed'), 2),
                'received' => round((float) $rows->sum('received'), 2),
                'balance' => round((float) $rows->sum('balance'), 2),
                'credit' => round((float) $rows->sum('credit'), 2),
            ],
        ]);
    }

    public function show(Request $request, Customer $customer)
    {
        return view('tenant.catering.customer-balances.show', $this->balances->forCustomer(
            $customer,
            $request->integer('branch_id') ?: null,
        ) + [
            // Wohi fehrist jo booking ki screen deti hai. Paisa lene ke form
            // yahan se bhi wohi endpoints par jate hain, is liye khaane bhi
            // bilkul wohi hone chahiyen — warna do screenein do alag cheezein
            // maangne lagti hain.
            'paymentMethods' => \App\Models\Tenant\PaymentMethod::on('tenant')
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
