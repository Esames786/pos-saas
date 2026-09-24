<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CATERING-SLICE-2: Material Rate Book — versioned catering quote rates.
 * Writes only catering_material_rates; inventory costs and POS prices are
 * never touched from here.
 *
 * CATERING-RATE-PARITY-1 (2026-09-24) — this screen was the commercial screen's
 * poor relation, and had every fault the commercial one was deliberately built
 * to avoid. Research: docs/plans/kashif-rate-book-daily-update-2026-09-24.md.
 *
 * Three of them, all now closed:
 *
 *   1. "Current" was max(id) — the highest row, whatever date it carried. The
 *      COSTING has always resolved by date (CateringMaterialRate::effectiveFor),
 *      so a rate dated next week became the screen's current rate the moment it
 *      was saved while every quotation still costed at the old one. The screen
 *      and the arithmetic disagreed, and the screen was the one lying.
 *
 *   2. Any product could be given a "material" rate: the picker searched the
 *      whole catalogue and the rule was a bare exists:products,id. On the live
 *      tenant that is how the DISH "Chatni" acquired a material cost rate. The
 *      commercial controller says it plainly — a dish is not a material — and
 *      now this one agrees.
 *
 *   3. The unit was optional. A rate of 450 means nothing until it says 450 per
 *      what, and a cost block can only follow a rate measured in the same unit.
 */
class CateringMaterialRateController extends Controller
{
    /** The same definition the commercial rate book uses. One answer, not two. */
    public const MATERIAL_KINDS = CateringCommercialRateController::MATERIAL_KINDS;

    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));
        $today = app(\App\Support\TenantClock::class)->now()->toDateString();

        // CURRENT means in force TODAY, resolved exactly as the costing resolves
        // it (CateringMaterialRate::effectiveFor): the latest effective_from that
        // has ARRIVED, then the latest row at that date. A rate dated next Monday
        // is a decision already taken; it is not what anything is costed at this
        // morning, and listing it as current had the screen contradicting the
        // arithmetic.
        //
        // Chosen in SQL rather than by filtering a collection so the page can
        // still paginate — a tenant with a long material list must not have its
        // table silently collapse into one page.
        $current = CateringMaterialRate::with(['product.unit', 'unit', 'product.translations'])
            ->whereIn('id', function ($q) use ($today) {
                $q->selectRaw('MAX(r2.id)')
                    ->from('catering_material_rates as r2')
                    ->whereDate('r2.effective_from', '<=', $today)
                    ->whereRaw(
                        'r2.effective_from = (SELECT MAX(r3.effective_from) FROM catering_material_rates r3'
                        .' WHERE r3.product_id = r2.product_id AND r3.effective_from <= ?)',
                        [$today]
                    )
                    ->groupBy('r2.product_id');
            })
            ->when($search !== '', fn ($query) => $query->whereHas(
                'product',
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Recorded, dated, and deliberately not in force yet — shown apart so
        // nobody costs from a price that has not arrived.
        $scheduled = CateringMaterialRate::with(['product', 'unit'])
            ->whereDate('effective_from', '>', $today)
            ->orderBy('effective_from')->orderBy('id')
            ->get();

        $history = null;
        if ($productId = (int) $request->input('product_id')) {
            $history = CateringMaterialRate::with(['unit', 'product'])
                ->where('product_id', $productId)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->get();
        }

        return view('tenant.catering.material-rates.index', [
            'latestRates' => $current,
            'scheduled' => $scheduled,
            'history' => $history,
            'units' => Unit::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'search' => $search,
            // A plain list, not a catalogue search: there are only ever a handful
            // of materials, and offering the whole catalogue is what put a rate
            // on a dish.
            'materials' => Product::query()
                ->whereIn('product_kind', self::MATERIAL_KINDS)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit_id']),
            // CATERING-RATE-HISTORY-1: what each material has cost before, so the
            // operator can put yesterday's rate back without leaving the modal.
            'historyByMaterial' => $this->recentHistory(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // Fail closed on the identity of the thing being priced, not just on
            // its existence. The select box is a convenience; the request can
            // name any product id.
            'product_id' => ['required', Rule::exists('products', 'id')->where(
                fn ($q) => $q->whereIn('product_kind', self::MATERIAL_KINDS)
            )],
            'rate' => ['required', 'numeric', 'min:0'],
            'unit_id' => ['required', Rule::exists('units', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data['created_by_user_id'] = $request->user()?->id;

        // Append-only: a second rate on a day that already has one writes a new
        // row, so the morning's decision survives the afternoon's.
        $rate = CateringMaterialRate::create($data);

        // url(), not route() — see CateringEventController::store.
        return redirect()
            ->to('/catering/rate-impact?product_id='.$rate->product_id)
            ->with('status', "Rate recorded for {$rate->product->name} — review the impact below.");
    }

    /**
     * The last few rates per material, for the modal.
     *
     * Keyed by product id and deliberately small: there are a handful of
     * materials, so this travels with the page and needs no second request —
     * and therefore no new route, and no new permission for every role to be
     * granted separately.
     */
    private function recentHistory(): array
    {
        $materialIds = Product::query()
            ->whereIn('product_kind', self::MATERIAL_KINDS)
            ->pluck('id');

        return CateringMaterialRate::with('unit:id,code')
            ->whereIn('product_id', $materialIds)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->take(10)->map(fn ($r) => [
                'rate' => (float) $r->rate,
                'unit_id' => $r->unit_id,
                'unit' => $r->unit?->code,
                'effective_from' => $r->effective_from instanceof \DateTimeInterface
                    ? $r->effective_from->format('Y-m-d')
                    : (string) $r->effective_from,
                'note' => $r->note,
            ])->values()->all())
            ->all();
    }
}
