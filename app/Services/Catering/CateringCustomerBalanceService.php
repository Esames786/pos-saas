<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use Illuminate\Support\Collection;

/**
 * CATERING-CUSTOMER-BALANCES-1 — graahak ka hisaab, booking ke bahar se.
 *
 * Malik (28 Sep): "ek screen ho — customer group by name aur phone, us par
 * click karne se orders, count, current balance … taake main event se bahar
 * ja kar bhi customer ki balances, receivable, payment, refund sab manage kar
 * sakoon."
 *
 * Aaj ye sab data mojood hai magar sirf EK BOOKING KE ANDAR SE pahunchta hai.
 * Jis graahak ke gyarah event hain, us ke liye gyarah alag jagahen hain aur
 * koi jama nahi.
 *
 * ── DO USOOL, aur dono ki wajah ek hi din purani hai ───────────────────────
 *
 * 1. HISAAB YAHAN DOBARA NAHI LIKHA JATA. "Kitna baqi hai" ka qaida
 *    `CateringFinancialPositionService::outstanding()` me hai aur "kis cheez
 *    par bill bana" `billedFrom()` me. Ye service sirf unhe bulati hai.
 *
 *    27 Sep ko theek isi cheez ne kaat-a tha: ek hi sawal ke chaar jawab chaar
 *    jagah likhe hue the, aur teen purana khaana parhte the. Ek nayi screen
 *    jo apna hisaab khud likhti, wo kahani dobara shuru kar deti.
 *
 * 2. `catering_final_invoices.balance_due` KABHI NAHI parha jata. Wo khaana
 *    invoice jaari hote waqt likha jata hai aur immutable hai — us ke baad
 *    aaya hua paisa us me kabhi nahi pahunchta.
 *
 * ── N+1 ───────────────────────────────────────────────────────────────────
 *
 * Fehrist dozens bookings ek saath dikhati hai, is liye har booking par
 * `position()` bulana (do query per booking) nahi chalega. Us ke bajaye saare
 * events EK BAAR laaye jate hain — advances/refunds ke sums aggregate
 * subquery se, invoice aur estimate eager-load se — aur hisaab PHP me usi
 * qaide se lagta hai. Adad wohi aate hain; sirf raasta sasta hai. Test isi
 * baat par khara hai: module ka total us graahak ke events par `position()`
 * ke jama ke BARABAR hona chahiye.
 */
