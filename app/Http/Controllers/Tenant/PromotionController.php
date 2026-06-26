<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Category;
use App\Models\Tenant\Product;
use App\Models\Tenant\Promotion;
use App\Services\Sales\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromotionController extends Controller
{
    public function index()
    {
        return view('tenant.promotions.index', [
            'promotions' => Promotion::with('branch')->orderByDesc('priority')->paginate(15),
        ]);
    }

    public function create()
    {
        return view('tenant.promotions.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:190'],
            'code'                  => ['nullable', 'string', 'max:50', 'unique:promotions,code'],
            'promotion_type'        => ['required', 'in:order,product,category'],
            'discount_type'         => ['required', 'in:fixed,percent'],
            'discount_value'        => ['required', 'numeric', 'min:0'],
            'max_discount_amount'   => ['nullable', 'numeric', 'min:0'],
            'min_order_amount'      => ['nullable', 'numeric', 'min:0'],
            'order_types'           => ['nullable', 'array'],
            'requires_code'         => ['nullable', 'boolean'],
            'usage_limit'           => ['nullable', 'integer', 'min:1'],
            'starts_at'             => ['nullable', 'date'],
            'ends_at'               => ['nullable', 'date'],
            'status'                => ['required', 'in:active,inactive'],
            'priority'              => ['nullable', 'integer', 'min:0'],
            'notes'                 => ['nullable', 'string'],
            'target_ids'            => ['nullable', 'array'],
            'target_ids.*'          => ['integer'],
        ]);

        $data['requires_code'] = (bool) ($data['requires_code'] ?? false);

        DB::connection('tenant')->transaction(function () use ($data) {
            $targets = $data['target_ids'] ?? [];
            unset($data['target_ids']);

            $promotion = Promotion::create($data);
            $this->syncTargets($promotion, $targets);
        });

        return redirect(url('/promotions'))->with('status', 'Promotion created successfully.');
    }

    public function edit(Promotion $promotion)
    {
        return view('tenant.promotions.edit', array_merge(
            ['promotion' => $promotion->load('targets')],
            $this->formData()
        ));
    }

    public function update(Request $request, Promotion $promotion)
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:190'],
            'code'                  => ['nullable', 'string', 'max:50', 'unique:promotions,code,' . $promotion->id],
            'promotion_type'        => ['required', 'in:order,product,category'],
            'discount_type'         => ['required', 'in:fixed,percent'],
            'discount_value'        => ['required', 'numeric', 'min:0'],
            'max_discount_amount'   => ['nullable', 'numeric', 'min:0'],
            'min_order_amount'      => ['nullable', 'numeric', 'min:0'],
            'order_types'           => ['nullable', 'array'],
            'requires_code'         => ['nullable', 'boolean'],
            'usage_limit'           => ['nullable', 'integer', 'min:1'],
            'starts_at'             => ['nullable', 'date'],
            'ends_at'               => ['nullable', 'date'],
            'status'                => ['required', 'in:active,inactive'],
            'priority'              => ['nullable', 'integer', 'min:0'],
            'notes'                 => ['nullable', 'string'],
            'target_ids'            => ['nullable', 'array'],
            'target_ids.*'          => ['integer'],
        ]);

        $data['requires_code'] = (bool) ($data['requires_code'] ?? false);

        DB::connection('tenant')->transaction(function () use ($promotion, $data) {
            $targets = $data['target_ids'] ?? [];
            unset($data['target_ids']);

            $promotion->update($data);
            $this->syncTargets($promotion, $targets);
        });

        return redirect(url('/promotions'))->with('status', 'Promotion updated successfully.');
    }

    public function destroy(Promotion $promotion)
    {
        $promotion->delete();
        return back()->with('status', 'Promotion deleted successfully.');
    }

    public function quote(Request $request, PromotionService $promotionService)
    {
        $data = $request->validate([
            'promo_code'  => ['required', 'string'],
            'branch_id'   => ['required', 'integer'],
            'order_type'  => ['required', 'string'],
            'subtotal'    => ['required', 'numeric', 'min:0'],
            'lines'       => ['nullable', 'array'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.category_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $quoteLines = $this->normalizeQuoteLines($data['lines'] ?? []);

        $promotion = $promotionService->findApplicablePromotion(
            (int) $data['branch_id'],
            $data['order_type'],
            (float) $data['subtotal'],
            $data['promo_code'],
            $quoteLines,
        );

        if (!$promotion) {
            return response()->json([
                'valid'            => false,
                'message'          => 'Promo code is invalid, expired, or does not apply to this order.',
                'discount_amount'  => 0,
                'promotion_id'     => null,
                'promo_code'       => null,
            ], 422);
        }

        $discountAmount = $promotionService->calculateDiscountForLines($promotion, $quoteLines, (float) $data['subtotal']);

        return response()->json([
            'valid'            => true,
            'discount_amount'  => round($discountAmount, 2),
            'promotion_id'     => $promotion->id,
            'promo_code'       => $promotion->code,
            'promotion_name'   => $promotion->name,
            'discount_type'    => $promotion->discount_type,
            'discount_value'   => (float) $promotion->discount_value,
        ]);
    }

    private function formData(): array
    {
        return [
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
            'categories' => Category::orderBy('name')->get(['id', 'name', 'code']),
        ];
    }

    private function syncTargets(Promotion $promotion, array $targetIds): void
    {
        $promotion->targets()->delete();

        if (!in_array($promotion->promotion_type, ['product', 'category'], true)) {
            return;
        }

        $targetIds = collect($targetIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        foreach ($targetIds as $targetId) {
            $promotion->targets()->create([
                'target_type' => $promotion->promotion_type,
                'target_id' => $targetId,
            ]);
        }
    }

    private function normalizeQuoteLines(array $lines): array
    {
        return collect($lines)
            ->filter(fn ($line) => (float) ($line['quantity'] ?? 0) > 0)
            ->map(fn ($line) => [
                'product_id' => (int) ($line['product_id'] ?? 0),
                'category_id' => (int) ($line['category_id'] ?? 0),
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
