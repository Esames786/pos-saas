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
            ->with(['lines', 'event:id,event_no,customer_name', 'branch:id,name'])
            ->latest('issued_at')
            ->paginate(25);

        return view('tenant.catering.store-issues.index', [
            'issues' => $issues,
            'materials' => $this->materials(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            // Only open bookings are offered — referencing a closed or cancelled
            // event would be a note nobody can act on.
            'events' => CateringEvent::query()
                ->whereIn('status', CateringEvent::OPEN_STATUSES)
                ->orderByDesc('event_date')
                ->limit(50)
                ->get(['id', 'event_no', 'customer_name', 'event_date']),
        ]);
    }

    public function store(Request $request, CateringMaterialIssueService $issues)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'catering_event_id' => ['nullable', 'integer', 'exists:catering_events,id'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ], [
            'lines.required' => 'Add at least one material before issuing.',
        ]);

        try {
            $issue = $issues->issueDirect(
                lines: $data['lines'],
                branchId: (int) $data['branch_id'],
                eventId: $data['catering_event_id'] ?? null,
                releaseId: null,
                userId: $request->user()?->id,
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['issue' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->to('/catering/store-issues')
            ->with('status', "Issue {$issue->issue_no} recorded — stock reduced and cost posted at the real batch price.");
    }

    /**
     * What the store can hand over: stock-tracked materials only.
     *
     * A dish is not issuable — you cannot take biryani out of the store, you
     * take the rice and the chicken that make it.
     */
    private function materials()
    {
        return Product::query()
            ->where('status', 'active')
            ->where('is_stock_tracked', true)
            ->whereIn('product_kind', [Product::KIND_RAW_MATERIAL, Product::KIND_PACKAGING_MATERIAL])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit_id'])
            ->load('unit:id,code');
    }
}
