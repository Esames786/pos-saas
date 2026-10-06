<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringCustomerBalanceService;
use App\Support\Catering\EventDateWindow;
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

    /**
     * CATERING-BALANCES-STATUS-FILTER-1 — URL se maanga hua status, saaf kar ke.
     *
     * `open` ek jama naam hai jo model pehle se jaanta hai (OPEN_STATUSES), is
     * liye yahan ek nayi tareef nahi likhi ja rahi. Us ke siwa sirf wohi qubool
     * hota hai jo waqai ek status hai — warna URL me kuch bhi likh kar seedha
     * query me daala ja sakta tha.
     *
     * @return array{0: string, 1: array<int, string>} [jo URL me tha, jo query par lagega]
     */
    private function statusFilter(Request $request): array
    {
        $asked = trim((string) $request->query('status', ''));

        if ($asked === 'open') {
            return ['open', CateringEvent::OPEN_STATUSES];
        }

        return in_array($asked, CateringEvent::STATUSES, true)
            ? [$asked, [$asked]]
            : ['', []];
    }

    public function index(Request $request)
    {
        $branchId = $request->integer('branch_id') ?: null;
        $search = trim((string) $request->query('q', ''));
        [$status, $statuses] = $this->statusFilter($request);
        // CATERING-BALANCES-DATE-FILTER-1 — qaida sanjha hai, yahan dobara
        // nahi likha gaya: bookings ki fehrist bhi isi se From/To parhti hai.
        [$from, $to] = EventDateWindow::window($request->input('from'), $request->input('to'));

        $rows = $this->balances->rows($branchId, $search ?: null, $statuses, $from, $to);

        return view('tenant.catering.customer-balances.index', [
            'rows' => $rows,
            // Banner bhi usi muddat ka ho — warna upar "7 bookings not linked"
            // likha rehta jab ke neeche ki fehrist sirf ek mahine ki hai, aur
            // do adad ek doosre ko jhutlate hain.
            'unlinked' => $this->balances->unlinked($branchId, $statuses, $from, $to),
            'branches' => Branch::on('tenant')->orderBy('name')->get(['id', 'name']),
            'branchId' => $branchId,
            'search' => $search,
            'status' => $status,
            'from' => $from,
            'to' => $to,
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
        [$status, $statuses] = $this->statusFilter($request);
        [$from, $to] = EventDateWindow::window($request->input('from'), $request->input('to'));

        return view('tenant.catering.customer-balances.show', $this->balances->forCustomer(
            $customer,
            $request->integer('branch_id') ?: null,
            // Filter fehrist se tafseel tak saath chalta hai: jis haalat ke
            // graahak dekh kar aap ne click kiya, wahi bookings andar bhi
            // milni chahiyen — warna adad badal jate hain aur screen apni hi
            // pichhli satar ko jhutla deti hai.
            $statuses,
            // Muddat bhi saath aati hai, usi wajah se: fehrist par 4 events
            // dekh kar click kiya to andar bhi 4 milne chahiyen.
            $from,
            $to,
        ) + [
            'status' => $status,
            'from' => $from,
            'to' => $to,
            // Wohi fehrist jo booking ki screen deti hai. Paisa lene ke form
            // yahan se bhi wohi endpoints par jate hain, is liye khaane bhi
            // bilkul wohi hone chahiyen — warna do screenein do alag cheezein
            // maangne lagti hain.
            'paymentMethods' => \App\Models\Tenant\PaymentMethod::on('tenant')
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
