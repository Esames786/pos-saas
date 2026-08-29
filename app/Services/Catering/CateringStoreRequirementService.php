<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialIssueLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CAT-STORE-001 — what the store still owes, across every booking in play.
 *
 * THE QUESTION THIS ANSWERS. A storeman on a Saturday morning is covering nine
 * weddings. Before this, the only requirement figure anywhere was per production
 * release, derived from recipes, and it could not be compared with what had
 * actually gone out of the door. He could answer "what did release #14 need"
 * and could not answer any of:
 *
 *      what do today's bookings need altogether?
 *      which of that is the customer bringing?
 *      what must WE issue?
 *      what has already gone?
 *      what is left?
 *
 * So the same 10 KG of chicken could be issued twice, an hour apart, and nothing
 * on any screen would look wrong.
 *
 * FOUR NUMBERS, and the whole value is in keeping them apart:
 *
 *      PHYSICAL     what the kitchen needs
 *      SUPPLIED     the part of it the customer is bringing
 *      OURS         what our store has to hand over  (physical - supplied)
 *      ISSUED       what our store already handed over
 *      REMAINING    ours - issued, never below zero
 *
 * PLANNING ONLY. This reads requirements and reads issues. It writes nothing,
 * reserves nothing, and posts nothing. The real movement stays where it has
 * always been: one InventoryService::postOutFefo per issue line, through the
 * Store Issue authority. A second opinion about stock is not a second writer of
 * it.
 *
 * Cancelled and closed bookings are excluded from what is required — work nobody
 * is doing is not work the store owes — but anything already issued against them
 * stays visible, because that stock really did leave.
 */
class CateringStoreRequirementService
{
    public function __construct(
        private readonly CateringRequirementService $requirements,
    ) {}

    /**
     * Reconcile the bookings happening on one business date.
     *
     * @return array{rows: array<int, array>, events: array<int, array>, warnings: string[]}
     */
    public function forDate(string $eventDate, ?int $branchId = null): array
    {
        $events = CateringEvent::query()
            ->whereDate('event_date', $eventDate)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('event_no')
            ->get();

        return $this->reconcile($events, $branchId);
    }

    /**
     * Reconcile an explicit set of bookings — the ones the storeman ticked.
     *
     * @param  array<int, int>  $eventIds
     * @return array{rows: array<int, array>, events: array<int, array>, warnings: string[]}
     */
    public function forEvents(array $eventIds, ?int $branchId = null): array
    {
        $ids = collect($eventIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return ['rows' => [], 'events' => [], 'warnings' => []];
        }

        return $this->reconcile(
            CateringEvent::query()->whereIn('id', $ids)->orderBy('event_no')->get(),
            $branchId
        );
    }

    /**
     * @param  Collection<int, CateringEvent>  $events
     * @return array{rows: array<int, array>, events: array<int, array>, warnings: string[]}
     */
    private function reconcile(Collection $events, ?int $branchId): array
    {
        $rows = [];
        $warnings = [];
        $eventSummary = [];

        foreach ($events as $event) {
            // A booking nobody is cooking requires nothing. Its history of what
            // already left the store is kept below regardless.
            $counts = ! $event->isCancelled()
                && ! in_array($event->status, [CateringEvent::STATUS_CLOSED], true);

            $eventSummary[] = [
                'id' => (int) $event->id,
                'event_no' => $event->event_no,
                'customer' => $event->customer_name,
                'event_date' => $event->event_date?->toDateString(),
                'status' => $event->status,
                'counts_towards_requirement' => $counts,
            ];

            if (! $counts) {
                continue;
            }

            $estimate = $event->currentEstimate;
            if (! $estimate) {
                continue;
            }

            $result = $this->requirements->consolidatedForEstimate($estimate, $branchId ?? $event->branch_id);

            foreach ($result['warnings'] as $warning) {
                $warnings[] = "{$event->event_no}: {$warning}";
            }

            foreach ($result['requirements'] as $requirement) {
                $productId = (int) $requirement['product_id'];

                if (! isset($rows[$productId])) {
                    $rows[$productId] = [
                        'product_id' => $productId,
                        'name' => $requirement['name'],
                        'unit_code' => $requirement['unit_code'],
                        'physical_qty' => 0.0,
                        'customer_supplied_qty' => 0.0,
                        'required_qty' => 0.0,
                        'issued_qty' => 0.0,
                        'remaining_qty' => 0.0,
                        'on_hand' => (float) ($requirement['on_hand'] ?? 0),
                        'by_event' => [],
                    ];
                }

                $rows[$productId]['physical_qty'] += (float) $requirement['physical_qty'];
                $rows[$productId]['customer_supplied_qty'] += (float) $requirement['customer_supplied_qty'];
                $rows[$productId]['required_qty'] += (float) $requirement['required_qty'];

                $rows[$productId]['by_event'][$event->id] = [
                    'event_no' => $event->event_no,
                    'required_qty' => round((float) $requirement['required_qty'], 3),
                    'customer_supplied_qty' => round((float) $requirement['customer_supplied_qty'], 3),
                ];
            }
        }

        $this->attachIssued($rows, $events->pluck('id')->all());
        $this->attachOnHand($rows, $branchId);

        return [
            'rows' => array_values($rows),
            'events' => $eventSummary,
            'warnings' => $warnings,
        ];
    }

