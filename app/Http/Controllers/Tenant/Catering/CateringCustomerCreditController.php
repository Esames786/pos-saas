<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Services\Catering\CateringFinancialPositionService;

/**
 * CATERING-CUSTOMER-CREDIT-WORKLIST-1 — money the business is holding that
 * belongs to its customers.
 *
 * Read-only, on purpose. Refunding is already a deliberate act with its own
 * authority, its own reason and its own document, and it happens on the booking
 * where the money actually sits. This screen exists to make sure somebody is
 * ASKING the question; it does not answer it.
 *
 * The gap it closes: cancelling a booking never refunds anything, so a deposit
 * becomes credit owed back to the customer. close() refuses to finish a booking
 * that still owes money — but a CANCELLED booking never reaches close(), so
 * until now that liability had nowhere to show itself.
 */
class CateringCustomerCreditController extends Controller
{
    public function __construct(private readonly CateringFinancialPositionService $position) {}

    public function index()
    {
        $rows = $this->position->owedToCustomers();

        return view('tenant.catering.customer-credits.index', [
            'rows' => $rows,
            'total' => (float) $rows->sum('credit'),
        ]);
    }
}