class CateringCustomerBalanceService
{
    /**
     * Fehrist ka har row — ek graahak.
     *
     * @return Collection<int, array>
     */
    public function rows(?int $branchId = null, ?string $search = null, array $statuses = []): Collection
    {
        $events = $this->eventsQuery($branchId, $statuses)
            ->whereNotNull('customer_id')
            ->get();

        $customers = Customer::on('tenant')
            ->whereIn('id', $events->pluck('customer_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $events
            ->groupBy('customer_id')
            ->map(function (Collection $forCustomer, $customerId) use ($customers) {
                $customer = $customers->get($customerId);
                $totals = $this->totals($forCustomer);

                return [
                    'customer' => $customer,
                    'customer_id' => $customerId,
                    'name' => $customer?->name ?? '—',
                    'phone' => $customer?->phone,
                    'events' => $forCustomer->count(),
                    'last_event_date' => $forCustomer->max('event_date'),
                    // CATERING-BALANCES-STATUS-FILTER-1 — ek graahak ke kai
                    // event ho sakte hain aur har ek apni haalat me, is liye
                    // yahan EK status nahi likha ja sakta. Ginti likhi jati
                    // hai: "2 Confirmed · 1 Draft". Ek hi status chun lena
                    // (misal sab se naya) baqi bookings ko chhupa deta.
                    'status_counts' => $forCustomer->groupBy('status')
                        ->map->count()
                        ->sortKeysUsing(fn ($a, $b) => array_search($a, CateringEvent::STATUSES, true)
                            <=> array_search($b, CateringEvent::STATUSES, true))
                        ->all(),
                ] + $totals;
            })
            ->when($search, fn (Collection $rows) => $rows->filter(
                fn (array $r) => $this->matches($r, $search)
            ))
            // Jis par sab se zyada baqi hai wo sab se upar — screen ka sawal
            // "kis se paisa lena hai" hai, "kaun sa naam pehle aata hai" nahi.
            ->sortByDesc(fn (array $r) => [$r['balance'], $r['credit']])
            ->values();
    }

    /**
     * Wo bookings jin par graahak juda hi nahi.
     *
     * Ye jaan-boojh kar ALAG lautayi jati hain, fehrist me ghol kar nahi. Ek
     * total jis me se rows khamoshi se nikal jayen wo us total se bura hai
     * jis ke saath baqiya saath likha ho — aur ye baqiya kaam ki fehrist bhi
     * hai: inhe jorna ek command ka kaam hai.
     *
     * @return array{count: int, balance: float, credit: float}
     */
    public function unlinked(?int $branchId = null, array $statuses = []): array
    {
        $events = $this->eventsQuery($branchId, $statuses)->whereNull('customer_id')->get();
        $totals = $this->totals($events);

        return [
            'count' => $events->count(),
            'balance' => $totals['balance'],
            'credit' => $totals['credit'],
        ];
    }

    /**
     * Ek graahak ki poori tasveer — us ke saare events, har ek ka apna hisaab.
     *
     * @return array{customer: Customer, events: Collection, totals: array}
     */
    public function forCustomer(Customer $customer, ?int $branchId = null, array $statuses = []): array
    {
        $events = $this->eventsQuery($branchId, $statuses)
            ->where('customer_id', $customer->id)
            ->orderByDesc('event_date')
            ->get()
            ->map(function (CateringEvent $event) {
                $row = $this->positionOf($event);
                $row['event'] = $event;

                return $row;
            });

        return [
            'customer' => $customer,
            'events' => $events,
            'totals' => $this->totals($events->pluck('event')),
            'ledger' => $this->ledger($events->pluck('event')),
        ];
    }

    /**
     * Graahak ka poora hisaab — har booking ki har satar, tareekh ke hisaab se.
     *
     * Satrein yahan BANAYI NAHI jatin: har ek
     * `CateringFinancialPositionService::ledger()` se aati hai, jo pehle se har
     * booking ka statement banata hai (advance, refund, invoice, aur "advance
     * applied"). Yahan sirf unhe jor kar tarteeb di jati hai aur har satar par
     * booking ka number lagaya jata hai.
     *
     * EK NAYA ADAD zaroor banta hai aur wohi is method ka khatra hai: graahak
     * ki satah ka running total. Booking ka apna running us ki apni kahani
     * sunata hai; mila-jula fehrist me wo bemani ho jata. Is liye running
     * yahan naye sire se chalta hai — andar, bahar aur bill — aur AAKHRI satar
     * ka running theek `credit − balance` ke barabar aana chahiye. Test usi
     * par khara hai: agar ye adad sar par likhe adad se alag ho gaya, to ek hi
     * screen do kahaniyan keh rahi hai.
     *
     * @param  Collection<int, CateringEvent>  $events
     * @return Collection<int, array>
     */
    private function ledger(Collection $events): Collection
    {
        $position = app(CateringFinancialPositionService::class);

        $rows = collect();
        foreach ($events as $event) {
            foreach ($position->ledger($event) as $row) {
                $row['event_no'] = $event->event_no;
                $row['event_id'] = $event->id;
                $rows->push($row);
            }
        }

        $running = 0.0;

        return $rows
            ->sortBy([['sort_date', 'asc'], ['sort_at', 'asc']])
            ->values()
            ->map(function (array $row) use (&$running) {
                // Wohi simt jo booking ke statement ki hai: musbat = graahak ka
                // lena, manfi = graahak par baqi.
                $running = round(
                    $running + (float) $row['money_in'] - (float) $row['money_out'] - (float) $row['charged'],
                    2
                );
                $row['running'] = $running;

                return $row;
            });
    }

    // ── andar ka kaam ──────────────────────────────────────────────────────

    /**
     * Wohi query har jagah: sums aggregate se, invoice/estimate eager-load se.
     * Ek hi jagah rakhi gayi hai taake fehrist, unlinked aur tafseel teenon
     * BILKUL wohi data dekhen — warna teen screenein teen adad keh sakti hain.
     */
    private function eventsQuery(?int $branchId, array $statuses = [])
    {
        return CateringEvent::query()
            ->with([
                'finalInvoice:id,catering_event_id,grand_total,invoice_no,status,issued_at',
                'currentEstimate:id,catering_event_id,grand_total,version_no,status',
            ])
            ->withSum('advances', 'amount')
            ->withSum('refunds', 'amount')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            // CATERING-BALANCES-STATUS-FILTER-1 — filter EVENTS par lagta hai,
            // graahak par nahi. Jis graahak ka koi event is haalat me nahi wo
            // fehrist se khud nikal jata hai; aur jo bachte hain, un ka paisa
            // SIRF in events ka hota hai.
            //
            // Ye baat chhupayi nahi ja sakti: "Balance 9,59,597" parhne wala
            // samajhta hai ke graahak par itna baqi hai, jabke filter lage
            // hone par wo sirf chhante hue hisse ka hota hai. Is liye screen
            // filter lagte hi ye saaf likhti hai.
            ->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses));
    }