    /**
     * What has already gone out against these bookings.
     *
     * Counted through the issue↔event pivot, so one trip to the store covering
     * nine weddings is attributed to all nine — which is exactly why a per-issue
     * view could never answer this.
     *
     * @param  array<int, array>  $rows
     * @param  array<int, int>  $eventIds
     */
    private function attachIssued(array &$rows, array $eventIds): void
    {
        if ($eventIds === []) {
            return;
        }

        // CAT-STORE-002 — A BOOKING REFERENCE IS NOT AN ALLOCATION.
        //
        // A store issue may name several bookings. That is a reason, not a
        // division: one 12 KG handover covering weddings A and B does NOT record
        // that A took 7 and B took 5, and nothing in the system knows which.
        //
        // Reconciling [A, B] together is honest — the whole referenced set is in
        // front of us, so 12 came out against their combined 20. Reconciling A
        // ALONE is not: crediting A with all 12 would invent an allocation, and
        // the storeman would be told A was fully issued while a real top-up was
        // still owed. Splitting it 6/6 would be the same invention with better
        // manners.
        //
        // So an issue is DEFINITE only when every booking it references sits
        // inside the set being reconciled. Anything that merely overlaps is
        // reported separately, by name, and never quietly subtracted from one
        // booking's remainder.
        [$definiteIssueIds, $sharedIssueIds] = $this->classifyIssues($eventIds);

        $issued = $this->sumIssuedByProduct($definiteIssueIds);
        $ambiguous = $this->sumIssuedByProduct($sharedIssueIds);

        // Material that went out against these bookings but is required by none
        // of them still belongs on the sheet. A cancelled wedding requires
        // nothing, but six kilos of chicken really did leave the store, and
        // somebody has to account for them.
        foreach ([$issued, $ambiguous] as $set) {
            foreach ($set as $productId => $qty) {
                if (isset($rows[(int) $productId]) || (float) $qty <= 0) {
                    continue;
                }

                $material = Product::with('unit')->find($productId);
                if (! $material) {
                    continue;
                }

                $rows[(int) $productId] = [
                    'product_id' => (int) $productId,
                    'name' => $material->name,
                    'unit_code' => $material->unit?->code,
                    'physical_qty' => 0.0,
                    'customer_supplied_qty' => 0.0,
                    'required_qty' => 0.0,
                    'issued_qty' => 0.0,
                    'remaining_qty' => 0.0,
                    'on_hand' => 0.0,
                    'by_event' => [],
                ];
            }
        }

        foreach ($rows as $productId => &$row) {
            $row['issued_qty'] = round((float) ($issued[$productId] ?? 0), 3);
            $row['shared_unallocated_qty'] = round((float) ($ambiguous[$productId] ?? 0), 3);
            $row['physical_qty'] = round($row['physical_qty'], 3);
            $row['customer_supplied_qty'] = round($row['customer_supplied_qty'], 3);
            $row['required_qty'] = round($row['required_qty'], 3);

            // Remaining is derived from the DEFINITE figure only. The shared
            // quantity is shown beside it so the operator can see there is
            // something the system cannot attribute, rather than being handed a
            // confident number built on a guess.
            $row['remaining_qty'] = round(max($row['required_qty'] - $row['issued_qty'], 0), 3);
            $row['over_issued_qty'] = round(max($row['issued_qty'] - $row['required_qty'], 0), 3);
            $row['remaining_is_certain'] = $row['shared_unallocated_qty'] <= 0;
            $row['by_event'] = array_values($row['by_event']);
        }
        unset($row);
    }

