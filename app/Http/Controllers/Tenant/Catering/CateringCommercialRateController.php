<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringCommercialRateBookService;
use App\Services\Catering\CateringCommercialRateImpactService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KASHIF-CATERING-COMMERCIAL-RATE-1 — the house price list, and what changing it
 * would do.
 *
 * Two screens' worth of one idea. The rate book says what a material is CHARGED
 * at — a different question from what it costs, which lives in Material Rates
 * and is not touched here. Raising a rate records the new one and changes
 * nothing else: no dish, no quotation, no invoice.
 *
 * What it produces instead is a review. Which dishes follow the house rate, what
 * they would become, which drafts were quoted at the old one and by how much
 * they would move — and then a selection. There is no apply-everything, because
 * a caterer's premium counter and their wedding package are supposed to differ
 * from the house rate, and a bulk button is how those get quietly flattened.
 *
 * A sent quotation is never repriced in place. It gets its own action, which
 * creates the next version and applies the rate to that.
 */
class CateringCommercialRateController extends Controller
{
    public function __construct(
        private readonly CateringCommercialRateImpactService $impact,
        private readonly CateringCommercialRateBookService $book,
    ) {}

    public function index(Request $request)
    {
        $latest = CateringMaterialCommercialRate::query()
            ->with(['product:id,name,sku', 'unit:id,code'])
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get()
            ->unique('product_id')
            ->values();

        return view('tenant.catering.commercial-rates.index', [
            'rates' => $latest,
            'history' => CateringMaterialCommercialRate::query()
                ->with(['product:id,name', 'unit:id,code'])
                ->orderByDesc('effective_from')->orderByDesc('id')
                ->limit(30)->get(),
            'materials' => Product::query()
                ->whereIn('product_kind', [
                    Product::KIND_RAW_MATERIAL,
                    Product::KIND_PACKAGING_MATERIAL,
                    Product::KIND_SEMI_FINISHED,
                ])
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit_id']),
            'units' => Unit::where('is_active', true)->orderBy('code')->get(['id', 'code']),
        ]);
    }

    /**
     * Record a new house rate.
     *
     * Append-only: raising chicken writes a new row even on a day that already
     * has one, so the morning's decision survives the afternoon's. The unit is
     * required, because every later question about this rate — may this dish
     * follow it, may this quotation take it — is a comparison of units before it
     * is a comparison of numbers.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'rate' => ['required', 'numeric', 'min:0'],
            'unit_id' => ['required', 'exists:units,id'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::connection('tenant')->transaction(function () use ($data, $request) {
            $previous = $this->book->effectiveRate((int) $data['product_id'], $data['effective_from']);

            $rate = CateringMaterialCommercialRate::create([
                'product_id' => $data['product_id'],
                'rate' => $data['rate'],
                'unit_id' => $data['unit_id'],
                'effective_from' => $data['effective_from'],
                'note' => $data['note'] ?? null,
                'created_by_user_id' => $request->user()?->id,
            ]);

            $this->book->record([
                'material_product_id' => (int) $data['product_id'],
                'action' => CateringCommercialRateApplication::ACTION_RATE_RECORDED,
                'target_type' => CateringCommercialRateApplication::TARGET_COMMERCIAL_RATE,
                'target_id' => $rate->id,
                'target_label' => 'House rate from '.$data['effective_from'],
                'old_commercial_rate' => $previous === null ? null : (float) $previous->rate,
                'new_commercial_rate' => (float) $data['rate'],
                'performed_by_user_id' => $request->user()?->id,
                'note' => $data['note'] ?? null,
            ]);
        });

        $name = Product::whereKey($data['product_id'])->value('name');

        return redirect()->to('/catering/commercial-rates')->with('status',
            "{$name} is now charged at ".number_format((float) $data['rate'], 2).' per unit. '
            .'Nothing has been repriced — review the impact to decide what should follow it.');
    }

    /** What this rate change would do, to dishes and to drafts. */
    public function impact(Request $request, Product $product)
    {
        return view('tenant.catering.commercial-rates.impact', [
            'material' => $product,
            'impact' => $this->impact->productImpact($product->id),
            'drafts' => $this->impact->draftImpact($product->id),
            'log' => CateringCommercialRateApplication::query()
                ->where('material_product_id', $product->id)
                ->with('performedBy:id,name')
                ->orderByDesc('id')->limit(25)->get(),
        ]);
    }

    /** Apply the house rate to the dishes the operator picked. */
    public function applyToProducts(Request $request, Product $product)
    {
        $data = $request->validate([
            'block_ids' => ['required', 'array', 'min:1'],
            'block_ids.*' => ['integer'],
        ]);

        try {
            $applied = $this->impact->applyToProducts($product->id, $data['block_ids'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['impact' => $e->getMessage()]);
        }

        return redirect()->to('/catering/commercial-rates/'.$product->id.'/impact')
            ->with('status', $applied === 0
                ? 'Nothing was changed — none of those dishes follow the house rate.'
                : "{$applied} dish(es) now charge the house rate. Quotations already sent or drafted are untouched.");
    }

    /** Apply it to the draft quotations the operator picked. */
    public function applyToDrafts(Request $request, Product $product)
    {
        $data = $request->validate([
            'snapshot_ids' => ['required', 'array', 'min:1'],
            'snapshot_ids.*' => ['integer'],
        ]);

        try {
            $applied = $this->impact->applyToDrafts($product->id, $data['snapshot_ids'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['impact' => $e->getMessage()]);
        }

        return redirect()->to('/catering/commercial-rates/'.$product->id.'/impact')
            ->with('status', $applied === 0
                ? 'Nothing was changed — none of those quotations could take the house rate.'
                : "{$applied} quotation line(s) repriced.");
    }

    /**
     * A sent quotation takes the rate by becoming its next version. The sent one
     * is superseded, never rewritten.
     */
    public function reviseAndApply(Request $request, Product $product)
    {
        $data = $request->validate([
            'estimate_id' => ['required', 'integer'],
        ]);

        try {
            $revision = $this->impact->applyThroughRevision(
                $product->id,
                (int) $data['estimate_id'],
                $request->user()?->id
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['impact' => $e->getMessage()]);
        }

        return redirect()->to('/catering/commercial-rates/'.$product->id.'/impact')
            ->with('status', 'Revision v'.$revision->version_no.' created at the house rate. '
                .'The sent version is kept as it was and marked superseded.');
    }
}
