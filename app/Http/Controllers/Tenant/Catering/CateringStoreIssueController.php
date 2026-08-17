<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialIssue;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringMaterialIssueService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * KASHIF-CATERING-STORE-1 — the store counter.
 *
 * The kitchen man arrives with a sheet and asks for what he is cooking. He may
 * be covering ten bookings, one, half of one, or tomorrow's prep with no booking
 * at all. This screen records what actually left the store, in the quantities
 * that actually left it.
 *
 * The booking reference is a note. Leaving it blank produces a complete, valid
 * record — the stock movement is the fact, and it happened whether or not anyone
 * wrote an order number beside it.
 */
class CateringStoreIssueController extends Controller
{
    public function index(Request $request)
    {
        $issues = CateringMaterialIssue::query()
            ->with(['lines', 'events:id,event_no,customer_name,event_date', 'branch:id,name'])
            ->latest('issued_at')
            ->paginate(25);

        return view('tenant.catering.store-issues.index', [
            'issues' => $issues,
            // Only whether ANY material exists, not the list. The picker fetches
            // matches as they are typed, so loading five hundred rows to draw a
            // dropdown nobody scrolls is exactly what this screen stopped doing.
            'hasMaterials' => $this->materialsQuery()->exists(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            // Only open bookings are offered — referencing a closed or cancelled
            // event would be a note nobody can act on.
            'events' => $this->attachableEvents()
                ->orderByDesc('event_date')
                ->limit(50)
                ->get(['id', 'event_no', 'customer_name', 'event_date']),
        ]);
    }

    public function store(Request $request, CateringMaterialIssueService $issues)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            // KASHIF-CATERING-STORE-2: zero, one, or many. One morning trip to
            // the store may cover twelve weddings.
            'event_ids' => ['nullable', 'array'],
            'event_ids.*' => ['integer', 'exists:catering_events,id'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ], [
            'lines.required' => 'Add at least one material before issuing.',
        ]);

        // The picker offers only issuable materials; this makes that true rather
        // than merely displayed. A request naming a dish would otherwise reach
        // the service and be written as a non-stock line — a store issue for
        // something no store has ever held.
        $requested = collect($data['lines'])->pluck('product_id')->filter()->unique();
        $eligible = $this->materialsQuery()->whereIn('id', $requested)->pluck('id');
        if ($ineligible = $requested->diff($eligible)->first()) {
            $name = Product::whereKey($ineligible)->value('name') ?? "#{$ineligible}";

            return back()->withErrors([
                'issue' => "'{$name}' is not something the store can hand over. "
                    .'Only stock-tracked raw and packaging materials can be issued.',
            ])->withInput();
        }

        // A booking that is over or cancelled cannot explain stock leaving today,
        // so it cannot be attached. The same rule the picker filters by.
        $eventIds = collect($data['event_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique();
        if ($eventIds->isNotEmpty()) {
            $attachable = $this->attachableEvents()->whereIn('id', $eventIds)->pluck('id');
            if ($rejected = $eventIds->diff($attachable)->first()) {
                $eventNo = CateringEvent::whereKey($rejected)->value('event_no') ?? "#{$rejected}";

                return back()->withErrors([
                    'issue' => "Booking {$eventNo} is closed or cancelled, so material cannot be issued against it.",
                ])->withInput();
            }
        }

        try {
            $issue = $issues->issueDirect(
                lines: $data['lines'],
                branchId: (int) $data['branch_id'],
                eventIds: $eventIds->all(),
                releaseId: null,
                userId: $request->user()?->id,
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['issue' => $e->getMessage()])->withInput();
        }

        $against = $eventIds->isEmpty()
            ? 'as a general issue'
            : 'against '.$eventIds->count().' booking'.($eventIds->count() === 1 ? '' : 's');

        return redirect()
            ->to('/catering/store-issues')
            ->with('status', "Issue {$issue->issue_no} recorded {$against} — stock reduced and cost posted at the real batch price.");
    }

    /**
     * What the store can hand over: stock-tracked materials only.
     *
     * A dish is not issuable — you cannot take biryani out of the store, you
     * take the rice and the chicken that make it.
     *
     * The same restriction is applied by the shared product lookup under the
     * 'catering_store_issue' context, so the searchable picker offers exactly
     * this set. Kept here too, because the picker is a convenience and the
     * server is the authority.
     */
    /**
     * Bookings that material may still be issued against.
     *
     * Reuses the existing lifecycle contract rather than inventing a second
     * status vocabulary: CateringEvent::OPEN_STATUSES already decides what is
     * operationally alive. A cancelled or closed booking cannot explain stock
     * leaving the store today.
     */
    private function attachableEvents()
    {
        return CateringEvent::query()->whereIn('status', CateringEvent::OPEN_STATUSES);
    }

    private function materialsQuery()
    {
        return Product::query()
            ->where('status', 'active')
            ->where('is_stock_tracked', true)
            ->whereIn('product_kind', [Product::KIND_RAW_MATERIAL, Product::KIND_PACKAGING_MATERIAL]);
    }
}