    /**
     * Ek event ka hisaab, pehle se laaye hue data par — wohi qaide jo
     * `position()` lagata hai, magar bina nayi query ke.
     *
     * @return array{billed: float, billed_source: string, received: float, balance: float, credit: float}
     */
    private function positionOf(CateringEvent $event): array
    {
        [$billed, $source] = CateringFinancialPositionService::billedFrom(
            $event->finalInvoice,
            $event->isCancelled(),
            $event->currentEstimate,
        );

        $received = round(
            (float) ($event->advances_sum_amount ?? 0) - (float) ($event->refunds_sum_amount ?? 0),
            2
        );

        return [
            'billed' => $billed,
            'billed_source' => $source,
            'received' => $received,
            'balance' => CateringFinancialPositionService::outstanding($billed, $received),

            // Balance aur Credit DO khaane hain, ek signed adad nahi. Ek hi
            // graahak ek booking par de sakta hai aur doosri par le sakta hai;
            // ek net adad ye baat chhupa deta hai.
            'credit' => round(max($received - $billed, 0), 2),
        ];
    }

    /** @return array{billed: float, received: float, balance: float, credit: float} */
    private function totals(Collection $events): array
    {
        $rows = $events->map(fn (CateringEvent $e) => $this->positionOf($e));

        return [
            'billed' => round((float) $rows->sum('billed'), 2),
            'received' => round((float) $rows->sum('received'), 2),
            'balance' => round((float) $rows->sum('balance'), 2),
            'credit' => round((float) $rows->sum('credit'), 2),
        ];
    }

    /**
     * Naam par bhi, phone par bhi — aur phone par SIRF ADAD milaye jate hain.
     * Operator "0312-295 1623" likhta hai aur record me "03122951623" hai;
     * seedha milan un dono ko ajnabi keh deta.
     */
    private function matches(array $row, string $search): bool
    {
        $needle = trim($search);
        if ($needle === '') {
            return true;
        }

        if (mb_stripos((string) $row['name'], $needle) !== false) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $needle) ?? '';
        if ($digits === '') {
            return false;
        }

        return str_contains(preg_replace('/\D+/', '', (string) $row['phone']) ?? '', $digits);
    }
}