    /**
     * Split the issues touching this set into the ones we can attribute and the
     * ones we cannot.
     *
     * @param  array<int, int>  $eventIds
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function classifyIssues(array $eventIds): array
    {
        $touching = DB::connection('tenant')->table('catering_material_issue_events')
            ->whereIn('catering_event_id', $eventIds)
            ->distinct()->pluck('catering_material_issue_id')->all();

        if ($touching === []) {
            return [[], []];
        }

        $references = DB::connection('tenant')->table('catering_material_issue_events')
            ->whereIn('catering_material_issue_id', $touching)
            ->get()
            ->groupBy('catering_material_issue_id');

        $definite = [];
        $shared = [];

        foreach ($touching as $issueId) {
            $referenced = $references->get($issueId, collect())
                ->pluck('catering_event_id')->map(fn ($id) => (int) $id)->all();

            // Every booking this issue names is in front of us, so the whole
            // quantity belongs to this reconciliation. Otherwise some of it was
            // for a booking we are not looking at, and we do not know how much.
            if (array_diff($referenced, $eventIds) === []) {
                $definite[] = (int) $issueId;
            } else {
                $shared[] = (int) $issueId;
            }
        }

        return [$definite, $shared];
    }

    /**
     * @param  array<int, int>  $issueIds
     * @return \Illuminate\Support\Collection<int, float>
     */
    private function sumIssuedByProduct(array $issueIds): Collection
    {
        if ($issueIds === []) {
            return collect();
        }

        return CateringMaterialIssueLine::query()
            ->whereIn('catering_material_issue_id', $issueIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(issued_qty) as qty')
            ->pluck('qty', 'product_id');
    }

    /** @param  array<int, array>  $rows */
    private function attachOnHand(array &$rows, ?int $branchId): void
    {
        foreach ($rows as $productId => &$row) {
            $query = StockBalance::query()->where('product_id', $productId);
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
            $row['on_hand'] = round((float) $query->sum('quantity_on_hand'), 3);
            $row['short_of_remaining'] = round(max($row['remaining_qty'] - $row['on_hand'], 0), 3);
        }
        unset($row);
    }

    /**
     * What a proposed issue is allowed to be, per material, for these bookings.
     *
     * Used to bound the store screen's defaults and to refuse an accidental
     * repeat.
     *
     * ONLY materials these bookings actually asked for appear here, and the test
     * is physical_qty — what the KITCHEN needs — not required_qty.
     *
     * The distinction matters in two directions. A material that appears in the
     * reconciliation only because something was already issued against it has no
     * requirement to be measured against, so it must stay unbounded: daily prep
     * and staff food leave the store against no quotation and always could, and
     * an earlier handover must not silently become a ceiling on the next one.
     *
     * But a CUSTOMER-SUPPLIED material is bounded at zero rather than absent. The
     * kitchen needs it and we agreed not to provide it, so handing it over is a
     * real exception — usually the customer's delivery failing — and it should
     * cost the storeman one deliberate tick and a reason rather than passing
     * unremarked.
     *
     * @param  array<int, int>  $eventIds
     * @return array<int, float> product_id => remaining
     */
    public function remainingByMaterial(array $eventIds, ?int $branchId = null): array
    {
        $map = [];

        foreach ($this->forEvents($eventIds, $branchId)['rows'] as $row) {
            if ((float) $row['physical_qty'] <= 0) {
                continue;
            }

            // CAT-STORE-002: where an earlier issue cannot be attributed to this
            // set, the remainder is genuinely unknown — so it must not become a
            // ceiling. Blocking a real top-up because of a quantity we chose to
            // guess at would be the invented allocation doing damage through the
            // back door. The screen says the figure is uncertain instead.
            if (! ($row['remaining_is_certain'] ?? true)) {
                continue;
            }

            $map[(int) $row['product_id']] = (float) $row['remaining_qty'];
        }

        return $map;
    }
}
