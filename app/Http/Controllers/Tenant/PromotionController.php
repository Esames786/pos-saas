<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
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
        return view('tenant.promotions.create');
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
        ]);

        Promotion::create($data);

        return redirect(url('/promotions'))->with('status', 'Promotion created successfully.');
    }

    public function edit(Promotion $promotion)
    {
        return view('tenant.promotions.edit', ['promotion' => $promotion]);
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
        ]);

        $promotion->update($data);

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
        ]);

        $promotion = $promotionService->findApplicablePromotion(
            (int) $data['branch_id'],
            $data['order_type'],
            (float) $data['subtotal'],
            $data['promo_code'],
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

        $discountAmount = $promotionService->calculateDiscount($promotion, (float) $data['subtotal']);

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
}
